<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing parity: `clients.user_id` links a client to its customer-portal
 * login (the `users` row with role_id=5 "Customer") -- the piece that was
 * missing to make sense of the 212 Customer-role users already synced in via
 * sync_users_remaining_from_billing.sql. `notes` is a plain data-parity gap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (!Schema::hasColumn('clients', 'user_id')) {
                $table->integer('user_id')->nullable()->after('company_id');
            }
            if (!Schema::hasColumn('clients', 'notes')) {
                $table->string('notes', 192)->nullable()->after('adresse');
            }
        });

        if (!Schema::hasIndex('clients', 'clients_user_id_unique')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->unique('user_id', 'clients_user_id_unique');
            });
        }

        if (!Schema::hasIndex('clients', 'clients_user_id_foreign')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropForeign('clients_user_id_foreign');
            $table->dropUnique('clients_user_id_unique');
            $table->dropColumn(['user_id', 'notes']);
        });
    }
};
