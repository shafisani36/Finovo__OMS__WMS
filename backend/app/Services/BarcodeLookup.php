<?php

namespace App\Services;

use App\Models\ProductVariant;
use Illuminate\Support\Str;

/**
 * What a scanned barcode stands for. Finovo's labels may carry details after the code (a QR code has the code on
 * its first line, older labels "CODE | details"); the code alone is used for lookups.
 */
class BarcodeLookup
{
    private const DETAILS_SEPARATOR = ' | ';

    /**
     * The code part of what the scanner read.
     */
    public function code(string $scanned): string
    {
        return trim(Str::before(strtok($scanned, "\r\n") ?: $scanned, self::DETAILS_SEPARATOR));
    }

    /**
     * The variant with this SKU or barcode (a product's own SKU or barcode finds its variant when it has one).
     */
    public function variant(int $organizationId, string $code): ?ProductVariant
    {
        return ProductVariant::where('organization_id', $organizationId)
            ->where(fn ($q) => $q->where('sku', $code)
                ->orWhere('barcode', $code)
                ->orWhereHas('product', fn ($p) => $p->where('sku', $code)->orWhere('barcode', $code)))
            ->orderByRaw('CASE WHEN sku = ? OR barcode = ? THEN 0 ELSE 1 END', [$code, $code])
            ->first();
    }
}
