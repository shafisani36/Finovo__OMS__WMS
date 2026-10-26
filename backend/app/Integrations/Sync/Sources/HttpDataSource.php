<?php

namespace App\Integrations\Sync\Sources;

use App\Integrations\Concerns\SendsPlatformRequests;
use App\Integrations\Sync\Contracts\StoreDataSource;

/**
 * Shared base for platforms read over HTTP: request handling (SendsPlatformRequests), warnings and text clean-up.
 */
abstract class HttpDataSource implements StoreDataSource
{
    use SendsPlatformRequests;

    /** @var list<string> */
    protected array $warnings = [];

    public function warnings(): array
    {
        return array_values(array_unique($this->warnings));
    }

    /**
     * HTML descriptions from store platforms as plain text.
     */
    protected static function plainText(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)));

        return $text === '' ? null : $text;
    }
}
