<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\ProjectTimeLog;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The Projects Management workspace: dashboard, shared option lists and the
 * cross-project reports.
 *
 * One endpoint feeds the whole dashboard, because the project counts, task
 * counts and logged hours have to agree with each other; separate calls would
 * let the panels drift apart mid-render.
 *
 * Reports answer { rows, totalRows, totals } so the report shell's
 * export-everything refetch (limit=-1) works without special cases.
 */
class ProjectWorkspaceController extends Controller
{
    /** Statuses the existing modules use, kept in one place. */
    private const OPEN_PROJECT_STATUSES = ['not_started', 'progress', 'on_hold'];

    private const DONE_TASK_STATUSES = ['completed'];

    public function dashboard(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Project::class);

        $today = Carbon::today();
        $monthStart = $today->copy()->startOfMonth();

        $projectsByStatus = Project::whereNull('deleted_at')
            ->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')->pluck('aggregate', 'status')->toArray();

        $tasksByStatus = Task::whereNull('deleted_at')
            ->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')->pluck('aggregate', 'status')->toArray();

        $totalTasks = array_sum($tasksByStatus);
        $doneTasks = 0;
        foreach (self::DONE_TASK_STATUSES as $status) {
            $doneTasks += (int) ($tasksByStatus[$status] ?? 0);
        }

        return response()->json([
            'projects_total' => Project::whereNull('deleted_at')->count(),
            'projects_by_status' => collect($projectsByStatus)
                ->map(fn ($count, $status) => ['status' => $status, 'count' => $count])->values(),
            'projects_open' => Project::whereNull('deleted_at')
                ->whereIn('status', self::OPEN_PROJECT_STATUSES)->count(),
            // Past its end date and still not completed — the list that matters.
            'projects_overdue' => Project::whereNull('deleted_at')
                ->whereNotNull('end_date')
                ->whereDate('end_date', '<', $today->toDateString())
                ->where('status', '!=', 'completed')->count(),

            'tasks_total' => $totalTasks,
            'tasks_by_status' => collect($tasksByStatus)
                ->map(fn ($count, $status) => ['status' => $status, 'count' => $count])->values(),
            'tasks_done' => $doneTasks,
            'tasks_completion' => $totalTasks > 0 ? round(($doneTasks / $totalTasks) * 100, 1) : null,
            'tasks_overdue' => Task::whereNull('deleted_at')
                ->whereNotNull('end_date')
                ->whereDate('end_date', '<', $today->toDateString())
                ->whereNotIn('status', self::DONE_TASK_STATUSES)->count(),

            'milestones_open' => ProjectMilestone::whereNull('deleted_at')
                ->where('status', '!=', 'completed')->count(),
            'milestones_overdue' => ProjectMilestone::whereNull('deleted_at')
                ->where('status', '!=', 'completed')
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', $today->toDateString())->count(),

            'hours_month' => round((float) ProjectTimeLog::whereNull('deleted_at')
                ->whereDate('log_date', '>=', $monthStart->toDateString())->sum('hours'), 2),
            'billable_month' => round((float) ProjectTimeLog::whereNull('deleted_at')
                ->where('billable', 1)
                ->whereDate('log_date', '>=', $monthStart->toDateString())->sum('amount'), 2),

            'hours_trend' => $this->hoursTrend(),
            'upcoming_milestones' => $this->upcomingMilestones($today),
            'active_projects' => $this->activeProjects(),
            'workload' => $this->workload(),
        ]);
    }

    /** Hours logged per day for the last 14 days, oldest first. */
    private function hoursTrend()
    {
        $from = Carbon::today()->subDays(13);

        $rows = ProjectTimeLog::whereNull('deleted_at')
            ->whereDate('log_date', '>=', $from->toDateString())
            ->select('log_date', DB::raw('SUM(hours) as hours'), DB::raw('SUM(amount) as amount'))
            ->groupBy('log_date')->get()
            ->keyBy(fn ($r) => substr((string) $r->log_date, 0, 10));

        $days = [];
        for ($i = 13; $i >= 0; $i--) {
            $day = Carbon::today()->subDays($i)->toDateString();
            $row = $rows->get($day);
            $days[] = [
                'd' => $day,
                'hours' => round((float) ($row->hours ?? 0), 2),
                'amount' => round((float) ($row->amount ?? 0), 2),
            ];
        }

        return $days;
    }

    private function upcomingMilestones(Carbon $today)
    {
        return ProjectMilestone::with('project')->whereNull('deleted_at')
            ->where('status', '!=', 'completed')
            ->whereNotNull('due_date')
            ->orderBy('due_date')
            ->limit(8)->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'title' => $m->title,
                'project_id' => $m->project_id,
                'project_title' => $m->project ? $m->project->title : '',
                'due_date' => optional($m->due_date)->toDateString(),
                'days_to_due' => $m->days_to_due,
                'progress' => (int) $m->progress,
                'status' => $m->status,
            ])->values();
    }

    /** Open projects with their task progress, busiest first. */
    private function activeProjects()
    {
        $projects = Project::with('client')->whereNull('deleted_at')
            ->whereIn('status', self::OPEN_PROJECT_STATUSES)
            ->orderBy('end_date')->limit(8)->get();

        $taskCounts = Task::whereNull('deleted_at')
            ->whereIn('project_id', $projects->pluck('id'))
            ->select('project_id', 'status', DB::raw('count(*) as aggregate'))
            ->groupBy('project_id', 'status')->get()->groupBy('project_id');

        $hours = ProjectTimeLog::whereNull('deleted_at')
            ->whereIn('project_id', $projects->pluck('id'))
            ->select('project_id', DB::raw('SUM(hours) as hours'))
            ->groupBy('project_id')->pluck('hours', 'project_id')->toArray();

        return $projects->map(function ($p) use ($taskCounts, $hours) {
            $mine = $taskCounts->get($p->id, collect());
            $total = (int) $mine->sum('aggregate');
            $done = (int) $mine->whereIn('status', self::DONE_TASK_STATUSES)->sum('aggregate');

            return [
                'id' => $p->id,
                'title' => $p->title,
                'client_name' => $p->client ? $p->client->name : null,
                'status' => $p->status,
                'end_date' => $p->end_date,
                'tasks' => $total,
                'tasks_done' => $done,
                'progress' => $total > 0 ? round(($done / $total) * 100) : 0,
                'hours' => round((float) ($hours[$p->id] ?? 0), 2),
            ];
        })->values();
    }

    /** Hours per person this month — who is carrying the work. */
    private function workload()
    {
        $monthStart = Carbon::today()->startOfMonth();

        $rows = ProjectTimeLog::whereNull('deleted_at')
            ->whereNotNull('employee_id')
            ->whereDate('log_date', '>=', $monthStart->toDateString())
            ->select('employee_id', DB::raw('SUM(hours) as hours'))
            ->groupBy('employee_id')->orderByDesc('hours')->limit(8)->get();

        $employees = Employee::whereIn('id', $rows->pluck('employee_id'))->get()->keyBy('id');

        return $rows->map(function ($r) use ($employees) {
            $employee = $employees->get($r->employee_id);

            return [
                'id' => $r->employee_id,
                'name' => $employee ? trim($employee->firstname . ' ' . $employee->lastname) : '—',
                'hours' => round((float) $r->hours, 2),
            ];
        })->values();
    }

    /** Selects every workspace form needs, in one call. */
    public function meta(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Project::class);

        return response()->json([
            'projects' => Project::whereNull('deleted_at')->orderBy('title')
                ->get(['id', 'title', 'status', 'client_id']),
            'tasks' => Task::whereNull('deleted_at')->orderBy('title')
                ->get(['id', 'title', 'project_id', 'status']),
            'employees' => Employee::orderBy('firstname')->get(['id', 'firstname', 'lastname'])
                ->map(fn ($e) => ['id' => $e->id, 'name' => trim($e->firstname . ' ' . $e->lastname)])
                ->values(),
            'clients' => Client::whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    // ------------------------------------------------------------------
    // Reports
    // ------------------------------------------------------------------

    /** Delivery per project: tasks, progress, hours, and whether it is late. */
    public function projectReport(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'report', Project::class);

        [$from, $to] = $this->range($request);
        $today = Carbon::today();

        $projects = Project::with('client')->whereNull('deleted_at')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->client_id))
            ->when($request->filled('search'), fn ($q) => $q->where('title', 'LIKE', "%{$request->search}%"))
            ->get();

        $tasks = Task::whereNull('deleted_at')
            ->select('project_id', 'status', DB::raw('count(*) as aggregate'), DB::raw('SUM(estimated_hour) as estimated'))
            ->groupBy('project_id', 'status')->get()->groupBy('project_id');

        $hours = ProjectTimeLog::whereNull('deleted_at')
            ->when($from, fn ($q) => $q->whereDate('log_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('log_date', '<=', $to))
            ->select('project_id', DB::raw('SUM(hours) as hours'), DB::raw('SUM(amount) as amount'))
            ->groupBy('project_id')->get()->keyBy('project_id');

        $milestones = ProjectMilestone::whereNull('deleted_at')
            ->select('project_id', DB::raw('count(*) as total'),
                DB::raw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as done"))
            ->groupBy('project_id')->get()->keyBy('project_id');

        $rows = $projects->map(function ($p) use ($tasks, $hours, $milestones, $today) {
            $mine = $tasks->get($p->id, collect());
            $total = (int) $mine->sum('aggregate');
            $done = (int) $mine->whereIn('status', self::DONE_TASK_STATUSES)->sum('aggregate');
            $late = $p->end_date && $p->status !== 'completed'
                && Carbon::parse($p->end_date)->lt($today);

            return [
                'id' => $p->id,
                'title' => $p->title,
                'client_name' => $p->client ? $p->client->name : null,
                'status' => $p->status,
                'start_date' => $p->start_date,
                'end_date' => $p->end_date,
                'is_late' => $late,
                'days_late' => $late ? $today->diffInDays(Carbon::parse($p->end_date)) : 0,
                'tasks' => $total,
                'tasks_done' => $done,
                'progress' => $total > 0 ? round(($done / $total) * 100, 1) : 0,
                'milestones' => (int) ($milestones[$p->id]->total ?? 0),
                'milestones_done' => (int) ($milestones[$p->id]->done ?? 0),
                'estimated_hours' => round((float) $mine->sum('estimated'), 2),
                'logged_hours' => round((float) ($hours[$p->id]->hours ?? 0), 2),
                'billable_amount' => round((float) ($hours[$p->id]->amount ?? 0), 2),
            ];
        });

        return $this->paginated($request, $rows, [
            'tasks' => $rows->sum('tasks'),
            'tasks_done' => $rows->sum('tasks_done'),
            'logged_hours' => round($rows->sum('logged_hours'), 2),
            'billable_amount' => round($rows->sum('billable_amount'), 2),
        ], 'logged_hours');
    }

    /** Hours and billing per person. */
    public function workloadReport(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'report', Project::class);

        [$from, $to] = $this->range($request);

        $logs = ProjectTimeLog::whereNull('deleted_at')
            ->whereNotNull('employee_id')
            ->when($from, fn ($q) => $q->whereDate('log_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('log_date', '<=', $to))
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->project_id))
            ->get()->groupBy('employee_id');

        if ($logs->isEmpty()) {
            return $this->paginated($request, collect(), ['hours' => 0, 'billable_amount' => 0], 'hours');
        }

        $employees = Employee::whereIn('id', $logs->keys())
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->where(function ($inner) use ($request) {
                    $inner->where('firstname', 'LIKE', "%{$request->search}%")
                        ->orWhere('lastname', 'LIKE', "%{$request->search}%");
                });
            })->get()->keyBy('id');

        $rows = collect();
        foreach ($logs as $employeeId => $group) {
            $employee = $employees->get($employeeId);
            if (! $employee) {
                continue;
            }

            $hours = (float) $group->sum('hours');
            $billableHours = (float) $group->where('billable', true)->sum('hours');

            $rows->push([
                'id' => (int) $employeeId,
                'name' => trim($employee->firstname . ' ' . $employee->lastname),
                'entries' => $group->count(),
                'projects' => $group->pluck('project_id')->unique()->count(),
                'hours' => round($hours, 2),
                'billable_hours' => round($billableHours, 2),
                // The share of effort that can actually be invoiced.
                'billable_rate' => $hours > 0 ? round(($billableHours / $hours) * 100, 1) : null,
                'billable_amount' => round((float) $group->where('billable', true)->sum('amount'), 2),
            ]);
        }

        return $this->paginated($request, $rows->values(), [
            'hours' => round($rows->sum('hours'), 2),
            'billable_amount' => round($rows->sum('billable_amount'), 2),
        ], 'hours');
    }

    /** Milestone delivery: what is on plan, what has slipped. */
    public function milestoneReport(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'report', Project::class);

        $today = Carbon::today();

        $rows = ProjectMilestone::with('project')->whereNull('deleted_at')
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->project_id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('search'), fn ($q) => $q->where('title', 'LIKE', "%{$request->search}%"))
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'title' => $m->title,
                'project_id' => $m->project_id,
                'project_title' => $m->project ? $m->project->title : '',
                'due_date' => optional($m->due_date)->toDateString(),
                'completed_on' => optional($m->completed_on)->toDateString(),
                'status' => $m->status,
                'progress' => (int) $m->progress,
                'days_to_due' => $m->days_to_due,
                'is_overdue' => $m->is_overdue,
                // Positive = delivered late, negative = early. Null while open.
                'slippage' => ($m->completed_on && $m->due_date)
                    ? Carbon::parse($m->due_date)->diffInDays(Carbon::parse($m->completed_on), false)
                    : null,
                'budget' => $m->budget === null ? null : (float) $m->budget,
            ]);

        return $this->paginated($request, $rows, [
            'milestones' => $rows->count(),
            'overdue' => $rows->filter(fn ($r) => $r['is_overdue'])->count(),
        ], 'due_date', false);
    }

    // ------------------------------------------------------------------
    // Shared pieces
    // ------------------------------------------------------------------

    private function range(Request $request)
    {
        return [
            $request->filled('start_date') ? $request->start_date : null,
            $request->filled('end_date') ? $request->end_date : null,
        ];
    }

    /**
     * Sort + page an assembled collection. limit=-1 returns everything, which
     * the export uses. Sorting happens here because these rows are computed,
     * and ORDER BY on a column MySQL never selected is a 1054.
     */
    private function paginated(Request $request, $rows, array $totals, $defaultSort, $descendingDefault = true)
    {
        $sortField = $request->SortField ?: $defaultSort;
        $descending = $request->filled('SortType')
            ? strtolower((string) $request->SortType) !== 'asc'
            : $descendingDefault;

        if ($rows->count() && array_key_exists($sortField, $rows->first())) {
            $rows = $descending
                ? $rows->sortByDesc($sortField, SORT_NATURAL | SORT_FLAG_CASE)
                : $rows->sortBy($sortField, SORT_NATURAL | SORT_FLAG_CASE);
        }
        $rows = $rows->values();

        $totalRows = $rows->count();
        $perPage = (int) ($request->limit ?? 10);
        if ($perPage === -1) {
            return response()->json(['rows' => $rows, 'totalRows' => $totalRows, 'totals' => $totals]);
        }

        $page = max(1, (int) $request->get('page', 1));

        return response()->json([
            'rows' => $rows->slice(($page - 1) * $perPage, $perPage)->values(),
            'totalRows' => $totalRows,
            'totals' => $totals,
        ]);
    }
}
