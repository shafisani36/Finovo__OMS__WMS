<?php

namespace App\Integrations\Connectors;

use App\Enums\StorePlatform;
use App\Integrations\ConnectionResult;
use App\Integrations\Contracts\StoreConnector;
use App\Models\Store;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * OpenCart and osCommerce have no usable REST API, so Finovo reads the shop's MySQL database directly.
 */
class ExternalDatabaseConnector implements StoreConnector
{
    public function testConnection(Store $store): ConnectionResult
    {
        $platform = $store->platform->label();
        $ordersTable = $this->ordersTable($store);

        try {
            $count = $this->connection($store)->table($ordersTable)->count();
        } catch (Throwable $e) {
            report($e);

            return ConnectionResult::failure("Could not read the {$platform} database. Check the database name, login and that table \"{$ordersTable}\" exists.");
        }

        return ConnectionResult::success("Connected to the {$platform} database ({$count} orders found).");
    }

    /**
     * On-demand connection to the shop database. Missing values fall back to DB_EXTERNAL_* in .env.
     */
    public function connection(Store $store): Connection
    {
        $defaults = config('services.external_database');

        return DB::build([
            'driver' => 'mysql',
            'host' => $store->credential('db_host') ?: $defaults['host'],
            'port' => $store->credential('db_port') ?: $defaults['port'],
            'database' => $store->credential('db_name'),
            'username' => $store->credential('db_username') ?: $defaults['username'],
            'password' => $store->credential('db_password') ?: $defaults['password'],
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'options' => [\PDO::ATTR_TIMEOUT => 10],
        ]);
    }

    public function ordersTable(Store $store): string
    {
        $prefix = $store->credential('table_prefix') ?? ($store->platform === StorePlatform::OPENCART ? 'oc_' : '');

        return $store->platform === StorePlatform::OPENCART ? "{$prefix}order" : "{$prefix}orders";
    }
}
