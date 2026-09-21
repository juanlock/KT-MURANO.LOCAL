<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ecommerce_clients', function (Blueprint $table) {
            $table->boolean('is_blocked')->default(false)->after('status');
            $table->timestamp('terms_accepted_at')->nullable()->after('is_blocked');
        });

        Schema::table('store_settings', function (Blueprint $table) {
            $table->boolean('require_email_verification')->default(true)->after('require_admin_approval');
        });

        // Accounts created before this feature never received a verification
        // email — treat them as verified so they are not locked out of login.
        DB::table('ecommerce_clients')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('ecommerce_clients', function (Blueprint $table) {
            $table->dropColumn(['is_blocked', 'terms_accepted_at']);
        });

        Schema::table('store_settings', function (Blueprint $table) {
            $table->dropColumn('require_email_verification');
        });
    }
};
