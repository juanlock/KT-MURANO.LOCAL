<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Project questions (user, 2026-11-23): a question for one member of a project, tied to a task, answered from the PREGUNTAS
 * window (top bar, red while some are pending; it opens by itself at sign-in). The answer becomes an activity of its task,
 * so the project keeps the information with who answered and when.
 *
 *  - the person asked (any company, like Mis proyectos): GET my/questions, POST my/questions/{id}/answer, POST my/questions/later;
 *  - the project's team (SuperAdmin, an editor of the project's company, or its project manager): GET / POST
 *    projects/{id}/questions, PUT / DELETE project-questions/{id}.
 */
class ProjectQuestionController extends Controller
{
    public const KINDS = ['yesno', 'choice', 'number', 'text', 'confirm'];

    // ------------------------------------------------------------------ the person asked

    public function mine(Request $request)
    {
        $uid = (int) optional($request->user('api'))->id;
        $rows = DB::table('project_questions as q')->join('projects as p', 'p.id', '=', 'q.project_id')->leftJoin('tasks as t', 't.id', '=', 'q.task_id')
            ->where('q.user_id', $uid)->whereNull('q.deleted_at')->where('q.status', '!=', 'cancelled')->whereNull('p.deleted_at')
            ->orderByRaw("q.status = 'pending' desc")->orderByDesc('q.urgent')->orderBy('q.project_id')->orderBy('q.sort')->orderBy('q.id')
            ->limit(300)->get(['q.id', 'q.project_id', 'p.title as project', 'q.task_id', 't.title as task', 'q.kind', 'q.question', 'q.help', 'q.options',
                'q.current_value', 'q.urgent', 'q.status', 'q.answer', 'q.comment', 'q.answered_at', 'q.snoozed_until']);
        $rows->each(fn ($r) => $r->options = $r->options ? json_decode($r->options, true) : null);
        $pending = $rows->where('status', 'pending');

        return response()->json([
            'questions' => $rows->values(),
            'pending' => $pending->count(),
            // Opens by itself at sign-in while some are pending and not put off ("Más tarde").
            'popup' => $pending->contains(fn ($q) => ! $q->snoozed_until || $q->snoozed_until < now()->toDateTimeString()),
        ]);
    }

    public function answer(Request $request, int $id)
    {
        $uid = (int) optional($request->user('api'))->id;
        $q = DB::table('project_questions')->where('id', $id)->where('user_id', $uid)->whereNull('deleted_at')->first();
        abort_unless($q, 404);
        abort_if($q->status !== 'pending', 422, __('This question was already answered.'));
        $d = $request->validate(['answer' => 'required|string|max:5000', 'comment' => 'nullable|string|max:2000']);
        $answer = trim($d['answer']);
        if ($q->kind === 'number') {
            abort_unless(is_numeric(str_replace(',', '.', $answer)), 422, __('Write a number.'));
        }
        if (in_array($q->kind, ['choice', 'yesno'], true)) {
            $opts = $q->kind === 'yesno' ? ['Sí', 'No'] : (json_decode((string) $q->options, true) ?: []);
            abort_unless(in_array($answer, $opts, true), 422, __('Pick one of the options.'));
        }
        DB::transaction(function () use ($q, $answer, $d, $uid) {
            $activity = null;
            if ($q->task_id) {
                $note = "**Pregunta:** {$q->question}\n\n**Respuesta:** ".($q->kind === 'confirm' && $answer === 'Correcto' ? "Correcto ({$q->current_value})" : $answer)
                    .(! empty($d['comment']) ? "\n\n**Comentario:** ".trim($d['comment']) : '');
                $activity = DB::table('task_activities')->insertGetId([
                    'company_id' => $q->company_id, 'project_id' => $q->project_id, 'task_id' => $q->task_id, 'user_id' => $uid,
                    'note' => $note, 'progress' => null, 'hours' => null, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('project_questions')->where('id', $q->id)->update(['status' => 'answered', 'answer' => $answer, 'comment' => $d['comment'] ?? null,
                'answered_at' => now(), 'answered_by' => $uid, 'activity_id' => $activity, 'updated_at' => now()]);
        });

        return response()->json(['ok' => true]);
    }

    /** "Más tarde": the window does not open by itself again until tomorrow (an urgent question: in 4 hours). */
    public function later(Request $request)
    {
        $uid = (int) optional($request->user('api'))->id;
        $base = DB::table('project_questions')->where('user_id', $uid)->where('status', 'pending')->whereNull('deleted_at');
        (clone $base)->where('urgent', 1)->update(['snoozed_until' => now()->addHours(4)]);
        (clone $base)->where('urgent', 0)->update(['snoozed_until' => now()->addDay()->startOfDay()->addHours(6)]);

        return response()->json(['ok' => true]);
    }

    /** For the user's payload: pending count, total and whether the window should open. */
    public static function summaryFor(int $uid): array
    {
        if (! $uid || ! \Illuminate\Support\Facades\Schema::hasTable('project_questions')) {
            return ['pending' => 0, 'total' => 0, 'popup' => false];
        }
        $base = DB::table('project_questions')->where('user_id', $uid)->whereNull('deleted_at')->where('status', '!=', 'cancelled');
        $pending = (clone $base)->where('status', 'pending');

        return [
            'pending' => (clone $pending)->count(),
            'total' => (clone $base)->count(),
            'popup' => (clone $pending)->where(fn ($w) => $w->whereNull('snoozed_until')->orWhere('snoozed_until', '<', now()))->exists(),
        ];
    }

    // ------------------------------------------------------------------ the project's team

    private function project(Request $request, $projectId): Project
    {
        $p = Project::whereNull('deleted_at')->findOrFail((int) $projectId);
        $u = $request->user('api');
        $ok = $u && ($u->type === 'SuperAdmin'
            || ($u->can('update', Project::class) && (int) $u->company_id === (int) $p->company_id)
            || DB::table('employee_project as ep')->join('employees as e', 'e.id', '=', 'ep.employee_id')
                ->where('ep.project_id', $p->id)->where('ep.is_manager', 1)->where('e.user_id', $u->id)->exists());
        abort_unless($ok, 403, __('Only the project team can manage its questions.'));

        return $p;
    }

    public function index(Request $request, $projectId)
    {
        $p = $this->project($request, $projectId);
        $rows = DB::table('project_questions as q')->leftJoin('users as u', 'u.id', '=', 'q.user_id')->leftJoin('tasks as t', 't.id', '=', 'q.task_id')
            ->leftJoin('companies as c', 'c.id', '=', 'u.company_id')
            ->where('q.project_id', $p->id)->whereNull('q.deleted_at')
            ->when($request->filled('task_id'), fn ($w) => $w->where('q.task_id', (int) $request->task_id))
            ->orderByRaw("q.status = 'pending' desc")->orderByDesc('q.urgent')->orderBy('q.sort')->orderBy('q.id')
            ->get(['q.*', DB::raw("TRIM(CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, ''))) as person"), 'c.name as person_company', 't.title as task']);
        $rows->each(fn ($r) => $r->options = $r->options ? json_decode($r->options, true) : null);
        // Who can be asked: the project's members with a login; the tasks to tie a question to.
        $members = DB::table('employee_project as ep')->join('employees as e', 'e.id', '=', 'ep.employee_id')->leftJoin('companies as c', 'c.id', '=', 'e.company_id')
            ->where('ep.project_id', $p->id)->whereNull('e.deleted_at')->whereNotNull('e.user_id')
            ->orderBy('e.firstname')->get(['e.user_id', DB::raw("TRIM(CONCAT(e.firstname, ' ', e.lastname)) as name"), 'c.name as company'])->unique('user_id')->values();
        $tasks = DB::table('tasks')->where('project_id', $p->id)->whereNull('deleted_at')->orderBy('title')->get(['id', 'title']);

        return response()->json(['questions' => $rows, 'members' => $members, 'tasks' => $tasks]);
    }

    private function validated(Request $request): array
    {
        $d = $request->validate([
            'user_id' => 'required|integer', 'task_id' => 'nullable|integer', 'kind' => 'required|in:'.implode(',', self::KINDS),
            'question' => 'required|string|max:2000', 'help' => 'nullable|string|max:2000', 'options' => 'nullable|array', 'options.*' => 'string|max:200',
            'current_value' => 'nullable|string|max:500', 'urgent' => 'nullable|boolean',
        ]);
        if ($d['kind'] === 'choice') {
            $opts = array_values(array_filter(array_map('trim', $d['options'] ?? []), fn ($o) => $o !== ''));
            abort_if(count($opts) < 2, 422, __('A choice needs at least two options.'));
            $d['options'] = $opts;
        } else {
            $d['options'] = null;
        }

        return $d;
    }

    public function store(Request $request, $projectId)
    {
        $p = $this->project($request, $projectId);
        $d = $this->validated($request);
        abort_unless(DB::table('employee_project as ep')->join('employees as e', 'e.id', '=', 'ep.employee_id')
            ->where('ep.project_id', $p->id)->where('e.user_id', $d['user_id'])->exists(), 422, __('The person must be a member of the project.'));
        abort_if($d['task_id'] && ! DB::table('tasks')->where('id', $d['task_id'])->where('project_id', $p->id)->exists(), 422);
        $id = DB::table('project_questions')->insertGetId([
            'company_id' => $p->company_id, 'project_id' => $p->id, 'task_id' => $d['task_id'] ?? null, 'user_id' => $d['user_id'],
            'kind' => $d['kind'], 'question' => $d['question'], 'help' => $d['help'] ?? null,
            'options' => $d['options'] ? json_encode($d['options'], JSON_UNESCAPED_UNICODE) : null, 'current_value' => $d['current_value'] ?? null,
            'urgent' => ! empty($d['urgent']), 'status' => 'pending',
            'sort' => (int) DB::table('project_questions')->where('project_id', $p->id)->max('sort') + 1,
            'created_by' => optional($request->user('api'))->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['ok' => true, 'id' => $id]);
    }

    public function update(Request $request, int $id)
    {
        $q = DB::table('project_questions')->where('id', $id)->whereNull('deleted_at')->first();
        abort_unless($q, 404);
        $this->project($request, $q->project_id);
        if ($request->boolean('reopen')) {
            // Ask again (the answer stays in the task's activity).
            DB::table('project_questions')->where('id', $id)->update(['status' => 'pending', 'answer' => null, 'comment' => null, 'answered_at' => null,
                'answered_by' => null, 'activity_id' => null, 'snoozed_until' => null, 'updated_at' => now()]);

            return response()->json(['ok' => true]);
        }
        $d = $this->validated($request);
        DB::table('project_questions')->where('id', $id)->update(['user_id' => $d['user_id'], 'task_id' => $d['task_id'] ?? null, 'kind' => $d['kind'],
            'question' => $d['question'], 'help' => $d['help'] ?? null, 'options' => $d['options'] ? json_encode($d['options'], JSON_UNESCAPED_UNICODE) : null,
            'current_value' => $d['current_value'] ?? null, 'urgent' => ! empty($d['urgent']), 'updated_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, int $id)
    {
        $q = DB::table('project_questions')->where('id', $id)->whereNull('deleted_at')->first();
        abort_unless($q, 404);
        $this->project($request, $q->project_id);
        DB::table('project_questions')->where('id', $id)->update(['deleted_at' => now(), 'updated_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
