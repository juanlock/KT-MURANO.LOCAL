<?php

namespace App\Services;

use App\Http\Controllers\KitchenOrderController;
use App\Http\Controllers\PosController;
use App\Models\DraftSale;
use App\Models\DraftSaleDetail;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An order sent from a Portfolio tablet catalog (2026-10-25), in ONE transaction:
 *  - portfolio_catalog_orders row (catalog's own number, table / name, lines, total);
 *  - a POS held order (draft_sales + details, prices / taxes from Products, the
 *    company's default location and walk-in customer) the cashier opens in the
 *    POS ("Recent drafts") and charges -- stock moves only then;
 *  - when the catalog sends to the kitchen and the company uses the Kitchen
 *    display, a kitchen ticket on that held order (source "tablet"); charging it
 *    in the POS moves the ticket to the sale (KitchenOrderController::movedToSale).
 */
class CatalogOrderService
{
    public static function place(object $c, array $s, array $v, ?string $ip): array
    {
        $cid = (int) $c->company_id;
        $es = $s['language'] === 'es';
        $qty = [];
        $notes = [];
        foreach ($v['lines'] as $l) {
            $qty[(int) $l['item_id']] = ($qty[(int) $l['item_id']] ?? 0) + (int) $l['qty'];
            if (! empty($l['note'])) {
                $notes[(int) $l['item_id']] = mb_substr(strip_tags($l['note']), 0, 200);
            }
        }
        $items = DB::table('portfolio_catalog_items as i')->join('products as p', 'p.id', '=', 'i.product_id')
            ->where('i.catalog_id', $c->id)->whereIn('i.id', array_keys($qty))
            ->where('p.company_id', $cid)->whereNull('p.deleted_at')
            ->get(['i.id as item_id', 'p.id', 'p.name', 'p.price', 'p.TaxNet', 'p.tax_method', 'p.unit_sale_id'])->keyBy('item_id');
        if ($items->count() !== count($qty)) {
            throw ValidationException::withMessages(['lines' => $es ? 'Algunos productos ya no están disponibles. Actualice la página.' : 'Some products are no longer available. Refresh the page.']);
        }

        $lines = [];
        $total = 0.0;
        foreach ($qty as $itemId => $q) {
            $p = $items[$itemId];
            $price = round((float) $p->price, 2);
            $tax = (float) $p->TaxNet;
            $exclusive = (string) $p->tax_method === '1';
            $sub = round($price * $q * ($exclusive ? 1 + $tax / 100 : 1), 2);
            $total += $sub;
            $lines[] = ['product_id' => (int) $p->id, 'name' => $p->name, 'price' => $price, 'qty' => $q, 'total' => $sub,
                'tax' => $tax, 'tax_method' => (string) ($p->tax_method ?? '1'), 'unit' => $p->unit_sale_id, 'note' => $notes[$itemId] ?? null];
        }
        $table = trim(strip_tags((string) ($v['table'] ?? '')));
        $orderNotes = trim(strip_tags((string) ($v['notes'] ?? '')));

        return DB::transaction(function () use ($c, $s, $cid, $lines, $total, $table, $orderNotes, $ip, $es) {
            $cat = DB::table('portfolio_catalogs')->where('id', $c->id)->lockForUpdate()->first();
            $number = (int) $cat->next_number;
            DB::table('portfolio_catalogs')->where('id', $c->id)->update(['next_number' => $number + 1]);

            $draftId = null;
            $kitchenId = null;
            if ($s['hold_in_pos'] || $s['send_kitchen']) {
                $set = Setting::forCompany($cid);
                $warehouse = (int) ($set->warehouse_id ?: DB::table('warehouses')->where('company_id', $cid)->whereNull('deleted_at')->orderBy('id')->value('id'));
                $client = (int) ($set->client_id ?: DB::table('clients')->where('company_id', $cid)->whereNull('deleted_at')->orderBy('id')->value('id'));
                // The held order needs an owner: the company's first active admin (cashiers with
                // "see every record" list it; the kitchen ticket names the tablet as source).
                $owner = (int) DB::table('users')->where('company_id', $cid)->whereRaw('LOWER(type) = ?', ['admin'])->where('statut', 1)->whereNull('deleted_at')->orderBy('id')->value('id');
                if (! $warehouse || ! $client || ! $owner) {
                    throw ValidationException::withMessages(['lines' => $es ? 'La empresa no tiene ubicación, cliente o administrador por defecto.' : 'The company has no default location, customer or admin.']);
                }
                $draft = new DraftSale;
                $draft->company_id = $cid;
                $draft->date = now();
                $draft->Ref = app(PosController::class)->getNumberOrderDraft();
                $draft->client_id = $client;
                $draft->warehouse_id = $warehouse;
                $draft->tax_rate = 0;
                $draft->TaxNet = 0;
                $draft->discount = 0;
                $draft->discount_Method = '2';
                $draft->shipping = 0;
                $draft->GrandTotal = round($total, 2);
                $draft->user_id = $owner;
                $draft->save();
                $draftId = (int) $draft->id;
                DraftSaleDetail::insert(array_map(fn ($l) => [
                    'date' => now(), 'draft_sale_id' => $draftId, 'sale_unit_id' => $l['unit'], 'quantity' => $l['qty'],
                    'product_id' => $l['product_id'], 'product_variant_id' => null, 'total' => $l['total'], 'price' => $l['price'],
                    'TaxNet' => $l['tax'], 'tax_method' => $l['tax_method'], 'discount' => 0, 'discount_method' => '2',
                    'imei_number' => null, 'price_type' => 'retail', 'product_pack_id' => null, 'pack_multiplier' => 1, 'pack_name' => null,
                    'created_at' => now(), 'updated_at' => now(),
                ], $lines));

                if ($s['send_kitchen'] && (bool) $set->enable_kitchen_display) {
                    $label = $s['table_label'].': '.($table !== '' ? $table : '-');
                    $lineNotes = collect($lines)->filter(fn ($l) => $l['note'])->map(fn ($l) => $l['name'].': '.$l['note'])->implode(' | ');
                    $instr = trim($label.($orderNotes !== '' ? ' — '.$orderNotes : '').($lineNotes !== '' ? ' — '.$lineNotes : ''));
                    $ticket = KitchenOrderController::createForDraft($draftId, mb_substr(($es ? 'Tablet ' : 'Tablet ').$c->name.' #'.$number.' · '.$instr, 0, 1000), 'tablet', $owner);
                    $kitchenId = $ticket?->id;
                }
            }

            DB::table('portfolio_catalog_orders')->insert([
                'company_id' => $cid, 'catalog_id' => $c->id, 'number' => $number, 'table_label' => $table !== '' ? mb_substr($table, 0, 60) : null,
                'notes' => $orderNotes !== '' ? mb_substr($orderNotes, 0, 500) : null,
                'lines' => json_encode(array_map(fn ($l) => array_intersect_key($l, array_flip(['product_id', 'name', 'price', 'qty', 'total', 'note'])), $lines), JSON_UNESCAPED_UNICODE),
                'total' => round($total, 2), 'draft_sale_id' => $draftId, 'kitchen_order_id' => $kitchenId,
                'status' => 'sent', 'ip' => $ip, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return ['number' => $number, 'draft_sale_id' => $draftId, 'kitchen_order_id' => $kitchenId, 'total' => round($total, 2)];
        });
    }
}
