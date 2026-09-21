<?php

namespace App\Http\Controllers;

use App\Models\ProjectMilestone;
use Illuminate\Http\Request;

/**
 * Project milestones — the plan a project is measured against.
 *
 * Completing a milestone stamps `completed_on` and forces progress to 100;
 * re-opening one clears the stamp. Keeping those two in step here means the
 * milestone report can trust `completed_on` when it computes slippage.
 */
class ProjectMilestoneController extends Controller
{
    private const SORTABLE = ['id', 'title', 'due_date', 'status', 'progress', 'position', 'created_at'];

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', ProjectMilestone::class);

        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) $request->get('page', 1));
        $order = in_array($request->SortField, self::SORTABLE, true) ? $request->SortField : 'due_date';
        $dir = strtolower((string) $request->SortType) === 'desc' ? 'desc' : 'asc';

        $query = ProjectMilestone::with('project')->whereNull('deleted_at')
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->project_id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->boolean('overdue'), fn ($q) => $q->where('status', '!=', 'completed')
                ->whereNotNull('due_date')->whereDate('due_date', '<', now()->toDateString()))
            ->when($request->filled('search'), fn ($q) => $q->where('title', 'LIKE', "%{$request->search}%"));

        $totalRows = $query->count();
        if ($perPage === -1) {
            $perPage = max(1, $totalRows);
        }
        $offset = ($page * $perPage) - $perPage;

        $rows = $query->orderBy($order, $dir)->orderBy('position')
            ->offset($offset)->limit($perPage)->get();

        return response()->json([
            'totalRows' => $totalRows,
            'milestones' => $rows->map(fn ($m) => $this->present($m))->values(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', ProjectMilestone::class);

        $request->validate($this->rules());

        ProjectMilestone::create($this->payload($request) + [
            'created_by' => optional($request->user('api'))->id,
        ]);

        return response()->json(['success' => true]);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', ProjectMilestone::class);

        $request->validate($this->rules());
        ProjectMilestone::whereNull('deleted_at')->findOrFail($id)->update($this->payload($request));

        return response()->json(['success' => true]);
    }

    /** Quick toggle from the list, without opening the form. */
    public function setStatus(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', ProjectMilestone::class);

        $request->validate(['status' => 'required|in:pending,in_progress,completed,delayed']);

        $milestone = ProjectMilestone::whereNull('deleted_at')->findOrFail($id);
        $milestone->update($this->applyCompletion([
            'status' => $request->status,
            'progress' => $milestone->progress,
            'completed_on' => optional($milestone->completed_on)->toDateString(),
        ]));

        return response()->json(['success' => true, 'status' => $milestone->fresh()->status]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', ProjectMilestone::class);

        ProjectMilestone::whereNull('deleted_at')->findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    public function deleteBySelection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', ProjectMilestone::class);

        $ids = (array) $request->selectedIds;
        ProjectMilestone::whereIn('id', $ids)->delete();

        return response()->json(['success' => true, 'count' => count($ids)]);
    }

    private function present(ProjectMilestone $m)
    {
        return [
            'id' => $m->id,
            'project_id' => $m->project_id,
            'project_title' => $m->project ? $m->project->title : '',
            'title' => $m->title,
            'description' => $m->description,
            'due_date' => optional($m->due_date)->toDateString(),
            'completed_on' => optional($m->completed_on)->toDateString(),
            'status' => $m->status,
            'progress' => (int) $m->progress,
            'budget' => $m->budget === null ? null : (float) $m->budget,
            'position' => (int) $m->position,
            'days_to_due' => $m->days_to_due,
            'is_overdue' => $m->is_overdue,
        ];
    }

    private function rules()
    {
        return [
            'project_id' => 'required|exists:projects,id',
            'title' => 'required|string|max:191',
            'due_date' => 'nullable|date',
            'completed_on' => 'nullable|date',
            'status' => 'required|in:pending,in_progress,completed,delayed',
            'progress' => 'nullable|integer|min:0|max:100',
            'budget' => 'nullable|numeric|min:0',
            'position' => 'nullable|integer|min:0|max:9999',
        ];
    }

    private function payload(Request $request)
    {
        return $this->applyCompletion([
            'project_id' => $request->project_id,
            'title' => $request->title,
            'description' => $request->description,
            'due_date' => $request->due_date ?: null,
            'completed_on' => $request->completed_on ?: null,
            'status' => $request->status,
            'progress' => $request->progress === null ? 0 : (int) $request->progress,
            'budget' => $request->budget ?: null,
            'position' => $request->position ?: 0,
        ]);
    }

    /**
     * Keep status, progress and completed_on consistent — a "completed"
     * milestone at 40% with no completion date is the kind of record that makes
     * every downstream report lie.
     */
    private function applyCompletion(array $data)
    {
        if (($data['status'] ?? null) === 'completed') {
            $data['progress'] = 100;
            $data['completed_on'] = $data['completed_on'] ?: now()->toDateString();
        } else {
            $data['completed_on'] = null;
            if (($data['progress'] ?? 0) >= 100) {
                $data['progress'] = 99;
            }
        }

        return $data;
    }
}
