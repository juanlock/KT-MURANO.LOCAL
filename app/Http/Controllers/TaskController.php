<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeTask;
use App\Models\Project;
use App\Models\Task;
use App\utils\helpers;
use Carbon\Carbon;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Task::class);
        // How many items do you want to display.
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = $request->SortType;
        $data = [];
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
            1 => 'project_id',
            2 => 'start_date',
            3 => 'end_date',
            4 => 'company_id',
        ];
        $tasks = Task::with('company', 'project')->where('deleted_at', '=', null);

        // Multiple Filter
        $Filtred = $helpers->filter($tasks, $columns, $param, $request)
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
                            return $query->whereHas('project', function ($q) use ($request) {
                                $q->where('title', 'LIKE', "%{$request->search}%");
                            });
                        });
                });
            });

        $totalRows = $Filtred->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }
        $tasks = $Filtred->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        foreach ($tasks as $task) {
            $item['id'] = $task->id;
            $item['title'] = $task->title;
            $item['start_date'] = $task->start_date;
            $item['end_date'] = $task->end_date;
            $item['project_title'] = $task['project']->title;
            $item['company_name'] = $task['company']->name;
            $item['status'] = $task->status;

            $data[] = $item;
        }

        $companies = Company::where('deleted_at', '=', null)->get(['id', 'name']);
        $projects = Project::where('deleted_at', '=', null)->orderBy('id', 'desc')->get(['id', 'title']);

        $count_not_started = Task::where('deleted_at', '=', null)
            ->where('status', '=', 'not_started')
            ->count();

        $count_in_progress = Task::where('deleted_at', '=', null)
            ->where('status', '=', 'progress')
            ->count();

        $count_cancelled = Task::where('deleted_at', '=', null)
            ->where('status', '=', 'cancelled')
            ->count();

        $count_completed = Task::where('deleted_at', '=', null)
            ->where('status', '=', 'completed')
            ->count();

        return response()->json([
            'tasks' => $data,
            'companies' => $companies,
            'projects' => $projects,
            'count_not_started' => $count_not_started,
            'count_in_progress' => $count_in_progress,
            'count_cancelled' => $count_cancelled,
            'count_completed' => $count_completed,
            'totalRows' => $totalRows,
        ]);

    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Task::class);

        $projects = Project::where('deleted_at', '=', null)->orderBy('id', 'desc')->get(['id', 'title']);
        $companies = Company::where('deleted_at', '=', null)->orderBy('id', 'desc')->get(['id', 'name']);

        return response()->json([
            'projects' => $projects,
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
        $this->authorizeForUser($request->user('api'), 'view', Task::class);

        $request->validate([
            'title' => 'required|string|max:255',
            'project_id' => 'required',
            'start_date' => 'required',
            'end_date' => 'required',
            'status' => 'required',
            'company_id' => 'required',
        ]);

        $task = Task::create([
            'title' => $request['title'],
            'start_date' => $request['start_date'],
            'end_date' => $request['end_date'],
            'project_id' => $request['project_id'],
            'company_id' => $request['company_id'],
            'status' => $request['status'],
            'description' => $request['description'],
            'priority' => $request['priority'],
            'estimated_hour' => $request['estimated_hour'],
            'task_progress' => $request['task_progress'] ?: 0,
        ]);

        $task->assignedEmployees()->sync($request['assigned_to']);

        return response()->json(['success' => true]);

    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Task::class);

        $task = Task::where('deleted_at', '=', null)->findOrFail($id);
        $discussions = TaskDiscussion::where('task_id', $id)->where('deleted_at', '=', null)->with('User:id,username')->orderBy('id', 'desc')->get();
        $documents = TaskDocument::where('task_id', $id)->where('deleted_at', '=', null)->orderBy('id', 'desc')->get();

        return response()->json([
            'task' => $task,
            'discussions' => $discussions,
            'documents' => $documents,
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
        $this->authorizeForUser($request->user('api'), 'view', Task::class);

        $task = Task::where('deleted_at', '=', null)->findOrFail($id);
        $assigned_employees = EmployeeTask::where('task_id', $id)->pluck('employee_id')->toArray();
        $projects = Project::where('deleted_at', '=', null)->orderBy('id', 'desc')->get(['id', 'title']);
        $companies = Company::where('deleted_at', '=', null)->orderBy('id', 'desc')->get(['id', 'name']);
        $employees = Employee::where('company_id', $task->company_id)->where('deleted_at', '=', null)->orderBy('id', 'desc')->get(['id', 'username']);

        return response()->json([
            'task' => $task,
            'assigned_employees' => $assigned_employees,
            'projects' => $projects,
            'companies' => $companies,
            'employees' => $employees,
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
        $this->authorizeForUser($request->user('api'), 'view', Task::class);

        $request->validate([
            'title' => 'required|string|max:255',
            'project_id' => 'required',
            'start_date' => 'required',
            'end_date' => 'required',
            'status' => 'required',
            'company_id' => 'required',
        ]);

        Task::whereId($id)->update([
            'title' => $request['title'],
            'start_date' => $request['start_date'],
            'end_date' => $request['end_date'],
            'project_id' => $request['project_id'],
            'company_id' => $request['company_id'],
            'status' => $request['status'],
            'description' => $request['description'],
            'priority' => $request['priority'],
            'estimated_hour' => $request['estimated_hour'],
            'task_progress' => $request['task_progress'] ?: 0,
        ]);

        $task = Task::where('deleted_at', '=', null)->findOrFail($id);
        $task->assignedEmployees()->sync($request['assigned_to']);

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
        $this->authorizeForUser($request->user('api'), 'view', Task::class);

        Task::whereId($id)->update([
            'deleted_at' => Carbon::now(),
        ]);

        return response()->json(['success' => true]);

    }

    /**
     * The task board, grouped by status.
     *
     * This method (and the two below) are reached by routes that already
     * existed but had no implementation — calling them used to 500. The board
     * columns are the statuses the Tasks list already filters on, so the two
     * screens can never disagree about what states exist.
     */
    public function tasks_kanban(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Task::class);

        $tasks = Task::with('project')->where('deleted_at', '=', null)
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->project_id))
            ->when($request->filled('priority'), fn ($q) => $q->where('priority', $request->priority))
            ->when($request->filled('search'), fn ($q) => $q->where('title', 'LIKE', "%{$request->search}%"))
            ->orderBy('end_date')
            ->get();

        $today = \Carbon\Carbon::today();

        $present = function ($task) use ($today) {
            $overdue = $task->end_date
                && $task->status !== 'completed'
                && \Carbon\Carbon::parse($task->end_date)->lt($today);

            return [
                'id' => $task->id,
                'title' => $task->title,
                'project_id' => $task->project_id,
                'project_title' => $task->project ? $task->project->title : null,
                'start_date' => $task->start_date,
                'end_date' => $task->end_date,
                'status' => $task->status,
                'priority' => $task->priority,
                'progress' => (int) $task->task_progress,
                'estimated_hour' => $task->estimated_hour === null ? null : (float) $task->estimated_hour,
                'is_overdue' => $overdue,
            ];
        };

        $columns = [];
        foreach (['not_started', 'progress', 'completed', 'cancelled'] as $status) {
            $columns[$status] = $tasks->where('status', $status)->map($present)->values();
        }

        return response()->json([
            'columns' => $columns,
            'total' => $tasks->count(),
            'overdue' => $tasks->filter(fn ($t) => $present($t)['is_overdue'])->count(),
        ]);
    }

    /** Move a card between board columns. */
    public function task_change_status(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', Task::class);

        $request->validate([
            'id' => 'required|exists:tasks,id',
            'status' => 'required|in:not_started,progress,completed,cancelled',
        ]);

        $task = Task::where('deleted_at', '=', null)->findOrFail($request->id);
        $task->status = $request->status;
        // Completing from the board should not leave the bar at 40%.
        if ($request->status === 'completed') {
            $task->task_progress = 100;
        }
        $task->save();

        return response()->json(['success' => true, 'status' => $task->status]);
    }

    /** Same change, addressed by route parameter. */
    public function update_task_status(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Task::class);

        $request->validate(['status' => 'required|in:not_started,progress,completed,cancelled']);

        $task = Task::where('deleted_at', '=', null)->findOrFail($id);
        $task->status = $request->status;
        if ($request->status === 'completed') {
            $task->task_progress = 100;
        }
        $task->save();

        return response()->json(['success' => true, 'status' => $task->status]);
    }
}
