<?php

namespace App\Http\Controllers\hrm;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\CompanyProvisioner;
use App\Services\DemoData\DemoRunner;
use App\Support\CompanyProfile;
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

    /**
     * "superadmin/AdminSystem" (user's rule, 2026-10-21): the SuperAdmin with
     * AdminSystem (999) -- or nothing -- picked in the company switcher. Only
     * then the Companies list shows every company and companies can be created
     * or deleted; with a company picked the SuperAdmin works on that one only.
     */
    private function isSystemContext(Request $request): bool
    {
        $acting = (int) $request->input('company_id');

        return $this->isSuperAdmin($request) && (! $acting || $acting === \App\Models\Setting::TEMPLATE_COMPANY_ID);
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
            // Admins only ever see their own company; a SuperAdmin with a company
            // picked in the switcher sees that one; every company only as AdminSystem.
            ->when(! $this->isSuperAdmin($request), fn ($q) => $q->where('id', $request->user('api')->company_id))
            ->when($this->isSuperAdmin($request) && ! $this->isSystemContext($request), fn ($q) => $q->where('id', (int) $request->input('company_id')))
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
        // Logo column: the company's logo from its Settings (files/company_{id}/... ; older ones in images/).
        $logos = DB::table('settings')->whereIn('company_id', $companies->pluck('id'))->whereNull('deleted_at')->pluck('logo', 'company_id');
        $companies->each(fn ($c) => $c->logo_url = $this->logoUrl($logos[$c->id] ?? null));
        // Required information still missing (CompanyProfile::REQUIRED) -> "Setup" column.
        $companies->each(fn ($c) => $c->profile_missing = CompanyProfile::missing((int) $c->id));

        return response()->json([
            'companies' => $companies,
            'totalRows' => $totalRows,
            'options' => CompanyProfile::options(),
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
        abort_unless($this->isSystemContext($request), 403, 'Only a SuperAdmin working as AdminSystem can create companies (pick AdminSystem in the company switcher).');

        // Company profile (owner, business type, address, helpdesk email, defaults) + admin / helpdesk users.
        $data = $request->validate(CompanyProfile::rules() + [
            'admin_firstname' => ['required', 'string', 'max:100'],
            'admin_lastname' => ['nullable', 'string', 'max:100'],
            'admin_email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->whereNull('deleted_at')],
            // Empty = the standard password of new companies (CompanyLogins, 2026-10-25).
            'admin_password' => ['nullable', 'string', 'min:8'],
            'admin_role' => ['nullable', Rule::in(['Owner', 'Admin', 'Admin-ALL'])],
            // Optional helpdesk agent (role "Helpdesk").
            'helpdesk_firstname' => ['nullable', 'string', 'max:100'],
            'helpdesk_lastname' => ['nullable', 'string', 'max:100'],
            // (helpdesk_email = the company's support mailbox, in the profile;
            // helpdesk_login_email = this agent's own login)
            'helpdesk_login_email' => ['nullable', 'email', 'max:190', 'different:admin_email', Rule::unique('users', 'email')->whereNull('deleted_at')],
            'helpdesk_password' => ['nullable', 'string', 'min:8'],
            'demo_data' => ['nullable', 'boolean'],
            // Website: import the company's existing site (original design)
            // or use the default online store + its standard pages.
            'website_mode' => ['nullable', Rule::in(['import', 'default'])],
            'website_url' => ['nullable', 'required_if:website_mode,import', 'url', 'max:255'],
            // Business Modules the company starts with ({key: bool}, every switchable
            // module; pre-ticked from the business type, BusinessTypes::modules()).
            'module_flags' => ['required', 'array'],
            'module_flags.*' => ['boolean'],
            // The company logo, cropped / resized in the browser (data URL).
            'logo_data' => ['required', 'string'],
        ], [
            'logo_data.required' => 'Upload the company logo (step Company).',
            'module_flags.required' => 'Choose the company\'s modules (step Modules).',
            'admin_email.unique' => 'This admin email is already used by another user.',
            'helpdesk_login_email.unique' => 'This helpdesk login email is already used by another user.',
            'helpdesk_login_email.different' => 'The helpdesk user needs its own login email (not the admin one).',
        ] + CompanyProfile::messages());
        $logoBytes = $this->decodeLogo($data['logo_data']); // 422 before anything is created

        // Company + users + the rest of the profile: one transaction (all or nothing).
        $result = DB::transaction(fn () => tap(CompanyProvisioner::create(
            ['name' => $data['name'], 'email' => $data['email'] ?? null, 'phone' => $data['phone'] ?? null, 'country' => $data['country'] ?? null,
                'default_language' => $data['default_language'] ?? null],
            [
                'firstname' => $data['admin_firstname'], 'lastname' => $data['admin_lastname'] ?? null,
                'email' => $data['admin_email'], 'password' => ($data['admin_password'] ?? null) ?: \App\Support\CompanyLogins::ACCESS_PASSWORD,
                'role' => $data['admin_role'] ?? 'Admin-ALL',
            ],
            ! empty($data['helpdesk_login_email']) ? [
                'firstname' => $data['helpdesk_firstname'] ?? null, 'lastname' => $data['helpdesk_lastname'] ?? null,
                'email' => $data['helpdesk_login_email'], 'password' => ($data['helpdesk_password'] ?? null) ?: \App\Support\CompanyLogins::ACCESS_PASSWORD,
            ] : null
        ), function ($r) use ($data) {
            $cid = (int) $r['company']->id;
            CompanyProfile::save($cid, array_intersect_key($data, CompanyProfile::rules()));
            // Its modules (replaces the template's flags cloned with the settings row).
            $flags = [];
            foreach ($data['module_flags'] as $key => $on) {
                if (is_string($key) && preg_match('/^[A-Za-z_]{1,60}$/', $key)) {
                    $flags[$key] = (bool) $on;
                }
            }
            DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')
                ->update(['module_flags' => json_encode($flags), 'updated_at' => now()]);
            // Its business type is saved now: a hospital / school gets its module menu first.
            \App\Support\BusinessTypes::promoteMenu($cid);
            // Its roles: no SuperAdmin-only settings (PayPal, ZATCA, currencies...) unless its module is on.
            \App\Support\SettingsAccess::trimRoles($cid);
            // A school: Rector, Secretaria, Profesor, Padre de familia, Alumno.
            if (\App\Support\BusinessTypes::of($cid) === 'school') {
                \App\Support\SchoolRoles::ensure($cid);
            }
            // A hospital: Emergencia, Admisión, Médico, Enfermero, Jefe de enfermería, Farmacia... (2026-10-21).
            if (\App\Support\BusinessTypes::of($cid) === 'hospital') {
                \App\Support\HospitalRoles::apply($cid);
            }
            // A specialist clinic: Recepción, Enfermería, Médico especialista, Caja (2026-10-26).
            if (\App\Support\BusinessTypes::of($cid) === 'health_specialist') {
                \App\Support\HospitalRoles::apply($cid, \App\Support\SpecialistRoles::ROLES);
            }
            // Its mail server: AdminSystem's, sender "Admin {company}".
            \App\Models\Server::forCompany($cid);
            // Standard logins (2026-10-25): default passwords per type where none was typed,
            // and the admin / helpdesk / info access logins with their roles.
            \App\Support\CompanyLogins::applyDefaults($cid);
            \App\Support\CompanyLogins::ensureAccess($cid);
        }));
        $this->storeLogo((int) $result['company']->id, $logoBytes);

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
            'helpdesk_user_id' => $result['helpdesk_user']?->id,
            'helpdesk_username' => $result['helpdesk_user']?->username,
            'helpdesk_email' => $result['helpdesk_user']?->email,
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

    /** GET company/{id} -> {profile, options}: the Edit form (an Admin only for their own company). */
    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Company::class);
        abort_unless($this->isSuperAdmin($request) || (int) $id === (int) $request->user('api')->company_id, 403);
        abort_unless(Company::whereKey($id)->whereNull('deleted_at')->exists(), 404);

        return response()->json([
            'profile' => CompanyProfile::get((int) $id) + [
                'domains' => Company::whereKey($id)->value('domains'),
                'logo_url' => $this->logoUrl(DB::table('settings')->where('company_id', $id)->whereNull('deleted_at')->value('logo')),
            ],
            'missing' => CompanyProfile::missing((int) $id),
            'options' => CompanyProfile::options(),
        ]);
    }

    // -----------Update Company --------------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Company::class);
        // Admins may only edit their own company.
        abort_unless($this->isSuperAdmin($request) || (int) $id === (int) $request->user('api')->company_id, 403);

        // The whole company profile (CompanyProfile: companies + settings + helpdesk), one transaction.
        $data = $request->validate(CompanyProfile::rules(), CompanyProfile::messages());
        // Website domain(s): SuperAdmin only (it decides which company a
        // public domain serves). Checked before anything is saved.
        $domains = $this->isSuperAdmin($request) && $request->has('domains')
            ? ['domains' => $this->validatedDomains((string) $request['domains'], (int) $id)] : null;
        // A new logo (optional on Edit), checked before anything is saved.
        $logoBytes = $this->decodeLogo($request->input('logo_data'));

        CompanyProfile::save((int) $id, $data);
        if ($logoBytes !== null) {
            $this->storeLogo((int) $id, $logoBytes);
        }
        if ($domains) {
            Company::whereId($id)->update($domains);
            \App\Support\CompanyDomain::forget();
        }

        return response()->json(['success' => true, 'missing' => CompanyProfile::missing((int) $id)]);
    }

    /**
     * The logo cropped / resized in the browser (LogoCropper.vue) arrives as a
     * data URL: decoded and checked BEFORE anything is saved (422 if it is not
     * a PNG / JPEG / WebP image of at most 4 MB).
     */
    private function decodeLogo(?string $dataUrl): ?string
    {
        if ($dataUrl === null || $dataUrl === '') {
            return null;
        }
        $fail = fn () => throw \Illuminate\Validation\ValidationException::withMessages(['logo_data' => 'The logo must be a PNG, JPG or WebP image of at most 4 MB.']);
        if (! preg_match('#^data:image/(png|jpeg|webp);base64,#', $dataUrl)) {
            $fail();
        }
        $bytes = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1), true);
        if ($bytes === false || strlen($bytes) > 4 * 1024 * 1024 || ! @getimagesizefromstring($bytes)) {
            $fail();
        }

        return $bytes;
    }

    /**
     * Saves the logo as the company's (settings.logo) in files/company_{id}/logos/.
     * The previous one is deleted only when it is this company's own file
     * (a new company's settings start with the template company's logo path).
     */
    private function storeLogo(int $companyId, string $bytes): string
    {
        $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][getimagesizefromstring($bytes)['mime'] ?? ''] ?? 'png';
        $path = \App\Support\CompanyFiles::putBytes($companyId, 'logos', 'logo.'.$ext, $bytes);
        $old = (string) DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->value('logo');
        DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->update(['logo' => $path, 'updated_at' => now()]);
        if ($old !== '' && $old !== $path && str_starts_with($old, "files/company_{$companyId}/") && is_file(public_path($old))) {
            @unlink(public_path($old));
        }

        return $path;
    }

    /** "/files/company_2/logos/x.png" for the browser (older bare names live in images/). */
    private function logoUrl(?string $p): ?string
    {
        $p = trim((string) $p);

        return $p === '' ? null
            : '/'.implode('/', array_map('rawurlencode', explode('/', str_contains($p, '/') ? ltrim($p, '/') : 'images/'.$p)));
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
        abort_unless($this->isSystemContext($request), 403, 'Only a SuperAdmin working as AdminSystem can delete companies (pick AdminSystem in the company switcher).');

        Company::whereId($id)->update([
            'deleted_at' => Carbon::now(),
        ]);

        return response()->json(['success' => true]);
    }

    // -------------- Delete by selection  ---------------\\

    public function delete_by_selection(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'delete', Company::class);
        abort_unless($this->isSystemContext($request), 403, 'Only a SuperAdmin working as AdminSystem can delete companies (pick AdminSystem in the company switcher).');

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

            return response()->json(['message' => $this->demoBlockedMessage($e)], 409);
        }

        return response()->json(['success' => true, 'deleted' => $deleted]);
    }

    /**
     * Clear demo stopped by a foreign key (nothing was deleted -- it runs in one
     * transaction): say which demo record is still used, and by which real
     * record(s), e.g. "Demo clients #348 (Nadia Johnson) is still used by sales
     * SL_0007, which is not demo data."
     */
    private function demoBlockedMessage(\Illuminate\Database\QueryException $e): string
    {
        $msg = $e->getMessage();
        $generic = 'Nothing was removed: some demo records are still used by real (non-demo) data. Remove or change those records first.';
        if (! preg_match('/`[^`]+`\.`([^`]+)`, CONSTRAINT `[^`]+` FOREIGN KEY \(`([^`]+)`\) REFERENCES `([^`]+)`/', $msg, $fk)
            || ! preg_match('/delete from `([^`]+)` where `id` = (\d+)/', $msg, $del)) {
            return $generic;
        }
        [, $child, $column, $parent] = $fk;
        $id = (int) $del[2];
        try {
            $name = \Illuminate\Support\Facades\Schema::hasColumn($parent, 'name')
                ? \Illuminate\Support\Facades\DB::table($parent)->where('id', $id)->value('name') : null;
            $label = \Illuminate\Support\Facades\Schema::hasColumn($child, 'Ref') ? 'Ref' : 'id';
            $users = \Illuminate\Support\Facades\DB::table($child)->where($column, $id)->limit(5)->pluck($label)->all();
        } catch (\Throwable $x) {
            return $generic;
        }

        return 'Nothing was removed. Demo '.$parent.' #'.$id.($name ? ' ('.$name.')' : '')
            .' is still used by '.$child.' '.($users ? implode(', ', $users) : '').', which is not demo data. '
            .'Delete or change '.(count($users) > 1 ? 'them' : 'it').' first, then clear the demo again.';
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
