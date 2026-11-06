<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Products that track a serial number for every unit (unit_serial_numbers): each unit is registered when it
     * arrives and scanned onto its order before dispatch.
     */
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->boolean('track_serials')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', fn (Blueprint $table) => $table->dropColumn('track_serials'));
    }
};
