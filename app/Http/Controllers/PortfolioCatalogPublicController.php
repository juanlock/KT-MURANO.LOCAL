<?php

namespace App\Http\Controllers;

use App\Services\CatalogOrderService;
use App\Support\ModuleFlags;
use App\Support\PortfolioCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Portfolio tablet catalogs -- the tablet page (2026-10-25), no login: the
 * tablet / iPad is handed to the customer.
 *
 *  GET  /c/{key}         full-screen catalog (menu or models)
 *  POST /c/{key}/order   {table, notes, lines: [{item_id, qty, model?}]} -> the order
 *                        (model = number of the item's model photo the customer picked)
 *                        (prices from Products, never from the tablet)
 * CSRF-exempt like the public forms (the page may live in a kiosk browser
 * without a session); throttled.
 */
class PortfolioCatalogPublicController extends Controller
{
    private function load(string $key): object
    {
        $c = DB::table('portfolio_catalogs')->where('public_key', $key)->whereNull('deleted_at')->first();
        abort_unless($c && $c->status && ModuleFlags::enabled((int) $c->company_id, 'portfolio'), 404);

        return $c;
    }

    public function show(string $key)
    {
        $c = $this->load($key);

        return response()->view('portfolio.catalog', ['data' => PortfolioCatalog::publicData($c), 'orderUrl' => url('/c/'.$c->public_key.'/order')])
            ->header('Cache-Control', 'no-store');
    }

    public function order(Request $request, string $key)
    {
        $c = $this->load($key);
        $s = PortfolioCatalog::settings(json_decode((string) $c->settings, true), $c->type);
        $es = $s['language'] === 'es';
        abort_unless($s['orders'], 403, $es ? 'Este catálogo no recibe pedidos.' : 'This catalog does not take orders.');
        $v = $request->validate([
            'table' => ($s['ask_table'] ? 'required' : 'nullable').'|string|max:60',
            'notes' => 'nullable|string|max:500',
            'lines' => 'required|array|min:1|max:60',
            'lines.*.item_id' => 'required|integer',
            'lines.*.qty' => 'required|integer|min:1|max:99',
            'lines.*.note' => 'nullable|string|max:200',
            'lines.*.model' => 'nullable|integer|min:1|max:60',
        ], ['table.required' => $es ? 'Escriba su '.mb_strtolower($s['table_label']).'.' : 'Enter your '.mb_strtolower($s['table_label']).'.']);

        $order = CatalogOrderService::place($c, $s, $v, $request->ip());

        return response()->json(['ok' => true, 'number' => $order['number'], 'message' => $s['thanks']]);
    }
}
