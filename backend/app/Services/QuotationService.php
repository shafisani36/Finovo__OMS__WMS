<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates and updates quotations, moves them through their statuses and converts accepted ones into orders.
 *
 * Line maths: gross = quantity × unit price; discount = gross × discount %; tax = (gross − discount) × tax %;
 * line total = gross − discount + tax. Quotation totals are the sums of the lines.
 */
class QuotationService
{
    /** Which status each status may move to. */
    private const TRANSITIONS = [
        Quotation::STATUS_DRAFT => [Quotation::STATUS_SENT, Quotation::STATUS_ACCEPTED, Quotation::STATUS_REJECTED],
        Quotation::STATUS_SENT => [Quotation::STATUS_ACCEPTED, Quotation::STATUS_REJECTED],
        Quotation::STATUS_ACCEPTED => [],
        Quotation::STATUS_REJECTED => [],
        Quotation::STATUS_CONVERTED => [],
    ];

    public const DEFAULT_VALID_DAYS = 15;

    /** Attempts to get a free quotation number when two quotations are created at the same moment. */
    private const NUMBER_ATTEMPTS = 3;

    public function __construct(
        protected OrderAllocationEngine $allocation,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated by SaveQuotationRequest
     */
    public function create(Organization $organization, User $user, array $data): Quotation
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->createWithNextNumber($organization, $user, $data);
            } catch (UniqueConstraintViolationException $e) {
                // Another quotation took the same number in parallel; take the next one.
                if ($attempt >= self::NUMBER_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createWithNextNumber(Organization $organization, User $user, array $data): Quotation
    {
        return DB::transaction(function () use ($organization, $user, $data) {
            $quotation = Quotation::create($this->headerAttributes($data) + [
                'organization_id' => $organization->id,
                'quotation_number' => $this->nextNumber($organization->id),
                'status' => Quotation::STATUS_DRAFT,
                'created_by' => $user->id,
            ]);

            $this->saveItems($quotation, $data['items']);

            return $quotation->fresh(['items', 'store', 'customer']);
        });
    }

    /**
     * @param  array<string, mixed>  $data  validated by SaveQuotationRequest
     */
    public function update(Quotation $quotation, array $data): Quotation
    {
        return DB::transaction(function () use ($quotation, $data) {
            $quotation = Quotation::lockForUpdate()->findOrFail($quotation->id);

            if (! $quotation->isEditable()) {
                throw ValidationException::withMessages(['status' => "A {$quotation->status} quotation can no longer be edited."]);
            }

            $quotation->update($this->headerAttributes($data));
            $quotation->items()->delete();
            $this->saveItems($quotation, $data['items']);

            return $quotation->fresh(['items', 'store', 'customer']);
        });
    }

    public function changeStatus(Quotation $quotation, string $status): Quotation
    {
        return DB::transaction(function () use ($quotation, $status) {
            // Lock the row so two people clicking at the same time cannot both change it.
            $quotation = Quotation::lockForUpdate()->findOrFail($quotation->id);

            return $this->applyStatus($quotation, $status);
        });
    }

    private function applyStatus(Quotation $quotation, string $status): Quotation
    {
        if (! in_array($status, self::TRANSITIONS[$quotation->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => "A {$quotation->status} quotation cannot be marked as {$status}."]);
        }

        if ($status === Quotation::STATUS_ACCEPTED && $quotation->is_expired) {
            throw ValidationException::withMessages(['status' => 'This quotation expired on '.$quotation->valid_until->toFormattedDateString().'. Extend its validity before accepting it.']);
        }

        $quotation->update([
            'status' => $status,
            'sent_at' => $status === Quotation::STATUS_SENT ? now() : $quotation->sent_at,
            'responded_at' => in_array($status, [Quotation::STATUS_ACCEPTED, Quotation::STATUS_REJECTED], true) ? now() : $quotation->responded_at,
        ]);

        return $quotation;
    }

    /**
     * Turn an accepted quotation into a pending order with the same lines and prices, then try to allocate stock.
     */
    public function convertToOrder(Quotation $quotation): Order
    {
        $order = DB::transaction(function () use ($quotation) {
            // Locked and re-checked inside the transaction, so a double click creates only one order.
            $quotation = Quotation::with('items')->lockForUpdate()->findOrFail($quotation->id);

            if ($quotation->status !== Quotation::STATUS_ACCEPTED) {
                throw ValidationException::withMessages(['status' => 'Only an accepted quotation can be converted into an order.']);
            }

            $order = Order::create([
                'organization_id' => $quotation->organization_id,
                'store_id' => $quotation->store_id,
                'order_number' => 'ORD-'.$quotation->quotation_number,
                'external_order_id' => $quotation->quotation_number,
                'customer_name' => $quotation->customer_name,
                'customer_email' => $quotation->customer_email,
                'customer_phone' => $quotation->customer_phone,
                'shipping_address' => $quotation->billing_address,
                'order_status' => 'pending',
                'payment_status' => 'pending',
                'fulfillment_status' => 'unfulfilled',
                'total_amount' => $quotation->total_amount,
                'currency' => $quotation->currency,
                'notes' => "Created from quotation {$quotation->quotation_number}.",
                'placed_at' => now(),
            ]);

            foreach ($quotation->items as $item) {
                $order->items()->create([
                    'product_variant_id' => $item->product_variant_id,
                    'external_sku' => $item->sku ?? '',
                    'product_name' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'tax_amount' => $this->lineAmounts($item->toArray())['tax'],
                    'total_amount' => $item->line_total,
                    'status' => 'pending',
                ]);
            }

            $quotation->update(['status' => Quotation::STATUS_CONVERTED, 'order_id' => $order->id]);

            return $order;
        });

        return $this->allocation->allocateOrder($order);
    }

    /**
     * QT-2026-0001, numbered per organization and year.
     */
    private function nextNumber(int $organizationId): string
    {
        $prefix = 'QT-'.now()->year.'-';
        $last = Quotation::where('organization_id', $organizationId)
            ->where('quotation_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('quotation_number')
            ->value('quotation_number');

        return $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function headerAttributes(array $data): array
    {
        $totals = ['subtotal' => 0.0, 'discount' => 0.0, 'tax' => 0.0, 'total' => 0.0];

        foreach ($data['items'] as $item) {
            foreach ($this->lineAmounts($item) as $key => $amount) {
                $totals[$key] += $amount;
            }
        }

        $issueDate = $data['issue_date'] ?? now()->toDateString();

        return [
            'store_id' => $data['store_id'],
            'customer_id' => $data['customer_id'] ?? null,
            'customer_name' => $data['customer_name'],
            'customer_email' => $data['customer_email'] ?? null,
            'customer_phone' => $data['customer_phone'] ?? null,
            'billing_address' => array_filter($data['billing_address'] ?? [], fn ($value) => filled($value)) ?: null,
            'currency' => strtoupper($data['currency']),
            'issue_date' => $issueDate,
            'valid_until' => $data['valid_until'] ?? now()->parse($issueDate)->addDays(self::DEFAULT_VALID_DAYS)->toDateString(),
            'subtotal' => round($totals['subtotal'], 2),
            'discount_total' => round($totals['discount'], 2),
            'tax_total' => round($totals['tax'], 2),
            'total_amount' => round($totals['total'], 2),
            'notes' => $data['notes'] ?? null,
            'terms' => $data['terms'] ?? null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function saveItems(Quotation $quotation, array $items): void
    {
        $variants = ProductVariant::where('organization_id', $quotation->organization_id)
            ->whereIn('id', array_filter(array_column($items, 'product_variant_id')))
            ->get()
            ->keyBy('id');

        foreach (array_values($items) as $position => $item) {
            $variant = $variants->get($item['product_variant_id'] ?? null);

            $quotation->items()->create([
                'product_variant_id' => $variant?->id,
                'sku' => ($item['sku'] ?? null) ?: $variant?->sku,
                'description' => $item['description'],
                'quantity' => (int) $item['quantity'],
                'unit_price' => $item['unit_price'],
                'discount_percent' => $item['discount_percent'] ?? 0,
                'tax_percent' => $item['tax_percent'] ?? 0,
                'line_total' => $this->lineAmounts($item)['total'],
                'position' => $position,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{subtotal: float, discount: float, tax: float, total: float}
     */
    private function lineAmounts(array $item): array
    {
        $gross = (int) $item['quantity'] * (float) $item['unit_price'];
        $discount = round($gross * (float) ($item['discount_percent'] ?? 0) / 100, 2);
        $tax = round(($gross - $discount) * (float) ($item['tax_percent'] ?? 0) / 100, 2);

        return ['subtotal' => $gross, 'discount' => $discount, 'tax' => $tax, 'total' => round($gross - $discount + $tax, 2)];
    }
}
