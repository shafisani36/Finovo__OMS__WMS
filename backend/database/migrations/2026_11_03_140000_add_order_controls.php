<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Order holds (manual, approval, credit limit), customer credit limits and the order timeline (audit history).
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('hold_type', 20)->nullable()->after('fulfillment_status');
            $table->string('hold_reason', 500)->nullable()->after('hold_type');
            $table->timestamp('held_at')->nullable()->after('hold_reason');
            $table->foreignId('held_by_user_id')->nullable()->after('held_at')->constrained('users')->nullOnDelete();
            $table->index(['organization_id', 'hold_type']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->decimal('credit_limit', 14, 2)->nullable();
        });

        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('description', 500);
            $table->json('changes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_events');
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('credit_limit'));
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'hold_type']);
            $table->dropConstrainedForeignId('held_by_user_id');
            $table->dropColumn(['hold_type', 'hold_reason', 'held_at']);
        });
    }
};
