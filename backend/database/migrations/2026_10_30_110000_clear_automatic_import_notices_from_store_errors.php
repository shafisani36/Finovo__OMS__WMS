<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stores connected in a way that cannot notify Finovo (database details, an API key, or an old module) were
 * marked as having sync issues when automatic import was switched on. That is not a fault: their orders are read
 * every minute. Clear those notices and keep them as "unavailable" automatic import.
 */
return new class extends Migration
{
    private const NOTICES = [
        'This OpenCart store is connected with database details',
        'Install the latest Finovo module in OpenCart',
        'Install the latest Finovo module in PrestaShop',
        'This Wix site is connected with an API key',
    ];

    public function up(): void
    {
        DB::table('stores')->where('sync_status', 'sync_issues')->whereNotNull('connection_error')->orderBy('id')
            ->each(function (object $store) {
                if (! collect(self::NOTICES)->contains(fn (string $notice) => str_starts_with($store->connection_error, $notice))) {
                    return;
                }

                $settings = json_decode($store->settings ?? '[]', true) ?: [];
                if (isset($settings['webhooks'])) {
                    $settings['webhooks'] = ['status' => 'unavailable', 'message' => $store->connection_error] + $settings['webhooks'];
                }

                DB::table('stores')->where('id', $store->id)->update([
                    'sync_status' => 'healthy',
                    'connection_error' => null,
                    'settings' => json_encode($settings),
                ]);
            });
    }

    public function down(): void
    {
        // The notices were not faults; nothing to restore.
    }
};
