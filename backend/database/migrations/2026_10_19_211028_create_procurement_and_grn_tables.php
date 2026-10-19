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
        // 1. Purchase Requisitions
        Schema::create('purchase_requisitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('pr_number', 50);
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->string('department', 100)->nullable();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('delivery_location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();
            $table->string('priority', 20)->default('normal'); // low, normal, high, urgent
            $table->string('status', 30)->default('draft'); // draft, submitted, under_review, approved, partially_ordered, ordered, closed, rejected, cancelled
            $table->date('required_by_date')->nullable();
            $table->string('purpose')->nullable();
            $table->text('justification')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0.00);
            $table->decimal('tax_amount', 14, 2)->default(0.00);
            $table->decimal('total_amount', 14, 2)->default(0.00);
            $table->string('currency', 10)->default('USD');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'pr_number'], 'idx_pr_org_num_uniq');
            $table->index(['organization_id', 'status'], 'idx_pr_org_status');
            $table->index(['warehouse_id', 'status'], 'idx_pr_wh_status');
        });

        // 2. Purchase Requisition Items
        Schema::create('purchase_requisition_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_requisition_id')->constrained('purchase_requisitions')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->string('sku', 100);
            $table->string('product_name');
            $table->integer('requested_quantity')->default(1);
            $table->integer('approved_quantity')->default(0);
            $table->integer('ordered_quantity')->default(0);
            $table->decimal('estimated_unit_price', 14, 2)->default(0.00);
            $table->decimal('estimated_subtotal', 14, 2)->default(0.00);
            $table->string('uom', 20)->default('pcs');
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['purchase_requisition_id', 'product_variant_id'], 'idx_pri_pr_pv');
        });

        // 3. Purchase Requisition Approvals (Audit Trail)
        Schema::create('purchase_requisition_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_requisition_id')->constrained('purchase_requisitions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('action', 30); // submitted, under_review, approved, rejected, cancelled
            $table->text('comments')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['purchase_requisition_id', 'created_at'], 'idx_pra_pr_created');
        });

        // 4. Purchase Orders
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('po_number', 50);
            $table->foreignId('purchase_requisition_id')->nullable()->constrained('purchase_requisitions')->nullOnDelete();
            $table->string('supplier_name');
            $table->string('supplier_email')->nullable();
            $table->string('supplier_contact')->nullable();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 30)->default('draft'); // draft, issued, confirmed, partially_received, received, cancelled
            $table->date('order_date')->nullable();
            $table->date('expected_delivery_date')->nullable();
            $table->string('payment_terms')->nullable();
            $table->string('currency', 10)->default('USD');
            $table->decimal('subtotal', 14, 2)->default(0.00);
            $table->decimal('tax_amount', 14, 2)->default(0.00);
            $table->decimal('total_amount', 14, 2)->default(0.00);
            $table->text('notes')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'po_number'], 'idx_po_org_num_uniq');
            $table->index(['organization_id', 'status'], 'idx_po_org_status');
            $table->index(['warehouse_id', 'status'], 'idx_po_wh_status');
        });

        // 5. Purchase Order Items
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('purchase_requisition_item_id')->nullable()->constrained('purchase_requisition_items')->nullOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->string('sku', 100);
            $table->string('product_name');
            $table->integer('ordered_quantity')->default(1);
            $table->integer('received_quantity')->default(0);
            $table->decimal('unit_cost', 14, 2)->default(0.00);
            $table->decimal('total_cost', 14, 2)->default(0.00);
            $table->string('uom', 20)->default('pcs');
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['purchase_order_id', 'product_variant_id'], 'idx_poi_po_pv');
        });

        // 6. Goods Received Notes (GRN)
        Schema::create('goods_received_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('grn_number', 50);
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('receiving_location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();
            $table->string('supplier_name')->nullable();
            $table->string('supplier_delivery_note', 100)->nullable();
            $table->string('carrier', 100)->nullable();
            $table->string('tracking_or_vehicle_number', 100)->nullable();
            $table->foreignId('received_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('inspected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('draft'); // draft, inspected, posted, cancelled
            $table->timestamp('received_at')->nullable();
            $table->timestamp('inspected_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('total_received_units')->default(0);
            $table->integer('total_accepted_units')->default(0);
            $table->integer('total_damaged_units')->default(0);
            $table->integer('total_quarantined_units')->default(0);
            $table->integer('total_rejected_units')->default(0);
            $table->text('notes')->nullable();
            $table->text('discrepancy_summary')->nullable();
            $table->string('pdf_path')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'grn_number'], 'idx_grn_org_num_uniq');
            $table->index(['organization_id', 'status'], 'idx_grn_org_status');
            $table->index(['purchase_order_id', 'status'], 'idx_grn_po_status');
            $table->index(['warehouse_id', 'status'], 'idx_grn_wh_status');
        });

        // 7. Goods Received Note Items
        Schema::create('goods_received_note_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_received_note_id')->constrained('goods_received_notes')->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained('purchase_order_items')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->string('sku', 100);
            $table->string('product_name');
            $table->integer('ordered_quantity')->default(0);
            $table->integer('previously_received_quantity')->default(0);
            $table->integer('received_quantity')->default(0);
            $table->integer('accepted_quantity')->default(0);
            $table->integer('damaged_quantity')->default(0);
            $table->integer('quarantined_quantity')->default(0);
            $table->integer('rejected_quantity')->default(0);
            $table->integer('pending_inspection_quantity')->default(0);
            $table->foreignId('destination_location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();
            $table->string('lot_number', 100)->nullable();
            $table->date('expiry_date')->nullable();
            $table->date('manufacturing_date')->nullable();
            $table->string('discrepancy_reason')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('draft');
            $table->timestamps();

            $table->index(['goods_received_note_id', 'product_variant_id'], 'idx_grni_grn_pv');
            $table->index(['purchase_order_item_id'], 'idx_grni_poi');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('goods_received_note_items');
        Schema::dropIfExists('goods_received_notes');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('purchase_requisition_approvals');
        Schema::dropIfExists('purchase_requisition_items');
        Schema::dropIfExists('purchase_requisitions');
    }
};
