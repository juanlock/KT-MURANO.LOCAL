<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brings Murano's `users` table to schema parity with Billing's last two
 * differences: the `type` column (default 'admin', 'SuperAdmin' is the
 * sentinel Billing's ScopesToCompany trait checks to let a user act across
 * companies) and the unique constraint on `username`. Defaulting every
 * existing row to 'admin' keeps current behavior identical -- there's no
 * SuperAdmin concept wired into Murano's controllers yet, this just adds
 * the column so that work can start.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'type')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('type', 20)->default('admin')->after('role_id');
            });
        }

        if (!Schema::hasIndex('users', 'users_username_unique')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unique('username', 'users_username_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_username_unique');
            $table->dropColumn('type');
        });
    }
};
