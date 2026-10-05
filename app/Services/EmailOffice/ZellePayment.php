<?php

namespace App\Services\EmailOffice;

use App\Models\Account;
use App\Models\Expense;
use App\Models\OfficeEmail;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Zelle payment sent ("Confirmed: Your payment to JESSI MARROQUIN is
 * complete": Confirmation Number / Amount / From <account ******6933> / To
 * <payee> (phone)) -> an expense of the company (money out):
 *
 *  - the payee is looked up in email_office_payees (company + payee): an
 *    employee paid as a 1099 contractor and / or the expense category
 *    (e.g. "Contract labor (1099)", "Professional services"); a new payee is
 *    mapped once in the Confirm window and remembered;
 *  - date: when the email arrived; paid with Zelle from the company account
 *    ending in the email's last 4; details carry the confirmation number.
 *
 * Never twice: an expense already carrying the confirmation number is linked
 * instead. Prepared by an email rule (prepare_sale.kind = zelle_out ->
 * link_role 'payment_draft'), created on Confirm or in bulk (artisan
 * email-office:zelle-sales).
 */
class ZellePayment
{
    /** amount, payee, confirmation, account text, last4 -- or null when it isn't a Zelle payment sent. */
    public static function parse(?string $body): ?array
    {
        $t = preg_replace('/\s+/u', ' ', (string) $body);
        // Two layouts: "...your payment to JESSI has finished processing" (sent) and
        // "Just confirming your payment to Jose." (sending, with an optional Message).
        if (! preg_match('/(your payment to .+? has finished processing|Just confirming your payment to )/i', $t)
            || ! preg_match('/Amount \$\s*([\d,]+(?:\.\d{1,2})?)/', $t, $a)
            // "To JESSI MARROQUIN (973-842-6167)" or "To Bonita Supply (name@gmail.com)"
            || ! (preg_match('/ To (.+?) \((?:\d[\d\-\s]*|[^()\s]+@[^()\s]+)\)/', $t, $to)
                || preg_match('/ To (.+?) (?:Message|As of|You can|Thank you)/', $t, $to))) {
            return null;
        }
        $memo = preg_match('/\) Message ?(.+?) (?:\S+ should get the money|As of|Thank you)/', $t, $mm) ? trim($mm[1]) : null;
        $amount = (float) str_replace(',', '', $a[1]);
        $payee = trim($to[1]);
        if ($amount <= 0 || $payee === '') {
            return null;
        }
        $conf = preg_match('/Confirmation Number ([A-Za-z0-9]+)/', $t, $c) ? $c[1] : null;
        $from = preg_match('/ From (.+?) To /', $t, $f) ? trim($f[1]) : '';

        return [
            'amount' => $amount,
            'payee' => $payee,
            'payee_key' => self::key($payee),
            'confirmation' => $conf,
            'account_text' => $from,
            'last4' => preg_match('/(\d{4})\D*$/', $from, $l) ? $l[1] : null,
            'memo' => $memo ? mb_substr($memo, 0, 120) : null,
        ];
    }

    public static function key(string $payee): string
    {
        return mb_substr(mb_strtolower(trim(preg_replace('/\s+/u', ' ', $payee))), 0, 190);
    }

    public static function mapping(int $cid, string $payeeKey): ?object
    {
        return DB::table('email_office_payees')->where('company_id', $cid)->where('payee', $payeeKey)->first();
    }

    /** Remember who a payee is (employee and / or category). */
    public static function remember(int $cid, string $payee, ?int $employeeId, int $categoryId): void
    {
        DB::table('email_office_payees')->updateOrInsert(
            ['company_id' => $cid, 'payee' => self::key($payee)],
            ['employee_id' => $employeeId ?: null, 'expense_category_id' => $categoryId, 'updated_at' => now(), 'created_at' => now()]
        );
    }

    /** What Confirm will do (for the Email Office window). */
    public static function preview(OfficeEmail $e): ?array
    {
        $p = self::parse($e->body_text);
        if (! $p) {
            return null;
        }
        $cid = (int) $e->company_id;
        $map = self::mapping($cid, $p['payee_key']);
        $at = Carbon::parse($e->sent_at ?? $e->created_at);
        $existing = $p['confirmation'] ? self::existing($cid, $p['confirmation']) : null;

        return $p + [
            'date' => $at->toDateString(),
            'time' => $at->format('H:i:s'),
            'employee_id' => $map?->employee_id,
            'expense_category_id' => $map?->expense_category_id,
            'mapped' => (bool) $map,
            'account' => self::account($cid, $p['last4'])?->account_name,
            'existing_expense' => $existing ? ['id' => $existing->id, 'Ref' => $existing->Ref] : null,
        ];
    }

    private static function existing(int $cid, string $conf): ?object
    {
        return DB::table('expenses as x')->join('warehouses as w', 'w.id', '=', 'x.warehouse_id')
            ->where('w.company_id', $cid)->whereNull('x.deleted_at')
            ->where('x.details', 'like', '%Zelle #'.$conf.'%')->first(['x.id', 'x.Ref']);
    }

    private static function account(int $cid, ?string $last4): ?Account
    {
        return $last4 ? Account::withoutGlobalScope('company')->where('company_id', $cid)->whereNull('deleted_at')
            ->where('account_num', 'like', '%'.$last4)->orderBy('id')->first() : null;
    }

    private static function employeeName(?int $id): ?string
    {
        return $id ? DB::table('employees')->where('id', $id)->selectRaw("TRIM(CONCAT(COALESCE(firstname,''),' ',COALESCE(lastname,''))) n")->value('n') : null;
    }

    /**
     * Create (or link) the expense of one email. $employeeId / $categoryId
     * (from the Confirm window) map the payee first. Returns ['expense_id',
     * 'Ref', 'created' => bool].
     */
    public static function confirm(OfficeEmail $e, int $userId, ?int $employeeId = null, ?int $categoryId = null): array
    {
        if ($e->linked_type === 'expense' && $e->linked_id) {
            return ['expense_id' => (int) $e->linked_id, 'Ref' => DB::table('expenses')->where('id', $e->linked_id)->value('Ref'), 'created' => false];
        }
        abort_if($e->linked_type, 422, 'This email is already recorded in Murano.');
        $p = self::parse($e->body_text);
        abort_unless($p, 422, 'This email is not a Zelle payment sent (amount / payee not found).');
        $cid = (int) $e->company_id;

        if ($categoryId) {
            abort_unless(DB::table('expense_categories')->where('id', $categoryId)->where('company_id', $cid)->whereNull('deleted_at')->exists(), 422, 'Pick an expense category of this company.');
            abort_if($employeeId && ! DB::table('employees')->where('id', $employeeId)->where('company_id', $cid)->whereNull('deleted_at')->exists(), 422, 'Pick an employee of this company.');
            self::remember($cid, $p['payee'], $employeeId, $categoryId);
        }
        $map = self::mapping($cid, $p['payee_key']);
        abort_unless($map, 422, "Who is \"{$p['payee']}\"? Pick the employee / expense category first.");

        // Already recorded (an earlier import / Confirm): link, don't create.
        if ($p['confirmation'] && ($existing = self::existing($cid, $p['confirmation']))) {
            self::link($e, (int) $existing->id, $map->employee_id ? (int) $map->employee_id : null);

            return ['expense_id' => (int) $existing->id, 'Ref' => $existing->Ref, 'created' => false];
        }

        $at = Carbon::parse($e->sent_at ?? $e->created_at);
        $warehouseId = (int) DB::table('warehouses')->where('company_id', $cid)->whereNull('deleted_at')->orderBy('id')->value('id');
        abort_unless($warehouseId, 422, 'The company has no warehouse / location.');
        $methodId = DB::table('payment_methods')->whereNull('deleted_at')->whereRaw('LOWER(name) = ?', ['zelle'])->value('id');
        $account = self::account($cid, $p['last4']);
        $emp = self::employeeName($map->employee_id ? (int) $map->employee_id : null);
        $details = 'Zelle #'.($p['confirmation'] ?: '-').' - '.$p['payee'].($emp ? " (1099 contractor: {$emp})" : '')
            .($p['memo'] ? ' - "'.$p['memo'].'"' : '');

        $expense = DB::transaction(function () use ($e, $p, $map, $at, $warehouseId, $methodId, $account, $details, $userId) {
            $expense = Expense::create([
                'user_id' => $userId, 'date' => $at->toDateString(),
                'Ref' => app(\App\Http\Controllers\ExpensesController::class)->getNumberOrder(),
                'payment_method_id' => $methodId, 'warehouse_id' => $warehouseId,
                'expense_category_id' => (int) $map->expense_category_id, 'account_id' => $account?->id,
                'details' => $details, 'amount' => $p['amount'],
            ]);
            if ($account) {
                $account->update(['balance' => $account->balance - $p['amount']]);
            }
            self::link($e, (int) $expense->id, $map->employee_id ? (int) $map->employee_id : null);

            return $expense;
        });

        return ['expense_id' => (int) $expense->id, 'Ref' => $expense->Ref, 'created' => true];
    }

    private static function link(OfficeEmail $e, int $expenseId, ?int $employeeId): void
    {
        EmailOffice::linkTo((int) $e->id, (int) $e->company_id, 'expense', $expenseId, $employeeId ? 'employee' : null, $employeeId);
        OfficeEmail::whereKey($e->id)->update(['link_role' => 'payment']);
        OfficeEmail::whereKey($e->id)->where('category_manual', false)->update(['category' => 'payment']);
    }
}
