<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cycle counts that start by themselves every few days (a zone a week, the whole warehouse a month, ...).
     */
    public function up(): void
    {
        Schema::create('cycle_count_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->string('title', 150);
            $table->string('scope_type', 20);
            $table->string('scope_value', 100)->nullable();
            $table->unsignedSmallInteger('every_days');
            $table->date('next_run_on');
            $table->foreignId('last_cycle_count_id')->nullable()->constrained('cycle_counts')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['next_run_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cycle_count_schedules');
    }
};
