<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Parcel size captured at the packing station (for the courier and the shipping label).
     */
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->decimal('length_cm', 7, 1)->nullable()->after('weight_kg');
            $table->decimal('width_cm', 7, 1)->nullable()->after('length_cm');
            $table->decimal('height_cm', 7, 1)->nullable()->after('width_cm');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn(['length_cm', 'width_cm', 'height_cm']);
        });
    }
};
