<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Packed orders become shipments before a carrier is chosen, so carrier and tracking are filled in at dispatch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('tracking_number')->nullable()->change();
            $table->string('carrier')->nullable()->change();
            $table->foreignId('dispatched_by_user_id')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('delivered_at')->nullable()->after('shipped_at');
            $table->index(['organization_id', 'status'], 'idx_ship_org_status');
        });

        // Orders packed before this change are waiting for dispatch too.
        DB::table('pick_tasks')
            ->where('status', 'packed')
            ->whereNotExists(fn ($q) => $q->from('shipments')->whereColumn('shipments.order_id', 'pick_tasks.order_id'))
            ->get(['organization_id', 'order_id', 'warehouse_id'])
            ->each(fn ($task) => DB::table('shipments')->insert([
                'organization_id' => $task->organization_id,
                'order_id' => $task->order_id,
                'warehouse_id' => $task->warehouse_id,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]));
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex('idx_ship_org_status');
            $table->dropConstrainedForeignId('dispatched_by_user_id');
            $table->dropColumn('delivered_at');
        });
    }
};
