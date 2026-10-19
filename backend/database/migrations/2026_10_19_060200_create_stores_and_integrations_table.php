<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code');
            $table->string('platform'); // shopify, woocommerce, bigcommerce, prestashop, wix, ebay, magento, opencart, oscommerce, manual, custom_bridge
            $table->string('status')->default('active'); // active, setup_incomplete, disconnected
            $table->string('sync_status')->default('healthy'); // healthy, sync_issues
            $table->integer('orders_awaiting_sync')->default(0);
            $table->string('api_key')->nullable();
            $table->text('api_secret')->nullable();
            $table->string('webhook_secret')->nullable();
            $table->string('bridge_url')->nullable();
            $table->json('settings')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('store_warehouse_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_default')->default(false);
            $table->integer('priority')->default(0);
            $table->timestamps();

            $table->unique(['store_id', 'warehouse_id']);
        });

        Schema::create('custom_bridge_nonces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('nonce', 128);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['store_id', 'nonce']);
            $table->index('created_at');
        });

        Schema::create('integration_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('job_type'); // catalog_sync, order_sync, inventory_push, webhook_process
            $table->string('status')->default('pending'); // pending, processing, succeeded, failed, dead_letter
            $table->json('payload')->nullable();
            $table->text('error_message')->nullable();
            $table->integer('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['store_id', 'status']);
        });

        Schema::create('integration_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('error_code');
            $table->string('summary');
            $table->text('technical_details')->nullable();
            $table->boolean('is_retryable')->default(true);
            $table->integer('attempts')->default(1);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'resolved_at']);
        });

        Schema::create('outbox_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->json('payload');
            $table->string('status')->default('pending'); // pending, published, failed
            $table->integer('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('integration_errors');
        Schema::dropIfExists('integration_jobs');
        Schema::dropIfExists('custom_bridge_nonces');
        Schema::dropIfExists('store_warehouse_assignments');
        Schema::dropIfExists('stores');
    }
};
