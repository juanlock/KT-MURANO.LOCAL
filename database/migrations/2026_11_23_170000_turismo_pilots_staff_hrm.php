<?php

use App\Support\TransportDemoDay;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Turismo Cautivo: people first (user, 2026-11-23: "crea los usuarios y actualiza los pilotos, habilita en manager el
 * módulo de RRHH y pon ahí a todos los pilotos, Daniel, Mayte y Julio, pero no envíes ningún email.. solo crea un usuario
 * y correo"; the operational data -- buses, timetable, tolls -- waits: "datos.. no aún"):
 *  - the 47 real pilots of Operaciones' Piloto.xlsx (all on the payroll) take the demo pilots' places, names and WhatsApp
 *    numbers (TransportDemoDay::realPilots; the demo already assigns them its trips);
 *  - the agency staff of Agencia.xlsx: an employee with its login (role Agencia, its agency's location), username + a
 *    placeholder email (@example.com, never delivered). Nothing is emailed: creating a login sends no mail;
 *  - the Manager role gets HRM (employees view / add / edit, departments, designations, shifts, attendance, leave,
 *    holidays, payroll), so Mayte sees the pilots, Daniel, Mayte and Julio in HRM -> Employees (they already have their
 *    employee record).
 * By company and record names. Safe to run twice.
 */
return new class extends Migration
{
    private const PILOTS = [
        'ALEJANDRO CUENCA ELISEO SAMUEL|921046525', 'AMAYA BARSALLO SEGUNDO BERNABE|950657949', 'ARAUCANO JARA ISRAEL JUNNIOR|988502803',
        'AYASTA GRANADOS FREDDY RAUL|962765600', 'CASTRO DE LA CRUZ WILMAN DAVID|929162000', 'CHAFLOQUE YAMPUFE PEDRO|979034453',
        'CHALLE LEYVA MIGUEL ANGEL|938955283', 'CHAVEZ HUAMAN AUGUSTO|969438911', 'CONDOR ASMAT JOSE LUIS|999503797',
        'COPERTINO RODRIGUEZ CAYO CARLOS|941918842', 'CUBAS OBLITAS JHONNY ROBERTO|980887756', 'CUEVA VASQUEZ JOSE AGUSTIN|927411460',
        'GARCIA TOLENTINO BETO ADAN|926979534', 'GUERRA HUAYRE WILLIAMS EUSEBIO|929573215', 'JUAREZ CARRIL JAVIER SALOMÓN|920096308',
        'LEIVA ESPINOZA JUAN FIDEL|930212422', 'LEIVA ESPINOZA WILDER PABLO|969035563', 'LEON HERNANDEZ CARLOS HUGO|980879344',
        'MALLQUI ESTACIO JUBER JASMEN|958164214', 'MARTINEZ OLANO TEOFILO FRANCO|904194593', 'MAYHUIRE MUÑOZ CARLOS|972333120',
        'MIÑOPE GAMARRA JORGE ARMANDO|968085651', 'MIRES VASQUEZ JESUS FILIBERTO|945528485', 'MUÑOZ RAMIREZ ELMER|912755589',
        'NUÑEZ MEJIA SEGUNDO LORENZO|982327641', 'PAISIG CARRANZA RODNEY|968769756', 'PASAPERA TORRES YELSON|923064665',
        'PEÑA VARGAS BRAULIO|993837632', 'PEREZ DIAZ ROY ROGERES|901529312', 'PISFIL GUZMAN JUAN PABLO|964866452',
        'POLONIO CABANILLAS FERNANDO JOEL|973068736', 'PRINCIPE CARNERO JOSE MANUEL|913640291', 'RAMIREZ TORRES VICTOR ORLANDO|981265610',
        'RAMOS MAZA ALEX HENRY|933524759', 'ROJAS SANCHEZ ERNESTO|960855965', 'ROMAN TOLENTINO PEPE WUILI|986243586',
        'SANDOVAL ZEÑA CELSO DAVID|970255183', 'SERRANO SANDOVAL CESAR ANIBAL|928585829', 'TELLO PARIATANTA BRITALDO|907322129',
        'TORRES MONTENEGRO ROGNAR RIGOBERTO|912991287', 'TROYA LUCERO GRIMALDO|955638635', 'UBIA HENRIQUEZ PEDRO CESAR|976726700',
        'VALDIVIA VASQUEZ RONAL KICO|959728280', 'VALERIO PONCE MARIO FAUSTO|906442743', 'VASQUEZ VASQUEZ CARLOS MANUEL|942100556',
        'YUPANQUI CHUQUIPIONDO JOSE LUIS|958295412', 'ZAMORA VALENZUELA ANTHONY JOB|967557727',
    ];

    // [first name, last name, phone, agency, point · hours]
    private const STAFF = [
        ['Walter', 'Delgado More', '954295278', 'LIMA', 'Cavassa (Lima) · 1 pm - 9 pm'],
        ['Sandibel', 'Ascencio Vichez', '954295796', 'LIMA', 'Pza. Norte (Lima) · 2 pm - 10 pm'],
        ['Claudia', 'Carranza Mendez', '972569460', 'LIMA', 'Ter. Pza. Los Olivos (Lima) · 2 pm - 10 pm'],
        ['Maria', 'Escobedo', '948072550', 'TRUJILLO', 'Trujillo · 2 pm - 10 pm'],
        ['Nancy', 'Quispe', '', 'TRUJILLO', 'Trujillo · 2 pm - 10 pm (comparte celular con Maria Escobedo)'],
        ['Luis', 'Bringas Portilla', '950957731', 'CHEPEN', 'Chepén · 4 pm - 8 pm'],
        ['Hady', 'Facho Monja', '950957046', 'CHICLAYO', 'Chiclayo · 4 pm - 8 pm'],
        ['Jorge', 'Castillo Quispe', '951403204', 'CHICLAYO', 'Lambayeque · 4 pm - 8 pm'],
    ];

    private const MANAGER_HRM = ['view_employee', 'add_employee', 'edit_employee', 'department', 'designation', 'office_shift',
        'attendance', 'leave', 'holiday', 'payroll'];

    public function up(): void
    {
        $cid = DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->value('id');
        if (! $cid) {
            return;
        }

        // ---- the 47 pilots
        $tc = fn ($s) => mb_convert_case(mb_strtolower($s), MB_CASE_TITLE, 'UTF-8');
        $pilots = array_map(function ($line) use ($tc) {
            [$full, $phone] = explode('|', $line);
            $w = preg_split('/\s+/', trim($full));
            // "CASTRO DE LA CRUZ WILMAN DAVID": a compound surname keeps its particles.
            $lastWords = in_array($w[1] ?? '', ['DE', 'DEL'], true) ? 4 : 2;

            return [$tc(implode(' ', array_slice($w, 0, $lastWords))), $tc(implode(' ', array_slice($w, $lastWords))), $phone];
        }, self::PILOTS);
        if (DB::table('demo_records')->where('company_id', $cid)->where('record_type', 'employees')->exists()) {
            TransportDemoDay::realPilots((int) $cid, $pilots);
        }

        // ---- agency staff (real people, not demo data)
        $role = DB::table('roles')->where('company_id', $cid)->where('name', 'Agencia')->whereNull('deleted_at')->value('id');
        $like = DB::table('employees')->where('company_id', $cid)->whereNull('deleted_at')->where('staff_type', 'administrative')->orderBy('id')->first();
        foreach (self::STAFF as [$fn, $ln, $phone, $agency, $where]) {
            if (! $role || ! $like || DB::table('employees')->where('company_id', $cid)->whereNull('deleted_at')->where('firstname', $fn)->where('lastname', $ln)->exists()) {
                continue;
            }
            $wid = DB::table('transport_locations')->where('company_id', $cid)->where('name', $agency)->value('warehouse_id');
            $base = strtolower(preg_replace('/[^a-z.]/i', '', \Illuminate\Support\Str::ascii($fn.'.'.explode(' ', $ln)[0])));
            $e = \App\Models\Employee::create([
                'company_id' => $cid, 'firstname' => $fn, 'lastname' => $ln, 'username' => $fn.' '.$ln, 'email' => $base.'.turismo@example.com',
                'phone' => $phone ?: null, 'city' => $where,
                'gender' => in_array($fn, ['Sandibel', 'Claudia', 'Maria', 'Nancy', 'Hady'], true) ? 'female' : 'male', 'avatar' => 'no_avatar.png',
                'department_id' => $like->department_id, 'designation_id' => $like->designation_id, 'office_shift_id' => $like->office_shift_id,
                'joining_date' => now()->toDateString(), 'employment_type' => 'full_time', 'total_leave' => 0, 'remaining_leave' => 0, 'basic_salary' => 0,
            ]);
            DB::table('employees')->where('id', $e->id)->update(['staff_type' => 'administrative', 'whatsapp' => $phone ?: null]);
            $uid = (int) DB::table('employees')->where('id', $e->id)->value('user_id');
            if ($uid) {
                DB::table('users')->where('id', $uid)->update(['role_id' => $role, 'phone' => $phone ?: null, 'is_all_warehouses' => 0, 'updated_at' => now()]);
                DB::table('role_user')->where('user_id', $uid)->delete();
                DB::table('role_user')->insert(['user_id' => $uid, 'role_id' => $role]);
                if ($wid) {
                    DB::table('user_warehouse')->where('user_id', $uid)->delete();
                    DB::table('user_warehouse')->insert(['user_id' => $uid, 'warehouse_id' => $wid]);
                }
            }
        }

        // ---- HRM for the Manager
        $manager = DB::table('roles')->where('company_id', $cid)->where('name', 'Manager')->whereNull('deleted_at')->value('id');
        if ($manager) {
            foreach (DB::table('permissions')->whereIn('name', self::MANAGER_HRM)->pluck('id') as $pid) {
                if (! DB::table('permission_role')->where('role_id', $manager)->where('permission_id', $pid)->exists()) {
                    DB::table('permission_role')->insert(['role_id' => $manager, 'permission_id' => $pid]);
                }
            }
            // A dashboard tab list (roles.dashboard_tabs) does not hide menus; the HRM group is shown in Turismo's menu.
            DB::table('menu_positions')->where('company_id', $cid)->whereIn('item_key', ['hrm', '/app/hrm/employees'])->update(['hidden' => 0]);
        }
    }

    public function down(): void
    {
    }
};
