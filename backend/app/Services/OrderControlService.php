<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Order controls: holds (manual, approval of high-value orders, customer credit limit), splitting and merging
 * orders that are still waiting for stock, and orders at risk of missing the shipping deadline (SLA).
 */
class OrderControlService
{
    public const HOLD_TYPES = ['manual', 'approval', 'credit'];

    /** Orders that can still be held, split or merged: nothing is reserved or picked yet. */
    public const WAITING = ['imported', 'pending', 'exception'];

    /** Orders that still have to ship. */
    private const OPEN = ['imported', 'pending', 'exception', 'allocated', 'partially_allocated', 'picking', 'picked', 'packed'];

    private const SETTINGS_KEY = 'order_controls';

    private const STATUSES_KEY = 'order_statuses';

    /** Colours a company status can have (the frontend's status pill tones). */
    public const STATUS_COLORS = ['grey', 'blue', 'green', 'amber', 'red', 'purple'];

    private const DEFAULT_SLA_HOURS = 48;

    /** Share of the SLA after which an order is at risk. */
    private const AT_RISK_SHARE = 0.75;

    public function __construct(
        protected OrderService $orders
    ) {}

    /**
     * @return array{approval_threshold: float|null, sla_hours: int}
     */
    public function settings(Organization $organization): array
    {
        $saved = $organization->settings[self::SETTINGS_KEY] ?? [];

        return [
            'approval_threshold' => isset($saved['approval_threshold']) ? (float) $saved['approval_threshold'] : null,
            'sla_hours' => (int) ($saved['sla_hours'] ?? self::DEFAULT_SLA_HOURS),
        ];
    }

    /**
     * @param  array{approval_threshold: float|null, sla_hours: int}  $settings
     */
    public function saveSettings(Organization $organization, array $settings): void
    {
        $organization->update(['settings' => [...($organization->settings ?? []), self::SETTINGS_KEY => $settings]]);
    }

    /**
     * The company's own order statuses (e.g. "Awaiting stock", "Gift wrap"), shown next to the system status.
     *
     * @return list<array{key: string, label: string, color: string}>
     */
    public function customStatuses(Organization $organization): array
    {
        // Always key, label, colour: MySQL keeps JSON object keys in its own order, not the order they were saved in.
        return array_map(
            fn (array $status) => ['key' => $status['key'], 'label' => $status['label'], 'color' => $status['color']],
            $organization->settings[self::STATUSES_KEY] ?? [],
        );
    }

    /**
     * @param  list<array{key: string, label: string, color: string}>  $statuses
     */
    public function saveCustomStatuses(Organization $organization, array $statuses): void
    {
        $removed = array_diff(array_column($this->customStatuses($organization), 'key'), array_column($statuses, 'key'));
        $inUse = $removed === [] ? null : Order::where('organization_id', $organization->id)->whereIn('custom_status', $removed)->value('custom_status');
        if ($inUse !== null) {
            throw ValidationException::withMessages(['statuses' => "Status {$inUse} is still on some orders. Change their status first."]);
        }

        $organization->update(['settings' => [...($organization->settings ?? []), self::STATUSES_KEY => array_values($statuses)]]);
    }

    public function setCustomStatus(Order $order, ?string $key, User $user): void
    {
        $statuses = collect($this->customStatuses($order->organization))->keyBy('key');
        if ($key !== null && ! $statuses->has($key)) {
            throw ValidationException::withMessages(['custom_status' => 'Choose one of the company\'s order statuses.']);
        }
        if ($order->custom_status === $key) {
            return;
        }

        $order->forceFill(['custom_status' => $key])->saveQuietly();
        $order->touchListsVersion();
        $order->record('custom_status', $key === null ? 'Status label removed' : "Marked {$statuses[$key]['label']}", $user->id);
    }

    /**
     * A new or changed order that is still waiting is held when it needs approval (total at or above the
     * company's threshold, unless already approved) or puts its customer over their credit limit.
     */
    public function screen(Order $order): void
    {
        if ($order->hold_type !== null || ! in_array($order->order_status, self::WAITING, true)) {
            return;
        }

        $threshold = $this->settings($order->organization)['approval_threshold'];
        if ($threshold !== null && (float) $order->total_amount >= $threshold && ! $order->events()->where('type', 'approved')->exists()) {
            $this->hold($order, 'approval', "Total {$order->currency} ".number_format((float) $order->total_amount, 2)." is at or above the approval limit of {$threshold}.");

            return;
        }

        $customer = $order->customer_id ? Customer::find($order->customer_id) : null;
        if ($customer?->credit_limit !== null) {
            $exposure = (float) Order::where('customer_id', $customer->id)
                ->whereNotIn('order_status', ['cancelled', 'returned'])
                ->whereNotIn('payment_status', ['paid', 'refunded'])
                ->sum('total_amount');

            if ($exposure > (float) $customer->credit_limit) {
                $this->hold($order, 'credit', "Unpaid orders of {$customer->name} come to ".number_format($exposure, 2).', over their credit limit of '.(float) $customer->credit_limit.'.');
            }
        }
    }

    public function hold(Order $order, string $type, string $reason, ?User $user = null): void
    {
        if (! in_array($order->order_status, [...self::WAITING, 'allocated', 'partially_allocated', 'picking', 'picked', 'packed'], true)) {
            throw ValidationException::withMessages(['order' => "Order {$order->order_number} is {$order->order_status} and can no longer be held."]);
        }

        $order->forceFill(['hold_type' => $type, 'hold_reason' => $reason, 'held_at' => now(), 'held_by_user_id' => $user?->id])->saveQuietly();
        $order->touchListsVersion();
        $order->record('held', ucfirst($type === 'manual' ? 'held' : "held for {$type}").": {$reason}", $user?->id);
    }

    /**
     * Releases the hold; releasing an approval hold approves the order.
     */
    public function release(Order $order, User $user): string
    {
        if ($order->hold_type === null) {
            throw ValidationException::withMessages(['order' => "Order {$order->order_number} is not on hold."]);
        }

        $approval = $order->hold_type === 'approval';
        $order->forceFill(['hold_type' => null, 'hold_reason' => null, 'held_at' => null, 'held_by_user_id' => null])->saveQuietly();
        $order->touchListsVersion();
        $order->record($approval ? 'approved' : 'released', $approval ? "Approved by {$user->name}" : "Released by {$user->name}", $user->id);

        return $approval ? "Order {$order->order_number} approved." : "Order {$order->order_number} released.";
    }

    /**
     * Moves the given quantities into a new order (e.g. to ship what is in stock now and the rest later).
     *
     * @param  list<array{item_id: int, quantity: int}>  $lines
     */
    public function split(Order $order, array $lines, User $user): Order
    {
        $this->ensureAdjustable($order);

        return DB::transaction(function () use ($order, $lines, $user) {
            $items = $order->items()->lockForUpdate()->get()->keyBy('id');
            $moving = collect($lines)->filter(fn (array $line) => $line['quantity'] > 0);
            if ($moving->sum('quantity') >= $items->sum('quantity')) {
                throw ValidationException::withMessages(['items' => 'Leave at least one unit on the original order.']);
            }

            $new = $order->replicate(['order_number', 'external_order_id', 'customer_id', 'hold_type', 'hold_reason', 'held_at', 'held_by_user_id']);
            $new->fill(['order_number' => $this->nextSplitNumber($order), 'order_status' => 'pending', 'shipping_amount' => 0, 'discount_amount' => 0, 'tax_amount' => 0]);
            $new->save();

            foreach ($moving as $line) {
                $item = $items->get($line['item_id']) ?? throw ValidationException::withMessages(['items' => 'A line does not belong to this order.']);
                if ($line['quantity'] > $item->quantity) {
                    throw ValidationException::withMessages(['items' => "Only {$item->quantity} of {$item->product_name} are on the order."]);
                }
                $unit = (float) $item->unit_price;
                $new->items()->create([...$item->only(['product_variant_id', 'external_sku', 'product_name', 'image_url', 'unit_price']), 'quantity' => $line['quantity'], 'total_amount' => round($unit * $line['quantity'], 2), 'tax_amount' => 0, 'discount_amount' => 0, 'status' => 'pending']);
                $item->quantity === $line['quantity']
                    ? $item->delete()
                    : $item->update(['quantity' => $item->quantity - $line['quantity'], 'total_amount' => round($unit * ($item->quantity - $line['quantity']), 2)]);
            }

            $this->orders->recalculateTotals($order);
            $this->orders->recalculateTotals($new);
            $order->record('split', "Split: {$moving->sum('quantity')} unit(s) moved to order {$new->order_number}", $user->id);
            $new->record('split', "Split from order {$order->order_number}", $user->id);

            return $new->fresh('items');
        });
    }

    /**
     * Combines orders of the same customer, store and currency into the first one; the others are cancelled.
     *
     * @param  Collection<int, Order>  $orders
     */
    public function merge(Collection $orders, User $user): Order
    {
        $orders = $orders->sortBy('id')->values();
        $target = $orders->first();
        foreach ($orders as $order) {
            $this->ensureAdjustable($order);
            if ($order->store_id !== $target->store_id || $order->currency !== $target->currency || strtolower((string) $order->customer_email) !== strtolower((string) $target->customer_email)) {
                throw ValidationException::withMessages(['order_ids' => 'Only orders of the same customer, store and currency can be merged.']);
            }
        }

        return DB::transaction(function () use ($orders, $target, $user) {
            foreach ($orders->slice(1) as $order) {
                $order->items()->update(['order_id' => $target->id]);
                $order->update(['order_status' => 'cancelled', 'fulfillment_status' => 'cancelled', 'notes' => trim($order->notes."\nMerged into order {$target->order_number}.")]);
                $order->record('merged', "Merged into order {$target->order_number}", $user->id);
            }
            $this->orders->recalculateTotals($target);
            $target->record('merged', 'Merged with order(s) '.$orders->slice(1)->pluck('order_number')->implode(', '), $user->id);

            return $target->fresh('items');
        });
    }

    /**
     * Open orders past the shipping deadline (breached) or close to it (at risk), oldest first.
     *
     * @return array{sla_hours: int, breached: list<array<string, mixed>>, at_risk: list<array<string, mixed>>}
     */
    public function sla(Organization $organization, ?int $storeId = null): array
    {
        $hours = $this->settings($organization)['sla_hours'];

        // Worked out again after an order changes, and at least every minute (the hours left keep running).
        return Cache::remember(
            "orders-sla:{$organization->id}:".Order::listsVersion($organization->id).":{$hours}:{$storeId}",
            now()->addMinute(),
            fn () => $this->slaReport($organization, $hours, $storeId),
        );
    }

    /**
     * @return array{sla_hours: int, breached: list<array<string, mixed>>, at_risk: list<array<string, mixed>>}
     */
    private function slaReport(Organization $organization, int $hours, ?int $storeId): array
    {
        $orders = Order::where('organization_id', $organization->id)
            ->whereIn('order_status', self::OPEN)
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->where(DB::raw('COALESCE(placed_at, created_at)'), '<=', now()->subMinutes((int) ($hours * 60 * self::AT_RISK_SHARE)))
            ->orderByRaw('COALESCE(placed_at, created_at)')
            ->limit(200)
            ->get(['id', 'order_number', 'customer_name', 'order_status', 'placed_at', 'created_at']);

        $present = fn (Order $order) => [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'customer' => $order->customer_name,
            'status' => $order->order_status,
            'hours_left' => round($hours - ($order->placed_at ?? $order->created_at)->diffInMinutes(now()) / 60, 1),
        ];
        [$breached, $atRisk] = $orders->map($present)->partition(fn (array $row) => $row['hours_left'] <= 0);

        return ['sla_hours' => $hours, 'breached' => $breached->values()->all(), 'at_risk' => $atRisk->values()->all()];
    }

    private function ensureAdjustable(Order $order): void
    {
        if ($order->external_order_id) {
            throw ValidationException::withMessages(['order' => "Order {$order->order_number} came from its store; split or merge it there."]);
        }
        if (! in_array($order->order_status, self::WAITING, true) || $order->items()->where('status', 'allocated')->exists()) {
            throw ValidationException::withMessages(['order' => "Order {$order->order_number} already has stock reserved; split or merge orders before they are allocated."]);
        }
    }

    private function nextSplitNumber(Order $order): string
    {
        $suffix = 2;
        while (Order::where('organization_id', $order->organization_id)->where('order_number', "{$order->order_number}-{$suffix}")->exists()) {
            $suffix++;
        }

        return "{$order->order_number}-{$suffix}";
    }
}
