<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing parity: `employees.user_id` links an employee to their login
 * account (nullable -- not every employee has one). Murano's `employees`
 * had no such link at all, just a standalone `username` display field with
 * no tie to `users`. Adding this additively, same as clients.user_id --
 * Murano's own `username` column stays, unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('employees', 'user_id')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->integer('user_id')->nullable()->after('id');
            });
        }

        if (!Schema::hasIndex('employees', 'employees_user_id_unique')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->unique('user_id', 'employees_user_id_unique');
            });
        }

        if (!Schema::hasIndex('employees', 'employees_user_id_foreign')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign('employees_user_id_foreign');
            $table->dropUnique('employees_user_id_unique');
            $table->dropColumn('user_id');
        });
    }
};
