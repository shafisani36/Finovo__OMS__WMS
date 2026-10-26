<?php

namespace App\Services;

use App\Integrations\ConnectorErrors;
use App\Integrations\Push\StoreWriter;
use App\Integrations\Push\StoreWriterFactory;
use App\Models\Order;
use App\Models\Product;
use App\Models\SkuMapping;
use App\Models\Store;
use App\Models\StoreProductLink;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sends Finovo's changes to the connected stores (products and orders). Finovo saves first; a store that refuses
 * never undoes the change in Finovo. Each store's outcome is reported, and failures are kept against the store.
 */
class StorePushService
{
    public function __construct(
        protected StoreWriterFactory $writers,
        protected ConnectorErrors $errors,
    ) {}

    /**
     * Create or update the product on the given stores and on every store it is already linked to.
     *
     * @param  iterable<Store>  $stores  stores to add the product to
     */
    public function pushProduct(Product $product, iterable $stores = []): PushReport
    {
        $product->loadMissing('variants');
        $report = new PushReport;

        foreach ($this->linkedStores($product)->concat($stores)->unique('id') as $store) {
            $this->attempt($report, $store, 'product push', function (StoreWriter $writer) use ($store, $product) {
                $link = StoreProductLink::where('store_id', $store->id)->where('product_id', $product->id)->first();
                $pushed = $writer->saveProduct($store, $product, $link?->external_id);

                DB::transaction(function () use ($store, $product, $pushed) {
                    StoreProductLink::updateOrCreate(
                        ['store_id' => $store->id, 'product_id' => $product->id],
                        ['organization_id' => $store->organization_id, 'external_id' => $pushed->externalId],
                    );

                    // Links of these variants to store variants that no longer exist there are dropped.
                    SkuMapping::where('store_id', $store->id)
                        ->whereIn('product_variant_id', $product->variants->pluck('id'))
                        ->whereNotIn('external_id', array_values($pushed->variantIds))
                        ->delete();
                    foreach ($pushed->variantIds as $variantId => $externalId) {
                        $variant = $product->variants->firstWhere('id', $variantId);
                        SkuMapping::updateOrCreate(
                            ['store_id' => $store->id, 'external_id' => $externalId],
                            [
                                'organization_id' => $store->organization_id,
                                'product_variant_id' => $variantId,
                                'external_sku' => $variant->sku,
                                'external_name' => trim($product->name.' '.($variant->option_value ?? '')),
                            ],
                        );
                    }
                });
            });
        }

        return $report;
    }

    /**
     * Remove the product from every store it is linked to.
     */
    public function deleteProduct(Product $product): PushReport
    {
        $report = new PushReport;

        foreach (StoreProductLink::where('product_id', $product->id)->with('store')->get() as $link) {
            $this->attempt($report, $link->store, 'product delete', fn (StoreWriter $writer) => $writer->deleteProduct($link->store, $link->external_id));
        }

        return $report;
    }

    /**
     * Create a Finovo order on its store; the store's ID is saved on the order.
     */
    public function createOrder(Order $order): PushReport
    {
        $report = new PushReport;

        if ($order->store && ! $order->external_order_id) {
            $this->attempt($report, $order->store, 'order push', function (StoreWriter $writer) use ($order) {
                $order->forceFill(['external_order_id' => $writer->createOrder($order->store, $order)])->save();
            });
        }

        return $report;
    }

    public function updateOrder(Order $order): PushReport
    {
        return $this->onStoreOrder($order, 'order update', fn (StoreWriter $writer) => $writer->updateOrder($order->store, $order));
    }

    public function cancelOrder(Order $order): PushReport
    {
        return $this->onStoreOrder($order, 'order cancel', fn (StoreWriter $writer) => $writer->cancelOrder($order->store, $order));
    }

    /**
     * Stores the product is linked to (by product link or by a variant's SKU mapping).
     *
     * @return Collection<int, Store>
     */
    public function linkedStores(Product $product): Collection
    {
        $ids = StoreProductLink::where('product_id', $product->id)->pluck('store_id')
            ->merge(SkuMapping::whereIn('product_variant_id', $product->variants()->pluck('id'))->pluck('store_id'))
            ->unique();

        return Store::whereIn('id', $ids)->get();
    }

    /**
     * @param  Closure(StoreWriter): mixed  $call
     */
    private function onStoreOrder(Order $order, string $action, Closure $call): PushReport
    {
        $report = new PushReport;

        if ($order->store && $order->external_order_id) {
            $this->attempt($report, $order->store, $action, $call);
        }

        return $report;
    }

    /**
     * @param  Closure(StoreWriter): mixed  $call
     */
    private function attempt(PushReport $report, Store $store, string $action, Closure $call): void
    {
        $writer = $this->writers->for($store);

        if (! $writer) {
            $report->skipped[] = $store->name;

            return;
        }

        try {
            $call($writer);
            $report->saved[] = $store->name;
        } catch (Throwable $e) {
            $report->failed[$store->name] = $this->errors->record($store, $action, $e);
        }
    }
}
