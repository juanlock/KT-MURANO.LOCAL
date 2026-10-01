<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// System Settings -> Defaults -> POS -> "Require an open register": while on
// (default), the POS is locked until the cashier opens a cash register, and
// PosController::CreatePOS refuses a sale without one (offline-queued sales
// excepted). Per company (settings row).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'pos_require_open_register')) {
                $table->boolean('pos_require_open_register')->default(true);
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (Schema::hasColumn('settings', 'pos_require_open_register')) {
                $table->dropColumn('pos_require_open_register');
            }
        });
    }
};
