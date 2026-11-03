<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The role column was created as a MySQL ENUM of admin and operator only, so saving a manager, sales or client
     * user failed on MySQL ("Data truncated for column 'role'"). It becomes a plain string; the allowed roles are
     * checked by the UserRole enum in the application.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('operator')->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'operator'])->default('operator')->change();
        });
    }
};
