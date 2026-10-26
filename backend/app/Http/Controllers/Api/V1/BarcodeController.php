<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Barcode labels and scanning: the organization's label settings, and looking up what a scanned code belongs to.
 */
class BarcodeController extends Controller
{
    /** Barcode types the labels can be printed in. EAN-13 and UPC only fit numeric codes of the right length. */
    public const FORMATS = ['CODE128', 'CODE39', 'EAN13', 'UPC'];

    /** Label settings used until an admin changes them. */
    public const DEFAULTS = [
        'format' => 'CODE128',
        'width' => 2,
        'height' => 60,
        'display_value' => true,
        'font_size' => 14,
        'margin' => 5,
        'embed_details' => true,
    ];

    /** Labels can carry details after the code ("SKU-1 | Desk Lamp | Price 25.00"); lookups use the code. */
    private const DETAILS_SEPARATOR = ' | ';

    public function settings(Request $request): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => $this->currentSettings($request)]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'format' => ['required', Rule::in(self::FORMATS)],
            'width' => 'required|numeric|min:1|max:4',
            'height' => 'required|integer|min:30|max:150',
            'display_value' => 'required|boolean',
            'font_size' => 'required|integer|min:10|max:24',
            'margin' => 'required|integer|min:0|max:20',
            'embed_details' => 'required|boolean',
        ]);

        $organization = $request->user()->currentOrganization();
        $organization->update(['settings' => [...($organization->settings ?? []), 'barcode' => $validated]]);

        return response()->json([
            'status' => 'success',
            'message' => 'Barcode settings saved.',
            'data' => $this->currentSettings($request),
        ]);
    }

    /**
     * What a scanned code is: an order (by order number, store order ID or tracking ID) or a product
     * (by SKU or barcode), with what the scan screen needs to show it and move it on.
     */
    public function scan(Request $request): JsonResponse
    {
        $scanned = (string) $request->validate(['code' => 'required|string|max:2000'])['code'];
        // Label QR codes carry the code on the first line and details below; older labels used "CODE | details".
        $code = trim(Str::before(strtok($scanned, "\r\n") ?: $scanned, self::DETAILS_SEPARATOR));
        $orgId = $request->user()->currentOrganization()->id;

        $order = Order::where('organization_id', $orgId)
            ->where(fn ($q) => $q->where('order_number', $code)
                ->orWhere('order_number', '#'.ltrim($code, '#'))
                ->orWhere('external_order_id', $code)
                ->orWhereHas('shipments', fn ($s) => $s->where('tracking_number', $code)))
            ->latest('id')
            ->first();

        if ($order) {
            return response()->json(['status' => 'success', 'type' => 'order', 'data' => $order->fulfillmentSummary()]);
        }

        $variant = ProductVariant::where('organization_id', $orgId)
            ->where(fn ($q) => $q->where('sku', $code)
                ->orWhere('barcode', $code)
                ->orWhereHas('product', fn ($p) => $p->where('sku', $code)->orWhere('barcode', $code)))
            ->with(['product', 'balances.warehouse'])
            ->first();

        if ($variant) {
            return response()->json(['status' => 'success', 'type' => 'product', 'data' => $this->presentVariant($variant)]);
        }

        return response()->json([
            'status' => 'error',
            'message' => "No order, tracking ID or product matches \"{$code}\".",
        ], 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function currentSettings(Request $request): array
    {
        return [...self::DEFAULTS, ...($request->user()->currentOrganization()->settings['barcode'] ?? [])];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentVariant(ProductVariant $variant): array
    {
        $balances = $variant->balances;

        return [
            'id' => $variant->id,
            'product_id' => $variant->product_id,
            'name' => $variant->product?->name,
            'variant' => trim(($variant->option_name ? $variant->option_name.': ' : '').($variant->option_value ?? '')) ?: null,
            'sku' => $variant->sku,
            'barcode' => $variant->barcode,
            'image_url' => $variant->product?->image_url,
            'price' => $variant->price,
            'is_active' => $variant->is_active,
            'stock' => [
                'on_hand' => (int) $balances->sum('on_hand'),
                'available' => (int) $balances->sum('available'),
                'reserved' => (int) $balances->sum('reserved'),
            ],
            'warehouses' => $balances->map(fn ($balance) => [
                'warehouse' => $balance->warehouse?->name,
                'on_hand' => $balance->on_hand,
                'available' => $balance->available,
                'reserved' => $balance->reserved,
            ])->values(),
        ];
    }
}
