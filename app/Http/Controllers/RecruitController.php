<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\RecruitJob;
use App\Models\RecruitJobCategory;
use App\Models\RecruitCandidate;
use App\Models\RecruitApplication;
use App\Models\RecruitInterview;
use App\Support\CompanyFiles;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RecruitController extends Controller
{
    use ScopesToCompany;

    /**
     * Store an uploaded recruit file under the acting company's
     * files/company_{id}/recruit/<dir> folder and return its public-relative
     * path. Stocky serves uploads from public/images directly (no storage:link).
     */
    private function storeUpload($file, string $dir, int $companyId): string
    {
        $base = CompanyFiles::dir($companyId, 'recruit/' . $dir);
        $destination = public_path($base);
        $filename = Str::random(20) . '.' . $file->getClientOriginalExtension();
        $file->move($destination, $filename);

        return $base . '/' . $filename;
    }

    /** superadmin/AdminSystem: every company (2026-10-21) -- each row says whose it is (one query). */
    private function withCompanyNames($rows)
    {
        $names = \App\Models\Company::whereIn('id', $rows->pluck('company_id')->unique())->pluck('name', 'id');
        $rows->each(fn ($r) => $r->company_name = $r->company_id == \App\Models\Setting::TEMPLATE_COMPANY_ID ? 'AdminSystem' : ($names[$r->company_id] ?? null));

        return $rows;
    }

    // ======================== DASHBOARD ========================

    public function dashboard(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', RecruitJob::class);

        // superadmin/AdminSystem: every company (2026-10-21) -- null = no company filter.
        $companyId = $this->listCompanyId($request);

        $stats = [
            'total_jobs'         => RecruitJob::when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))->count(),
            'open_jobs'          => RecruitJob::when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))->where('status', 'open')->count(),
            'total_candidates'   => RecruitCandidate::when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))->count(),
            'total_applications' => RecruitApplication::when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))->count(),
            'pending_interviews' => RecruitInterview::when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))->where('status', 'scheduled')->count(),
            'hired_count'        => RecruitApplication::when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))->where('stage', 'hired')->count(),
        ];

        $recent_applications = RecruitApplication::with(['candidate', 'job'])
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        $upcoming_interviews = RecruitInterview::with(['application.candidate', 'application.job'])
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->where('status', 'scheduled')
            ->where('scheduled_at', '>=', now())
            ->orderBy('scheduled_at')
            ->limit(10)
            ->get();

        $pipeline = RecruitApplication::select('stage', DB::raw('count(*) as count'))
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->groupBy('stage')
            ->pluck('count', 'stage')
            ->toArray();

        return response()->json([
            'stats'               => $stats,
            'recent_applications' => $recent_applications,
            'upcoming_interviews' => $upcoming_interviews,
            'pipeline'            => $pipeline,
        ]);
    }

    // ======================== JOB CATEGORIES ========================

    public function categories_index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', RecruitJobCategory::class);

        // superadmin/AdminSystem: every company (2026-10-21) -- null = no company filter.
        $companyId = $this->listCompanyId($request);

        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField ?: 'id';
        $dir = $request->SortType ?: 'desc';

        $categories = RecruitJobCategory::withCount('jobs')
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('name', 'LIKE', "%{$request->search}%");
                });
            });

        $totalRows = $categories->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }

        $categories = $this->withCompanyNames($categories->offset($offSet)->limit($perPage)->orderBy($order, $dir)->get());

        return response()->json([
            'categories' => $categories,
            'totalRows'  => $totalRows,
        ]);
    }

    public function categories_all(Request $request)
    {
        return response()->json(
            RecruitJobCategory::where('company_id', $this->resolveActingCompanyId($request))
                ->where('is_active', true)->orderBy('name')->get(['id', 'name'])
        );
    }

    public function categories_store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', RecruitJobCategory::class);

        $request->validate(['name' => 'required|string|max:255']);

        RecruitJobCategory::create([
            'company_id'  => $this->resolveActingCompanyId($request),
            'name'        => $request->name,
            'description' => $request->description,
            'is_active'   => $request->filled('is_active') ? $request->is_active : true,
        ]);

        return response()->json(['success' => true]);
    }

    public function categories_update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', RecruitJobCategory::class);

        $request->validate(['name' => 'required|string|max:255']);

        $category = RecruitJobCategory::findOrFail($id);
        abort_unless((int) $category->company_id === $this->resolveActingCompanyId($request), 404);

        $category->update([
            'name'        => $request->name,
            'description' => $request->description,
            'is_active'   => $request->filled('is_active') ? $request->is_active : true,
        ]);

        return response()->json(['success' => true]);
    }

    public function categories_destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', RecruitJobCategory::class);

        $category = RecruitJobCategory::findOrFail($id);
        abort_unless((int) $category->company_id === $this->resolveActingCompanyId($request), 404);
        $category->update(['deleted_at' => Carbon::now()]);

        return response()->json(['success' => true]);
    }

    public function categories_delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', RecruitJobCategory::class);

        $companyId = $this->resolveActingCompanyId($request);
        foreach ($request->selectedIds as $id) {
            RecruitJobCategory::whereId($id)->where('company_id', $companyId)->update(['deleted_at' => Carbon::now()]);
        }

        return response()->json(['success' => true]);
    }

    // ======================== JOBS ========================

    public function jobs_index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', RecruitJob::class);

        // superadmin/AdminSystem: every company (2026-10-21) -- null = no company filter.
        $companyId = $this->listCompanyId($request);

        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField ?: 'id';
        $dir = $request->SortType ?: 'desc';

        $jobs = RecruitJob::with('category')
            ->withCount('applications')
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->where(function ($query) use ($request) {
                $query->when($request->filled('status'), function ($q) use ($request) {
                    $q->where('status', $request->status);
                });
                $query->when($request->filled('category_id'), function ($q) use ($request) {
                    $q->where('category_id', $request->category_id);
                });
                $query->when($request->filled('search'), function ($q) use ($request) {
                    $q->where('title', 'LIKE', "%{$request->search}%");
                });
            });

        $totalRows = $jobs->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }

        $jobs = $this->withCompanyNames($jobs->offset($offSet)->limit($perPage)->orderBy($order, $dir)->get());

        return response()->json([
            'jobs'      => $jobs,
            'totalRows' => $totalRows,
        ]);
    }

    public function jobs_all(Request $request)
    {
        return response()->json(
            RecruitJob::where('company_id', $this->resolveActingCompanyId($request))
                ->orderBy('title')->get(['id', 'title', 'status'])
        );
    }

    public function jobs_store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', RecruitJob::class);

        $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'required|string',
            'job_type'    => 'required|in:full_time,part_time,contract,internship,remote',
        ]);

        $data = $request->all();
        $data['company_id'] = $this->resolveActingCompanyId($request);
        $data['slug'] = Str::slug($request->title) . '-' . Str::random(5);
        $data['created_by'] = $request->user('api') ? $request->user('api')->id : null;

        RecruitJob::create($data);

        return response()->json(['success' => true]);
    }

    public function jobs_update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', RecruitJob::class);

        $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'required|string',
            'job_type'    => 'required|in:full_time,part_time,contract,internship,remote',
        ]);

        $job = RecruitJob::findOrFail($id);
        abort_unless((int) $job->company_id === $this->resolveActingCompanyId($request), 404);

        $data = $request->except(['company_id']);
        if ($request->filled('title') && $request->title !== $job->title) {
            $data['slug'] = Str::slug($request->title) . '-' . Str::random(5);
        }
        $job->update($data);

        return response()->json(['success' => true]);
    }

    public function jobs_destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', RecruitJob::class);

        $job = RecruitJob::findOrFail($id);
        abort_unless((int) $job->company_id === $this->resolveActingCompanyId($request), 404);
        $job->update(['deleted_at' => Carbon::now()]);

        return response()->json(['success' => true]);
    }

    public function jobs_delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', RecruitJob::class);

        $companyId = $this->resolveActingCompanyId($request);
        foreach ($request->selectedIds as $id) {
            RecruitJob::whereId($id)->where('company_id', $companyId)->update(['deleted_at' => Carbon::now()]);
        }

        return response()->json(['success' => true]);
    }

    // ======================== CANDIDATES ========================

    public function candidates_index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', RecruitCandidate::class);

        // superadmin/AdminSystem: every company (2026-10-21) -- null = no company filter.
        $companyId = $this->listCompanyId($request);

        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField ?: 'id';
        $dir = $request->SortType ?: 'desc';

        $candidates = RecruitCandidate::withCount('applications')
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->where(function ($query) use ($request) {
                $query->when($request->filled('source'), function ($q) use ($request) {
                    $q->where('source', $request->source);
                });
                $query->when($request->filled('search'), function ($q) use ($request) {
                    $s = $request->search;
                    $q->where(function ($qq) use ($s) {
                        $qq->where('first_name', 'LIKE', "%$s%")
                            ->orWhere('last_name', 'LIKE', "%$s%")
                            ->orWhere('email', 'LIKE', "%$s%");
                    });
                });
            });

        $totalRows = $candidates->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }

        $candidates = $this->withCompanyNames($candidates->offset($offSet)->limit($perPage)->orderBy($order, $dir)->get());

        return response()->json([
            'candidates' => $candidates,
            'totalRows'  => $totalRows,
        ]);
    }

    public function candidates_all(Request $request)
    {
        $candidates = RecruitCandidate::where('company_id', $this->resolveActingCompanyId($request))
            ->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'email']);

        return response()->json($candidates);
    }

    public function candidates_store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', RecruitCandidate::class);

        $companyId = $this->resolveActingCompanyId($request);

        $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name'  => 'required|string|max:255',
            'email'      => 'required|email|unique:recruit_candidates,email',
        ]);

        $data = $request->except(['resume', 'photo']);
        $data['company_id'] = $companyId;

        if ($request->hasFile('resume')) {
            $data['resume_path'] = $this->storeUpload($request->file('resume'), 'resumes', $companyId);
        }
        if ($request->hasFile('photo')) {
            $data['photo'] = $this->storeUpload($request->file('photo'), 'photos', $companyId);
        }

        RecruitCandidate::create($data);

        return response()->json(['success' => true]);
    }

    public function candidates_update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', RecruitCandidate::class);

        $companyId = $this->resolveActingCompanyId($request);

        $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name'  => 'required|string|max:255',
            'email'      => 'required|email|unique:recruit_candidates,email,' . $id,
        ]);

        $candidate = RecruitCandidate::findOrFail($id);
        abort_unless((int) $candidate->company_id === $companyId, 404);

        $data = $request->except(['resume', 'photo', 'company_id']);

        if ($request->hasFile('resume')) {
            $data['resume_path'] = $this->storeUpload($request->file('resume'), 'resumes', $companyId);
        }
        if ($request->hasFile('photo')) {
            $data['photo'] = $this->storeUpload($request->file('photo'), 'photos', $companyId);
        }

        $candidate->update($data);

        return response()->json(['success' => true]);
    }

    public function candidates_destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', RecruitCandidate::class);

        $candidate = RecruitCandidate::findOrFail($id);
        abort_unless((int) $candidate->company_id === $this->resolveActingCompanyId($request), 404);
        $candidate->update(['deleted_at' => Carbon::now()]);

        return response()->json(['success' => true]);
    }

    public function candidates_delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', RecruitCandidate::class);

        $companyId = $this->resolveActingCompanyId($request);
        foreach ($request->selectedIds as $id) {
            RecruitCandidate::whereId($id)->where('company_id', $companyId)->update(['deleted_at' => Carbon::now()]);
        }

        return response()->json(['success' => true]);
    }

    // ======================== APPLICATIONS ========================

    public function applications_index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', RecruitApplication::class);

        // superadmin/AdminSystem: every company (2026-10-21) -- null = no company filter.
        $companyId = $this->listCompanyId($request);

        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField ?: 'id';
        $dir = $request->SortType ?: 'desc';

        $apps = RecruitApplication::with(['job', 'candidate'])
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->where(function ($query) use ($request) {
                $query->when($request->filled('job_id'), function ($q) use ($request) {
                    $q->where('job_id', $request->job_id);
                });
                $query->when($request->filled('stage'), function ($q) use ($request) {
                    $q->where('stage', $request->stage);
                });
                $query->when($request->filled('search'), function ($q) use ($request) {
                    $s = $request->search;
                    $q->whereHas('candidate', function ($qq) use ($s) {
                        $qq->where('first_name', 'LIKE', "%$s%")
                            ->orWhere('last_name', 'LIKE', "%$s%")
                            ->orWhere('email', 'LIKE', "%$s%");
                    });
                });
            });

        $totalRows = $apps->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }

        $apps = $this->withCompanyNames($apps->offset($offSet)->limit($perPage)->orderBy($order, $dir)->get());

        return response()->json([
            'applications' => $apps,
            'totalRows'    => $totalRows,
        ]);
    }

    public function applications_all(Request $request)
    {
        $apps = RecruitApplication::with(['job:id,title', 'candidate:id,first_name,last_name'])
            ->where('company_id', $this->resolveActingCompanyId($request))
            ->orderBy('id', 'desc')
            ->get();

        return response()->json($apps);
    }

    public function applications_store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', RecruitApplication::class);

        $request->validate([
            'job_id'       => 'required|exists:recruit_jobs,id',
            'candidate_id' => 'required|exists:recruit_candidates,id',
        ]);

        $data = $request->all();
        $data['company_id'] = $this->resolveActingCompanyId($request);
        $data['applied_date'] = $request->applied_date ?: now()->toDateString();

        RecruitApplication::create($data);

        return response()->json(['success' => true]);
    }

    public function applications_update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', RecruitApplication::class);

        $app = RecruitApplication::findOrFail($id);
        abort_unless((int) $app->company_id === $this->resolveActingCompanyId($request), 404);
        $app->update($request->except(['company_id']));

        return response()->json(['success' => true]);
    }

    public function applications_update_stage(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', RecruitApplication::class);

        $request->validate(['stage' => 'required|in:applied,screening,shortlisted,interview,offered,hired,rejected']);

        $app = RecruitApplication::findOrFail($id);
        abort_unless((int) $app->company_id === $this->resolveActingCompanyId($request), 404);

        $app->update([
            'stage'       => $request->stage,
            'reviewed_by' => $request->user('api') ? $request->user('api')->id : null,
            'reviewed_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    public function applications_destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', RecruitApplication::class);

        $app = RecruitApplication::findOrFail($id);
        abort_unless((int) $app->company_id === $this->resolveActingCompanyId($request), 404);
        $app->update(['deleted_at' => Carbon::now()]);

        return response()->json(['success' => true]);
    }

    public function applications_delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', RecruitApplication::class);

        $companyId = $this->resolveActingCompanyId($request);
        foreach ($request->selectedIds as $id) {
            RecruitApplication::whereId($id)->where('company_id', $companyId)->update(['deleted_at' => Carbon::now()]);
        }

        return response()->json(['success' => true]);
    }

    // ======================== INTERVIEWS ========================

    public function interviews_index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', RecruitInterview::class);

        // superadmin/AdminSystem: every company (2026-10-21) -- null = no company filter.
        $companyId = $this->listCompanyId($request);

        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField ?: 'id';
        $dir = $request->SortType ?: 'desc';

        $interviews = RecruitInterview::with(['application.candidate', 'application.job'])
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->where(function ($query) use ($request) {
                $query->when($request->filled('status'), function ($q) use ($request) {
                    $q->where('status', $request->status);
                });
                $query->when($request->filled('application_id'), function ($q) use ($request) {
                    $q->where('application_id', $request->application_id);
                });
            });

        $totalRows = $interviews->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }

        $interviews = $this->withCompanyNames($interviews->offset($offSet)->limit($perPage)->orderBy($order, $dir)->get());

        return response()->json([
            'interviews' => $interviews,
            'totalRows'  => $totalRows,
        ]);
    }

    public function interviews_store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', RecruitInterview::class);

        $request->validate([
            'application_id' => 'required|exists:recruit_applications,id',
            'scheduled_at'   => 'required|date',
            'type'           => 'required|in:phone,video,in_person,technical,panel,group',
        ]);

        $data = $request->all();
        $data['company_id'] = $this->resolveActingCompanyId($request);

        RecruitInterview::create($data);

        // Auto-advance the application to the interview stage.
        $app = RecruitApplication::find($request->application_id);
        if ($app && in_array($app->stage, ['applied', 'screening', 'shortlisted'])) {
            $app->update(['stage' => 'interview']);
        }

        return response()->json(['success' => true]);
    }

    public function interviews_update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', RecruitInterview::class);

        $request->validate([
            'scheduled_at' => 'required|date',
            'type'         => 'required|in:phone,video,in_person,technical,panel,group',
        ]);

        $interview = RecruitInterview::findOrFail($id);
        abort_unless((int) $interview->company_id === $this->resolveActingCompanyId($request), 404);

        $interview->update($request->only([
            'application_id', 'type', 'scheduled_at', 'duration_minutes', 'location',
            'meeting_link', 'interviewer_id', 'status', 'rating', 'feedback', 'notes',
        ]));

        return response()->json(['success' => true]);
    }

    public function interviews_destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', RecruitInterview::class);

        $interview = RecruitInterview::findOrFail($id);
        abort_unless((int) $interview->company_id === $this->resolveActingCompanyId($request), 404);
        $interview->update(['deleted_at' => Carbon::now()]);

        return response()->json(['success' => true]);
    }

    public function interviews_delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', RecruitInterview::class);

        $companyId = $this->resolveActingCompanyId($request);
        foreach ($request->selectedIds as $id) {
            RecruitInterview::whereId($id)->where('company_id', $companyId)->update(['deleted_at' => Carbon::now()]);
        }

        return response()->json(['success' => true]);
    }

    // ======================== REPORTS ========================

    public function reports(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', RecruitJob::class);

        // superadmin/AdminSystem: every company (2026-10-21) -- null = no company filter.
        $companyId = $this->listCompanyId($request);

        $period = $request->get('period', '6months');
        $dateFrom = match ($period) {
            '1month'  => now()->subMonth(),
            '3months' => now()->subMonths(3),
            '1year'   => now()->subYear(),
            default   => now()->subMonths(6),
        };

        $funnel = RecruitApplication::select('stage', DB::raw('count(*) as count'))
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->where('created_at', '>=', $dateFrom)
            ->groupBy('stage')
            ->pluck('count', 'stage');

        $byJob = RecruitApplication::select('job_id', DB::raw('count(*) as count'))
            ->with('job:id,title')
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->where('created_at', '>=', $dateFrom)
            ->groupBy('job_id')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        $bySource = RecruitCandidate::select('source', DB::raw('count(*) as count'))
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->where('created_at', '>=', $dateFrom)
            ->groupBy('source')
            ->pluck('count', 'source');

        $monthlyTrend = RecruitApplication::select(
                DB::raw('DATE_FORMAT(applied_date, "%Y-%m") as month'),
                DB::raw('count(*) as total'),
                DB::raw('SUM(CASE WHEN stage = "hired" THEN 1 ELSE 0 END) as hired'),
                DB::raw('SUM(CASE WHEN stage = "rejected" THEN 1 ELSE 0 END) as rejected')
            )
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->where('applied_date', '>=', $dateFrom)
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $avgTimeToHire = RecruitApplication::when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->where('stage', 'hired')
            ->where('created_at', '>=', $dateFrom)
            ->selectRaw('AVG(DATEDIFF(reviewed_at, applied_date)) as avg_days')
            ->value('avg_days');

        $byCategory = RecruitJob::select('category_id', DB::raw('count(*) as count'))
            ->with('category:id,name')
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->where('created_at', '>=', $dateFrom)
            ->whereNotNull('category_id')
            ->groupBy('category_id')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        return response()->json([
            'funnel'           => $funnel,
            'by_job'           => $byJob,
            'by_source'        => $bySource,
            'monthly_trend'    => $monthlyTrend,
            'avg_time_to_hire' => round($avgTimeToHire ?? 0, 1),
            'by_category'      => $byCategory,
        ]);
    }
}
