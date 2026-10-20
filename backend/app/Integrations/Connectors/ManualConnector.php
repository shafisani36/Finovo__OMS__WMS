<?php

namespace App\Integrations\Connectors;

use App\Integrations\ConnectionResult;
use App\Integrations\Contracts\StoreConnector;
use App\Models\Store;

/**
 * Manual stores have no remote platform; orders are entered in Finovo directly.
 */
class ManualConnector implements StoreConnector
{
    public function testConnection(Store $store): ConnectionResult
    {
        return ConnectionResult::success('Manual store is ready. Orders are entered in Finovo.');
    }
}
