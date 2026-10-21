<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales documents are invoices issued from orders; the earlier quotation tables are no longer used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('quotation_items');
        Schema::dropIfExists('quotations');

        Schema::table('orders', function (Blueprint $table) {
            $table->string('invoice_number')->nullable()->after('order_number'); // INV-2026-0001, given when first issued
            $table->timestamp('invoiced_at')->nullable()->after('placed_at');
            $table->unique(['organization_id', 'invoice_number'], 'idx_order_invoice_number');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('idx_order_invoice_number');
            $table->dropColumn(['invoice_number', 'invoiced_at']);
        });
    }
};
