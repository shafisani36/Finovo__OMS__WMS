<?php

namespace App\Services;

use App\Models\InventoryBalance;
use App\Models\InventoryLedgerEntry;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Insights worked out from the company's records, without an AI service:
 * - likely duplicate orders: the same customer (email, else phone, else name) with the same total within a day;
 * - stock anomalies: stock below zero, and adjustments far larger than the company's usual ones.
 */
class OperationalInsightsService
{
    private const LOOKBACK_DAYS = 30;

    private const DUPLICATE_WINDOW_HOURS = 24;

    /** An adjustment is unusual when it is this many times the average size and at least MIN_UNUSUAL units. */
    private const UNUSUAL_FACTOR = 3;

    private const MIN_UNUSUAL = 10;

    public function __construct(
        protected AccessControl $access
    ) {}

    /**
     * @return array{duplicate_orders: list<array<string, mixed>>, anomalies: list<array<string, mixed>>}
     */
    public function report(User $user): array
    {
        $orgId = $user->currentOrganization()->id;

        return [
            'duplicate_orders' => $this->access->allows($user, 'orders.view') ? $this->duplicateOrders($orgId) : [],
            'anomalies' => $this->access->allows($user, 'inventory.view') ? $this->anomalies($user, $orgId) : [],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function duplicateOrders(int $orgId): array
    {
        $orders = Order::where('organization_id', $orgId)
            ->where('order_status', '!=', 'cancelled')
            ->where('created_at', '>=', now()->subDays(self::LOOKBACK_DAYS))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'order_number', 'customer_name', 'customer_email', 'customer_phone', 'total_amount', 'created_at']);

        $groups = [];
        foreach ($orders->groupBy(fn (Order $o) => $this->customerKey($o).'|'.number_format((float) $o->total_amount, 2, '.', '')) as $key => $same) {
            if (str_starts_with($key, '|') || $same->count() < 2) {
                continue;
            }
            for ($i = 1; $i < $same->count(); $i++) {
                if ($same[$i - 1]->created_at->diffInHours($same[$i]->created_at) <= self::DUPLICATE_WINDOW_HOURS) {
                    $groups[] = [
                        'customer' => $same[$i]->customer_name ?: $same[$i]->customer_email,
                        'total_amount' => (float) $same[$i]->total_amount,
                        'order_numbers' => [$same[$i - 1]->order_number, $same[$i]->order_number],
                    ];
                }
            }
        }

        return $groups;
    }

    private function customerKey(Order $order): string
    {
        return strtolower(trim((string) ($order->customer_email ?: $order->customer_phone ?: $order->customer_name)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function anomalies(User $user, int $orgId): array
    {
        $negative = $this->access->scopeToWarehouses(InventoryBalance::with(['variant:id,sku', 'warehouse:id,name'])->where('organization_id', $orgId), $user)
            ->where(fn ($q) => $q->where('on_hand', '<', 0)->orWhere('available', '<', 0))
            ->get()
            ->map(fn (InventoryBalance $b) => [
                'type' => 'negative_stock',
                'sku' => $b->variant?->sku,
                'warehouse' => $b->warehouse?->name,
                'detail' => "Stock below zero: {$b->on_hand} on hand, {$b->available} available.",
            ]);

        $adjustments = $this->access->scopeToWarehouses(InventoryLedgerEntry::where('organization_id', $orgId), $user)
            ->where('movement_type', 'adjustment')
            ->where('created_at', '>=', now()->subDays(self::LOOKBACK_DAYS));
        $average = (float) (clone $adjustments)->avg(DB::raw('ABS(quantity)'));
        $threshold = (int) ceil(max(self::MIN_UNUSUAL, $average * self::UNUSUAL_FACTOR));

        $large = (clone $adjustments)->with(['warehouse:id,name'])
            ->whereRaw('ABS(quantity) >= ?', [$threshold])
            ->latest('created_at')
            ->limit(50)
            ->get()
            ->map(fn (InventoryLedgerEntry $e) => [
                'type' => 'large_adjustment',
                'reference' => $e->reference_number,
                'warehouse' => $e->warehouse?->name,
                'detail' => "Adjustment of {$e->quantity} units, against a usual size of about ".round($average).'.',
            ]);

        return [...$negative, ...$large];
    }
}
