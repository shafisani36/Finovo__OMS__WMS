<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-way store integration:
 * - store_product_links: which product on each store a Finovo product is (variants are linked by sku_mappings),
 *   so editing or deleting a product in Finovo updates the same product on the store.
 * - store_webhook_events: every notification a store sends (new order, product changed...), so a repeated
 *   delivery is processed only once and failures can be seen and retried.
 * - orders.created_by: who created an order by hand in Finovo (empty for imported orders). An order made in
 *   Finovo may have no store (walk-in, phone or wholesale) and has no store ID until it is sent to its store.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_product_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 100);
            $table->timestamps();

            $table->unique(['store_id', 'product_id']);
            $table->index(['store_id', 'external_id']);
        });

        Schema::create('store_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('topic', 80);
            $table->string('delivery_id', 150);
            $table->string('resource_id', 100)->nullable();
            $table->string('status', 20)->default('received');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'delivery_id']);
            $table->index(['store_id', 'status']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('store_id')->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('store_id')->nullable()->change();
            $table->string('external_order_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });
        // store_id and external_order_id stay nullable: orders created in Finovo may have neither.
        Schema::dropIfExists('store_webhook_events');
        Schema::dropIfExists('store_product_links');
    }
};
