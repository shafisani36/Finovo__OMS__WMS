<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->text('credentials')->nullable()->after('bridge_url'); // encrypted JSON, never returned by the API
            $table->string('external_reference')->nullable()->after('credentials'); // shop domain, site URL, store hash, eBay account
            $table->text('connection_error')->nullable()->after('external_reference');
            $table->timestamp('connected_at')->nullable()->after('connection_error');

            $table->index(['platform', 'external_reference']);
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropIndex(['platform', 'external_reference']);
            $table->dropColumn(['credentials', 'external_reference', 'connection_error', 'connected_at']);
        });
    }
};
