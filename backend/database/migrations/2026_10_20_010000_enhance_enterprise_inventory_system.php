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
        // 1. Enhance products table with UOM and Tracking configuration
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'base_uom')) {
                $table->string('base_uom', 20)->default('pcs')->after('category');
            }
            if (! Schema::hasColumn('products', 'tracking_type')) {
                $table->string('tracking_type', 30)->default('untracked')->after('base_uom'); // untracked, lot_tracked, serial_tracked, expiry_tracked
            }
        });

        // 2. Enhance inventory_balances table with in_transit and quarantined states
        Schema::table('inventory_balances', function (Blueprint $table) {
            if (! Schema::hasColumn('inventory_balances', 'in_transit')) {
                $table->integer('in_transit')->default(0)->after('damaged');
            }
            if (! Schema::hasColumn('inventory_balances', 'quarantined')) {
                $table->integer('quarantined')->default(0)->after('in_transit');
            }
            if (! Schema::hasColumn('inventory_balances', 'picked')) {
                $table->integer('picked')->default(0)->after('quarantined');
            }
        });

        // 3. Create inventory_location_balances for physical storage bin & lot tracking
        if (! Schema::hasTable('inventory_location_balances')) {
            Schema::create('inventory_location_balances', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
                $table->foreignId('warehouse_location_id')->constrained('warehouse_locations')->cascadeOnDelete();
                $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
                $table->string('lot_number', 100)->nullable();
                $table->date('expiry_date')->nullable();
                $table->string('condition', 30)->default('available'); // available, quarantined, damaged, held
                $table->integer('quantity')->default(0);
                $table->integer('reserved')->default(0);
                $table->timestamps();

                $table->index(['organization_id', 'warehouse_id', 'product_variant_id'], 'idx_ilb_org_wh_pv');
                $table->index(['warehouse_location_id', 'product_variant_id'], 'idx_ilb_loc_pv');
                $table->index(['organization_id', 'lot_number'], 'idx_ilb_org_lot');
            });
        }

        // 4. Enhance inventory_ledger_entries with location, lot, serial, and condition
        Schema::table('inventory_ledger_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('inventory_ledger_entries', 'warehouse_location_id')) {
                $table->foreignId('warehouse_location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete()->after('warehouse_id');
            }
            if (! Schema::hasColumn('inventory_ledger_entries', 'destination_location_id')) {
                $table->foreignId('destination_location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete()->after('warehouse_location_id');
            }
            if (! Schema::hasColumn('inventory_ledger_entries', 'lot_number')) {
                $table->string('lot_number', 100)->nullable()->after('destination_location_id');
            }
            if (! Schema::hasColumn('inventory_ledger_entries', 'serial_number')) {
                $table->string('serial_number', 100)->nullable()->after('lot_number');
            }
            if (! Schema::hasColumn('inventory_ledger_entries', 'expiry_date')) {
                $table->date('expiry_date')->nullable()->after('serial_number');
            }
            if (! Schema::hasColumn('inventory_ledger_entries', 'inventory_status')) {
                $table->string('inventory_status', 30)->default('available')->after('expiry_date');
            }
            if (! Schema::hasColumn('inventory_ledger_entries', 'unit_of_measure')) {
                $table->string('unit_of_measure', 20)->default('pcs')->after('inventory_status');
            }
        });

        // 5. Enhance stock_adjustments with approval workflow and location
        Schema::table('stock_adjustments', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_adjustments', 'adjustment_number')) {
                $table->string('adjustment_number', 50)->nullable()->after('id');
            }
            if (! Schema::hasColumn('stock_adjustments', 'warehouse_location_id')) {
                $table->foreignId('warehouse_location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete()->after('warehouse_id');
            }
            if (! Schema::hasColumn('stock_adjustments', 'lot_number')) {
                $table->string('lot_number', 100)->nullable()->after('warehouse_location_id');
            }
            if (! Schema::hasColumn('stock_adjustments', 'current_quantity')) {
                $table->integer('current_quantity')->default(0)->after('quantity');
            }
            if (! Schema::hasColumn('stock_adjustments', 'new_quantity')) {
                $table->integer('new_quantity')->default(0)->after('current_quantity');
            }
            if (! Schema::hasColumn('stock_adjustments', 'status')) {
                $table->string('status', 30)->default('approved')->after('reason'); // pending_approval, approved, rejected, posted
            }
            if (! Schema::hasColumn('stock_adjustments', 'approved_by_user_id')) {
                $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete()->after('user_id');
            }
            if (! Schema::hasColumn('stock_adjustments', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by_user_id');
            }
        });

        // 6. Enhance inventory_transfers with dispatch, transit, receipt details
        Schema::table('inventory_transfers', function (Blueprint $table) {
            if (! Schema::hasColumn('inventory_transfers', 'from_location_id')) {
                $table->foreignId('from_location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete()->after('from_warehouse_id');
            }
            if (! Schema::hasColumn('inventory_transfers', 'to_location_id')) {
                $table->foreignId('to_location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete()->after('to_warehouse_id');
            }
            if (! Schema::hasColumn('inventory_transfers', 'dispatched_at')) {
                $table->timestamp('dispatched_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('inventory_transfers', 'received_at')) {
                $table->timestamp('received_at')->nullable()->after('dispatched_at');
            }
            if (! Schema::hasColumn('inventory_transfers', 'received_by_user_id')) {
                $table->foreignId('received_by_user_id')->nullable()->constrained('users')->nullOnDelete()->after('user_id');
            }
            if (! Schema::hasColumn('inventory_transfers', 'received_quantity')) {
                $table->integer('received_quantity')->nullable()->after('quantity');
            }
            if (! Schema::hasColumn('inventory_transfers', 'lot_number')) {
                $table->string('lot_number', 100)->nullable()->after('received_quantity');
            }
            if (! Schema::hasColumn('inventory_transfers', 'discrepancy_quantity')) {
                $table->integer('discrepancy_quantity')->default(0)->after('lot_number');
            }
            if (! Schema::hasColumn('inventory_transfers', 'discrepancy_reason')) {
                $table->string('discrepancy_reason')->nullable()->after('discrepancy_quantity');
            }
        });

        // 7. Create inventory_receipts table
        if (! Schema::hasTable('inventory_receipts')) {
            Schema::create('inventory_receipts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
                $table->string('receipt_number', 50);
                $table->string('supplier_name')->nullable();
                $table->string('purchase_order_number')->nullable();
                $table->string('status', 30)->default('draft'); // draft, received, putaway_in_progress, completed, cancelled
                $table->foreignId('receiving_location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();
                $table->text('notes')->nullable();
                $table->foreignId('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('received_at')->nullable();
                $table->timestamps();

                $table->unique(['organization_id', 'receipt_number'], 'idx_rec_org_num_uniq');
                $table->index(['organization_id', 'warehouse_id', 'status'], 'idx_rec_org_wh_stat');
            });
        }

        // 8. Create inventory_receipt_items table
        if (! Schema::hasTable('inventory_receipt_items')) {
            Schema::create('inventory_receipt_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('inventory_receipt_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
                $table->integer('expected_quantity')->default(0);
                $table->integer('received_quantity')->default(0);
                $table->foreignId('location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();
                $table->foreignId('destination_location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();
                $table->string('lot_number', 100)->nullable();
                $table->date('expiry_date')->nullable();
                $table->string('condition', 30)->default('available');
                $table->decimal('unit_cost', 12, 2)->default(0.00);
                $table->string('status', 30)->default('pending'); // pending, received, putaway_completed
                $table->timestamps();

                $table->index(['inventory_receipt_id', 'status'], 'idx_ri_rec_stat');
            });
        }

        // 9. Create cycle_counts table
        if (! Schema::hasTable('cycle_counts')) {
            Schema::create('cycle_counts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
                $table->string('count_number', 50);
                $table->string('title');
                $table->string('status', 30)->default('draft'); // draft, in_progress, review, posted, cancelled
                $table->string('scope_type', 30)->default('full'); // full, zone, location, category
                $table->string('scope_value')->nullable();
                $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('posted_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->unique(['organization_id', 'count_number'], 'idx_cc_org_num_uniq');
                $table->index(['organization_id', 'warehouse_id', 'status'], 'idx_cc_org_wh_stat');
            });
        }

        // 10. Create cycle_count_items table
        if (! Schema::hasTable('cycle_count_items')) {
            Schema::create('cycle_count_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('cycle_count_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('warehouse_location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();
                $table->string('lot_number', 100)->nullable();
                $table->integer('snapshot_quantity')->default(0);
                $table->integer('counted_quantity')->nullable();
                $table->integer('variance_quantity')->default(0);
                $table->string('status', 30)->default('pending'); // pending, counted, recounted, approved, rejected
                $table->string('notes')->nullable();
                $table->timestamps();

                $table->index(['cycle_count_id', 'status'], 'idx_cci_count_stat');
            });
        }

        // 11. Create reorder_rules table
        if (! Schema::hasTable('reorder_rules')) {
            Schema::create('reorder_rules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
                $table->integer('min_quantity')->default(0);
                $table->integer('reorder_point')->default(10);
                $table->integer('reorder_quantity')->default(50);
                $table->integer('safety_stock')->default(5);
                $table->integer('lead_time_days')->default(7);
                $table->string('preferred_supplier')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['organization_id', 'warehouse_id', 'product_variant_id'], 'idx_rr_org_wh_pv_uniq');
                $table->index(['organization_id', 'is_active'], 'idx_rr_org_active');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reorder_rules');
        Schema::dropIfExists('cycle_count_items');
        Schema::dropIfExists('cycle_counts');
        Schema::dropIfExists('inventory_receipt_items');
        Schema::dropIfExists('inventory_receipts');
        Schema::dropIfExists('inventory_location_balances');
    }
};
