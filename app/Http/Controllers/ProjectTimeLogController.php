<?php

namespace App\Http\Controllers;

use App\Models\ProjectTimeLog;
use Illuminate\Http\Request;

/**
 * Timesheets: hours booked against projects and tasks.
 *
 * `amount` is ALWAYS recomputed from hours x rate and is zero for
 * non-billable time — a client-supplied amount is never trusted, because this
 * figure ends up on invoices.
 */
class ProjectTimeLogController extends Controller
{
    private const SORTABLE = ['id', 'log_date', 'hours', 'amount', 'created_at'];

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', ProjectTimeLog::class);

        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) $request->get('page', 1));
        $order = in_array($request->SortField, self::SORTABLE, true) ? $request->SortField : 'log_date';
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';

        $query = ProjectTimeLog::with('project', 'task', 'employee')->whereNull('deleted_at')
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->project_id))
            ->when($request->filled('task_id'), fn ($q) => $q->where('task_id', $request->task_id))
            ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', $request->employee_id))
            ->when($request->filled('billable'), fn ($q) => $q->where('billable', $request->billable === '1' ? 1 : 0))
            ->when($request->filled('start_date'), fn ($q) => $q->whereDate('log_date', '>=', $request->start_date))
            ->when($request->filled('end_date'), fn ($q) => $q->whereDate('log_date', '<=', $request->end_date))
            ->when($request->filled('search'), fn ($q) => $q->where('description', 'LIKE', "%{$request->search}%"));

        $totalRows = $query->count();
        $totals = [
            'hours' => round((float) $query->sum('hours'), 2),
            'amount' => round((float) $query->sum('amount'), 2),
        ];

        if ($perPage === -1) {
            $perPage = max(1, $totalRows);
        }
        $offset = ($page * $perPage) - $perPage;

        $rows = $query->orderBy($order, $dir)->offset($offset)->limit($perPage)->get();

        return response()->json([
            'totalRows' => $totalRows,
            'totals' => $totals,
            'time_logs' => $rows->map(fn ($l) => [
                'id' => $l->id,
                'project_id' => $l->project_id,
                'project_title' => $l->project ? $l->project->title : '',
                'task_id' => $l->task_id,
                'task_title' => $l->task ? $l->task->title : null,
                'employee_id' => $l->employee_id,
                'employee_name' => $l->employee
                    ? trim($l->employee->firstname . ' ' . $l->employee->lastname)
                    : null,
                'log_date' => optional($l->log_date)->toDateString(),
                'hours' => (float) $l->hours,
                'billable' => (bool) $l->billable,
                'hourly_rate' => $l->hourly_rate === null ? null : (float) $l->hourly_rate,
                'amount' => (float) $l->amount,
                'description' => $l->description,
            ])->values(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', ProjectTimeLog::class);

        $request->validate($this->rules());

        ProjectTimeLog::create($this->payload($request) + [
            'created_by' => optional($request->user('api'))->id,
        ]);

        return response()->json(['success' => true]);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', ProjectTimeLog::class);

        $request->validate($this->rules());
        ProjectTimeLog::whereNull('deleted_at')->findOrFail($id)->update($this->payload($request));

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', ProjectTimeLog::class);

        ProjectTimeLog::whereNull('deleted_at')->findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    public function deleteBySelection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', ProjectTimeLog::class);

        $ids = (array) $request->selectedIds;
        ProjectTimeLog::whereIn('id', $ids)->delete();

        return response()->json(['success' => true, 'count' => count($ids)]);
    }

    private function rules()
    {
        return [
            'project_id' => 'required|exists:projects,id',
            'task_id' => 'nullable|exists:tasks,id',
            'employee_id' => 'nullable|exists:employees,id',
            'log_date' => 'required|date',
            // A day has 24 hours; anything above is a typo, not overtime.
            'hours' => 'required|numeric|min:0.01|max:24',
            'hourly_rate' => 'nullable|numeric|min:0',
        ];
    }

    private function payload(Request $request)
    {
        $hours = (float) $request->hours;
        $rate = (float) $request->hourly_rate;
        $billable = $request->boolean('billable');

        return [
            'project_id' => $request->project_id,
            'task_id' => $request->task_id ?: null,
            'employee_id' => $request->employee_id ?: null,
            'log_date' => $request->log_date,
            'hours' => $hours,
            'billable' => $billable,
            'hourly_rate' => $request->filled('hourly_rate') ? $rate : null,
            // Recomputed, never taken from the client; non-billable is worth 0.
            'amount' => $billable ? round($hours * $rate, 2) : 0,
            'description' => $request->description,
        ];
    }
}
