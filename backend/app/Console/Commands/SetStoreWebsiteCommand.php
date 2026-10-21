<?php

namespace App\Console\Commands;

use App\Enums\StorePlatform;
use App\Models\Store;
use Illuminate\Console\Command;

class SetStoreWebsiteCommand extends Command
{
    protected $signature = 'stores:set-website
        {store : ID of the store (shown by stores:sync)}
        {url : The shop\'s public address, e.g. https://shop.example.com}';

    protected $description = 'Set the website of an OpenCart or osCommerce store connected by database, so product images can be imported';

    public function handle(): int
    {
        $store = Store::find($this->argument('store'));
        $url = rtrim(trim((string) $this->argument('url')), '/');

        if (! $store) {
            $this->error('No store has that ID.');

            return self::FAILURE;
        }

        if (! in_array($store->platform, [StorePlatform::OPENCART, StorePlatform::OSCOMMERCE], true)) {
            $this->error("{$store->name} is a {$store->platform->label()} store; its images already come from the platform.");

            return self::FAILURE;
        }

        if (! filter_var($url, FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', $url)) {
            $this->error('Enter the full address starting with http:// or https://');

            return self::FAILURE;
        }

        $store->update(['credentials' => array_merge($store->credentials ?? [], ['site_url' => $url])]);
        $this->info("Website of {$store->name} set to {$url}. Run php artisan stores:sync {$store->id} --now to import the images.");

        return self::SUCCESS;
    }
}
