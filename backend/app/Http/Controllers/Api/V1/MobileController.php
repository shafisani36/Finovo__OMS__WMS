<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CycleCount;
use App\Models\InventoryLocationBalance;
use App\Models\InventoryReceipt;
use App\Models\PickTask;
use App\Models\PickWave;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\AccessControl;
use App\Services\BarcodeLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Look-ups for the mobile scanning app: the work waiting in a warehouse, and what a scanned product or bin is.
 * Picking, receiving, putaway and counting themselves use the same endpoints as the desktop screens.
 */
class MobileController extends Controller
{
    public function __construct(
        protected BarcodeLookup $lookup
    ) {}

    /**
     * Work waiting in the warehouse, for the app's home screen.
     */
    public function summary(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->authorizeWarehouse($request, $warehouse);

        // With ?store_id= (the store being managed) only that store's orders and products are counted.
        $storeId = $request->integer('store_id');
        $tasks = fn () => PickTask::where('warehouse_id', $warehouse->id)
            ->when($storeId, fn ($q) => $q->whereHas('order', fn ($o) => $o->where('store_id', $storeId)));
        $receipts = fn () => $this->forStoreProducts(InventoryReceipt::where('warehouse_id', $warehouse->id), $request, 'items.variant');

        return response()->json([
            'status' => 'success',
            'data' => [
                'to_pick' => $tasks()->whereIn('status', ['not_started', 'in_progress'])->count(),
                'to_pack' => $tasks()->where('status', 'picked')->count(),
                'open_waves' => PickWave::where('warehouse_id', $warehouse->id)->where('status', PickWave::STATUS_OPEN)
                    ->when($storeId, fn ($q) => $q->whereHas('tasks.order', fn ($o) => $o->where('store_id', $storeId)))
                    ->count(),
                'to_receive' => $receipts()->where('status', 'draft')->count(),
                'to_put_away' => $receipts()->whereIn('status', ['received', 'putaway_in_progress'])->count(),
                'to_count' => $this->forStoreProducts(CycleCount::where('warehouse_id', $warehouse->id), $request, 'items.variant')
                    ->where('status', 'in_progress')->count(),
            ],
        ]);
    }

    /**
     * A scanned product and the bins of the warehouse holding it (first expiry first).
     */
    public function product(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->authorizeWarehouse($request, $warehouse);
        $code = $this->lookup->code((string) $request->validate(['code' => ['required', 'string', 'max:2000']])['code']);
        $variant = $this->lookup->variant($warehouse->organization_id, $code)?->load('product:id,name,image_url,image_path');

        abort_unless($variant, 404, "No product has the barcode or SKU \"{$code}\".");

        $bins = InventoryLocationBalance::with('location:id,code,location_type')
            ->where('warehouse_id', $warehouse->id)
            ->where('product_variant_id', $variant->id)
            ->where('quantity', '>', 0)
            ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expiry_date')
            ->orderByDesc('quantity')
            ->get()
            ->map(fn (InventoryLocationBalance $balance) => [
                'location_id' => $balance->warehouse_location_id,
                'code' => $balance->location?->code,
                'location_type' => $balance->location?->location_type,
                'lot_number' => $balance->lot_number,
                'expiry_date' => $balance->expiry_date?->toDateString(),
                'condition' => $balance->condition,
                'quantity' => (int) $balance->quantity,
            ]);

        return response()->json([
            'status' => 'success',
            'data' => [
                'product_variant_id' => $variant->id,
                'sku' => $variant->sku,
                'barcode' => $variant->barcode,
                'name' => $variant->product?->name,
                'variant' => $variant->option_value,
                'image_url' => $variant->product?->image_url,
                'bins' => $bins,
            ],
        ]);
    }

    /**
     * A scanned bin (by its barcode or code) and what is in it.
     */
    public function location(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->authorizeWarehouse($request, $warehouse);
        $code = trim($this->lookup->code((string) $request->validate(['code' => ['required', 'string', 'max:200']])['code']));

        $location = WarehouseLocation::where('warehouse_id', $warehouse->id)
            ->where(fn ($q) => $q->where('barcode', $code)->orWhere('code', $code))
            ->orderByRaw('CASE WHEN barcode = ? THEN 0 ELSE 1 END', [$code])
            ->first();

        abort_unless($location, 404, "No bin in {$warehouse->name} has the code \"{$code}\".");

        $stock = InventoryLocationBalance::with('variant.product:id,name')
            ->where('warehouse_location_id', $location->id)
            ->where('quantity', '>', 0)
            ->get()
            ->map(fn (InventoryLocationBalance $balance) => [
                'product_variant_id' => $balance->product_variant_id,
                'sku' => $balance->variant?->sku,
                'name' => $balance->variant?->product?->name,
                'lot_number' => $balance->lot_number,
                'quantity' => (int) $balance->quantity,
            ]);

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $location->id,
                'code' => $location->code,
                'location_type' => $location->location_type,
                'putaway_eligible' => (bool) $location->putaway_eligible,
                'is_active' => (bool) $location->is_active,
                'stock' => $stock,
            ],
        ]);
    }

    private function authorizeWarehouse(Request $request, Warehouse $warehouse): void
    {
        abort_unless($warehouse->organization_id === $request->user()->currentOrganization()->id, 404);
        abort_unless(app(AccessControl::class)->canAccessWarehouse($request->user(), $warehouse->id), 403, 'You are not assigned to this warehouse.');
    }
}
