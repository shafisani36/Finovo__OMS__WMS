<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InventoryBalance;
use App\Models\InventoryLedgerEntry;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SkuMapping;
use App\Services\ProductService;
use App\Services\StorePushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProductController extends Controller
{
    public function __construct(
        protected ProductService $products,
        protected StorePushService $push,
    ) {}

    /**
     * Add a product with its variants; it is also created on the chosen stores (store_ids).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateProduct($request);
        [$product, $report] = $this->products->save($request->user()->currentOrganization()->id, $validated, null, $validated['store_ids'] ?? []);

        return response()->json([
            'status' => 'success',
            'message' => trim("{$product->name} was added. ".$report->message('created')),
            'data' => ['id' => $product->id],
            'push' => $report->toArray(),
        ], 201);
    }

    /**
     * Change a product and its variants; the change is sent to every store it is sold on (and to new store_ids).
     */
    public function update(Request $request, Product $product): JsonResponse
    {
        $this->ensureSameOrganization($request, $product);
        $validated = $this->validateProduct($request, $product);
        [$product, $report] = $this->products->save($product->organization_id, $validated, $product, $validated['store_ids'] ?? []);

        return response()->json([
            'status' => 'success',
            'message' => trim("{$product->name} was saved. ".$report->message('updated')),
            'data' => ['id' => $product->id],
            'push' => $report->toArray(),
        ]);
    }

    /**
     * Delete a product that was never used; it is also removed from its stores.
     */
    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->ensureSameOrganization($request, $product);
        $report = $this->products->delete($product);

        return response()->json([
            'status' => 'success',
            'message' => trim("{$product->name} was deleted. ".$report->message('deleted')),
            'push' => $report->toArray(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateProduct(Request $request, ?Product $product = null): array
    {
        $orgId = $request->user()->currentOrganization()->id;
        $variantIds = $product ? $product->variants()->pluck('id')->all() : [];

        $validator = validator($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:100', Rule::unique('products', 'sku')->where('organization_id', $orgId)->ignore($product?->id)],
            'category' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:10000'],
            'image_url' => ['nullable', 'url:https,http', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
            'variants' => ['required', 'array', 'min:1', 'max:100'],
            'variants.*.id' => ['nullable', 'integer', Rule::in($variantIds)],
            'variants.*.sku' => ['required', 'string', 'max:100', 'distinct:ignore_case'],
            'variants.*.option_name' => ['nullable', 'string', 'max:100'],
            'variants.*.option_value' => ['nullable', 'string', 'max:255'],
            'variants.*.barcode' => ['nullable', 'string', 'max:100'],
            'variants.*.price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'variants.*.cost_price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'variants.*.is_active' => ['sometimes', 'boolean'],
            'store_ids' => ['nullable', 'array'],
            'store_ids.*' => ['integer', 'distinct', $this->inOrganization($request, 'stores')],
        ], [
            'variants.*.sku.distinct' => 'Each variant needs its own SKU.',
        ]);

        $validator->after(function (Validator $validator) use ($request, $orgId) {
            $variants = collect($request->input('variants', []));

            // SKUs are unique across the company, so stock and store links never mix up two items.
            $taken = ProductVariant::where('organization_id', $orgId)
                ->whereIn('sku', $variants->pluck('sku')->filter())
                ->whereNotIn('id', $variants->pluck('id')->filter())
                ->pluck('sku');
            foreach ($taken as $sku) {
                $validator->errors()->add('variants', "The SKU {$sku} is already used by another product.");
            }

            // With several variants, each needs its own option value (e.g. Size: S, M, L).
            if ($variants->count() > 1) {
                $values = $variants->pluck('option_value')->map(fn ($value) => mb_strtolower(trim((string) $value)));
                if ($values->contains('') || $values->duplicates()->isNotEmpty()) {
                    $validator->errors()->add('variants', 'With more than one variant, give each a different option value (for example Small, Medium, Large).');
                }
            }
        });

        return $validator->validate();
    }

    private function ensureSameOrganization(Request $request, Product $product): void
    {
        abort_unless($product->organization_id === $request->user()->currentOrganization()->id, 404);
    }

    /**
     * List products with variants, stock balances, search, and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $query = Product::where('organization_id', $org->id)
            ->with(['variants.balances.warehouse', 'variants.skuMappings.store:id,name,platform']);

        // Search
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%");
            });
        }

        // Category filter
        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }

        // Store filter: products sold in the store being managed (linked through SKU mappings)
        if ($storeId = $request->query('store_id')) {
            $query->whereHas('variants.skuMappings', fn ($q) => $q->where('store_id', $storeId));
        }

        $perPage = (int) $request->query('per_page', 15);
        $products = $query->latest()->paginate($perPage);

        $items = collect($products->items())->map(function ($p) {
            $stockOf = fn ($balances) => [
                'on_hand' => (int) $balances->sum('on_hand'),
                'reserved' => (int) $balances->sum('reserved'),
                'available' => (int) $balances->sum('available'),
            ];
            $mappings = $p->variants->flatMap->skuMappings;

            return [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'barcode' => $p->barcode,
                'category' => $p->category,
                'description' => $p->description,
                'image_url' => $p->image_url,
                'is_active' => $p->is_active,
                'default_variant_id' => $p->variants->first()?->id,
                'variants' => $p->variants->map(fn ($v) => [
                    'id' => $v->id,
                    'sku' => $v->sku,
                    'name' => $v->option_value ?: $p->name,
                    'option_name' => $v->option_name,
                    'option_value' => $v->option_value,
                    'barcode' => $v->barcode,
                    'price' => (float) $v->price,
                    'cost_price' => $v->cost_price !== null ? (float) $v->cost_price : null,
                    'is_active' => $v->is_active,
                    'stock' => $stockOf($v->balances),
                ]),
                'variants_count' => $p->variants->count(),
                'stores' => $mappings->pluck('store')->filter()->unique('id')->values()
                    ->map(fn ($store) => ['id' => $store->id, 'name' => $store->name, 'platform' => $store->platform]),
                'stock' => $stockOf($p->variants->flatMap->balances),
                'created_at' => $p->created_at->format('Y-m-d H:i'),
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $items,
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ],
            'kpis' => [
                'total_products' => Product::where('organization_id', $org->id)->count(),
                'active_products' => Product::where('organization_id', $org->id)->where('is_active', true)->count(),
            ],
        ]);
    }

    /**
     * Everything about one product for its page: all photos, description and the store's other facts, every
     * variant with its barcode and stock per warehouse, the stores selling it (with each store's original record),
     * units sold and the latest stock movements.
     */
    public function show(Request $request, Product $product): JsonResponse
    {
        abort_unless($product->organization_id === $request->user()->currentOrganization()->id, 404);

        $product->load(['variants.balances.warehouse:id,name,code', 'variants.skuMappings.store:id,name,platform']);
        $variantIds = $product->variants->pluck('id');
        $balances = $product->variants->flatMap->balances;
        $mappings = $product->variants->flatMap->skuMappings;

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'category' => $product->category,
                'description' => $product->description,
                'is_active' => $product->is_active,
                'image_url' => $product->image_url,
                'images' => $this->images($product),
                'details' => $product->details ?? (object) [],
                'created_at' => $product->created_at?->toIso8601String(),
                'updated_at' => $product->updated_at?->toIso8601String(),
                'stock' => $this->stock($balances),
                'warehouses' => $balances->groupBy('warehouse_id')->map(fn (Collection $rows) => [
                    'id' => $rows->first()->warehouse_id,
                    'name' => $rows->first()->warehouse?->name,
                    'code' => $rows->first()->warehouse?->code,
                    ...$this->stock($rows),
                ])->values(),
                'variants' => $product->variants->map(fn ($variant) => [
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'barcode' => $variant->barcode,
                    'option_name' => $variant->option_name,
                    'option_value' => $variant->option_value,
                    'image_url' => $variant->image_url,
                    'price' => (float) $variant->price,
                    'cost_price' => $variant->cost_price !== null ? (float) $variant->cost_price : null,
                    'is_active' => $variant->is_active,
                    'stock' => $this->stock($variant->balances),
                    'warehouses' => $variant->balances->map(fn (InventoryBalance $balance) => [
                        'id' => $balance->warehouse_id,
                        'name' => $balance->warehouse?->name,
                        ...$this->stock(collect([$balance])),
                    ])->values(),
                ]),
                // Every store the product is on, for the edit form (a store with no listing yet included).
                'store_ids' => $this->push->linkedStores($product)->pluck('id')->values(),
                'stores' => $mappings->groupBy('store_id')->map(fn (Collection $rows) => [
                    'id' => $rows->first()->store_id,
                    'name' => $rows->first()->store?->name,
                    'platform' => $rows->first()->store?->platform,
                    'listings' => $rows->map(fn (SkuMapping $mapping) => [
                        'external_id' => $mapping->external_id,
                        'external_sku' => $mapping->external_sku,
                        'external_name' => $mapping->external_name,
                    ])->values(),
                    'platform_data' => $product->platform_data[(string) $rows->first()->store_id] ?? null,
                ])->values(),
                'sales' => [
                    'orders' => OrderItem::whereIn('product_variant_id', $variantIds)->whereHas('order', fn ($q) => $q->where('order_status', '!=', 'cancelled'))->distinct()->count('order_id'),
                    'units' => (int) OrderItem::whereIn('product_variant_id', $variantIds)->whereHas('order', fn ($q) => $q->where('order_status', '!=', 'cancelled'))->sum('quantity'),
                ],
                'recent_movements' => InventoryLedgerEntry::whereIn('product_variant_id', $variantIds)
                    ->with('warehouse:id,name')
                    ->latest('created_at')
                    ->limit(10)
                    ->get()
                    ->map(fn (InventoryLedgerEntry $entry) => [
                        'id' => $entry->id,
                        'type' => $entry->movement_type,
                        'quantity' => (int) $entry->quantity,
                        'balance_after' => $entry->balance_after !== null ? (int) $entry->balance_after : null,
                        'warehouse' => $entry->warehouse?->name,
                        'reference' => $entry->reference_number,
                        'reason' => $entry->reason,
                        'created_at' => $entry->created_at?->toIso8601String(),
                    ]),
            ],
        ]);
    }

    /**
     * Every photo: Finovo's saved copy of the main photo first, then the store's other photos.
     *
     * @return list<string>
     */
    private function images(Product $product): array
    {
        $original = $product->getRawOriginal('image_url');
        $others = array_filter($product->images ?? [], fn (string $url) => $url !== $original);

        return array_values(array_unique(array_filter([$product->image_url, ...$others])));
    }

    /**
     * @param  Collection<int, InventoryBalance>  $balances
     * @return array{on_hand: int, reserved: int, available: int, damaged: int, in_transit: int}
     */
    private function stock(Collection $balances): array
    {
        return [
            'on_hand' => (int) $balances->sum('on_hand'),
            'reserved' => (int) $balances->sum('reserved'),
            'available' => (int) $balances->sum('available'),
            'damaged' => (int) $balances->sum('damaged'),
            'in_transit' => (int) $balances->sum('in_transit'),
        ];
    }
}
