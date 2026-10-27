<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 3PL billing: the brands whose stock the company keeps and ships (clients), each with its own rate card,
 * their stores, and the invoices for the warehouse work done for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 30);
            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->text('billing_address')->nullable();
            $table->string('currency', 3)->default('PKR');
            $table->unsignedSmallInteger('payment_terms_days')->default(15);
            // Rate card
            $table->decimal('storage_per_unit_day', 12, 4)->default(0);
            $table->decimal('receiving_per_unit', 12, 4)->default(0);
            $table->decimal('order_fee', 12, 4)->default(0);
            $table->decimal('additional_unit_fee', 12, 4)->default(0);
            $table->decimal('return_fee', 12, 4)->default(0);
            $table->decimal('monthly_minimum', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('organization_id')->constrained()->nullOnDelete();
        });

        Schema::create('client_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('invoice_number', 30);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 20)->default('draft'); // draft, issued, paid, void
            $table->string('currency', 3);
            $table->decimal('total', 14, 2)->default(0);
            $table->date('due_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'invoice_number']);
            $table->index(['client_id', 'period_start', 'period_end']);
        });

        Schema::create('client_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_invoice_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30); // storage, receiving, orders, additional_units, returns, minimum, manual
            $table->string('description');
            $table->decimal('quantity', 14, 2);
            $table->decimal('rate', 12, 4);
            $table->decimal('amount', 14, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_invoice_lines');
        Schema::dropIfExists('client_invoices');
        Schema::table('stores', fn (Blueprint $table) => $table->dropConstrainedForeignId('client_id'));
        Schema::dropIfExists('clients');
    }
};
