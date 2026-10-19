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
        Schema::create('inventory_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->integer('on_hand')->default(0);
            $table->integer('reserved')->default(0);
            $table->integer('available')->default(0);
            $table->integer('damaged')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'warehouse_id', 'product_variant_id'], 'idx_inv_balance_unique');
            $table->index(['organization_id', 'warehouse_id'], 'idx_inv_bal_org_wh');
            $table->index(['organization_id', 'product_variant_id'], 'idx_inv_bal_org_pv');
        });

        Schema::create('inventory_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->string('movement_type'); // receipt, transfer_in, transfer_out, reservation, release, adjustment, shipment, return, reconciliation
            $table->integer('quantity'); // can be positive or negative
            $table->integer('balance_before');
            $table->integer('balance_after');
            $table->string('reference_type')->nullable(); // Order, Transfer, Adjustment, Receipt
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_number')->nullable(); // e.g. MOV-000123
            $table->string('reason')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('idempotency_key')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'warehouse_id', 'created_at'], 'idx_ile_org_wh_created');
            $table->index(['organization_id', 'product_variant_id', 'created_at'], 'idx_ile_org_pv_created');
            $table->index(['organization_id', 'idempotency_key'], 'idx_ile_org_idemp');
            $table->index('reference_number', 'idx_ile_ref_num');
        });

        Schema::create('inventory_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('order_item_id');
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->integer('quantity');
            $table->string('status')->default('active'); // active, released, fulfilled
            $table->string('idempotency_key')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'order_id', 'status'], 'idx_res_org_ord_stat');
            $table->index(['organization_id', 'product_variant_id'], 'idx_res_org_pv');
        });

        Schema::create('inventory_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('transfer_number');
            $table->foreignId('from_warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('to_warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->integer('quantity');
            $table->string('status')->default('pending'); // pending, in_transit, completed, cancelled
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'transfer_number'], 'idx_trf_org_num_unique');
            $table->index(['organization_id', 'status'], 'idx_trf_org_stat');
        });

        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->string('adjustment_type'); // add, subtract, recount
            $table->integer('quantity');
            $table->string('reason'); // damage, loss, correction, audit
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'warehouse_id'], 'idx_adj_org_wh');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_adjustments');
        Schema::dropIfExists('inventory_transfers');
        Schema::dropIfExists('inventory_reservations');
        Schema::dropIfExists('inventory_ledger_entries');
        Schema::dropIfExists('inventory_balances');
    }
};
