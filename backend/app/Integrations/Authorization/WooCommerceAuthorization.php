<?php

namespace App\Integrations\Authorization;

use App\Models\Store;

/**
 * WooCommerce "Approve" flow (wc-auth/v1). After the merchant approves, WooCommerce
 * POSTs the consumer key and secret to Finovo's callback URL.
 */
class WooCommerceAuthorization
{
    public function authorizationUrl(Store $store, string $state): string
    {
        return rtrim((string) $store->external_reference, '/').'/wc-auth/v1/authorize?'.http_build_query([
            'app_name' => config('app.name'),
            'scope' => 'read_write',
            'user_id' => $store->id,
            'return_url' => config('app.frontend_url').'/stores?connected=woocommerce',
            'callback_url' => route('integrations.woocommerce.callback', ['state' => $state]),
        ]);
    }
}
