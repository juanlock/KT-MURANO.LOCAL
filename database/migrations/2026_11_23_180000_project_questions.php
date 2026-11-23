<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Project questions (user, 2026-11-23: "esas preguntas puedes ponerlas como un popup al iniciar sesión, para que marque o
 * escriba.. un medio de ingreso de información que actualizará la información de las tareas.. un botón al lado de
 * Proyectos que diga PREGUNTAS y si hay alguna en rojo"): a question for one member of a project, tied to a task, of a kind
 * (yes/no, choice, number, text, confirm a value we have); the person answers it from the PREGUNTAS window (it opens by
 * itself at sign-in while some are pending) and the answer becomes an activity of the task. Plus the questions of the
 * 2026-11-23 analysis of Operaciones' answers, for Daniel (KcolTech's project "Turismo Cautivo 2026", task "Respuestas de
 * Daniel a los cuestionarios"). Safe to run twice.
 */
return new class extends Migration
{
    // [code, kind, question, help, options, current value, urgent]
    private const DANIEL = [
        ['vip_confort', 'number', '¿Cuántos asientos VIP tiene un bus CONFORT (Bus 170 y 180)?', 'Hoy suponemos 15.', null, null, false],
        ['vip_master', 'number', '¿Cuántos asientos VIP tiene un bus MASTER (Bus 200, 210, 220, 230, 300, 310, 320, 330, 340 y 350)?', 'Hoy suponemos 15.', null, null, false],
        ['vip_premium', 'number', '¿Cuántos asientos VIP tiene un bus PREMIUM (Bus 240 y 250)?', 'Hoy suponemos 15.', null, null, false],
        ['vip_normal_premium', 'number', '¿Cuántos asientos VIP tiene el bus NORMAL PREMIUM (Bus 260)?', 'Hoy suponemos 15.', null, null, false],
        ['vip_ejecutivo_vip', 'number', '¿Cuántos asientos VIP tiene un bus EJECUTIVO VIP (Bus 370 y 380)?', 'Hoy suponemos 15.', null, null, false],
        ['first_floor', 'text', '¿Cuántos asientos van en el primer piso de cada categoría de bus?', 'Por ejemplo: CONFORT 12, MASTER 12, PREMIUM 11…', null, null, false],
        ['clash_210', 'text', 'De lunes a sábado, el Bus 210 sale dos veces de Chiclayo (18:30 y 19:30). ¿Qué bus hace la salida de las 19:30?', 'Mientras tanto el sistema pone un bus MASTER libre (330).', null, null, true],
        ['clash_200', 'text', 'De lunes a sábado, el Bus 200 sale de Lima a las 22:00 y de Chiclayo a las 17:30 el mismo día. ¿Cuál es el bus de cada salida?', 'Mientras tanto el sistema pone el Bus 320 en la salida de Lima de las 22:00.', null, null, true],
        ['clash_sunday', 'text', 'Los domingos, el Bus 180 sale de Lima (19:00) y de Chiclayo (16:00), y el Bus 220 de Lima (18:00) y de Chiclayo (16:15). ¿Qué buses hacen esas salidas?', 'Con 14 horas de viaje no puede ser el mismo bus el mismo día.', null, null, true],
        ['habitual_bus', 'choice', 'El «bus habitual» de cada salida, ¿es siempre el mismo o solo importa la categoría?', null, ['Es siempre el mismo bus', 'Solo importa la categoría', 'Cambia según el día'], null, false],
        ['toll_stretches', 'text', 'Peaje por tramo (S/): Lima–Trujillo, Trujillo–Chepén y Chepén–Chiclayo.', 'Si no lo tiene a mano, lo repartimos por kilómetros con el total de 329.30.', null, null, false],
        ['diesel_price', 'number', 'Precio actual del galón de diésel (S/).', 'Con 135-140 galones por viaje, sirve para calcular el combustible de cada salida.', null, null, false],
        ['fuel_who', 'choice', '¿Quién registra el combustible que carga cada bus?', null, ['El piloto', 'Operaciones', 'La agencia', 'Nadie todavía'], null, false],
        ['gps', 'choice', '¿Los buses tienen GPS?', 'Si tienen, escriba en el comentario el proveedor y si da acceso a los datos.', ['Sí, todos', 'Algunos', 'No'], null, false],
        ['nancy_phone', 'text', 'Celular (WhatsApp) de Nancy Quispe, agencia Trujillo.', 'El acceso por WhatsApp funciona en un solo celular por persona; hoy comparte el de María Escobedo.', null, null, false],
        ['addresses', 'text', 'Dirección de los puntos de embarque: Plaza Norte (P.N.), Cavassa (CV), Terminal Los Olivos, Lambayeque y Terminal Chiclayo.', 'Con la dirección calculamos solos la distancia y la hora de llegada a cada punto.', null, null, false],
        ['plate_220', 'confirm', 'Placa del Bus 220.', 'En la hoja figura «D4i967».', null, 'D4I967', false],
        ['delay_rule', 'confirm', 'Aviso de atraso: la alerta sale si el bus no llegó al punto de embarque 5 minutos antes de la hora de salida.', null, null, '5 minutos antes de la salida', false],
        ['points_codes', 'confirm', 'Puntos de embarque: TER. CIX = Terminal Chiclayo, CHEPEN = Terminal Chepén y TRUJILLO II = Terrapuerto Trujillo.', null, null, 'TER. CIX, CHEPEN y TRUJILLO II como arriba', false],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('project_questions')) {
            Schema::create('project_questions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('project_id')->index();
                $table->unsignedBigInteger('task_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->index();        // the person asked (any company)
                $table->string('code', 60)->nullable();                // a stable key (seeded questions)
                $table->string('kind', 20)->default('text');           // yesno | choice | number | text | confirm
                $table->text('question');
                $table->text('help')->nullable();
                $table->text('options')->nullable();                   // JSON list (choice)
                $table->text('current_value')->nullable();             // what we have (confirm)
                $table->boolean('urgent')->default(false);
                $table->string('status', 20)->default('pending');      // pending | answered | cancelled
                $table->text('answer')->nullable();
                $table->text('comment')->nullable();
                $table->timestamp('answered_at')->nullable();
                $table->unsignedBigInteger('answered_by')->nullable();
                $table->unsignedBigInteger('activity_id')->nullable();
                $table->timestamp('snoozed_until')->nullable();
                $table->unsignedInteger('sort')->default(0);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['user_id', 'status']);
            });
        }

        // Daniel's questions of the 2026-11-23 analysis.
        $project = DB::table('projects')->where('title', 'like', 'Turismo Cautivo 2026%')->whereNull('deleted_at')->first(['id', 'company_id']);
        $turismo = DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->value('id');
        $daniel = $turismo ? DB::table('users')->where('company_id', $turismo)->where('username', 'operaciones')->whereNull('deleted_at')->value('id') : null;
        if (! $project || ! $daniel) {
            return;
        }
        $task = DB::table('tasks')->where('project_id', $project->id)->where('title', 'Respuestas de Daniel a los cuestionarios')->whereNull('deleted_at')->value('id');
        $by = DB::table('users')->where('firstname', 'Team')->where('lastname', 'Desarrollo')->value('id');
        foreach (self::DANIEL as $i => [$code, $kind, $q, $help, $options, $current, $urgent]) {
            if (DB::table('project_questions')->where('project_id', $project->id)->where('code', $code)->exists()) {
                continue;
            }
            DB::table('project_questions')->insert([
                'company_id' => $project->company_id, 'project_id' => $project->id, 'task_id' => $task, 'user_id' => $daniel, 'code' => $code,
                'kind' => $kind, 'question' => $q, 'help' => $help, 'options' => $options ? json_encode($options, JSON_UNESCAPED_UNICODE) : null,
                'current_value' => $current, 'urgent' => $urgent, 'status' => 'pending', 'sort' => $i + 1, 'created_by' => $by,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('project_questions');
    }
};
