<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Jelaf automático (user's request, 2026-11-26): every Jelaf report Murano imports runs by itself every night at 22:00 on the
 * server AND can be run at any moment from Integración -> Jelaf automático, with the history of every run.
 *  - transport_jelaf_runs: one run = report, dates, night / manual, status, what it brought, message;
 *  - transport_boardings: Jelaf's "Control de Embarque - Detallado", one row per ticket of a travel day: departure
 *    (programación, hour, route, plate -> bus / Murano departure), seat, passenger, seller, the passenger's own stretch, sale
 *    date-time, ticket, amount, boarded (SI / NO), manifest, boarding and alighting point. A day's import replaces that day.
 *  - menu: /app/transport/jelaf in the Integración group (permission transport_aurasoft_import). Safe to run twice.
 */
return new class extends Migration
{
    private const ITEM = '/app/transport/jelaf';

    private const TEXTS = [
        'Transport_JelafSync' => ['Jelaf automatic', 'Jelaf automático'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('transport_jelaf_runs')) {
            Schema::create('transport_jelaf_runs', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('company_id')->index();
                $t->string('report', 20);                  // production | boarding
                $t->date('date_from');
                $t->date('date_to');
                $t->string('trigger', 10)->default('night'); // night | manual
                $t->string('status', 10)->default('running'); // running | ok | nodata | failed
                $t->unsignedInteger('files')->default(0);
                $t->unsignedInteger('rows')->default(0);
                $t->json('summary')->nullable();
                $t->text('message')->nullable();
                $t->unsignedInteger('user_id')->nullable();
                $t->timestamp('started_at')->nullable();
                $t->timestamp('finished_at')->nullable();
            });
        }
        if (! Schema::hasTable('transport_boardings')) {
            Schema::create('transport_boardings', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('company_id');
                $t->date('travel_date');
                $t->unsignedInteger('programacion')->nullable();
                $t->time('departure_time')->nullable();
                $t->string('origin', 40)->nullable();
                $t->string('destination', 40)->nullable();
                $t->string('plate', 20)->nullable();
                $t->unsignedInteger('vehicle_id')->nullable();
                $t->unsignedBigInteger('trip_id')->nullable()->index();
                $t->string('seat', 10)->nullable();
                $t->string('passenger', 150)->nullable();
                $t->string('seller', 80)->nullable();
                $t->string('pax_origin', 40)->nullable();
                $t->string('pax_destination', 40)->nullable();
                $t->dateTime('sold_at')->nullable();
                $t->string('ticket', 30)->nullable();
                $t->decimal('amount', 10, 2)->default(0);
                $t->boolean('boarded')->default(true);
                $t->string('manifest', 30)->nullable();
                $t->string('boarding_point', 40)->nullable();
                $t->string('alighting_point', 40)->nullable();
                $t->timestamp('created_at')->nullable();
                $t->index(['company_id', 'travel_date']);
            });
        }

        $now = now();
        foreach (DB::table('menu_positions')->where('item_key', 'transport_integration')->pluck('company_id')->unique() as $cid) {
            if (! DB::table('menu_positions')->where('company_id', $cid)->where('item_key', self::ITEM)->exists()) {
                $sort = (int) DB::table('menu_positions')->where('company_id', $cid)->where('parent_key', 'transport_integration')->max('sort_order') + 1;
                DB::table('menu_positions')->insert([
                    'company_id' => $cid, 'item_key' => self::ITEM, 'parent_key' => 'transport_integration', 'sort_order' => $sort,
                    'external' => 0, 'is_custom' => 0, 'hidden' => 0, 'location' => 'sidebar', 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
        foreach (self::TEXTS as $key => [$en, $es]) {
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
        Schema::dropIfExists('transport_boardings');
        Schema::dropIfExists('transport_jelaf_runs');
    }
};
