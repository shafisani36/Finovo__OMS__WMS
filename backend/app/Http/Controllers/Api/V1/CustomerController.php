<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Customers built from the orders of connected stores. With store_id, the list and its figures cover that store only.
 */
class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();
        $storeId = $request->integer('store_id') ?: null;
        $ordersInScope = fn ($query) => $query->when($storeId, fn ($q) => $q->where('store_id', $storeId));
        $base = fn () => Customer::where('organization_id', $org->id)->whereHas('orders', $ordersInScope);

        $customers = $base()
            ->when($request->query('search'), function (Builder $query, string $search) {
                $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%"));
            })
            ->withCount(['orders as orders_count' => $ordersInScope])
            ->withMax(['orders as latest_order_at' => $ordersInScope], 'placed_at')
            ->withMin(['orders as first_order_at' => $ordersInScope], 'placed_at')
            ->orderByDesc('latest_order_at')
            ->orderByDesc('id')
            ->paginate(min(max($request->integer('per_page', 10), 1), 100));

        $spending = $this->spendingByCurrency(
            Order::whereIn('customer_id', $customers->pluck('id'))->when($storeId, fn ($q) => $q->where('store_id', $storeId))->get(),
        );
        $stores = Order::whereIn('customer_id', $customers->pluck('id'))
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->with('store:id,name,platform')
            ->get(['customer_id', 'store_id'])
            ->groupBy('customer_id')
            ->map(fn ($orders) => $orders->pluck('store')->filter()->unique('id')->values());

        $data = collect($customers->items())->map(fn (Customer $customer) => $customer->toArray() + [
            'spent' => $spending->get($customer->id, []),
            'stores' => $stores->get($customer->id, collect()),
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $data,
            'meta' => [
                'current_page' => $customers->currentPage(),
                'last_page' => $customers->lastPage(),
                'per_page' => $customers->perPage(),
                'total' => $customers->total(),
            ],
            'kpis' => [
                'total_customers' => $base()->count(),
                'repeat_buyers' => $base()->whereHas('orders', $ordersInScope, '>=', 2)->count(),
                'new_last_30_days' => $base()->whereDoesntHave('orders', fn ($q) => $ordersInScope($q)->where('placed_at', '<', now()->subDays(30)))->count(),
                'with_email' => $base()->whereNotNull('email')->count(),
            ],
        ]);
    }

    public function show(Request $request, Customer $customer): JsonResponse
    {
        abort_if($customer->organization_id !== $request->user()->currentOrganization()->id, 404);

        $orders = $customer->orders()
            ->with(['store:id,name,platform', 'items.variant.product:id,name,image_url,image_path'])
            ->latest('placed_at')
            ->latest('id')
            ->get();
        $items = $orders->where('order_status', '!=', 'cancelled')->flatMap->items;

        $stores = $orders->groupBy('store_id')->map(fn ($storeOrders) => [
            'id' => $storeOrders->first()->store?->id,
            'name' => $storeOrders->first()->store?->name,
            'platform' => $storeOrders->first()->store?->platform,
            'orders_count' => $storeOrders->count(),
            'last_order_at' => $storeOrders->max('placed_at'),
        ])->values();

        return response()->json([
            'status' => 'success',
            'data' => $customer->toArray() + [
                'stats' => [
                    'orders_count' => $orders->count(),
                    'cancelled_orders' => $orders->where('order_status', 'cancelled')->count(),
                    // One entry per currency: stores may sell in different currencies, and amounts are never added across them.
                    'spent' => $this->spendingByCurrency($orders)->get($customer->id, []),
                    'items_purchased' => (int) $items->sum('quantity'),
                    'first_order_at' => $orders->min('placed_at'),
                    'last_order_at' => $orders->max('placed_at'),
                ],
                'stores' => $stores,
                'orders' => $orders->map(fn (Order $order) => [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'external_order_id' => $order->external_order_id,
                    'store' => $order->store,
                    'order_status' => $order->order_status,
                    'payment_status' => $order->payment_status,
                    'fulfillment_status' => $order->fulfillment_status,
                    'total_amount' => $order->total_amount,
                    'currency' => $order->currency,
                    'notes' => $order->notes,
                    'placed_at' => $order->placed_at,
                    'shipping_address' => $order->shipping_address,
                    'items' => $order->items->map(fn (OrderItem $item) => [
                        'id' => $item->id,
                        'product_name' => $item->product_name,
                        'external_sku' => $item->external_sku,
                        'image_url' => $item->variant?->product?->image_url,
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unit_price,
                        'total_amount' => $item->total_amount,
                        'status' => $item->status,
                    ]),
                ]),
            ],
        ]);
    }

    /**
     * Amount spent per customer and currency (cancelled orders excluded), largest first.
     *
     * @param  Collection<int, Order>  $orders
     * @return Collection<int, list<array{currency: string, amount: float, orders: int, average: float}>>
     */
    private function spendingByCurrency(Collection $orders): Collection
    {
        return $orders->where('order_status', '!=', 'cancelled')
            ->groupBy('customer_id')
            ->map(fn (Collection $customerOrders) => $customerOrders->groupBy('currency')
                ->map(fn (Collection $sameCurrency, string $currency) => [
                    'currency' => $currency,
                    'amount' => round((float) $sameCurrency->sum('total_amount'), 2),
                    'orders' => $sameCurrency->count(),
                    'average' => round((float) $sameCurrency->avg('total_amount'), 2),
                ])
                ->sortByDesc('amount')
                ->values()
                ->all());
    }
}
