<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeProject;
use App\Models\Project;
use App\utils\helpers;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProjectController extends Controller
{
    use \App\Http\Controllers\Concerns\ScopesToCompany;

    /**
     * Multicompany (2026-11-19: the module came from the single-business base unscoped -- the list showed every company's
     * projects and the form offered other companies' customers). A project / customer is reachable only inside the
     * list company (null = every company for superadmin/AdminSystem).
     */
    private function inScope(Request $request, ?int $companyId): bool
    {
        $cid = $this->listCompanyId($request);

        return $cid === null || (int) $companyId === $cid;
    }

    /** The company a write goes to: an Admin's own; a SuperAdmin's pick in the form, else the acting company. */
    private function writeCompanyId(Request $request): int
    {
        $user = $request->user('api');
        $picked = (int) $request->input('company_id');

        return $user && $user->type === 'SuperAdmin' && $picked ? $picked : (int) $this->resolveActingCompanyId($request);
    }

    /** The customer and employees sent, refused (422) unless they belong to the project's company. */
    private function checkedRefs(Request $request, int $cid, ?int $projectId = null): array
    {
        $client = (int) $request['client'];
        abort_unless(Client::where('id', $client)->where('company_id', $cid)->whereNull('deleted_at')->exists(), 422,
            __('The customer does not belong to this company.'));
        $staff = array_values(array_unique(array_map('intval', (array) ($request['assigned_to'] ?? []))));
        // A member from the client's company (Members tab) stays a member (2026-11-21).
        $kept = $projectId ? DB::table('employee_project as ep')->join('employees as e', 'e.id', '=', 'ep.employee_id')
            ->where('ep.project_id', $projectId)->where('e.company_id', '!=', $cid)->pluck('e.id')->map(fn ($v) => (int) $v)->all() : [];
        $check = array_values(array_diff($staff, $kept));
        $staff = array_values(array_unique(array_merge($staff, $kept)));
        if ($check) {
            abort_unless(Employee::whereIn('id', $check)->where('company_id', $cid)->whereNull('deleted_at')->count() === count($check), 422,
                __('An employee does not belong to this company.'));
        }

        return [$client, $staff];
    }

    /** Customers offered by the form: the list company's (each with its company_id, so a SuperAdmin's pick can filter them). */
    private function clientList(Request $request)
    {
        $q = Client::where('deleted_at', '=', null)->orderBy('id', 'desc');
        $this->scopeListToCompany($q, $request);

        return $q->get(['id', 'name', 'company_id']);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Project::class);
        $role = Auth::user()->roles()->first();

        // How many items do you want to display.
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = $request->SortType;
        $helpers = new helpers;
        // Filter fields With Params to retrieve
        $param = [
            0 => 'like',
            1 => '=',
            2 => '=',
            3 => '=',
            4 => '=',
        ];
        $columns = [
            0 => 'status',
            1 => 'client_id',
            2 => 'start_date',
            3 => 'end_date',
            4 => 'company_id',
        ];
        $data = [];

        // Check If User Has Permission View  All Records
        $projects = Project::with('company', 'client')
            ->where('deleted_at', '=', null);
        $this->scopeListToCompany($projects, $request);

        // Multiple Filter
        $Filtred = $helpers->filter($projects, $columns, $param, $request)
        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('title', 'LIKE', "%{$request->search}%")
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('company', function ($q) use ($request) {
                                $q->where('name', 'LIKE', "%{$request->search}%");
                            });
                        })
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('client', function ($q) use ($request) {
                                $q->where('name', 'LIKE', "%{$request->search}%");
                            });
                        });
                });
            });

        $totalRows = $Filtred->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }
        $all_projects = $Filtred->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        foreach ($all_projects as $project) {

            $item['id'] = $project->id;
            $item['title'] = $project->title;
            $item['start_date'] = $project->start_date;
            $item['end_date'] = $project->end_date;
            $item['client_name'] = $project['client']->name;
            $item['company_name'] = $project['company']->name;
            $item['status'] = $project->status;

            $data[] = $item;
        }

        $companies = \App\Support\CompanyNames::visibleTo($request->user('api'));
        $clients = $this->clientList($request);

        $count_not_started = $this->scopeListToCompany(Project::where('deleted_at', '=', null), $request)
            ->where('status', '=', 'not_started')
            ->count();

        $count_in_progress = $this->scopeListToCompany(Project::where('deleted_at', '=', null), $request)
            ->where('status', '=', 'progress')
            ->count();

        $count_cancelled = $this->scopeListToCompany(Project::where('deleted_at', '=', null), $request)
            ->where('status', '=', 'cancelled')
            ->count();

        $count_completed = $this->scopeListToCompany(Project::where('deleted_at', '=', null), $request)
            ->where('status', '=', 'completed')
            ->count();

        return response()->json([
            'totalRows' => $totalRows,
            'projects' => $data,
            'companies' => $companies,
            'clients' => $clients,
            'count_not_started' => $count_not_started,
            'count_in_progress' => $count_in_progress,
            'count_cancelled' => $count_cancelled,
            'count_completed' => $count_completed,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Project::class);

        $clients = $this->clientList($request);
        $companies = \App\Support\CompanyNames::visibleTo($request->user('api'));

        return response()->json([
            'clients' => $clients,
            'companies' => $companies,
        ]);

    }

    /**
     * Store a newly created resource in storage.
     *
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Project::class);

        $request->validate([
            'title' => 'required|string|max:255',
            'client' => 'required',
            'company_id' => 'required',
            // 2026-11-19: the employee is optional (the form never asked for it); dates as before, the end not before the start.
            'assigned_to' => 'nullable|array',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date', // none = open-ended (monthly support, 2026-11-21)
            'status' => 'required',
        ]);

        $cid = $this->writeCompanyId($request);
        [$client, $staff] = $this->checkedRefs($request, $cid);

        $project = Project::create([
            'title' => $request['title'],
            'start_date' => $request['start_date'],
            'end_date' => $request['end_date'] ?: null,
            'company_id' => $cid,
            'client_id' => $client,
            'status' => $request['status'],
            'description' => $request['description'],
        ]);

        $project->assignedEmployees()->sync($staff);

        // Every project starts with its scope (user's rule, 2026-11-22): objective 1 "Definición del alcance del proyecto"
        // and its first task, the meeting with the client.
        if (\Illuminate\Support\Facades\Schema::hasTable('project_objectives')) {
            DB::transaction(function () use ($project, $cid) {
                // ...and its four pillars as its stages (2026-11-22).
                \App\Support\ProjectPillars::seed($project->id, optional(request()->user('api'))->id);
                $oid = DB::table('project_objectives')->insertGetId([
                    'company_id' => $cid, 'project_id' => $project->id, 'position' => 1,
                    'title' => __('Definition of the project scope').' '.$project->title,
                    'description' => __('Agree with the client what the project includes and gather the information to plan it.'),
                    'created_by' => optional(request()->user('api'))->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
                \App\Models\Task::create([
                    'title' => __('Meeting with the client to define the project scope'), 'project_id' => $project->id, 'company_id' => $cid,
                    'objective_id' => $oid, 'milestone_id' => \App\Support\ProjectPillars::stageId($project->id, \App\Support\ProjectPillars::REQ), 'is_meeting' => 1, 'start_date' => $project->start_date, 'end_date' => $project->start_date,
                    'status' => 'not_started', 'priority' => 'high', 'task_progress' => 0,
                ]);
            });
        }

        // The form opens the new project's workspace (2026-11-19).
        return response()->json(['success' => true, 'id' => $project->id]);

    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show(Request $request, $id)
    {
        // One project as its own workspace (user, 2026-11-19, "like Asana"): its header, overview figures and the dated
        // items the Calendar tab draws. Tasks / milestones / hours tabs read their own endpoints with project_id.
        // A member of the project reads it whatever their company or role (2026-11-22, App\Support\ProjectMembers).
        $member = \App\Support\ProjectMembers::isMember(optional($request->user('api'))->id, (int) $id);
        if (! $member) {
            $this->authorizeForUser($request->user('api'), 'view', Project::class);
        }

        $project = Project::with('client:id,name', 'company:id,name')->where('deleted_at', '=', null)->findOrFail($id);
        abort_unless($member || $this->inScope($request, $project->company_id), 404);

        $today = Carbon::today()->toDateString();
        $tasks = \App\Models\Task::whereNull('deleted_at')->where('project_id', $id)
            ->get(['id', 'title', 'status', 'priority', 'start_date', 'end_date', 'task_progress', 'estimated_hour', 'milestone_id', 'objective_id']);
        $milestones = \App\Models\ProjectMilestone::whereNull('deleted_at')->where('project_id', $id)->orderBy('position')->orderBy('due_date')
            ->get(['id', 'title', 'description', 'status', 'due_date', 'progress', 'position', 'budget']);
        $logs = \App\Models\ProjectTimeLog::whereNull('deleted_at')->where('project_id', $id);
        $team = ProjectTeamController::members((int) $id);

        // Who does each task (the Timeline tab's "Responsable" column).
        $assignees = DB::table('employee_task as et')->join('employees as e', 'e.id', '=', 'et.employee_id')
            ->whereIn('et.task_id', $tasks->pluck('id'))->get(['et.task_id', 'e.firstname', 'e.lastname', 'e.username'])
            ->groupBy('task_id')->map(fn ($rows) => $rows->map(fn ($e) => trim($e->firstname.' '.$e->lastname) ?: $e->username)->implode(', '));

        $open = fn ($t) => ! in_array($t->status, ['completed', 'cancelled'], true);
        $done = $tasks->where('status', 'completed')->count();
        $counted = $tasks->where('status', '!=', 'cancelled')->count();

        return response()->json([
            'project' => [
                'id' => $project->id, 'title' => $project->title, 'description' => $project->description,
                'status' => $project->status, 'start_date' => $project->start_date, 'end_date' => $project->end_date,
                'client_name' => optional($project->client)->name, 'company_name' => optional($project->company)->name,
                'company_id' => $project->company_id,
                'is_late' => $project->end_date && $project->status !== 'completed' && $project->end_date < $today,
            ],
            'team' => $team,
            'stats' => [
                'tasks' => $tasks->count(),
                'tasks_done' => $done,
                'tasks_overdue' => $tasks->filter(fn ($t) => $open($t) && $t->end_date && $t->end_date < $today)->count(),
                'progress' => $counted ? (int) round($done * 100 / $counted) : 0,
                'milestones' => $milestones->count(),
                'milestones_done' => $milestones->where('status', 'completed')->count(),
                'estimated_hours' => round((float) $tasks->sum('estimated_hour'), 2),
                'logged_hours' => round((float) (clone $logs)->sum('hours'), 2),
                'billable_amount' => round((float) (clone $logs)->where('billable', 1)->sum('amount'), 2),
            ],
            'tasks' => $tasks->map(fn ($t) => [
                'id' => $t->id, 'title' => $t->title, 'status' => $t->status, 'priority' => $t->priority, 'milestone_id' => $t->milestone_id, 'objective_id' => $t->objective_id,
                'start_date' => $t->start_date, 'end_date' => $t->end_date, 'progress' => (int) $t->task_progress,
                'assignees' => (string) ($assignees[$t->id] ?? ''),
                'is_overdue' => $open($t) && $t->end_date && $t->end_date < $today,
            ])->values(),
            'milestones' => $milestones->map(fn ($m) => [
                'id' => $m->id, 'title' => $m->title, 'description' => $m->description, 'status' => $m->status, 'progress' => (int) $m->progress,
                'position' => (int) $m->position, 'budget' => $m->budget === null ? null : (float) $m->budget,
                'due_date' => optional($m->due_date)->toDateString(),
            ])->values(),
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Project::class);

        $project = Project::where('deleted_at', '=', null)->findOrFail($id);
        abort_unless($this->inScope($request, $project->company_id), 404);
        $assigned_employees = EmployeeProject::where('project_id', $id)->pluck('employee_id')->toArray();
        $clients = $this->clientList($request);
        $companies = \App\Support\CompanyNames::visibleTo($request->user('api'));
        $employees = Employee::where('company_id', $project->company_id)->where('deleted_at', '=', null)->orderBy('id', 'desc')->get(['id', 'username']);

        return response()->json([
            'project' => $project,
            'companies' => $companies,
            'clients' => $clients,
            'employees' => $employees,
            'assigned_employees' => $assigned_employees,
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Project::class);

        $request->validate([
            'title' => 'required|string|max:255',
            'client' => 'required',
            'company_id' => 'required',
            // 2026-11-19: the employee is optional (the form never asked for it); dates as before, the end not before the start.
            'assigned_to' => 'nullable|array',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date', // none = open-ended (monthly support, 2026-11-21)
            'status' => 'required',
        ]);

        $project = Project::where('deleted_at', '=', null)->findOrFail($id);
        abort_unless($this->inScope($request, $project->company_id), 404);
        $cid = $this->writeCompanyId($request);
        [$client, $staff] = $this->checkedRefs($request, $cid, (int) $id);

        Project::whereId($id)->update([
            'title' => $request['title'],
            'start_date' => $request['start_date'],
            'end_date' => $request['end_date'] ?: null,
            'company_id' => $cid,
            'client_id' => $client,
            'status' => $request['status'],
            'description' => $request['description'],
        ]);

        $project->assignedEmployees()->sync($staff);

        return response()->json(['success' => true]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Project::class);
        abort_unless($this->inScope($request, Project::whereId($id)->value('company_id')), 404);

        Project::whereId($id)->update([
            'deleted_at' => Carbon::now(),
        ]);

        return response()->json(['success' => true]);

    }
}
