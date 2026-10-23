<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
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
}
