<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Purchase cost of each unit received, the base of FIFO / LIFO / AVCO inventory valuation.
     */
    public function up(): void
    {
        Schema::table('inventory_ledger_entries', function (Blueprint $table) {
            $table->decimal('unit_cost', 14, 4)->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_ledger_entries', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });
    }
};
