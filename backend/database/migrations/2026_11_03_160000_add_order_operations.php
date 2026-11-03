<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The company's own order statuses, recurring orders, and refunds or replacements for returns.
     */
    public function up(): void
    {
        Schema::create('recurring_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // The order to repeat: store, customer, address, currency, payment and lines, as sent to OrderService.
            $table->json('template');
            $table->string('interval', 20);
            $table->timestamp('next_run_at');
            $table->boolean('active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
            $table->index(['active', 'next_run_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('custom_status', 40)->nullable()->after('hold_type');
            $table->foreignId('recurring_order_id')->nullable()->constrained()->nullOnDelete();
            $table->index(['organization_id', 'custom_status']);
        });

        Schema::table('returns', function (Blueprint $table) {
            $table->string('resolution', 20)->nullable()->after('disposition');
            $table->decimal('refund_amount', 14, 2)->nullable()->after('resolution');
            $table->string('refund_method', 100)->nullable()->after('refund_amount');
            $table->foreignId('replacement_order_id')->nullable()->after('refund_method')->constrained('orders')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replacement_order_id');
            $table->dropColumn(['resolution', 'refund_amount', 'refund_method', 'resolved_at']);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'custom_status']);
            $table->dropConstrainedForeignId('recurring_order_id');
            $table->dropColumn('custom_status');
        });
        Schema::dropIfExists('recurring_orders');
    }
};
