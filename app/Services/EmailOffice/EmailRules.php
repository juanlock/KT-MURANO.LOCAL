<?php

namespace App\Services\EmailOffice;

use App\Models\OfficeEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Email Office rules (email_office_rules): the company's own sorting, e.g.
 * "From contains tiktok or facebook -> Social". Applied to each new email
 * after the automatic sorting (EmailOffice::record), so a rule wins; can also
 * be run on the emails already there. Rules run in order; every matching rule
 * applies (a later one can override an earlier one's type).
 */
class EmailRules
{
    public const FIELDS = ['from', 'from_name', 'subject', 'body', 'any'];

    public const OPS = ['contains', 'is', 'ends_with'];

    /** Active rules of a company, in order (cached per request). */
    public static function rules(?int $companyId): array
    {
        static $cache = [];
        if (! $companyId || ! Schema::hasTable('email_office_rules')) {
            return [];
        }

        return $cache[$companyId] ??= DB::table('email_office_rules')->where('company_id', $companyId)->where('active', true)
            ->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn ($r) => (object) ((array) $r + ['conds' => json_decode((string) $r->conditions, true) ?: []]))->all();
    }

    /** What a rule prepares: 'zelle_in' (paid sale), 'zelle_out' (expense) or null. */
    public static function prepareKind(object $rule): ?string
    {
        $p = json_decode((string) ($rule->prepare_sale ?? ''), true);

        return is_array($p) && $p ? ($p['kind'] ?? 'zelle_in') : null;
    }

    /** Does one rule match an email? */
    public static function matches(object $rule, object $e): bool
    {
        $conds = $rule->conds ?? (json_decode((string) $rule->conditions, true) ?: []);
        if (! $conds) {
            return false;
        }
        $hits = array_map(fn ($c) => self::condition($c, $e), $conds);

        return ($rule->match ?? 'any') === 'all' ? ! in_array(false, $hits, true) : in_array(true, $hits, true);
    }

    private static function condition(array $c, object $e): bool
    {
        $needle = mb_strtolower(trim((string) ($c['value'] ?? '')));
        if ($needle === '') {
            return false;
        }
        $values = match ($c['field'] ?? 'any') {
            'from' => [$e->from_email ?? ''],
            'from_name' => [trim((string) ($e->from_name ?? ''), '"')],
            'subject' => [$e->subject ?? ''],
            'body' => [mb_substr((string) ($e->body_text ?? ''), 0, 20000)],
            default => [$e->from_email ?? '', trim((string) ($e->from_name ?? ''), '"'), $e->subject ?? '', mb_substr((string) ($e->body_text ?? ''), 0, 20000)],
        };
        foreach ($values as $v) {
            $v = mb_strtolower(trim((string) $v));
            $ok = match ($c['op'] ?? 'contains') {
                'is' => $v === $needle,
                'ends_with' => str_ends_with($v, $needle),
                default => str_contains($v, $needle),
            };
            if ($ok) {
                return true;
            }
        }

        return false;
    }

    /** The changes a set of rules makes to an email ([] = none). */
    public static function changesFor(object $e, array $rules): array
    {
        $set = [];
        foreach ($rules as $r) {
            if (! self::matches($r, $e)) {
                continue;
            }
            if ($r->set_category && in_array($r->set_category, OfficeEmail::categoriesFor((int) ($e->company_id ?? $r->company_id)), true)) {
                $set['category'] = $r->set_category;
            }
            if ($r->mark_read) {
                $set['is_read'] = true;
            }
            // "Prepare ...": a Zelle deposit (-> paid sale) or a Zelle payment sent
            // (-> expense) not yet recorded waits for Confirm.
            if (! empty($r->prepare_sale) && empty($e->linked_type)) {
                $kind = self::prepareKind($r);
                if ($kind === 'zelle_in' && ZelleSale::parse($e->body_text ?? null)) {
                    $set['link_role'] = 'sale_draft';
                } elseif ($kind === 'zelle_out' && ZellePayment::parse($e->body_text ?? null)) {
                    $set['link_role'] = 'payment_draft';
                }
            }
            $set['_rules'][] = (int) $r->id;
        }

        return $set;
    }

    /** A new email: apply the company's rules (not to emails sent by the company). */
    public static function applyToNew(OfficeEmail $e): void
    {
        if ($e->direction !== 'in') {
            return;
        }
        $set = self::changesFor($e, self::rules((int) $e->company_id));
        $ids = $set['_rules'] ?? [];
        unset($set['_rules']);
        if ($set) {
            $e->update($set);
        }
        if ($ids) {
            DB::table('email_office_rules')->whereIn('id', $ids)->increment('applied_count');
        }
    }

    /**
     * One rule on the emails already there (received, type not set by hand,
     * not recorded in Murano). Returns how many changed.
     */
    public static function runOnExisting(object $rule): int
    {
        $n = 0;
        OfficeEmail::where('company_id', $rule->company_id)->where('direction', 'in')
            ->where('category_manual', false)->whereNull('linked_type')
            ->orderBy('id')->select(['id', 'company_id', 'direction', 'from_email', 'from_name', 'subject', 'body_text', 'category', 'is_read', 'linked_type', 'link_role'])
            ->chunkById(500, function ($rows) use ($rule, &$n) {
                foreach ($rows as $e) {
                    $set = self::changesFor($e, [$rule]);
                    unset($set['_rules']);
                    $set = array_filter($set, fn ($v, $k) => $e->{$k} != $v, ARRAY_FILTER_USE_BOTH);
                    if ($set) {
                        OfficeEmail::whereKey($e->id)->update($set);
                        $n++;
                    }
                }
            });
        if ($n) {
            DB::table('email_office_rules')->where('id', $rule->id)->increment('applied_count', $n);
        }

        return $n;
    }
}
