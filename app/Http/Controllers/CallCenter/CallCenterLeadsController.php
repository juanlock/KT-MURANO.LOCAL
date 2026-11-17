<?php

namespace App\Http\Controllers\CallCenter;

use App\Support\CallCenter;
use App\Support\CompanyFiles;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Call center leads (2026-11-17): the list, one by one, and the import.
 *
 * The import improves on the customers' one (user: "revísalo y mejóralo"): it takes .xlsx, .xls and .csv; it first shows
 * the file's columns with a guess of what each one is (Spanish or English headers) and the first rows, so nothing has to be
 * renamed in the file; columns the lead has no field for are kept as extra data (and can be filtered in a campaign); a
 * bad row is skipped and listed with its reason instead of refusing the whole file; a phone already in the company
 * updates that lead or is skipped (the user picks); the leads can go straight into a campaign. The file is kept
 * (private) with the import's record.
 *
 *  GET  callcenter/leads                 GET callcenter/leads/options     GET callcenter/leads/template
 *  POST callcenter/leads                 PUT callcenter/leads/{id}        DELETE callcenter/leads/{id}
 *  POST callcenter/leads/import/preview  POST callcenter/leads/import/{id}/run   GET callcenter/leads/imports
 */
class CallCenterLeadsController extends CallCenterBase
{
    public const FIELDS = [
        'full_name' => ['Full name (split in two)', 'Nombre completo (se separa)'],
        'first_name' => ['First name', 'Nombres'],
        'last_name' => ['Last name', 'Apellidos'],
        'phone' => ['Phone', 'Teléfono'],
        'phone2' => ['Phone 2', 'Teléfono 2'],
        'email' => ['Email', 'Correo'],
        'document' => ['ID document', 'Documento (DNI)'],
        'birth_date' => ['Birth date', 'Fecha de nacimiento'],
        'age' => ['Age', 'Edad'],
        'gender' => ['Sex', 'Sexo'],
        'region' => ['Region / department', 'Departamento / región'],
        'province' => ['Province', 'Provincia'],
        'district' => ['District', 'Distrito'],
        'city' => ['City', 'Ciudad'],
        'address' => ['Address', 'Dirección'],
        'source' => ['Source', 'Fuente'],
        'notes' => ['Notes', 'Notas'],
    ];

    /** Header (lowercase, no accents) => field. */
    private const GUESS = [
        'nombre completo' => 'full_name', 'nombre y apellido' => 'full_name', 'nombres y apellidos' => 'full_name', 'full name' => 'full_name',
        'cliente' => 'full_name', 'name' => 'full_name', 'contacto' => 'full_name',
        'nombre' => 'first_name', 'nombres' => 'first_name', 'first name' => 'first_name', 'firstname' => 'first_name',
        'apellido' => 'last_name', 'apellidos' => 'last_name', 'last name' => 'last_name', 'lastname' => 'last_name', 'surname' => 'last_name',
        'telefono' => 'phone', 'celular' => 'phone', 'movil' => 'phone', 'phone' => 'phone', 'mobile' => 'phone', 'numero' => 'phone',
        'cel' => 'phone', 'whatsapp' => 'phone', 'telefono 1' => 'phone', 'telefono1' => 'phone', 'phone number' => 'phone',
        'telefono 2' => 'phone2', 'telefono2' => 'phone2', 'otro telefono' => 'phone2', 'phone 2' => 'phone2', 'fijo' => 'phone2', 'telefono fijo' => 'phone2',
        'email' => 'email', 'correo' => 'email', 'e-mail' => 'email', 'mail' => 'email', 'correo electronico' => 'email',
        'dni' => 'document', 'documento' => 'document', 'doc' => 'document', 'nro documento' => 'document', 'ruc' => 'document', 'document' => 'document',
        'fecha de nacimiento' => 'birth_date', 'fecha nacimiento' => 'birth_date', 'nacimiento' => 'birth_date', 'birth date' => 'birth_date',
        'birthdate' => 'birth_date', 'dob' => 'birth_date', 'f. nac' => 'birth_date', 'f nac' => 'birth_date',
        'edad' => 'age', 'age' => 'age',
        'sexo' => 'gender', 'genero' => 'gender', 'gender' => 'gender', 'sex' => 'gender',
        'departamento' => 'region', 'region' => 'region', 'estado' => 'region', 'state' => 'region',
        'provincia' => 'province', 'province' => 'province',
        'distrito' => 'district', 'district' => 'district',
        'ciudad' => 'city', 'city' => 'city', 'localidad' => 'city',
        'direccion' => 'address', 'address' => 'address', 'domicilio' => 'address',
        'fuente' => 'source', 'origen' => 'source', 'source' => 'source',
        'notas' => 'notes', 'observaciones' => 'notes', 'comentarios' => 'notes', 'notes' => 'notes', 'nota' => 'notes',
    ];

    private const MAX_ROWS = 50000;

    // ------------------------------------------------------------------ list

    public function index(Request $request)
    {
        $this->allow($request, 'callcenter_leads', 'callcenter_campaigns');
        $cid = $this->company($request);
        $age = CallCenter::ageSql('l');
        $q = DB::table('cc_leads as l')->where('l.company_id', $cid)->whereNull('l.deleted_at')
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = '%'.trim($request->search).'%';
                $digits = preg_replace('/\D+/', '', (string) $request->search);
                $q->where(fn ($w) => $w->where('l.first_name', 'like', $s)->orWhere('l.last_name', 'like', $s)->orWhere('l.email', 'like', $s)
                    ->orWhere('l.document', 'like', $s)->orWhereRaw("CONCAT_WS(' ', l.first_name, l.last_name) LIKE ?", [$s])
                    ->when(strlen($digits) >= 3, fn ($x) => $x->orWhere('l.phone_key', 'like', '%'.$digits.'%')));
            })
            ->when($request->filled('gender'), fn ($q) => $q->where('l.gender', $request->gender))
            ->when($request->filled('region'), fn ($q) => $q->where('l.region', $request->region))
            ->when($request->filled('district'), fn ($q) => $q->where('l.district', $request->district))
            ->when($request->filled('source'), fn ($q) => $q->where('l.source', $request->source))
            ->when($request->filled('import_id'), fn ($q) => $q->where('l.import_id', (int) $request->import_id))
            ->when($request->filled('age_min'), fn ($q) => $q->whereRaw("$age >= ?", [(int) $request->age_min]))
            ->when($request->filled('age_max'), fn ($q) => $q->whereRaw("$age <= ?", [(int) $request->age_max]))
            ->when($request->input('dnc') === '1', fn ($q) => $q->where('l.do_not_call', 1))
            ->when($request->input('dnc') === '0', fn ($q) => $q->where('l.do_not_call', 0))
            ->select('l.*')->selectRaw("$age as age_now");
        [$rows, $total] = $this->page($q, $request, [
            'name' => 'l.first_name', 'phone' => 'l.phone', 'age_now' => 'age_now', 'gender' => 'l.gender', 'region' => 'l.region',
            'district' => 'l.district', 'source' => 'l.source', 'created_at' => 'l.created_at', 'id' => 'l.id',
        ], 'l.id');

        $ids = $rows->pluck('id')->all();
        $last = DB::table('cc_calls')->whereIn('lead_id', $ids)->selectRaw('lead_id, COUNT(*) n, MAX(started_at) last_at')->groupBy('lead_id')->get()->keyBy('lead_id');
        $lastResult = DB::table('cc_calls as c')->whereIn('c.lead_id', $ids)
            ->whereRaw('c.id = (SELECT MAX(c2.id) FROM cc_calls c2 WHERE c2.lead_id = c.lead_id)')->pluck('result', 'lead_id');
        $loc = $this->locale();

        return response()->json([
            'rows' => $rows->map(fn ($l) => $this->out($l) + [
                'age_now' => $l->age_now !== null ? (int) $l->age_now : null,
                'calls' => (int) ($last[$l->id]->n ?? 0), 'last_call_at' => $last[$l->id]->last_at ?? null,
                'last_result' => $lastResult[$l->id] ?? null, 'last_result_label' => CallCenter::label(CallCenter::RESULTS, $lastResult[$l->id] ?? null, $loc),
            ]),
            'totalRows' => $total,
        ]);
    }

    private function out(object $l): array
    {
        return [
            'id' => (int) $l->id, 'first_name' => $l->first_name, 'last_name' => $l->last_name,
            'name' => trim($l->first_name.' '.$l->last_name), 'phone' => $l->phone, 'phone2' => $l->phone2, 'email' => $l->email,
            'document' => $l->document, 'birth_date' => $l->birth_date, 'age' => $l->age !== null ? (int) $l->age : null, 'gender' => $l->gender,
            'region' => $l->region, 'province' => $l->province, 'district' => $l->district, 'city' => $l->city, 'address' => $l->address,
            'source' => $l->source, 'extra' => json_decode((string) $l->extra, true) ?: (object) [], 'do_not_call' => (bool) $l->do_not_call,
            'notes' => $l->notes, 'created_at' => $l->created_at,
        ];
    }

    /** The values the filters offer: what the leads hold, and the extra columns' names. */
    public function options(Request $request)
    {
        $this->allow($request, 'callcenter_leads', 'callcenter_campaigns');
        $cid = $this->company($request);
        $base = DB::table('cc_leads')->where('company_id', $cid)->whereNull('deleted_at');
        $vals = fn ($col) => (clone $base)->whereNotNull($col)->where($col, '!=', '')->distinct()->orderBy($col)->limit(500)->pluck($col);
        $extraKeys = [];
        foreach ((clone $base)->whereNotNull('extra')->orderByDesc('id')->limit(300)->pluck('extra') as $e) {
            foreach (array_keys(json_decode((string) $e, true) ?: []) as $k) {
                $extraKeys[$k] = true;
            }
        }
        ksort($extraKeys);
        $loc = $this->locale();

        return response()->json([
            'regions' => $vals('region'), 'provinces' => $vals('province'), 'districts' => $vals('district'), 'cities' => $vals('city'),
            'sources' => $vals('source'), 'extra_keys' => array_keys($extraKeys),
            'fields' => collect(self::FIELDS)->map(fn ($v, $k) => ['value' => $k, 'label' => $loc === 'es' ? $v[1] : $v[0]])->values(),
            'imports' => DB::table('cc_imports')->where('company_id', $cid)->orderByDesc('id')->limit(30)->get(['id', 'file_name', 'created_at']),
            'total' => (clone $base)->count(), 'dnc' => (clone $base)->where('do_not_call', 1)->count(),
        ]);
    }

    // ------------------------------------------------------------------ one lead

    private function validated(Request $request): array
    {
        $d = $request->validate([
            'first_name' => 'nullable|string|max:120', 'last_name' => 'nullable|string|max:120', 'phone' => 'required|string|max:40',
            'phone2' => 'nullable|string|max:40', 'email' => 'nullable|email|max:190', 'document' => 'nullable|string|max:30',
            'birth_date' => 'nullable|date', 'age' => 'nullable|integer|min:0|max:120', 'gender' => 'nullable|in:M,F',
            'region' => 'nullable|string|max:100', 'province' => 'nullable|string|max:100', 'district' => 'nullable|string|max:100',
            'city' => 'nullable|string|max:100', 'address' => 'nullable|string|max:255', 'source' => 'nullable|string|max:120',
            'notes' => 'nullable|string|max:5000', 'do_not_call' => 'nullable|boolean', 'extra' => 'nullable|array',
        ]);
        abort_if(strlen(CallCenter::phoneKey($d['phone'])) < 6, 422, __('The phone number is not valid.'));
        $d['phone_key'] = CallCenter::phoneKey($d['phone']);
        $d['do_not_call'] = (bool) ($d['do_not_call'] ?? false);
        $d['extra'] = isset($d['extra']) ? json_encode(array_filter($d['extra'], fn ($v) => $v !== null && $v !== ''), JSON_UNESCAPED_UNICODE) : null;

        return $d;
    }

    public function store(Request $request)
    {
        $this->allow($request, 'callcenter_leads');
        $cid = $this->company($request);
        $d = $this->validated($request);
        abort_if(DB::table('cc_leads')->where('company_id', $cid)->whereNull('deleted_at')->where('phone_key', $d['phone_key'])->exists(), 422,
            __('A lead with this phone number already exists.'));
        $id = DB::table('cc_leads')->insertGetId($d + ['company_id' => $cid, 'source' => $d['source'] ?? null, 'created_by' => $request->user('api')->id,
            'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['id' => $id]);
    }

    public function update(Request $request, int $id)
    {
        $this->allow($request, 'callcenter_leads');
        $cid = $this->company($request);
        $lead = DB::table('cc_leads')->where('company_id', $cid)->whereNull('deleted_at')->find($id);
        abort_unless($lead, 404);
        $d = $this->validated($request);
        abort_if(DB::table('cc_leads')->where('company_id', $cid)->whereNull('deleted_at')->where('phone_key', $d['phone_key'])->where('id', '!=', $id)->exists(), 422,
            __('A lead with this phone number already exists.'));
        DB::transaction(function () use ($id, $d) {
            DB::table('cc_leads')->where('id', $id)->update($d + ['updated_at' => now()]);
            // "Do not call" takes it out of every campaign still calling.
            if ($d['do_not_call']) {
                DB::table('cc_campaign_leads')->where('lead_id', $id)->whereIn('status', ['pending', 'callback'])->update(['status' => 'done', 'last_result' => 'do_not_call', 'updated_at' => now()]);
            }
        });

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, int $id)
    {
        $this->allow($request, 'callcenter_leads');
        $cid = $this->company($request);
        abort_unless(DB::table('cc_leads')->where('company_id', $cid)->whereNull('deleted_at')->where('id', $id)->exists(), 404);
        DB::transaction(function () use ($id) {
            DB::table('cc_leads')->where('id', $id)->update(['deleted_at' => now()]);
            DB::table('cc_campaign_leads')->where('lead_id', $id)->whereIn('status', ['pending', 'callback'])->delete();
        });

        return response()->json(['ok' => true]);
    }

    // ------------------------------------------------------------------ import

    public function template(Request $request)
    {
        $this->allow($request, 'callcenter_leads');
        $es = $this->locale() === 'es';
        $head = $es
            ? ['Nombres', 'Apellidos', 'Teléfono', 'Teléfono 2', 'Correo', 'DNI', 'Fecha de nacimiento', 'Edad', 'Sexo', 'Departamento', 'Provincia', 'Distrito', 'Ciudad', 'Dirección', 'Fuente', 'Notas', 'Seguro actual']
            : ['First name', 'Last name', 'Phone', 'Phone 2', 'Email', 'Document', 'Birth date', 'Age', 'Sex', 'Region', 'Province', 'District', 'City', 'Address', 'Source', 'Notes', 'Current insurance'];
        $row = ['María', 'Quispe Rojas', '987654321', '', 'maria@example.com', '45678912', '1985-04-12', '', 'F', 'Lima', 'Lima', 'Miraflores', 'Lima', 'Av. Larco 123', 'Feria 2026', '', 'Ninguno'];
        $csv = "\xEF\xBB\xBF".implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', $v).'"', $head))."\r\n"
            .implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', $v).'"', $row))."\r\n";

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.($es ? 'plantilla_leads.csv' : 'leads_template.csv').'"']);
    }

    private static function norm(string $h): string
    {
        $h = mb_strtolower(trim($h));
        $h = strtr($h, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', '_' => ' ', 'º' => '', '°' => '']);

        return trim(preg_replace('/\s+/', ' ', $h));
    }

    /** The file's rows (first sheet); empty rows out. */
    private function sheet(string $absPath): array
    {
        $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
        if (in_array($ext, ['csv', 'txt'], true)) {
            // CSV read here: Excel in Spanish saves it with ";" and often in Windows-1252.
            $text = (string) file_get_contents($absPath);
            $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
            if (! mb_check_encoding($text, 'UTF-8')) {
                $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
            }
            $firstLine = strtok($text, "\n");
            $delims = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
            arsort($delims);
            $delim = array_key_first($delims);
            $fh = fopen('php://temp', 'r+');
            fwrite($fh, $text);
            rewind($fh);
            $rows = [];
            while (($r = fgetcsv($fh, 0, $delim)) !== false) {
                $rows[] = $r;
            }
            fclose($fh);
        } else {
            $sheets = Excel::toArray([], new \Illuminate\Http\UploadedFile($absPath, basename($absPath), null, null, true));
            $rows = $sheets[0] ?? [];
        }

        return array_values(array_filter($rows, fn ($r) => is_array($r) && count(array_filter($r, fn ($v) => $v !== null && trim((string) $v) !== '')) > 0));
    }

    public function preview(Request $request)
    {
        $this->allow($request, 'callcenter_leads');
        $cid = $this->company($request);
        $request->validate(['file' => 'required|file|max:20480']);
        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        abort_unless(in_array($ext, ['xlsx', 'xls', 'csv', 'txt'], true), 422, __('The file must be Excel (.xlsx, .xls) or CSV.'));

        $rel = CompanyFiles::putUpload($cid, 'callcenter/imports', $file, true);
        $rows = $this->sheet(public_path($rel));
        abort_if(count($rows) < 2, 422, __('The file has no rows under its headers.'));
        abort_if(count($rows) - 1 > self::MAX_ROWS, 422, __('The file has more than :n rows. Split it in parts.', ['n' => self::MAX_ROWS]));

        $headers = array_map(fn ($h) => trim((string) $h), $rows[0]);
        $used = [];
        $mapping = [];
        foreach ($headers as $i => $h) {
            $guess = self::GUESS[self::norm($h)] ?? null;
            if ($guess && ! isset($used[$guess])) {
                $used[$guess] = true;
                $mapping[$i] = $guess;
            } else {
                $mapping[$i] = $h === '' ? 'ignore' : 'extra';
            }
        }
        $id = DB::table('cc_imports')->insertGetId(['company_id' => $cid, 'file_name' => mb_substr($file->getClientOriginalName(), 0, 190),
            'file_path' => $rel, 'rows' => count($rows) - 1, 'user_id' => $request->user('api')->id, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json([
            'import_id' => $id, 'file_name' => $file->getClientOriginalName(), 'rows' => count($rows) - 1,
            'headers' => $headers, 'mapping' => $mapping,
            'sample' => array_map(fn ($r) => array_map(fn ($v) => $v === null ? '' : (string) $v, $r), array_slice($rows, 1, 5)),
        ]);
    }

    public function run(Request $request, int $id)
    {
        $this->allow($request, 'callcenter_leads');
        $cid = $this->company($request);
        $imp = DB::table('cc_imports')->where('company_id', $cid)->find($id);
        abort_unless($imp && $imp->file_path && CompanyFiles::owns($imp->file_path, $cid, 'callcenter/imports'), 404);
        abort_if($imp->created || $imp->updated, 422, __('This file was already imported.'));
        $data = $request->validate([
            'mapping' => 'required|array', 'mapping.*' => 'string|max:40',
            'duplicates' => 'required|in:update,skip', 'source' => 'nullable|string|max:120', 'campaign_id' => 'nullable|integer',
        ]);
        $mapping = $data['mapping'];
        abort_unless(in_array('phone', $mapping, true), 422, __('Choose the column that holds the phone number.'));
        $campaign = ! empty($data['campaign_id'])
            ? DB::table('cc_campaigns')->where('company_id', $cid)->whereNull('deleted_at')->find((int) $data['campaign_id']) : null;
        abort_if(! empty($data['campaign_id']) && ! $campaign, 404);

        $rows = $this->sheet(public_path($imp->file_path));
        $headers = array_map(fn ($h) => trim((string) $h), $rows[0] ?? []);
        $errors = [];
        $created = $updated = $skipped = 0;
        $seen = [];
        $leadIds = [];
        $userId = $request->user('api')->id;
        $source = $data['source'] ?: pathinfo($imp->file_name, PATHINFO_FILENAME);

        DB::transaction(function () use ($rows, $headers, $mapping, $cid, $id, $data, $userId, $source, &$errors, &$created, &$updated, &$skipped, &$seen, &$leadIds) {
            $existing = DB::table('cc_leads')->where('company_id', $cid)->whereNull('deleted_at')->pluck('id', 'phone_key');
            for ($i = 1; $i < count($rows); $i++) {
                $line = $i + 1;
                [$lead, $extra, $why] = $this->rowToLead($rows[$i], $headers, $mapping);
                if ($why) {
                    $errors[] = ['row' => $line, 'reason' => $why];
                    $skipped++;

                    continue;
                }
                $key = $lead['phone_key'];
                if (isset($seen[$key])) {
                    $errors[] = ['row' => $line, 'reason' => __('Phone repeated in the file (row :r).', ['r' => $seen[$key]])];
                    $skipped++;

                    continue;
                }
                $seen[$key] = $line;
                if (isset($existing[$key])) {
                    if ($data['duplicates'] === 'skip') {
                        $errors[] = ['row' => $line, 'reason' => __('The phone is already a lead (skipped).')];
                        $skipped++;

                        continue;
                    }
                    $old = DB::table('cc_leads')->where('id', $existing[$key])->first();
                    $merged = array_merge(json_decode((string) $old->extra, true) ?: [], $extra);
                    DB::table('cc_leads')->where('id', $old->id)->update(array_filter($lead, fn ($v) => $v !== null && $v !== '')
                        + ['extra' => $merged ? json_encode($merged, JSON_UNESCAPED_UNICODE) : null, 'updated_at' => now()]);
                    $leadIds[] = (int) $old->id;
                    $updated++;

                    continue;
                }
                $newId = DB::table('cc_leads')->insertGetId($lead + [
                    'company_id' => $cid, 'source' => $lead['source'] ?? $source, 'extra' => $extra ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null,
                    'import_id' => $id, 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $existing[$key] = $newId;
                $leadIds[] = $newId;
                $created++;
            }
            DB::table('cc_imports')->where('id', $id)->update(['mapping' => json_encode($mapping), 'created' => $created, 'updated' => $updated,
                'skipped' => $skipped, 'errors' => json_encode(array_slice($errors, 0, 2000), JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        });

        $added = 0;
        if ($campaign && $leadIds) {
            $now = now();
            foreach (array_chunk($leadIds, 1000) as $chunk) {
                $added += DB::table('cc_campaign_leads')->insertOrIgnore(array_map(fn ($lid) => ['company_id' => $cid, 'campaign_id' => $campaign->id,
                    'lead_id' => $lid, 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now], $chunk));
            }
        }

        return response()->json(['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'errors' => array_slice($errors, 0, 500), 'added_to_campaign' => $added]);
    }

    /** One file row => [lead columns, extra data, reason it is refused or null]. */
    private function rowToLead(array $row, array $headers, array $mapping): array
    {
        $lead = [];
        $extra = [];
        foreach ($mapping as $i => $field) {
            $raw = $row[(int) $i] ?? null;
            $val = is_string($raw) ? trim($raw) : $raw;
            if ($val === null || $val === '') {
                continue;
            }
            if ($field === 'ignore') {
                continue;
            }
            if ($field === 'extra') {
                $name = $headers[(int) $i] ?? ('col'.$i);
                if ($name !== '') {
                    $extra[mb_substr($name, 0, 60)] = mb_substr((string) $val, 0, 255);
                }

                continue;
            }
            if (! isset(self::FIELDS[$field])) {
                continue;
            }
            $lead[$field] = $val;
        }

        if (isset($lead['full_name'])) {
            $parts = preg_split('/\s+/', trim((string) $lead['full_name']));
            // Peru: "Nombres Apellido1 Apellido2" -> the last two words are the surnames when there are 3 or more.
            $cut = count($parts) >= 3 ? count($parts) - 2 : max(1, count($parts) - 1);
            $lead['first_name'] = $lead['first_name'] ?? implode(' ', array_slice($parts, 0, $cut));
            $lead['last_name'] = $lead['last_name'] ?? (implode(' ', array_slice($parts, $cut)) ?: null);
            unset($lead['full_name']);
        }
        $phone = (string) ($lead['phone'] ?? '');
        if (is_float($lead['phone'] ?? null)) {
            $phone = number_format($lead['phone'], 0, '', '');
        }
        $key = CallCenter::phoneKey($phone);
        if (strlen($key) < 6) {
            return [[], [], $phone === '' ? __('No phone number.') : __('Phone ":p" is not valid.', ['p' => $phone])];
        }
        $lead['phone'] = mb_substr($phone, 0, 40);
        $lead['phone_key'] = $key;
        if (isset($lead['phone2']) && is_float($lead['phone2'])) {
            $lead['phone2'] = number_format($lead['phone2'], 0, '', '');
        }
        if (isset($lead['email']) && ! filter_var($lead['email'], FILTER_VALIDATE_EMAIL)) {
            $extra['Correo (no válido)'] = (string) $lead['email'];
            unset($lead['email']);
        }
        if (isset($lead['birth_date'])) {
            $d = self::date($lead['birth_date']);
            if (! $d) {
                return [[], [], __('Birth date ":d" is not a date.', ['d' => $lead['birth_date']])];
            }
            $lead['birth_date'] = $d;
        }
        if (isset($lead['age'])) {
            if (! is_numeric($lead['age']) || $lead['age'] < 0 || $lead['age'] > 120) {
                return [[], [], __('Age ":a" is not valid.', ['a' => $lead['age']])];
            }
            $lead['age'] = (int) $lead['age'];
        }
        if (isset($lead['gender'])) {
            $g = self::norm((string) $lead['gender']);
            $lead['gender'] = in_array($g, ['m', 'masculino', 'hombre', 'male', 'h', 'varon'], true) ? 'M'
                : (in_array($g, ['f', 'femenino', 'mujer', 'female'], true) ? 'F' : null);
        }
        foreach (['first_name' => 120, 'last_name' => 120, 'phone2' => 40, 'email' => 190, 'document' => 30, 'region' => 100, 'province' => 100,
            'district' => 100, 'city' => 100, 'address' => 255, 'source' => 120] as $f => $len) {
            if (isset($lead[$f])) {
                $lead[$f] = mb_substr((string) $lead[$f], 0, $len);
            }
        }
        if (isset($lead['document']) && is_numeric($lead['document'])) {
            $lead['document'] = (string) (int) $lead['document'];
        }

        return [$lead, $extra, null];
    }

    /** Excel serial, dd/mm/yyyy, dd-mm-yyyy or yyyy-mm-dd. */
    private static function date($v): ?string
    {
        if (is_numeric($v) && $v > 1000 && $v < 80000) {
            return Carbon::create(1899, 12, 30)->addDays((int) $v)->toDateString();
        }
        $s = trim((string) $v);
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y', 'd.m.Y', 'Y/m/d'] as $fmt) {
            try {
                $d = Carbon::createFromFormat('!'.$fmt, $s);
                $errs = Carbon::getLastErrors();
                if ($d && empty($errs['warning_count']) && empty($errs['error_count']) && $d->year > 1900 && $d->year <= (int) date('Y')) {
                    return $d->toDateString();
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    public function imports(Request $request)
    {
        $this->allow($request, 'callcenter_leads');
        $cid = $this->company($request);
        $rows = DB::table('cc_imports as i')->leftJoin('users as u', 'u.id', '=', 'i.user_id')->where('i.company_id', $cid)
            ->orderByDesc('i.id')->limit(50)->get(['i.id', 'i.file_name', 'i.rows', 'i.created', 'i.updated', 'i.skipped', 'i.errors', 'i.created_at', 'u.username']);

        return response()->json(['rows' => $rows->map(fn ($r) => array_merge((array) $r, ['errors' => json_decode((string) $r->errors, true) ?: []]))]);
    }
}
