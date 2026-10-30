<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Finovo variant as a variant of a store. For a shop it holds the shop's own ID; for a store kept in Finovo
 * only (see StorePlatform::keepsCatalogInFinovo) it just puts the variant in that store's catalog.
 */
class SkuMapping extends Model
{
    use HasFactory;

    /** External ID of a variant in a store kept in Finovo only (the variant is its own record there). */
    public const FINOVO_ID_PREFIX = 'finovo-';

    protected $fillable = [
        'organization_id',
        'store_id',
        'product_variant_id',
        'external_id',
        'external_sku',
        'external_name',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * Put the variant in the catalog of a store kept in Finovo only (no change for other stores).
     */
    public static function addToFinovoStore(Store $store, ProductVariant $variant): void
    {
        if (! $store->platform->keepsCatalogInFinovo()) {
            return;
        }

        self::updateOrCreate(
            ['store_id' => $store->id, 'product_variant_id' => $variant->id],
            [
                'organization_id' => $store->organization_id,
                'external_id' => self::FINOVO_ID_PREFIX.$variant->id,
                'external_sku' => $variant->sku,
                'external_name' => trim(($variant->product?->name ?? '').' '.($variant->option_value ?? '')),
            ],
        );
    }

    /**
     * Whether this is the catalog link of a store kept in Finovo only (it has no ID on a shop).
     */
    public function isFinovoOnly(): bool
    {
        return str_starts_with($this->external_id, self::FINOVO_ID_PREFIX);
    }
}
