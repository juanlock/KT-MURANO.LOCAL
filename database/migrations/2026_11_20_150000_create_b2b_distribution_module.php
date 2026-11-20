<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * "Distribución B2B" (user's request 2026-11-20, Hordex): selling to retail chains and wholesalers -- remission guides
 * (guías de remisión: carrier, plate, reason of transfer, delivery address, purchase order), invoicing one or several
 * guides into a sale, the collection of each invoice in lines (bank deposit, SUNAT detracción, the chain's commercial
 * deductions: rebate, co-op advertising, crossdocking...), payment terms, and the B2B board (overdue invoices, sales of
 * the month / year by chain, rebate base). Business module `b2b`, off by default. Safe to run twice.
 */
return new class extends Migration
{
    private const PERMS = [
        'b2b_view' => ['B2B distribution: board', 'Distribución B2B: tablero'],
        'b2b_guides' => ['B2B distribution: remission guides', 'Distribución B2B: guías de remisión'],
        'b2b_invoice' => ['B2B distribution: invoice guides', 'Distribución B2B: facturar guías'],
        'b2b_collections' => ['B2B distribution: collections (deposits, detracciones, deductions)', 'Distribución B2B: cobranza (abonos, detracciones, deducciones)'],
        'b2b_setup' => ['B2B distribution: setup (carriers, reasons, concepts, payment terms)', 'Distribución B2B: configuración (transportistas, motivos, conceptos, condiciones de pago)'],
    ];

    private const TEXTS = [
        'B2B' => ['B2B distribution', 'Distribución B2B'],
        'B2B_Board' => ['Home', 'Inicio'],
        'B2B_Menu_Guides' => ['Remission guides', 'Guías de remisión'],
        'B2B_Menu_Collections' => ['Collections', 'Cobranza'],
        'B2B_Kardex' => ['Kardex', 'Kardex'],
        'B2B_Reports' => ['Reports', 'Reportes'],
        'B2B_Menu_Setup' => ['B2B setup', 'Configuración B2B'],
    ];

    /** SUNAT catalogue 20 (motivos de traslado). */
    private const REASONS = [
        ['01', 'Venta', 'out'], ['14', 'Venta sujeta a confirmación del comprador', 'out'], ['03', 'Venta con entrega a terceros', 'out'],
        ['02', 'Compra', 'in'], ['04', 'Traslado entre establecimientos de la misma empresa', 'out'], ['05', 'Consignación', 'out'],
        ['06', 'Devolución', 'in'], ['07', 'Recojo de bienes transformados', 'in'], ['17', 'Traslado de bienes para transformación', 'out'],
        ['08', 'Importación', 'in'], ['09', 'Exportación', 'out'], ['18', 'Traslado emisor itinerante de comprobantes de pago', 'out'],
        ['13', 'Otros', 'out'],
    ];

    public function up(): void
    {
        $this->tables();

        foreach (self::PERMS as $name => [$en]) {
            if (! DB::table('permissions')->where('name', $name)->exists()) {
                DB::table('permissions')->insert(['name' => $name, 'label' => $en, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        // IT Admin: every one; Manager: every one but the setup.
        $ids = DB::table('permissions')->whereIn('name', array_keys(self::PERMS))->pluck('id', 'name');
        foreach (DB::table('roles')->whereIn('name', ['IT Admin', 'Manager'])->whereNull('deleted_at')->get(['id', 'name']) as $role) {
            foreach ($ids as $name => $pid) {
                if ($role->name === 'Manager' && $name === 'b2b_setup') {
                    continue;
                }
                if (! DB::table('permission_role')->where('role_id', $role->id)->where('permission_id', $pid)->exists()) {
                    DB::table('permission_role')->insert(['role_id' => $role->id, 'permission_id' => $pid]);
                }
            }
        }
        foreach (self::PERMS + self::TEXTS as $key => [$en, $es]) {
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

        // SUNAT's reasons of transfer, install-wide (company_id null): every B2B company reads them.
        foreach (self::REASONS as [$code, $name, $dir]) {
            if (! DB::table('b2b_transfer_reasons')->whereNull('company_id')->where('sunat_code', $code)->exists()) {
                DB::table('b2b_transfer_reasons')->insert(['company_id' => null, 'sunat_code' => $code, 'name' => $name, 'direction' => $dir,
                    'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    private function tables(): void
    {
        if (! Schema::hasColumn('settings', 'b2b_settings')) {
            // {invoice_series, guide_series, igv, warehouse_id} of the company's B2B module.
            Schema::table('settings', fn (Blueprint $t) => $t->json('b2b_settings')->nullable());
        }
        if (! Schema::hasTable('b2b_carriers')) {
            Schema::create('b2b_carriers', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->string('ruc', 15)->nullable();
                $t->string('name', 190);
                $t->string('driver_name', 150)->nullable();
                $t->string('license', 30)->nullable();
                $t->string('plate', 15)->nullable();
                $t->string('brand', 60)->nullable();
                $t->string('model', 60)->nullable();
                $t->boolean('active')->default(true);
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('b2b_transfer_reasons')) {
            Schema::create('b2b_transfer_reasons', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->nullable()->index();   // null = SUNAT's, for every company
                $t->string('sunat_code', 4)->nullable();
                $t->string('name', 150);
                $t->string('direction', 4)->default('out');                 // out = dispatch, in = reception
                $t->boolean('active')->default(true);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('b2b_client_addresses')) {
            Schema::create('b2b_client_addresses', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->unsignedBigInteger('client_id')->index();
                $t->string('alias', 120)->nullable();                       // the chain's store / warehouse name
                $t->string('address', 255);
                $t->string('ubigeo', 6)->nullable();
                $t->string('district', 100)->nullable();
                $t->string('province', 100)->nullable();
                $t->string('department', 100)->nullable();
                $t->string('sunat_annex', 10)->nullable();                  // código de establecimiento anexo
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('b2b_payment_terms')) {
            Schema::create('b2b_payment_terms', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->string('code', 20)->nullable();
                $t->string('name', 120);
                $t->unsignedSmallInteger('days')->default(0);
                $t->boolean('active')->default(true);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('b2b_concepts')) {
            // What settles an invoice besides the bank deposit: SUNAT's detracción and each chain's commercial deductions.
            Schema::create('b2b_concepts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->unsignedBigInteger('client_id')->nullable()->index();   // null = every client
                $t->string('name', 150);
                $t->string('kind', 12)->default('deduction');               // deposit / detraction / rebate / deduction
                $t->decimal('percent', 6, 2)->default(0);
                $t->boolean('active')->default(true);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('b2b_guides')) {
            Schema::create('b2b_guides', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->string('series', 8);
                $t->unsignedInteger('number');
                $t->date('issue_date');
                $t->date('transfer_date')->nullable();
                $t->unsignedBigInteger('client_id')->nullable()->index();
                $t->unsignedBigInteger('address_id')->nullable();
                $t->string('delivery_address', 255)->nullable();
                $t->unsignedBigInteger('reason_id')->nullable();
                $t->unsignedBigInteger('carrier_id')->nullable();
                $t->string('plate', 15)->nullable();
                $t->string('driver_license', 30)->nullable();
                $t->string('brand', 60)->nullable();
                $t->string('model', 60)->nullable();
                $t->string('purchase_order', 60)->nullable();
                $t->unsignedBigInteger('warehouse_id')->nullable();
                $t->unsignedBigInteger('sale_id')->nullable()->index();     // the invoice it went into
                $t->string('status', 12)->default('dispatched');            // dispatched / invoiced / cancelled
                $t->boolean('stock_moved')->default(false);                 // the goods left the warehouse with the guide
                $t->text('notes')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->unique(['company_id', 'series', 'number']);
            });
        }
        if (! Schema::hasTable('b2b_guide_items')) {
            Schema::create('b2b_guide_items', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('guide_id')->index();
                $t->unsignedBigInteger('product_id')->nullable();
                $t->string('description', 255)->nullable();
                $t->decimal('quantity', 14, 3);
                $t->string('unit', 10)->default('UND');
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('b2b_sale_info')) {
            // What a B2B invoice carries besides the sale: its SUNAT series / number, purchase order, payment term, due date.
            Schema::create('b2b_sale_info', function (Blueprint $t) {
                $t->unsignedBigInteger('sale_id')->primary();
                $t->unsignedBigInteger('company_id')->index();
                $t->string('series', 8)->nullable();
                $t->unsignedInteger('number')->nullable();
                $t->string('purchase_order', 60)->nullable();
                $t->unsignedBigInteger('payment_term_id')->nullable();
                $t->date('due_date')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('b2b_payment_info')) {
            // Each collection line (payment_sales row): which concept, the bank / SUNAT document and its date.
            Schema::create('b2b_payment_info', function (Blueprint $t) {
                $t->unsignedBigInteger('payment_sale_id')->primary();
                $t->unsignedBigInteger('company_id')->index();
                $t->unsignedBigInteger('concept_id')->nullable();
                $t->string('kind', 12)->default('deposit');
                $t->string('document', 60)->nullable();
                $t->date('document_date')->nullable();
                $t->decimal('percent', 6, 2)->nullable();
                $t->decimal('expected', 14, 2)->nullable();                 // "Monto" of the earlier system (the amount due by the concept)
                $t->timestamps();
            });
        }
        if (! Schema::hasColumn('b2b_payment_info', 'expected')) {
            Schema::table('b2b_payment_info', fn (Blueprint $t) => $t->decimal('expected', 14, 2)->nullable()->after('percent'));
        }
        if (! Schema::hasTable('b2b_kardex')) {
            // The stock card history brought from the earlier system (read only); new movements are Murano's own.
            Schema::create('b2b_kardex', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->unsignedBigInteger('product_id')->index();
                $t->dateTime('moved_at');
                $t->string('direction', 8);                                  // entrada / salida
                $t->decimal('quantity', 14, 3);
                $t->decimal('price', 14, 4)->nullable();
                $t->decimal('balance', 14, 3)->nullable();
                $t->string('reference', 120)->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('b2b_legacy_map')) {
            // Earlier system id -> Murano id, so the import can run again without duplicating anything.
            Schema::create('b2b_legacy_map', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('source', 40);                                    // e.g. hordex.facturas
                $t->unsignedBigInteger('legacy_id');
                $t->string('target', 40);
                $t->unsignedBigInteger('target_id');
                $t->unique(['company_id', 'source', 'legacy_id']);
            });
        }
    }

    public function down(): void
    {
        foreach (['b2b_legacy_map', 'b2b_kardex', 'b2b_payment_info', 'b2b_sale_info', 'b2b_guide_items', 'b2b_guides', 'b2b_concepts',
            'b2b_payment_terms', 'b2b_client_addresses', 'b2b_transfer_reasons', 'b2b_carriers'] as $t) {
            Schema::dropIfExists($t);
        }
        $ids = DB::table('permissions')->whereIn('name', array_keys(self::PERMS))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        DB::table('menu_positions')->where('item_key', 'b2b')->delete();
    }
};
