<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Courier accounts (Leopards, PostEx, TCS, Trax) a company books parcels with, and the courier side of each
 * shipment: the account it was booked with, its label and the latest status the courier reported.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('carrier', 30); // leopards, postex, tcs, trax
            $table->string('name', 100);
            $table->text('credentials'); // encrypted
            $table->json('settings')->nullable();
            $table->boolean('sandbox')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('last_error')->nullable();
            $table->timestamp('last_tracked_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'is_active']);
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->foreignId('courier_account_id')->nullable()->after('carrier')->constrained()->nullOnDelete();
            $table->string('label_url', 500)->nullable()->after('tracking_url');
            $table->string('courier_status', 30)->nullable()->after('status');
            $table->string('courier_status_text')->nullable()->after('courier_status');
            $table->timestamp('courier_status_at')->nullable()->after('courier_status_text');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('courier_account_id');
            $table->dropColumn(['label_url', 'courier_status', 'courier_status_text', 'courier_status_at']);
        });
        Schema::dropIfExists('courier_accounts');
    }
};
