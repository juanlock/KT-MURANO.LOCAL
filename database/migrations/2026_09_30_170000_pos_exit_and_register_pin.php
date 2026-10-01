<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * POS Settings additions:
 *  - users.pin: hashed personal PIN, asked when opening a cash register if
 *    the company turns on "Open register with PIN".
 *  - settings.pos_register_pin_required / pos_close_register_email: company
 *    rules (PIN to open a register; email the company admins the register
 *    summary when it is closed).
 *  - pos_settings.show_customer_filter: show/hide the POS toolbar's
 *    customer selector (hidden = sales go to the default customer).
 *  - payment_methods "Zelle" (global list, like the other methods).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'pin')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('pin')->nullable()->after('password');
            });
        }

        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'pos_register_pin_required')) {
                $table->boolean('pos_register_pin_required')->default(false)->after('pos_require_open_register');
            }
            if (! Schema::hasColumn('settings', 'pos_close_register_email')) {
                $table->boolean('pos_close_register_email')->default(true)->after('pos_register_pin_required');
            }
        });

        if (! Schema::hasColumn('pos_settings', 'show_customer_filter')) {
            Schema::table('pos_settings', function (Blueprint $table) {
                $table->tinyInteger('show_customer_filter')->default(1)->after('show_brands');
            });
        }

        if (! DB::table('payment_methods')->whereRaw('LOWER(name) = ?', ['zelle'])->exists()) {
            DB::table('payment_methods')->insert([
                'name' => 'Zelle', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'pin')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('pin'));
        }
        Schema::table('settings', function (Blueprint $table) {
            foreach (['pos_register_pin_required', 'pos_close_register_email'] as $c) {
                if (Schema::hasColumn('settings', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
        if (Schema::hasColumn('pos_settings', 'show_customer_filter')) {
            Schema::table('pos_settings', fn (Blueprint $t) => $t->dropColumn('show_customer_filter'));
        }
        // "Zelle" stays: payments may already reference it.
    }
};
