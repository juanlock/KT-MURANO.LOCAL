<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Passengers in their own table (user's rule, 2026-11-24: "creamos una tabla pasajeros para que podamos usarla solo para
 * turismo.. va a crecer.. no quiero ver lentitud en las otras compañías"), replacing the customers of type Pasajero made
 * earlier the same day:
 *  - transport_passengers: one row per person / company that bought through AuraSoft -- DNI / RUC (from the Liquidación
 *    Detallada), name, phone (from AuraSoft's RedBus / web sales PDF, matched by name), first / last purchase, purchases,
 *    amount, parcels; client_id only if the passenger ever becomes a Murano customer.
 *  - the clients of type 'passenger' (25,953 at Turismo, no sales, no login) are moved here and deleted from clients;
 *    one that has a sale or a login stays a customer.
 *  - menu: "Base de pasajeros" (/app/transport/passengers, permission transport_profit) in the Integración group and, in
 *    Turismo's own menu, under Pasajeros. Safe to run twice.
 */
return new class extends Migration
{
    private const ITEM = '/app/transport/passengers';

    public function up(): void
    {
        if (! Schema::hasTable('transport_passengers')) {
            Schema::create('transport_passengers', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('company_id');
                $t->string('doc', 20)->nullable();              // DNI (8) / RUC (11); null = known only by name + phone
                $t->string('name', 190);
                $t->string('name_key', 190);                    // words without accents, sorted: to match the PDFs by name
                $t->boolean('is_company')->default(false);
                $t->string('phone', 20)->nullable();            // the latest one
                $t->json('phones')->nullable();                 // every one seen
                $t->date('first_purchase')->nullable();
                $t->date('last_purchase')->nullable();
                $t->unsignedInteger('purchases')->default(0);
                $t->unsignedInteger('parcels')->default(0);
                $t->decimal('amount', 12, 2)->default(0);
                $t->unsignedInteger('online_tickets')->default(0); // from the RedBus / web PDFs
                $t->date('last_online')->nullable();
                $t->unsignedBigInteger('client_id')->nullable();
                $t->timestamps();
                $t->unique(['company_id', 'doc'], 'tp_company_doc');
                $t->index(['company_id', 'name_key'], 'tp_company_name');
                $t->index(['company_id', 'phone'], 'tp_company_phone');
                $t->index(['company_id', 'last_purchase'], 'tp_company_last');
            });
        }

        // The customers of type Pasajero made earlier today move here (only those without sales or a login).
        if (Schema::hasColumn('clients', 'client_type')) {
            $now = now();
            DB::table('clients')->where('client_type', 'passenger')->whereNull('user_id')->whereNull('deleted_at')
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('sales')->whereColumn('sales.client_id', 'clients.id'))
                ->orderBy('id')->select(['id', 'company_id', 'name', 'tax_number'])
                ->chunkById(2000, function ($rows) use ($now) {
                    $ins = [];
                    foreach ($rows as $c) {
                        if (! $c->tax_number) {
                            continue;
                        }
                        $ins[] = ['company_id' => $c->company_id, 'doc' => $c->tax_number, 'name' => $c->name, 'name_key' => \App\Support\AuraSoftPassengers::nameKey($c->name),
                            'is_company' => strlen($c->tax_number) === 11, 'created_at' => $now, 'updated_at' => $now];
                    }
                    DB::table('transport_passengers')->insertOrIgnore($ins);
                    DB::table('clients')->whereIn('id', $rows->pluck('id'))->delete();
                });
        }

        // Menu: the code group "Integración" gets the item; Turismo's own menu puts it under its "Pasajeros" group.
        $now = now();
        foreach (DB::table('menu_positions')->where('item_key', 'custom_tc_pasajeros')->pluck('company_id')->unique() as $cid) {
            if (! DB::table('menu_positions')->where('company_id', $cid)->where('item_key', self::ITEM)->exists()) {
                $sort = (int) DB::table('menu_positions')->where('company_id', $cid)->where('parent_key', 'custom_tc_pasajeros')->max('sort_order') + 1;
                DB::table('menu_positions')->insert([
                    'company_id' => $cid, 'item_key' => self::ITEM, 'parent_key' => 'custom_tc_pasajeros', 'sort_order' => $sort,
                    'external' => 0, 'is_custom' => 0, 'hidden' => 0, 'location' => 'sidebar', 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
        foreach (['Transport_Passengers' => ['Passenger base', 'Base de pasajeros']] as $key => [$en, $es]) {
            TranslationKeys::ensure($key, ['es' => $es]);
            foreach (['en' => $en, 'es' => $es] as $col => $text) {
                DB::table('translations')->whereRaw('`key` = ? COLLATE utf8mb4_bin', [$key])
                    ->where(fn ($q) => $q->whereNull($col)->orWhere($col, '')->orWhere($col, $key))
                    ->update([$col => $text, 'updated_at' => now()]);
            }
        }
        if (function_exists('forget_db_translations')) {
            forget_db_translations();
        }
    }

    public function down(): void
    {
        DB::table('menu_positions')->where('item_key', self::ITEM)->delete();
        Schema::dropIfExists('transport_passengers');
    }
};
