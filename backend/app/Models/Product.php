<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'sku',
        'barcode',
        'description',
        'category',
        'base_uom',
        'tracking_type',
        'image_url',
        'image_path',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * The photo to show: Finovo's own saved copy when there is one, otherwise the store's link.
     * Setting a different store link drops the saved copy so the new photo is downloaded (ProductImageStore).
     * The store's link itself is getRawOriginal('image_url').
     */
    protected function imageUrl(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value, array $attributes) => filled($attributes['image_path'] ?? null)
                ? Storage::disk(self::IMAGE_DISK)->url($attributes['image_path'])
                : $value,
            set: fn (?string $value, array $attributes) => $value === ($attributes['image_url'] ?? null)
                ? ['image_url' => $value]
                : ['image_url' => $value, 'image_path' => null],
        );
    }

    public const IMAGE_DISK = 'public';

    /** Absolute path of the saved photo on this server, for the PDF documents. */
    public function localImageFile(): ?string
    {
        $path = $this->getAttributes()['image_path'] ?? null;

        return $path && Storage::disk(self::IMAGE_DISK)->exists($path) ? Storage::disk(self::IMAGE_DISK)->path($path) : null;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }
}
