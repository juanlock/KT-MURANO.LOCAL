<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\Task;
use App\Support\CompanyFiles;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A project's people, documents and templates (user, 2026-11-21).
 * - Members (employee_project + role + is_manager): employees of the project's company or of the client's own Murano
 *   company (projects.client_company_id, set by a SuperAdmin only). The manager ("Jefe de proyecto") gets new tasks by default.
 * - Documents (project_documents): files / images attached to the tasks while they are worked on; private files in
 *   files/company_{project company}/projects/, served only here (permission + project in scope + owns()).
 * - Templates (project_templates): stages + tasks + role + approximate dates by type of project; applying one adds them
 *   to the project in one transaction; a project can be saved as a template of its company.
 */
class ProjectTeamController extends Controller
{
    use \App\Http\Controllers\Concerns\ScopesProjects;

    private function project(Request $request, $id, string $ability = 'view'): Project
    {
        $project = Project::whereNull('deleted_at')->findOrFail($id);
        // A member of the project sees it whatever company they belong to (the client's staff, 2026-11-22).
        if ($ability === 'view' && $this->myEmployeeIds($request, $project->id)) {
            return $project;
        }
        $this->authorizeForUser($request->user('api'), $ability, Project::class);
        $this->abortUnlessProjectInScope($request, $project->id);

        return $project;
    }

    /** The signed-in person's employee records that are members of the project. */
    private function myEmployeeIds(Request $request, int $projectId): array
    {
        $uid = optional($request->user('api'))->id;
        if (! $uid) {
            return [];
        }

        return DB::table('employee_project as ep')->join('employees as e', 'e.id', '=', 'ep.employee_id')
            ->where('ep.project_id', $projectId)->where('e.user_id', $uid)->whereNull('e.deleted_at')
            ->pluck('e.id')->map(fn ($v) => (int) $v)->all();
    }

    /** Can edit the project (its company's staff with the permission), not only take part in it. */
    private function isEditor(Request $request, Project $project): bool
    {
        $u = $request->user('api');
        if (! $u || ! $u->can('update', Project::class)) {
            return false;
        }
        $ids = $this->projectIdsInScope($request);

        return $ids === null || in_array((int) $project->id, $ids, true);
    }

    /** A member may write on a task assigned to them (or any task when they are the project manager). */
    private function canWorkOnTask(Request $request, Task $task): bool
    {
        $project = Project::find($task->project_id);
        if ($project && $this->isEditor($request, $project)) {
            return true;
        }
        $mine = $this->myEmployeeIds($request, (int) $task->project_id);
        if (! $mine) {
            return false;
        }
        if (DB::table('employee_project')->where('project_id', $task->project_id)->whereIn('employee_id', $mine)->where('is_manager', 1)->exists()) {
            return true;
        }

        return DB::table('employee_task')->where('task_id', $task->id)->whereIn('employee_id', $mine)->exists();
    }

    private static function name($e): string
    {
        return trim(($e->firstname ?? '').' '.($e->lastname ?? '')) ?: (string) $e->username;
    }

    /** Companies whose employees can be members: the project's and the client's Murano company. */
    private function memberCompanies(Project $project): array
    {
        return array_values(array_unique(array_filter([(int) $project->company_id, (int) $project->client_company_id])));
    }

    public static function members(int $projectId)
    {
        $project = Project::find($projectId);

        return DB::table('employee_project as ep')->join('employees as e', 'e.id', '=', 'ep.employee_id')
            ->leftJoin('companies as c', 'c.id', '=', 'e.company_id')
            ->where('ep.project_id', $projectId)
            ->orderByDesc('ep.is_manager')->orderBy('e.firstname')
            ->get(['e.id', 'e.firstname', 'e.lastname', 'e.username', 'e.email', 'e.company_id', 'c.name as company_name', 'ep.role', 'ep.is_manager'])
            ->map(fn ($e) => [
                'id' => (int) $e->id, 'name' => self::name($e), 'email' => $e->email, 'company_id' => (int) $e->company_id,
                'company_name' => $e->company_name, 'role' => $e->role, 'is_manager' => (bool) $e->is_manager,
                'side' => $project && (int) $e->company_id === (int) $project->company_id ? 'own' : 'client',
            ])->values();
    }

    /** GET projects/{id}/members */
    public function index(Request $request, $id)
    {
        $project = $this->project($request, $id);
        $members = self::members($project->id);
        $in = $members->pluck('id')->all();
        $candidates = Employee::whereIn('company_id', $this->memberCompanies($project))->whereNull('deleted_at')
            ->whereNotIn('id', $in ?: [0])->orderBy('firstname')->get(['id', 'firstname', 'lastname', 'username', 'company_id'])
            ->map(fn ($e) => ['id' => $e->id, 'name' => self::name($e), 'company_id' => (int) $e->company_id,
                'side' => (int) $e->company_id === (int) $project->company_id ? 'own' : 'client'])->values();

        $isSuper = optional($request->user('api'))->type === 'SuperAdmin';
        $roles = collect(DB::table('project_templates')->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $project->company_id))->pluck('data'))
            ->flatMap(fn ($d) => (array) (json_decode($d, true)['roles'] ?? []))
            ->merge(DB::table('employee_project')->whereNotNull('role')->where('role', '!=', '')->distinct()->pluck('role'))
            ->unique()->sort()->values();

        return response()->json([
            'members' => $this->membersWithLogins($request, $project), // with each one's user and standard password (2026-11-22)
            'candidates' => $candidates,
            'roles' => $roles,
            'company' => ['id' => $project->company_id, 'name' => DB::table('companies')->where('id', $project->company_id)->value('name')],
            'client_company' => $project->client_company_id
                ? ['id' => (int) $project->client_company_id, 'name' => DB::table('companies')->where('id', $project->client_company_id)->value('name')]
                : null,
            'can_pick_client_company' => $isSuper,
            'companies' => $isSuper ? DB::table('companies')->where('id', '!=', 999)->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']) : [],
        ]);
    }

    /** POST projects/{id}/members {employee_ids[], role, is_manager} */
    public function store(Request $request, $id)
    {
        $project = $this->project($request, $id, 'update');
        $request->validate(['employee_ids' => 'required|array|min:1', 'role' => 'nullable|string|max:80']);
        $ids = array_values(array_unique(array_map('intval', $request->employee_ids)));
        abort_unless(Employee::whereIn('id', $ids)->whereIn('company_id', $this->memberCompanies($project))->whereNull('deleted_at')->count() === count($ids),
            422, __('An employee does not belong to this project\'s companies.'));

        DB::transaction(function () use ($project, $ids, $request) {
            $have = DB::table('employee_project')->where('project_id', $project->id)->pluck('employee_id')->map(fn ($v) => (int) $v)->all();
            foreach ($ids as $eid) {
                if (! in_array($eid, $have, true)) {
                    DB::table('employee_project')->insert(['project_id' => $project->id, 'employee_id' => $eid,
                        'role' => $request->role ?: null, 'is_manager' => 0]);
                }
            }
            if ($request->boolean('is_manager') && count($ids) === 1) {
                $this->setManager($project->id, $ids[0]);
            }
        });

        return response()->json(['success' => true]);
    }

    private function setManager(int $projectId, int $employeeId): void
    {
        DB::table('employee_project')->where('project_id', $projectId)->update(['is_manager' => 0]);
        DB::table('employee_project')->where('project_id', $projectId)->where('employee_id', $employeeId)
            ->update(['is_manager' => 1]);
        DB::table('employee_project')->where('project_id', $projectId)->where('employee_id', $employeeId)
            ->where(fn ($q) => $q->whereNull('role')->orWhere('role', ''))->update(['role' => 'Jefe de proyecto']);
    }

    /** PUT projects/{id}/members/{employee} {role, is_manager} */
    public function update(Request $request, $id, $employee)
    {
        $project = $this->project($request, $id, 'update');
        $request->validate(['role' => 'nullable|string|max:80']);
        $q = DB::table('employee_project')->where('project_id', $project->id)->where('employee_id', (int) $employee);
        abort_unless($q->exists(), 404);
        DB::transaction(function () use ($request, $q, $project, $employee) {
            if ($request->has('role')) {
                (clone $q)->update(['role' => $request->role ?: null]);
            }
            if ($request->has('is_manager')) {
                if ($request->boolean('is_manager')) {
                    $this->setManager($project->id, (int) $employee);
                } else {
                    (clone $q)->update(['is_manager' => 0]);
                }
            }
        });

        return response()->json(['success' => true]);
    }

    /** DELETE projects/{id}/members/{employee} -- the person stays on the tasks already assigned to them. */
    public function destroy(Request $request, $id, $employee)
    {
        $project = $this->project($request, $id, 'update');
        DB::table('employee_project')->where('project_id', $project->id)->where('employee_id', (int) $employee)->delete();

        return response()->json(['success' => true]);
    }

    /** POST projects/{id}/client-company {client_company_id|null} -- SuperAdmin only (it opens another company's staff list). */
    public function clientCompany(Request $request, $id)
    {
        $project = $this->project($request, $id, 'update');
        abort_unless(optional($request->user('api'))->type === 'SuperAdmin', 403);
        $cid = (int) $request->client_company_id ?: null;
        abort_if($cid && ($cid === (int) $project->company_id || ! DB::table('companies')->where('id', $cid)->exists()), 422);
        $project->client_company_id = $cid;
        $project->save();

        return response()->json(['success' => true]);
    }

    /** GET projects/{id}/task-meta -- what the task form needs: stages, members (default = the manager), the project's staff. */
    public function taskMeta(Request $request, $id)
    {
        $project = $this->project($request, $id);
        $members = self::members($project->id);
        $staff = Employee::where('company_id', $project->company_id)->whereNull('deleted_at')
            ->whereNotIn('id', $members->pluck('id')->all() ?: [0])->orderBy('firstname')
            ->get(['id', 'firstname', 'lastname', 'username'])->map(fn ($e) => ['id' => $e->id, 'name' => self::name($e)]);

        return response()->json([
            'project' => ['id' => $project->id, 'title' => $project->title, 'company_id' => $project->company_id,
                'start_date' => $project->start_date, 'end_date' => $project->end_date],
            'stages' => ProjectMilestone::whereNull('deleted_at')->where('project_id', $project->id)
                ->orderBy('position')->orderBy('due_date')->get(['id', 'title', 'due_date']),
            'objectives' => DB::table('project_objectives')->where('project_id', $project->id)->whereNull('deleted_at')->orderBy('position')->get(['id', 'title']),
            'members' => $members,
            'staff' => $staff,
            'manager_id' => optional($members->firstWhere('is_manager', true))['id'] ?? null,
        ]);
    }

    // ---------------------------------------------------------------- documents

    private function presentDoc($d, array $taskTitles = [])
    {
        return [
            'id' => $d->id, 'name' => $d->name, 'mime' => $d->mime, 'size' => (int) $d->size, 'note' => $d->note,
            'task_id' => $d->task_id, 'task_title' => $d->task_id ? ($taskTitles[$d->task_id] ?? null) : null,
            'is_image' => str_starts_with((string) $d->mime, 'image/'), 'is_video' => str_starts_with((string) $d->mime, 'video/'),
            'url' => $d->url ?? null,
            'meta' => isset($d->meta) && $d->meta ? json_decode($d->meta, true) : null,
            'has_thumb' => isset($d->meta) && $d->meta && ! empty(json_decode($d->meta, true)['thumb_path']),
            'uploaded_by' => $d->uploaded_by_name ?? null, 'created_at' => $d->created_at,
        ];
    }

    /** GET projects/{id}/documents?task_id= */
    public function documents(Request $request, $id)
    {
        $project = $this->project($request, $id);
        $rows = DB::table('project_documents as d')->leftJoin('users as u', 'u.id', '=', 'd.uploaded_by')
            ->where('d.project_id', $project->id)->whereNull('d.deleted_at')
            ->when($request->filled('task_id'), fn ($q) => $q->where('d.task_id', (int) $request->task_id))
            ->orderByDesc('d.id')->get(['d.*', 'u.username as uploaded_by_name']);
        $titles = Task::whereIn('id', $rows->pluck('task_id')->filter()->unique())->pluck('title', 'id')->all();

        return response()->json(['documents' => $rows->map(fn ($d) => $this->presentDoc($d, $titles))->values()]);
    }

    /** POST projects/{id}/documents (multipart: files[], task_id, note) */
    public function upload(Request $request, $id)
    {
        $project = $this->project($request, $id);
        if (! $this->isEditor($request, $project)) {
            // A member uploads only to a task they work on.
            $t = $request->task_id ? Task::where('project_id', $project->id)->find((int) $request->task_id) : null;
            abort_unless($t && $this->canWorkOnTask($request, $t), 403, __('Only the people assigned to this task can add documents to it.'));
        }
        // A file (videos included, up to 200 MB) or a link (a Zoom recording, a shared folder) -- 2026-11-21.
        $request->validate(['files' => 'required_without:url|array|max:20', 'files.*' => 'file|max:204800', 'url' => 'nullable|url|max:1000',
            'name' => 'nullable|string|max:250', 'task_id' => 'nullable|integer', 'note' => 'nullable|string|max:255']);
        $taskId = $request->task_id ? (int) $request->task_id : null;
        abort_if($taskId && ! Task::where('id', $taskId)->where('project_id', $project->id)->exists(), 422);

        $saved = 0;
        if ($request->filled('url')) {
            // Its preview (platform, title, description, picture), read by the server, not trusted from the browser.
            $meta = \App\Support\LinkPreview::get($request->url);
            // A copy of its picture in the project's private files: some platforms' picture addresses expire (TikTok).
            if ($img = \App\Support\LinkPreview::image($meta['thumbnail'] ?? null)) {
                $meta['thumb_path'] = CompanyFiles::putBytes((int) $project->company_id, 'projects', 'enlace.'.$img['ext'], $img['bytes'], true);
            }
            DB::table('project_documents')->insert([
                'company_id' => $project->company_id, 'project_id' => $project->id, 'task_id' => $taskId,
                'name' => $request->name ?: ($meta['title'] ?: mb_substr($request->url, 0, 250)), 'path' => null, 'url' => $request->url,
                'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'mime' => 'text/uri-list', 'size' => 0,
                'note' => $request->note ?: null, 'uploaded_by' => optional($request->user('api'))->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $saved++;
        }
        foreach ((array) $request->file('files') as $file) {
            $mime = $file->getMimeType();
            $size = $file->getSize();
            $name = mb_substr($file->getClientOriginalName(), 0, 250);
            $path = CompanyFiles::putUpload((int) $project->company_id, 'projects', $file, true);
            DB::table('project_documents')->insert([
                'company_id' => $project->company_id, 'project_id' => $project->id, 'task_id' => $taskId,
                'name' => $name, 'path' => $path, 'mime' => $mime, 'size' => $size, 'note' => $request->note ?: null,
                'uploaded_by' => optional($request->user('api'))->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $saved++;
        }

        return response()->json(['success' => true, 'count' => $saved]);
    }

    private function doc(Request $request, $docId)
    {
        $d = DB::table('project_documents')->whereNull('deleted_at')->where('id', (int) $docId)->first();
        abort_unless($d, 404);
        $this->project($request, $d->project_id);
        if ($d->url) {
            return $d;
        }
        abort_unless(CompanyFiles::owns($d->path, (int) $d->company_id, 'projects'), 404);

        return $d;
    }

    /** GET projects/{id}/link-preview?url= -- what a pasted link is (platform, title, description, picture). */
    public function linkPreview(Request $request, $id)
    {
        $this->project($request, $id);
        $url = $request->validate(['url' => 'required|url:http,https|max:1000'])['url'];

        return response()->json(['preview' => \App\Support\LinkPreview::get($url)]);
    }

    /** GET project_files/{doc} -- inline (images, PDF) or ?download=1 */
    public function file(Request $request, $docId)
    {
        $d = $this->doc($request, $docId);
        // A link's stored picture (?thumb=1).
        if ($d->url) {
            $m = $d->meta ? json_decode($d->meta, true) : [];
            $p = $m['thumb_path'] ?? null;
            abort_unless($request->boolean('thumb') && $p && CompanyFiles::owns($p, (int) $d->company_id, 'projects'), 404);

            return response()->file(public_path($p));
        }
        if ($request->boolean('download')) {
            return CompanyFiles::download($d->path, $d->name);
        }

        return response()->file(public_path($d->path), ['Content-Type' => $d->mime ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.addslashes($d->name).'"']);
    }

    /** DELETE project_files/{doc} -- soft delete; the file stays on disk. */
    public function deleteFile(Request $request, $docId)
    {
        $d = $this->doc($request, $docId);
        $this->authorizeForUser($request->user('api'), 'update', Project::class);
        DB::table('project_documents')->where('id', $d->id)->update(['deleted_at' => now()]);

        return response()->json(['success' => true]);
    }

    // ---------------------------------------------------------------- my projects (the member's own screen)

    /** GET my/projects -- the projects the signed-in person is a member of, any company. */
    public function myProjects(Request $request)
    {
        $uid = optional($request->user('api'))->id;
        $emp = DB::table('employees')->where('user_id', $uid)->whereNull('deleted_at')->pluck('id');
        $rows = DB::table('employee_project as ep')->join('projects as p', 'p.id', '=', 'ep.project_id')
            ->leftJoin('companies as c', 'c.id', '=', 'p.company_id')
            ->whereIn('ep.employee_id', $emp ?: [0])->whereNull('p.deleted_at')
            ->get(['p.id', 'p.title', 'p.status', 'p.start_date', 'p.end_date', 'c.name as company_name', 'ep.role', 'ep.is_manager'])->unique('id');

        return response()->json(['projects' => $rows->map(function ($p) use ($emp) {
            $mine = DB::table('employee_task as et')->join('tasks as t', 't.id', '=', 'et.task_id')
                ->where('t.project_id', $p->id)->whereNull('t.deleted_at')->whereIn('et.employee_id', $emp)->distinct();
            $total = (clone $mine)->count('t.id');
            $open = (clone $mine)->whereNotIn('t.status', ['completed', 'cancelled'])->count('t.id');
            $all = DB::table('tasks')->where('project_id', $p->id)->whereNull('deleted_at')->where('status', '!=', 'cancelled');
            $n = (clone $all)->count();

            return ['id' => $p->id, 'title' => $p->title, 'status' => $p->status, 'start_date' => $p->start_date, 'end_date' => $p->end_date,
                'company_name' => $p->company_name, 'role' => $p->role, 'is_manager' => (bool) $p->is_manager,
                'my_tasks' => $total, 'my_open' => $open, 'progress' => $n ? (int) round((clone $all)->where('status', 'completed')->count() * 100 / $n) : 0];
        })->values()]);
    }

    /** GET my/projects/{id} -- the project for one of its members: header, members, my task ids. */
    public function myProject(Request $request, $id)
    {
        $project = Project::whereNull('deleted_at')->findOrFail($id);
        $mine = $this->myEmployeeIds($request, $project->id);
        abort_unless($mine || $this->isEditor($request, $project), 404);
        $tasks = Task::whereNull('deleted_at')->where('project_id', $project->id)
            ->get(['id', 'title', 'status', 'priority', 'start_date', 'end_date', 'task_progress', 'milestone_id', 'objective_id']);
        $myTaskIds = DB::table('employee_task')->whereIn('employee_id', $mine ?: [0])->whereIn('task_id', $tasks->pluck('id'))->pluck('task_id')->unique()->values();
        $stages = ProjectMilestone::where('project_id', $project->id)->whereNull('deleted_at')->pluck('title', 'id');

        return response()->json([
            'project' => ['id' => $project->id, 'title' => $project->title, 'description' => $project->description, 'status' => $project->status,
                'start_date' => $project->start_date, 'end_date' => $project->end_date,
                'company_name' => DB::table('companies')->where('id', $project->company_id)->value('name'),
                'links' => (json_decode((string) $project->settings, true) ?: [])['links'] ?? []],
            'members' => self::members($project->id),
            'my_task_ids' => $myTaskIds,
            'tasks' => $tasks->map(fn ($t) => ['id' => $t->id, 'title' => $t->title, 'status' => $t->status, 'start_date' => $t->start_date,
                'end_date' => $t->end_date, 'progress' => (int) $t->task_progress, 'stage' => $stages[$t->milestone_id] ?? null,
                'objective_id' => $t->objective_id])->values(),
            'can_edit' => $this->isEditor($request, $project),
        ]);
    }

    /** GET my/tasks/{id} -- a task for one of its project's members (the side panel's data). */
    public function myTask(Request $request, $taskId)
    {
        $task = $this->taskInScope($request, $taskId);

        return response()->json([
            'task' => $task,
            'assigned_employees' => DB::table('employee_task')->where('task_id', $task->id)->pluck('employee_id'),
            'can_work' => $this->canWorkOnTask($request, $task),
            'can_edit' => ($p = Project::find($task->project_id)) ? $this->isEditor($request, $p) : false,
        ]);
    }

    // ---------------------------------------------------------------- configuration and credentials

    /** The login's password when it is still a standard one (never a changed one: they are stored hashed). */
    private static function standardPassword(?object $user): ?string
    {
        if (! $user || ! $user->password) {
            return null;
        }
        $type = (string) $user->type;
        $candidates = array_values(array_filter([
            \App\Support\CompanyLogins::PASSWORDS[$type === 'sales_agent' ? 'sales_agent' : $type] ?? null,
            \App\Support\CompanyLogins::ACCESS_PASSWORD,
        ]));
        foreach ($candidates as $pw) {
            $ok = \Illuminate\Support\Facades\Cache::rememberForever('login_std:'.sha1($user->password.'|'.$pw),
                fn () => \Illuminate\Support\Facades\Hash::check($pw, $user->password) ? 1 : 0);
            if ($ok === 1) {
                return $pw;
            }
        }

        return null;
    }

    /** May the signed-in person see this member's login data? The SuperAdmin, or an editor for their own company's staff. */
    private function canSeeLogin(Request $request, Project $project, int $memberCompanyId): bool
    {
        $u = $request->user('api');
        if (! $u) {
            return false;
        }
        if ($u->type === 'SuperAdmin') {
            return true;
        }

        return $this->isEditor($request, $project) && (int) $u->company_id === $memberCompanyId;
    }

    /** The members with their login (user, standard password, state) for the Members and Configuration views. */
    private function membersWithLogins(Request $request, Project $project)
    {
        $emps = DB::table('employees')->whereIn('id', DB::table('employee_project')->where('project_id', $project->id)->pluck('employee_id'))
            ->pluck('user_id', 'id');
        $users = DB::table('users')->whereIn('id', $emps->filter()->values())->get(['id', 'email', 'username', 'type', 'statut', 'password'])->keyBy('id');

        return self::members($project->id)->map(function ($m) use ($request, $project, $emps, $users) {
            $u = $users[$emps[$m['id']] ?? 0] ?? null;
            $see = $this->canSeeLogin($request, $project, (int) $m['company_id']);
            $m['login'] = $u && $see ? ($u->email ?: $u->username) : null;
            $m['has_login'] = (bool) $u;
            $m['login_active'] = $u ? (int) $u->statut === 1 : false;
            $m['password'] = $u && $see ? self::standardPassword($u) : null;
            $m['password_custom'] = $u && $see && ! $m['password'];
            $m['can_see_login'] = $see;

            return $m;
        })->values();
    }

    /** GET projects/{id}/config -- links, access addresses and every member's login. */
    public function config(Request $request, $id)
    {
        $project = $this->project($request, $id, 'update');
        $settings = json_decode((string) $project->settings, true) ?: [];
        $base = rtrim(\App\Support\PublicUrl::base((int) $project->company_id), '/');

        return response()->json([
            'links' => $settings['links'] ?? [],
            'access' => [
                'login' => $base.'/login',
                'my_projects' => $base.'/next/mis-proyectos',
                'project' => $base.'/next/mis-proyectos/'.$project->id,
            ],
            'members' => $this->membersWithLogins($request, $project),
        ]);
    }

    /** PUT projects/{id}/config {links: [{label, url}]} */
    public function saveConfig(Request $request, $id)
    {
        $project = $this->project($request, $id, 'update');
        $request->validate(['links' => 'array|max:30', 'links.*.label' => 'nullable|string|max:120', 'links.*.url' => 'required|url|max:1000']);
        $settings = json_decode((string) $project->settings, true) ?: [];
        $settings['links'] = collect($request->links ?? [])->map(fn ($l) => ['label' => trim((string) ($l['label'] ?? '')), 'url' => trim($l['url'])])->values()->all();
        Project::whereKey($project->id)->update(['settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);

        return response()->json(['success' => true]);
    }

    private function memberLogin(Request $request, Project $project, $employeeId): array
    {
        abort_unless(DB::table('employee_project')->where('project_id', $project->id)->where('employee_id', (int) $employeeId)->exists(), 404);
        $e = DB::table('employees')->where('id', (int) $employeeId)->first();
        abort_unless($e && $e->user_id && $this->canSeeLogin($request, $project, (int) $e->company_id), 403);
        $u = DB::table('users')->where('id', $e->user_id)->first();
        abort_unless($u && (int) $u->statut === 1, 422, __('That login is disabled.'));

        return [$e, $u];
    }

    /** POST projects/{id}/members/{employee}/send-credentials -- email with the project's address and how to sign in. */
    public function sendCredentials(Request $request, $id, $employee)
    {
        $project = $this->project($request, $id, 'update');
        [$e, $u] = $this->memberLogin($request, $project, $employee);
        abort_unless($u->email && filter_var($u->email, FILTER_VALIDATE_EMAIL), 422, __('That login has no email.'));
        $base = rtrim(\App\Support\PublicUrl::base((int) $project->company_id), '/');
        $pw = self::standardPassword($u);
        $name = trim(($e->firstname ?? '').' '.($e->lastname ?? '')) ?: $e->username;
        $lines = [
            "Hola {$name},",
            '',
            "Te damos acceso al proyecto \"{$project->title}\" en Murano.",
            '',
            'Entra aquí: '.$base.'/next/mis-proyectos/'.$project->id,
            'Usuario: '.$u->email,
            $pw ? 'Contraseña: '.$pw.' (te pedirá cambiarla al entrar)' : 'Contraseña: la tuya. Si no la recuerdas, usa "¿Olvidaste tu contraseña?" en la página de ingreso.',
            '',
            'Ahí verás tus tareas, podrás registrar lo que avances y subir documentos.',
        ];
        // The project company's own mail server (Mail Settings), like every company email.
        [$mailer] = \App\Support\CompanyMail::mailer((int) $project->company_id);
        $mailer->raw(implode("\n", $lines), function ($m) use ($u, $project) {
            $m->to($u->email)->subject('Acceso al proyecto '.$project->title);
        });

        return response()->json(['success' => true, 'to' => $u->email]);
    }

    /** POST projects/{id}/members/{employee}/access-link -- a sign-in link for WhatsApp (first device only, App\Support\LoginLinks). */
    public function accessLink(Request $request, $id, $employee)
    {
        $project = $this->project($request, $id, 'update');
        [, $u] = $this->memberLogin($request, $project, $employee);
        $user = \App\Models\User::find($u->id);
        abort_unless(\App\Support\LoginLinks::allowedFor($user), 422, __('Only staff logins can have a link.'));

        return response()->json(['url' => \App\Support\LoginLinks::create($user, $request->user('api'))]);
    }

    // ---------------------------------------------------------------- task activity log

    private function taskInScope(Request $request, $taskId): Task
    {
        $task = Task::whereNull('deleted_at')->findOrFail((int) $taskId);
        if ($this->myEmployeeIds($request, (int) $task->project_id)) {
            return $task; // a member of its project
        }
        $this->authorizeForUser($request->user('api'), 'view', Task::class);
        $this->abortUnlessProjectInScope($request, $task->project_id);

        return $task;
    }

    /** GET tasks/{id}/activities -- the task's agreements / activity log, newest first. */
    public function activities(Request $request, $taskId)
    {
        $task = $this->taskInScope($request, $taskId);
        $rows = DB::table('task_activities as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.task_id', $task->id)->whereNull('a.deleted_at')->orderByDesc('a.created_at')->orderByDesc('a.id')
            ->get(['a.id', 'a.note', 'a.progress', 'a.created_at', 'a.user_id', 'u.firstname', 'u.lastname', 'u.username']);

        return response()->json(['activities' => $rows->map(fn ($r) => [
            'id' => $r->id, 'note' => $r->note, 'progress' => $r->progress === null ? null : (int) $r->progress, 'created_at' => $r->created_at,
            'user' => trim(($r->firstname ?? '').' '.($r->lastname ?? '')) ?: $r->username,
            'mine' => (int) $r->user_id === (int) optional($request->user('api'))->id,
        ])->values(), 'progress' => (int) $task->task_progress, 'status' => $task->status]);
    }

    /**
     * POST tasks/{id}/activities {note, progress} -- one transaction: the entry and the task's progress (100 = completed,
     * more than 0 on a task not started = in progress).
     */
    public function addActivity(Request $request, $taskId)
    {
        $task = $this->taskInScope($request, $taskId);
        abort_unless($this->canWorkOnTask($request, $task), 403, __('Only the people assigned to this task can record on it.'));
        $request->validate(['note' => 'required|string|max:5000', 'progress' => 'nullable|integer|min:0|max:100']);
        DB::transaction(function () use ($task, $request) {
            DB::table('task_activities')->insert([
                'company_id' => $task->company_id, 'project_id' => $task->project_id, 'task_id' => $task->id,
                'user_id' => optional($request->user('api'))->id, 'note' => $request->note,
                'progress' => $request->filled('progress') ? (int) $request->progress : null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($request->filled('progress')) {
                $p = (int) $request->progress;
                $status = $p >= 100 ? 'completed' : ($p > 0 && in_array($task->status, ['not_started', null, ''], true) ? 'progress' : $task->status);
                Task::whereKey($task->id)->update(['task_progress' => $p, 'status' => $status]);
            }
        });

        return response()->json(['success' => true]);
    }

    /** DELETE task_activities/{id} -- one's own entry (or a project editor). */
    public function deleteActivity(Request $request, $actId)
    {
        $a = DB::table('task_activities')->whereNull('deleted_at')->where('id', (int) $actId)->first();
        abort_unless($a, 404);
        $this->taskInScope($request, $a->task_id);
        $me = optional($request->user('api'));
        abort_unless((int) $a->user_id === (int) $me->id || $request->user('api')->can('update', Project::class), 403);
        DB::table('task_activities')->where('id', $a->id)->update(['deleted_at' => now()]);

        return response()->json(['success' => true]);
    }

    // ---------------------------------------------------------------- description and objectives

    /** GET projects/{id}/objectives -- the project's description and its objectives with their tasks. */
    public function objectives(Request $request, $id)
    {
        $project = $this->project($request, $id);
        $objs = DB::table('project_objectives')->where('project_id', $project->id)->whereNull('deleted_at')->orderBy('position')->orderBy('id')->get();
        // In date order inside each objective (user, 2026-11-22).
        $tasks = Task::whereNull('deleted_at')->where('project_id', $project->id)->whereNotNull('objective_id')
            ->orderBy('start_date')->orderBy('end_date')->orderBy('id')->get(['id', 'title', 'status', 'start_date', 'end_date', 'objective_id', 'milestone_id', 'task_progress', 'requirement', 'reason', 'description']);
        $people = DB::table('employee_task as et')->join('employees as e', 'e.id', '=', 'et.employee_id')->whereIn('et.task_id', $tasks->pluck('id'))
            ->get(['et.task_id', 'e.id', 'e.firstname', 'e.lastname', 'e.username', 'e.company_id'])->groupBy('task_id');
        $who = $people->map(fn ($r) => $r->map(fn ($e) => self::name($e))->implode(', '));
        // Avatars (2026-11-22): initials, and whose staff it is (the project's company or the client's).
        $avatars = $people->map(fn ($r) => $r->map(function ($e) use ($project) {
            $n = self::name($e);
            $parts = preg_split('/\s+/u', trim(preg_replace('/\(.*?\)/u', '', $n))) ?: [];
            $ini = mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1).(count($parts) > 1 ? mb_substr(end($parts), 0, 1) : ''));

            return ['id' => (int) $e->id, 'name' => $n, 'initials' => $ini, 'side' => (int) $e->company_id === (int) $project->company_id ? 'own' : 'client'];
        })->values());
        $stages = ProjectMilestone::where('project_id', $project->id)->pluck('title', 'id');

        return response()->json([
            'description' => $project->description,
            'entities' => DB::table('project_entities')->where('project_id', $project->id)->whereNull('deleted_at')->orderBy('position')->orderBy('id')
                ->get(['id', 'name', 'description', 'position']),
            'objectives' => $objs->map(function ($o) use ($tasks, $who, $stages, $avatars) {
                $ts = $tasks->where('objective_id', $o->id)->values();
                $counted = $ts->where('status', '!=', 'cancelled');
                $done = $counted->where('status', 'completed')->count();

                return [
                    'id' => $o->id, 'entity_id' => $o->entity_id, 'pillar' => $o->pillar ?? null, 'title' => $o->title, 'description' => $o->description, 'position' => (int) $o->position,
                    'progress' => $counted->count() ? (int) round($done * 100 / $counted->count()) : 0,
                    'done' => $counted->count() && $done === $counted->count(),
                    'tasks' => $ts->map(fn ($t) => ['id' => $t->id, 'title' => $t->title, 'status' => $t->status, 'end_date' => $t->end_date,
                        'stage' => $stages[$t->milestone_id] ?? null, 'assignees' => (string) ($who[$t->id] ?? ''), 'people' => $avatars[$t->id] ?? [],
                        'requirement' => $t->requirement ?? $t->description, 'reason' => $t->reason])->values(),
                ];
            })->values(),
        ]);
    }

    /** PUT projects/{id}/description {description} */
    public function saveDescription(Request $request, $id)
    {
        $project = $this->project($request, $id, 'update');
        $request->validate(['description' => 'nullable|string|max:20000']);
        Project::whereKey($project->id)->update(['description' => $request->description, 'updated_at' => now()]);

        return response()->json(['success' => true]);
    }

    /**
     * POST projects/{id}/objectives {title, description, milestone_id, assignee_id, start_date, end_date}
     * One transaction: the objective and its task (same title), assigned to the person picked, else the project manager.
     */
    public function storeObjective(Request $request, $id)
    {
        $project = $this->project($request, $id, 'update');
        $request->validate(['title' => 'required|string|max:255', 'description' => 'nullable|string|max:5000', 'entity_id' => 'nullable|integer',
            'milestone_id' => 'nullable|integer', 'assignee_id' => 'nullable|integer', 'start_date' => 'nullable|date', 'end_date' => 'nullable|date']);
        $stage = $request->milestone_id ? ProjectMilestone::whereKey($request->milestone_id)->where('project_id', $project->id)->whereNull('deleted_at')->first() : null;
        abort_if($request->milestone_id && ! $stage, 422);
        $members = self::members($project->id);
        $who = (int) $request->assignee_id ?: (optional($members->firstWhere('is_manager', true))['id'] ?? null);
        abort_if($who && ! $members->contains('id', $who)
            && ! Employee::whereKey($who)->where('company_id', $project->company_id)->whereNull('deleted_at')->exists(), 422);
        $start = $request->start_date ?: now()->toDateString();
        $end = $request->end_date ?: (optional(optional($stage)->due_date)->toDateString() ?: Carbon::parse($start)->addDays(7)->toDateString());
        if ($end < $start) {
            $end = $start;
        }

        $out = DB::transaction(function () use ($project, $request, $stage, $who, $start, $end) {
            $pos = (int) DB::table('project_objectives')->where('project_id', $project->id)->max('position');
            $oid = DB::table('project_objectives')->insertGetId([
                'company_id' => $project->company_id, 'project_id' => $project->id, 'title' => $request->title,
                'entity_id' => $this->entityId($project, $request->entity_id),
                // Its pillar: the one picked, else the pillar of its stage, else Requerimiento (2026-11-22).
                'pillar' => in_array($request->pillar, \App\Support\ProjectPillars::ALL, true) ? $request->pillar
                    : (in_array(optional($stage)->title, \App\Support\ProjectPillars::ALL, true) ? $stage->title : \App\Support\ProjectPillars::REQ),
                'description' => $request->description, 'position' => $pos + 1, 'created_by' => optional($request->user('api'))->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $task = Task::create([
                'title' => $request->title, 'project_id' => $project->id, 'company_id' => $project->company_id,
                'milestone_id' => optional($stage)->id, 'objective_id' => $oid, 'start_date' => $start, 'end_date' => $end,
                'status' => 'not_started', 'priority' => 'medium', 'task_progress' => 0, 'description' => $request->description,
            ]);
            if ($who) {
                DB::table('employee_task')->insert(['employee_id' => $who, 'task_id' => $task->id]);
            }

            return ['id' => $oid, 'task_id' => $task->id];
        });

        return response()->json(['success' => true] + $out);
    }

    private function objective(Project $project, $oid)
    {
        $o = DB::table('project_objectives')->where('project_id', $project->id)->whereNull('deleted_at')->where('id', (int) $oid)->first();
        abort_unless($o, 404);

        return $o;
    }

    /** PUT projects/{id}/objectives/{oid} {title, description, position} */
    public function updateObjective(Request $request, $id, $oid)
    {
        $project = $this->project($request, $id, 'update');
        $o = $this->objective($project, $oid);
        $request->validate(['title' => 'required|string|max:255', 'description' => 'nullable|string|max:5000', 'position' => 'nullable|integer|min:0']);
        DB::table('project_objectives')->where('id', $o->id)->update(['title' => $request->title, 'description' => $request->description,
            'entity_id' => $request->has('entity_id') ? $this->entityId($project, $request->entity_id) : $o->entity_id,
            'pillar' => in_array($request->pillar, \App\Support\ProjectPillars::ALL, true) ? $request->pillar : ($o->pillar ?? null),
            'position' => $request->position ?? $o->position, 'updated_at' => now()]);

        return response()->json(['success' => true]);
    }

    /** The entity sent, only one of this project. */
    private function entityId(Project $project, $eid): ?int
    {
        if (! (int) $eid) {
            return null;
        }
        abort_unless(DB::table('project_entities')->where('id', (int) $eid)->where('project_id', $project->id)->whereNull('deleted_at')->exists(), 422);

        return (int) $eid;
    }

    /** POST projects/{id}/entities {name, description} -- who the objectives relate to (Jelaf, Dirección, Operaciones...). */
    public function storeEntity(Request $request, $id)
    {
        $project = $this->project($request, $id, 'update');
        $request->validate(['name' => 'required|string|max:150', 'description' => 'nullable|string|max:500']);
        $pos = (int) DB::table('project_entities')->where('project_id', $project->id)->max('position');
        $eid = DB::table('project_entities')->insertGetId(['company_id' => $project->company_id, 'project_id' => $project->id,
            'name' => $request->name, 'description' => $request->description, 'position' => $pos + 1, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['success' => true, 'id' => $eid]);
    }

    /** PUT projects/{id}/entities/{eid} {name, description, position} */
    public function updateEntity(Request $request, $id, $eid)
    {
        $project = $this->project($request, $id, 'update');
        $request->validate(['name' => 'required|string|max:150', 'description' => 'nullable|string|max:500', 'position' => 'nullable|integer|min:0']);
        $e = DB::table('project_entities')->where('id', (int) $eid)->where('project_id', $project->id)->whereNull('deleted_at')->first();
        abort_unless($e, 404);
        DB::table('project_entities')->where('id', $e->id)->update(['name' => $request->name, 'description' => $request->description,
            'position' => $request->position ?? $e->position, 'updated_at' => now()]);

        return response()->json(['success' => true]);
    }

    /** DELETE projects/{id}/entities/{eid} -- its objectives stay, without an entity. */
    public function deleteEntity(Request $request, $id, $eid)
    {
        $project = $this->project($request, $id, 'update');
        DB::transaction(function () use ($project, $eid) {
            DB::table('project_objectives')->where('project_id', $project->id)->where('entity_id', (int) $eid)->update(['entity_id' => null]);
            DB::table('project_entities')->where('id', (int) $eid)->where('project_id', $project->id)->update(['deleted_at' => now()]);
        });

        return response()->json(['success' => true]);
    }

    /** DELETE projects/{id}/objectives/{oid} -- its tasks stay, without the objective. */
    public function deleteObjective(Request $request, $id, $oid)
    {
        $project = $this->project($request, $id, 'update');
        $o = $this->objective($project, $oid);
        DB::transaction(function () use ($o) {
            Task::where('objective_id', $o->id)->update(['objective_id' => null]);
            DB::table('project_objectives')->where('id', $o->id)->update(['deleted_at' => now()]);
        });

        return response()->json(['success' => true]);
    }

    /** POST projects/{id}/objectives/{oid}/tasks {task_id, unlink} -- tie an existing task to the objective (or untie it). */
    public function linkObjectiveTask(Request $request, $id, $oid)
    {
        $project = $this->project($request, $id, 'update');
        $o = $this->objective($project, $oid);
        $task = Task::whereKey((int) $request->task_id)->where('project_id', $project->id)->whereNull('deleted_at')->firstOrFail();
        $task->objective_id = $request->boolean('unlink') ? null : $o->id;
        $task->save();

        return response()->json(['success' => true]);
    }

    // ---------------------------------------------------------------- templates

    private function templatesFor(int $companyId)
    {
        return DB::table('project_templates')->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId));
    }

    private static function presentTemplate($t, bool $withData = false): array
    {
        $data = json_decode($t->data, true) ?: [];
        $out = [
            'id' => $t->id, 'code' => $t->code, 'name' => $t->name, 'name_en' => $t->name_en, 'description' => $t->description,
            'duration_days' => (int) $t->duration_days, 'standard' => $t->company_id === null,
            'stages_count' => count($data['stages'] ?? []),
            'tasks_count' => array_sum(array_map(fn ($s) => count($s['tasks'] ?? []), $data['stages'] ?? [])),
            'roles' => $data['roles'] ?? [],
        ];
        if ($withData) {
            $out['stages'] = $data['stages'] ?? [];
        }

        return $out;
    }

    /** GET project_templates?project_id= */
    public function templates(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Project::class);
        $cid = $request->filled('project_id')
            ? (int) $this->project($request, $request->project_id)->company_id
            : (int) $this->resolveActingCompanyId($request);

        return response()->json(['templates' => $this->templatesFor($cid)->orderByRaw('company_id is null desc')->orderBy('id')->get()
            ->map(fn ($t) => self::presentTemplate($t, true))->values()]);
    }

    /** DELETE project_templates/{id} -- a company's own template only. */
    public function deleteTemplate(Request $request, $tplId)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Project::class);
        $t = DB::table('project_templates')->whereNull('deleted_at')->where('id', (int) $tplId)->first();
        abort_unless($t && $t->company_id !== null, 404);
        $cid = $this->listCompanyId($request);
        abort_unless($cid === null || (int) $t->company_id === $cid, 404);
        DB::table('project_templates')->where('id', $t->id)->update(['deleted_at' => now()]);

        return response()->json(['success' => true]);
    }

    /**
     * POST projects/{id}/apply-template {template_id, start_date, fit}
     * Adds the template's stages and tasks (nothing existing is removed). Dates: start_date + the template's days; with
     * fit, stretched / shrunk to end on the project's end date. Each task goes to the member whose project role is the
     * task's role, else the manager.
     */
    public function applyTemplate(Request $request, $id)
    {
        $project = $this->project($request, $id, 'update');
        $request->validate(['template_id' => 'required|integer', 'start_date' => 'required|date']);
        $tpl = $this->templatesFor((int) $project->company_id)->where('id', (int) $request->template_id)->first();
        abort_unless($tpl, 404);
        $data = json_decode($tpl->data, true) ?: [];
        $start = Carbon::parse($request->start_date)->startOfDay();
        $total = max(1, (int) $tpl->duration_days);
        $scale = 1.0;
        if ($request->boolean('fit') && $project->end_date) {
            $span = $start->diffInDays(Carbon::parse($project->end_date), false);
            if ($span > 0) {
                $scale = $span / $total;
            }
        }
        $at = fn (float $days) => $start->copy()->addDays((int) round($days * $scale))->toDateString();

        $members = self::members($project->id);
        $manager = optional($members->firstWhere('is_manager', true))['id'] ?? null;
        $byRole = [];
        foreach ($members as $m) {
            $k = mb_strtolower(trim((string) $m['role']));
            if ($k !== '' && ! isset($byRole[$k])) {
                $byRole[$k] = $m['id'];
            }
        }

        $made = ['stages' => 0, 'tasks' => 0, 'unassigned_roles' => []];
        DB::transaction(function () use ($data, $project, $at, $byRole, $manager, $request, &$made) {
            $pos = (int) ProjectMilestone::where('project_id', $project->id)->whereNull('deleted_at')->max('position');
            foreach ($data['stages'] ?? [] as $s) {
                // A stage the project already has (the four pillars, 2026-11-22) takes the tasks; its end moves later if needed.
                $due = $at($s['start'] + $s['days']);
                $stage = ProjectMilestone::where('project_id', $project->id)->whereNull('deleted_at')->where('title', $s['title'])->first();
                if ($stage) {
                    if (! $stage->due_date || $stage->due_date->toDateString() < $due) {
                        $stage->update(['due_date' => $due]);
                    }
                } else {
                    $pillar = array_search($s['title'], \App\Support\ProjectPillars::ALL, true);
                    $stage = ProjectMilestone::create([
                        'project_id' => $project->id, 'title' => $s['title'], 'status' => 'pending', 'progress' => 0,
                        'description' => \App\Support\ProjectPillars::DESCRIPTIONS[$s['title']] ?? null,
                        'due_date' => $due, 'position' => $pillar !== false ? $pillar + 1 : ++$pos,
                        'created_by' => optional($request->user('api'))->id,
                    ]);
                    $made['stages']++;
                }
                foreach ($s['tasks'] ?? [] as [$title, $off, $days, $role, $hours]) {
                    $task = Task::create([
                        'title' => $title, 'project_id' => $project->id, 'company_id' => $project->company_id,
                        'start_date' => $at($s['start'] + $off), 'end_date' => $at($s['start'] + $off + max(1, $days)),
                        'status' => 'not_started', 'priority' => 'medium', 'estimated_hour' => $hours, 'task_progress' => 0,
                        'note' => $role ? 'Rol: '.$role : null, 'milestone_id' => $stage->id,
                    ]);
                    $who = $byRole[mb_strtolower(trim((string) $role))] ?? null;
                    if (! $who && $role) {
                        $made['unassigned_roles'][$role] = true;
                    }
                    $who = $who ?: $manager;
                    if ($who) {
                        DB::table('employee_task')->insert(['employee_id' => $who, 'task_id' => $task->id]);
                    }
                    $made['tasks']++;
                }
            }
        });
        $made['unassigned_roles'] = array_keys($made['unassigned_roles']);

        return response()->json(['success' => true] + $made);
    }

    /** POST projects/{id}/save-template {name, description} -- the project's stages and tasks as a template of its company. */
    public function saveTemplate(Request $request, $id)
    {
        $project = $this->project($request, $id, 'update');
        $request->validate(['name' => 'required|string|max:191', 'description' => 'nullable|string|max:500']);
        $base = Carbon::parse($project->start_date ?: now())->startOfDay();
        $roles = DB::table('employee_project')->where('project_id', $project->id)->pluck('role', 'employee_id');
        $tasks = Task::whereNull('deleted_at')->where('project_id', $project->id)->get();
        $assignee = DB::table('employee_task')->whereIn('task_id', $tasks->pluck('id'))->get()->groupBy('task_id');
        $off = fn ($d) => $d ? max(0, $base->diffInDays(Carbon::parse($d), false)) : 0;

        $stages = [];
        $groups = ProjectMilestone::whereNull('deleted_at')->where('project_id', $project->id)->orderBy('position')->orderBy('due_date')->get()
            ->map(fn ($m) => ['title' => $m->title, 'due' => $m->due_date, 'tasks' => $tasks->where('milestone_id', $m->id)])->all();
        $loose = $tasks->whereNull('milestone_id');
        if ($loose->count()) {
            $groups[] = ['title' => 'Otras tareas', 'due' => $loose->max('end_date'), 'tasks' => $loose];
        }
        foreach ($groups as $g) {
            $ts = collect($g['tasks']);
            $sStart = $ts->count() ? $ts->map(fn ($t) => $off($t->start_date))->min() : max(0, $off($g['due']) - 7);
            $sEnd = max($sStart + 1, $off($g['due']), $ts->map(fn ($t) => $off($t->end_date))->max() ?? 0);
            $stages[] = ['title' => $g['title'], 'start' => $sStart, 'days' => $sEnd - $sStart,
                'tasks' => $ts->sortBy('start_date')->map(function ($t) use ($off, $sStart, $assignee, $roles) {
                    $first = optional(($assignee[$t->id] ?? collect())->first())->employee_id;

                    return [$t->title, max(0, $off($t->start_date) - $sStart), max(1, $off($t->end_date) - $off($t->start_date)),
                        $first ? ($roles[$first] ?? null) : null, $t->estimated_hour === null ? null : (float) $t->estimated_hour];
                })->values()->all()];
        }
        $days = 0;
        foreach ($stages as $s) {
            $days = max($days, $s['start'] + $s['days']);
        }
        $roleList = collect($stages)->flatMap(fn ($s) => array_column($s['tasks'], 3))->filter()->unique()->values()->all();

        $newId = DB::table('project_templates')->insertGetId([
            'company_id' => $project->company_id, 'code' => null, 'name' => $request->name, 'name_en' => null,
            'description' => $request->description, 'duration_days' => $days,
            'data' => json_encode(['roles' => $roleList, 'stages' => $stages], JSON_UNESCAPED_UNICODE),
            'created_by' => optional($request->user('api'))->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['success' => true, 'id' => $newId]);
    }
}
