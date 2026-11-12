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
 * a document type, and read through LEGACY_KEYS. Since 2026-11-10 the 337 rows are corrected (migration
 * 2026_11_10_160000, the original kept in form_submissions.notes); LEGACY_KEYS stays only as a fallback.
 */
class TransportClaims
{
    private const LEGACY_KEYS = ['type' => 'selectlist_1', 'product' => 'selectlist_3', 'reason' => 'selectlist_5', 'agency' => 'selectlist_6', 'amount' => 'number_3', 'detail' => 'textarea_1', 'request' => 'textarea_2'];

    private const LABELS = ['type' => '/^tipo/i', 'product' => '/^producto/i', 'reason' => '/una opci/i', 'agency' => '/agencia/i', 'document' => '/doc\.? *identidad/i', 'amount' => '/^monto/i', 'detail' => '/^detalle/i', 'request' => '/^pedido/i'];

    /** The form's own reasons, grouped (2026-11-09: the user found the list too long to read). */
    private const REASON_GROUPS = [
        'service' => ['/atenci(o|ó)n/iu'],
        'luggage' => ['/equipaje|pertenc|encomienda/i'],
        'schedule' => ['/horario/i'],
        'boarding' => ['/embarque|recojo/i'],
        'sales' => ['/venta|asiento sin autoriz/i'],
        'bus' => ['/comida/i'],
    ];

    /** The reason "Otros reclamos" says nothing: its topic comes from the words of the detail (first match wins). */
    private const OTHER = '/^otros? reclamos?$/i';

    private const TOPICS = [
        'safety' => ['safety', '/alcohol|bebida|delincuen|pandiller|seguridad|borrach|ca[ií]da|accidente|herid|robo|asalt/'],
        'test' => ['other', '/^(test|prueba|h+j+)\b/'],
        'pests' => ['bus', '/cucarach|chinche|chiche|acaro|garrapat|picadur|picazon|pulga/'],
        'seat' => ['bus', '/asiento.{0,60}(reclin|averiad|defect|mal estado|malogr|roto|rechina)|no (se )?reclin|se regresa/'],
        'bus_change' => ['bus', '/cambio de bus|bus (otorgado|ofrecido)|no es el (bus )?ofrecido|master|confort|premium|bus cama/'],
        'breakdown' => ['bus', '/descompuest|averi[ao]|mecanico|desperfecto|malogr|se malo|se quedo en (la )?(ruta|carretera)|varados/'],
        'clean' => ['bus', '/bano|limpieza|aseo|apesta|sucio|aire acondicionado|gotera|calor|frio/'],
        'equipment' => ['bus', '/luces|\busb\b|cargador|enchuf|lluvia|me moje|antiguo|wifi|televis|pantalla/'],
        'parcel' => ['luggage', '/encomienda|equipaje|maleta/'],
        'invoice' => ['sales', '/factura|comprobante|boleta electronica/'],
        'promo' => ['sales', '/promocion|gratis/'],
        'money' => ['sales', '/vuelto|cobr(o|aron)|cobro/'],
        'price' => ['sales', '/\bcaro\b|precio|desigualdad/'],
        'online' => ['sales', '/internet|pagina web|via web|\bweb\b|online|en linea|pago con tarjeta|redbus/'],
        'refund' => ['sales', '/cambio de (fecha|pasaje)|cambiar (la )?fecha|devoluci|reemb|cancelaron|reprogram|postergar|devuelvan|devolver/'],
        'missed' => ['schedule', '/ya se habia ido|perdi(o|do)? (ayer )?(mi|su|el) (viaje|bus|pasaje)|llegue tarde|llegamos tarde|tolerancia|me dejo el bus|nos dejo el bus|negativa de embarque/'],
        'delay' => ['schedule', '/sal(io|ia) .{0,30}(despues|tarde)|retraso|demora|horas? de (espera|retraso)|llego tarde|incumplimiento.{0,20}horario/'],
        'stop' => ['boarding', '/desembar|paradero|bajar|embarque|recojo|recoger/'],
        'staff' => ['service', '/trato|grit|mala manera|grosera?|mal(a)? atencion|maltrat|prepoten/'],
    ];

    /** The form's placeholder options: not an answer. */
    private const UNSPECIFIED = ['Tercera opción', 'Third Choice', 'Segunda opción', 'Second Choice', 'Primera opción', 'First Choice', ''];

    private const TOP = 10;

    /**
     * @param string|null $from null = all the history
     * @param array|null $places agency / terminal names (2026-11-11, a supervisor's own agency): only the complaints that name one of them
     */
    public static function compute(int $companyId, ?string $from, ?string $to, ?array $places = null): array
    {
        $places = $places === null ? null : array_map(fn ($p) => mb_strtoupper(trim($p)), $places);
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
        $mine = fn ($r) => $places === null || in_array(mb_strtoupper((string) self::read($r, $keys[$r->form_id])['agency']), $places, true);
        if ($places !== null) {
            $rows = $rows->filter($mine)->values();
        }
        $previous = null;
        if (! $allHistory) {
            $prevQuery = (clone $query)->whereDate('created_at', '>=', Carbon::parse($from)->subDays($days)->toDateString())->whereDate('created_at', '<', $from);
            $previous = $places === null ? $prevQuery->count() : $prevQuery->get(['form_id', 'data', 'created_at', 'is_read', 'attended_at'])->filter($mine)->count();
        }

        $unit = $days <= 32 ? 'day' : ($days <= 185 ? 'week' : 'month');
        $types = ['claim' => 0, 'complaint' => 0, 'other' => 0];
        $reasons = $agencies = $products = $trend = $amounts = $groups = [];
        $attended = $unread = 0;
        $hours = [];
        foreach ($rows as $r) {
            $x = self::read($r, $keys[$r->form_id]);
            $types[$x['type']]++;
            self::bump($reasons, $x['reason']);
            [$group, $detail] = self::group($x);
            $groups[$group][$detail] = ($groups[$group][$detail] ?? 0) + 1;
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
            'reasons' => self::ranked($reasons), 'groups' => self::groups($groups), 'agencies' => self::ranked($agencies), 'products' => self::ranked($products),
        ];
    }

    /**
     * The complaints about parcels (the Parcels tab, 2026-11-09): product "Encomiendas", a parcel reason, or an
     * "Otros reclamos" whose words are about a parcel. Counts only. $agency = the agency's name (the form's option).
     */
    public static function parcels(int $companyId, string $from, string $to, ?array $places = null): array
    {
        $places = $places === null ? null : array_map(fn ($p) => mb_strtoupper(trim($p)), $places);
        $forms = DB::table('forms')->where('company_id', $companyId)->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('name', 'like', '%reclam%')->orWhere('name', 'like', '%complaint%'))->get(['id', 'fields']);
        $out = ['has_form' => $forms->isNotEmpty(), 'total' => 0, 'reasons' => [], 'agencies' => []];
        if ($forms->isEmpty()) {
            return $out;
        }
        $keys = $forms->mapWithKeys(fn ($f) => [$f->id => self::fieldKeys((array) json_decode($f->fields, true))]);
        $rows = DB::table('form_submissions')->whereIn('form_id', $keys->keys()->all())->whereNull('deleted_at')
            ->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to)->get(['form_id', 'data']);
        foreach ($rows as $r) {
            $x = self::read($r, $keys[$r->form_id]);
            [$group, $detail] = self::group($x);
            $about = preg_match('/encomienda/i', $x['product'].' '.$x['reason']) || $detail === 'topic:parcel';
            if (! $about || ($places !== null && ! in_array(mb_strtoupper($x['agency']), $places, true))) {
                continue;
            }
            $out['total']++;
            $name = preg_match('/encomienda/i', $x['reason']) ? $x['reason'] : ($group === 'unspecified' ? '' : $detail);
            $out['reasons'][$name] = ($out['reasons'][$name] ?? 0) + 1;
            $a = in_array($x['agency'], self::UNSPECIFIED, true) ? '' : mb_strtoupper($x['agency']);
            $out['agencies'][$a] = ($out['agencies'][$a] ?? 0) + 1;
        }
        arsort($out['reasons']);
        arsort($out['agencies']);
        $out['reasons'] = array_map(fn ($n, $c) => ['name' => (string) $n, 'n' => $c], array_keys($out['reasons']), $out['reasons']);
        $out['agencies'] = array_map(fn ($n, $c) => ['name' => (string) $n, 'n' => $c], array_keys($out['agencies']), $out['agencies']);

        return $out;
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
            // Read only to sort "Otros reclamos" by topic here; never returned.
            'text' => trim($get('detail').' '.$get('request')),
        ];
    }

    /**
     * [group, detail]: detail = the form's reason, or "topic:<key>" for an "Otros reclamos" sorted by its words,
     * "topic:none" when no word matched, "" when no reason was picked.
     */
    private static function group(array $x): array
    {
        $reason = in_array($x['reason'], self::UNSPECIFIED, true) ? '' : $x['reason'];
        if ($reason === '') {
            return ['unspecified', ''];
        }
        if (preg_match(self::OTHER, $reason)) {
            $text = mb_strtolower(\Illuminate\Support\Str::ascii($x['text']));
            foreach (self::TOPICS as $topic => [$group, $pattern]) {
                if ($text !== '' && preg_match($pattern, $text)) {
                    return [$group, "topic:$topic"];
                }
            }

            return ['other', 'topic:none'];
        }
        foreach (self::REASON_GROUPS as $group => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $reason)) {
                    return [$group, $reason];
                }
            }
        }

        return ['other', $reason];
    }

    /** Groups, the largest first, each with its details (largest first). "unspecified" goes last. */
    private static function groups(array $groups): array
    {
        $out = [];
        foreach ($groups as $key => $details) {
            arsort($details);
            $out[] = ['key' => $key, 'n' => array_sum($details), 'details' => array_map(fn ($name, $n) => ['name' => (string) $name, 'n' => $n], array_keys($details), $details)];
        }
        usort($out, fn ($a, $b) => [$a['key'] === 'unspecified', $b['n']] <=> [$b['key'] === 'unspecified', $a['n']]);

        return $out;
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
