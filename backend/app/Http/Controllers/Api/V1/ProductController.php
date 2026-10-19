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
            ->with(['variants.balances.warehouse']);

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

        $perPage = (int) $request->query('per_page', 15);
        $products = $query->latest()->paginate($perPage);

        $items = collect($products->items())->map(function ($p) {
            $totalOnHand = 0;
            $totalReserved = 0;
            $totalAvailable = 0;

            foreach ($p->variants as $v) {
                foreach ($v->balances as $b) {
                    $totalOnHand += $b->on_hand;
                    $totalReserved += $b->reserved;
                    $totalAvailable += $b->available;
                }
            }

            return [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'barcode' => $p->barcode,
                'category' => $p->category,
                'is_active' => $p->is_active,
                'default_variant_id' => $p->variants->first()?->id,
                'variants' => $p->variants->map(function ($v) {
                    return [
                        'id' => $v->id,
                        'sku' => $v->sku,
                        'name' => $v->name,
                        'price' => (float) $v->price,
                    ];
                }),
                'variants_count' => $p->variants->count(),
                'stock' => [
                    'on_hand' => $totalOnHand,
                    'reserved' => $totalReserved,
                    'available' => $totalAvailable,
                ],
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
     * Create product with default variant.
     */
    public function store(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:100'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'max:100'],
            'price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $product = Product::create([
            'organization_id' => $org->id,
            'name' => $validated['name'],
            'sku' => strtoupper(trim($validated['sku'])),
            'barcode' => $validated['barcode'] ?? null,
            'description' => $validated['description'] ?? null,
            'category' => $validated['category'] ?? 'General',
            'is_active' => true,
        ]);

        $product->variants()->create([
            'organization_id' => $org->id,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'price' => $validated['price'] ?? 0.00,
            'is_active' => true,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Product created successfully.',
            'data' => $product->load('variants'),
        ], 201);
    }
}
