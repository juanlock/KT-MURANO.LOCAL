<?php

namespace App\Console\Commands;

use App\Support\B2b;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Brings Hordex's earlier system (Laravel app, database c2111853_hordex on this MySQL server) into a Murano company with the
 * B2B module (user's request, 2026-11-20). Repeatable: every earlier id is recorded in b2b_legacy_map, so a second run only
 * adds what is new and updates what changed; --fresh removes everything this import made for the company first.
 *
 *   php artisan b2b:import-hordex --company=1027 [--source=c2111853_hordex] [--fresh] [--dry]
 *
 * What goes where:
 *   medidas -> units · tipo de producto -> categories · clientes (+ contactos) -> clients · direcciones -> b2b_client_addresses
 *   proveedores -> providers · productos -> products (+ stock = last kardex balance) · pago_condiciones -> b2b_payment_terms
 *   comisiones -> b2b_concepts · transportistas -> b2b_carriers · guia_motivos -> SUNAT reasons (by code)
 *   facturas + factura_detalles -> sales + sale_details + b2b_sale_info · pagos -> payment_sales + b2b_payment_info
 *   guias + guia_detalles -> b2b_guides + b2b_guide_items (history: stock not moved again)
 *   facturas_proveedores + detalles -> purchases + purchase_details · kardex -> b2b_kardex (history)
 */
class B2bImportHordex extends Command
{
    // --closed-before: a dispatched guide older than this date that no invoice points to is history (the earlier system did not
    // link its guides to the invoices before 2025): it is imported as "closed", not as one still to invoice.
    protected $signature = 'b2b:import-hordex {--company=} {--source=c2111853_hordex} {--closed-before=2025-01-01} {--fresh} {--dry}';

    protected $description = 'Import Hordex\'s earlier system into a Murano company (Distribución B2B)';

    private int $cid;

    private $src;

    private array $map = [];

    private int $userId;

    private int $warehouseId;

    public function handle(): int
    {
        $this->cid = (int) $this->option('company');
        if (! $this->cid || ! DB::table('companies')->where('id', $this->cid)->exists()) {
            $this->error('--company=ID of an existing company');

            return self::FAILURE;
        }
        config(['database.connections.hordex' => array_merge(config('database.connections.'.config('database.default')), ['database' => $this->option('source')])]);
        $this->src = DB::connection('hordex');
        $this->userId = (int) DB::table('users')->where('company_id', $this->cid)->where('type', 'admin')->orderBy('id')->value('id');
        $this->warehouseId = (int) (B2b::warehouse($this->cid) ?: DB::table('warehouses')->where('company_id', $this->cid)->value('id'));

        if ($this->option('fresh') && ! $this->option('dry')) {
            $this->fresh();
        }
        foreach (DB::table('b2b_legacy_map')->where('company_id', $this->cid)->get() as $m) {
            $this->map[$m->source][$m->legacy_id] = (int) $m->target_id;
        }
        if ($this->option('dry')) {
            foreach (['clientes', 'proveedores', 'productos', 'facturas', 'pagos', 'guias', 'facturas_proveedores', 'ingresos', 'kardex'] as $t) {
                $this->line(str_pad($t, 22).$this->src->table($t)->count().' rows, already imported '.count($this->map["hordex.$t"] ?? []));
            }

            return self::SUCCESS;
        }

        $t0 = microtime(true);
        DB::transaction(function () {
            $this->units();
            $this->categories();
            $this->clients();
            $this->providers();
            $this->products();
            $this->setupTables();
            $this->sales();
            $this->payments();
            $this->guides();
            $this->purchases();
            $this->receipts();
            $this->kardex();
            $this->counters();
        });
        $this->info('Done in '.round(microtime(true) - $t0, 1).' s.');

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------ helpers

    private function fix($s)
    {
        if (! is_string($s)) {
            return $s;
        }
        $s = trim($s);
        if (preg_match('/Ã.|Â./u', $s)) {
            $d = mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
            if (mb_check_encoding($d, 'UTF-8')) {
                $s = $d;
            }
        }

        return $s === '' ? null : $s;
    }

    private function mapped(string $src, $legacyId): ?int
    {
        return $legacyId === null ? null : ($this->map[$src][$legacyId] ?? null);
    }

    private function remember(string $src, int $legacyId, string $target, int $targetId): void
    {
        DB::table('b2b_legacy_map')->updateOrInsert(['company_id' => $this->cid, 'source' => $src, 'legacy_id' => $legacyId], ['target' => $target, 'target_id' => $targetId]);
        $this->map[$src][$legacyId] = $targetId;
    }

    /** Insert or update the Murano row of an earlier row. */
    private function upsert(string $src, int $legacyId, string $table, array $values): int
    {
        $id = $this->mapped($src, $legacyId);
        if ($id && DB::table($table)->where('id', $id)->exists()) {
            DB::table($table)->where('id', $id)->update($values + ['updated_at' => now()]);

            return $id;
        }
        $id = DB::table($table)->insertGetId($values + ['created_at' => $values['created_at'] ?? now(), 'updated_at' => now()]);
        $this->remember($src, $legacyId, $table, $id);

        return $id;
    }

    private function fresh(): void
    {
        $maps = DB::table('b2b_legacy_map')->where('company_id', $this->cid)->get()->groupBy('target');
        DB::transaction(function () use ($maps) {
            $saleIds = ($maps['sales'] ?? collect())->pluck('target_id');
            DB::table('payment_sales')->whereIn('sale_id', $saleIds)->delete();
            DB::table('sale_details')->whereIn('sale_id', $saleIds)->delete();
            DB::table('b2b_sale_info')->whereIn('sale_id', $saleIds)->delete();
            $purchaseIds = ($maps['purchases'] ?? collect())->pluck('target_id');
            DB::table('purchase_details')->whereIn('purchase_id', $purchaseIds)->delete();
            $guideIds = ($maps['b2b_guides'] ?? collect())->pluck('target_id');
            DB::table('b2b_guide_items')->whereIn('guide_id', $guideIds)->delete();
            DB::table('b2b_receipt_items')->whereIn('receipt_id', ($maps['b2b_receipts'] ?? collect())->pluck('target_id'))->delete();
            foreach ($maps as $table => $rows) {
                DB::table($table)->whereIn('id', $rows->pluck('target_id'))->delete();
            }
            DB::table('b2b_payment_info')->where('company_id', $this->cid)->whereNotIn('payment_sale_id', DB::table('payment_sales')->select('id'))->delete();
            DB::table('b2b_kardex')->where('company_id', $this->cid)->delete();
            DB::table('b2b_legacy_map')->where('company_id', $this->cid)->delete();
        });
        $this->map = [];
        $this->warn('Previous import removed.');
    }

    // ------------------------------------------------------------------ steps

    private function units(): void
    {
        $base = null;
        foreach ($this->src->table('medidas')->orderBy('unidades')->get() as $m) {
            $id = $this->upsert('hordex.medidas', $m->id, 'units', [
                'company_id' => $this->cid, 'name' => ucfirst((string) $m->nombre), 'ShortName' => $m->simbolo,
                'base_unit' => (int) $m->unidades === 1 ? null : $base, 'operator' => '*', 'operator_value' => max(1, (int) $m->unidades), 'is_active' => 1,
            ]);
            if ((int) $m->unidades === 1) {
                $base = $id;
            }
        }
        $this->line('units: '.count($this->map['hordex.medidas'] ?? []));
    }

    private function categories(): void
    {
        $names = ['final' => 'Producto terminado', 'insumo' => 'Insumos', 'embalaje' => 'Embalaje', 'servicio' => 'Servicios', 'out' => 'Otros'];
        foreach ($names as $tipo => $name) {
            $id = DB::table('categories')->where('company_id', $this->cid)->where('name', $name)->whereNull('deleted_at')->value('id');
            $this->map['cat'][$tipo] = (int) ($id ?: DB::table('categories')->insertGetId(['company_id' => $this->cid, 'code' => strtoupper(substr($tipo, 0, 3)),
                'name' => $name, 'created_at' => now(), 'updated_at' => now()]));
        }
    }

    private function clients(): void
    {
        $code = (int) DB::table('clients')->max('code');
        $contacts = $this->src->table('contactos')->orderBy('id')->get()->groupBy('cliente_id');
        foreach ($this->src->table('clientes')->orderBy('id')->get() as $c) {
            $k = $contacts[$c->id][0] ?? null;
            $id = $this->mapped('hordex.clientes', $c->id);
            $phones = implode(' / ', array_filter([$c->telefono1, $c->telefono2, $c->telefono3]));
            $this->upsert('hordex.clientes', $c->id, 'clients', [
                'company_id' => $this->cid, 'name' => $this->fix($c->razon_social), 'short_name' => $this->fix($c->nombre_comercial),
                'tax_number' => $c->ruc, 'adresse' => $this->fix($c->direccion),
                'phone' => $phones ?: null, 'email' => $c->email ?: null, 'country' => 'Peru',
                'contact_name' => $k ? $this->fix(trim($k->nombre.' '.$k->paterno.' '.$k->materno)) : $this->fix($c->nombre_comercial),
                'contact_phone' => $k->telefono1 ?? null, 'contact_email' => $k->email ?? null,
                'notes' => trim(($c->nombre_comercial ? 'Nombre comercial: '.$this->fix($c->nombre_comercial) : '').($c->agente_retencion ? "\nAgente de retención" : '')) ?: null,
                'deleted_at' => $c->deleted_at ?? null,
            ] + ($id ? [] : ['code' => ++$code, 'created_at' => $c->created_at]));
        }
        // Every contact of each client (contactos), not only the first (2026-11-21).
        foreach ($this->src->table('contactos')->orderBy('id')->get() as $k) {
            $cl = $this->mapped('hordex.clientes', $k->cliente_id);
            if (! $cl) {
                continue;
            }
            $this->upsert('hordex.contactos', $k->id, 'b2b_client_contacts', [
                'company_id' => $this->cid, 'client_id' => $cl,
                'name' => $this->fix(trim(implode(' ', array_filter([$k->nombre, $k->mas_nombres, $k->paterno, $k->materno])))) ?: '-',
                'area' => $this->fix($k->area), 'phone' => implode(' / ', array_filter([$k->telefono1, $k->telefono2, $k->telefono3])) ?: null,
                'email' => $k->email ?: null,
            ]);
        }
        foreach ($this->src->table('direcciones')->orderBy('id')->get() as $d) {
            $u = $this->src->table('ubigeos')->where('id', $d->ubigeo_id)->first();
            $cl = $this->mapped('hordex.clientes', $d->cliente_id);
            if (! $cl) {
                continue;
            }
            $this->upsert('hordex.direcciones', $d->id, 'b2b_client_addresses', [
                'company_id' => $this->cid, 'client_id' => $cl, 'alias' => $this->fix($d->alias), 'address' => $this->fix($d->direccion) ?: '-',
                'ubigeo' => $u && $u->id !== '000000' ? $u->id : null, 'district' => $u->distrito ?? null, 'province' => $u->provincia ?? null,
                'department' => $u->departamento ?? null, 'sunat_annex' => $d->anexo_sunat,
            ]);
        }
        $this->line('clients: '.count($this->map['hordex.clientes'] ?? []).', addresses: '.count($this->map['hordex.direcciones'] ?? []));
    }

    private function providers(): void
    {
        $code = (int) DB::table('providers')->max('code');
        foreach ($this->src->table('proveedores')->orderBy('id')->get() as $p) {
            $id = $this->mapped('hordex.proveedores', $p->id);
            // Its card (2026-11-21): trade name, location from the ubigeo (city = "district - province - department", zip = the
            // code), the 3 contacts and 3 phones joined with " / " like the clients' phones.
            $u = $p->ubigeo_id ? $this->src->table('ubigeos')->where('id', str_pad((string) $p->ubigeo_id, 6, '0', STR_PAD_LEFT))->first() : null;
            $this->upsert('hordex.proveedores', $p->id, 'providers', [
                'company_id' => $this->cid, 'name' => $this->fix($p->razon_social), 'short_name' => $this->fix($p->nombre_comercial),
                'tax_number' => $p->ruc, 'adresse' => $this->fix($p->direccion),
                'city' => $u ? implode(' - ', array_filter([$u->distrito, $u->provincia, $u->departamento])) : null, 'zip' => $u->id ?? null,
                'phone' => implode(' / ', array_filter([$p->telefono1, $p->telefono2, $p->telefono3])) ?: null, 'email' => $p->email ?: null,
                'contact_name' => implode(' / ', array_filter(array_map(fn ($c) => $this->fix($c), [$p->contacto1, $p->contacto2, $p->contacto3]))) ?: null,
                'country' => 'Peru', 'deleted_at' => $p->deleted_at ?? null,
            ] + ($id ? [] : ['code' => ++$code, 'created_at' => $p->created_at]));
        }
        $this->line('providers: '.count($this->map['hordex.proveedores'] ?? []));
    }

    private function products(): void
    {
        $und = $this->mapped('hordex.medidas', 1);
        foreach ($this->src->table('productos')->orderBy('id')->get() as $p) {
            $unit = $this->mapped('hordex.medidas', $p->um) ?: $und;
            $service = $p->tipo === 'servicio';
            $id = $this->upsert('hordex.productos', $p->id, 'products', [
                'company_id' => $this->cid, 'type' => $service ? 'is_service' : 'is_single', 'code' => $p->sku ?: 'HX'.$p->id, 'Type_barcode' => 'CODE128',
                'name' => $this->fix($p->nombre), 'cost' => 0, 'price' => (float) $p->precio, 'category_id' => $this->map['cat'][$p->tipo] ?? $this->map['cat']['out'],
                'unit_id' => $unit, 'unit_sale_id' => $unit, 'unit_purchase_id' => $unit, 'TaxNet' => 0, 'tax_method' => '2', 'note' => $this->fix($p->detalle),
                'pack_size' => (int) ($p->paso ?? 0) ?: null,   // "Empaque" (2026-11-21)
                'is_active' => (int) ($p->estado ?? 1) ? 1 : 0, 'not_selling' => in_array($p->tipo, ['insumo', 'embalaje'], true) ? 1 : 0, 'stock_alert' => 0,
                'deleted_at' => $p->deleted_at,
            ]);
            if (! $service) {
                // The stock its screen shows: Producto::saldo() = the last kardex line by created_at, then id (2026-11-21: by
                // f_movimiento, BOLSA TRANSPARENTE GRANDE POR 100U came in as 1,000 where Hordex shows 1,005).
                $balance = $this->src->table('kardex')->where('producto_id', $p->id)->whereNull('deleted_at')->orderByDesc('created_at')->orderByDesc('id')->value('saldo');
                DB::table('product_warehouse')->updateOrInsert(['product_id' => $id, 'warehouse_id' => $this->warehouseId, 'product_variant_id' => null],
                    ['qte' => (float) ($balance ?? 0), 'manage_stock' => 1, 'updated_at' => now(), 'created_at' => now()]);
            }
        }
        $this->line('products: '.count($this->map['hordex.productos'] ?? []));
    }

    private function setupTables(): void
    {
        // A finished product's recipe ("Insumos necesarios", producto_insumos), 2026-11-21.
        DB::table('b2b_product_inputs')->where('company_id', $this->cid)->delete();
        foreach ($this->src->table('producto_insumos')->orderBy('id')->get() as $r) {
            $prod = $this->mapped('hordex.productos', $r->producto_id);
            $input = $this->mapped('hordex.productos', $r->insumo_id);
            if ($prod && $input) {
                DB::table('b2b_product_inputs')->insert(['company_id' => $this->cid, 'product_id' => $prod, 'input_id' => $input,
                    'quantity' => (float) $r->cantidad, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        foreach ($this->src->table('pago_condiciones')->orderBy('id')->get() as $t) {
            $this->upsert('hordex.pago_condiciones', $t->id, 'b2b_payment_terms', ['company_id' => $this->cid, 'code' => $t->abrev,
                'name' => $this->fix($t->nombre), 'days' => (int) $t->dias, 'active' => 1]);
        }
        // Each client's payment condition and withholding agent flag (its card, 2026-11-21) -- after the terms exist.
        foreach ($this->src->table('clientes')->orderBy('id')->get(['id', 'pago_condicion_id', 'agente_retencion']) as $c) {
            $client = $this->mapped('hordex.clientes', $c->id);
            if (! $client) {
                continue;
            }
            DB::table('b2b_client_info')->updateOrInsert(['client_id' => $client], [
                'company_id' => $this->cid, 'payment_term_id' => $c->pago_condicion_id ? $this->mapped('hordex.pago_condiciones', $c->pago_condicion_id) : null,
                'retention_agent' => (int) $c->agente_retencion ? 1 : 0, 'updated_at' => now(), 'created_at' => now(),
            ]);
        }
        foreach ($this->src->table('comisiones')->orderBy('id')->get() as $k) {
            $name = $this->fix($k->concepto);
            $kind = stripos($name, 'detrac') !== false ? 'detraction' : (stripos($name, 'rebate') !== false ? 'rebate' : (stripos($name, 'abono') !== false ? 'deposit' : 'deduction'));
            $this->upsert('hordex.comisiones', $k->id, 'b2b_concepts', ['company_id' => $this->cid, 'client_id' => $this->mapped('hordex.clientes', $k->cliente_id),
                'name' => $name, 'kind' => $kind, 'percent' => (float) $k->porcentaje, 'active' => 1]);
        }
        // The bank accounts the deposits go to (pagos sentido in: "ABONO EN CTA BBVA" / "abono bbva"...) as deposit concepts.
        foreach (['ABONO EN CTA BBVA', 'ABONO EN CTA SCOTIABANK'] as $name) {
            if (! DB::table('b2b_concepts')->where('company_id', $this->cid)->where('name', $name)->exists()) {
                DB::table('b2b_concepts')->insert(['company_id' => $this->cid, 'client_id' => null, 'name' => $name, 'kind' => 'deposit',
                    'percent' => 0, 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        foreach ($this->src->table('transportistas')->orderBy('id')->get() as $t) {
            $r = (array) $t;
            $this->upsert('hordex.transportistas', $t->id, 'b2b_carriers', ['company_id' => $this->cid, 'ruc' => $r['ruc'] ?? null,
                'name' => $this->fix($r['razon_social'] ?? $r['nombre'] ?? ('Transportista '.$t->id)), 'driver_name' => $this->fix($r['conductor'] ?? null),
                'license' => $r['licencia'] ?? null, 'plate' => $r['placa'] ?? null, 'brand' => $r['marca'] ?? null, 'model' => $r['modelo'] ?? null, 'active' => 1]);
        }
        foreach ($this->src->table('guia_motivos')->get() as $m) {
            $code = $m->sunat ? str_pad((string) $m->sunat, 2, '0', STR_PAD_LEFT) : '01';
            $this->map['reason'][$m->id] = (int) DB::table('b2b_transfer_reasons')->whereNull('company_id')->where('sunat_code', $code)->value('id');
        }
        $this->line('terms / concepts / carriers: '.count($this->map['hordex.pago_condiciones'] ?? []).' / '.count($this->map['hordex.comisiones'] ?? []).' / '.count($this->map['hordex.transportistas'] ?? []));
    }

    private function sales(): void
    {
        $terms = $this->src->table('pago_condiciones')->pluck('dias', 'id');
        $n = 0;
        foreach ($this->src->table('facturas')->orderBy('id')->cursor() as $f) {
            $client = $this->mapped('hordex.clientes', $f->cliente_id);
            $date = substr((string) $f->fecha_emision, 0, 10);
            $id = $this->upsert('hordex.facturas', $f->id, 'sales', [
                'company_id' => $this->cid, 'user_id' => $this->userId, 'date' => $date, 'time' => '00:00:00',
                // The series in capitals (11 were typed "e001" there; 2026-11-21).
                'Ref' => B2b::ref(strtoupper(trim((string) $f->serie)), (int) $f->correlativo), 'is_pos' => 0, 'client_id' => $client, 'warehouse_id' => $this->warehouseId,
                'tax_rate' => 18, 'TaxNet' => (float) $f->igv, 'discount' => 0, 'shipping' => 0, 'GrandTotal' => (float) $f->total,
                'statut' => $f->estado === 'ANULADA' ? 'canceled' : 'completed', 'payment_statut' => $f->estado === 'CANCELADA' ? 'paid' : 'unpaid',
                'paid_amount' => 0, 'notes' => $f->oc ? 'OC '.$f->oc : null, 'created_at' => $f->created_at,
            ]);
            DB::table('sale_details')->where('sale_id', $id)->delete();
            foreach ($this->src->table('factura_detalles')->where('factura_id', $f->id)->get() as $d) {
                DB::table('sale_details')->insert(['date' => $date, 'sale_id' => $id, 'product_id' => $this->mapped('hordex.productos', $d->producto_id),
                    'price' => (float) $d->p_unit, 'quantity' => (float) $d->cantidad, 'total' => (float) $d->subtotal, 'TaxNet' => 0, 'tax_method' => '2',
                    'discount' => 0, 'discount_method' => '2', 'sale_unit_id' => $this->mapped('hordex.medidas', $d->medida_id),
                    'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('b2b_sale_info')->updateOrInsert(['sale_id' => $id], ['company_id' => $this->cid, 'series' => strtoupper(trim((string) $f->serie)), 'number' => (int) $f->correlativo,
                'purchase_order' => $f->oc, 'payment_term_id' => $this->mapped('hordex.pago_condiciones', $f->pago_condicion_id),
                'due_date' => $f->fecha_vencimiento ?: ($terms[$f->pago_condicion_id] ?? null ? date('Y-m-d', strtotime($date.' +'.(int) $terms[$f->pago_condicion_id].' days')) : $date),
                'created_at' => now(), 'updated_at' => now()]);
            $n++;
        }
        $this->line("sales: $n");
    }

    private function payments(): void
    {
        $concepts = DB::table('b2b_concepts')->where('company_id', $this->cid)->get();
        $n = 0;
        foreach ($this->src->table('pagos')->orderBy('id')->cursor() as $p) {
            $sale = $this->mapped('hordex.facturas', $p->factura_id);
            if (! $sale) {
                continue;
            }
            $name = (string) $this->fix($p->concepto);
            $kind = $p->sentido === 'in' ? 'deposit' : (stripos($name, 'detrac') !== false ? 'detraction' : (stripos($name, 'rebate') !== false ? 'rebate' : 'deduction'));
            $concept = $concepts->first(fn ($c) => mb_strtolower($c->name) === mb_strtolower($name));
            if (! $concept && $kind === 'deposit') {
                $bank = stripos($name, 'scotia') !== false ? 'ABONO EN CTA SCOTIABANK' : (stripos($name, 'bbva') !== false ? 'ABONO EN CTA BBVA' : null);
                $concept = $bank ? $concepts->firstWhere('name', $bank) : null;
            }
            $amount = (float) ($p->abonado > 0 ? $p->abonado : $p->monto);
            $legacy = $this->mapped('hordex.pagos', $p->id);
            $values = ['user_id' => $this->userId, 'date' => substr((string) ($p->fecha_abono ?: $p->fecha_documento ?: $p->created_at), 0, 10),
                'Ref' => 'HX-'.$p->id, 'sale_id' => $sale, 'montant' => $amount, 'change' => 0, 'payment_method_id' => B2b::methodId($kind),
                'notes' => $name, 'created_at' => $p->created_at];
            $pid = $this->upsert('hordex.pagos', $p->id, 'payment_sales', $values);
            DB::table('b2b_payment_info')->updateOrInsert(['payment_sale_id' => $pid], ['company_id' => $this->cid, 'concept_id' => $concept->id ?? null, 'kind' => $kind,
                'document' => $p->documento, 'document_date' => $p->fecha_documento ? substr((string) $p->fecha_documento, 0, 10) : null,
                'percent' => $p->porcentaje, 'expected' => $p->monto, 'created_at' => now(), 'updated_at' => now()]);
            $n++;
        }
        // Each sale's paid amount from its lines; a sale the earlier system closed (CANCELADA) stays paid.
        foreach (array_values($this->map['hordex.facturas'] ?? []) as $saleId) {
            $paid = round((float) DB::table('payment_sales')->where('sale_id', $saleId)->whereNull('deleted_at')->sum('montant'), 2);
            $s = DB::table('sales')->where('id', $saleId)->first(['GrandTotal', 'payment_statut']);
            $status = $s->payment_statut === 'paid' ? 'paid' : ($paid <= 0 ? 'unpaid' : ($paid + 0.009 >= (float) $s->GrandTotal ? 'paid' : 'partial'));
            DB::table('sales')->where('id', $saleId)->update(['paid_amount' => $status === 'paid' ? max($paid, (float) $s->GrandTotal) : $paid, 'payment_statut' => $status]);
        }
        $this->line("payments: $n");
    }

    private function guides(): void
    {
        $n = 0;
        $links = $this->src->table('factura_guia')->get()->groupBy('guia_id');
        foreach ($this->src->table('guias')->orderBy('id')->cursor() as $g) {
            $saleLegacy = $g->factura_id ?: ($links[$g->id][0]->factura_id ?? null);
            $sale = $this->mapped('hordex.facturas', $saleLegacy);
            $carrier = $this->mapped('hordex.transportistas', $g->transportista_id);
            $address = $this->mapped('hordex.direcciones', $g->destino_id);
            $id = $this->upsert('hordex.guias', $g->id, 'b2b_guides', [
                'company_id' => $this->cid, 'series' => (string) $g->serie, 'number' => (int) $g->correlativo, 'issue_date' => substr((string) $g->fecha_emision, 0, 10),
                'transfer_date' => $g->fecha_partida ? substr((string) $g->fecha_partida, 0, 10) : null, 'client_id' => $this->mapped('hordex.clientes', $g->cliente_id),
                'address_id' => $address, 'delivery_address' => $address ? DB::table('b2b_client_addresses')->where('id', $address)->value('address') : null,
                'reason_id' => $this->map['reason'][$g->motivo_id] ?? null, 'carrier_id' => $carrier, 'plate' => $g->placa, 'driver_license' => $g->licencia,
                'brand' => $g->marca, 'model' => $g->modelo, 'purchase_order' => $g->oc, 'warehouse_id' => $this->warehouseId, 'sale_id' => $sale,
                'status' => $g->estado === 'ANULADA' ? 'cancelled' : ($sale ? 'invoiced'
                    : (substr((string) $g->fecha_emision, 0, 10) < $this->option('closed-before') ? 'closed' : 'dispatched')),
                'stock_moved' => 0, 'created_at' => $g->created_at,
            ]);
            DB::table('b2b_guide_items')->where('guide_id', $id)->delete();
            foreach ($this->src->table('guia_detalles')->where('guia_id', $g->id)->get() as $d) {
                DB::table('b2b_guide_items')->insert(['guide_id' => $id, 'product_id' => $this->mapped('hordex.productos', $d->producto_id), 'quantity' => (float) $d->cant,
                    'unit' => 'UND', 'created_at' => now(), 'updated_at' => now()]);
            }
            $n++;
        }
        $this->line("guides: $n");
    }

    private function purchases(): void
    {
        $n = 0;
        foreach ($this->src->table('facturas_proveedores')->orderBy('id')->cursor() as $f) {
            $date = substr((string) $f->f_emision, 0, 10);
            $id = $this->upsert('hordex.facturas_proveedores', $f->id, 'purchases', [
                'company_id' => $this->cid, 'user_id' => $this->userId, 'Ref' => trim($f->serie.'-'.$f->correlativo, '-'), 'date' => $date, 'time' => '00:00:00',
                'provider_id' => $this->mapped('hordex.proveedores', $f->proveedor_id), 'warehouse_id' => $this->warehouseId, 'tax_rate' => 18,
                'TaxNet' => (float) $f->igv, 'discount' => 0, 'shipping' => 0, 'GrandTotal' => (float) $f->total,
                'paid_amount' => $f->estado === 'CANCELADA' ? (float) $f->total : 0, 'statut' => 'received',
                'payment_statut' => $f->estado === 'CANCELADA' ? 'paid' : 'unpaid', 'notes' => ($f->moneda_id == 2 ? 'Dólares, TC '.$f->tc : null),
                'created_at' => $f->created_at,
            ]);
            // Its kind, currency, dates and state (Hordex's Facturas de Proveedores, 2026-11-21).
            DB::table('b2b_purchase_info')->updateOrInsert(['purchase_id' => $id], ['company_id' => $this->cid,
                // There is no kind column there: adelanto by its flag, else servicios when every line is a service, else bienes.
                'kind' => $f->adelanto ? 'adelanto' : ($this->src->table('facturas_proveedores_detalles as d')->join('productos as x', 'x.id', '=', 'd.producto_id')
                    ->where('d.factura_id', $f->id)->where('x.tipo', '!=', 'servicio')->exists() ? 'bienes'
                    : ($this->src->table('facturas_proveedores_detalles')->where('factura_id', $f->id)->exists() ? 'servicios' : 'bienes')),
                'currency' => $f->moneda_id == 2 ? 'USD' : 'PEN', 'exchange_rate' => $f->tc ?: null,
                'due_date' => substr((string) $f->f_vencimiento, 0, 10) ?: null, 'pay_date' => $f->f_pago ? substr((string) $f->f_pago, 0, 10) : null,
                'status' => $f->estado ?: 'PENDIENTE', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('purchase_details')->where('purchase_id', $id)->delete();
            foreach ($this->src->table('facturas_proveedores_detalles')->where('factura_id', $f->id)->get() as $d) {
                DB::table('purchase_details')->insert(['purchase_id' => $id, 'product_id' => $this->mapped('hordex.productos', $d->producto_id),
                    'cost' => (float) $d->p_unit, 'quantity' => (float) $d->cantidad, 'total' => (float) $d->subtotal, 'TaxNet' => 0, 'tax_method' => '2',
                    'discount' => 0, 'discount_method' => '2', 'created_at' => now(), 'updated_at' => now()]);
            }
            $n++;
        }
        $this->line("purchases: $n");
    }

    /** The series in use and their next number: those of the LAST invoice / guide by date (not the highest number). */
    private function counters(): void
    {
        $s = B2b::settings($this->cid);
        $inv = $this->src->table('facturas')->where('estado', '!=', 'ANULADA')->orderByDesc('fecha_emision')->orderByDesc('id')->first(['serie', 'correlativo']);
        $gui = $this->src->table('guias')->where('estado', '!=', 'ANULADA')->orderByDesc('fecha_emision')->orderByDesc('id')->first(['serie', 'correlativo']);
        if ($inv) {
            $s['invoice_series'] = (string) $inv->serie;
            $s['next'][(string) $inv->serie] = max((int) ($s['next'][(string) $inv->serie] ?? 0), (int) $inv->correlativo + 1);
        }
        if ($gui) {
            $s['guide_series'] = (string) $gui->serie;
            $s['next'][(string) $gui->serie] = max((int) ($s['next'][(string) $gui->serie] ?? 0), (int) $gui->correlativo + 1);
        }
        $s['warehouse_id'] = $s['warehouse_id'] ?: $this->warehouseId;
        // The chains' annual rebates of Hordex's board (its HomeController: CENCOSUD 2 %, TMH 1.5 %), when not set yet.
        if (empty($s['annual'])) {
            $s['annual'] = array_values(array_filter([
                ($c = $this->mapped('hordex.clientes', 2)) ? ['client_id' => $c, 'label' => 'Rebate anual', 'percent' => 2.0] : null,
                ($c = $this->mapped('hordex.clientes', 13)) ? ['client_id' => $c, 'label' => 'Bonificación anual', 'percent' => 1.5] : null,
            ]));
        }
        DB::table('settings')->where('company_id', $this->cid)->whereNull('deleted_at')->update(['b2b_settings' => json_encode($s)]);
        $this->line('next: invoice '.B2b::ref($s['invoice_series'], B2b::nextInvoiceNumber($this->cid, $s['invoice_series']))
            .', guide '.B2b::ref($s['guide_series'], B2b::nextGuideNumber($this->cid, $s['guide_series'])));
    }

    private function kardex(): void
    {
        DB::table('b2b_kardex')->where('company_id', $this->cid)->delete();
        $rows = [];
        // The movement's motive by its earlier name (2026-11-21: the product card's "Últimos movimientos").
        $reasons = $this->src->table('transporte_motivos')->pluck('nombre', 'id');   // its Kardex::Motivo()
        foreach ($this->src->table('kardex')->whereNull('deleted_at')->orderBy('f_movimiento')->orderBy('id')->cursor() as $k) {
            $ref = null;
            if ($k->referencia_type === 'App\\Models\\Guia' && $k->referencia_id) {
                $g = $this->src->table('guias')->where('id', $k->referencia_id)->first(['serie', 'correlativo']);
                $ref = $g ? 'Guía '.B2b::ref((string) $g->serie, (int) $g->correlativo) : null;
            } elseif ($k->referencia_type === 'App\\Models\\Ingreso' && $k->referencia_id) {
                $in = $this->src->table('ingresos')->where('id', $k->referencia_id)->first(['guia', 'factura']);
                $ref = 'Compra'.($in && ($in->factura || $in->guia) ? ' '.($in->factura ? 'Fact. '.$in->factura : 'G.R. '.$in->guia) : ' #'.$k->referencia_id);
            }
            $rows[] = ['company_id' => $this->cid, 'product_id' => $this->mapped('hordex.productos', $k->producto_id) ?: 0, 'moved_at' => $k->f_movimiento,
                'direction' => $k->sentido, 'reason' => $reasons[$k->motivo_id] ?? null,
                'quantity' => (float) $k->cantidad, 'price' => $k->precio, 'balance' => $k->saldo, 'reference' => $ref,
                // The receipt ("Compras") that brought it in, so deleting the receipt takes its stock back out (2026-11-21).
                'receipt_id' => $k->referencia_type === 'App\\Models\\Ingreso' ? $this->mapped('hordex.ingresos', $k->referencia_id) : null,
                'created_at' => now(), 'updated_at' => now()];
            if (count($rows) >= 500) {
                DB::table('b2b_kardex')->insert($rows);
                $rows = [];
            }
        }
        if ($rows) {
            DB::table('b2b_kardex')->insert($rows);
        }
        // The earlier balances carry its calculation errors (phantom stock when a guide manufactured, packing subtracted
        // undivided): recompute every product's balances and stock from the movements (user's decision, 2026-11-21).
        $fixed = 0;
        foreach (DB::table('b2b_kardex')->where('company_id', $this->cid)->distinct()->pluck('product_id') as $pid) {
            if ($pid && B2b::rebalance($this->cid, (int) $pid, true)) {
                $fixed++;
            }
        }
        $this->line('kardex: '.DB::table('b2b_kardex')->where('company_id', $this->cid)->count().', products rebalanced: '.$fixed);
    }

    /** Hordex's "Compras" (ingresos: goods received) -> b2b_receipts + items (2026-11-21). */
    private function receipts(): void
    {
        $motives = $this->src->table('transporte_motivos')->pluck('nombre', 'id');
        $kinds = [8 => 'compra', 12 => 'adelanto', 13 => 'servicio'];
        $currency = $this->src->table('monedas')->pluck('abrev', 'id');
        foreach ($this->src->table('ingresos')->orderBy('id')->get() as $r) {
            $id = $this->upsert('hordex.ingresos', $r->id, 'b2b_receipts', [
                'company_id' => $this->cid, 'date' => $r->fecha_ingreso, 'motive' => $motives[$r->transporte_motivo_id] ?? null,
                'kind' => $kinds[$r->transporte_motivo_id] ?? null, 'provider_id' => $this->mapped('hordex.proveedores', $r->proveedor_id),
                'guide' => $r->guia, 'invoice' => $r->factura, 'invoice_date' => $r->fecha_factura, 'notes' => $this->fix($r->observaciones),
                'purchase_id' => $r->factura_id ? $this->mapped('hordex.facturas_proveedores', $r->factura_id) : null,   // "Vincular"
                'created_at' => $r->created_at ?? now(),
            ]);
            DB::table('b2b_receipt_items')->where('receipt_id', $id)->delete();
            foreach ($this->src->table('ingreso_detalles')->where('ingreso_id', $r->id)->whereNull('deleted_at')->orderBy('id')->get() as $d) {
                DB::table('b2b_receipt_items')->insert(['receipt_id' => $id, 'product_id' => $this->mapped('hordex.productos', $d->producto_id),
                    'currency' => ($currency[$d->moneda_id] ?? 'PEN') === 'DOL' ? 'USD' : 'PEN', 'price' => $d->precio_compra,
                    'quantity' => (float) $d->cantidad, 'created_at' => $d->created_at ?? now(), 'updated_at' => now()]);
            }
        }
        $this->line('receipts (compras): '.count($this->map['hordex.ingresos'] ?? []));
    }
}
