<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\InventoryLedgerEntry;
use App\Models\OrderReturn;
use App\Models\Shipment;
use App\Models\SkuMapping;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 3PL billing: what a client owes for the warehouse work of a period, priced with the client's rate card.
 *
 *  - Storage: units kept in stock each day of the period (unit-days), from the stock ledger.
 *  - Receiving: units of the client's products received into stock.
 *  - Orders: the client's orders shipped, plus every unit beyond the first of each order.
 *  - Returns: the client's returns completed.
 *  - Monthly minimum: when the charges are below it, the difference is added (prorated for part of a month).
 *
 * A product belongs to the client of the store it was first sold in (its first store link to a store with
 * a client), so stock shared by two stores of one client is counted once.
 */
class ClientBillingService
{
    /**
     * The charges for the period, one line per kind of work, with the figures behind them.
     *
     * @return array{lines: list<array{type: string, description: string, quantity: float, rate: float, amount: float}>, total: float, activity: array<string, int|float>}
     */
    public function charges(Client $client, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $from = $from->startOfDay();
        $to = $to->endOfDay();
        $variantIds = $this->variantIds($client);
        $orders = $this->shippedOrders($client, $from, $to);

        $activity = [
            'unit_days' => $this->unitDays($client->organization_id, $variantIds, $from, $to),
            'units_received' => $this->unitsReceived($client->organization_id, $variantIds, $from, $to),
            'orders_shipped' => $orders['orders'],
            'units_shipped' => $orders['units'],
            'additional_units' => max(0, $orders['units'] - $orders['orders']),
            'returns' => $this->returnsCompleted($client, $from, $to),
        ];

        $lines = collect([
            ['storage', 'Storage (unit-days)', $activity['unit_days'], $client->storage_per_unit_day],
            ['receiving', 'Receiving (units received)', $activity['units_received'], $client->receiving_per_unit],
            ['orders', 'Order fulfillment (orders shipped)', $activity['orders_shipped'], $client->order_fee],
            ['additional_units', 'Additional units picked (beyond the first of each order)', $activity['additional_units'], $client->additional_unit_fee],
            ['returns', 'Returns processed', $activity['returns'], $client->return_fee],
        ])
            ->filter(fn (array $line) => $line[2] > 0 && $line[3] > 0)
            ->map(fn (array $line) => $this->line(...$line))
            ->values();

        $minimum = round($client->monthly_minimum * $this->monthShare($from, $to), 2);
        $subtotal = round($lines->sum('amount'), 2);
        if ($minimum > $subtotal) {
            $lines->push($this->line('minimum', 'Monthly minimum (top-up to the agreed minimum)', 1, $minimum - $subtotal));
        }

        return ['lines' => $lines->all(), 'total' => round($lines->sum('amount'), 2), 'activity' => $activity];
    }

    /**
     * Create a draft invoice for the period.
     *
     * @throws ValidationException when the period is invalid or already billed
     */
    public function generate(Client $client, CarbonImmutable $from, CarbonImmutable $to, User $user): ClientInvoice
    {
        if ($to->lt($from)) {
            throw ValidationException::withMessages(['period_end' => 'The period must end on or after its start.']);
        }

        $overlap = $client->invoices()
            ->where('status', '!=', ClientInvoice::STATUS_VOID)
            ->whereDate('period_start', '<=', $to->toDateString())
            ->whereDate('period_end', '>=', $from->toDateString())
            ->value('invoice_number');
        if ($overlap) {
            throw ValidationException::withMessages(['period_start' => "Part of this period is already billed on {$overlap}. Void that invoice first, or choose another period."]);
        }

        return DB::transaction(function () use ($client, $from, $to, $user) {
            $invoice = ClientInvoice::create([
                'organization_id' => $client->organization_id,
                'client_id' => $client->id,
                'invoice_number' => ClientInvoice::nextNumber($client->organization_id),
                'period_start' => $from->toDateString(),
                'period_end' => $to->toDateString(),
                'status' => ClientInvoice::STATUS_DRAFT,
                'currency' => $client->currency,
                'created_by' => $user->id,
            ]);

            return $this->fill($invoice, $client);
        });
    }

    /**
     * Price a draft again with the latest activity and rates; lines added by hand are kept.
     */
    public function recalculate(ClientInvoice $invoice): ClientInvoice
    {
        $this->assertDraft($invoice);

        return DB::transaction(fn () => $this->fill($invoice, $invoice->client, true));
    }

    /**
     * Add a charge by hand to a draft (special packaging, labelling, kitting, a discount as a negative rate).
     */
    public function addLine(ClientInvoice $invoice, string $description, float $quantity, float $rate): ClientInvoice
    {
        $this->assertDraft($invoice);
        $invoice->lines()->create($this->line(ClientInvoiceLine::TYPE_MANUAL, $description, $quantity, $rate));

        return $this->refreshTotal($invoice);
    }

    public function removeLine(ClientInvoice $invoice, ClientInvoiceLine $line): ClientInvoice
    {
        $this->assertDraft($invoice);
        abort_unless($line->client_invoice_id === $invoice->id, 404);
        $line->delete();

        return $this->refreshTotal($invoice);
    }

    /**
     * Send the invoice: it can no longer change, and payment is due after the client's payment terms.
     */
    public function issue(ClientInvoice $invoice): ClientInvoice
    {
        $this->assertDraft($invoice);
        if ($invoice->lines()->doesntExist()) {
            throw ValidationException::withMessages(['invoice' => 'The invoice has no charges. Recalculate it or add a charge before issuing it.']);
        }

        $invoice->update([
            'status' => ClientInvoice::STATUS_ISSUED,
            'issued_at' => now(),
            'due_date' => now()->addDays($invoice->client->payment_terms_days)->toDateString(),
        ]);

        return $invoice;
    }

    public function markPaid(ClientInvoice $invoice): ClientInvoice
    {
        if ($invoice->status !== ClientInvoice::STATUS_ISSUED) {
            throw ValidationException::withMessages(['invoice' => "Only an issued invoice can be paid; {$invoice->invoice_number} is {$invoice->status}."]);
        }

        $invoice->update(['status' => ClientInvoice::STATUS_PAID, 'paid_at' => now()]);

        return $invoice;
    }

    /**
     * Cancel a draft or issued invoice; its period can then be billed again.
     */
    public function void(ClientInvoice $invoice): ClientInvoice
    {
        if (! in_array($invoice->status, [ClientInvoice::STATUS_DRAFT, ClientInvoice::STATUS_ISSUED], true)) {
            throw ValidationException::withMessages(['invoice' => "{$invoice->invoice_number} is {$invoice->status} and cannot be voided."]);
        }

        $invoice->update(['status' => ClientInvoice::STATUS_VOID]);

        return $invoice;
    }

    /**
     * Product variants billed to the client: those whose first link to a store with a client is the client's.
     *
     * @return list<int>
     */
    public function variantIds(Client $client): array
    {
        $firstLinks = SkuMapping::query()
            ->join('stores', 'stores.id', '=', 'sku_mappings.store_id')
            ->where('sku_mappings.organization_id', $client->organization_id)
            ->whereNotNull('stores.client_id')
            ->groupBy('sku_mappings.product_variant_id')
            ->selectRaw('MIN(sku_mappings.id)');

        return SkuMapping::query()
            ->join('stores', 'stores.id', '=', 'sku_mappings.store_id')
            ->whereIn('sku_mappings.id', $firstLinks)
            ->where('stores.client_id', $client->id)
            ->pluck('sku_mappings.product_variant_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Units on hand at the end of each day of the period, added up (10 units kept 3 days = 30 unit-days).
     * Days after today are not counted.
     *
     * @param  list<int>  $variantIds
     */
    private function unitDays(int $organizationId, array $variantIds, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $last = CarbonImmutable::now()->endOfDay()->min($to);
        if ($variantIds === [] || $last->lt($from)) {
            return 0;
        }

        $ledger = fn () => InventoryLedgerEntry::where('organization_id', $organizationId)->whereIn('product_variant_id', $variantIds);

        // Stock at the start: the balance after the last movement before the period, per warehouse and item.
        $opening = $ledger()
            ->whereIn('id', $ledger()->where('created_at', '<', $from)->groupBy('warehouse_id', 'product_variant_id')->selectRaw('MAX(id)'))
            ->get(['warehouse_id', 'product_variant_id', 'balance_after'])
            ->mapWithKeys(fn ($entry) => ["{$entry->warehouse_id}-{$entry->product_variant_id}" => (int) $entry->balance_after]);

        // The balance at the end of each day with movements.
        $closing = $ledger()
            ->whereBetween('created_at', [$from, $last])
            ->orderBy('id')
            ->get(['warehouse_id', 'product_variant_id', 'balance_after', 'created_at'])
            ->groupBy(fn ($entry) => "{$entry->warehouse_id}-{$entry->product_variant_id}")
            ->map(fn (Collection $entries) => $entries->mapWithKeys(fn ($entry) => [$entry->created_at->toDateString() => (int) $entry->balance_after]));

        $total = 0;
        foreach ($opening->keys()->merge($closing->keys())->unique() as $key) {
            $onHand = $opening[$key] ?? 0;
            $days = $closing[$key] ?? collect();
            for ($day = $from; $day->lte($last); $day = $day->addDay()) {
                $onHand = $days[$day->toDateString()] ?? $onHand;
                $total += max(0, $onHand);
            }
        }

        return $total;
    }

    /**
     * @param  list<int>  $variantIds
     */
    private function unitsReceived(int $organizationId, array $variantIds, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return $variantIds === [] ? 0 : (int) InventoryLedgerEntry::where('organization_id', $organizationId)
            ->whereIn('product_variant_id', $variantIds)
            ->where('movement_type', 'receipt')
            ->where('quantity', '>', 0)
            ->whereBetween('created_at', [$from, $to])
            ->sum('quantity');
    }

    /**
     * Orders of the client's stores shipped in the period and their units (cancelled lines left out).
     *
     * @return array{orders: int, units: int}
     */
    private function shippedOrders(Client $client, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $orderIds = Shipment::where('organization_id', $client->organization_id)
            ->whereIn('status', ['shipped', 'delivered'])
            ->whereBetween('shipped_at', [$from, $to])
            ->whereHas('order.store', fn ($q) => $q->where('client_id', $client->id))
            ->distinct()
            ->pluck('order_id');

        $units = $orderIds->isEmpty() ? 0 : (int) DB::table('order_items')
            ->whereIn('order_id', $orderIds)
            ->where('status', '!=', 'cancelled')
            ->sum('quantity');

        return ['orders' => $orderIds->count(), 'units' => $units];
    }

    private function returnsCompleted(Client $client, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return OrderReturn::where('organization_id', $client->organization_id)
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$from, $to])
            ->whereHas('order.store', fn ($q) => $q->where('client_id', $client->id))
            ->count();
    }

    /**
     * How much of a month the period is: a whole calendar month (or more) is 1.
     */
    private function monthShare(CarbonImmutable $from, CarbonImmutable $to): float
    {
        $from = $from->startOfDay();
        $to = $to->startOfDay();

        // Whole calendar months (1 Oct to 30 Nov is 2); otherwise the share of the starting month's days.
        if ($from->isSameDay($from->startOfMonth()) && $to->isSameDay($to->endOfMonth())) {
            return (float) (($to->year - $from->year) * 12 + $to->month - $from->month + 1);
        }

        return round(($from->diffInDays($to) + 1) / $from->daysInMonth, 4);
    }

    /**
     * @return array{type: string, description: string, quantity: float, rate: float, amount: float}
     */
    private function line(string $type, string $description, float $quantity, float $rate): array
    {
        return ['type' => $type, 'description' => $description, 'quantity' => $quantity, 'rate' => $rate, 'amount' => round($quantity * $rate, 2)];
    }

    /**
     * Put the calculated lines on the invoice (replacing earlier calculated ones) and update its total.
     */
    private function fill(ClientInvoice $invoice, Client $client, bool $keepManual = false): ClientInvoice
    {
        $manual = $keepManual ? $invoice->lines()->where('type', ClientInvoiceLine::TYPE_MANUAL)->get() : collect();
        $invoice->lines()->where('type', '!=', ClientInvoiceLine::TYPE_MANUAL)->delete();

        $charges = $this->charges($client, CarbonImmutable::parse($invoice->period_start), CarbonImmutable::parse($invoice->period_end));
        // Calculated lines come first; hand-added lines stay after them.
        $manual->each->delete();
        $invoice->lines()->createMany($charges['lines']);
        $invoice->lines()->createMany($manual->map->only(['type', 'description', 'quantity', 'rate', 'amount'])->all());
        $invoice->update(['currency' => $client->currency]);

        return $this->refreshTotal($invoice);
    }

    private function refreshTotal(ClientInvoice $invoice): ClientInvoice
    {
        $invoice->update(['total' => round((float) $invoice->lines()->sum('amount'), 2)]);

        return $invoice->load('lines');
    }

    private function assertDraft(ClientInvoice $invoice): void
    {
        if ($invoice->status !== ClientInvoice::STATUS_DRAFT) {
            throw ValidationException::withMessages(['invoice' => "{$invoice->invoice_number} is {$invoice->status}; only a draft can be changed."]);
        }
    }
}
