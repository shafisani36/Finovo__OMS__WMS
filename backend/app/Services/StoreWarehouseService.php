<?php

namespace App\Services;

use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Which warehouses fulfil which stores. A store can ship from several warehouses and a warehouse can serve
 * several stores. For each store the warehouses are kept in order: orders are allocated to the first one that
 * has the stock (priority 1 is the store's main warehouse, which also receives the stock its sync imports).
 */
class StoreWarehouseService
{
    public function __construct(
        protected AuditService $audit,
    ) {}

    /**
     * Set the warehouses a store ships from, main warehouse first. An empty list lets every active warehouse
     * of the company fulfil the store.
     *
     * @param  list<int>  $warehouseIds  in priority order
     */
    public function setStoreWarehouses(Store $store, array $warehouseIds, User $user): Store
    {
        $warehouses = $this->warehousesOf($store->organization_id, $warehouseIds, 'warehouse_ids');

        DB::transaction(fn () => $this->setLinks($store, $warehouses->pluck('id')->all()));

        $this->audit->record('store.warehouses_changed', $user->id, [
            'store_id' => $store->id,
            'warehouse_ids' => $warehouses->pluck('id')->all(),
        ]);

        return $store->load('warehouses');
    }

    /**
     * Set the stores a warehouse serves. A store that is newly linked gets this warehouse after the ones it
     * already ships from; an unlinked store keeps its other warehouses in the same order.
     *
     * @param  list<int>  $storeIds
     */
    public function setWarehouseStores(Warehouse $warehouse, array $storeIds, User $user): Warehouse
    {
        $stores = Store::where('organization_id', $warehouse->organization_id)->whereIn('id', $storeIds)->get();

        if ($stores->count() !== count(array_unique($storeIds))) {
            throw ValidationException::withMessages(['store_ids' => 'Choose stores of your company.']);
        }

        DB::transaction(function () use ($warehouse, $stores) {
            $linked = $warehouse->stores()->pluck('stores.id');

            foreach ($warehouse->stores()->whereNotIn('stores.id', $stores->pluck('id'))->get() as $store) {
                $this->setLinks($store, $store->warehouses()->pluck('warehouses.id')->reject(fn ($id) => $id === $warehouse->id)->all());
            }

            foreach ($stores->whereNotIn('id', $linked) as $store) {
                $this->setLinks($store, $store->warehouses()->pluck('warehouses.id')->push($warehouse->id)->all());
            }
        });

        $this->audit->record('warehouse.stores_changed', $user->id, [
            'warehouse_id' => $warehouse->id,
            'store_ids' => $stores->pluck('id')->all(),
        ]);

        return $warehouse->load('stores:id,name,platform,code');
    }

    /**
     * Add warehouses to a store after the ones it already ships from (used when a store is connected again).
     *
     * @param  list<int>  $warehouseIds
     */
    public function addStoreWarehouses(Store $store, array $warehouseIds): void
    {
        $current = $store->warehouses()->pluck('warehouses.id');

        $this->setLinks($store, $current->merge(array_diff($warehouseIds, $current->all()))->all());
    }

    /**
     * The organization's warehouses with these IDs, in the order given; refuses IDs of other companies.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Warehouse>
     */
    public function warehousesOf(int $organizationId, array $ids, string $field): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $found = Warehouse::where('organization_id', $organizationId)->whereIn('id', $ids)->get()->keyBy('id');

        if ($found->count() !== count($ids)) {
            throw ValidationException::withMessages([$field => 'Choose warehouses of your company.']);
        }

        return collect($ids)->map(fn (int $id) => $found[$id]);
    }

    /**
     * Link the store to exactly these warehouses, in this order.
     *
     * @param  list<int>  $orderedIds
     */
    public function setLinks(Store $store, array $orderedIds): void
    {
        $store->warehouses()->sync($this->ranked(collect($orderedIds)));
    }

    /**
     * Pivot rows for warehouses in priority order; the first is the main (default) warehouse.
     *
     * @param  Collection<int, int>  $orderedIds
     * @return array<int, array{is_default: bool, priority: int}>
     */
    private function ranked(Collection $orderedIds): array
    {
        return $orderedIds->values()
            ->mapWithKeys(fn (int $id, int $index) => [$id => ['is_default' => $index === 0, 'priority' => $index + 1]])
            ->all();
    }
}
