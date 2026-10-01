<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings -> Mail Settings -> "Send a copy to the SuperAdmin": the company's
 * admin notices (bookings, POS register open/close) always go to EVERY admin
 * user of that company; the SuperAdmin(s) get a copy only when this is on
 * for that company.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('settings', 'notify_superadmin_copy')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->boolean('notify_superadmin_copy')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('settings', 'notify_superadmin_copy')) {
            Schema::table('settings', fn (Blueprint $t) => $t->dropColumn('notify_superadmin_copy'));
        }
    }
};
