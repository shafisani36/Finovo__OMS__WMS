<?php

namespace App\Services;

use App\Models\CycleCountItem;
use App\Models\GoodsReceivedNoteItem;
use App\Models\InventoryBalance;
use App\Models\InventoryLedgerEntry;
use App\Models\InventoryReceiptItem;
use App\Models\InventoryReservation;
use App\Models\InventoryTransfer;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequisitionItem;
use App\Models\ReorderRule;
use App\Models\ReturnItem;
use App\Models\SkuMapping;
use App\Models\StockAdjustment;
use App\Models\Store;
use App\Models\StoreProductLink;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Products created and changed in Finovo, sent to the connected stores they are sold on.
 * Products and variants with history (stock, orders, purchases) are archived instead of deleted, so
 * every past document still shows what was moved or sold.
 */
class ProductService
{
    /** Records that keep a variant from being deleted. */
    private const HISTORY = [
        InventoryLedgerEntry::class, OrderItem::class, PurchaseOrderItem::class, PurchaseRequisitionItem::class,
        GoodsReceivedNoteItem::class, InventoryReceiptItem::class, StockAdjustment::class, CycleCountItem::class,
        InventoryTransfer::class, InventoryReservation::class, ReturnItem::class,
    ];

    public function __construct(
        protected StorePushService $push
    ) {}

    /**
     * Create the product (no $product) or update it, then send it to its stores and to $storeIds.
     *
     * @param  array<string, mixed>  $data  validated product fields with variants[]
     * @param  list<int>  $storeIds  stores to add the product to
     * @return array{0: Product, 1: PushReport}
     */
    public function save(int $organizationId, array $data, ?Product $product = null, array $storeIds = []): array
    {
        $product = DB::transaction(function () use ($organizationId, $data, $product) {
            $fields = collect($data)->only(['name', 'sku', 'category', 'description', 'image_url', 'is_active'])->all();

            $product = $product
                ? tap($product)->update($fields)
                : Product::create([...$fields, 'organization_id' => $organizationId, 'is_active' => $data['is_active'] ?? true]);

            $this->saveVariants($product, $data['variants']);

            return $product;
        });

        $stores = Store::where('organization_id', $organizationId)->whereIn('id', $storeIds)->get();
        $product = $product->fresh('variants');

        $this->linkToFinovoStores($product, $stores);

        return [$product, $this->push->pushProduct($product, $stores)];
    }

    /**
     * Put the product in the catalog of stores kept in Finovo only (no shop to create it on), so it is listed
     * with that store's products, stock and movements. Stores it is already in also get its new variants.
     *
     * @param  Collection<int, Store>  $chosen
     */
    private function linkToFinovoStores(Product $product, Collection $chosen): void
    {
        $variantIds = $product->variants->pluck('id');
        $alreadyIn = Store::whereIn('id', SkuMapping::whereIn('product_variant_id', $variantIds)->select('store_id'))->get();

        $stores = $chosen->concat($alreadyIn)->unique('id')->filter(fn (Store $store) => $store->platform->keepsCatalogInFinovo());

        foreach ($stores as $store) {
            foreach ($product->variants as $variant) {
                SkuMapping::addToFinovoStore($store, $variant->setRelation('product', $product));
            }
        }
    }

    /**
     * Delete a product that was never used, also from its stores. A product with history is archived instead.
     */
    public function delete(Product $product): PushReport
    {
        $variantIds = $product->variants()->pluck('id');

        if ($this->hasHistory($variantIds)) {
            throw ValidationException::withMessages([
                'product' => "{$product->name} has stock, order or purchase history, so it cannot be deleted. Archive it instead; it stays in past documents.",
            ]);
        }

        $report = $this->push->deleteProduct($product);

        DB::transaction(function () use ($product, $variantIds) {
            InventoryBalance::whereIn('product_variant_id', $variantIds)->delete();
            ReorderRule::whereIn('product_variant_id', $variantIds)->delete();
            SkuMapping::whereIn('product_variant_id', $variantIds)->delete();
            StoreProductLink::where('product_id', $product->id)->delete();
            $product->variants()->delete();
            $product->delete();
        });

        return $report;
    }

    /**
     * Create, update and remove variants to match the request. A removed variant with history is refused.
     *
     * @param  list<array<string, mixed>>  $variants
     */
    private function saveVariants(Product $product, array $variants): void
    {
        $existing = $product->variants()->get()->keyBy('id');
        $keptIds = collect($variants)->pluck('id')->filter()->map(fn ($id) => (int) $id);

        $removed = $existing->keys()->diff($keptIds);
        if ($removed->isNotEmpty() && $this->hasHistory($removed)) {
            throw ValidationException::withMessages([
                'variants' => 'A variant you removed has stock, order or purchase history. Mark it inactive instead of removing it.',
            ]);
        }

        SkuMapping::whereIn('product_variant_id', $removed)->delete();
        InventoryBalance::whereIn('product_variant_id', $removed)->delete();
        ProductVariant::whereIn('id', $removed)->delete();

        foreach ($variants as $variantData) {
            $fields = [
                'sku' => $variantData['sku'],
                'barcode' => $variantData['barcode'] ?? null,
                'option_name' => $variantData['option_name'] ?? null,
                'option_value' => $variantData['option_value'] ?? null,
                'price' => $variantData['price'],
                'cost_price' => $variantData['cost_price'] ?? 0,
                'is_active' => $variantData['is_active'] ?? true,
            ];

            $variant = isset($variantData['id']) ? $existing->get((int) $variantData['id']) : null;
            $variant
                ? $variant->update($fields)
                : $product->variants()->create([...$fields, 'organization_id' => $product->organization_id]);
        }
    }

    /**
     * @param  Collection<int, int>  $variantIds
     */
    private function hasHistory(Collection $variantIds): bool
    {
        if ($variantIds->isEmpty()) {
            return false;
        }

        return InventoryBalance::whereIn('product_variant_id', $variantIds)->where(fn ($q) => $q->where('on_hand', '!=', 0)->orWhere('reserved', '!=', 0))->exists()
            || collect(self::HISTORY)->contains(fn (string $model) => $model::whereIn('product_variant_id', $variantIds)->exists());
    }
}
