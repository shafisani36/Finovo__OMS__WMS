<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Indexes for the busiest order lookups: by order number (search, scanning and the customer track page), by
     * customer email (credit checks, duplicates) and a store's orders newest first (the Orders page of a store).
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index('order_number', 'idx_ord_number');
            $table->index(['organization_id', 'customer_email'], 'idx_ord_org_email');
            $table->index(['store_id', 'placed_at'], 'idx_ord_store_placed');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('idx_ord_number');
            $table->dropIndex('idx_ord_org_email');
            $table->dropIndex('idx_ord_store_placed');
        });
    }
};
