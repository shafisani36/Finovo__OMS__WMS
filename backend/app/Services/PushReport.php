<?php

namespace App\Services;

/**
 * What happened on each store when a Finovo change was sent to the connected stores.
 */
class PushReport
{
    /** @var list<string> stores that took the change */
    public array $saved = [];

    /** @var array<string, string> reason by store name */
    public array $failed = [];

    /** @var list<string> stores Finovo cannot write to (platform not supported or not connected) */
    public array $skipped = [];

    public function hasFailures(): bool
    {
        return $this->failed !== [];
    }

    /**
     * One sentence for the merchant, e.g. "Also updated on Demo Shopify." or the reason a store refused.
     */
    public function message(string $verb = 'updated'): string
    {
        $parts = [];

        if ($this->saved) {
            $parts[] = "Also {$verb} on ".implode(', ', $this->saved).'.';
        }

        foreach ($this->failed as $store => $reason) {
            $parts[] = "{$store} could not be {$verb}: {$reason}";
        }

        if ($this->skipped) {
            $parts[] = implode(', ', $this->skipped).' must be changed on the store itself (Finovo cannot write to it).';
        }

        return implode(' ', $parts);
    }

    /**
     * @return array{saved: list<string>, failed: array<string, string>, skipped: list<string>}
     */
    public function toArray(): array
    {
        return ['saved' => $this->saved, 'failed' => $this->failed, 'skipped' => $this->skipped];
    }
}
