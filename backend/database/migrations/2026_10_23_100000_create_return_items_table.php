<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Returns record which order lines came back, how many, and where they were restocked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->foreignId('warehouse_id')->nullable()->after('order_id')->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->after('warehouse_id')->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable()->after('disposition');
            $table->timestamp('completed_at')->nullable()->after('received_at');
        });

        Schema::create('return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_id')->constrained('returns')->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku')->nullable();
            $table->string('product_name');
            $table->unsignedInteger('quantity');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_items');

        Schema::table('returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warehouse_id');
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropColumn(['notes', 'completed_at']);
        });
    }
};
