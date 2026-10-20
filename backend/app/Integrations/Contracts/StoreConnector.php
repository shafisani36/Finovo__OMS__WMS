<?php

namespace App\Integrations\Contracts;

use App\Integrations\ConnectionResult;
use App\Models\Store;

interface StoreConnector
{
    /**
     * Call the platform with the store's saved credentials and report whether access works.
     */
    public function testConnection(Store $store): ConnectionResult;
}
