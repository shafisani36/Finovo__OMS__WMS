<?php

namespace App\Enums;

enum StorePlatform: string
{
    case SHOPIFY = 'shopify';
    case WOOCOMMERCE = 'woocommerce';
    case BIGCOMMERCE = 'bigcommerce';
    case PRESTASHOP = 'prestashop';
    case WIX = 'wix';
    case EBAY = 'ebay';
    case MAGENTO = 'magento';
    case OPENCART = 'opencart';
    case OSCOMMERCE = 'oscommerce';
    case CUSTOM_BRIDGE = 'custom_bridge';
    case MANUAL = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::SHOPIFY => 'Shopify',
            self::WOOCOMMERCE => 'WooCommerce',
            self::BIGCOMMERCE => 'BigCommerce',
            self::PRESTASHOP => 'PrestaShop',
            self::WIX => 'Wix',
            self::EBAY => 'eBay',
            self::MAGENTO => 'Magento 2',
            self::OPENCART => 'OpenCart',
            self::OSCOMMERCE => 'osCommerce',
            self::CUSTOM_BRIDGE => 'Finovo Custom Bridge',
            self::MANUAL => 'Manual Store',
        };
    }

    /**
     * Platforms where the merchant approves Finovo on the platform itself (approval page, app or module install)
     * and the platform sends the credentials back to Finovo, so nobody has to copy keys.
     */
    public function authorizesOnPlatform(): bool
    {
        return in_array($this, [self::SHOPIFY, self::WOOCOMMERCE, self::BIGCOMMERCE, self::WIX, self::EBAY, self::PRESTASHOP, self::OPENCART], true);
    }

    /**
     * Platforms that have no remote system to connect to.
     */
    public function requiresConnection(): bool
    {
        return $this !== self::MANUAL;
    }

    /**
     * Validation rules for the platform-specific "credentials" payload.
     *
     * @return array<string, array<int, string>>
     */
    public function credentialRules(): array
    {
        $databaseName = ['string', 'max:64', 'regex:/^[A-Za-z0-9_\-]+$/'];
        $database = [
            'credentials.db_host' => ['nullable', 'string', 'max:255'],
            'credentials.db_port' => ['nullable', 'integer', 'between:1,65535'],
            'credentials.db_name' => ['required', ...$databaseName],
            'credentials.db_username' => ['nullable', 'string', 'max:255'],
            'credentials.db_password' => ['nullable', 'string', 'max:255'],
            'credentials.table_prefix' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9_]*$/'],
            // The shop's public address, used to build product image links (it is not stored in the shop database).
            'credentials.site_url' => ['nullable', 'url', 'max:255'],
        ];

        return match ($this) {
            self::SHOPIFY => [
                'credentials.shop_domain' => ['required', 'string', 'max:255'],
            ],
            self::WOOCOMMERCE => [
                'credentials.site_url' => ['required', 'url', 'max:255'],
                // Optional: REST API keys created in WooCommerce. Without them the merchant approves access in WordPress.
                'credentials.consumer_key' => ['nullable', 'required_with:credentials.consumer_secret', 'string', 'max:255'],
                'credentials.consumer_secret' => ['nullable', 'required_with:credentials.consumer_key', 'string', 'max:255'],
            ],
            self::BIGCOMMERCE => [
                // Optional with one-click install (the hash comes back from BigCommerce); required with a store-level API token.
                'credentials.store_hash' => ['nullable', 'required_with:credentials.access_token', 'string', 'max:50', 'regex:/^[a-z0-9]+$/'],
                'credentials.access_token' => ['nullable', 'string', 'max:255'],
            ],
            self::PRESTASHOP => [
                // Optional with the Finovo module (the module reports the shop address); required with a webservice key.
                'credentials.site_url' => ['nullable', 'required_with:credentials.webservice_key', 'url', 'max:255'],
                'credentials.webservice_key' => ['nullable', 'string', 'max:255'],
            ],
            self::WIX => [
                // Optional with one-click install (Wix sends the site back); required when connecting with an API key.
                'credentials.site_id' => ['nullable', 'uuid'],
                'credentials.api_key' => ['nullable', 'string', 'max:4096'],
            ],
            self::MAGENTO => [
                'credentials.site_url' => ['required', 'url', 'max:255'],
                'credentials.access_token' => ['required', 'string', 'max:255'],
            ],
            // Optional with the Finovo module; the database details are for connecting without it.
            self::OPENCART => array_merge($database, ['credentials.db_name' => ['nullable', ...$databaseName]]),
            self::OSCOMMERCE => $database,
            self::CUSTOM_BRIDGE => [
                'credentials.bridge_url' => ['required', 'url', 'max:255'],
            ],
            self::EBAY, self::MANUAL => [],
        };
    }
}
