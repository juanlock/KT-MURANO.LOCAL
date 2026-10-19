<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PayPal module (user's rule, 2026-10-19): each company collects its invoices
 * with ITS OWN PayPal account ("Pay now" link on the invoice). A Business
 * Module that is OFF by default (ModuleFlags::DEFAULT_OFF / modules.js
 * defaultOff); on only for KcolTech (company 1) for now.
 *
 *  - paypal_settings   one row per company: switch, REST app Client ID /
 *                      Secret (secret encrypted), sandbox or live, webhook id,
 *                      the account the money goes to (accounts.id)
 *  - paypal_payments   every PayPal payment of an invoice (order -> capture ->
 *                      the sale payment it created)
 *  - permission paypal_manage (Settings -> PayPal), to roles with setting_system
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('paypal_settings')) {
            Schema::create('paypal_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->unique();
                $table->boolean('enabled')->default(false);
                $table->string('client_id', 191)->nullable();
                $table->text('client_secret')->nullable(); // encrypted (Crypt)
                $table->boolean('sandbox')->default(true);
                $table->string('webhook_id', 100)->nullable();
                $table->unsignedBigInteger('account_id')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('paypal_payments')) {
            Schema::create('paypal_payments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('sale_id')->index();
                $table->string('paypal_order_id', 64)->unique();
                $table->string('capture_id', 64)->nullable()->index();
                $table->decimal('amount', 15, 2);
                $table->string('currency', 3);
                $table->string('status', 12)->default('created'); // created | captured | failed | cancelled | refunded
                $table->string('payer_email', 190)->nullable();
                $table->string('payer_name', 190)->nullable();
                $table->unsignedBigInteger('payment_sale_id')->nullable();
                $table->timestamps();
            });
        }

        if (! DB::table('permissions')->where('name', 'paypal_manage')->exists()) {
            $text = 'PayPal: account settings and the PayPal payments list';
            $id = DB::table('permissions')->insertGetId(['name' => 'paypal_manage', 'label' => $text, 'description' => $text, 'created_at' => now(), 'updated_at' => now()]);
            $from = DB::table('permissions')->where('name', 'setting_system')->value('id');
            foreach ($from ? DB::table('permission_role')->where('permission_id', $from)->pluck('role_id')->unique() : [] as $rid) {
                DB::table('permission_role')->insert(['permission_id' => $id, 'role_id' => $rid]);
            }
        }

        // Menu name.
        if (Schema::hasTable('translations') && ! DB::table('translations')->where('key', 'PayPal_Settings')->exists()) {
            DB::table('translations')->insert(['key' => 'PayPal_Settings', 'is_default' => 1, 'en' => 'PayPal', 'es' => 'PayPal', 'created_at' => now(), 'updated_at' => now()]);
        }

        // Business Module: on for KcolTech only (others follow the default: off).
        $row = DB::table('settings')->where('company_id', 1)->whereNull('deleted_at')->first(['id', 'module_flags']);
        if ($row) {
            $flags = json_decode((string) $row->module_flags, true) ?: [];
            if (! array_key_exists('paypal', $flags)) {
                $flags['paypal'] = true;
                DB::table('settings')->where('id', $row->id)->update(['module_flags' => json_encode($flags)]);
            }
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('name', 'paypal_manage')->value('id');
        if ($id) {
            DB::table('permission_role')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
        Schema::dropIfExists('paypal_payments');
        Schema::dropIfExists('paypal_settings');
    }
};
