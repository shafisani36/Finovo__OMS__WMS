<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps everything a store platform sends for products and orders: every product photo, the platform's other facts
 * (details: vendor, tags, weight...), order totals and addresses, and the complete original records (platform_data), so
 * nothing is lost to a fixed set of columns. A product sold in several stores keeps each store's record separately.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('images')->nullable()->after('image_path');
            $table->json('details')->nullable()->after('images');
            $table->json('platform_data')->nullable()->after('details');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('image_url', 2048)->nullable()->after('option_value');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->json('billing_address')->nullable()->after('shipping_address');
            $table->decimal('subtotal_amount', 12, 2)->nullable()->after('fulfillment_status');
            $table->decimal('shipping_amount', 12, 2)->nullable()->after('subtotal_amount');
            $table->decimal('tax_amount', 12, 2)->nullable()->after('shipping_amount');
            $table->decimal('discount_amount', 12, 2)->nullable()->after('tax_amount');
            $table->string('payment_method')->nullable()->after('discount_amount');
            $table->json('details')->nullable()->after('notes');
            $table->json('platform_data')->nullable()->after('details');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('discount_amount', 12, 2)->default(0)->after('tax_amount');
            $table->string('image_url', 2048)->nullable()->after('product_name');
            $table->json('platform_data')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['discount_amount', 'image_url', 'platform_data']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['billing_address', 'subtotal_amount', 'shipping_amount', 'tax_amount', 'discount_amount', 'payment_method', 'details', 'platform_data']);
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('image_url');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['images', 'details', 'platform_data']);
        });
    }
};
