<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave picking (several orders picked in one round of the warehouse) and barcode-verified picking and packing
 * (the units of each order line scanned so far, per stage).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pick_waves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->string('wave_number', 30);
            $table->string('status', 20)->default('open');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'wave_number']);
            $table->index(['organization_id', 'status']);
        });

        Schema::table('pick_tasks', function (Blueprint $table) {
            $table->foreignId('pick_wave_id')->nullable()->after('warehouse_id')->constrained('pick_waves')->nullOnDelete();
            $table->json('scan_progress')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('pick_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pick_wave_id');
            $table->dropColumn('scan_progress');
        });

        Schema::dropIfExists('pick_waves');
    }
};
