<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outbox for updates Finovo sends to connected stores (stock quantities, shipped orders with tracking).
 * A task is written in the same database transaction as the change that needs it, then sent by a worker
 * that retries with increasing waits, so a store being down never loses or repeats an update.
 * One row per store, type and subject: a newer change re-queues the same row instead of adding another.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_sync_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->unsignedBigInteger('subject_id');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'type', 'subject_id']);
            $table->index(['status', 'available_at']);
        });

        Schema::table('shipments', function (Blueprint $table) {
            // The store's ID for the fulfillment Finovo created there, so delivery can be reported on it.
            $table->string('store_fulfillment_id', 100)->nullable()->after('tracking_url');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn('store_fulfillment_id');
        });
        Schema::dropIfExists('store_sync_tasks');
    }
};
