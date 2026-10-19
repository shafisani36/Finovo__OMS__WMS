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
        // 1. Enhance warehouses table defensively
        Schema::table('warehouses', function (Blueprint $table) {
            if (! Schema::hasColumn('warehouses', 'warehouse_type')) {
                $table->string('warehouse_type', 50)->default('distribution_center')->after('code');
            }
            if (! Schema::hasColumn('warehouses', 'description')) {
                $table->text('description')->nullable()->after('warehouse_type');
            }
            if (! Schema::hasColumn('warehouses', 'status')) {
                $table->string('status', 30)->default('draft')->after('description');
            }
            if (! Schema::hasColumn('warehouses', 'is_default')) {
                $table->boolean('is_default')->default(false)->after('status');
            }
            if (! Schema::hasColumn('warehouses', 'external_reference')) {
                $table->string('external_reference', 100)->nullable()->after('is_default');
            }
            if (! Schema::hasColumn('warehouses', 'parent_warehouse_id')) {
                $table->foreignId('parent_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete()->after('external_reference');
            }
            if (! Schema::hasColumn('warehouses', 'address_line_2')) {
                $table->string('address_line_2')->nullable()->after('address');
            }
            if (! Schema::hasColumn('warehouses', 'state')) {
                $table->string('state', 100)->nullable()->after('city');
            }
            if (! Schema::hasColumn('warehouses', 'timezone')) {
                $table->string('timezone', 50)->default('UTC')->after('postal_code');
            }
            if (! Schema::hasColumn('warehouses', 'contact_name')) {
                $table->string('contact_name', 150)->nullable()->after('timezone');
            }
            if (! Schema::hasColumn('warehouses', 'contact_email')) {
                $table->string('contact_email', 150)->nullable()->after('contact_name');
            }
            if (! Schema::hasColumn('warehouses', 'contact_phone')) {
                $table->string('contact_phone', 50)->nullable()->after('contact_email');
            }
            if (! Schema::hasColumn('warehouses', 'latitude')) {
                $table->decimal('latitude', 10, 7)->nullable()->after('contact_phone');
            }
            if (! Schema::hasColumn('warehouses', 'longitude')) {
                $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            }
            if (! Schema::hasColumn('warehouses', 'operating_hours')) {
                $table->json('operating_hours')->nullable()->after('longitude');
            }
            if (! Schema::hasColumn('warehouses', 'inventory_policies')) {
                $table->json('inventory_policies')->nullable()->after('operating_hours');
            }
            if (! Schema::hasColumn('warehouses', 'inbound_policies')) {
                $table->json('inbound_policies')->nullable()->after('inventory_policies');
            }
            if (! Schema::hasColumn('warehouses', 'outbound_policies')) {
                $table->json('outbound_policies')->nullable()->after('inbound_policies');
            }
            if (! Schema::hasColumn('warehouses', 'integration_settings')) {
                $table->json('integration_settings')->nullable()->after('outbound_policies');
            }
        });

        // 2. Enhance warehouse_locations table defensively
        Schema::table('warehouse_locations', function (Blueprint $table) {
            if (! Schema::hasColumn('warehouse_locations', 'parent_id')) {
                $table->foreignId('parent_id')->nullable()->constrained('warehouse_locations')->nullOnDelete()->after('warehouse_id');
            }
            if (! Schema::hasColumn('warehouse_locations', 'location_type')) {
                $table->string('location_type', 40)->default('bin')->after('code');
            }
            if (! Schema::hasColumn('warehouse_locations', 'barcode')) {
                $table->string('barcode', 100)->nullable()->after('location_type');
            }
            if (! Schema::hasColumn('warehouse_locations', 'picking_eligible')) {
                $table->boolean('picking_eligible')->default(true)->after('bin');
            }
            if (! Schema::hasColumn('warehouse_locations', 'putaway_eligible')) {
                $table->boolean('putaway_eligible')->default(true)->after('picking_eligible');
            }
            if (! Schema::hasColumn('warehouse_locations', 'capacity')) {
                $table->decimal('capacity', 12, 2)->nullable()->after('putaway_eligible');
            }
            if (! Schema::hasColumn('warehouse_locations', 'capacity_unit')) {
                $table->string('capacity_unit', 30)->default('units')->after('capacity');
            }
            if (! Schema::hasColumn('warehouse_locations', 'weight_capacity')) {
                $table->decimal('weight_capacity', 12, 2)->nullable()->after('capacity_unit');
            }
            if (! Schema::hasColumn('warehouse_locations', 'sort_order')) {
                $table->integer('sort_order')->default(0)->after('weight_capacity');
            }
        });

        // 3. Create warehouse_memberships table defensively
        if (! Schema::hasTable('warehouse_memberships')) {
            Schema::create('warehouse_memberships', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('role', 40)->default('operator');
                $table->json('permissions')->nullable();
                $table->string('status', 20)->default('active');
                $table->timestamp('assigned_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();

                $table->unique(['warehouse_id', 'user_id'], 'uniq_wh_membership');
                $table->index(['organization_id', 'user_id'], 'idx_wh_mem_org_user');
            });
        }

        // 4. Create warehouse_audit_logs table defensively
        if (! Schema::hasTable('warehouse_audit_logs')) {
            Schema::create('warehouse_audit_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('action', 60);
                $table->string('description');
                $table->json('before_state')->nullable();
                $table->json('after_state')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->timestamps();

                $table->index(['warehouse_id', 'created_at'], 'idx_wh_audit_created');
                $table->index(['organization_id', 'action'], 'idx_wh_audit_action');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Keep down idempotent
    }
};
