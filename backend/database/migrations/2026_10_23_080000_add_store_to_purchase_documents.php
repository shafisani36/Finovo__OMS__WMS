<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase requisitions and purchase orders belong to the store that raised them, so each store only sees its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['purchase_requisitions', 'purchase_orders'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->foreignId('store_id')->nullable()->after('organization_id')->constrained()->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['purchase_requisitions', 'purchase_orders'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropConstrainedForeignId('store_id');
            });
        }
    }
};
