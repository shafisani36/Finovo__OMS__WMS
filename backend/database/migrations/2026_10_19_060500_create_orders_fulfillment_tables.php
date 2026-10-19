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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('order_number'); // e.g. ORD-1008
            $table->string('external_order_id'); // e.g. 1008
            $table->string('customer_name');
            $table->string('customer_email')->nullable();
            $table->string('customer_phone')->nullable();
            $table->json('shipping_address')->nullable();
            $table->string('order_status')->default('imported'); // imported, pending, confirmed, allocated, partially_allocated, picking, picked, packed, partially_shipped, shipped, delivered, cancelled, returned, exception
            $table->string('payment_status')->default('pending'); // pending, paid, refunded, failed
            $table->string('fulfillment_status')->default('unfulfilled'); // unfulfilled, partially_fulfilled, fulfilled
            $table->decimal('total_amount', 12, 2)->default(0.00);
            $table->string('currency', 10)->default('USD');
            $table->text('notes')->nullable();
            $table->timestamp('placed_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'store_id', 'external_order_id'], 'idx_order_ext_unique');
            $table->index(['organization_id', 'order_status'], 'idx_ord_org_status');
            $table->index(['organization_id', 'placed_at'], 'idx_ord_org_placed');
            $table->index(['store_id', 'order_status'], 'idx_ord_store_status');
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_sku');
            $table->string('product_name');
            $table->integer('quantity');
            $table->decimal('unit_price', 12, 2)->default(0.00);
            $table->decimal('tax_amount', 12, 2)->default(0.00);
            $table->decimal('total_amount', 12, 2)->default(0.00);
            $table->foreignId('allocated_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->integer('fulfilled_quantity')->default(0);
            $table->string('status')->default('pending'); // pending, allocated, picked, packed, shipped, cancelled
            $table->timestamps();

            $table->index(['order_id', 'status'], 'idx_oi_ord_status');
        });

        Schema::create('pick_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('not_started'); // not_started, in_progress, picked, packed
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('items_count')->default(1);
            $table->string('priority')->default('normal'); // normal, high, urgent
            $table->text('notes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status'], 'idx_pick_org_status');
            $table->index(['warehouse_id', 'status'], 'idx_pick_wh_status');
        });

        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tracking_number');
            $table->string('carrier');
            $table->string('status')->default('pending'); // pending, label_created, shipped, in_transit, delivered, cancelled
            $table->timestamp('shipped_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'tracking_number'], 'idx_ship_org_track');
        });

        Schema::create('returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('return_number');
            $table->string('status')->default('requested'); // requested, approved, received, inspected, rejected, completed
            $table->string('reason')->nullable();
            $table->string('disposition')->nullable(); // sellable, damaged, quarantine, disposed
            $table->integer('items_count')->default(1);
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'return_number'], 'idx_ret_org_num');
            $table->index(['organization_id', 'status'], 'idx_ret_org_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('returns');
        Schema::dropIfExists('shipments');
        Schema::dropIfExists('pick_tasks');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
