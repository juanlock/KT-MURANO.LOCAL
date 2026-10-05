<?php

namespace App\Services\AccountingV2;

use Illuminate\Support\Facades\DB;

/**
 * The document a journal entry comes from (acc_journal_entries.reference_type
 * + reference_id): its company, a one-line label for the Journal Entries list
 * ("Sale SL_0019 · Customer") and the details shown in the "Source" modal,
 * with the page to open it.
 */
class JournalSource
{
    /** reference_type => [label, table] */
    public const TYPES = [
        'sale' => ['Sale', 'sales'],
        'sale_return' => ['Sale return', 'sale_returns'],
        'purchase' => ['Purchase', 'purchases'],
        'purchase_return' => ['Purchase return', 'purchase_returns'],
        'expense' => ['Expense', 'expenses'],
        'payment_sale' => ['Sale payment', 'payment_sales'],
        'payment_purchase' => ['Purchase payment', 'payment_purchases'],
        'payment_sale_return' => ['Sale return refund', 'payment_sale_returns'],
        'payment_purchase_return' => ['Purchase return refund', 'payment_purchase_returns'],
        'deposit' => ['Deposit', 'deposits'],
        'transfer' => ['Account transfer', 'transfer_money'],
        'cash_register_open' => ['Cash register opening', 'cash_registers'],
        'mrp_production_order' => ['Production order', 'mrp_production_orders'],
        'year_close' => ['Year close', null],
        'manual' => ['Manual entry', null],
    ];

    public static function label(?string $type): string
    {
        return self::TYPES[$type][0] ?? ucfirst(str_replace('_', ' ', (string) $type));
    }

    /** Company of the source document (null when it can't be told). */
    public static function companyOf(?string $type, $id): ?int
    {
        if (! $id || ! isset(self::TYPES[$type]) || ! self::TYPES[$type][1]) {
            return null;
        }
        $row = DB::table(self::TYPES[$type][1])->where('id', $id)->first();
        if (! $row) {
            return null;
        }
        $wh = fn ($w) => $w ? DB::table('warehouses')->where('id', $w)->value('company_id') : null;
        $cid = match ($type) {
            'sale', 'sale_return', 'purchase', 'purchase_return' => $row->company_id ?? $wh($row->warehouse_id ?? null),
            'expense', 'cash_register_open', 'mrp_production_order' => $wh($row->warehouse_id ?? null),
            'payment_sale' => DB::table('sales')->where('id', $row->sale_id)->value('company_id'),
            'payment_purchase' => DB::table('purchases')->where('id', $row->purchase_id)->value('company_id'),
            'payment_sale_return' => DB::table('sale_returns')->where('id', $row->sale_return_id)->value('company_id'),
            'payment_purchase_return' => DB::table('purchase_returns')->where('id', $row->purchase_return_id)->value('company_id'),
            default => null,
        };
        if (! $cid && isset($row->user_id)) {
            $cid = DB::table('users')->where('id', $row->user_id)->value('company_id');
        }

        return $cid ? (int) $cid : null;
    }

    /**
     * Short text for the list: "Sale SL_0019 · Walk-in Customer".
     *
     * @param  array<int, array{type: string, id: int}>  $refs
     * @return array<string, string> "type:id" => text
     */
    public static function summaries(array $refs): array
    {
        $out = [];
        foreach (collect($refs)->groupBy('type') as $type => $group) {
            foreach ($group as $r) {
                $d = self::describe($type, $r['id'], false);
                if ($d) {
                    // "Customer: X" -- the party's role, so a customer's name is
                    // never read as the company that made the sale.
                    $party = $d['party'] ? ($d['party_role'] ? "{$d['party_role']}: {$d['party']}" : $d['party']) : '';
                    $out["{$type}:{$r['id']}"] = trim($d['title'].($party ? ' · '.$party : ''));
                }
            }
        }

        return $out;
    }

    /**
     * The source for the modal: title, ref, date, party, amount, status, the
     * page to open, and its lines (sales / purchases / returns).
     */
    public static function describe(?string $type, $id, bool $withLines = true): ?array
    {
        if (! $id || ! isset(self::TYPES[$type]) || ! self::TYPES[$type][1]) {
            return null;
        }
        $row = DB::table(self::TYPES[$type][1])->where('id', $id)->first();
        if (! $row) {
            return ['title' => self::label($type).' #'.$id.' (deleted)', 'type' => $type, 'type_label' => self::label($type), 'ref' => null, 'party' => null, 'fields' => [], 'link' => null, 'lines' => []];
        }
        $client = fn ($cid) => $cid ? DB::table('clients')->where('id', $cid)->value('name') : null;
        $provider = fn ($pid) => $pid ? DB::table('providers')->where('id', $pid)->value('name') : null;
        $account = fn ($aid) => $aid ? DB::table('accounts')->where('id', $aid)->value('account_name') : null;
        $warehouse = fn ($wid) => $wid ? DB::table('warehouses')->where('id', $wid)->value('name') : null;

        $ref = $row->Ref ?? $row->reference ?? ('#'.$row->id);
        $party = null;
        $link = null;
        $fields = [];
        $lines = [];
        switch ($type) {
            case 'sale':
            case 'sale_return':
                $party = $client($row->client_id ?? null);
                $link = $type === 'sale' ? "/sales/{$row->id}" : "/sale-returns/{$row->id}";
                $fields = ['Date' => $row->date, 'Customer' => $party, 'Location' => $warehouse($row->warehouse_id ?? null), 'Total' => $row->GrandTotal, 'Status' => $row->statut, 'Payment' => $row->payment_statut];
                if ($withLines) {
                    $lines = DB::table($type === 'sale' ? 'sale_details' : 'sale_return_details')->where($type === 'sale' ? 'sale_id' : 'sale_return_id', $row->id)
                        ->leftJoin('products', 'products.id', '=', 'product_id')->get(['products.name', 'quantity', 'price', 'total'])->map(fn ($l) => (array) $l)->all();
                }
                break;
            case 'purchase':
            case 'purchase_return':
                $party = $provider($row->provider_id ?? null);
                $link = $type === 'purchase' ? "/purchases/{$row->id}" : "/purchase-returns/{$row->id}";
                $fields = ['Date' => $row->date, 'Supplier' => $party, 'Location' => $warehouse($row->warehouse_id ?? null), 'Total' => $row->GrandTotal, 'Status' => $row->statut, 'Payment' => $row->payment_statut];
                if ($withLines) {
                    $lines = DB::table($type === 'purchase' ? 'purchase_details' : 'purchase_return_details')->where($type === 'purchase' ? 'purchase_id' : 'purchase_return_id', $row->id)
                        ->leftJoin('products', 'products.id', '=', 'product_id')->get(['products.name', 'quantity', 'cost as price', 'total'])->map(fn ($l) => (array) $l)->all();
                }
                break;
            case 'expense':
                $cat = DB::table('expense_categories')->where('id', $row->expense_category_id ?? 0)->value('name');
                $party = $cat;
                $link = "/expenses/{$row->id}/edit";
                $fields = ['Date' => $row->date, 'Category' => $cat, 'Details' => $row->details ?? null, 'Location' => $warehouse($row->warehouse_id ?? null), 'Account' => $account($row->account_id ?? null), 'Amount' => $row->amount];
                break;
            case 'payment_sale':
            case 'payment_purchase':
            case 'payment_sale_return':
            case 'payment_purchase_return':
                [$docTable, $fk, $docLink, $partyCol] = [
                    'payment_sale' => ['sales', 'sale_id', '/sales/', 'client'],
                    'payment_purchase' => ['purchases', 'purchase_id', '/purchases/', 'provider'],
                    'payment_sale_return' => ['sale_returns', 'sale_return_id', '/sale-returns/', 'client'],
                    'payment_purchase_return' => ['purchase_returns', 'purchase_return_id', '/purchase-returns/', 'provider'],
                ][$type];
                $doc = DB::table($docTable)->where('id', $row->{$fk})->first();
                $party = $doc ? ($partyCol === 'client' ? $client($doc->client_id ?? null) : $provider($doc->provider_id ?? null)) : null;
                $link = $doc ? $docLink.$doc->id : null;
                $method = DB::table('payment_methods')->where('id', $row->payment_method_id ?? 0)->value('name');
                $fields = ['Date' => $row->date, 'For' => $doc->Ref ?? null, 'Party' => $party, 'Amount' => $row->montant, 'Method' => $method, 'Account' => $account($row->account_id ?? null)];
                break;
            case 'deposit':
                $link = "/deposits/{$row->id}/edit";
                $fields = ['Date' => $row->date, 'Account' => $account($row->account_id ?? null), 'Amount' => $row->amount];
                break;
            case 'transfer':
                $link = '/transfer-money';
                $fields = ['Date' => $row->date, 'From account' => $account($row->from_account_id ?? null), 'To account' => $account($row->to_account_id ?? null), 'Amount' => $row->amount];
                break;
            case 'cash_register_open':
                $party = DB::table('users')->where('id', $row->user_id ?? 0)->value('username');
                $fields = ['Opened' => $row->opened_at, 'Cashier' => $party, 'Location' => $warehouse($row->warehouse_id ?? null), 'Opening cash' => $row->opening_balance];
                break;
            case 'mrp_production_order':
                $ref = $row->reference ?? $ref;
                $link = "/mrp/production-orders/{$row->id}";
                $fields = ['Order' => $ref, 'Location' => $warehouse($row->warehouse_id ?? null)];
                break;
        }

        $partyRole = match ($type) {
            'sale', 'sale_return', 'payment_sale', 'payment_sale_return' => 'Customer',
            'purchase', 'purchase_return', 'payment_purchase', 'payment_purchase_return' => 'Supplier',
            'expense' => 'Category',
            'cash_register_open' => 'Cashier',
            default => null,
        };

        return [
            'type' => $type,
            'type_label' => self::label($type),
            'title' => self::label($type).' '.$ref,
            'ref' => $ref,
            'party' => $party,
            'party_role' => $partyRole,
            'fields' => array_filter($fields, fn ($v) => $v !== null && $v !== ''),
            'link' => $link,
            'lines' => $lines,
        ];
    }
}
