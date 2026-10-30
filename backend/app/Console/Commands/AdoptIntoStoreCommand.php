<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Product;
use App\Models\SkuMapping;
use App\Models\Store;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pages list only the managed store's data, so orders saved without a store and products that belong to no
 * store appear nowhere. This one-time step gives them to a store kept in Finovo only (e.g. the "Manually" store).
 */
class AdoptIntoStoreCommand extends Command
{
    protected $signature = 'stores:adopt
        {store? : Name or ID of a store kept in Finovo only (Manual Store); lists them when omitted}
        {--dry-run : Only show what would change}';

    protected $description = 'Give orders without a store and products of no store to a Finovo-only store';

    public function handle(): int
    {
        $manualStores = Store::all()->filter(fn (Store $store) => $store->platform->keepsCatalogInFinovo());

        if (blank($this->argument('store'))) {
            $this->table(['ID', 'Name'], $manualStores->map(fn (Store $store) => [$store->id, $store->name])->values()->all());
            $this->line('Run again with the store name or ID, e.g. php artisan stores:adopt "'.($manualStores->first()?->name ?? 'Manually').'" --dry-run');

            return self::SUCCESS;
        }

        $given = trim((string) $this->argument('store'));
        $store = Store::find(ctype_digit($given) ? (int) $given : 0) ?? Store::where('name', $given)->first();

        if (! $store) {
            $this->error("No store is called or numbered \"{$given}\". Run php artisan stores:adopt to list the Manual stores.");

            return self::FAILURE;
        }

        if (! $store->platform->keepsCatalogInFinovo()) {
            $this->error("{$store->name} is a {$store->platform->label()} store. Only a Manual Store can take orders and products kept in Finovo only.");

            return self::FAILURE;
        }

        $orders = Order::where('organization_id', $store->organization_id)->whereNull('store_id');
        $products = Product::where('organization_id', $store->organization_id)
            ->whereDoesntHave('variants.skuMappings')
            ->with('variants');

        $orderCount = $orders->count();
        $productList = $products->get();

        if ($this->option('dry-run')) {
            $this->info("{$orderCount} orders and {$productList->count()} products would be given to {$store->name}.");

            return self::SUCCESS;
        }

        DB::transaction(function () use ($orders, $productList, $store) {
            $orders->update(['store_id' => $store->id]);

            foreach ($productList as $product) {
                foreach ($product->variants as $variant) {
                    SkuMapping::addToFinovoStore($store, $variant->setRelation('product', $product));
                }
            }
        });

        $this->info("{$orderCount} orders and {$productList->count()} products now belong to {$store->name}.");

        return self::SUCCESS;
    }
}
