<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Downloads a product's photo from its store and keeps a copy on Finovo's storage, so pages and PDFs do not
 * depend on the store being online or allowing its images to be shown elsewhere (hotlinking).
 *
 * Only JPEG, PNG, GIF and WebP files up to 5 MB are saved. When the download fails, the store's link is kept.
 */
class ProductImageStore
{
    private const MAX_BYTES = 5 * 1024 * 1024;

    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    /**
     * Save the product's current store photo if it is not saved yet.
     *
     * @return bool|null true when saved now, null when there was nothing to do, false when the download failed
     */
    public function store(Product $product): ?bool
    {
        $source = $product->getAttributes()['image_url'] ?? null;

        if (blank($source) || filled($product->getAttributes()['image_path'] ?? null) || ! preg_match('#^https?://#i', $source)) {
            return null;
        }

        try {
            $response = Http::timeout(15)->connectTimeout(5)->withOptions(['allow_redirects' => ['max' => 3, 'protocols' => ['http', 'https']]])->get($source);
        } catch (Throwable $e) {
            Log::warning('Product image download failed', ['product' => $product->id, 'url' => $source, 'error' => $e->getMessage()]);

            return false;
        }

        $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        $body = $response->body();

        if (! $response->successful() || ! isset(self::EXTENSIONS[$type]) || $body === '' || strlen($body) > self::MAX_BYTES || ! $this->isImage($body)) {
            Log::warning('Product image not saved', ['product' => $product->id, 'url' => $source, 'status' => $response->status(), 'type' => $type]);

            return false;
        }

        $disk = Storage::disk(Product::IMAGE_DISK);
        $folder = "product-images/{$product->organization_id}";
        $path = "{$folder}/{$product->id}-".substr(sha1($source), 0, 12).'.'.self::EXTENSIONS[$type];

        // Remove this product's older photos before saving the new one.
        foreach ($disk->files($folder) as $old) {
            if (str_starts_with(basename($old), "{$product->id}-")) {
                $disk->delete($old);
            }
        }

        $disk->put($path, $body);
        $product->forceFill(['image_path' => $path])->saveQuietly();

        return true;
    }

    /** The file really is a picture (not an HTML error page served with an image content type). */
    private function isImage(string $body): bool
    {
        return @getimagesizefromstring($body) !== false;
    }
}
