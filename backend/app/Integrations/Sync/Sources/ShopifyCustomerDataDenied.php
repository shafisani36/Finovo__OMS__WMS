<?php

namespace App\Integrations\Sync\Sources;

use RuntimeException;

/**
 * Shopify refused customer fields because the app is not approved for protected customer data.
 */
class ShopifyCustomerDataDenied extends RuntimeException {}
