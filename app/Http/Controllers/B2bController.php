<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Services\Helpdesk\HelpdeskAccess;
use App\Support\B2b;
use App\Support\ModuleFlags;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Distribución B2B (2026-11-20, Hordex). Every endpoint works on the acting company (never AdminSystem 999) with the
 * module on, and checks its permission (b2b_view / b2b_guides / b2b_invoice / b2b_collections / b2b_setup).
 *
 *  GET  b2b/options                      GET  b2b/dashboard              GET b2b/kardex
 *  GET  b2b/guides  POST b2b/guides      GET  b2b/guides/{id}            POST b2b/guides/{id}/cancel
 *  POST b2b/invoice                      GET  b2b/invoices               GET b2b/invoices/{id}
 *  POST b2b/invoices/{id}/payments       DELETE b2b/payments/{id}
 *  GET  b2b/setup   POST b2b/setup/{type}   PUT b2b/setup/{type}/{id}   DELETE b2b/setup/{type}/{id}   PUT b2b/settings
 */
class B2bController extends Controller
{
    use ScopesToCompany;

    private function allow(Request $request, string ...$perms): void
    {
        foreach ($perms as $p) {
            if (HelpdeskAccess::can($request->user('api'), $p)) {
                return;
            }
        }
        abort(403, __('You do not have permission for this.'));
    }

    private function company(Request $request): int
    {
        $cid = (int) $this->resolveActingCompanyId($request);
        abort_if($cid === 999, 422, __('Pick a company in the header first.'));
        ModuleFlags::abortUnlessEnabled($cid, 'b2b');

        return $cid;
    }

    private function page($q, Request $request, array $sortable, string $default): array
    {
        $total = (clone $q)->count();
        $per = max(10, min(500, (int) $request->input('limit', 50)));
        $sort = $sortable[$request->SortField] ?? $default;
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';

        return [$q->orderBy($sort, $dir)->orderBy(explode('.', $default)[0].'.id', $dir)->forPage(max(1, (int) $request->input('page', 1)), $per)->get(), $total];
    }

    // ------------------------------------------------------------------ options / settings

    public function options(Request $request)
    {
        $this->allow($request, 'b2b_view', 'b2b_guides', 'b2b_invoice', 'b2b_collections', 'b2b_setup');
        $cid = $this->company($request);
        $s = B2b::settings($cid);

        return response()->json([
            'settings' => $s,
            'next_guide' => B2b::nextGuideNumber($cid, $s['guide_series']),
            'next_invoice' => B2b::nextInvoiceNumber($cid, $s['invoice_series']),
            'clients' => DB::table('clients')->where('company_id', $cid)->whereNull('deleted_at')->where('name', '!=', 'Walk-in Customer')
                ->orderBy('name')->get(['id', 'name', 'tax_number']),
            'products' => DB::table('products')->where('company_id', $cid)->whereNull('deleted_at')->orderBy('name')
                ->get(['id', 'code', 'name', 'price', 'type', 'unit_sale_id']),
            'units' => DB::table('units')->where(fn ($w) => $w->where('company_id', $cid)->orWhereNull('company_id'))->whereNull('deleted_at')
                ->orderBy('ShortName')->get(['id', 'name', 'ShortName']),
            'carriers' => DB::table('b2b_carriers')->where('company_id', $cid)->whereNull('deleted_at')->where('active', 1)->orderBy('name')->get(),
            'reasons' => DB::table('b2b_transfer_reasons')->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $cid))
                ->where('active', 1)->orderBy('sunat_code')->get(['id', 'sunat_code', 'name', 'direction']),
            'terms' => DB::table('b2b_payment_terms')->where('company_id', $cid)->where('active', 1)->orderBy('days')->get(['id', 'name', 'days']),
            'concepts' => DB::table('b2b_concepts')->where('company_id', $cid)->where('active', 1)->orderBy('name')->get(['id', 'client_id', 'name', 'kind', 'percent']),
            'addresses' => DB::table('b2b_client_addresses')->where('company_id', $cid)->whereNull('deleted_at')->orderBy('alias')
                ->get(['id', 'client_id', 'alias', 'address', 'district']),
            'warehouses' => DB::table('warehouses')->where('company_id', $cid)->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            'providers' => DB::table('providers')->where('company_id', $cid)->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function saveSettings(Request $request)
    {
        $this->allow($request, 'b2b_setup');
        $cid = $this->company($request);
        $d = $request->validate([
            'invoice_series' => 'required|string|max:8|regex:/^[A-Za-z0-9]+$/', 'guide_series' => 'required|string|max:8|regex:/^[A-Za-z0-9]+$/',
            'igv' => 'required|numeric|min:0|max:50', 'warehouse_id' => 'nullable|integer',
        ]);
        $d['invoice_series'] = strtoupper($d['invoice_series']);
        $d['guide_series'] = strtoupper($d['guide_series']);
        $d['next'] = B2b::settings($cid)['next'] ?? [];
        $d['annual'] = B2b::settings($cid)['annual'] ?? [];
        DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->update(['b2b_settings' => json_encode($d), 'updated_at' => now()]);

        return response()->json(['ok' => true]);
    }

    // ------------------------------------------------------------------ guides

    public function guides(Request $request)
    {
        $this->allow($request, 'b2b_guides', 'b2b_invoice');
        $cid = $this->company($request);
        $q = DB::table('b2b_guides as g')->leftJoin('clients as c', 'c.id', '=', 'g.client_id')
            ->leftJoin('sales as s', 's.id', '=', 'g.sale_id')
            ->where('g.company_id', $cid)->whereNull('g.deleted_at')
            ->when($request->status, fn ($q, $v) => $q->where('g.status', $v))
            ->when($request->client_id, fn ($q, $v) => $q->where('g.client_id', $v))
            ->when($request->from, fn ($q, $v) => $q->where('g.issue_date', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->where('g.issue_date', '<=', $v))
            ->when($request->search, fn ($q, $v) => $q->where(fn ($w) => $w->where('c.name', 'like', "%$v%")->orWhere('g.purchase_order', 'like', "%$v%")
                ->orWhereRaw("CONCAT(g.series, '-', LPAD(g.number, 6, '0')) like ?", ["%$v%"])->orWhere('g.number', $v)))
            ->select('g.id', 'g.series', 'g.number', 'g.issue_date', 'g.client_id', 'c.name as client_name', 'g.purchase_order', 'g.status',
                'g.sale_id', 's.Ref as sale_ref',
                DB::raw('(SELECT SUM(quantity) FROM b2b_guide_items i WHERE i.guide_id = g.id) as units'));
        [$rows, $total] = $this->page($q, $request, ['number' => 'g.number', 'issue_date' => 'g.issue_date', 'client_name' => 'c.name', 'status' => 'g.status'], 'g.issue_date');

        return response()->json(['guides' => $rows, 'totalRows' => $total]);
    }

    public function guide(Request $request, int $id)
    {
        $this->allow($request, 'b2b_guides', 'b2b_invoice');
        $cid = $this->company($request);
        $g = DB::table('b2b_guides')->where('company_id', $cid)->where('id', $id)->whereNull('deleted_at')->first();
        abort_unless($g, 404);
        $g->items = DB::table('b2b_guide_items as i')->leftJoin('products as p', 'p.id', '=', 'i.product_id')->where('i.guide_id', $id)
            ->get(['i.id', 'i.product_id', 'p.name as product_name', 'p.code', 'p.price', 'i.description', 'i.quantity', 'i.unit']);
        $g->client_name = DB::table('clients')->where('id', $g->client_id)->value('name');
        $g->reason_name = DB::table('b2b_transfer_reasons')->where('id', $g->reason_id)->value('name');
        $g->carrier_name = DB::table('b2b_carriers')->where('id', $g->carrier_id)->value('name');
        $g->sale_ref = $g->sale_id ? DB::table('sales')->where('id', $g->sale_id)->value('Ref') : null;

        return response()->json($g);
    }

    /**
     * POST b2b/guides/xml (file) -- Hordex's "XML" button (2026-11-20): reads the SUNAT electronic remission guide
     * (UBL 2.1 DespatchAdvice) and returns the new-guide form filled: series / number, dates, reason (HandlingCode),
     * client (RUC), delivery point (SUNAT annex), PO (Note "OC: ..."), carrier (RUC), plate, license and the lines
     * (SellersItemIdentification = product code). Nothing is saved; what isn't found comes back in `warnings`.
     */
    public function guideFromXml(Request $request)
    {
        $this->allow($request, 'b2b_guides');
        $cid = $this->company($request);
        $request->validate(['file' => 'required|file|max:2048']);
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string((string) file_get_contents($request->file('file')->getRealPath()), 'SimpleXMLElement', LIBXML_NONET);
        // === false: a DespatchAdvice has only prefixed children, so the element itself casts to false.
        abort_if($xml === false, 422, __('The file is not a valid XML.'));
        $ns = $xml->getNamespaces(true);
        // An invoice XML (also UBL, same namespaces) filled the guide with nonsense (2026-11-20): only a DespatchAdvice.
        abort_unless(isset($ns['cbc'], $ns['cac']) && $xml->getName() === 'DespatchAdvice', 422, __('This XML is not a SUNAT remission guide.'));
        $xml->registerXPathNamespace('cac', $ns['cac']);
        $xml->registerXPathNamespace('cbc', $ns['cbc']);
        $one = function (array $paths) use ($xml) {
            foreach ($paths as $p) {
                $n = @$xml->xpath($p);
                if ($n && trim((string) $n[0]) !== '') {
                    return trim((string) $n[0]);
                }
            }

            return null;
        };
        $ids = array_map('strval', $xml->xpath('//cbc:ID') ?: []);
        $warn = [];

        $docId = $one(['/*/cbc:ID']) ?? ($ids[0] ?? '');
        [$series, $number] = array_pad(explode('-', $docId, 2), 2, null);
        $date = fn ($v) => $v ? substr($v, 0, 10) : null;
        $issue = $date($one(['/*/cbc:IssueDate']));
        $start = $date($one(['//cac:ShipmentStage/cac:TransitPeriod/cbc:StartDate', '//cbc:StartDate']));
        $handling = $one(['//cac:Shipment/cbc:HandlingCode', '//cbc:HandlingCode']);
        $clientRuc = $one(['//cac:DeliveryCustomerParty/cac:Party/cac:PartyIdentification/cbc:ID', '//cac:DeliveryCustomerParty/cbc:CustomerAssignedAccountID']) ?? ($ids[4] ?? null);
        $annex = $one(['//cac:Delivery/cac:DeliveryAddress/cbc:AddressTypeCode', '//cac:DeliveryAddress/cbc:AddressTypeCode']);
        $street = $one(['//cac:Delivery/cac:DeliveryAddress/cac:AddressLine/cbc:Line', '//cac:DeliveryAddress/cac:AddressLine/cbc:Line']);
        $note = $one(['/*/cbc:Note', '//cbc:Note']) ?? '';
        $po = str_contains($note, ':') ? trim(explode(':', $note, 2)[1]) : null;
        $carrierRuc = $one(['//cac:ShipmentStage/cac:CarrierParty/cac:PartyIdentification/cbc:ID']) ?? ($ids[6] ?? null);
        $plate = $one(['//cac:TransportHandlingUnit/cac:TransportEquipment/cbc:ID', '//cac:TransportMeans/cac:RoadTransport/cbc:LicensePlateID']) ?? ($ids[13] ?? null);
        $license = $one(['//cac:DriverPerson/cac:IdentityDocumentReference/cbc:ID']) ?? ($ids[8] ?? null);

        $client = $clientRuc ? DB::table('clients')->where('company_id', $cid)->whereNull('deleted_at')->where('tax_number', $clientRuc)->first(['id', 'name']) : null;
        if (! $client) {
            $warn[] = __('Client not found').': RUC '.($clientRuc ?: '?');
        }
        $address = $client && $annex !== null ? DB::table('b2b_client_addresses')->where('company_id', $cid)->where('client_id', $client->id)
            ->whereNull('deleted_at')->where('sunat_annex', $annex)->value('id') : null;
        $reason = $handling ? DB::table('b2b_transfer_reasons')->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $cid))
            ->where('sunat_code', $handling)->value('id') : null;
        $carrier = $carrierRuc ? DB::table('b2b_carriers')->where('company_id', $cid)->whereNull('deleted_at')->where('ruc', $carrierRuc)->value('id') : null;
        if ($carrierRuc && ! $carrier) {
            $warn[] = __('Carrier not found').': RUC '.$carrierRuc;
        }
        $items = [];
        foreach ($xml->xpath('//cac:DespatchLine') ?: [] as $line) {
            $line->registerXPathNamespace('cac', $ns['cac']);
            $line->registerXPathNamespace('cbc', $ns['cbc']);
            $code = trim((string) ($line->xpath('cac:Item/cac:SellersItemIdentification/cbc:ID')[0] ?? ''));
            $qtyNode = $line->xpath('cbc:DeliveredQuantity')[0] ?? null;
            $name = trim((string) ($line->xpath('cac:Item/cbc:Description')[0] ?? ''));
            $pid = $code !== '' ? DB::table('products')->where('company_id', $cid)->whereNull('deleted_at')->where('code', $code)->value('id') : null;
            if (! $pid) {
                $warn[] = __('Product not found').': '.($code ?: '?').($name ? ' '.$name : '');
            }
            $items[] = ['product_id' => $pid, 'quantity' => (float) $qtyNode, 'unit' => 'UND'];
        }
        if ($series && $number && DB::table('b2b_guides')->where('company_id', $cid)->where('series', strtoupper($series))->where('number', (int) $number)->whereNull('deleted_at')->exists()) {
            $warn[] = __('That guide number already exists.').' '.B2b::ref(strtoupper($series), (int) $number);
        }

        return response()->json([
            'series' => $series ? strtoupper($series) : null, 'number' => $number ? (int) $number : null,
            'issue_date' => $issue, 'transfer_date' => $start ?: $issue, 'reason_id' => $reason, 'client_id' => $client->id ?? null,
            'address_id' => $address, 'delivery_address' => $address ? null : $street, 'purchase_order' => $po, 'carrier_id' => $carrier,
            'plate' => $plate, 'driver_license' => $license, 'items' => $items, 'warnings' => $warn,
        ]);
    }

    public function storeGuide(Request $request)
    {
        $this->allow($request, 'b2b_guides');
        $cid = $this->company($request);
        $d = $request->validate([
            'series' => 'nullable|string|max:8', 'number' => 'nullable|integer|min:1', 'issue_date' => 'required|date', 'transfer_date' => 'nullable|date',
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->where('company_id', $cid)],
            'address_id' => 'nullable|integer', 'delivery_address' => 'nullable|string|max:255', 'reason_id' => 'required|integer',
            'carrier_id' => 'nullable|integer', 'plate' => 'nullable|string|max:15', 'driver_license' => 'nullable|string|max:30',
            'purchase_order' => 'nullable|string|max:60', 'warehouse_id' => 'nullable|integer', 'notes' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1', 'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('company_id', $cid)],
            'items.*.quantity' => 'required|numeric|gt:0', 'items.*.unit' => 'nullable|string|max:10',
        ], ['items.required' => __('Add at least one product.')]);
        $s = B2b::settings($cid);
        $series = strtoupper(trim($d['series'] ?? '') ?: $s['guide_series']);

        $id = DB::transaction(function () use ($cid, $d, $series, $request) {
            // A guide imported from its SUNAT XML keeps that XML's number (Hordex's "XML" button).
            $number = ! empty($d['number']) ? (int) $d['number'] : B2b::nextGuideNumber($cid, $series);
            abort_if(DB::table('b2b_guides')->where('company_id', $cid)->where('series', $series)->where('number', $number)->whereNull('deleted_at')->exists(),
                422, __('That guide number already exists.').' '.B2b::ref($series, $number));
            $carrier = ! empty($d['carrier_id']) ? DB::table('b2b_carriers')->where('company_id', $cid)->where('id', $d['carrier_id'])->first() : null;
            $address = ! empty($d['address_id']) ? DB::table('b2b_client_addresses')->where('company_id', $cid)->where('id', $d['address_id'])->first() : null;
            $id = DB::table('b2b_guides')->insertGetId([
                'company_id' => $cid, 'series' => $series, 'number' => $number, 'issue_date' => $d['issue_date'],
                'transfer_date' => $d['transfer_date'] ?? $d['issue_date'], 'client_id' => $d['client_id'], 'address_id' => $address->id ?? null,
                'delivery_address' => $d['delivery_address'] ?? ($address->address ?? null), 'reason_id' => $d['reason_id'],
                'carrier_id' => $carrier->id ?? null, 'plate' => $d['plate'] ?? ($carrier->plate ?? null),
                'driver_license' => $d['driver_license'] ?? ($carrier->license ?? null), 'brand' => $carrier->brand ?? null, 'model' => $carrier->model ?? null,
                'purchase_order' => $d['purchase_order'] ?? null, 'warehouse_id' => $d['warehouse_id'] ?? B2b::warehouse($cid),
                'status' => 'dispatched', 'stock_moved' => 1, 'notes' => $d['notes'] ?? null, 'created_by' => $request->user('api')->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($d['items'] as $it) {
                DB::table('b2b_guide_items')->insert(['guide_id' => $id, 'product_id' => $it['product_id'], 'quantity' => $it['quantity'],
                    'unit' => $it['unit'] ?? 'UND', 'created_at' => now(), 'updated_at' => now()]);
            }
            B2b::moveStock($id, -1);
            B2b::used($cid, $series, $number);

            return $id;
        });

        return response()->json(['ok' => true, 'id' => $id, 'ref' => B2b::ref($series, (int) DB::table('b2b_guides')->where('id', $id)->value('number'))]);
    }

    public function cancelGuide(Request $request, int $id)
    {
        $this->allow($request, 'b2b_guides');
        $cid = $this->company($request);
        DB::transaction(function () use ($cid, $id) {
            $g = DB::table('b2b_guides')->where('company_id', $cid)->where('id', $id)->lockForUpdate()->first();
            abort_unless($g, 404);
            abort_if($g->status === 'invoiced', 422, __('This guide is already invoiced: cancel the invoice first.'));
            abort_if($g->status === 'cancelled', 422, __('This guide is already cancelled.'));
            if ($g->stock_moved) {
                B2b::moveStock($id, 1);
            }
            DB::table('b2b_guides')->where('id', $id)->update(['status' => 'cancelled', 'stock_moved' => 0, 'updated_at' => now()]);
        });

        return response()->json(['ok' => true]);
    }

    public function invoice(Request $request)
    {
        $this->allow($request, 'b2b_invoice');
        $cid = $this->company($request);
        $d = $request->validate([
            'guide_ids' => 'required|array|min:1', 'guide_ids.*' => 'integer', 'date' => 'required|date', 'series' => 'nullable|string|max:8',
            'payment_term_id' => 'nullable|integer', 'purchase_order' => 'nullable|string|max:60', 'prices' => 'nullable|array', 'notes' => 'nullable|string|max:2000',
        ]);
        $saleId = B2b::invoiceGuides($cid, $d['guide_ids'], (int) $request->user('api')->id, $d);

        return response()->json(['ok' => true, 'sale_id' => $saleId, 'ref' => DB::table('sales')->where('id', $saleId)->value('Ref')]);
    }

    /** Hordex's "Registrar Nueva Factura" (2026-11-20): an invoice typed directly, without guides (no stock movement, like Hordex). */
    public function storeInvoice(Request $request)
    {
        $this->allow($request, 'b2b_invoice');
        $cid = $this->company($request);
        $d = $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->where('company_id', $cid)],
            'date' => 'required|date', 'series' => 'nullable|string|max:8', 'number' => 'nullable|integer|min:1',
            'payment_term_id' => 'nullable|integer', 'purchase_order' => 'nullable|string|max:60', 'notes' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1', 'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('company_id', $cid)],
            'items.*.quantity' => 'required|numeric|gt:0', 'items.*.price' => 'required|numeric|min:0', 'items.*.unit_id' => 'nullable|integer',
            // Products and / or the client's guides (2026-11-22): the guides' lines come in items, the guides get invoiced.
            'guide_ids' => 'nullable|array', 'guide_ids.*' => 'integer',
        ]);
        $saleId = DB::transaction(function () use ($cid, $request, $d) {
            $id = B2b::createInvoice($cid, (int) $request->user('api')->id, (int) $d['client_id'], $d['items'], $d);
            B2b::attachGuides($cid, $id, (int) $d['client_id'], $d['guide_ids'] ?? []);

            return $id;
        });

        return response()->json(['ok' => true, 'sale_id' => $saleId, 'ref' => DB::table('sales')->where('id', $saleId)->value('Ref')]);
    }

    /**
     * PUT b2b/invoices/{id} -- "Editar" (user, 2026-11-22): the invoice is cleared and written again with the lines and guides
     * sent (same number); its earlier guides go back to "to invoice".
     */
    public function updateInvoice(Request $request, int $id)
    {
        $this->allow($request, 'b2b_invoice');
        $cid = $this->company($request);
        $d = $request->validate([
            'date' => 'required|date', 'payment_term_id' => 'nullable|integer', 'purchase_order' => 'nullable|string|max:60', 'notes' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1', 'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('company_id', $cid)],
            'items.*.quantity' => 'required|numeric|gt:0', 'items.*.price' => 'required|numeric|min:0', 'items.*.unit_id' => 'nullable|integer',
            'guide_ids' => 'nullable|array', 'guide_ids.*' => 'integer',
        ]);
        B2b::rebuildInvoice($cid, $id, $d['items'], $d['guide_ids'] ?? [], $d);

        return response()->json(['ok' => true, 'sale_id' => $id, 'ref' => DB::table('sales')->where('id', $id)->value('Ref')]);
    }

    /**
     * GET b2b/invoices/guides?client_id=&sale_id= -- the client's guides an invoice can take: dispatched and not invoiced,
     * plus the ones already on that invoice (editing); each with its lines and the product's price.
     */
    public function invoiceGuides(Request $request)
    {
        $this->allow($request, 'b2b_invoice');
        $cid = $this->company($request);
        $client = (int) $request->client_id;
        $sale = (int) $request->sale_id;
        // Without a client (2026-11-23, "Desde guía" / FACTURAR from a guide): every guide still to invoice, any client.
        $guides = DB::table('b2b_guides as g')->leftJoin('clients as c', 'c.id', '=', 'g.client_id')
            ->where('g.company_id', $cid)->whereNull('g.deleted_at')->when($client, fn ($q) => $q->where('g.client_id', $client))
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('g.status', 'dispatched')->whereNull('g.sale_id'))->when($sale, fn ($w) => $w->orWhere('g.sale_id', $sale)))
            ->orderByDesc('g.issue_date')->orderByDesc('g.number')->limit($client ? 200 : 500)
            ->get(['g.id', 'g.series', 'g.number', 'g.issue_date', 'g.purchase_order', 'g.sale_id', 'g.client_id', 'c.name as client_name']);
        $items = DB::table('b2b_guide_items as i')->leftJoin('products as p', 'p.id', '=', 'i.product_id')
            ->whereIn('i.guide_id', $guides->pluck('id'))->get(['i.guide_id', 'i.product_id', 'i.quantity', 'p.code', 'p.name', 'p.price', 'p.unit_sale_id'])
            ->groupBy('guide_id');

        return response()->json(['guides' => $guides->map(fn ($g) => (array) $g + ['on_invoice' => $sale && (int) $g->sale_id === $sale,
            'items' => ($items[$g->id] ?? collect())->values()])->values()]);
    }

    // ------------------------------------------------------------------ collections

    private function invoiceQuery(int $cid)
    {
        return DB::table('sales as s')->leftJoin('clients as c', 'c.id', '=', 's.client_id')->leftJoin('b2b_sale_info as b', 'b.sale_id', '=', 's.id')
            ->where('s.company_id', $cid)->whereNull('s.deleted_at')->where(fn ($q) => $q->whereNull('s.is_draft')->orWhere('s.is_draft', 0));
    }

    public function invoices(Request $request)
    {
        $this->allow($request, 'b2b_collections', 'b2b_invoice');
        $cid = $this->company($request);
        $today = now()->toDateString();
        $q = $this->invoiceQuery($cid)
            ->when($request->status === 'pending', fn ($q) => $q->where('s.statut', '!=', 'canceled')->whereIn('s.payment_statut', ['unpaid', 'partial']))
            ->when($request->status === 'overdue', fn ($q) => $q->where('s.statut', '!=', 'canceled')->whereIn('s.payment_statut', ['unpaid', 'partial'])->where('b.due_date', '<', $today))
            ->when($request->status === 'paid', fn ($q) => $q->where('s.payment_statut', 'paid'))
            ->when($request->client_id, fn ($q, $v) => $q->where('s.client_id', $v))
            ->when($request->from, fn ($q, $v) => $q->where('s.date', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->where('s.date', '<=', $v))
            ->when($request->search, fn ($q, $v) => $q->where(fn ($w) => $w->where('s.Ref', 'like', "%$v%")->orWhere('c.name', 'like', "%$v%")->orWhere('b.purchase_order', 'like', "%$v%")))
            ->select('s.id', 's.Ref', 's.date', 'c.name as client_name', 's.client_id', 's.GrandTotal', 's.paid_amount', 's.payment_statut', 's.statut',
                'b.due_date', 'b.purchase_order', DB::raw('ROUND(s.GrandTotal - s.paid_amount, 2) as balance'));
        [$rows, $total] = $this->page($q, $request, ['Ref' => 's.Ref', 'date' => 's.date', 'client_name' => 'c.name', 'GrandTotal' => 's.GrandTotal',
            'balance' => DB::raw('s.GrandTotal - s.paid_amount'), 'due_date' => 'b.due_date'], 's.date');
        foreach ($rows as $r) {
            $r->overdue = $r->due_date && $r->due_date < $today && in_array($r->payment_statut, ['unpaid', 'partial'], true) && $r->statut !== 'canceled';
        }

        return response()->json(['invoices' => $rows, 'totalRows' => $total]);
    }

    public function invoiceDetail(Request $request, int $id)
    {
        $this->allow($request, 'b2b_collections', 'b2b_invoice');
        $cid = $this->company($request);
        $s = $this->invoiceQuery($cid)->where('s.id', $id)->first(['s.id', 's.Ref', 's.date', 'c.name as client_name', 's.client_id', 's.GrandTotal',
            's.TaxNet', 's.paid_amount', 's.payment_statut', 's.statut', 'b.due_date', 'b.purchase_order']);
        abort_unless($s, 404);
        $s->payments = DB::table('payment_sales as p')->leftJoin('b2b_payment_info as i', 'i.payment_sale_id', '=', 'p.id')
            ->leftJoin('b2b_concepts as k', 'k.id', '=', 'i.concept_id')->leftJoin('payment_methods as m', 'm.id', '=', 'p.payment_method_id')
            ->where('p.sale_id', $id)->whereNull('p.deleted_at')->orderBy('p.date')->orderBy('p.id')
            ->get(['p.id', 'p.date', 'p.montant as amount', 'p.notes', 'i.kind', 'i.document', 'i.document_date', 'i.percent', 'i.expected', 'k.name as concept', 'm.name as method']);
        $s->guides = DB::table('b2b_guides')->where('sale_id', $id)->get(['id', 'series', 'number', 'issue_date']);
        $s->lines = DB::table('sale_details as d')->leftJoin('products as p', 'p.id', '=', 'd.product_id')->leftJoin('units as u', 'u.id', '=', 'd.sale_unit_id')
            ->where('d.sale_id', $id)->orderBy('d.id')->get(['d.product_id', 'd.sale_unit_id as unit_id', 'p.code', 'p.name', 'd.quantity', 'd.price', 'd.total', 'u.ShortName as unit', 'u.name as unit_name']);
        $s->term = DB::table('b2b_sale_info as b')->join('b2b_payment_terms as t', 't.id', '=', 'b.payment_term_id')->where('b.sale_id', $id)->value('t.name');
        $s->payment_term_id = DB::table('b2b_sale_info')->where('sale_id', $id)->value('payment_term_id');
        $s->notes = DB::table('sales')->where('id', $id)->value('notes');
        $s->concepts = DB::table('b2b_concepts')->where('company_id', $cid)->where('active', 1)
            ->where(fn ($q) => $q->whereNull('client_id')->orWhere('client_id', $s->client_id))->orderBy('name')->get(['id', 'name', 'kind', 'percent']);
        foreach ($this->invoiceDocument($cid, $s) as $k => $v) {
            $s->$k = $v;
        }

        return response()->json($s);
    }

    /**
     * What Hordex's invoice document shows (parciales/detalleFactura, 2026-11-20): RUC, unit names, amount in words, the
     * state (ANULADA / CANCELADA / VENCIDA / PENDIENTE) and, for a client that withholds (agente de retención), the
     * withholdings -- 3 % fiscal + the client's own deductions / rebate -- and the total to receive.
     */
    private function invoiceDocument(int $cid, object $s): array
    {
        $client = DB::table('clients')->where('id', $s->client_id)->first(['tax_number', 'notes']);
        $trunc = fn ($v) => floor($v * 100) / 100;
        $total = (float) $s->GrandTotal;
        $ret = [];
        if ($client && stripos((string) $client->notes, 'retenci') !== false) {
            $ret[] = ['name' => 'Retención Fiscal 3%', 'amount' => $trunc($total * 0.03)];
            foreach (DB::table('b2b_concepts')->where('company_id', $cid)->where('client_id', $s->client_id)->where('active', 1)
                ->whereIn('kind', ['deduction', 'rebate'])->where('percent', '>', 0)->where('name', 'not like', 'Crossdocking%')->orderBy('id')->get() as $k) {
                $ret[] = ['name' => $k->name.' '.rtrim(rtrim(number_format((float) $k->percent, 2), '0'), '.').'%', 'amount' => $trunc($total * $k->percent / 100)];
            }
        }
        $state = $s->statut === 'canceled' ? 'ANULADA' : ($s->payment_statut === 'paid' ? 'CANCELADA'
            : ($s->due_date && $s->due_date < now()->toDateString() ? 'VENCIDA' : 'PENDIENTE'));

        return [
            'tax_number' => $client->tax_number ?? null, 'words' => B2b::words($total).' SOLES', 'state' => $state,
            'retentions' => $ret, 'to_receive' => $ret ? round($total - array_sum(array_column($ret, 'amount')), 2) : null,
        ];
    }

    /**
     * Hordex's "ANULAR FACTURA": its guides go back to "to invoice". With payments (2026-11-23, user: "cambiar el estado
     * de una factura de vencida a cancelada y/o anulada.. no me deja en ninguna parte"): only with remove_payments=1 --
     * the screen asks first -- and its collection lines are removed in the same transaction (kept as deleted rows).
     */
    public function cancelInvoice(Request $request, int $id)
    {
        $this->allow($request, 'b2b_invoice');
        $cid = $this->company($request);
        $removePayments = $request->boolean('remove_payments');
        DB::transaction(function () use ($cid, $id, $removePayments) {
            $s = DB::table('sales')->where('company_id', $cid)->where('id', $id)->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($s, 404);
            abort_if($s->statut === 'canceled', 422, __('The invoice is already cancelled.'));
            abort_if((float) $s->paid_amount > 0 && ! $removePayments, 422, __('The invoice has payments: remove them first.'));
            if ((float) $s->paid_amount > 0) {
                $pids = DB::table('payment_sales')->where('sale_id', $id)->whereNull('deleted_at')->pluck('id');
                DB::table('payment_sales')->whereIn('id', $pids)->update(['deleted_at' => now()]);
                DB::table('b2b_payment_info')->whereIn('payment_sale_id', $pids)->delete();
                B2b::refreshSale($id);
            }
            $guides = DB::table('b2b_guides')->where('company_id', $cid)->where('sale_id', $id)->pluck('id')->all();
            DB::table('sales')->where('id', $id)->update(['statut' => 'canceled', 'updated_at' => now()]);
            DB::table('b2b_sale_info')->where('sale_id', $id)->update(['canceled_guide_ids' => json_encode($guides), 'updated_at' => now()]);
            DB::table('b2b_guides')->where('company_id', $cid)->where('sale_id', $id)->update(['sale_id' => null, 'status' => 'dispatched', 'updated_at' => now()]);
        });

        return response()->json(['ok' => true]);
    }

    /**
     * "Marcar cancelada" (user, 2026-11-23): a pending / overdue invoice is settled in one step -- its balance becomes one
     * collection line (bank deposit by default, with its date and document), so the board's "to collect" and the
     * collections report stay right. Detracciones / deductions still go through Cobranzas line by line.
     */
    /**
     * POST b2b/products/prices {items: [{product_id, price}]} (user, 2026-11-23): after an invoice, the prices the user chose
     * to keep for the next invoices become the products' sheet price. This company's products only.
     */
    public function updateProductPrices(Request $request)
    {
        $this->allow($request, 'b2b_invoice');
        $cid = $this->company($request);
        $d = $request->validate(['items' => 'required|array|min:1', 'items.*.product_id' => 'required|integer', 'items.*.price' => 'required|numeric|min:0']);
        $n = DB::transaction(function () use ($cid, $d) {
            $n = 0;
            foreach ($d['items'] as $i) {
                $n += DB::table('products')->where('company_id', $cid)->where('id', $i['product_id'])->whereNull('deleted_at')
                    ->update(['price' => round((float) $i['price'], 2), 'updated_at' => now()]);
            }

            return $n;
        });

        return response()->json(['ok' => true, 'updated' => $n]);
    }

    public function settleInvoice(Request $request, int $id)
    {
        $this->allow($request, 'b2b_invoice');
        $cid = $this->company($request);
        $d = $request->validate(['date' => 'required|date', 'kind' => 'nullable|in:deposit,detraction,rebate,deduction',
            'document' => 'nullable|string|max:60', 'notes' => 'nullable|string|max:255']);
        $paid = DB::transaction(function () use ($cid, $id, $d, $request) {
            $s = DB::table('sales')->where('company_id', $cid)->where('id', $id)->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($s, 404);
            abort_if($s->statut === 'canceled', 422, __('The invoice is cancelled.'));
            $balance = round((float) $s->GrandTotal - (float) $s->paid_amount, 2);
            abort_if($balance <= 0.009, 422, __('The invoice is already paid.'));
            B2b::addPayment($cid, $id, (int) $request->user('api')->id, ['amount' => $balance, 'date' => $d['date'], 'kind' => $d['kind'] ?? 'deposit',
                'document' => $d['document'] ?? null, 'notes' => $d['notes'] ?? 'Cancelada desde Facturas']);
            B2b::refreshSale($id);

            return $balance;
        });

        return response()->json(['ok' => true, 'amount' => $paid]);
    }

    /**
     * "Revertir anulación" (user, 2026-11-22: an invoice cancelled by mistake): the invoice is valid again with its lines,
     * number and total, and takes back the guides it had when it was cancelled -- those still free; a guide invoiced
     * meanwhile on another invoice stays there and is reported.
     */
    public function revertCancelInvoice(Request $request, int $id)
    {
        $this->allow($request, 'b2b_invoice');
        $cid = $this->company($request);
        $result = DB::transaction(function () use ($cid, $id) {
            $s = DB::table('sales')->where('company_id', $cid)->where('id', $id)->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($s, 404);
            abort_unless($s->statut === 'canceled', 422, __('The invoice is not cancelled.'));
            $ids = json_decode((string) DB::table('b2b_sale_info')->where('sale_id', $id)->value('canceled_guide_ids'), true) ?: [];
            $guides = DB::table('b2b_guides')->where('company_id', $cid)->whereIn('id', $ids)->whereNull('deleted_at')->lockForUpdate()->get();
            $free = $guides->filter(fn ($g) => $g->sale_id === null && $g->status === 'dispatched' && (int) $g->client_id === (int) $s->client_id);
            DB::table('sales')->where('id', $id)->update(['statut' => 'completed', 'updated_at' => now()]);
            DB::table('b2b_guides')->whereIn('id', $free->pluck('id'))->update(['sale_id' => $id, 'status' => 'invoiced', 'updated_at' => now()]);
            DB::table('b2b_sale_info')->where('sale_id', $id)->update(['canceled_guide_ids' => null, 'updated_at' => now()]);

            return [
                'guides' => $free->map(fn ($g) => B2b::ref($g->series, (int) $g->number))->values(),
                'not_returned' => $guides->reject(fn ($g) => $free->contains('id', $g->id))->map(fn ($g) => B2b::ref($g->series, (int) $g->number))->values(),
            ];
        });

        return response()->json(['ok' => true] + $result);
    }

    /** The invoice as Hordex printed it, PDF (inline: the page prints it or downloads it). */
    public function invoicePdf(Request $request, int $id)
    {
        $data = $this->invoiceDetail($request, $id)->getData();
        $cid = $this->company($request);
        $co = DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->first(['CompanyName', 'logo', 'CompanyAdress', 'CompanyPhone', 'email', 'vat_number']);
        $logo = $co && $co->logo && is_file(public_path($co->logo)) ? 'data:image/'.pathinfo($co->logo, PATHINFO_EXTENSION).';base64,'.base64_encode(file_get_contents(public_path($co->logo))) : null;
        $html = view('pdf.b2b_invoice', ['f' => $data, 'co' => $co, 'logo' => $logo])->render();

        return response(\PDF::loadHTML($html)->setPaper('a4')->output(), 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$data->Ref.'.pdf"',
        ]);
    }


    public function addPayments(Request $request, int $id)
    {
        $this->allow($request, 'b2b_collections');
        $cid = $this->company($request);
        $d = $request->validate([
            'lines' => 'required|array|min:1', 'lines.*.amount' => 'required|numeric|gt:0', 'lines.*.date' => 'required|date',
            'lines.*.concept_id' => 'nullable|integer', 'lines.*.kind' => 'nullable|in:deposit,detraction,rebate,deduction',
            'lines.*.document' => 'nullable|string|max:60', 'lines.*.document_date' => 'nullable|date', 'lines.*.percent' => 'nullable|numeric',
            'lines.*.notes' => 'nullable|string|max:255', 'lines.*.expected' => 'nullable|numeric',
        ]);
        abort_unless(DB::table('sales')->where('company_id', $cid)->where('id', $id)->whereNull('deleted_at')->exists(), 404);
        DB::transaction(function () use ($cid, $id, $d, $request) {
            foreach ($d['lines'] as $line) {
                B2b::addPayment($cid, $id, (int) $request->user('api')->id, $line);
            }
            B2b::refreshSale($id);
        });

        return response()->json(['ok' => true]);
    }

    public function deletePayment(Request $request, int $id)
    {
        $this->allow($request, 'b2b_collections');
        $cid = $this->company($request);
        $p = DB::table('payment_sales as p')->join('sales as s', 's.id', '=', 'p.sale_id')->where('s.company_id', $cid)->where('p.id', $id)->first(['p.id', 'p.sale_id']);
        abort_unless($p, 404);
        DB::transaction(function () use ($p) {
            DB::table('payment_sales')->where('id', $p->id)->update(['deleted_at' => now()]);
            DB::table('b2b_payment_info')->where('payment_sale_id', $p->id)->delete();
            B2b::refreshSale((int) $p->sale_id);
        });

        return response()->json(['ok' => true]);
    }

    // ------------------------------------------------------------------ board / kardex

    public function dashboard(Request $request)
    {
        $this->allow($request, 'b2b_view');
        $cid = $this->company($request);
        $today = now()->toDateString();
        $year = (int) ($request->year ?: now()->year);
        $valid = fn ($q) => $q->where('s.company_id', $cid)->whereNull('s.deleted_at')->where('s.statut', '!=', 'canceled');
        $byClient = fn ($from, $to) => DB::table('sales as s')->leftJoin('clients as c', 'c.id', '=', 's.client_id')->tap($valid)
            ->whereBetween('s.date', [$from, $to])->groupBy('s.client_id', 'c.name', 'c.short_name')
            ->selectRaw('s.client_id, COALESCE(NULLIF(c.short_name, \'\'), c.name) as client, ROUND(SUM(s.GrandTotal), 2) as total, ROUND(SUM(s.GrandTotal - s.TaxNet), 2) as net, COUNT(*) as invoices')
            ->orderByDesc('total')->get();
        $overdue = DB::table('sales as s')->join('b2b_sale_info as b', 'b.sale_id', '=', 's.id')->leftJoin('clients as c', 'c.id', '=', 's.client_id')
            ->tap($valid)->whereIn('s.payment_statut', ['unpaid', 'partial'])
            ->orderBy('b.due_date')->limit(120)
            ->get(['s.id', 's.Ref', 's.date', 'b.due_date', DB::raw("COALESCE(NULLIF(c.short_name, ''), c.name) as client"), DB::raw('ROUND(s.GrandTotal - s.paid_amount, 2) as balance')]);
        // Hordex's board groups what is still to collect: overdue, this week, the next 15 days, later.
        $in7 = now()->addDays(7)->toDateString();
        $in15 = now()->addDays(15)->toDateString();
        foreach ($overdue as $o) {
            $o->overdue = $o->due_date && $o->due_date < $today;
            $o->days = $o->due_date ? (int) round((strtotime($today) - strtotime($o->due_date)) / 86400) : null;
            $o->group = ! $o->due_date || $o->due_date < $today ? 'overdue' : ($o->due_date <= $in7 ? 'week' : ($o->due_date <= $in15 ? 'fortnight' : 'later'));
        }
        // The chains' annual rebates (b2b_settings.annual: {client_id, label, percent}) for the year before and the year.
        $rules = B2b::settings($cid)['annual'] ?? [];
        $annual = [];
        foreach ([$year - 1, $year] as $y) {
            $sales = DB::table('sales as s')->tap($valid)->whereBetween('s.date', ["$y-01-01", "$y-12-31"])
                ->groupBy('s.client_id')->selectRaw('s.client_id, ROUND(SUM(s.GrandTotal), 2) as total')->pluck('total', 'client_id');
            $annual[$y] = array_map(function ($r) use ($sales) {
                $base = (float) ($sales[$r['client_id']] ?? 0);
                $c = DB::table('clients')->where('id', $r['client_id'])->first(['name', 'short_name']);

                return ['label' => $r['label'], 'client' => $c ? ($c->short_name ?: $c->name) : '', 'percent' => (float) $r['percent'],
                    'base' => round($base, 2), 'amount' => floor($base * (float) $r['percent']) / 100];
            }, $rules);
        }
        $deductions = DB::table('payment_sales as p')->join('sales as s', 's.id', '=', 'p.sale_id')->join('b2b_payment_info as i', 'i.payment_sale_id', '=', 'p.id')
            ->leftJoin('b2b_concepts as k', 'k.id', '=', 'i.concept_id')->leftJoin('clients as c', 'c.id', '=', 's.client_id')
            ->tap($valid)->whereNull('p.deleted_at')->whereYear('p.date', $year)->where('i.kind', '!=', 'deposit')
            ->groupBy('c.name', 'i.kind', DB::raw('COALESCE(k.name, p.notes, i.kind)'))
            ->selectRaw('c.name as client, i.kind, COALESCE(k.name, p.notes, i.kind) as concept, ROUND(SUM(p.montant), 2) as total')
            ->orderByDesc('total')->get();
        $years = [];
        foreach ([$year - 1, $year] as $y) {
            $years[$y] = $byClient("$y-01-01", "$y-12-31");
        }

        return response()->json([
            'year' => $year,
            'month' => $byClient(now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()),
            'years' => $years,
            'overdue' => $overdue,
            'annual' => $annual,
            'deductions' => $deductions,
            'guides_pending' => DB::table('b2b_guides')->where('company_id', $cid)->where('status', 'dispatched')->whereNull('deleted_at')->count(),
        ]);
    }

    public function kardex(Request $request)
    {
        $this->allow($request, 'b2b_view');
        $cid = $this->company($request);
        $q = DB::table('b2b_kardex as k')->leftJoin('products as p', 'p.id', '=', 'k.product_id')->where('k.company_id', $cid)
            ->when($request->product_id, fn ($q, $v) => $q->where('k.product_id', $v))
            ->when($request->from, fn ($q, $v) => $q->where('k.moved_at', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->where('k.moved_at', '<=', $v.' 23:59:59'))
            ->when($request->search, fn ($q, $v) => $q->where(fn ($w) => $w->where('p.name', 'like', "%$v%")->orWhere('k.reference', 'like', "%$v%")))
            ->select('k.id', 'k.moved_at', 'k.direction', 'k.quantity', 'k.price', 'k.balance', 'k.reference', 'p.name as product', 'p.code');
        [$rows, $total] = $this->page($q, $request, ['moved_at' => 'k.moved_at', 'product' => 'p.name'], 'k.moved_at');

        return response()->json(['rows' => $rows, 'totalRows' => $total]);
    }

    // ------------------------------------------------------------------ supplier invoices (Hordex's "Compras", 2026-11-20)

    // ------------------------------------------------------------------ Facturas de Proveedores (Hordex's, 2026-11-21)

    /** The supplier invoices' state as there: PENDIENTE / CANCELADA (paid) / ANULADA, from b2b_purchase_info. */
    private function purchaseQuery(int $cid)
    {
        return DB::table('purchases as p')->leftJoin('providers as v', 'v.id', '=', 'p.provider_id')->leftJoin('b2b_purchase_info as i', 'i.purchase_id', '=', 'p.id')
            ->where('p.company_id', $cid)->whereNull('p.deleted_at');
    }

    public function purchases(Request $request)
    {
        $this->allow($request, 'b2b_view');
        $cid = $this->company($request);
        $q = $this->purchaseQuery($cid)
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w->where('p.Ref', 'like', "%$s%")->orWhere('v.name', 'like', "%$s%")->orWhere('v.short_name', 'like', "%$s%")))
            // State filter (2026-11-23).
            ->when(in_array($request->status, ['PENDIENTE', 'CANCELADA', 'ANULADA'], true), fn ($q) => $q->whereRaw(
                "COALESCE(i.status, CASE WHEN p.payment_statut = 'paid' THEN 'CANCELADA' ELSE 'PENDIENTE' END) = ?", [$request->status]))
            ->select('p.id', 'p.Ref', 'p.date', DB::raw("COALESCE(NULLIF(v.short_name, ''), v.name) as provider"), 'p.GrandTotal',
                DB::raw("CASE WHEN i.currency = 'USD' THEN '$' ELSE 'S/' END as currency"),
                DB::raw("COALESCE(i.status, CASE WHEN p.payment_statut = 'paid' THEN 'CANCELADA' ELSE 'PENDIENTE' END) as estado"));
        [$rows, $total] = $this->page($q, $request, ['date' => 'p.date', 'Ref' => 'p.Ref', 'provider' => 'v.name', 'GrandTotal' => 'p.GrandTotal'], 'p.date');

        return response()->json(['purchases' => $rows, 'totalRows' => $total]);
    }

    /** "Ver": the invoice as Hordex shows it (FACTURA ELECTRONICA box, dates, supplier, currency, related guides, lines, totals). */
    public function purchase(Request $request, int $id)
    {
        $this->allow($request, 'b2b_view');
        $cid = $this->company($request);
        $p = $this->purchaseQuery($cid)->where('p.id', $id)->first(['p.id', 'p.Ref', 'p.date', 'p.provider_id', 'v.name as provider', 'v.tax_number',
            'p.GrandTotal', 'p.TaxNet', 'i.kind', 'i.currency', 'i.exchange_rate', 'i.due_date', 'i.pay_date',
            DB::raw("COALESCE(i.status, CASE WHEN p.payment_statut = 'paid' THEN 'CANCELADA' ELSE 'PENDIENTE' END) as estado")]);
        abort_unless($p, 404);
        $p->lines = DB::table('purchase_details as d')->leftJoin('products as pr', 'pr.id', '=', 'd.product_id')->where('d.purchase_id', $id)->orderBy('d.id')
            ->get(['d.product_id', 'pr.code', 'pr.name', 'd.quantity', 'd.cost', 'd.total']);
        $receipts = DB::table('b2b_receipts')->where('purchase_id', $id)->whereNull('deleted_at')->get(['id', 'guide']);
        $p->guides = $receipts->pluck('guide')->filter()->values();
        // For "Editar" (2026-11-23): the linked receipts and their items, so the form keeps them.
        $p->receipt_ids = $receipts->pluck('id')->values();
        $p->receipt_items = DB::table('b2b_receipt_items')->whereIn('receipt_id', $p->receipt_ids)->get(['receipt_id', 'product_id']);

        return response()->json($p);
    }

    /** The receipts ("Compras") of a supplier with a guide and not invoiced yet -- "Vincular" (getGuiasProveedor). */
    public function purchasePendingGuides(Request $request)
    {
        $this->allow($request, 'b2b_view');
        $cid = $this->company($request);
        $request->validate(['provider_id' => 'required|integer']);
        $rows = DB::table('b2b_receipts')->where('company_id', $cid)->where('provider_id', $request->provider_id)->whereNull('deleted_at')
            ->whereNotNull('guide')->where('guide', '!=', '')->whereNull('purchase_id')->where(fn ($w) => $w->whereNull('invoice')->orWhere('invoice', ''))
            ->orderBy('date')->get(['id', 'date', 'guide', 'motive']);
        foreach ($rows as $r) {
            $r->items = DB::table('b2b_receipt_items as i')->leftJoin('products as x', 'x.id', '=', 'i.product_id')->where('i.receipt_id', $r->id)
                ->orderBy('i.id')->get(['i.id', 'i.product_id', 'x.name', 'x.code', 'i.quantity', 'i.price']);
        }

        return response()->json($rows);
    }

    /** The number to suggest (2026-11-21): the next after the supplier's last invoice, by date, in the series asked (or its last series). */
    public function purchaseNextNumber(Request $request)
    {
        $this->allow($request, 'b2b_view');
        $cid = $this->company($request);
        $request->validate(['provider_id' => 'required|integer']);
        $refs = DB::table('purchases')->where('company_id', $cid)->where('provider_id', $request->provider_id)->whereNull('deleted_at')
            ->orderByDesc('date')->orderByDesc('id')->limit(200)->pluck('Ref');
        $want = strtoupper(trim((string) $request->series));
        foreach ($refs as $ref) {
            if (! preg_match('/^\s*([A-Z0-9]+)\s*-\s*0*(\d+)\s*$/i', (string) $ref, $m)) {
                continue;
            }
            if ($want === '' || strtoupper($m[1]) === $want) {
                return response()->json(['series' => strtoupper($m[1]), 'last' => (int) $m[2], 'next' => (int) $m[2] + 1]);
            }
        }

        return response()->json(['series' => $want ?: null, 'last' => null, 'next' => null]);
    }

    /**
     * "Registrar Nueva Factura de Proveedor", as there: supplier, kind (bienes / servicios / adelanto), currency (+ T.C.),
     * series - number, dates, lines; "Vincular" links goods receipts (their lines come with it) and writes the invoice
     * on them. The same supplier + series - number cannot be registered twice (verifyFactura).
     */
    public function storePurchase(Request $request)
    {
        return $this->savePurchase($request, null);
    }

    /**
     * "Editar" a supplier invoice (user, 2026-11-23: "cuando le doy click ... se muestre al costado el detalle y tenga la
     * opción de editar"): the same form and checks as a new one; its lines are replaced and its receipts re-linked. Its
     * state is kept (changed with its own buttons). Not an ANULADA one.
     */
    public function updatePurchase(Request $request, int $id)
    {
        return $this->savePurchase($request, $id);
    }

    private function savePurchase(Request $request, ?int $editId)
    {
        $this->allow($request, 'b2b_view');
        $cid = $this->company($request);
        if ($editId) {
            $cur = $this->purchaseQuery($cid)->where('p.id', $editId)->first([DB::raw("COALESCE(i.status, 'PENDIENTE') as estado")]);
            abort_unless($cur, 404);
            abort_if($cur->estado === 'ANULADA', 422, __('A voided invoice cannot be edited.'));
        }
        $d = $request->validate([
            'provider_id' => ['required', 'integer', Rule::exists('providers', 'id')->where('company_id', $cid)],
            'kind' => 'required|in:bienes,servicios,adelanto', 'currency' => 'required|in:PEN,USD', 'tc' => 'nullable|required_if:currency,USD|numeric|gt:0',
            'series' => 'required|string|max:5', 'number' => 'required|integer|min:1|max:999999999',
            'f_emision' => 'required|date|before_or_equal:today', 'f_venc' => 'required|date|after_or_equal:f_emision', 'f_pago' => 'nullable|date',
            'receipt_ids' => 'nullable|array', 'receipt_ids.*' => 'integer',
            'items' => 'required|array|min:1', 'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('company_id', $cid)],
            'items.*.quantity' => 'required|numeric|gt:0', 'items.*.price' => 'required|numeric|min:0',
        ], ['tc.required_if' => __('Type the exchange rate.'), 'f_venc.after_or_equal' => __('The due date cannot be before the issue date.')]);
        $series = strtoupper(trim($d['series']));
        $ref = $series.'-'.str_pad((string) $d['number'], 7, '0', STR_PAD_LEFT);
        $exists = DB::table('purchases')->where('company_id', $cid)->where('provider_id', $d['provider_id'])->whereNull('deleted_at')
            ->when($editId, fn ($q) => $q->where('id', '!=', $editId))->get(['Ref'])
            ->contains(fn ($p) => preg_match('/^\s*'.preg_quote($series, '/').'\s*-\s*0*'.(int) $d['number'].'\s*$/i', (string) $p->Ref));
        abort_if($exists, 422, __('That invoice of this supplier is already registered.'));
        $id = DB::transaction(function () use ($cid, $d, $ref, $request, $editId) {
            $net = round(collect($d['items'])->sum(fn ($i) => $i['quantity'] * $i['price']), 2);
            $igv = round($net * 0.18, 2);
            if ($editId) {
                // Edit: the amounts and data change; the state stays (a paid one keeps the new total as paid).
                $info = DB::table('b2b_purchase_info')->where('purchase_id', $editId)->first();
                $paid = ($info->status ?? 'PENDIENTE') === 'CANCELADA';
                DB::table('purchases')->where('id', $editId)->update([
                    'Ref' => $ref, 'date' => $d['f_emision'], 'provider_id' => $d['provider_id'], 'TaxNet' => $igv, 'GrandTotal' => round($net + $igv, 2),
                    'paid_amount' => $paid ? round($net + $igv, 2) : 0, 'updated_at' => now(),
                ]);
                DB::table('b2b_purchase_info')->updateOrInsert(['purchase_id' => $editId], ['company_id' => $cid, 'kind' => $d['kind'], 'currency' => $d['currency'],
                    'exchange_rate' => $d['currency'] === 'USD' ? $d['tc'] : null, 'due_date' => $d['f_venc'],
                    'pay_date' => $paid ? ($d['f_pago'] ?? $info->pay_date) : null, 'updated_at' => now()]);
                DB::table('purchase_details')->where('purchase_id', $editId)->delete();
                DB::table('b2b_receipts')->where('company_id', $cid)->where('purchase_id', $editId)->whereNotIn('id', $d['receipt_ids'] ?? [])
                    ->update(['purchase_id' => null, 'invoice' => null, 'invoice_date' => null, 'updated_at' => now()]);
                $id = $editId;
            } else {
                // A new supplier invoice starts PENDIENTE (user, 2026-11-23: "lo programa a 30 días"), unless a payment date is given.
                $paid = ! empty($d['f_pago']);
                $id = DB::table('purchases')->insertGetId([
                    'company_id' => $cid, 'user_id' => $request->user('api')->id, 'Ref' => $ref, 'date' => $d['f_emision'], 'time' => now()->format('H:i:s'),
                    'provider_id' => $d['provider_id'], 'warehouse_id' => B2b::warehouse($cid), 'tax_rate' => 18, 'TaxNet' => $igv, 'discount' => 0, 'shipping' => 0,
                    'GrandTotal' => round($net + $igv, 2), 'paid_amount' => $paid ? round($net + $igv, 2) : 0, 'statut' => 'received',
                    'payment_statut' => $paid ? 'paid' : 'unpaid', 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('b2b_purchase_info')->insert(['company_id' => $cid, 'purchase_id' => $id, 'kind' => $d['kind'], 'currency' => $d['currency'],
                    'exchange_rate' => $d['currency'] === 'USD' ? $d['tc'] : null, 'due_date' => $d['f_venc'], 'pay_date' => $d['f_pago'] ?? null,
                    'status' => $paid ? 'CANCELADA' : 'PENDIENTE', 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach ($d['items'] as $i) {
                DB::table('purchase_details')->insert(['purchase_id' => $id, 'product_id' => $i['product_id'], 'cost' => $i['price'], 'quantity' => $i['quantity'],
                    'total' => round($i['quantity'] * $i['price'], 2), 'TaxNet' => 0, 'tax_method' => '2', 'discount' => 0, 'discount_method' => '2',
                    'created_at' => now(), 'updated_at' => now()]);
            }
            if ($d['kind'] === 'bienes' && ! empty($d['receipt_ids'])) {
                DB::table('b2b_receipts')->where('company_id', $cid)->where('provider_id', $d['provider_id'])->whereIn('id', $d['receipt_ids'])
                    ->where(fn ($q) => $q->whereNull('purchase_id')->orWhere('purchase_id', $id))
                    ->update(['purchase_id' => $id, 'invoice' => $ref, 'invoice_date' => $d['f_emision'], 'updated_at' => now()]);
            }

            return $id;
        });

        return response()->json(['ok' => true, 'id' => $id, 'ref' => $ref]);
    }

    /** The row's actions, as there: CANCELAR (paid on a date), ANULAR, BORRAR (its receipts unlinked -- ALL of them; there only the first). */
    public function payPurchase(Request $request, int $id)
    {
        $this->allow($request, 'b2b_view');
        $cid = $this->company($request);
        $d = $request->validate(['f_pago' => 'required|date']);
        $p = $this->purchaseQuery($cid)->where('p.id', $id)->first(['p.GrandTotal', DB::raw("COALESCE(i.status, 'PENDIENTE') as estado")]);
        abort_unless($p, 404);
        abort_unless($p->estado === 'PENDIENTE', 422, __('Only a pending invoice can be paid.'));
        DB::transaction(function () use ($cid, $id, $d, $p) {
            DB::table('purchases')->where('id', $id)->update(['payment_statut' => 'paid', 'paid_amount' => $p->GrandTotal, 'updated_at' => now()]);
            DB::table('b2b_purchase_info')->updateOrInsert(['purchase_id' => $id], ['company_id' => $cid, 'status' => 'CANCELADA', 'pay_date' => $d['f_pago'], 'updated_at' => now()]);
        });

        return response()->json(['ok' => true]);
    }

    /**
     * "Cambiar estado" (user, 2026-11-23: "que también sea un botón el cambiar el estado de cancelada (pagada) a pendiente"):
     * PENDIENTE <-> CANCELADA (paid on a date) and ANULADA, in any direction; ANULADA -> PENDIENTE undoes a void.
     */
    public function purchaseStatus(Request $request, int $id)
    {
        $this->allow($request, 'b2b_view');
        $cid = $this->company($request);
        $d = $request->validate(['status' => 'required|in:PENDIENTE,CANCELADA,ANULADA', 'f_pago' => 'nullable|required_if:status,CANCELADA|date']);
        $p = $this->purchaseQuery($cid)->where('p.id', $id)->first(['p.GrandTotal']);
        abort_unless($p, 404);
        DB::transaction(function () use ($cid, $id, $d, $p) {
            $paid = $d['status'] === 'CANCELADA';
            DB::table('purchases')->where('id', $id)->update(['payment_statut' => $paid ? 'paid' : 'unpaid', 'paid_amount' => $paid ? $p->GrandTotal : 0, 'updated_at' => now()]);
            DB::table('b2b_purchase_info')->updateOrInsert(['purchase_id' => $id], ['company_id' => $cid, 'status' => $d['status'],
                'pay_date' => $paid ? $d['f_pago'] : null, 'updated_at' => now()]);
        });

        return response()->json(['ok' => true]);
    }

    /**
     * "Subir XML" (user, 2026-11-23): a supplier's SUNAT electronic invoice (UBL 2.1 Invoice) fills the form -- the supplier
     * by RUC, series - number, issue date, due date (the instalment's, else the DueDate), currency, its lines (unit value
     * without IGV = LineExtensionAmount / quantity) matched to products by code, then by name among what this supplier
     * sold before, then by name; a receipt of this supplier with the invoice's guide is linked (Vincular). Nothing saved.
     */
    public function purchaseFromXml(Request $request)
    {
        $this->allow($request, 'b2b_view');
        $cid = $this->company($request);
        $request->validate(['file' => 'required|file|max:4096']);
        $raw = file_get_contents($request->file('file')->getRealPath());
        if (str_starts_with($raw, "PK")) {
            // A zip as SUNAT sends it: the first .xml inside.
            $zip = new \ZipArchive();
            abort_unless($zip->open($request->file('file')->getRealPath()) === true, 422, __('The file is not a valid XML.'));
            $raw = null;
            for ($n = 0; $n < $zip->numFiles; $n++) {
                if (preg_match('/\.xml$/i', $zip->getNameIndex($n))) {
                    $raw = $zip->getFromIndex($n);
                    break;
                }
            }
            $zip->close();
            abort_unless($raw, 422, __('The file is not a valid XML.'));
        }
        $prev = libxml_use_internal_errors(true);
        $x = simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NONET);
        libxml_use_internal_errors($prev);
        abort_if($x === false, 422, __('The file is not a valid XML.'));
        abort_unless($x->getName() === 'Invoice', 422, __('It is not a SUNAT invoice (Invoice).'));
        $x->registerXPathNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $x->registerXPathNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $one = function ($el, string $path) {
            $el->registerXPathNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
            $el->registerXPathNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
            $r = $el->xpath($path);

            return $r ? trim((string) $r[0]) : null;
        };
        $warnings = [];
        [$series, $number] = array_pad(explode('-', (string) $one($x, 'cbc:ID'), 2), 2, null);
        $ruc = $one($x, 'cac:AccountingSupplierParty/cac:Party/cac:PartyIdentification/cbc:ID');
        $supplierName = $one($x, 'cac:AccountingSupplierParty/cac:Party/cac:PartyLegalEntity/cbc:RegistrationName');
        $provider = $ruc ? DB::table('providers')->where('company_id', $cid)->where('tax_number', $ruc)->whereNull('deleted_at')->first(['id', 'name']) : null;
        if (! $provider) {
            $warnings[] = __('Supplier not found').": {$supplierName} (RUC {$ruc})";
        }
        $issue = $one($x, 'cbc:IssueDate');
        $dues = array_filter(array_map('strval', $x->xpath('cac:PaymentTerms/cbc:PaymentDueDate') ?: []));
        $due = $dues ? max($dues) : ($one($x, 'cbc:DueDate') ?: null);
        $currency = strtoupper((string) $one($x, 'cbc:DocumentCurrencyCode')) === 'USD' ? 'USD' : 'PEN';

        // Products this supplier sold before (their names weigh more), then every product.
        $bought = $provider ? DB::table('purchase_details as d')->join('purchases as p', 'p.id', '=', 'd.purchase_id')
            ->where('p.company_id', $cid)->where('p.provider_id', $provider->id)->whereNull('p.deleted_at')->distinct()->pluck('d.product_id')->flip() : collect();
        $products = DB::table('products')->where('company_id', $cid)->whereNull('deleted_at')->get(['id', 'code', 'name', 'type', 'cost', 'price']);
        $norm = fn ($s) => trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Z0-9 ]/', ' ',
            strtr(strtoupper(\Illuminate\Support\Str::ascii((string) $s)), ['C/T' => ' CON TAPA ', 'S/T' => ' SIN TAPA ', 'S/E' => ' ']))));
        $tokens = fn ($s) => array_values(array_filter(explode(' ', $norm($s))));
        $items = [];
        $serviceLines = 0;
        foreach ($x->xpath('cac:InvoiceLine') ?: [] as $line) {
            $qty = (float) $one($line, 'cbc:InvoicedQuantity');
            $ext = (float) $one($line, 'cbc:LineExtensionAmount');
            $desc = $one($line, 'cac:Item/cbc:Description') ?: '';
            $code = $one($line, 'cac:Item/cac:SellersItemIdentification/cbc:ID');
            $price = $qty > 0 ? round($ext / $qty, 6) : (float) $one($line, 'cac:Price/cbc:PriceAmount');
            $match = $code ? $products->first(fn ($p) => strcasecmp((string) $p->code, $code) === 0) : null;
            if (! $match) {
                $want = $tokens($desc);
                $best = null;
                $bestScore = 0;
                foreach ($products as $p) {
                    $have = $tokens($p->name);
                    if (! $have || ! $want) {
                        continue;
                    }
                    $common = count(array_intersect($want, $have));
                    // Every word of the invoice's line must be in the product's name; fewer extra words, bought before, near the last cost = better.
                    if ($common < count($want)) {
                        continue;
                    }
                    $score = $common / count($have) + (isset($bought[$p->id]) ? 1 : 0)
                        + ((float) $p->cost > 0 && abs((float) $p->cost - $price) < 0.005 ? 0.5 : 0);
                    if ($score > $bestScore) {
                        [$best, $bestScore] = [$p, $score];
                    }
                }
                $match = $best;
            }
            if (! $match) {
                $warnings[] = __('Product not found').": {$desc}";
            }
            if ($match && $match->type === 'is_service') {
                $serviceLines++;
            }
            $items[] = ['product_id' => $match->id ?? null, 'desc' => $desc, 'quantity' => $qty, 'price' => $price];
        }

        // The guide(s) it refers to: a receipt of this supplier with the same guide, not invoiced yet, is linked.
        $receiptIds = [];
        $guides = [];
        foreach ($x->xpath('cac:DespatchDocumentReference/cbc:ID') ?: [] as $g) {
            $guides[] = $gref = preg_replace('/\s+/', '', (string) $g);
            if ($provider && preg_match('/^([A-Z0-9]+)-0*(\d+)$/i', $gref, $m)) {
                $r = DB::table('b2b_receipts')->where('company_id', $cid)->where('provider_id', $provider->id)->whereNull('deleted_at')->whereNull('purchase_id')
                    ->get(['id', 'guide'])->first(fn ($r) => preg_match('/^\s*'.preg_quote($m[1], '/').'\s*-\s*0*'.(int) $m[2].'\s*$/i', (string) $r->guide));
                if ($r) {
                    $receiptIds[] = $r->id;
                } else {
                    $warnings[] = __('Guide without a receipt yet').": {$gref}";
                }
            }
        }

        return response()->json([
            'provider_id' => $provider->id ?? null, 'provider_name' => $supplierName, 'ruc' => $ruc,
            'series' => $series ? strtoupper(trim($series)) : null, 'number' => $number !== null ? (int) $number : null,
            'f_emision' => $issue, 'f_venc' => $due, 'currency' => $currency,
            'kind' => $items && $serviceLines === count($items) ? 'servicios' : 'bienes',
            'items' => $items, 'receipt_ids' => $receiptIds, 'guides' => $guides,
            'total' => (float) $one($x, 'cac:LegalMonetaryTotal/cbc:PayableAmount'), 'igv' => (float) $one($x, 'cac:TaxTotal/cbc:TaxAmount'),
            'warnings' => $warnings,
        ]);
    }

    public function voidPurchase(Request $request, int $id)
    {
        $this->allow($request, 'b2b_view');
        $cid = $this->company($request);
        $p = $this->purchaseQuery($cid)->where('p.id', $id)->first([DB::raw("COALESCE(i.status, 'PENDIENTE') as estado")]);
        abort_unless($p, 404);
        abort_unless($p->estado === 'PENDIENTE', 422, __('Only a pending invoice can be voided.'));
        DB::table('b2b_purchase_info')->updateOrInsert(['purchase_id' => $id], ['company_id' => $cid, 'status' => 'ANULADA', 'updated_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function deletePurchase(Request $request, int $id)
    {
        $this->allow($request, 'b2b_view');
        $cid = $this->company($request);
        abort_unless(DB::table('purchases')->where('company_id', $cid)->where('id', $id)->whereNull('deleted_at')->exists(), 404);
        DB::transaction(function () use ($cid, $id) {
            DB::table('b2b_receipts')->where('company_id', $cid)->where('purchase_id', $id)
                ->update(['purchase_id' => null, 'invoice' => null, 'invoice_date' => null, 'updated_at' => now()]);
            DB::table('purchase_details')->where('purchase_id', $id)->delete();
            DB::table('b2b_purchase_info')->where('purchase_id', $id)->delete();
            DB::table('purchases')->where('id', $id)->update(['deleted_at' => now(), 'updated_at' => now()]);
        });

        return response()->json(['ok' => true]);
    }

    /**
     * The guide form's stock light per line (Hordex's verificarSaldo, 2026-11-21): 2 = disponible (the stock covers it),
     * 1 = por preparar (it will be manufactured and the recipe's inputs cover the shortfall), 0 = sin stock (no recipe, or
     * the inputs don't cover it). Inputs are counted across all the lines, as saving the guide would consume them.
     */
    public function guideStockCheck(Request $request)
    {
        $this->allow($request, 'b2b_guides');
        $cid = $this->company($request);
        $d = $request->validate(['items' => 'array', 'items.*.product_id' => 'nullable|integer', 'items.*.quantity' => 'nullable|numeric']);
        $need = [];
        $out = [];
        foreach ($d['items'] ?? [] as $i => $it) {
            $pid = (int) ($it['product_id'] ?? 0);
            $qty = (float) ($it['quantity'] ?? 0);
            if (! $pid || $qty <= 0 || ! DB::table('products')->where('company_id', $cid)->where('id', $pid)->exists()) {
                $out[$i] = null;
                continue;
            }
            $stock = B2b::stock($pid);
            if ($stock >= $qty) {
                $out[$i] = ['status' => 2, 'stock' => $stock];
                continue;
            }
            $short = $qty - max($stock, 0);
            $recipe = DB::table('b2b_product_inputs')->where('company_id', $cid)->where('product_id', $pid)->get();
            $ok = $recipe->isNotEmpty();
            foreach ($recipe as $r) {
                $need[$r->input_id] = ($need[$r->input_id] ?? 0) + $short * (float) $r->quantity;
                if ($need[$r->input_id] > B2b::stock((int) $r->input_id) + 0.0005) {
                    $ok = false;
                }
            }
            $out[$i] = ['status' => $ok ? 1 : 0, 'stock' => $stock, 'short' => round($short, 3)];
        }

        return response()->json($out);
    }

    // ------------------------------------------------------------------ Compras = goods received (Hordex's ingresos, 2026-11-21)

    /** The earlier system's transport motives (its "Motivo" list on Compras), in its order. */
    private const MOTIVES = ['VENTA', 'AJUSTE', 'DEVOLUCION', 'FABRICACION', 'IMPORTACION', 'DE ACTIVO FIJO A STOCK', 'TRANSFERENCIA',
        'COMPRA LOCAL', 'OTROS', 'AJUSTE INVENTARIO', 'CONSUMO INTERNO', 'ADELANTO', 'SERVICIO'];

    public function receipts(Request $request)
    {
        $this->allow($request, 'b2b_view');
        $cid = $this->company($request);
        $q = DB::table('b2b_receipts as r')->leftJoin('providers as p', 'p.id', '=', 'r.provider_id')
            ->where('r.company_id', $cid)->whereNull('r.deleted_at')
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w->where('p.name', 'like', "%$s%")->orWhere('p.short_name', 'like', "%$s%")
                ->orWhere('r.guide', 'like', "%$s%")->orWhere('r.invoice', 'like', "%$s%")->orWhere('r.notes', 'like', "%$s%")))
            ->select('r.id', 'r.date', 'r.motive', 'r.kind', 'r.guide', 'r.invoice', 'r.notes',
                DB::raw("COALESCE(NULLIF(p.short_name, ''), p.name) as provider"));
        $request->merge(['SortType' => $request->SortType ?: 'desc']);
        [$rows, $total] = $this->page($q, $request, ['date' => 'r.date', 'provider' => 'p.name', 'motive' => 'r.motive'], 'r.date');

        return response()->json(['rows' => $rows, 'totalRows' => $total, 'motives' => self::MOTIVES,
            'providers' => DB::table('providers')->where('company_id', $cid)->whereNull('deleted_at')->whereNotNull('tax_number')
                ->orderByRaw("COALESCE(NULLIF(short_name, ''), name)")->get(['id', DB::raw("COALESCE(NULLIF(short_name, ''), name) as name")]),
            'products' => DB::table('products')->where('company_id', $cid)->whereNull('deleted_at')->orderBy('name')->get(['id', 'code', 'name'])]);
    }

    public function receipt(Request $request, int $id)
    {
        $this->allow($request, 'b2b_view');
        $cid = $this->company($request);
        $r = DB::table('b2b_receipts as r')->leftJoin('providers as p', 'p.id', '=', 'r.provider_id')->where('r.company_id', $cid)->where('r.id', $id)
            ->whereNull('r.deleted_at')->first(['r.*', DB::raw("COALESCE(NULLIF(p.short_name, ''), p.name) as provider")]);
        abort_unless($r, 404);
        $r->items = DB::table('b2b_receipt_items as i')->leftJoin('products as x', 'x.id', '=', 'i.product_id')->where('i.receipt_id', $id)->orderBy('i.id')
            ->get(['i.id', 'i.product_id', 'x.name', 'x.code', 'i.currency', 'i.price', 'i.quantity']);
        $r->moves_stock = DB::table('b2b_kardex')->where('receipt_id', $id)->exists();

        return response()->json($r);
    }

    /**
     * The guide number to fill in on a new receipt (2026-11-21: "ayúdalo rellenando el nro de guía acorde al último"): the
     * next after the supplier's last guide, keeping its series and zero padding (EG07-00295 -> EG07-00296).
     */
    public function receiptNextGuide(Request $request)
    {
        $this->allow($request, 'b2b_view');
        $cid = $this->company($request);
        $request->validate(['provider_id' => 'required|integer']);
        $last = DB::table('b2b_receipts')->where('company_id', $cid)->where('provider_id', $request->provider_id)->whereNull('deleted_at')
            ->whereNotNull('guide')->where('guide', '!=', '')->orderByDesc('date')->orderByDesc('id')->value('guide');
        $next = null;
        if ($last && preg_match('/^(.*?)(\d+)\s*$/', trim($last), $m)) {
            $next = $m[1].str_pad((string) ((int) $m[2] + 1), strlen($m[2]), '0', STR_PAD_LEFT);
        }

        return response()->json(['last' => $last, 'next' => $next]);
    }

    /** "Registrar Nueva Compra": only kind "compra" adds stock (COMPRA LOCAL), as in the earlier system. */
    public function storeReceipt(Request $request)
    {
        $this->allow($request, 'b2b_view');
        $cid = $this->company($request);
        $d = $request->validate([
            'date' => 'required|date|before_or_equal:today', 'motive' => ['nullable', Rule::in(self::MOTIVES)],
            'provider_id' => ['required', 'integer', Rule::exists('providers', 'id')->where('company_id', $cid)],
            'guide' => 'nullable|string|max:30', 'invoice' => 'nullable|string|max:30', 'kind' => 'required|in:compra,adelanto,servicio',
            'notes' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1', 'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('company_id', $cid)],
            'items.*.currency' => 'required|in:PEN,USD', 'items.*.price' => 'nullable|numeric|min:0', 'items.*.quantity' => 'required|numeric|gt:0',
        ], ['date.before_or_equal' => __('The receipt date cannot be in the future.')]);
        // Adelanto / servicio carry a price on every line; the invoice is typed only for them (as there).
        if ($d['kind'] !== 'compra') {
            abort_if(collect($d['items'])->contains(fn ($i) => empty($i['price'])), 422, __('Type the purchase price of every line.'));
        } else {
            $d['invoice'] = null;
        }
        abort_unless(! empty($d['guide']) || ! empty($d['invoice']), 422, __('Type the guide or the invoice.'));
        $dupe = DB::table('b2b_receipts')->where('company_id', $cid)->where('provider_id', $d['provider_id'])->whereNull('deleted_at')
            ->where(fn ($w) => $w->when(! empty($d['invoice']), fn ($q) => $q->where('invoice', $d['invoice']))
                ->when(! empty($d['guide']), fn ($q) => $q->orWhere('guide', $d['guide'])))->first();
        abort_if($dupe, 422, __('That document of this supplier is already registered.'));
        $motive = $d['motive'] ?? ['compra' => 'COMPRA LOCAL', 'adelanto' => 'ADELANTO', 'servicio' => 'SERVICIO'][$d['kind']];
        $id = DB::transaction(function () use ($cid, $d, $motive, $request) {
            $id = DB::table('b2b_receipts')->insertGetId([
                'company_id' => $cid, 'date' => $d['date'], 'motive' => $motive, 'kind' => $d['kind'], 'provider_id' => $d['provider_id'],
                'guide' => $d['guide'] ?? null, 'invoice' => $d['invoice'] ?? null, 'notes' => $d['notes'] ?? null,
                'user_id' => $request->user('api')->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $label = 'Compra '.(! empty($d['invoice']) ? 'Fact. '.$d['invoice'] : 'G.R. '.$d['guide']);
            $wh = B2b::warehouse($cid);
            foreach ($d['items'] as $i) {
                DB::table('b2b_receipt_items')->insert(['receipt_id' => $id, 'product_id' => $i['product_id'], 'currency' => $i['currency'],
                    'price' => $i['price'] ?? null, 'quantity' => $i['quantity'], 'created_at' => now(), 'updated_at' => now()]);
                if ($d['kind'] === 'compra') {
                    B2b::changeStock((int) $i['product_id'], (int) $wh, (float) $i['quantity']);
                    B2b::kardex($cid, (int) $i['product_id'], (float) $i['quantity'], 'COMPRA LOCAL', $label, isset($i['price']) ? (float) $i['price'] : null);
                    DB::table('b2b_kardex')->where('company_id', $cid)->where('product_id', $i['product_id'])->orderByDesc('id')->limit(1)->update(['receipt_id' => $id]);
                }
            }

            return $id;
        });

        return response()->json(['ok' => true, 'id' => $id]);
    }

    /** "Eliminar": the receipt, and its stock back out -- the later balances recomputed (there they were left wrong). */
    public function deleteReceipt(Request $request, int $id)
    {
        $this->allow($request, 'b2b_view');
        $cid = $this->company($request);
        abort_unless(DB::table('b2b_receipts')->where('company_id', $cid)->where('id', $id)->whereNull('deleted_at')->exists(), 404);
        DB::transaction(function () use ($cid, $id) {
            $moves = DB::table('b2b_kardex')->where('company_id', $cid)->where('receipt_id', $id)->get(['id', 'product_id', 'direction', 'quantity']);
            $wh = (int) B2b::warehouse($cid);
            foreach ($moves as $m) {
                B2b::changeStock((int) $m->product_id, $wh, ($m->direction === 'ingreso' ? -1 : 1) * (float) $m->quantity);
            }
            DB::table('b2b_kardex')->whereIn('id', $moves->pluck('id'))->delete();
            foreach ($moves->pluck('product_id')->unique() as $pid) {
                B2b::rebalance($cid, (int) $pid);
            }
            DB::table('b2b_receipts')->where('id', $id)->update(['deleted_at' => now(), 'updated_at' => now()]);
        });

        return response()->json(['ok' => true]);
    }

    // ------------------------------------------------------------------ masters: Productos / Proveedores / Clientes (Hordex, 2026-11-20)

    private const MASTERS = ['products', 'providers', 'clients'];

    /**
     * A client's addresses (its delivery points, the guides' arrival points) as a table, like Hordex's client detail
     * (2026-11-21: ADYGA EIRL has 2 and the form showed only the single `adresse` field).
     */
    private function clientInScope(Request $request, int $id): int
    {
        $this->allow($request, 'b2b_view');
        $cid = $this->company($request);
        abort_unless(DB::table('clients')->where('company_id', $cid)->where('id', $id)->whereNull('deleted_at')->exists(), 404);

        return $cid;
    }

    /**
     * RUC lookup (2026-11-21, the user chose a free service): SUNAT itself answers 403 to automated reads (that broke
     * Hordex's jossmp/sunat button), so we ask apis.net.pe v1 -- free, no token, few requests a minute (429 when busy).
     * Each answer is cached 30 days so a RUC is asked once. Returns razón social, state, condition and the fiscal address.
     */
    public function rucLookup(Request $request, string $ruc)
    {
        $this->allow($request, 'b2b_view', 'b2b_guides', 'b2b_invoice');
        abort_unless(preg_match('/^(10|15|16|17|20)\d{9}$/', $ruc), 422, __('A RUC has 11 digits and starts with 10, 15, 16, 17 or 20.'));
        $key = 'ruc_lookup:'.$ruc;
        if ($hit = \Illuminate\Support\Facades\Cache::get($key)) {
            return response()->json($hit + ['cached' => true]);
        }
        try {
            $r = \Illuminate\Support\Facades\Http::timeout(12)->acceptJson()->get('https://api.apis.net.pe/v1/ruc', ['numero' => $ruc]);
        } catch (\Throwable $e) {
            abort(503, __('The RUC lookup service did not answer. Try again in a moment.'));
        }
        abort_if($r->status() === 429, 429, __('The RUC lookup service is busy. Try again in a minute.'));
        $j = $r->json();
        abort_unless($r->successful() && ! empty($j['nombre']), 404, __('SUNAT has no taxpayer with that RUC.'));
        $clean = fn ($v) => ($v = trim((string) $v)) === '' || $v === '-' ? null : $v;
        $out = [
            'ruc' => $ruc, 'name' => $clean($j['nombre']), 'state' => $clean($j['estado'] ?? null), 'condition' => $clean($j['condicion'] ?? null),
            'address' => $clean($j['direccion'] ?? null), 'ubigeo' => $clean($j['ubigeo'] ?? null), 'district' => $clean($j['distrito'] ?? null),
            'province' => $clean($j['provincia'] ?? null), 'department' => $clean($j['departamento'] ?? null),
        ];
        \Illuminate\Support\Facades\Cache::put($key, $out, now()->addDays(30));

        return response()->json($out + ['cached' => false]);
    }

    /**
     * A product's card as in Hordex's earlier system (2026-11-21): stock ("Existencias"), its kind, the recipe with each
     * input's stock ("Insumos necesarios") and the last movements of its kardex (in / out, date, motive, qty, balance).
     */
    public function productCard(Request $request, int $id)
    {
        $this->allow($request, 'b2b_view');
        $cid = $this->company($request);
        $p = DB::table('products as p')->leftJoin('categories as c', 'c.id', '=', 'p.category_id')->leftJoin('units as u', 'u.id', '=', 'p.unit_id')
            ->where('p.company_id', $cid)->where('p.id', $id)->whereNull('p.deleted_at')->first(['p.id', 'p.name', 'p.code', 'c.name as kind', 'u.ShortName as unit']);
        abort_unless($p, 404);
        $p->stock = B2b::stock($id);
        $p->inputs = DB::table('b2b_product_inputs as i')->join('products as x', 'x.id', '=', 'i.input_id')
            ->where('i.company_id', $cid)->where('i.product_id', $id)->orderBy('x.name')->get(['i.input_id', 'x.name', 'i.quantity'])
            ->each(function ($r) { $r->stock = B2b::stock((int) $r->input_id); });
        $p->moves = DB::table('b2b_kardex')->where('company_id', $cid)->where('product_id', $id)
            ->orderByDesc('moved_at')->orderByDesc('id')->limit(200)
            ->get(['id', 'moved_at', 'direction', 'reason', 'reference', 'quantity', 'balance']);

        return response()->json($p);
    }

    /** "Ajustar" (2026-11-21): the stock counted -> the difference goes in and out of the kardex as AJUSTE INVENTARIO. */
    public function adjustProduct(Request $request, int $id)
    {
        $this->allow($request, 'b2b_view');
        $cid = $this->company($request);
        $d = $request->validate(['quantity' => 'required|numeric|min:0|max:99999999']);
        abort_unless(DB::table('products')->where('company_id', $cid)->where('id', $id)->whereNull('deleted_at')->exists(), 404);
        DB::transaction(function () use ($cid, $id, $d) {
            $wh = B2b::warehouse($cid);
            $rows = DB::table('product_warehouse')->where('product_id', $id)->whereNull('product_variant_id')->whereNull('deleted_at')->lockForUpdate()->get();
            $delta = (float) $d['quantity'] - (float) $rows->sum('qte');
            if (abs($delta) < 0.0005) {
                return;
            }
            $row = $rows->firstWhere('warehouse_id', $wh) ?? $rows->first();
            if ($row) {
                DB::table('product_warehouse')->where('id', $row->id)->update(['qte' => (float) $row->qte + $delta, 'updated_at' => now()]);
            } else {
                DB::table('product_warehouse')->insert(['product_id' => $id, 'warehouse_id' => $wh, 'qte' => $delta, 'manage_stock' => 1,
                    'created_at' => now(), 'updated_at' => now()]);
            }
            B2b::kardex($cid, $id, $delta, 'AJUSTE INVENTARIO', 'Ajuste manual');
        });

        return response()->json(['ok' => true, 'stock' => B2b::stock($id)]);
    }

    /** A client's contacts -- several people, as Hordex's earlier system (2026-11-21). */
    public function clientContacts(Request $request, int $id)
    {
        $cid = $this->clientInScope($request, $id);

        return response()->json(DB::table('b2b_client_contacts')->where('company_id', $cid)->where('client_id', $id)->whereNull('deleted_at')
            ->orderBy('id')->get(['id', 'name', 'area', 'phone', 'email']));
    }

    public function saveClientContact(Request $request, int $id, ?int $kid = null)
    {
        $cid = $this->clientInScope($request, $id);
        $d = $request->validate(['name' => 'required|string|max:190', 'area' => 'nullable|string|max:120', 'phone' => 'nullable|string|max:60',
            'email' => 'nullable|email|max:190'], ['name.required' => __('Type the contact name.')]);
        $d = array_map(fn ($v) => is_string($v) && trim($v) === '' ? null : (is_string($v) ? trim($v) : $v), $d);
        DB::transaction(function () use ($cid, $id, $kid, $d) {
            if ($kid) {
                $q = DB::table('b2b_client_contacts')->where('company_id', $cid)->where('client_id', $id)->where('id', $kid)->whereNull('deleted_at');
                abort_unless($q->exists(), 404);
                $q->update($d + ['updated_at' => now()]);
            } else {
                DB::table('b2b_client_contacts')->insert($d + ['company_id' => $cid, 'client_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->syncFirstContact($cid, $id);
        });

        return response()->json(['ok' => true]);
    }

    public function deleteClientContact(Request $request, int $id, int $kid)
    {
        $cid = $this->clientInScope($request, $id);
        DB::transaction(function () use ($cid, $id, $kid) {
            $n = DB::table('b2b_client_contacts')->where('company_id', $cid)->where('client_id', $id)->where('id', $kid)->whereNull('deleted_at')
                ->update(['deleted_at' => now(), 'updated_at' => now()]);
            abort_unless($n, 404);
            $this->syncFirstContact($cid, $id);
        });

        return response()->json(['ok' => true]);
    }

    /** clients.contact_name / phone / email = the first contact, for the screens that read one contact. */
    private function syncFirstContact(int $cid, int $clientId): void
    {
        $k = DB::table('b2b_client_contacts')->where('company_id', $cid)->where('client_id', $clientId)->whereNull('deleted_at')->orderBy('id')->first();
        DB::table('clients')->where('id', $clientId)->update(['contact_name' => $k->name ?? null, 'contact_phone' => $k->phone ?? null,
            'contact_email' => $k->email ?? null, 'updated_at' => now()]);
    }

    public function clientAddresses(Request $request, int $id)
    {
        $cid = $this->clientInScope($request, $id);

        return response()->json(DB::table('b2b_client_addresses')->where('company_id', $cid)->where('client_id', $id)->whereNull('deleted_at')
            ->orderBy('id')->get(['id', 'alias', 'address', 'ubigeo', 'district', 'province', 'department', 'sunat_annex']));
    }

    public function saveClientAddress(Request $request, int $id, ?int $aid = null)
    {
        $cid = $this->clientInScope($request, $id);
        $d = $request->validate([
            'address' => 'required|string|max:255', 'alias' => 'nullable|string|max:120', 'ubigeo' => 'nullable|string|max:6',
            'district' => 'nullable|string|max:100', 'province' => 'nullable|string|max:100', 'department' => 'nullable|string|max:100',
            'sunat_annex' => 'nullable|string|max:10',
        ]);
        $d = array_map(fn ($v) => is_string($v) ? (trim($v) === '' ? null : mb_strtoupper(trim($v))) : $v, $d);
        if ($aid) {
            $q = DB::table('b2b_client_addresses')->where('company_id', $cid)->where('client_id', $id)->where('id', $aid)->whereNull('deleted_at');
            abort_unless($q->exists(), 404);
            $q->update($d + ['updated_at' => now()]);
        } else {
            $aid = DB::table('b2b_client_addresses')->insertGetId($d + ['company_id' => $cid, 'client_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
        }

        return response()->json(['ok' => true, 'id' => $aid]);
    }

    public function deleteClientAddress(Request $request, int $id, int $aid)
    {
        $cid = $this->clientInScope($request, $id);
        $n = DB::table('b2b_client_addresses')->where('company_id', $cid)->where('client_id', $id)->where('id', $aid)->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
        abort_unless($n, 404);

        return response()->json(['ok' => true]);
    }

    private function masterQuery(string $type, int $cid)
    {
        if ($type === 'products') {
            return DB::table('products as p')->leftJoin('units as u', 'u.id', '=', 'p.unit_id')->where('p.company_id', $cid)->whereNull('p.deleted_at')
                ->select('p.id', 'p.code', 'p.name', 'p.price', 'p.type', 'p.unit_id', 'p.note', 'p.category_id', 'p.pack_size', 'u.ShortName as unit',
                    DB::raw('(select coalesce(sum(pw.qte), 0) from product_warehouse pw where pw.product_id = p.id and pw.deleted_at is null) as stock'));
        }

        // The earlier system lists only businesses with a RUC (no Walk-in / demo customers).
        return DB::table("$type as p")->where('p.company_id', $cid)->whereNull('p.deleted_at')->whereNotNull('p.tax_number')->where('p.tax_number', '!=', '')
            ->when($type === 'clients', fn ($q) => $q->leftJoin('b2b_client_info as ci', 'ci.client_id', '=', 'p.id'))
            ->select(array_merge(['p.id', 'p.tax_number', 'p.name', 'p.adresse', 'p.phone', 'p.email', 'p.contact_name'],
                $type === 'clients' ? ['p.short_name', 'ci.payment_term_id', DB::raw('COALESCE(ci.retention_agent, 0) as retention_agent')]
                    : ['p.short_name', 'p.city', 'p.zip']));
    }

    public function masters(Request $request, string $type)
    {
        $this->allow($request, 'b2b_view');
        $cid = $this->company($request);
        abort_unless(in_array($type, self::MASTERS, true), 404);
        $q = $this->masterQuery($type, $cid)->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w->where('p.name', 'like', "%$s%")
            ->orWhere($type === 'products' ? 'p.code' : 'p.tax_number', 'like', "%$s%")));
        $sortable = $type === 'products' ? ['code' => 'p.code', 'name' => 'p.name'] : ['tax_number' => 'p.tax_number', 'name' => 'p.name'];
        $request->merge(['SortType' => $request->SortType ?: 'asc']);
        [$rows, $total] = $this->page($q, $request, $sortable, 'p.name');

        return response()->json(['rows' => $rows, 'totalRows' => $total,
            'units' => $type === 'products' ? DB::table('units')->where(fn ($w) => $w->where('company_id', $cid)->orWhereNull('company_id'))
                ->whereNull('deleted_at')->orderBy('name')->get(['id', 'name', 'ShortName']) : [],
            'terms' => $type === 'clients' ? DB::table('b2b_payment_terms')->where('company_id', $cid)->orderBy('name')->get(['id', 'name']) : [],
            // Products: the kinds (Hordex's Tipo = the company's categories) and what can go in a recipe (inputs / packing).
            'categories' => $type === 'products' ? DB::table('categories')->where('company_id', $cid)->whereNull('deleted_at')->orderBy('id')->get(['id', 'name', 'code']) : [],
            'inputs' => $type === 'products' ? DB::table('products as p')->join('categories as c', 'c.id', '=', 'p.category_id')
                ->where('p.company_id', $cid)->whereNull('p.deleted_at')->whereIn('c.code', ['INS', 'EMB'])->orderBy('p.name')->get(['p.id', 'p.name', 'c.code as kind']) : []]);
    }

    public function saveMaster(Request $request, string $type, ?int $id = null)
    {
        $this->allow($request, 'b2b_view');
        $cid = $this->company($request);
        abort_unless(in_array($type, self::MASTERS, true), 404);
        if ($id) {
            abort_unless(DB::table($type)->where('company_id', $cid)->where('id', $id)->whereNull('deleted_at')->exists(), 404);
        }
        if ($type === 'products') {
            $d = $request->validate([
                'code' => ['required', 'string', 'max:60', Rule::unique('products', 'code')->where('company_id', $cid)->whereNull('deleted_at')->ignore($id)],
                'name' => 'required|string|max:190', 'unit_id' => 'nullable|integer|exists:units,id', 'price' => 'nullable|numeric|min:0',
                'type' => 'nullable|in:is_single,is_service', 'note' => 'nullable|string|max:1000',
                // Hordex's edit form (2026-11-21): Tipo = the category, Empaque, and the recipe (Receta).
                'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('company_id', $cid)],
                'pack_size' => 'nullable|integer|min:1|max:100000',
                'inputs' => 'nullable|array', 'inputs.*.input_id' => ['required', 'integer', Rule::exists('products', 'id')->where('company_id', $cid)],
                'inputs.*.quantity' => 'required|numeric|gt:0',
            ]);
            $cat = ! empty($d['category_id']) ? DB::table('categories')->where('id', $d['category_id'])->value('code') : null;
            $row = ['code' => strtoupper(trim($d['code'])), 'name' => mb_strtoupper(trim($d['name'])), 'unit_id' => $d['unit_id'] ?? null,
                'unit_sale_id' => $d['unit_id'] ?? null, 'unit_purchase_id' => $d['unit_id'] ?? null, 'price' => $d['price'] ?? 0,
                // A service (Tipo "Servicios") carries no stock; any other kind is a stocked product.
                'type' => $cat ? ($cat === 'SER' ? 'is_service' : 'is_single') : ($d['type'] ?? 'is_single'), 'note' => $d['note'] ?? null, 'updated_at' => now()]
                + ($request->has('category_id') ? ['category_id' => $d['category_id'] ?? null, 'not_selling' => in_array($cat, ['INS', 'EMB'], true) ? 1 : 0] : [])
                + ($request->has('pack_size') ? ['pack_size' => $d['pack_size'] ?? null] : []);
            $recipe = $request->has('inputs') ? collect($d['inputs'] ?? [])->filter(fn ($i) => (int) $i['input_id'] !== (int) $id)->unique('input_id')->values() : null;
            $id = DB::transaction(function () use ($id, $row, $cid, $recipe) {
                $saveRecipe = function (int $pid) use ($cid, $recipe) {
                    if ($recipe === null) {
                        return;
                    }
                    DB::table('b2b_product_inputs')->where('company_id', $cid)->where('product_id', $pid)->delete();
                    foreach ($recipe as $i) {
                        DB::table('b2b_product_inputs')->insert(['company_id' => $cid, 'product_id' => $pid, 'input_id' => (int) $i['input_id'],
                            'quantity' => (float) $i['quantity'], 'created_at' => now(), 'updated_at' => now()]);
                    }
                };
                if ($id) {
                    DB::table('products')->where('id', $id)->update($row);
                    $saveRecipe($id);

                    return $id;
                }
                $new = DB::table('products')->insertGetId($row + ['company_id' => $cid, 'Type_barcode' => 'CODE128', 'cost' => 0, 'TaxNet' => 0, 'tax_method' => '2',
                    'stock_alert' => 0, 'is_active' => 1, 'not_selling' => 0, 'is_variant' => 0, 'is_imei' => 0, 'created_at' => now()]);
                if ($row['type'] === 'is_single') {
                    DB::table('product_warehouse')->insert(['product_id' => $new, 'warehouse_id' => B2b::warehouse($cid), 'qte' => 0, 'manage_stock' => 1,
                        'created_at' => now(), 'updated_at' => now()]);
                }
                $saveRecipe($new);

                return $new;
            });

            return response()->json(['ok' => true, 'id' => $id]);
        }

        $d = $request->validate([
            'tax_number' => ['required', 'string', 'max:20', Rule::unique($type, 'tax_number')->where('company_id', $cid)->whereNull('deleted_at')->ignore($id)],
            'name' => 'required|string|max:190', 'short_name' => 'nullable|string|max:190', 'adresse' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:100', 'email' => 'nullable|email|max:190', 'contact_name' => 'nullable|string|max:190',
            'payment_term_id' => ['nullable', 'integer', Rule::exists('b2b_payment_terms', 'id')->where('company_id', $cid)],
            'retention_agent' => 'nullable|boolean',
            // A supplier's location (Hordex's "Ubicación": "district - province - department", zip = the ubigeo code).
            'city' => 'nullable|string|max:190', 'zip' => 'nullable|string|max:10',
        ], [
            // Clear words for the "Nuevo Cliente" line (2026-11-21: Laravel said "El campo tax number ya ha sido tomado").
            'tax_number.unique' => __('That RUC is already registered.'), 'tax_number.required' => __('Type the RUC.'),
            'name.required' => __('Type the company name (Razón Social).'),
        ]);
        $row = ['tax_number' => trim($d['tax_number']), 'name' => trim($d['name']), 'adresse' => $d['adresse'] ?? null, 'phone' => $d['phone'] ?? null,
            'email' => $d['email'] ?? null, 'updated_at' => now()]
            // A client's contact comes from its Contactos table once it exists (2026-11-21): the card no longer sends it.
            + ($request->has('contact_name') ? ['contact_name' => $d['contact_name'] ?? null] : [])
            + ['short_name' => $d['short_name'] ?? null]
            + ($type === 'providers' ? ['city' => $d['city'] ?? null, 'zip' => $d['zip'] ?? null] : []);
        // A client's card also keeps its payment condition and withholding-agent flag (b2b_client_info, 2026-11-21).
        $info = fn (int $clientId) => $type === 'clients' && ($request->has('payment_term_id') || $request->has('retention_agent'))
            ? DB::table('b2b_client_info')->updateOrInsert(['client_id' => $clientId], ['company_id' => $cid,
                'payment_term_id' => $d['payment_term_id'] ?? null, 'retention_agent' => ! empty($d['retention_agent']) ? 1 : 0,
                'updated_at' => now(), 'created_at' => now()])
            : null;
        $id = DB::transaction(function () use ($id, $row, $cid, $type, $info) {
            if ($id) {
                DB::table($type)->where('id', $id)->update($row);
                $info($id);

                return $id;
            }
            $new = DB::table($type)->insertGetId($row + ['company_id' => $cid, 'country' => 'Peru', 'code' => (int) DB::table($type)->max('code') + 1, 'created_at' => now()]);
            // Every customer / supplier has its login (user's rule, 2026-10-05).
            $userId = \App\Services\LinkedUserService::provision($cid, $type === 'clients' ? 'customer' : 'supplier',
                $type === 'clients' ? 'Customer' : 'Supplier', $row['name'], $row['email'], $row['phone']);
            DB::table($type)->where('id', $new)->update(['user_id' => $userId]);
            $info($new);

            return $new;
        });

        return response()->json(['ok' => true, 'id' => $id]);
    }

    // ------------------------------------------------------------------ reports (Hordex's five Excel reports, 2026-11-20)

    /**
     * GET b2b/reports/{type}?desde=&hasta=&producto_id=&proveedor_id= -> .xlsx with the earlier system's columns:
     * compras (purchase lines), ventas (invoices, or one product's lines), pagos (deductions / detracciones, sentido out),
     * cobros (bank deposits, sentido in), existencias (stock of each product).
     */
    public function report(Request $request, string $type)
    {
        $this->allow($request, 'b2b_view');
        $cid = $this->company($request);
        abort_unless(in_array($type, ['compras', 'ventas', 'pagos', 'cobros', 'existencias'], true), 404);
        $from = $request->desde ?: '2000-01-01';
        $to = $request->hasta ?: now()->toDateString();
        $d = fn ($v) => $v ? date('d/m/Y', strtotime((string) $v)) : '';
        $guideOf = fn ($saleId) => DB::table('b2b_guides')->where('sale_id', $saleId)->orderBy('number')->get(['series', 'number'])
            ->map(fn ($g) => B2b::ref($g->series, (int) $g->number))->implode(', ');
        $estado = fn ($s) => $s->statut === 'canceled' ? 'ANULADA' : ($s->payment_statut === 'paid' ? 'CANCELADA' : 'PENDIENTE');
        $rows = [];
        // Excel layout as Hordex's exports (2026-11-21): title lines above the header (a product's report), which columns
        // get a SUM in the "Total" row and where the word "Total" goes, which columns are 2-decimal numbers.
        $titles = [];
        $sum = [];
        $totalAt = null;
        $num = [];
        switch ($type) {
            case 'compras':
                // Compras = goods received (b2b_receipts), as its IngresosExport -- not the suppliers' invoices (2026-11-21:
                // ours listed the invoices' services and lines without a guide).
                $q = DB::table('b2b_receipt_items as i')->join('b2b_receipts as r', 'r.id', '=', 'i.receipt_id')->leftJoin('products as pr', 'pr.id', '=', 'i.product_id')
                    ->leftJoin('providers as v', 'v.id', '=', 'r.provider_id')->where('r.company_id', $cid)->whereNull('r.deleted_at')
                    ->whereBetween('r.date', [$from, $to])->when($request->producto_id, fn ($q, $v) => $q->where('i.product_id', $v))
                    ->when($request->proveedor_id, fn ($q, $v) => $q->where('r.provider_id', $v))->orderBy('r.date')->orderBy('r.id')->orderBy('i.id')
                    ->get(['r.date', 'pr.code', 'pr.name', 'i.quantity', 'i.price', 'i.currency', DB::raw("COALESCE(NULLIF(v.short_name, ''), v.name) as provider"), 'r.invoice', 'r.guide']);
                $cur = fn ($c) => $c === 'USD' ? 'DOL' : 'PEN';
                if ($request->producto_id) {
                    $p = DB::table('products')->where('id', $request->producto_id)->first(['code', 'name']);
                    $titles = ['PRODUCTO : '.($p->name ?? ''), 'CÓDIGO : '.($p->code ?? ''), 'PERIODO : '.$from.' al '.$to];
                    $head = ['Fecha', 'Cant.', 'Unit.', 'Monto', 'Moneda', 'Proveedor', 'Factura', 'Guía'];
                    foreach ($q as $r) {
                        $rows[] = [$d($r->date), (float) $r->quantity, $r->price !== null ? (float) $r->price : null, round($r->quantity * (float) $r->price, 2),
                            $cur($r->currency), $r->provider, $r->invoice, $r->guide];
                    }
                    [$sum, $totalAt, $num] = [['B', 'D'], 'A', ['B', 'C', 'D']];
                } else {
                    $head = ['Fecha', 'Código', 'Producto', 'Cant.', 'Unit.', 'Monto', 'Moneda', 'Proveedor', 'Factura', 'Guía'];
                    foreach ($q as $r) {
                        $rows[] = [$d($r->date), $r->code, $r->name, (float) $r->quantity, $r->price !== null ? (float) $r->price : null,
                            round($r->quantity * (float) $r->price, 2), $cur($r->currency), $r->provider, $r->invoice, $r->guide];
                    }
                    [$sum, $totalAt, $num] = [['F'], 'E', ['E', 'F']];
                }
                break;
            case 'ventas':
                if ($request->producto_id) {
                    $head = ['Factura', 'Emisión', 'Cliente', 'Guía', 'Cantidad', 'P.Unit', 'Total'];
                    $q = DB::table('sale_details as d')->join('sales as s', 's.id', '=', 'd.sale_id')->leftJoin('clients as c', 'c.id', '=', 's.client_id')
                        ->where('s.company_id', $cid)->whereNull('s.deleted_at')->where('d.product_id', $request->producto_id)
                        ->whereBetween('s.date', [$from, $to])->orderBy('s.date')->orderBy('s.id')->get(['s.id', 's.Ref', 's.date', 'c.name', 'd.quantity', 'd.price']);
                    foreach ($q as $r) {
                        $rows[] = [$r->Ref, $d($r->date), $r->name, $guideOf($r->id), (float) $r->quantity, round((float) $r->price, 2), round($r->quantity * $r->price, 2)];
                    }
                    $p = DB::table('products')->where('id', $request->producto_id)->first(['code', 'name']);
                    $titles = ['PRODUCTO : '.($p->name ?? ''), 'CÓDIGO : '.($p->code ?? ''), 'PERIODO : '.$from.' al '.$to];
                    [$sum, $totalAt, $num] = [['E', 'G'], 'D', ['E', 'F', 'G']];
                } else {
                    $head = ['Factura', 'Emisión', 'Cliente', 'Items', 'Guía', 'Subtotal', 'IGV', 'Total', 'Estado'];
                    $q = DB::table('sales as s')->leftJoin('clients as c', 'c.id', '=', 's.client_id')->where('s.company_id', $cid)->whereNull('s.deleted_at')
                        ->whereBetween('s.date', [$from, $to])->orderBy('s.date')->orderBy('s.id')
                        ->get(['s.id', 's.Ref', 's.date', 'c.name', 's.GrandTotal', 's.TaxNet', 's.statut', 's.payment_statut',
                            DB::raw('(SELECT COUNT(*) FROM sale_details x WHERE x.sale_id = s.id) as items')]);
                    foreach ($q as $r) {
                        $rows[] = [$r->Ref, $d($r->date), $r->name, (int) $r->items, $guideOf($r->id), round($r->GrandTotal - $r->TaxNet, 2), round((float) $r->TaxNet, 2),
                            round((float) $r->GrandTotal, 2), $estado($r)];
                    }
                    [$sum, $totalAt, $num] = [['F', 'G', 'H'], 'E', ['F', 'G', 'H']];
                }
                break;
            case 'pagos':
            case 'cobros':
                $head = ['Factura Hordex', 'Factura Cliente', 'Emitida', 'Concepto', 'Fecha abono', 'Monto abonado'];
                $q = DB::table('payment_sales as p')->join('sales as s', 's.id', '=', 'p.sale_id')->leftJoin('b2b_payment_info as i', 'i.payment_sale_id', '=', 'p.id')
                    ->leftJoin('b2b_concepts as k', 'k.id', '=', 'i.concept_id')->where('s.company_id', $cid)->whereNull('p.deleted_at')
                    ->whereBetween('p.date', [$from, $to])
                    ->where(fn ($w) => $type === 'cobros' ? $w->where('i.kind', 'deposit') : $w->where('i.kind', '!=', 'deposit'))
                    ->orderBy('p.date')->orderBy('p.id')->get(['s.Ref', 'i.document', 'i.document_date', 'k.name', 'p.notes', 'p.date', 'p.montant']);
                // "Factura Hordex": the company's first word, as the earlier system titled it.
                $head[0] = 'Factura '.ucfirst(strtolower(strtok((string) DB::table('companies')->where('id', $cid)->value('name'), ' ')));
                foreach ($q as $r) {
                    $rows[] = [$r->Ref, $r->document, $d($r->document_date), $r->name ?: $r->notes, $d($r->date), round((float) $r->montant, 2)];
                }
                [$sum, $totalAt, $num] = [['F'], 'E', ['F']];
                break;
            case 'existencias':
                $head = ['#', 'Código', 'Nombre', 'Saldo'];
                $q = DB::table('products as p')->leftJoin('product_warehouse as w', fn ($j) => $j->on('w.product_id', '=', 'p.id')->whereNull('w.deleted_at'))
                    ->where('p.company_id', $cid)->whereNull('p.deleted_at')->where('p.type', '!=', 'is_service')
                    ->groupBy('p.id', 'p.code', 'p.name')->orderBy('p.name')->get(['p.code', 'p.name', DB::raw('COALESCE(SUM(w.qte), 0) as saldo')]);
                foreach ($q->values() as $i => $r) {
                    $rows[] = [$i + 1, $r->code, $r->name, round((float) $r->saldo, 2)];
                }
                $num = ['D'];
                break;
        }

        return \Maatwebsite\Excel\Facades\Excel::download(new \App\Exports\B2bReportExport($head, $rows, $titles, $sum, $totalAt, $num), $type.'.xlsx');
    }

    // ------------------------------------------------------------------ setup

    private const SETUP = [
        'carriers' => ['b2b_carriers', ['ruc' => 'nullable|string|max:15', 'name' => 'required|string|max:190', 'driver_name' => 'nullable|string|max:150',
            'license' => 'nullable|string|max:30', 'plate' => 'nullable|string|max:15', 'brand' => 'nullable|string|max:60', 'model' => 'nullable|string|max:60', 'active' => 'boolean'], true],
        'terms' => ['b2b_payment_terms', ['code' => 'nullable|string|max:20', 'name' => 'required|string|max:120', 'days' => 'required|integer|min:0|max:720', 'active' => 'boolean'], false],
        'concepts' => ['b2b_concepts', ['client_id' => 'nullable|integer', 'name' => 'required|string|max:150', 'kind' => 'required|in:deposit,detraction,rebate,deduction',
            'percent' => 'required|numeric|min:0|max:100', 'active' => 'boolean'], false],
        'addresses' => ['b2b_client_addresses', ['client_id' => 'required|integer', 'alias' => 'nullable|string|max:120', 'address' => 'required|string|max:255',
            'ubigeo' => 'nullable|string|max:6', 'district' => 'nullable|string|max:100', 'province' => 'nullable|string|max:100', 'department' => 'nullable|string|max:100',
            'sunat_annex' => 'nullable|string|max:10'], true],
        'reasons' => ['b2b_transfer_reasons', ['sunat_code' => 'nullable|string|max:4', 'name' => 'required|string|max:150', 'direction' => 'required|in:out,in', 'active' => 'boolean'], false],
    ];

    public function setup(Request $request)
    {
        $this->allow($request, 'b2b_setup');
        $cid = $this->company($request);
        $out = [];
        foreach (self::SETUP as $type => [$table, , $soft]) {
            $q = DB::table($table)->when($soft, fn ($q) => $q->whereNull('deleted_at'));
            $q = $type === 'reasons' ? $q->where(fn ($w) => $w->whereNull('company_id')->orWhere('company_id', $cid)) : $q->where('company_id', $cid);
            $out[$type] = $q->orderBy('id')->get();
        }
        $out['settings'] = B2b::settings($cid);

        return response()->json($out);
    }

    public function saveSetup(Request $request, string $type, ?int $id = null)
    {
        $this->allow($request, 'b2b_setup');
        $cid = $this->company($request);
        abort_unless(isset(self::SETUP[$type]), 404);
        [$table, $rules] = self::SETUP[$type];
        $d = $request->validate($rules);
        if (isset($d['client_id']) && $d['client_id']) {
            abort_unless(DB::table('clients')->where('company_id', $cid)->where('id', $d['client_id'])->exists(), 422, __('Customer not found.'));
        }
        if ($id) {
            $n = DB::table($table)->where('id', $id)->where('company_id', $cid)->update($d + ['updated_at' => now()]);
            abort_unless($n || DB::table($table)->where('id', $id)->where('company_id', $cid)->exists(), 404);
        } else {
            $id = DB::table($table)->insertGetId($d + ['company_id' => $cid, 'created_at' => now(), 'updated_at' => now()]);
        }

        return response()->json(['ok' => true, 'id' => $id]);
    }

    public function deleteSetup(Request $request, string $type, int $id)
    {
        $this->allow($request, 'b2b_setup');
        $cid = $this->company($request);
        abort_unless(isset(self::SETUP[$type]), 404);
        [$table, , $soft] = self::SETUP[$type];
        $q = DB::table($table)->where('id', $id)->where('company_id', $cid);
        $soft ? $q->update(['deleted_at' => now()]) : $q->update(['active' => 0, 'updated_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
