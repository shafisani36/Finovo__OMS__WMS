<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wrong units refused while scanning an order: at the shelf (pick) and at packing (pack). A packing refusal is
 * a picking mistake caught before shipping; the warehouse performance page reports it as picking accuracy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pick_tasks', function (Blueprint $table) {
            $table->unsignedInteger('pick_errors')->default(0)->after('scan_progress');
            $table->unsignedInteger('pack_errors')->default(0)->after('pick_errors');
        });
    }

    public function down(): void
    {
        Schema::table('pick_tasks', function (Blueprint $table) {
            $table->dropColumn(['pick_errors', 'pack_errors']);
        });
    }
};
