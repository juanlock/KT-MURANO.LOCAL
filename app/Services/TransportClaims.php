<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard's "Complaints" tab (Turismo Cautivo, 2026-11-05): indicators from the company's complaints-book form in
 * the Forms module (form name with "reclam" / "complaint"). Counts and amounts only: names, documents, phones and
 * the complaint's text never leave this class.
 *
 * Rows imported from the old forms system have their answers one field off (FormsImportEasyForms): the Agency field
 * holds the reason, the ID-document field the agency, and so on. They are told apart by the document field not holding
 * a document type, and read through LEGACY_KEYS. The stored rows are left as they are.
 */
class TransportClaims
{
    private const LEGACY_KEYS = ['type' => 'selectlist_1', 'product' => 'selectlist_3', 'reason' => 'selectlist_5', 'agency' => 'selectlist_6', 'amount' => 'number_3'];

    private const LABELS = ['type' => '/^tipo/i', 'product' => '/^producto/i', 'reason' => '/una opci/i', 'agency' => '/agencia/i', 'document' => '/doc\.? *identidad/i', 'amount' => '/^monto/i'];

    /** The form's placeholder options: not an answer. */
    private const UNSPECIFIED = ['Tercera opción', 'Third Choice', 'Segunda opción', 'Second Choice', 'Primera opción', 'First Choice', ''];

    private const TOP = 10;

    /** @param string|null $from null = all the history */
    public static function compute(int $companyId, ?string $from, ?string $to): array
    {
        $forms = DB::table('forms')->where('company_id', $companyId)->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('name', 'like', '%reclam%')->orWhere('name', 'like', '%complaint%'))->get(['id', 'name', 'fields']);
        if ($forms->isEmpty()) {
            return ['enabled' => true, 'has_form' => false];
        }
        $ids = $forms->pluck('id')->all();
        $keys = $forms->mapWithKeys(fn ($f) => [$f->id => self::fieldKeys((array) json_decode($f->fields, true))]);

        $query = DB::table('form_submissions')->whereIn('form_id', $ids)->whereNull('deleted_at');
        $first = (clone $query)->min('created_at');
        $allHistory = ! $from; // nothing before it to compare with
        $from = $from ?: ($first ? substr($first, 0, 10) : now()->toDateString());
        $to = $to ?: now()->toDateString();
        $rows = (clone $query)->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to)->orderBy('created_at')
            ->get(['form_id', 'data', 'created_at', 'is_read', 'attended_at']);

        // The same number of days just before, to compare.
        $days = Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
        $previous = $allHistory ? null : (clone $query)->whereDate('created_at', '>=', Carbon::parse($from)->subDays($days)->toDateString())->whereDate('created_at', '<', $from)->count();

        $unit = $days <= 32 ? 'day' : ($days <= 185 ? 'week' : 'month');
        $types = ['claim' => 0, 'complaint' => 0, 'other' => 0];
        $reasons = $agencies = $products = $trend = $amounts = [];
        $attended = $unread = 0;
        $hours = [];
        foreach ($rows as $r) {
            $x = self::read($r, $keys[$r->form_id]);
            $types[$x['type']]++;
            self::bump($reasons, $x['reason']);
            self::bump($agencies, $x['agency']);
            self::bump($products, $x['product']);
            if ($x['amount'] > 0) {
                $amounts[] = $x['amount'];
            }
            $unread += $r->is_read ? 0 : 1;
            if ($r->attended_at) {
                $attended++;
                $hours[] = Carbon::parse($r->created_at)->diffInHours(Carbon::parse($r->attended_at));
            }
            $at = Carbon::parse($r->created_at);
            $bucket = match ($unit) {
                'day' => $at->toDateString(),
                'week' => $at->copy()->startOfWeek()->toDateString(),
                default => $at->format('Y-m').'-01',
            };
            $trend[$bucket] ??= ['date' => $bucket, 'claim' => 0, 'complaint' => 0, 'other' => 0];
            $trend[$bucket][$x['type']]++;
        }
        sort($amounts);

        return [
            'enabled' => true, 'has_form' => true, 'forms' => $forms->pluck('name')->all(), 'from' => $from, 'to' => $to,
            'total' => $rows->count(), 'previous' => $previous, 'types' => $types,
            // Median: the field holds typing errors (555,555), which would swamp a sum or an average.
            'amount' => ['count' => count($amounts), 'median' => $amounts ? $amounts[(int) (count($amounts) / 2)] : null],
            'attended' => $attended, 'unread' => $unread, 'avg_hours' => $hours ? round(array_sum($hours) / count($hours), 1) : null,
            'trend' => ['unit' => $unit, 'points' => array_values($trend)],
            'reasons' => self::ranked($reasons), 'agencies' => self::ranked($agencies), 'products' => self::ranked($products),
        ];
    }

    /** field => key in this form, by the field's label. */
    private static function fieldKeys(array $fields): array
    {
        $out = [];
        foreach ($fields as $f) {
            foreach (self::LABELS as $name => $pattern) {
                if (! isset($out[$name]) && preg_match($pattern, (string) ($f['label'] ?? ''))) {
                    $out[$name] = $f['key'];
                    if ($name === 'document') {
                        $out['documents'] = $f['options'] ?? [];
                    }
                }
            }
        }

        return $out;
    }

    private static function read(object $row, array $keys): array
    {
        $d = (array) json_decode($row->data, true);
        $document = $keys['document'] ?? null;
        $shifted = $document && ! empty($keys['documents']) && isset($d[$document]) && ! in_array($d[$document], $keys['documents'], true);
        $at = $shifted ? self::LEGACY_KEYS : $keys;
        $get = fn (string $name) => trim((string) (isset($at[$name]) && is_scalar($d[$at[$name]] ?? null) ? $d[$at[$name]] : ''));
        $type = mb_strtolower($get('type'));

        return [
            'type' => $type === 'reclamo' ? 'claim' : ($type === 'queja' ? 'complaint' : 'other'),
            'reason' => $get('reason'), 'agency' => $get('agency'), 'product' => $get('product'),
            'amount' => (float) $get('amount'),
        ];
    }

    private static function bump(array &$counts, string $value): void
    {
        $key = in_array($value, self::UNSPECIFIED, true) ? '' : $value;
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }

    /** The largest first; past TOP they go together. name '' = not specified, name null = the rest (the screen words them). */
    private static function ranked(array $counts): array
    {
        $blank = $counts[''] ?? 0;
        unset($counts['']);
        arsort($counts);
        $rows = [];
        foreach (array_slice($counts, 0, self::TOP, true) as $name => $n) {
            $rows[] = ['name' => (string) $name, 'n' => $n];
        }
        $rest = array_sum(array_slice($counts, self::TOP));
        if ($rest) {
            $rows[] = ['name' => null, 'n' => $rest];
        }
        if ($blank) {
            $rows[] = ['name' => '', 'n' => $blank];
        }

        return $rows;
    }
}
