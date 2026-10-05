<?php

namespace App\Services\EmailOffice;

use App\Models\Account;
use App\Models\Client;
use App\Models\OfficeEmail;
use App\Models\PaymentSale;
use App\Models\Sale;
use App\Models\SaleDetail;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Zelle deposit email ("We deposited your payment": "...deposited the $120.00
 * payment from KATTY BUSTAMANTE (confirmation number 6270582159) into your
 * account (TD Bank, ..., ******6933)") -> a paid sale of the company:
 *
 *  - customer: the payer, Title Case, same exact name in the same company =
 *    same customer (Zelle gives no email), otherwise a new one -- never Walk-in;
 *  - one line: the rule's service (e.g. "No precisa") for the amount;
 *  - sales agent: the rule's (e.g. Silvia Becerra);
 *  - date / time: when the email arrived;
 *  - paid with Zelle into the company account ending in the email's last 4,
 *    the confirmation number in the payment's note.
 *
 * Never twice: a sale already carrying that confirmation number, or a sale of
 * the same amount at exactly the same date and time, is linked instead.
 * Prepared by an email rule with prepare_sale (link_role 'sale_draft'), created
 * on Confirm (Email Office) or in bulk (artisan email-office:zelle-sales).
 */
class ZelleSale
{
    private const RE = '/deposited the \$\s*([\d,]+(?:\.\d{1,2})?) payment from (.+?) \(confirmation number ([A-Za-z0-9]+)\)(?: into your account \((.+?)\))?/s';

    /** amount, payer (Title Case), confirmation, account text, last4 -- or null when it isn't a Zelle deposit. */
    public static function parse(?string $body): ?array
    {
        $text = preg_replace('/\s+/u', ' ', (string) $body);
        if (! preg_match(self::RE, $text, $m)) {
            return null;
        }
        $amount = (float) str_replace(',', '', $m[1]);
        $payer = trim($m[2]);
        if ($amount <= 0 || $payer === '') {
            return null;
        }
        $account = trim($m[4] ?? '');

        return [
            'amount' => $amount,
            'payer_raw' => $payer,
            'payer' => self::titleCase($payer),
            'confirmation' => $m[3],
            'account_text' => $account,
            'last4' => preg_match('/(\d{4})\D*$/', $account, $a) ? $a[1] : null,
        ];
    }

    /** "KATTY BUSTAMANTE" -> "Katty Bustamante" (an already mixed-case name is kept). */
    public static function titleCase(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name));

        return $name === mb_strtoupper($name) ? mb_convert_case(mb_strtolower($name), MB_CASE_TITLE, 'UTF-8') : $name;
    }

    /** The active rule of the company that prepares a sale for this email. */
    public static function ruleFor(object $e): ?object
    {
        foreach (EmailRules::rules((int) $e->company_id) as $r) {
            if (EmailRules::prepareKind($r) === 'zelle_in' && EmailRules::matches($r, $e)) {
                return $r;
            }
        }

        return null;
    }

    public static function params(?object $rule): array
    {
        return $rule ? (json_decode((string) $rule->prepare_sale, true) ?: []) : [];
    }

    /** What Confirm will do (for the Email Office window). */
    public static function preview(OfficeEmail $e): ?array
    {
        $p = self::parse($e->body_text);
        if (! $p) {
            return null;
        }
        $cid = (int) $e->company_id;
        $params = self::params(self::ruleFor($e));
        $at = Carbon::parse($e->sent_at ?? $e->created_at);
        $client = self::findClient($cid, $p['payer']);
        $existing = self::existingSale($cid, $p, $at);
        $account = self::account($cid, $p['last4']);

        return $p + [
            'date' => $at->toDateString(),
            'time' => $at->format('H:i:s'),
            'client_id' => $client?->id,
            'client_name' => $client?->name ?? $p['payer'],
            'client_new' => ! $client,
            'product' => isset($params['product_id']) ? DB::table('products')->where('id', $params['product_id'])->value('name') : null,
            'sales_agent' => isset($params['sales_agent_id']) ? DB::table('sales_agents')->where('id', $params['sales_agent_id'])->value('name') : null,
            'account' => $account?->account_name,
            'existing_sale' => $existing ? ['id' => $existing->id, 'Ref' => $existing->Ref] : null,
            'ready' => ! empty($params['product_id']),
        ];
    }

    private static function findClient(int $cid, string $name): ?object
    {
        return DB::table('clients')->where('company_id', $cid)->whereNull('deleted_at')
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)])->orderBy('id')->first(['id', 'name']);
    }

    /** A sale already recording this deposit (confirmation number, or same amount at the exact same date + time). */
    private static function existingSale(int $cid, array $p, Carbon $at): ?object
    {
        $byConf = DB::table('payment_sales as p')->join('sales as s', 's.id', '=', 'p.sale_id')
            ->where('s.company_id', $cid)->whereNull('s.deleted_at')->whereNull('p.deleted_at')
            ->where('p.notes', 'like', '%Zelle #'.$p['confirmation'].'%')->first(['s.id', 's.Ref']);
        if ($byConf) {
            return $byConf;
        }

        return DB::table('sales')->where('company_id', $cid)->whereNull('deleted_at')
            ->where('date', $at->toDateString())->where('time', $at->format('H:i:s'))
            ->where('GrandTotal', $p['amount'])->first(['id', 'Ref']);
    }

    private static function account(int $cid, ?string $last4): ?Account
    {
        if (! $last4) {
            return null;
        }

        return Account::withoutGlobalScope('company')->where('company_id', $cid)->whereNull('deleted_at')
            ->where('account_num', 'like', '%'.$last4)->orderBy('id')->first();
    }

    /**
     * Create (or link) the sale for one email. Returns ['sale_id', 'Ref',
     * 'created' => bool, 'client_created' => bool].
     */
    public static function confirm(OfficeEmail $e, int $userId): array
    {
        if ($e->linked_type === 'sale' && $e->linked_id) {
            return ['sale_id' => (int) $e->linked_id, 'Ref' => DB::table('sales')->where('id', $e->linked_id)->value('Ref'), 'created' => false, 'client_created' => false];
        }
        abort_if($e->linked_type, 422, 'This email is already recorded in Murano.');
        $p = self::parse($e->body_text);
        abort_unless($p, 422, 'This email is not a Zelle deposit (amount / payer not found).');
        $cid = (int) $e->company_id;
        $params = self::params(self::ruleFor($e));
        $productId = (int) ($params['product_id'] ?? 0);
        abort_unless($productId && DB::table('products')->where('id', $productId)->where('company_id', $cid)->whereNull('deleted_at')->exists(), 422,
            'Pick the service of the sale in the email rule ("Prepare a paid sale").');
        $agentId = (int) ($params['sales_agent_id'] ?? 0) ?: null;
        if ($agentId && ! DB::table('sales_agents')->where('id', $agentId)->where('company_id', $cid)->exists()) {
            $agentId = null;
        }
        $at = Carbon::parse($e->sent_at ?? $e->created_at);

        // Already in Murano (POS / by hand / an earlier import): link, don't create.
        if ($existing = self::existingSale($cid, $p, $at)) {
            $clientId = DB::table('sales')->where('id', $existing->id)->value('client_id');
            self::link($e, (int) $existing->id, $clientId ? (int) $clientId : null);

            return ['sale_id' => (int) $existing->id, 'Ref' => $existing->Ref, 'created' => false, 'client_created' => false];
        }

        $warehouseId = (int) (DB::table('product_warehouse as pw')->join('warehouses as w', 'w.id', '=', 'pw.warehouse_id')
            ->where('pw.product_id', $productId)->where('w.company_id', $cid)->whereNull('w.deleted_at')->value('w.id')
            ?: DB::table('warehouses')->where('company_id', $cid)->whereNull('deleted_at')->orderBy('id')->value('id'));
        abort_unless($warehouseId, 422, 'The company has no warehouse / location.');
        $methodId = DB::table('payment_methods')->whereNull('deleted_at')->whereRaw('LOWER(name) = ?', ['zelle'])->value('id');
        $account = self::account($cid, $p['last4']);
        $note = 'Zelle #'.$p['confirmation'].' - '.$p['payer_raw'].($p['account_text'] ? ' -> '.$p['account_text'] : '');

        $clientCreated = false;
        $sale = DB::transaction(function () use ($e, $p, $cid, $productId, $agentId, $at, $warehouseId, $methodId, $account, $note, $userId, &$clientCreated) {
            $client = self::findClient($cid, $p['payer']);
            if (! $client) {
                $parts = explode(' ', $p['payer'], 2);
                $client = new Client;
                $client->forceFill([
                    'company_id' => $cid, 'name' => $p['payer'], 'firstname' => $parts[0], 'lastname' => $parts[1] ?? null,
                    'code' => app(\App\Http\Controllers\ClientController::class)->getNumberOrder(),
                    'notes' => 'Created from a Zelle deposit email ('.$at->toDateString().').',
                ])->save();
                $clientCreated = true;
            }

            $sale = new Sale;
            $sale->forceFill([
                'is_pos' => 0, 'date' => $at->toDateString(), 'time' => $at->format('H:i:s'),
                'Ref' => app(\App\Http\Controllers\SalesController::class)->getNumberOrder(),
                'client_id' => $client->id, 'warehouse_id' => $warehouseId, 'company_id' => $cid,
                'GrandTotal' => $p['amount'], 'paid_amount' => 0, 'payment_statut' => 'unpaid', 'statut' => 'completed',
                'tax_rate' => 0, 'TaxNet' => 0, 'tax_method' => '1', 'discount' => 0, 'discount_Method' => '2', 'shipping' => 0,
                'notes' => 'Zelle deposit (email). '.$note,
                'user_id' => $userId, 'sales_agent_id' => $agentId,
            ]);
            if (\Illuminate\Support\Facades\Schema::hasColumn('sales', 'sale_uuid')) {
                $sale->sale_uuid = (string) Str::uuid();
            }
            $sale->save();

            SaleDetail::insert([
                'date' => $at->toDateString(), 'sale_id' => $sale->id, 'product_id' => $productId, 'product_variant_id' => null,
                'sale_unit_id' => null, 'quantity' => 1, 'price' => $p['amount'], 'total' => $p['amount'],
                'TaxNet' => 0, 'tax_method' => '1', 'discount' => 0, 'discount_method' => '2', 'price_type' => 'retail',
                'pack_multiplier' => 1,
            ]);

            PaymentSale::create([
                'sale_id' => $sale->id,
                'Ref' => app(\App\Http\Controllers\PaymentSalesController::class)->getNumberOrder(),
                'date' => $at, 'account_id' => $account?->id, 'payment_method_id' => $methodId,
                'montant' => $p['amount'], 'change' => 0, 'notes' => $note, 'user_id' => $userId,
            ]);
            if ($account) {
                $account->update(['balance' => $account->balance + $p['amount']]);
            }
            $sale->update(['paid_amount' => $p['amount'], 'payment_statut' => 'paid']);

            self::link($e, (int) $sale->id, (int) $client->id);

            return $sale;
        });

        try {
            app(\App\Services\CommissionService::class)->calculateForSale($sale->fresh());
        } catch (\Throwable $ex) {
            Log::warning('Zelle sale commission failed (non-blocking): '.$ex->getMessage(), ['sale_id' => $sale->id]);
        }

        return ['sale_id' => (int) $sale->id, 'Ref' => $sale->Ref, 'created' => true, 'client_created' => $clientCreated];
    }

    private static function link(OfficeEmail $e, int $saleId, ?int $clientId): void
    {
        EmailOffice::linkTo((int) $e->id, (int) $e->company_id, 'sale', $saleId, $clientId ? 'client' : null, $clientId);
        OfficeEmail::whereKey($e->id)->update(['link_role' => 'payment']);
        OfficeEmail::whereKey($e->id)->where('category_manual', false)->update(['category' => 'payment']);
    }
}
