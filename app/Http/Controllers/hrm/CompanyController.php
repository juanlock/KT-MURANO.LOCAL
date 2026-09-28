<?php

namespace App\Http\Controllers\hrm;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\CompanyProvisioner;
use App\Services\DemoData\DemoRunner;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * HRM -> Companies. Multicompany rule: a SuperAdmin manages every company
 * (create/delete are SuperAdmin-only); an Admin only sees and edits its own.
 */
class CompanyController extends Controller
{
    private function isSuperAdmin(Request $request): bool
    {
        return optional($request->user('api'))->type === 'SuperAdmin';
    }

    // ----------- GET ALL  company --------------\\

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Company::class);

        // How many items do you want to display.
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = in_array($request->SortField, ['id', 'name', 'email', 'phone', 'country', 'created_at'], true) ? $request->SortField : 'id';
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';

        $companies = Company::where('deleted_at', '=', null)
            // Admins only ever see their own company.
            ->when(! $this->isSuperAdmin($request), fn ($q) => $q->where('id', $request->user('api')->company_id))
        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('name', 'LIKE', "%{$request->search}%")
                        ->orWhere('phone', 'LIKE', "%{$request->search}%")
                        ->orWhere('country', 'LIKE', "%{$request->search}%")
                        ->orWhere('email', 'LIKE', "%{$request->search}%");
                });
            });
        $totalRows = $companies->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }
        $companies = $companies->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        // Demo rows per company (for the list's Demo data column / Clear).
        $demoCounts = DB::table('demo_records')->whereIn('company_id', $companies->pluck('id'))
            ->select('company_id', DB::raw('count(*) as c'))->groupBy('company_id')->pluck('c', 'company_id');
        $companies->each(fn ($c) => $c->demo_count = (int) ($demoCounts[$c->id] ?? 0));

        // Website mode: imported (Home page in the original design) or the
        // default online store.
        $imported = \App\Models\StoreSetting::whereIn('company_id', $companies->pluck('id'))
            ->where('front_page_mode', 'imported')->pluck('company_id')->flip();
        $companies->each(fn ($c) => $c->website_mode = isset($imported[$c->id]) ? 'import' : 'default');

        return response()->json([
            'companies' => $companies,
            'totalRows' => $totalRows,
        ]);
    }

    // ----------- Store new Company --------------\\

    /**
     * Creates a ready-to-use company (SuperAdmin only): the company + its
     * roles, settings, main warehouse, Walk-in Customer, HR basics, its admin
     * user and that user's employee record (CompanyProvisioner). Optionally
     * fills it with demo data afterwards (tagged; removable any time).
     */
    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Company::class);
        abort_unless($this->isSuperAdmin($request), 403, 'Only a SuperAdmin can create companies.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:50'],
            'country' => ['nullable', 'string', 'max:100'],
            'admin_firstname' => ['required', 'string', 'max:100'],
            'admin_lastname' => ['nullable', 'string', 'max:100'],
            'admin_email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->whereNull('deleted_at')],
            'admin_password' => ['required', 'string', 'min:8'],
            'admin_role' => ['nullable', Rule::in(['Owner', 'Admin', 'Admin-ALL'])],
            'demo_data' => ['nullable', 'boolean'],
            // Website: import the company's existing site (original design)
            // or use the default online store + its standard pages.
            'website_mode' => ['nullable', Rule::in(['import', 'default'])],
            'website_url' => ['nullable', 'required_if:website_mode,import', 'url', 'max:255'],
        ], [
            'admin_email.unique' => 'This admin email is already used by another user.',
        ]);

        $result = CompanyProvisioner::create(
            ['name' => $data['name'], 'email' => $data['email'] ?? null, 'phone' => $data['phone'] ?? null, 'country' => $data['country'] ?? null],
            [
                'firstname' => $data['admin_firstname'], 'lastname' => $data['admin_lastname'] ?? null,
                'email' => $data['admin_email'], 'password' => $data['admin_password'],
                'role' => $data['admin_role'] ?? 'Admin-ALL',
            ]
        );

        if ($request->filled('domains')) {
            $result['company']->update(['domains' => $this->validatedDomains((string) $request['domains'], (int) $result['company']->id)]);
            \App\Support\CompanyDomain::forget();
        }

        // Website (outside the provisioning transaction, like demo data).
        $website = $this->applyWebsite((int) $result['company']->id, $data['website_mode'] ?? 'default', $data['website_url'] ?? null);

        $demo = null;
        if (! empty($data['demo_data'])) {
            // Outside the provisioning transaction: a failing demo module is
            // reported but never undoes the company itself.
            $demo = DemoRunner::run((int) $result['company']->id, (int) $result['user']->id);
        }

        return response()->json([
            'success' => true,
            'company_id' => $result['company']->id,
            'admin_user_id' => $result['user']->id,
            'admin_username' => $result['user']->username,
            'website' => $website,
            'demo' => $demo,
        ]);
    }

    /**
     * POST company/{id}/website {mode: import|default, url?} -- switch a
     * company's website (SuperAdmin): import its existing site as the Home
     * page (original design), or use the default online store + pages.
     */
    public function website(Request $request, $id)
    {
        abort_unless($this->isSuperAdmin($request), 403, 'Only a SuperAdmin can change a company\'s website.');
        abort_unless(Company::whereKey($id)->whereNull('deleted_at')->exists(), 404);
        $data = $request->validate([
            'mode' => ['required', Rule::in(['import', 'default'])],
            'url' => ['nullable', 'required_if:mode,import', 'url', 'max:255'],
        ]);

        return response()->json(['success' => true, 'website' => $this->applyWebsite((int) $id, $data['mode'], $data['url'] ?? null)]);
    }

    /**
     * import: SiteImporter::fromUrl() -> Home page in the original design.
     *         If the import fails, falls back to the default store (reported).
     * default: the default online store -- the imported Home (if any) is
     *         switched to the store's own design and the standard pages are
     *         created (existing pages are kept).
     */
    private function applyWebsite(int $companyId, string $mode, ?string $url): array
    {
        if ($mode === 'import' && $url) {
            @set_time_limit(600);
            try {
                $slug = \Illuminate\Support\Str::slug(Company::whereKey($companyId)->value('name') ?: 'site') ?: 'site';
                $r = \App\Services\SiteImporter::fromUrl($companyId, $url, $slug);
                if (($r['pages'] ?? 0) > 0) {
                    // Store Settings -> Front page = the imported page (+ store bar).
                    $entry = \App\Models\StorePage::where('company_id', $companyId)->where('slug', 'home')->value('site_path');
                    \App\Services\FrontPage::setImported($companyId, $url, $entry ?: $r['path'].'/index.html');

                    return ['mode' => 'import', 'status' => 'imported', 'pages' => $r['pages'], 'assets' => $r['assets']];
                }
                $error = 'No page could be read from '.$url;
            } catch (\Throwable $e) {
                report($e);
                $error = $e->getMessage();
            }
            $created = \App\Services\StorePageDefaults::ensure($companyId);
            \App\Services\FrontPage::setSections($companyId);

            return ['mode' => 'default', 'status' => 'import_failed', 'error' => $error, 'pages_created' => $created];
        }

        \App\Models\StorePage::where('company_id', $companyId)->where('slug', 'home')->update(['use_site_design' => false]);
        \App\Services\FrontPage::setSections($companyId);

        return ['mode' => 'default', 'status' => 'default', 'pages_created' => \App\Services\StorePageDefaults::ensure($companyId)];
    }

    // ------------ function show -----------\\

    public function show($id)
    {
        //

    }

    // -----------Update Company --------------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Company::class);
        // Admins may only edit their own company.
        abort_unless($this->isSuperAdmin($request) || (int) $id === (int) $request->user('api')->company_id, 403);

        request()->validate([
            'name' => 'required|string',
        ]);

        $update = [
            'name' => $request['name'],
            'email' => $request['email'],
            'phone' => $request['phone'],
            'country' => $request['country'],
        ];
        // Website domain(s): SuperAdmin only (it decides which company a
        // public domain serves).
        if ($this->isSuperAdmin($request) && $request->has('domains')) {
            $update['domains'] = $this->validatedDomains((string) $request['domains'], (int) $id);
        }

        Company::whereId($id)->update($update);
        \App\Support\CompanyDomain::forget();

        return response()->json(['success' => true]);
    }

    /**
     * "www.a.com, b.com" -> "a.com, b.com" (normalized, de-duplicated), or
     * null when empty. 422 on a malformed domain or one another company has.
     */
    private function validatedDomains(string $raw, int $companyId): ?string
    {
        $domains = collect(explode(',', $raw))
            ->map(fn ($d) => \App\Support\CompanyDomain::normalize($d))
            ->filter()->unique()->values();

        foreach ($domains as $d) {
            if (! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $d)) {
                abort(response()->json(['message' => "Invalid domain: $d", 'errors' => ['domains' => ["Invalid domain: $d"]]], 422));
            }
            $owner = \App\Support\CompanyDomain::companyIdForHost($d);
            if ($owner && $owner !== $companyId) {
                abort(response()->json(['message' => "$d is already used by another company.", 'errors' => ['domains' => ["$d is already used by another company."]]], 422));
            }
        }

        return $domains->isEmpty() ? null : $domains->implode(', ');
    }

    // ----------- Delete  company --------------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Company::class);
        abort_unless($this->isSuperAdmin($request), 403, 'Only a SuperAdmin can delete companies.');

        Company::whereId($id)->update([
            'deleted_at' => Carbon::now(),
        ]);

        return response()->json(['success' => true]);
    }

    // -------------- Delete by selection  ---------------\\

    public function delete_by_selection(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'delete', Company::class);
        abort_unless($this->isSuperAdmin($request), 403, 'Only a SuperAdmin can delete companies.');

        $selectedIds = $request->selectedIds;
        foreach ($selectedIds as $company_id) {
            Company::whereId($company_id)->update([
                'deleted_at' => Carbon::now(),
            ]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * POST company/{id}/clear-demo -- remove every demo row generated for this
     * company (SuperAdmin only). Real data is never touched.
     */
    public function clearDemo(Request $request, $id)
    {
        abort_unless($this->isSuperAdmin($request), 403, 'Only a SuperAdmin can clear a company\'s demo data.');
        try {
            $deleted = DemoRunner::reset((int) $id, (int) $request->user('api')->id);
        } catch (\Illuminate\Database\QueryException $e) {
            report($e);

            return response()->json(['message' => 'Some demo records are referenced by real data; remove those references first.'], 409);
        }

        return response()->json(['success' => true, 'deleted' => $deleted]);
    }

    // ----------- GET ALL  Company --------------\\

    public function Get_all_Company(Request $request)
    {
        $companies = Company::where('deleted_at', '=', null)
            ->when(optional($request->user('api'))->type !== 'SuperAdmin',
                fn ($q) => $q->where('id', optional($request->user('api'))->company_id))
            ->orderBy('id', 'desc')
            ->get(['id', 'name']);

        return response()->json($companies);
    }
}
