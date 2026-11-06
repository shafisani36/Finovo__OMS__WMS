<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\UnitSerialNumber;
use App\Models\Warehouse;
use App\Services\SerialNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Serial numbers per unit: list and trace units, register units already in stock, and scan units onto an order
 * before dispatch.
 */
class SerialNumberController extends Controller
{
    private const STATUSES = [
        UnitSerialNumber::STATUS_IN_STOCK, UnitSerialNumber::STATUS_ALLOCATED, UnitSerialNumber::STATUS_SHIPPED,
        UnitSerialNumber::STATUS_DAMAGED, UnitSerialNumber::STATUS_QUARANTINED, UnitSerialNumber::STATUS_DISPOSED,
    ];

    public function __construct(
        protected SerialNumberService $serials
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'warehouse_id' => ['nullable', 'integer', $this->accessibleWarehouse($request)],
        ]);

        $page = UnitSerialNumber::where('organization_id', $request->user()->currentOrganization()->id)
            // Units in a warehouse the user does not work in stay hidden; shipped units are in no warehouse.
            ->where(fn ($q) => $this->inUserWarehouses($q->whereNotNull('warehouse_id'), $request)->orWhereNull('warehouse_id'))
            ->when($filters['search'] ?? null, function ($q, $search) {
                $like = str_replace(['%', '_'], ['\%', '\_'], strtoupper(trim($search))).'%';
                $q->where(fn ($q) => $q->where('serial_number', 'like', $like)->orWhereHas('productVariant', fn ($v) => $v->where('sku', 'like', $like)));
            })
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->with(['productVariant.product:id,name', 'warehouse:id,name', 'order:id,order_number'])
            ->latest('id')
            ->paginate($this->perPage($request, 25));

        return response()->json([
            'status' => 'success',
            'data' => collect($page->items())->map(fn (UnitSerialNumber $unit) => $this->present($unit)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * Where a unit is now and everything that happened to it.
     */
    public function trace(Request $request): JsonResponse
    {
        $serial = $request->validate(['serial' => ['required', 'string', 'max:120']])['serial'];
        $units = $this->serials->trace($request->user()->currentOrganization()->id, $serial);

        return response()->json(['status' => 'success', 'data' => $units->map(fn (UnitSerialNumber $unit) => [
            ...$this->present($unit),
            'location' => $unit->location?->code,
            'lot_number' => $unit->lot_number,
            'grn_number' => $unit->goodsReceivedNote?->grn_number,
            'customer' => $unit->order?->customer_name,
            'return_number' => $unit->orderReturn?->return_number,
            'events' => collect($unit->history ?? [])->map(fn (array $event) => ['type' => $event['event'], 'description' => $event['reference'], 'at' => $event['timestamp']])->values(),
        ])]);
    }

    /**
     * Register the serial numbers of units already in a warehouse.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', $this->accessibleWarehouse($request)],
            'product_variant_id' => ['required', 'integer', $this->inOrganization($request, 'product_variants')],
            'serials' => ['required', 'array', 'min:1', 'max:500'],
            'serials.*' => ['required', 'string', 'max:120'],
        ]);
        $units = $this->serials->register(Warehouse::findOrFail($data['warehouse_id']), ProductVariant::findOrFail($data['product_variant_id']), $data['serials'], $request->user());

        return response()->json(['status' => 'success', 'message' => $units->count().' serial number(s) registered.', 'data' => $units->map(fn (UnitSerialNumber $unit) => $unit->only(['id', 'serial_number', 'status']))], 201);
    }

    /**
     * The order's lines that need serial numbers, with the units scanned onto each.
     */
    public function forOrder(Request $request, Order $order): JsonResponse
    {
        $this->ensureSameOrganization($request, $order);
        $order->load('items.variant');

        return response()->json(['status' => 'success', 'data' => $order->items->filter(fn ($item) => $item->variant?->track_serials)->map(fn ($item) => [
            'item_id' => $item->id,
            'product_name' => $item->product_name,
            'sku' => $item->variant->sku,
            'quantity' => $item->quantity,
            'status' => $item->status,
            'serials' => $this->serials->onLine($item)->map(fn (UnitSerialNumber $unit) => $unit->only(['id', 'serial_number', 'status']))->values(),
        ])->values()]);
    }

    public function assign(Request $request, Order $order): JsonResponse
    {
        $this->ensureSameOrganization($request, $order);
        $data = $request->validate([
            'item_id' => ['required', 'integer', Rule::exists('order_items', 'id')->where('order_id', $order->id)],
            'serials' => ['required', 'array', 'min:1', 'max:500'],
            'serials.*' => ['required', 'string', 'max:120'],
        ]);
        $units = $this->serials->assign($order->items()->findOrFail($data['item_id']), $data['serials'], $request->user());

        return response()->json(['status' => 'success', 'message' => 'Serial numbers saved.', 'data' => $units->map(fn (UnitSerialNumber $unit) => $unit->only(['id', 'serial_number', 'status']))->values()]);
    }

    public function unassign(Request $request, Order $order, UnitSerialNumber $unitSerialNumber): JsonResponse
    {
        $this->ensureSameOrganization($request, $order);
        abort_unless($unitSerialNumber->order_id === $order->id, 404);
        $this->serials->unassign($unitSerialNumber, $request->user());

        return response()->json(['status' => 'success', 'message' => "{$unitSerialNumber->serial_number} was taken off order {$order->order_number}."]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(UnitSerialNumber $unit): array
    {
        return [
            'id' => $unit->id,
            'serial' => $unit->serial_number,
            'status' => $unit->status,
            'sku' => $unit->productVariant?->sku,
            'product_name' => $unit->productVariant?->product?->name,
            'warehouse' => $unit->warehouse?->name,
            'order_number' => $unit->order?->order_number,
            'updated_at' => $unit->updated_at,
        ];
    }

    private function ensureSameOrganization(Request $request, Order $order): void
    {
        abort_unless($order->organization_id === $request->user()->currentOrganization()->id, 404);
    }
}
