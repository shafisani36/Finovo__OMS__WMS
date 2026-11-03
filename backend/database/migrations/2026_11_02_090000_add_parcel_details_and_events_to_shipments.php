<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The parcel as handed to the courier (weight, pieces, cash to collect), when it came back, and a timeline of
 * what happened to each shipment (dispatched, courier updates, delivered, returned, dispatch cancelled).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->decimal('weight_kg', 8, 2)->nullable()->after('carrier');
            $table->unsignedSmallInteger('pieces')->default(1)->after('weight_kg');
            $table->decimal('cod_amount', 12, 2)->nullable()->after('pieces');
            $table->timestamp('returned_at')->nullable()->after('delivered_at');
        });

        Schema::create('shipment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            // dispatched, courier, delivered, returned, dispatch_cancelled
            $table->string('type', 30);
            $table->string('courier_status', 30)->nullable();
            $table->string('description');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('happened_at');
            $table->timestamps();

            $table->index(['shipment_id', 'happened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_events');

        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn(['weight_kg', 'pieces', 'cod_amount', 'returned_at']);
        });
    }
};
