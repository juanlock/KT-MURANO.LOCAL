<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Integración -> Producción AuraSoft (user's request, 2026-11-23; AuraSoft = the ticket system once called Jelaf).
 * The Manager uploads the "Reporte producción por bus" Excel that AuraSoft exports (daily or on demand, by a date range,
 * so two files can repeat the same departures):
 *  - transport_aurasoft_imports: every file kept (private, files/company_{id}/transport/aurasoft/) with its period and counts;
 *  - transport_aurasoft_rows: every row of every file (what each file brought) -- a departure = plate + date + time + route;
 *    the latest file's row of a departure is the current one (is_current), earlier ones stay as history ("updated" / "same").
 *  - permission transport_aurasoft_import: IT Admin and Manager of the companies with transport;
 *  - menu: the code group "Integración" (transport_integration) and, in Turismo Cautivo's own menu, the group placed before
 *    Configuración. Safe to run twice; by name.
 */
return new class extends Migration
{
    private const PERM = 'transport_aurasoft_import';

    private const GROUP = 'transport_integration';

    private const ITEM = '/app/transport/aurasoft';

    private const TEXTS = [
        'Transport_Integration' => ['Integration', 'Integración'],
        'Transport_AuraSoft' => ['AuraSoft production', 'Producción AuraSoft'],
        'transport_aurasoft_import' => ['Transport: import AuraSoft (Jelaf) production', 'Transporte: importar la producción de AuraSoft (Jelaf)'],
        'Perm_Transport_AuraSoft' => ['Integration: upload the AuraSoft (Jelaf) production report', 'Integración: subir el reporte de producción de AuraSoft (Jelaf)'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('transport_aurasoft_imports')) {
            Schema::create('transport_aurasoft_imports', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('company_id')->index();
                $t->string('file');                       // files/company_{id}/transport/aurasoft/... (private)
                $t->string('original_name');
                $t->string('sha256', 64)->nullable();
                $t->string('company_text')->nullable();   // "EMPRESA: ..." line of the report
                $t->date('period_from')->nullable();
                $t->date('period_to')->nullable();
                $t->unsignedInteger('rows_total')->default(0);
                $t->unsignedInteger('rows_new')->default(0);
                $t->unsignedInteger('rows_updated')->default(0);
                $t->unsignedInteger('rows_same')->default(0);
                $t->unsignedInteger('rows_skipped')->default(0);
                $t->unsignedInteger('seats')->default(0);
                $t->decimal('ticket_income', 14, 2)->default(0);
                $t->decimal('production', 14, 2)->default(0);
                $t->json('columns')->nullable();          // the file's headers, in its order
                $t->json('skipped')->nullable();          // [{row, reason}]
                $t->unsignedInteger('created_by')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('transport_aurasoft_rows')) {
            Schema::create('transport_aurasoft_rows', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('company_id');
                $t->unsignedBigInteger('import_id')->index();
                $t->unsignedInteger('row_number');
                $t->string('departure_key', 80);          // PLATE|Y-m-d|H:i|ORIGIN/DEST
                $t->string('plate', 20);
                $t->date('trip_date');
                $t->time('departure_time');
                $t->string('route', 80);
                $t->string('origin', 40)->nullable();
                $t->string('destination', 40)->nullable();
                $t->unsignedInteger('seats')->default(0);           // CNT ASI.
                $t->json('channels')->nullable();                   // {AURA SOFT: n, REDBUS: n, ...}
                $t->decimal('ticket_income', 12, 2)->default(0);    // TOTAL PAX
                $t->unsignedInteger('parcels')->default(0);         // CNT ENCO
                $t->decimal('parcel_income', 12, 2)->default(0);    // TOTAL ENCO
                $t->unsignedInteger('excess')->default(0);          // CNT DE DOC.EXCESOS
                $t->decimal('excess_income', 12, 2)->default(0);    // TOTAL EXC
                $t->decimal('production', 12, 2)->default(0);       // TOTAL PRODUCCIÓN
                $t->json('values');                                  // every column of the row, by its header
                $t->string('action', 10);                            // new | updated | same
                $t->boolean('is_current')->default(true);
                $t->unsignedInteger('vehicle_id')->nullable();       // the bus with that plate in Murano, when there is one
                $t->timestamps();
                $t->index(['company_id', 'departure_key', 'is_current'], 'tar_company_key_current');
                $t->index(['company_id', 'trip_date'], 'tar_company_date');
            });
        }

        if (! DB::table('permissions')->where('name', self::PERM)->exists()) {
            DB::table('permissions')->insert(['name' => self::PERM, 'label' => self::TEXTS[self::PERM][0], 'created_at' => now(), 'updated_at' => now()]);
        }
        $pid = DB::table('permissions')->where('name', self::PERM)->value('id');
        $transportCompanies = DB::table('transport_locations')->whereNull('deleted_at')->distinct()->pluck('company_id');
        $roles = DB::table('roles')->whereIn('company_id', $transportCompanies)->whereIn('name', ['IT Admin', 'Manager'])->whereNull('deleted_at')->pluck('id');
        foreach ($roles as $rid) {
            if (! DB::table('permission_role')->where('role_id', $rid)->where('permission_id', $pid)->exists()) {
                DB::table('permission_role')->insert(['role_id' => $rid, 'permission_id' => $pid]);
            }
        }

        // Turismo Cautivo's own menu (its six custom groups): "Integración" right before Configuración.
        $now = now();
        foreach (DB::table('menu_positions')->where('item_key', 'custom_tc_pasajeros')->pluck('company_id')->unique() as $cid) {
            $rows = fn () => DB::table('menu_positions')->where('company_id', $cid);
            if (! $rows()->where('item_key', self::GROUP)->exists()) {
                $settings = $rows()->where('item_key', 'settings')->whereNull('parent_key')->value('sort_order');
                $sort = $settings !== null ? (int) $settings : (int) $rows()->whereNull('parent_key')->max('sort_order') + 1;
                $rows()->whereNull('parent_key')->where('sort_order', '>=', $sort)->increment('sort_order');
                DB::table('menu_positions')->insert([
                    'company_id' => $cid, 'item_key' => self::GROUP, 'parent_key' => null, 'sort_order' => $sort, 'label' => 'Integración',
                    'external' => 0, 'is_custom' => 0, 'hidden' => 0, 'location' => 'sidebar', 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            if (! $rows()->where('item_key', self::ITEM)->exists()) {
                DB::table('menu_positions')->insert([
                    'company_id' => $cid, 'item_key' => self::ITEM, 'parent_key' => self::GROUP, 'sort_order' => 1,
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
        DB::table('menu_positions')->whereIn('item_key', [self::ITEM, self::GROUP])->delete();
        $pid = DB::table('permissions')->where('name', self::PERM)->value('id');
        if ($pid) {
            DB::table('permission_role')->where('permission_id', $pid)->delete();
            DB::table('permissions')->where('id', $pid)->delete();
        }
        Schema::dropIfExists('transport_aurasoft_rows');
        Schema::dropIfExists('transport_aurasoft_imports');
    }
};
