<?php

namespace App\Console\Commands;

use App\Support\CallCenter;
use App\Support\CompanyLogins;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Call center demo (user's request 2026-11-17, Alo Mundo): 1 supervisor and 3 agents with their logins, a client
 * (a health insurer), 4,000 leads from the usual Peruvian lists, the campaign "Seguros de salud" (25 to 60 years old,
 * Lima and Callao) loaded and active, a second campaign as a draft, the agents' last 6 working days (shifts, sessions with pauses,
 * calls with their results) and quality reviews. Every row goes to demo_records (Demo Data -> reset removes it).
 * Made once: run again, it only completes what is missing.
 *
 *   php artisan callcenter:demo --company=1016
 */
class CallCenterDemo extends Command
{
    protected $signature = 'callcenter:demo {--company= : company id}';

    protected $description = 'Call center demo: supervisor, 3 agents, client, leads, campaigns and two weeks of calls';

    private const SUPERVISOR_ROLE = 'Supervisor Call Center';

    private const AGENT_ROLE = 'Agente Call Center';

    private const SUPERVISOR_PERMS = ['callcenter_work', 'callcenter_campaigns', 'callcenter_leads', 'callcenter_calls', 'callcenter_shifts',
        'callcenter_dashboard', 'callcenter_quality'];

    private const AGENT_PERMS = ['callcenter_work'];

    private const PEOPLE = [
        ['supervisor', 'Rosa', 'Huamán Torres', 'F'],
        ['agente1', 'Carlos', 'Mendoza Ríos', 'M'],
        ['agente2', 'Lucía', 'Paredes Vega', 'F'],
        ['agente3', 'Jorge', 'Salazar Quispe', 'M'],
    ];

    private int $cid;

    public function handle(): int
    {
        $this->cid = (int) $this->option('company');
        $company = DB::table('companies')->whereNull('deleted_at')->find($this->cid);
        if (! $company) {
            $this->error('Company not found.');

            return 1;
        }
        mt_srand(20261117 + $this->cid);

        DB::transaction(function () {
            [$supervisor, $agents] = $this->people();
            $client = $this->client();
            $this->leads();
            [$main] = $this->campaigns($client);
            if (! DB::table('cc_calls')->where('campaign_id', $main)->exists()) {
                $this->history($main, $agents, $supervisor);
            }
            $this->shifts($agents);
        });
        $this->info('Call center demo ready for '.$company->name.'.');

        return 0;
    }

    private function remember(string $table, array $ids): void
    {
        $now = now();
        foreach (array_chunk($ids, 1000) as $chunk) {
            DB::table('demo_records')->insert(array_map(fn ($id) => ['company_id' => $this->cid, 'module' => 'callcenter', 'record_type' => $table,
                'record_id' => $id, 'created_at' => $now, 'updated_at' => $now], $chunk));
        }
    }

    private function role(string $name, array $perms, string $home, string $description): int
    {
        $id = DB::table('roles')->where('company_id', $this->cid)->where('name', $name)->whereNull('deleted_at')->value('id');
        if (! $id) {
            $id = DB::table('roles')->insertGetId(['company_id' => $this->cid, 'name' => $name, 'label' => $name, 'description' => $description,
                'home_path' => $home, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('permission_role')->where('role_id', $id)->delete();
        DB::table('permission_role')->insert(DB::table('permissions')->whereIn('name', $perms)->pluck('id')->map(fn ($p) => ['permission_id' => $p, 'role_id' => $id])->all());

        return (int) $id;
    }

    /** [supervisor user id, [agent user ids]] */
    private function people(): array
    {
        $supRole = $this->role(self::SUPERVISOR_ROLE, self::SUPERVISOR_PERMS, '/callcenter/dashboard',
            'Runs the floor: campaigns, leads, the agents\' shifts, indicators and quality. Also calls.');
        $agentRole = $this->role(self::AGENT_ROLE, self::AGENT_PERMS, '/callcenter/work', 'Calls the leads of the active campaigns from "My campaign".');
        $dept = DB::table('departments')->where('company_id', $this->cid)->whereNull('deleted_at')->where('department', 'Operaciones')->value('id')
            ?: DB::table('departments')->insertGetId(['company_id' => $this->cid, 'department' => 'Operaciones', 'created_at' => now(), 'updated_at' => now()]);
        $shift = DB::table('office_shifts')->where('company_id', $this->cid)->whereNull('deleted_at')->orderBy('id')->value('id');
        $slug = CompanyLogins::slug($this->cid);
        $sup = null;
        $agents = [];
        foreach (self::PEOPLE as [$key, $first, $last, $gender]) {
            $isSup = $key === 'supervisor';
            $email = $slug.'-'.$key.'@kcoltech.com';
            $uid = DB::table('users')->where('email', $email)->value('id');
            if (! $uid) {
                $title = $isSup ? 'Supervisor de call center' : 'Agente de call center';
                $designation = DB::table('designations')->where('company_id', $this->cid)->whereNull('deleted_at')->where('designation', $title)->value('id')
                    ?: DB::table('designations')->insertGetId(['company_id' => $this->cid, 'department_id' => $dept, 'designation' => $title, 'created_at' => now(), 'updated_at' => now()]);
                $employee = \App\Models\Employee::create([
                    'company_id' => $this->cid, 'firstname' => $first, 'lastname' => $last, 'username' => $first.' '.explode(' ', $last)[0], 'email' => $email,
                    'gender' => $gender === 'F' ? 'female' : 'male', 'avatar' => 'no_avatar.png', 'department_id' => $dept, 'designation_id' => $designation,
                    'office_shift_id' => $shift, 'joining_date' => now()->subMonths(4)->toDateString(), 'employment_type' => 'full_time',
                    'total_leave' => 0, 'remaining_leave' => 0, 'basic_salary' => $isSup ? 2200 : 1300,
                ]);
                $uid = (int) DB::table('employees')->where('id', $employee->id)->value('user_id');
                if (! $uid) {
                    throw new \RuntimeException('No login was made for '.$email);
                }
                $this->remember('employees', [$employee->id]);
                $this->remember('users', [$uid]);
            }
            $role = $isSup ? $supRole : $agentRole;
            // The name the screens and the header show (the login made one like "carlos_mendoza_rios2").
            $nice = $first.' '.explode(' ', $last)[0];
            if (! DB::table('users')->where('username', $nice)->where('id', '!=', $uid)->exists()) {
                DB::table('users')->where('id', $uid)->update(['username' => $nice]);
            }
            DB::table('users')->where('id', $uid)->update(['role_id' => $role, 'email' => $email, 'statut' => 1, 'is_all_warehouses' => 1, 'record_view' => $isSup ? 1 : 0,
                'password' => Hash::make(CompanyLogins::PASSWORDS['employee']), 'updated_at' => now()]);
            DB::table('role_user')->where('user_id', $uid)->delete();
            DB::table('role_user')->insert(['user_id' => $uid, 'role_id' => $role]);
            $isSup ? $sup = (int) $uid : $agents[] = (int) $uid;
        }

        return [$sup, $agents];
    }

    private function client(): int
    {
        $name = 'Aseguradora Vida Sana S.A.';
        $id = DB::table('clients')->where('company_id', $this->cid)->whereNull('deleted_at')->where('name', $name)->value('id');
        if ($id) {
            return (int) $id;
        }
        $c = new \App\Models\Client;
        $c->forceFill(['company_id' => $this->cid, 'name' => $name, 'code' => ((int) DB::table('clients')->where('company_id', $this->cid)->max('code')) + 1,
            'country' => 'Perú', 'city' => 'Lima', 'phone' => '014405060', 'adresse' => 'Av. Javier Prado Este 2450, San Borja', 'tax_number' => '20512345678',
            'contact_name' => 'Patricia Linares', 'notes' => 'Demo: client of the "Seguros de salud" campaign.'])->save();
        $this->remember('clients', [$c->id]);

        return (int) $c->id;
    }

    private function leads(): void
    {
        if (DB::table('cc_leads')->where('company_id', $this->cid)->where('source', 'Base demo 2026')->exists()) {
            return;
        }
        $men = ['José', 'Luis', 'Carlos', 'Juan', 'Miguel', 'Jorge', 'César', 'Víctor', 'Pedro', 'Ricardo', 'Fernando', 'Raúl', 'Javier', 'Manuel', 'Óscar', 'Alberto', 'Daniel', 'Hugo', 'Walter', 'Edwin'];
        $women = ['María', 'Rosa', 'Ana', 'Carmen', 'Luz', 'Elena', 'Patricia', 'Gloria', 'Julia', 'Silvia', 'Milagros', 'Lucía', 'Gabriela', 'Karina', 'Sofía', 'Diana', 'Teresa', 'Verónica', 'Flor', 'Yolanda'];
        $surnames = ['Quispe', 'Flores', 'Sánchez', 'Rodríguez', 'García', 'Rojas', 'Mamani', 'Huamán', 'Vásquez', 'Ramírez', 'Torres', 'Chávez', 'Mendoza', 'Díaz',
            'Castillo', 'Gutiérrez', 'Espinoza', 'Ramos', 'Vargas', 'Paredes', 'Salazar', 'Cruz', 'Romero', 'Herrera', 'Medina', 'Aguilar', 'Silva', 'Córdova'];
        $lima = ['Miraflores', 'San Isidro', 'Santiago de Surco', 'La Molina', 'San Borja', 'Jesús María', 'Lince', 'Los Olivos', 'San Martín de Porres',
            'Comas', 'Ate', 'Chorrillos', 'San Juan de Lurigancho', 'Pueblo Libre', 'Magdalena del Mar', 'Barranco', 'Villa El Salvador', 'Independencia'];
        $others = [['Arequipa', 'Arequipa', ['Cayma', 'Yanahuara', 'Cerro Colorado', 'José Luis Bustamante y Rivero']],
            ['La Libertad', 'Trujillo', ['Trujillo', 'Víctor Larco Herrera', 'La Esperanza']], ['Piura', 'Piura', ['Piura', 'Castilla', 'Veintiséis de Octubre']],
            ['Callao', 'Callao', ['Callao', 'Bellavista', 'La Perla']]];
        $insurance = ['Ninguno' => 45, 'EsSalud' => 30, 'SIS' => 15, 'EPS privada' => 10];
        $income = ['Menos de S/ 1,500', 'S/ 1,500 - 3,000', 'S/ 3,000 - 6,000', 'Más de S/ 6,000'];
        $pick = function (array $weights) {
            $r = mt_rand(1, array_sum($weights));
            foreach ($weights as $k => $w) {
                if (($r -= $w) <= 0) {
                    return $k;
                }
            }

            return array_key_first($weights);
        };
        $rows = [];
        $phones = [];
        $now = now();
        for ($i = 0; $i < 4000; $i++) {
            $g = mt_rand(0, 1) ? 'F' : 'M';
            $first = ($g === 'F' ? $women : $men)[mt_rand(0, 19)];
            $last = $surnames[mt_rand(0, count($surnames) - 1)].' '.$surnames[mt_rand(0, count($surnames) - 1)];
            do {
                $phone = '9'.mt_rand(10000000, 99999999);
            } while (isset($phones[$phone]));
            $phones[$phone] = true;
            if (mt_rand(1, 100) <= 75) {
                [$region, $province, $district] = ['Lima', 'Lima', $lima[mt_rand(0, count($lima) - 1)]];
            } else {
                $o = $others[mt_rand(0, 3)];
                [$region, $province, $district] = [$o[0], $o[1], $o[2][mt_rand(0, count($o[2]) - 1)]];
            }
            $age = mt_rand(19, 74);
            $hasBirth = mt_rand(1, 100) <= 80;
            $rows[] = [
                'company_id' => $this->cid, 'first_name' => $first, 'last_name' => $last, 'phone' => $phone, 'phone_key' => CallCenter::phoneKey($phone),
                'email' => mt_rand(1, 100) <= 40 ? strtolower(\Illuminate\Support\Str::ascii($first.'.'.explode(' ', $last)[0])).mt_rand(1, 99).'@example.com' : null,
                'document' => (string) mt_rand(10000000, 79999999), 'birth_date' => $hasBirth ? now()->subYears($age)->subDays(mt_rand(0, 364))->toDateString() : null,
                'age' => $hasBirth ? null : $age, 'gender' => $g, 'region' => $region, 'province' => $province, 'district' => $district, 'city' => $province,
                'source' => 'Base demo 2026',
                'extra' => json_encode(['Seguro actual' => $pick($insurance), 'Ingreso aprox.' => $income[mt_rand(0, 3)]], JSON_UNESCAPED_UNICODE),
                'do_not_call' => 0, 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('cc_leads')->insert($chunk);
        }
        $this->remember('cc_leads', DB::table('cc_leads')->where('company_id', $this->cid)->where('source', 'Base demo 2026')->pluck('id')->all());
    }

    private function campaigns(int $client): array
    {
        $make = function (string $name, array $f, string $status, string $script, string $mode, float $rate) use ($client) {
            $id = DB::table('cc_campaigns')->where('company_id', $this->cid)->whereNull('deleted_at')->where('name', $name)->value('id');
            if ($id) {
                return (int) $id;
            }
            $id = DB::table('cc_campaigns')->insertGetId(['company_id' => $this->cid, 'name' => $name, 'client_id' => $client, 'mode' => $mode, 'status' => 'draft',
                'script' => $script, 'filters' => json_encode($f, JSON_UNESCAPED_UNICODE), 'starts_on' => now()->subDays(15)->toDateString(),
                'ends_on' => now()->addDays(45)->toDateString(), 'call_from' => '08:00', 'call_to' => '21:00', 'max_attempts' => 3, 'retry_minutes' => 120,
                'billing_mode' => 'sale', 'billing_rate' => $rate, 'created_at' => now()->subDays(15), 'updated_at' => now()]);
            $this->remember('cc_campaigns', [$id]);
            if ($status !== 'draft') {
                CallCenter::loadLeads(DB::table('cc_campaigns')->find($id));
                DB::table('cc_campaigns')->where('id', $id)->update(['status' => $status]);
            }

            return (int) $id;
        };
        $main = $make('Seguros de salud', ['age_min' => 25, 'age_max' => 60, 'regions' => ['Lima', 'Callao']], 'active',
            "Buenos días/tardes, ¿hablo con el Sr./Sra. {nombre}? Le saluda {agente} de Aseguradora Vida Sana.\n\n"
            ."1. Motivo: le llamamos porque tenemos un plan de salud desde S/ 89 al mes, con atención en más de 200 clínicas.\n"
            ."2. Pregunte: ¿cuenta hoy con algún seguro de salud? (EsSalud, SIS, EPS...)\n"
            ."3. Beneficios: consultas sin copago el primer mes, emergencias 24 h, cobertura oncológica.\n"
            ."4. Objeciones: «Es caro» → plan básico S/ 59. «Ya tengo EsSalud» → complementa: sin colas y clínicas privadas.\n"
            ."5. Cierre: confirme nombre, DNI, correo y fecha de pago. Explique que recibirá el contrato por correo.\n"
            ."6. Despedida: «Gracias por su tiempo, que tenga un buen día».", 'screen', 35);
        $draft = $make('Seguro oncológico mujeres 40+', ['age_min' => 40, 'age_max' => 70, 'gender' => 'F', 'extra' => [['key' => 'Seguro actual', 'value' => 'Ninguno']]], 'draft',
            "Saludo y presentación.\nOfrezca el seguro oncológico desde S/ 39 al mes, sin examen médico previo hasta los 65 años.\nCierre con los datos para la afiliación.", 'screen', 25);

        return [$main, $draft];
    }

    /** Two weeks of the agents' work on the main campaign: sessions with pauses and calls taken in order, like the screen does. */
    private function history(int $campaignId, array $agents, int $supervisor): void
    {
        $campaign = DB::table('cc_campaigns')->find($campaignId);
        $weights = ['no_answer' => 30, 'busy' => 7, 'voicemail' => 10, 'not_interested' => 24, 'interested' => 9, 'callback' => 7, 'sale' => 9, 'wrong_number' => 3, 'do_not_call' => 1];
        $pick = function () use ($weights) {
            $r = mt_rand(1, 100);
            foreach ($weights as $k => $w) {
                if (($r -= $w) <= 0) {
                    return $k;
                }
            }

            return 'no_answer';
        };
        $notes = [
            'sale' => ['Acepta plan básico, pago con tarjeta el día 5.', 'Contrata plan familiar (3 personas).', 'Acepta, envío de contrato por correo.'],
            'interested' => ['Pide que le envíen la información por WhatsApp.', 'Lo conversa con su esposa.', 'Interesado, compara con su EPS.'],
            'not_interested' => ['Ya tiene EPS por su trabajo.', 'No le interesa por ahora.', 'Dice que es caro.', 'Cuelga a mitad de la presentación.'],
            'callback' => ['Está manejando, llamar en la tarde.', 'Pide que llamen el fin de semana.', 'Ocupado en reunión.'],
            'wrong_number' => ['El número es de otra persona.'], 'do_not_call' => ['Pide que no lo vuelvan a llamar.'],
        ];
        $callIds = [];
        $sessionIds = [];
        $days = [];
        // The last 6 working days (Monday to Saturday).
        for ($d = 1; count($days) < 6; $d++) {
            $day = now()->subDays($d)->startOfDay();
            if (! $day->isSunday()) {
                array_unshift($days, $day);
            }
        }
        $queue = DB::table('cc_campaign_leads')->where('campaign_id', $campaignId)->where('status', 'pending')->orderBy('id')->pluck('id', 'lead_id')->all();
        $leadIds = array_keys($queue);
        $state = [];  // campaign_lead_id => [attempts, next_at]
        $ptr = 0;
        foreach ($days as $day) {
            foreach ($agents as $n => $uid) {
                // 09:00-13:00 and 14:00-18:00 (Saturdays until 13:00), a 15-minute break mid-morning, arrivals 0-12 minutes late.
                $blocks = $day->isSaturday() ? [[9, 13]] : [[9, 13], [14, 18]];
                foreach ($blocks as $bi => [$h1, $h2]) {
                    $start = $day->copy()->setTime($h1, mt_rand(0, $bi === 0 ? 12 : 4));
                    $end = $day->copy()->setTime($h2, 0)->subMinutes(mt_rand(0, 8));
                    $pauseAt = $bi === 0 ? $day->copy()->setTime(11, mt_rand(0, 20)) : null;
                    $segments = $pauseAt ? [[$start, $pauseAt, 'work'], [$pauseAt, $pauseAt->copy()->addMinutes(15), 'pause'], [$pauseAt->copy()->addMinutes(15), $end, 'work']]
                        : [[$start, $end, 'work']];
                    foreach ($segments as [$s, $e, $kind]) {
                        $sessionIds[] = DB::table('cc_agent_sessions')->insertGetId(['company_id' => $this->cid, 'user_id' => $uid, 'campaign_id' => $campaignId,
                            'kind' => $kind, 'reason' => $kind === 'pause' ? 'break' : null, 'started_at' => $s, 'ended_at' => $e, 'last_seen_at' => $e,
                            'created_at' => $s, 'updated_at' => $e]);
                        if ($kind !== 'work') {
                            continue;
                        }
                        $t = $s->copy()->addMinutes(1);
                        while ($t->lt($e->copy()->subMinutes(3))) {
                            // Next lead: a due retry / callback first, else a fresh one.
                            $clId = null;
                            foreach ($state as $id => $st) {
                                if ($st['status'] === 'wait' && $st['next']->lte($t)) {
                                    $clId = $id;
                                    break;
                                }
                            }
                            if (! $clId) {
                                if ($ptr >= count($leadIds)) {
                                    break 2;
                                }
                                $clId = $queue[$leadIds[$ptr++]];
                                $state[$clId] = ['status' => 'new', 'attempts' => 0, 'next' => null];
                            }
                            $result = $pick();
                            if ($state[$clId]['attempts'] > 0 && $result === 'wrong_number') {
                                $result = 'no_answer';
                            }
                            $contact = CallCenter::RESULTS[$result][3];
                            $dur = $contact ? mt_rand(70, $result === 'sale' ? 600 : 300) : mt_rand(15, 40);
                            $lead = DB::table('cc_campaign_leads as x')->join('cc_leads as l', 'l.id', '=', 'x.lead_id')->where('x.id', $clId)->first(['l.id', 'l.phone']);
                            $callback = $result === 'callback' ? $t->copy()->addHours(mt_rand(2, 26))->setMinute(0) : null;
                            $id = DB::table('cc_calls')->insertGetId(['company_id' => $this->cid, 'campaign_id' => $campaignId, 'lead_id' => $lead->id, 'campaign_lead_id' => $clId,
                                'user_id' => $uid, 'direction' => 'outbound', 'source' => 'screen', 'phone' => $lead->phone, 'started_at' => $t, 'ended_at' => $t->copy()->addSeconds($dur),
                                'duration_sec' => $dur, 'result' => $result, 'callback_at' => $callback, 'sale_amount' => $result === 'sale' ? [59, 89, 89, 129, 199][mt_rand(0, 4)] : null,
                                'notes' => isset($notes[$result]) ? $notes[$result][mt_rand(0, count($notes[$result]) - 1)] : null, 'created_at' => $t, 'updated_at' => $t]);
                            $callIds[] = $id;
                            $att = $state[$clId]['attempts'] + 1;
                            $effect = CallCenter::RESULTS[$result][2];
                            $upd = ['attempts' => $att, 'last_result' => $result, 'last_user_id' => $uid, 'last_call_id' => $id, 'updated_at' => $t,
                                'locked_by' => null, 'locked_at' => null, 'claim' => null, 'callback_user_id' => null, 'next_call_at' => null];
                            if ($effect === 'callback') {
                                $upd += ['status' => 'callback', 'next_call_at' => $callback, 'callback_user_id' => $uid];
                                $upd['next_call_at'] = $callback;
                                $upd['callback_user_id'] = $uid;
                                $state[$clId] = ['status' => 'wait', 'attempts' => $att, 'next' => $callback];
                            } elseif ($effect === 'retry' && $att < (int) $campaign->max_attempts) {
                                $next = $t->copy()->addMinutes((int) $campaign->retry_minutes);
                                $upd += ['status' => 'pending'];
                                $upd['next_call_at'] = $next;
                                $state[$clId] = ['status' => 'wait', 'attempts' => $att, 'next' => $next];
                            } else {
                                $upd += ['status' => $effect === 'retry' ? 'exhausted' : 'done'];
                                $state[$clId] = ['status' => 'closed', 'attempts' => $att, 'next' => null];
                                if ($effect === 'dnc') {
                                    DB::table('cc_leads')->where('id', $lead->id)->update(['do_not_call' => 1]);
                                }
                            }
                            DB::table('cc_campaign_leads')->where('id', $clId)->update($upd);
                            // Reading the lead, dialing by hand and writing the notes: 2.5-5.5 minutes between calls.
                            $t = $t->copy()->addSeconds($dur + mt_rand(150, 330));
                        }
                    }
                }
            }
        }
        $this->remember('cc_calls', $callIds);
        $this->remember('cc_agent_sessions', $sessionIds);

        // Quality: the supervisor reviewed some contacts of each agent.
        $criteria = DB::table('cc_qa_criteria')->where('company_id', $this->cid)->exists() ? null : [
            ['Saludo e identificación', 1], ['Uso del guion', 2], ['Escucha y empatía', 2], ['Manejo de objeciones', 2], ['Información correcta del producto', 2], ['Cierre y despedida', 1]];
        foreach ($criteria ?? [] as $i => [$name, $w]) {
            DB::table('cc_qa_criteria')->insert(['company_id' => $this->cid, 'name' => $name, 'weight' => $w, 'sort' => $i, 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $crit = DB::table('cc_qa_criteria')->where('company_id', $this->cid)->where('active', 1)->get(['id', 'weight']);
        $reviewIds = [];
        $skill = [];
        foreach ($agents as $i => $uid) {
            $skill[$uid] = [4, 3, 3][$i] ?? 3;
        }
        $sample = DB::table('cc_calls')->whereIn('id', $callIds)->whereIn('result', ['sale', 'not_interested', 'interested'])->inRandomOrder(7)->limit(18)->get(['id', 'user_id', 'started_at']);
        foreach ($sample as $c) {
            $scores = [];
            $got = 0;
            $max = 0;
            foreach ($crit as $k) {
                $scores[$k->id] = max(1, min(5, $skill[$c->user_id] + mt_rand(-1, 1)));
                $got += $scores[$k->id] * $k->weight;
                $max += 5 * $k->weight;
            }
            $reviewIds[] = DB::table('cc_qa_reviews')->insertGetId(['company_id' => $this->cid, 'call_id' => $c->id, 'agent_id' => $c->user_id, 'reviewer_id' => $supervisor,
                'scores' => json_encode($scores), 'score' => round(100 * $got / $max, 1),
                'comments' => ['Buen manejo de objeciones.', 'Le faltó confirmar el DNI al cierre.', 'Tono amable, siguió el guion.', 'Hablar más despacio en la presentación.'][mt_rand(0, 3)],
                'created_at' => Carbon::parse($c->started_at)->addDay(), 'updated_at' => Carbon::parse($c->started_at)->addDay()]);
        }
        $this->remember('cc_qa_reviews', $reviewIds);
    }

    /** Planned shifts: last week, this week and next, Monday to Friday 09:00-18:00 and Saturday 09:00-13:00. */
    private function shifts(array $agents): void
    {
        $ids = [];
        $mon = now()->startOfWeek(Carbon::MONDAY)->subWeek();
        for ($i = 0; $i < 20; $i++) {
            $day = $mon->copy()->addDays($i);
            if ($day->isSunday()) {
                continue;
            }
            foreach ($agents as $uid) {
                if (DB::table('cc_shifts')->where('user_id', $uid)->where('day', $day->toDateString())->exists()) {
                    continue;
                }
                $ids[] = DB::table('cc_shifts')->insertGetId(['company_id' => $this->cid, 'user_id' => $uid, 'day' => $day->toDateString(),
                    'start_time' => '09:00', 'end_time' => $day->isSaturday() ? '13:00' : '18:00', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $this->remember('cc_shifts', $ids);
    }
}
