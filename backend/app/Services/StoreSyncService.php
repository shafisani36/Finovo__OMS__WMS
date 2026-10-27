<?php

namespace App\Services;

use App\Integrations\Sync\Contracts\StoreDataSource;
use App\Integrations\Sync\Data\OrderData;
use App\Integrations\Sync\Data\OrderLineData;
use App\Integrations\Sync\Data\ProductData;
use App\Integrations\Sync\Data\VariantData;
use App\Integrations\Sync\StoreDataSourceFactory;
use App\Integrations\Sync\SyncResult;
use App\Models\IntegrationJob;
use App\Models\InventoryBalance;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SkuMapping;
use App\Models\Store;
use App\Models\StoreProductLink;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Imports a store's catalog (products, images, variants, prices) and orders (with customer details) into Finovo.
 *
 * Store products are matched to Finovo variants through SKU mappings, or by SKU, so a product sold in several
 * stores shares one Finovo variant and one stock balance. Stock is imported once, as opening stock in the
 * store's default warehouse; after that Finovo's warehouse is the source of truth for inventory.
 */
class StoreSyncService
{
    /** Orders changed shortly before the previous sync are read again, so none are missed between runs. */
    private const ORDER_OVERLAP_MINUTES = 10;

    /** The Orders page asks for new orders each time it opens; the store is read at most this often for it. */
    private const ORDER_REFRESH_SECONDS = 60;

    public function __construct(
        protected StoreDataSourceFactory $sources,
        protected InventoryEngine $inventory,
        protected ProductImageStore $images,
        protected EmailService $emails,
        protected OrderService $orders,
        protected StoreSyncOutbox $outbox,
    ) {}

    public function canSync(Store $store): bool
    {
        return $store->isConnected() && $this->sources->supports($store->platform);
    }

    /**
     * Run a full sync for one store and record the outcome on the store and as an integration job.
     *
     * @throws Throwable when the store cannot be read; the failure is recorded first
     */
    public function sync(Store $store): SyncResult
    {
        $startedAt = now();
        $result = new SyncResult;
        $source = $this->sources->for($store->platform);
        $job = IntegrationJob::create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'job_type' => 'store_sync',
            'status' => 'processing',
            'attempts' => 1,
            'last_attempt_at' => $startedAt,
        ]);

        try {
            $warehouse = $this->defaultWarehouse($store);

            $imagesNotSaved = 0;

            foreach ($source->products($store) as $product) {
                if (! $this->importProduct($store, $product, $warehouse, $result)) {
                    $imagesNotSaved++;
                }
            }

            $this->importChangedOrders($store, $source, $result);

            $result->warnings = $source->warnings();

            if ($imagesNotSaved > 0) {
                $result->warnings[] = "{$imagesNotSaved} product images could not be downloaded, so the store's own image links are used for them. The next sync tries again.";
            }

            if (! $warehouse) {
                $result->warnings[] = 'No warehouse is linked to this store, so stock was not imported.';
            }
        } catch (Throwable $e) {
            $job->update(['status' => 'failed', 'error_message' => $e->getMessage(), 'payload' => $result->toArray()]);
            $store->update(['sync_status' => 'sync_issues', 'connection_error' => 'Sync failed: '.$e->getMessage()]);

            throw $e;
        }

        $job->update(['status' => 'succeeded', 'payload' => $result->toArray()]);
        $store->update([
            'last_sync_at' => $startedAt,
            'sync_status' => 'healthy',
            'connection_error' => $result->warnings ? implode(' ', $result->warnings) : null,
            'orders_awaiting_sync' => 0,
        ]);

        return $result;
    }

    /**
     * Import the orders created or changed on the store since it was last read, so the Orders page shows them
     * without pressing Sync. Skipped (null) before the store's first full sync, when it was read moments ago,
     * or while another import of it is running.
     *
     * @throws Throwable when the store cannot be read
     */
    public function syncOrders(Store $store): ?SyncResult
    {
        if (! $store->last_sync_at || $store->last_sync_at->gt(now()->subSeconds(self::ORDER_REFRESH_SECONDS))) {
            return null;
        }

        return Cache::lock("store-order-import:{$store->id}", 120)->get(function () use ($store) {
            $startedAt = now();
            $result = new SyncResult;
            $this->importChangedOrders($store, $this->sources->for($store->platform), $result);
            $store->update(['last_sync_at' => $startedAt]);

            return $result;
        }) ?: null;
    }

    /**
     * Orders changed shortly before the store was last read are read again, so none are missed between runs.
     */
    private function importChangedOrders(Store $store, StoreDataSource $source, SyncResult $result): void
    {
        $since = $store->last_sync_at?->copy()->subMinutes(self::ORDER_OVERLAP_MINUTES);

        foreach ($source->orders($store, $since) as $order) {
            $this->importOrder($store, $order, $result);
        }
    }

    /**
     * Save one product from the store (a full sync, or a webhook for a single product).
     *
     * @return bool false when the product photo could not be downloaded
     */
    public function importProduct(Store $store, ProductData $data, ?Warehouse $warehouse = null, ?SyncResult $result = null): bool
    {
        $saved = $this->saveProduct($store, $data, $warehouse ?? $this->defaultWarehouse($store), $result ?? new SyncResult);

        // Outside the database transaction: a slow or failed download never blocks the catalog import.
        return $this->images->store($saved) !== false;
    }

    /**
     * Save one order from the store (a full sync, or a webhook for a single order).
     */
    public function importOrder(Store $store, OrderData $data, ?SyncResult $result = null): void
    {
        $this->saveOrder($store, $data, $result ?? new SyncResult);
    }

    /**
     * The store deleted a product: its link to this store is removed, and the Finovo product is archived when
     * no other store sells it. Stock and history stay in Finovo.
     */
    public function removeProduct(Store $store, string $externalId): void
    {
        DB::transaction(function () use ($store, $externalId) {
            $link = StoreProductLink::where('store_id', $store->id)->where('external_id', $externalId)->first();
            $product = $link?->product;

            if (! $product) {
                return;
            }

            SkuMapping::where('store_id', $store->id)->whereIn('product_variant_id', $product->variants()->pluck('id'))->delete();
            $link->delete();

            $soldElsewhere = StoreProductLink::where('product_id', $product->id)->exists()
                || SkuMapping::whereIn('product_variant_id', $product->variants()->pluck('id'))->exists();

            if (! $soldElsewhere) {
                $product->update(['is_active' => false]);
                $product->variants()->update(['is_active' => false]);
            }
        });
    }

    private function defaultWarehouse(Store $store): ?Warehouse
    {
        return $store->warehouses()
            ->orderByDesc('store_warehouse_assignments.is_default')
            ->orderBy('store_warehouse_assignments.priority')
            ->first();
    }

    private function saveProduct(Store $store, ProductData $data, ?Warehouse $warehouse, SyncResult $result): Product
    {
        return DB::transaction(function () use ($store, $data, $warehouse, $result) {
            $mapped = SkuMapping::where('store_id', $store->id)
                ->whereIn('external_id', array_map(fn (VariantData $variant) => $variant->externalId, $data->variants))
                ->with('variant.product')
                ->get()
                ->keyBy('external_id');

            $product = $mapped->first()?->variant?->product
                ?? $this->variantBySku($store, $data->variants)?->product
                ?? $this->orphanedProduct($store, $data);

            $attributes = [
                'name' => $data->name,
                'description' => $data->description,
                'category' => $data->category,
                'image_url' => $data->imageUrl,
                'images' => array_values(array_unique(array_filter([$data->imageUrl, ...$data->images]))) ?: null,
                'details' => $data->details ?: null,
                'is_active' => $data->isActive,
            ];

            // Each store keeps its own original record, so a product sold in several stores never mixes them.
            if ($data->raw) {
                $attributes['platform_data'] = [...($product?->platform_data ?? []), (string) $store->id => $data->raw];
            }

            if ($product) {
                $product->update($attributes);
                $result->productsUpdated++;
            } else {
                $product = Product::create($attributes + [
                    'organization_id' => $store->organization_id,
                    'sku' => $this->uniqueProductSku($store, $data),
                ]);
                $result->productsCreated++;
            }

            foreach ($data->variants as $variantData) {
                $variant = $mapped->get($variantData->externalId)?->variant
                    ?? $this->unmappedVariantWithSku($store, $variantData->sku)
                    ?? $this->orphanedVariant($store, $product, $variantData);

                $variantAttributes = array_filter([
                    'product_id' => $product->id,
                    'barcode' => $variantData->barcode,
                    'option_name' => $variantData->optionName,
                    'option_value' => $variantData->optionValue,
                    'image_url' => $variantData->imageUrl,
                    'price' => $variantData->price,
                    'cost_price' => $variantData->costPrice,
                    'is_active' => $data->isActive,
                ], fn ($value) => $value !== null);

                if ($variant) {
                    $variant->update($variantAttributes);
                } else {
                    $variant = ProductVariant::create($variantAttributes + [
                        'organization_id' => $store->organization_id,
                        'sku' => $this->uniqueVariantSku($store, $product, $variantData),
                    ]);
                }

                SkuMapping::updateOrCreate(
                    ['store_id' => $store->id, 'external_id' => $variantData->externalId],
                    [
                        'organization_id' => $store->organization_id,
                        'product_variant_id' => $variant->id,
                        'external_sku' => $variantData->sku ?? $variant->sku,
                        'external_name' => trim($data->name.' '.($variantData->optionValue ?? '')),
                    ],
                );

                $result->variantsSynced++;

                if ($warehouse && $this->importOpeningStock($store, $warehouse, $variant, $variantData)) {
                    $result->openingStockLines++;
                }
            }

            StoreProductLink::updateOrCreate(
                ['store_id' => $store->id, 'product_id' => $product->id],
                ['organization_id' => $store->organization_id, 'external_id' => $data->externalId],
            );

            return $product;
        });
    }

    /**
     * Stock comes from the store only once, for variants the warehouse has never held.
     */
    private function importOpeningStock(Store $store, Warehouse $warehouse, ProductVariant $variant, VariantData $data): bool
    {
        if (($data->stockQuantity ?? 0) <= 0) {
            return false;
        }

        $alreadyStocked = InventoryBalance::where('warehouse_id', $warehouse->id)
            ->where('product_variant_id', $variant->id)
            ->exists();

        if ($alreadyStocked) {
            return false;
        }

        $this->outbox->importingFrom($store, fn () => $this->inventory->adjustStock(
            organizationId: $store->organization_id,
            warehouseId: $warehouse->id,
            productVariantId: $variant->id,
            adjustmentType: 'add',
            quantity: $data->stockQuantity,
            reason: 'opening_stock',
            notes: "Opening stock imported from {$store->platform->label()} store {$store->name}",
            idempotencyKey: "store-{$store->id}-opening-{$variant->id}",
        ));

        return true;
    }

    /**
     * A Finovo variant with the same SKU (e.g. the product is also sold in another store), if any.
     *
     * @param  list<VariantData>  $variants
     */
    private function variantBySku(Store $store, array $variants): ?ProductVariant
    {
        $skus = array_values(array_filter(array_map(fn (VariantData $variant) => $variant->sku, $variants)));

        return $skus === [] ? null : ProductVariant::where('organization_id', $store->organization_id)
            ->whereIn('sku', $skus)
            ->with('product')
            ->first();
    }

    /**
     * Same-SKU variant not yet linked to another variant of this store (two store variants can't share one mapping).
     */
    private function unmappedVariantWithSku(Store $store, ?string $sku): ?ProductVariant
    {
        if ($sku === null) {
            return null;
        }

        return ProductVariant::where('organization_id', $store->organization_id)
            ->where('sku', $sku)
            ->whereDoesntHave('skuMappings', fn ($query) => $query->where('store_id', $store->id))
            ->first();
    }

    /**
     * A product left behind when its store was deleted (it has no store links any more), e.g. the same shop
     * connected again as a new store. It is matched by the SKU Finovo generated for it the first time.
     */
    private function orphanedProduct(Store $store, ProductData $data): ?Product
    {
        $base = $this->productSkuBase($data);

        return Product::where('organization_id', $store->organization_id)
            ->whereIn('sku', [$base, $this->suffixed($base, $data->externalId)])
            ->whereDoesntHave('variants.skuMappings')
            ->first();
    }

    /**
     * A variant of this product left behind by a deleted store, matched by its SKU.
     */
    private function orphanedVariant(Store $store, Product $product, VariantData $data): ?ProductVariant
    {
        $base = $this->variantSkuBase($product, $data);

        return ProductVariant::where('organization_id', $store->organization_id)
            ->where('product_id', $product->id)
            ->whereIn('sku', [$base, $this->suffixed($base, $data->externalId)])
            ->whereDoesntHave('skuMappings')
            ->first();
    }

    private function productSkuBase(ProductData $data): string
    {
        $base = count($data->variants) === 1 && $data->variants[0]->sku
            ? $data->variants[0]->sku
            : Str::upper(Str::slug($data->handle ?: $data->name));

        return $base ?: 'PRODUCT';
    }

    private function variantSkuBase(Product $product, VariantData $data): string
    {
        return $data->sku ?? $product->sku.'-'.$data->externalId;
    }

    private function uniqueProductSku(Store $store, ProductData $data): string
    {
        return $this->firstFreeSku(Product::class, $store, $this->productSkuBase($data), $data->externalId);
    }

    private function uniqueVariantSku(Store $store, Product $product, VariantData $data): string
    {
        return $this->firstFreeSku(ProductVariant::class, $store, $this->variantSkuBase($product, $data), $data->externalId);
    }

    /**
     * SKUs are unique per organization. A taken SKU gets the store's ID for the item appended, then a counter,
     * until a free one is found.
     *
     * @param  class-string<Product|ProductVariant>  $model
     */
    private function firstFreeSku(string $model, Store $store, string $sku, string $externalId): string
    {
        $taken = fn (string $candidate) => $model::where('organization_id', $store->organization_id)->where('sku', $candidate)->exists();

        if (! $taken($sku)) {
            return $sku;
        }

        $candidate = $this->suffixed($sku, $externalId);

        for ($counter = 2; $taken($candidate); $counter++) {
            $candidate = $this->suffixed($sku, $externalId.'-'.$counter);
        }

        return $candidate;
    }

    private function suffixed(string $sku, string $suffix): string
    {
        return Str::limit($sku, 180, '').'-'.$suffix;
    }

    private function saveOrder(Store $store, OrderData $data, SyncResult $result): void
    {
        $created = DB::transaction(function () use ($store, $data, $result) {
            $order = Order::where('organization_id', $store->organization_id)
                ->where('store_id', $store->id)
                ->where('external_order_id', $data->externalId)
                ->first()
                // An order Finovo created on the store comes back with Finovo's own ID; it is the same order.
                ?? ($data->finovoOrderId ? Order::where('organization_id', $store->organization_id)
                    ->where('store_id', $store->id)
                    ->whereKey((int) $data->finovoOrderId)
                    ->first() : null);

            $attributes = [
                'customer_email' => $data->customerEmail,
                'customer_phone' => $data->customerPhone,
                'shipping_address' => $data->shippingAddress,
                'billing_address' => $data->billingAddress,
                'payment_status' => $data->paymentStatus,
                'fulfillment_status' => $data->fulfillmentStatus,
                'subtotal_amount' => $data->subtotal,
                'shipping_amount' => $data->shippingTotal,
                'tax_amount' => $data->taxTotal,
                'discount_amount' => $data->discountTotal,
                'payment_method' => $data->paymentMethod,
                'total_amount' => $data->total,
                'currency' => $data->currency,
                'notes' => $data->note,
                'details' => $data->details ?: null,
                'platform_data' => $data->raw ?: null,
            ];

            if ($order) {
                // Finovo owns the order workflow; the store can only add details or cancel an unshipped order.
                $order->fill(array_filter($attributes, fn ($value) => $value !== null));

                if ($data->customerName) {
                    $order->customer_name = $data->customerName;
                }

                $order->external_order_id = $data->externalId;
                $order->save();
                $this->refreshLineDetails($order, $data->lines);
                $result->ordersUpdated++;

                return null;
            }

            $order = Order::create($attributes + [
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'order_number' => $data->number,
                'external_order_id' => $data->externalId,
                'customer_name' => $data->customerName ?? 'Guest customer',
                'order_status' => $data->cancelled ? 'cancelled' : 'imported',
                'placed_at' => $data->placedAt,
            ]);

            foreach ($data->lines as $line) {
                $order->items()->create([
                    'product_variant_id' => $this->variantForLine($store, $line)?->id,
                    'external_sku' => $line->sku ?? '',
                    'product_name' => $line->name,
                    'image_url' => $line->imageUrl,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unitPrice,
                    'tax_amount' => $line->taxAmount,
                    'discount_amount' => $line->discountAmount,
                    'total_amount' => $line->totalAmount,
                    'status' => $data->cancelled ? 'cancelled' : 'pending',
                    'platform_data' => $line->raw ?: null,
                ]);
            }

            $result->ordersCreated++;

            return $order;
        });

        if ($created) {
            $this->emails->sendOrderConfirmationEmail($created);

            return;
        }

        // Cancelled on the store: release Finovo's reserved stock the same way a cancellation in Finovo does.
        $existing = Order::where('organization_id', $store->organization_id)->where('store_id', $store->id)->where('external_order_id', $data->externalId)->first();
        if ($data->cancelled && $existing && $existing->order_status !== 'cancelled' && ! in_array($existing->order_status, Order::SHIPPED_STATUSES, true)) {
            $this->orders->cancel($existing, onStore: false);
        }
    }

    /**
     * Lines of an order imported earlier get the store's newer descriptive details (photo, discount, original record).
     * Quantities and prices stay as imported: Finovo has already reserved and picked against them.
     *
     * @param  list<OrderLineData>  $lines
     */
    private function refreshLineDetails(Order $order, array $lines): void
    {
        $items = $order->items()->get();

        foreach ($lines as $line) {
            $index = $items->search(fn ($item) => $item->external_sku === ($line->sku ?? '') && $item->product_name === $line->name);

            if ($index === false) {
                continue;
            }

            $items->pull($index)->update(array_filter([
                'image_url' => $line->imageUrl,
                'discount_amount' => $line->discountAmount,
                'platform_data' => $line->raw ?: null,
            ], fn ($value) => $value !== null));
        }
    }

    private function variantForLine(Store $store, OrderLineData $line): ?ProductVariant
    {
        if ($line->externalVariantId) {
            $mapping = SkuMapping::where('store_id', $store->id)->where('external_id', $line->externalVariantId)->first();

            if ($mapping) {
                return $mapping->variant;
            }
        }

        return $line->sku
            ? ProductVariant::where('organization_id', $store->organization_id)->where('sku', $line->sku)->first()
            : null;
    }
}
