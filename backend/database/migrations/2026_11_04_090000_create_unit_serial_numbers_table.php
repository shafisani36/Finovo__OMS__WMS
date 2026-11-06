<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An earlier copy of this migration stopped half way on MySQL (return_items has no notes column to place
        // the new column after), so each step is skipped when it already ran.
        if (Schema::hasTable('unit_serial_numbers')) {
            $this->addSerialColumns();

            return;
        }

        Schema::create('unit_serial_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();

            $table->string('serial_number', 120);
            $table->string('lot_number', 80)->nullable();
            $table->string('status', 40)->default('in_stock'); // in_stock, allocated, picked, packed, shipped, delivered, returned, damaged, quarantined, disposed

            // Linked document references for complete traceability
            $table->foreignId('grn_id')->nullable()->constrained('goods_received_notes')->nullOnDelete();
            $table->foreignId('grn_item_id')->nullable()->constrained('goods_received_note_items')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
            $table->foreignId('pick_task_id')->nullable()->constrained('pick_tasks')->nullOnDelete();
            $table->foreignId('order_return_id')->nullable()->constrained('returns')->nullOnDelete();
            $table->foreignId('return_item_id')->nullable()->constrained('return_items')->nullOnDelete();

            $table->timestamp('received_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('returned_at')->nullable();

            $table->json('history')->nullable(); // Traceability timeline events
            $table->timestamps();

            $table->unique(['organization_id', 'product_variant_id', 'serial_number'], 'unit_serial_unique');
            $table->index(['organization_id', 'serial_number'], 'unit_serial_lookup');
            $table->index(['organization_id', 'status'], 'unit_serial_status');
            $table->index(['organization_id', 'order_id'], 'unit_serial_order');
        });

        $this->addSerialColumns();
    }

    private function addSerialColumns(): void
    {
        foreach (['goods_received_note_items', 'return_items'] as $name) {
            if (! Schema::hasColumn($name, 'serial_numbers')) {
                Schema::table($name, fn (Blueprint $table) => $table->json('serial_numbers')->nullable());
            }
        }
    }

    public function down(): void
    {
        Schema::table('goods_received_note_items', function (Blueprint $table) {
            $table->dropColumn('serial_numbers');
        });
        Schema::table('return_items', function (Blueprint $table) {
            $table->dropColumn('serial_numbers');
        });
        Schema::dropIfExists('unit_serial_numbers');
    }
};
