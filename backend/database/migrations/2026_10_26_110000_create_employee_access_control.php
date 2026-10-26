<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee access control: roles give default permissions, an admin can allow or deny single actions for one
 * employee (user_permission_overrides; no row means "follow the role"), and an employee works either in every
 * warehouse or only in the warehouses assigned to them. Warehouse assignments keep their history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_permission_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('permission', 100);
            $table->string('effect', 10); // allow | deny
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'permission'], 'uniq_user_permission');
            $table->index(['organization_id', 'user_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            // Raised on every permission change, so two admins cannot overwrite each other's edits unseen.
            $table->unsignedInteger('permissions_version')->default(0)->after('role');
            // all = every warehouse of the company; assigned = only the warehouses in warehouse_memberships.
            $table->string('warehouse_access', 20)->default('all')->after('permissions_version');
        });

        // An earlier draft kept a list of allowed pages on the user; overrides replace it.
        if (Schema::hasColumn('users', 'permissions')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('permissions'));
        }

        Schema::table('warehouse_memberships', function (Blueprint $table) {
            $table->foreignId('assigned_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable()->after('assigned_at');
            $table->index(['warehouse_id', 'status'], 'idx_wh_mem_status');
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_memberships', function (Blueprint $table) {
            $table->dropIndex('idx_wh_mem_status');
            $table->dropConstrainedForeignId('assigned_by');
            $table->dropColumn('revoked_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['permissions_version', 'warehouse_access']);
        });

        Schema::dropIfExists('user_permission_overrides');
    }
};
