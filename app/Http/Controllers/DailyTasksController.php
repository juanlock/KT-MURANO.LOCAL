<?php

namespace App\Http\Controllers;

use App\Models\ServiceJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Daily Tasks: the service jobs of one day, for the people doing them.
 *  - daily_tasks         open the page; own jobs (the technician linked to my
 *                        employee record); start / complete them with a note.
 *  - daily_tasks_all     everyone's jobs (supervisor).
 *  - daily_tasks_assign  assign / reassign the technician (supervisor).
 * Company: the user's own (a SuperAdmin: the acting company, all when 999).
 * Service jobs have no company_id: their location's, else their customer's.
 */
class DailyTasksController extends BaseController
{
    use \App\Http\Controllers\Concerns\ScopesToCompany;

    private const OPEN = ['pending', 'intake', 'approved', 'in_progress'];

    private function can(Request $request, string $perm): bool
    {
        $user = $request->user('api');

        return $user && ($user->type === 'SuperAdmin' || (bool) $user->roles()->first()?->permissions->contains('name', $perm));
    }

    private function companyId(Request $request): ?int
    {
        $user = $request->user('api');
        $cid = $user->type === 'SuperAdmin' ? $this->resolveActingCompanyId($request) : (int) $user->company_id;

        return $cid === 999 ? null : $cid;
    }

    /** My technician ids (service_technicians linked to my employee record). */
    private function myTechnicianIds(Request $request): array
    {
        $employeeId = DB::table('employees')->where('user_id', $request->user('api')->id)->whereNull('deleted_at')->value('id');

        return $employeeId
            ? DB::table('service_technicians')->where('employee_id', $employeeId)->whereNull('deleted_at')->pluck('id')->map(fn ($v) => (int) $v)->all()
            : [];
    }

    /** Jobs of the company (null = all), as a query on service_jobs as j. */
    private function jobs(?int $companyId)
    {
        return DB::table('service_jobs as j')
            ->leftJoin('warehouses as w', 'w.id', '=', 'j.warehouse_id')
            ->leftJoin('clients as c', 'c.id', '=', 'j.client_id')
            ->leftJoin('service_technicians as t', 't.id', '=', 'j.technician_id')
            ->whereNull('j.deleted_at')
            ->when($companyId, fn ($q) => $q->whereRaw('COALESCE(w.company_id, c.company_id) = ?', [$companyId]));
    }

    /** One job the user may act on (own unless daily_tasks_all), or 404. */
    private function findJob(Request $request, int $id)
    {
        $job = $this->jobs($this->companyId($request))->where('j.id', $id)->first(['j.id', 'j.status', 'j.technician_id', 'j.assign_all', 'j.notes', 'j.completed_at', 'j.started_at', 'w.company_id as wc', 'c.company_id as cc']);
        abort_unless($job, 404);
        if (! $this->can($request, 'daily_tasks_all')) {
            // Mine, or an "All operators" crew job (any linked operator may take it).
            $mine = $this->myTechnicianIds($request);
            abort_unless(in_array((int) $job->technician_id, $mine, true) || (! empty($job->assign_all) && $mine), 403, 'This job is not assigned to you.');
        }

        return $job;
    }

    public function index(Request $request)
    {
        abort_unless($this->can($request, 'daily_tasks'), 403);
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->input('date')) ? $request->input('date') : now()->toDateString();
        // view: day (default) | week (Mon-Sun around the date) | month.
        $view = in_array($request->input('view'), ['week', 'month'], true) ? $request->input('view') : 'day';
        $d = \Carbon\Carbon::parse($date);
        [$from, $to] = match ($view) {
            'week' => [$d->copy()->startOfWeek(\Carbon\Carbon::MONDAY)->toDateString(), $d->copy()->endOfWeek(\Carbon\Carbon::SUNDAY)->toDateString()],
            'month' => [$d->copy()->startOfMonth()->toDateString(), $d->copy()->endOfMonth()->toDateString()],
            default => [$date, $date],
        };
        $all = $this->can($request, 'daily_tasks_all');
        // superadmin/AdminSystem: every company (2026-10-21) -- null = no company filter
        // (999 already was; now also a SuperAdmin with no company picked).
        $companyId = $this->listCompanyId($request);
        $mine = $this->myTechnicianIds($request);

        $rows = $this->jobs($companyId)
            ->whereNotIn('j.status', ['cancelled', 'declined'])
            // In the period: starts in it, or a multi-day job overlapping it.
            ->whereDate('j.scheduled_date', '<=', $to)
            ->where(fn ($q) => $q->whereDate('j.scheduled_end_date', '>=', $from)
                ->orWhere(fn ($w) => $w->whereNull('j.scheduled_end_date')->whereDate('j.scheduled_date', '>=', $from)))
            ->when(! $all, fn ($q) => $q->where(fn ($w) => $w->whereIn('j.technician_id', $mine ?: [0])->when($mine, fn ($x) => $x->orWhere('j.assign_all', true))))
            // By day, then all-day tasks first, then by time.
            ->orderByRaw('DATE(j.scheduled_date)')->orderBy('j.all_day', 'desc')->orderBy('j.scheduled_date')->orderBy('j.id')
            ->get([
                'j.id', 'j.Ref', 'j.service_item', 'j.job_type', 'j.status', 'j.scheduled_date', 'j.scheduled_end_date', 'j.all_day',
                'j.reported_issue', 'j.notes', 'j.technician_id', 'j.assign_all', 't.name as technician', 'j.started_at', 'j.work_hours',
                'c.name as client', 'c.phone as client_phone', 'c.adresse as client_address', 'c.city as client_city', 'w.name as location',
            ]);

        $canAssign = $this->can($request, 'daily_tasks_assign');

        // Around the period: jobs still open from before it (overdue) and the
        // 14 days after it -- so assigned work shows even when the period is empty.
        $brief = fn ($q) => $q->when(! $all, fn ($w) => $w->where(fn ($y) => $y->whereIn('j.technician_id', $mine ?: [0])->when($mine, fn ($x) => $x->orWhere('j.assign_all', true))))
            ->whereIn('j.status', self::OPEN)
            ->get(['j.id', 'j.Ref', 'j.service_item', 'j.status', 'j.scheduled_date', 'j.technician_id', 'j.assign_all', 't.name as technician', 'c.name as client']);
        $overdue = $brief($this->jobs($companyId)->whereDate('j.scheduled_date', '<', $from)
            ->where(fn ($q) => $q->whereNull('j.scheduled_end_date')->orWhereDate('j.scheduled_end_date', '<', $from))
            ->orderBy('j.scheduled_date')->limit(50));
        $upcoming = $brief($this->jobs($companyId)->whereDate('j.scheduled_date', '>', $to)
            ->whereDate('j.scheduled_date', '<=', \Carbon\Carbon::parse($to)->addDays(14)->toDateString())
            ->orderBy('j.scheduled_date')->limit(50));

        return response()->json([
            'date' => $date,
            'view' => $view,
            'from' => $from,
            'to' => $to,
            'tasks' => $rows,
            'overdue' => $overdue,
            'upcoming' => $upcoming,
            'can_all' => $all,
            'can_assign' => $canAssign,
            'can_open_job' => $this->can($request, 'service_jobs'),
            // Technicians to assign (the company's active ones).
            'technicians' => $canAssign
                ? DB::table('service_technicians')->whereNull('deleted_at')->where('is_active', 1)
                    ->when($companyId, fn ($q) => $q->where('company_id', $companyId))->orderBy('name')->get(['id', 'name'])
                : [],
            'linked' => ! empty($mine),
        ]);
    }

    /** Start / complete (or reopen) a job, with an optional note. */
    public function status(Request $request, $id)
    {
        abort_unless($this->can($request, 'daily_tasks'), 403);
        $job = $this->findJob($request, (int) $id);
        // Empty status = just add the note.
        $status = (string) $request->input('status', '');
        $note = trim((string) $request->input('note', ''));
        abort_unless($status === '' || in_array($status, ['pending', 'in_progress', 'completed'], true), 422, 'Invalid status.');
        abort_if($status === '' && $note === '', 422, 'Nothing to save.');

        $user = $request->user('api');
        $attrs = [];
        if ($status !== '' && $status !== $job->status) {
            abort_unless(in_array($job->status, array_merge(self::OPEN, ['completed']), true), 422, 'This job can only be changed from its own page.');
            $attrs['status'] = $status;
            if ($status === 'completed') {
                $attrs['completed_at'] = $job->completed_at ?: now();
            } elseif ($job->status === 'completed') {
                $attrs['completed_at'] = null;
            }
            if ($status === 'in_progress' && ! $job->started_at) {
                $attrs['started_at'] = now();
            }
        }
        if ($note !== '') {
            $attrs['notes'] = trim(($job->notes ? $job->notes."\n" : '').'['.now()->format('Y-m-d H:i').' '.($user->username ?? 'user').'] '.mb_substr($note, 0, 2000));
        }
        // Hours worked (asked on Complete): the job's labor cost and the operator's hourly payroll.
        $hours = $request->input('hours');
        if ($hours !== null && $hours !== '' && \Illuminate\Support\Facades\Schema::hasColumn('service_jobs', 'work_hours')) {
            $attrs['work_hours'] = max(0, min(24 * 14, round((float) $hours, 2)));
        }
        if ($attrs) {
            ServiceJob::whereKey($job->id)->first()->update($attrs);
            if (array_key_exists('work_hours', $attrs) || array_key_exists('status', $attrs)) {
                \App\Support\ServiceJobCost::recalc((int) $job->id);
            }
        }

        return response()->json(['success' => true]);
    }

    /** Assign / reassign the technician (daily_tasks_assign). */
    public function assign(Request $request, $id)
    {
        abort_unless($this->can($request, 'daily_tasks_assign'), 403);
        $job = $this->findJob($request, (int) $id);
        // 'all' = "All operators" (crew job).
        $all = $request->input('technician_id') === 'all';
        $techId = ! $all && $request->input('technician_id') ? (int) $request->input('technician_id') : null;
        if ($techId) {
            $jobCompany = (int) ($job->wc ?: $job->cc);
            abort_unless(DB::table('service_technicians')->where('id', $techId)->where('company_id', $jobCompany)->whereNull('deleted_at')->exists(), 422, 'Operator not found in this company.');
        }
        DB::table('service_jobs')->where('id', $job->id)->update(['technician_id' => $techId, 'assign_all' => $all, 'updated_at' => now()]);
        \App\Support\ServiceJobCost::recalc((int) $job->id);

        return response()->json(['success' => true]);
    }
}
