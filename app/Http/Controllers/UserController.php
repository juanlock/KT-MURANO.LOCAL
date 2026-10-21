<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Client;
use App\Models\Company;
use App\Models\product_warehouse;
use App\Models\Provider;
use App\Models\Role;
use App\Models\role_user;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use App\utils\helpers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Intervention\Image\ImageManagerStatic as Image;

class UserController extends BaseController
{
    use ScopesToCompany;

    // ------------- GET ALL USERS---------\\

    public function index(request $request)
    {

        $this->authorizeForUser($request->user('api'), 'view', User::class);
        // How many items do you want to display.
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = $request->SortType;
        $helpers = new helpers;
        // Filter fields With Params to retrieve
        $columns = [0 => 'username', 1 => 'statut', 2 => 'phone', 3 => 'email'];
        $param = [0 => 'like', 1 => '=', 2 => 'like', 3 => 'like'];
        $data = [];

        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $ShowRecord = $user->hasRecordView();

        // Company boundary: a SuperAdmin sees every company (optionally
        // narrowed via ?company_id= from a future company picker); anyone
        // else only ever sees their own company's users, regardless of
        // record_view.
        $actingCompanyId = $this->resolveActingCompanyId($request);
        // A SuperAdmin opening Users from a role (Group Permissions -> Users
        // column, possibly another company's role): that role's company.
        if ($user->type === 'SuperAdmin' && ctype_digit((string) $request->filter_role)) {
            $actingCompanyId = Role::whereKey((int) $request->filter_role)->value('company_id') ?: $actingCompanyId;
        }
        $users = User::where('deleted_at', '=', null)
            ->when($user->type !== 'SuperAdmin' || $request->filled('company_id'), function ($query) use ($actingCompanyId) {
                return $query->where('company_id', $actingCompanyId);
            })
            ->where(function ($query) use ($ShowRecord) {
            if (! $ShowRecord) {
                return $query->where('id', '=', Auth::user()->id);
            }
        });

        // Users list filters (2026-10-12): type, role (role_user; "none" = no
        // role), status (1 active / 0 disabled).
        $users->when($request->filled('filter_type'), fn ($q) => $q->where('type', $request->filter_type))
            ->when($request->filled('filter_role'), function ($q) use ($request) {
                return $request->filter_role === 'none'
                    ? $q->whereDoesntHave('roles')
                    : $q->whereHas('roles', fn ($r) => $r->where('roles.id', (int) $request->filter_role));
            })
            ->when($request->filled('filter_status'), fn ($q) => $q->where('statut', (int) $request->filter_status ? 1 : 0));

        // Multiple Filter
        $Filtred = $helpers->filter($users, $columns, $param, $request)
        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('username', 'LIKE', "%{$request->search}%")
                        ->orWhere('firstname', 'LIKE', "%{$request->search}%")
                        ->orWhere('lastname', 'LIKE', "%{$request->search}%")
                        ->orWhere('email', 'LIKE', "%{$request->search}%")
                        ->orWhere('phone', 'LIKE', "%{$request->search}%");
                });
            });
        $totalRows = $Filtred->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }
        $users = $Filtred->with('roles:id,name')
            ->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get()
            // The user's role (role_user), shown in the list's Role column.
            ->each(function ($u) {
                $u->role_name = $u->roles->first()?->name;
                $u->unsetRelation('roles');
            });

        // Roles are per-company copies (Owner/Admin/... exist once per
        // company) -- only offer the acting company's own, i.e. the company
        // store() will create the new user in. Unscoped, every role showed
        // up once per company in the Create User dropdown.
        $roles = Role::where('deleted_at', null)->where('company_id', $actingCompanyId)->orderBy('name')->get(['id', 'name']);

        // Only a SuperAdmin gets every company's warehouses (needed so the
        // Create User form could offer choices across companies) -- everyone
        // else only ever sees their own company's warehouses.
        $warehouses = $user->type === 'SuperAdmin'
            ? Warehouse::where('deleted_at', '=', null)->get(['id', 'name', 'company_id'])
            : Warehouse::where('deleted_at', '=', null)->where('company_id', $user->company_id)->get(['id', 'name', 'company_id']);

        $companies = Company::where('deleted_at', null)->get(['id', 'name']);

        return response()->json([
            'users' => $users,
            'roles' => $roles,
            'warehouses' => $warehouses,
            'companies' => $companies,
            'totalRows' => $totalRows,
        ]);
    }

    // ------------- GET USER Auth ---------\\

    public function GetUserAuth(Request $request)
    {
        $helpers = new Helpers;
        $user = Auth::user();
        $settings = Setting::forCompany($this->resolveActingCompanyId($request));

        $userData = [
            'id' => $user->id,
            'avatar' => $user->avatar,
            'username' => $user->username,
            // Read by the frontend to gate SuperAdmin-only UI (e.g. the
            // company picker + full CRUD on Manage Menu) -- previously never
            // exposed to the SPA at all.
            'type' => $user->type,
            'company_id' => $user->company_id,
            'currency' => $helpers->Get_Currency(),
            // Multi-Currency module: master toggle + the base currency id, read
            // by document forms/POS to show a currency picker (default off)
            'enable_multi_currency' => (bool) ($settings->enable_multi_currency ?? false),
            'default_currency_id' => $settings->currency_id ?? null,
            // System Settings -> Defaults -> Default Warehouse: pre-selects
            // the warehouse on new documents (Sales, etc.) instead of
            // leaving the picker blank every time.
            'default_warehouse_id' => $settings->warehouse_id ?? null,
            // System Settings -> Defaults -> Default Tax: pre-fills a new
            // product's own tax rate instead of always starting at 0, so
            // sale/purchase lines for that product carry the company's
            // usual rate without retyping it every time.
            'default_tax' => $settings->default_tax ?? 0,
            'logo' => $settings->logo ?? null,
            'default_language' => $settings->default_language ?? 'en',
            'show_language' => $settings->show_language ?? false,
            'footer' => $settings->footer ?? '',
            'developed_by' => $settings->developed_by ?? '',
            // kt_logs.version: the running "MM.mm" track-version number that
            // bumps by one on every logged request (Protocol Kelsy), shown in
            // the footer for every user (all companies + SuperAdmin), not
            // company-scoped -- kt_logs tracks the whole install, not one company.
            'kt_log_version' => \App\Models\KtLog::orderByDesc('id')->value('version'),
            // A SuperAdmin working as this admin (login chooser, 2026-10-20): the
            // header shows "Back to SuperAdmin" (POST /login/back).
            'superadmin_switch' => ($sa = \App\Support\SuperAdminSwitch::superAdminFor($user, $request))
                ? ['name' => trim(($sa->firstname ?? '').' '.($sa->lastname ?? '')) ?: $sa->username, 'email' => $sa->email]
                : null,
            'app_name' => $settings->app_name ?? config('app.name'),
            'page_title_suffix' => $settings->page_title_suffix ?? '',
            // Appearance -> Show breadcrumbs (every page header).
            'show_breadcrumbs' => (bool) ($settings->show_breadcrumbs ?? true),
            'customize_button_visible' => (bool) ($settings->customize_button_visible ?? true),
            // System Settings -> Appearance -> Primary Color: the admin app's
            // (colorPrimary) brand color. Null = no company override, the
            // SPA falls back to its own built-in default.
            'primary_color' => $settings->primary_color ?? null,
            'hide_site_name' => (bool) ($settings->hide_site_name ?? false),
            'sidebar_logo_width' => (int) ($settings->sidebar_logo_width ?: 32),
            'sidebar_logo_height' => (int) ($settings->sidebar_logo_height ?: 32),
            'sidebar_show_logo' => (bool) ($settings->sidebar_show_logo ?? true),
            'company' => $settings->CompanyName ?? '',
            'date_format' => $settings->date_format ?? 'YYYY-MM-DD',
            'price_format' => $settings->price_format ?? null,
            // Monetary precision (3 when 3-decimal pricing is enabled, else 2) â€” read by the SPA at boot
            'price_decimals' => (bool) ($settings->enable_3_decimal_pricing ?? false) ? 3 : 2,
            'dark_mode' => (bool) ($settings->dark_mode ?? false),
            'enable_kitchen_display' => (bool) ($settings->enable_kitchen_display ?? false),
            // System Settings -> Defaults -> Customers: optional customer
            // features; the SPA hides all UI tied to one when it's off.
            'customer_loyalty_enabled' => (bool) ($settings->customer_loyalty_enabled ?? true),
            'customer_credit_limit_enabled' => (bool) ($settings->customer_credit_limit_enabled ?? true),
            'customer_opening_balance_enabled' => (bool) ($settings->customer_opening_balance_enabled ?? true),
            // POS Settings -> "Show POS button in header" (the topbar's blue POS button)
            'show_pos_button' => (bool) (\App\Models\PosSetting::where('company_id', $this->resolveActingCompanyId($request))->value('show_pos_button') ?? true),
            // Header icons on / off: the company's hidden list + this user's own choices (App\Support\HeaderIcons).
            'header_icons_hidden' => \App\Support\HeaderIcons::companyHidden((int) $this->resolveActingCompanyId($request)),
            'header_icons_user' => (object) \App\Support\HeaderIcons::userChoices($user->header_icons ?? null),
            'header_icons_order' => \App\Support\HeaderIcons::userOrder($user->header_icons ?? null),
            'timezone' => $this->getEnvValue('APP_TIMEZONE', 'UTC'),
            // Admin-defined sidebar arrangement (null = default menu.js order) â€”
            // applied by the SPA for every user at boot.
            'sidebar_menu_order' => ($settings && $settings->sidebar_menu_order)
                ? json_decode($settings->sidebar_menu_order, true)
                : null,
            // Admin module toggles ({key: bool}, null = everything enabled) â€”
            // gates the sidebar and SPA routes for every user at boot.
            'module_flags' => ($settings && $settings->module_flags)
                ? json_decode($settings->module_flags, true)
                : null,
            // Defaults -> Products: the company's names for "Group Name" / "Name"
            // (null = standard), applied to every form's labels by the SPA's i18n.
            'product_labels' => [
                'group' => $settings->product_group_label ?? null,
                'name' => $settings->product_name_label ?? null,
            ],
            // Global export defaults for list/report exports (null = built-in
            // defaults in the SPA's exporters lib).
            'export_settings' => ($settings && $settings->export_settings)
                ? json_decode($settings->export_settings, true)
                : null,
            // System Settings -> DataTable: the company's table format (null =
            // built-in defaults) and admin column customizations per table.
            'datatable_settings' => ($settings && $settings->datatable_settings)
                ? json_decode($settings->datatable_settings, true)
                : null,
            'datatable_columns' => ($settings && $settings->datatable_columns)
                ? (object) json_decode($settings->datatable_columns, true)
                : (object) [],
            // "Customize Filters" -- same idea, for the filter row (Products/
            // Sales/Purchases lists) instead of the table's own columns.
            'datatable_filters' => ($settings && $settings->datatable_filters)
                ? (object) json_decode($settings->datatable_filters, true)
                : (object) [],
            // This user's own UI preferences (e.g. each list's show/hide
            // columns), kept on the server so they follow the user to every
            // PC/browser -- see updateUiPreference().
            'ui_preferences' => (object) (json_decode((string) $user->ui_preferences, true) ?: []),
            // System Settings -> Defaults -> Users: inactivity logout (minutes,
            // null = off), what users see after login, dashboard sections off.
            'idle_logout_minutes' => $settings->idle_logout_minutes ? (int) $settings->idle_logout_minutes : null,
            'start_page_mode' => $settings->start_page_mode ?: 'dashboard',
            'dashboard_hidden_sections' => json_decode((string) ($settings->dashboard_hidden_sections ?? '[]'), true) ?: [],
            // System Settings -> Dashboard: the blocks top to bottom (block ids).
            'dashboard_section_order' => json_decode((string) ($settings->dashboard_section_order ?? '[]'), true) ?: [],
            // Admin-picked shortcut buttons (8 slots, null = the user's most used form).
            'start_page_shortcuts' => array_pad(array_slice(json_decode((string) ($settings->start_page_shortcuts ?? '[]'), true) ?: [], 0, 8), 8, null),
            // This user's own start page ({mode: recent|form, form, recent}).
            'start_preferences' => self::startPrefsOf($user),
            // Roles -> "Home page": where Home / the dashboard takes this role's
            // users (e.g. Daily Tasks for field staff); null = the normal start page.
            // The company's type has its own dashboard (hospital, school, real estate --
            // 2026-10-20): the general Dashboard menu item is hidden (stores/menu.js).
            // A SuperAdmin follows the company picked in the switcher (AdminSystem / none = no own dashboard).
            'own_dashboard' => $typeHome = \App\Support\BusinessTypes::homePath(\App\Support\BusinessTypes::of(
                $user->type === 'SuperAdmin' ? (int) $this->resolveActingCompanyId($request) : (int) $user->company_id)),
            // Else the company's business-type start page (hospital -> Hospital dashboard, 2026-10-20).
            'role_home' => $user->type === 'SuperAdmin' ? $typeHome
                : ($user->roles()->first()?->home_path ?: $typeHome),
        ];

        // Exactly the role's permissions (the role is mandatory, Admins included).
        $permissions = $user->roles()->first()?->permissions->pluck('name') ?? [];

        $productsAlerts = product_warehouse::join('products', 'product_warehouse.product_id', '=', 'products.id')
            ->whereRaw('qte <= stock_alert')
            ->whereNull('product_warehouse.deleted_at')
            ->count();

        return response()->json([
            'success' => true,
            'user' => $userData,
            'notifs' => $productsAlerts,
            'permissions' => $permissions,
        ]);
    }

    // ------------- GET USER ROLES ---------\\

    public function GetUserRole(Request $request)
    {

        $roles = Auth::user()->roles()->with('permissions')->first();

        $data = [];
        if ($roles) {
            foreach ($roles->permissions as $permission) {
                $data[] = $permission->name;

            }

            return response()->json(['success' => true, 'data' => $data]);
        }

    }

    // ------------- STORE NEW USER ---------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', User::class);

        // Company boundary: non-SuperAdmin admins can only create users
        // within their own company; a SuperAdmin may target any company
        // (defaults to their own if none was specified).
        $companyId = $this->resolveActingCompanyId($request);

        $this->validate($request, [
            // Soft-deleted users don't hold on to their email.
            'email' => ['required', Rule::unique('users')->whereNull('deleted_at')],
        ], [
            'email.unique' => 'This Email already taken.',
        ]);
        $this->validate($request, ['pin' => 'nullable|digits_between:4,8'], ['pin.digits_between' => 'The PIN must be 4 to 8 digits.']);
        if ($request->filled('pin') && User::pinTaken($companyId, $request['pin'])) {
            return response()->json([
                'message' => 'This PIN is already used by another user of this company.',
                'errors' => ['pin' => ['This PIN is already used by another user of this company.']],
            ], 422);
        }

        // Type defaults to plain admin (unchanged from before this field
        // existed). Only an acting SuperAdmin may create another SuperAdmin --
        // anyone else requesting it gets rejected outright rather than
        // silently downgraded, so the mistake is visible instead of hidden.
        $type = $request->input('type', 'admin');
        if (! in_array($type, ['SuperAdmin', 'admin', 'employee', 'customer', 'sales_agent', 'supplier'], true)) {
            $type = 'admin';
        }
        // Users -> Add creates Admin / SuperAdmin logins only (user's rule,
        // 2026-10-06): an employee, customer, supplier or sales agent gets its
        // login automatically when created in its own module.
        $homes = ['employee' => 'HRM -> Employees', 'customer' => 'People -> Customers', 'supplier' => 'People -> Suppliers', 'sales_agent' => 'Commissions -> Sales Agents'];
        if (isset($homes[$type])) {
            return response()->json([
                'message' => "Create it in {$homes[$type]}: its login is created automatically there. Users -> Add is for Admin / SuperAdmin logins.",
                'errors' => ['type' => ["Create it in {$homes[$type]}."]],
            ], 422);
        }
        if ($type === 'SuperAdmin' && optional($request->user('api'))->type !== 'SuperAdmin') {
            abort(403, 'Only a SuperAdmin can create a SuperAdmin user.');
        }

        // employee/customer/sales_agent users get their role auto-assigned
        // (the type's own per-company role) instead of the manually picked
        // one -- resolved up front since users.role_id/role_user.role_id are
        // both NOT NULL, so the initial insert needs a valid id either way.
        $autoRoleNames = ['employee' => 'Employee', 'customer' => 'Customer', 'sales_agent' => 'Sales Agent', 'supplier' => 'Supplier'];
        $roleId = isset($autoRoleNames[$type])
            ? (Role::where('company_id', $companyId)->where('name', $autoRoleNames[$type])->value('id') ?? $request['role'])
            : $request['role'];

        // Roles are per-company copies: the picked role must be the new
        // user's own company's (the dropdown only offers those now).
        if (! Role::where('id', $roleId)->where('company_id', $companyId)->whereNull('deleted_at')->exists()) {
            return response()->json([
                'message' => 'This role does not belong to the user\'s company.',
                'errors' => ['role' => ['This role does not belong to the user\'s company.']],
            ], 422);
        }

        $warning = null;
        \DB::transaction(function () use ($request, $companyId, $type, $roleId, &$warning) {
            if ($request->hasFile('avatar')) {

                $image = $request->file('avatar');
                $avatarDir = \App\Support\CompanyFiles::dir($companyId, 'avatar');
                $filename = rand(11111111, 99999999).$image->getClientOriginalName();

                $image_resize = Image::make($image->getRealPath());
                $image_resize->resize(128, 128);
                $image_resize->save(public_path($avatarDir.'/'.$filename));

                // Store the full relative path so it can be resolved later
                // without guessing which company's folder it lives in.
                $filename = $avatarDir.'/'.$filename;

            } else {
                $filename = 'no_avatar.png';
            }

            if ($request['is_all_warehouses'] == '1' || $request['is_all_warehouses'] == 'true') {
                $is_all_warehouses = 1;
            } else {
                $is_all_warehouses = 0;
            }

            $User = new User;
            $User->firstname = $request['firstname'];
            $User->lastname = $request['lastname'];
            $User->username = $request['username'];
            $User->company_id = $companyId;
            $User->email = $request['email'];
            // users.phone is NOT NULL and ConvertEmptyStringsToNull turns '' into null
            $User->phone = $request['phone'] ?? '';
            $User->password = Hash::make($request['password']);
            // Cashier PIN (POS open/close register): plain digits, unique per company.
            if ($request->filled('pin')) {
                $User->pin = (string) $request['pin'];
            }
            $User->avatar = $filename;
            $User->role_id = $roleId;
            $User->type = $type;
            $User->is_all_warehouses = $is_all_warehouses;

            // Set record_view from request (default to false if not provided)
            if (isset($request['record_view'])) {
                $User->record_view = ($request['record_view'] == '1' || $request['record_view'] == 'true' || $request['record_view'] == 1) ? 1 : 0;
            } else {
                $User->record_view = 0;
            }

            $User->save();

            $role_user = new role_user;
            $role_user->user_id = $User->id;
            $role_user->role_id = $roleId;
            $role_user->save();

            if (! $User->is_all_warehouses) {
                // Only warehouses belonging to this user's own company can be
                // assigned, regardless of what ids the request sent.
                $requestedWarehouseIds = array_map('intval', $request['assigned_to'] ?? []);
                $allowedWarehouseIds = Warehouse::whereNull('deleted_at')->where('company_id', $companyId)->pluck('id')->all();
                $requestedWarehouseIds = array_values(array_intersect($requestedWarehouseIds, $allowedWarehouseIds));
                $User->assignedWarehouses()->sync($requestedWarehouseIds);
                // Staff saved with no Location picked: company default Location
                // (else their register / bookings pickers are empty).
                \App\Services\LinkedUserService::assignDefaultLocation($User);
            }

            // employee/customer/sales_agent: auto-create the matching
            // Employee/Client/SalesAgent row, mirroring (in reverse) how
            // creating one of those already auto-creates a linked User.
            if (in_array($type, ['employee', 'customer', 'sales_agent', 'supplier'], true)) {
                $warning = \App\Services\LinkedUserService::attachTypedRecord($User, $type, $companyId);
            }

        }, 10);

        return response()->json(['success' => true, 'warning' => $warning]);
    }

    // ------------ Start page (own account only) -----------\\
    // Defaults -> Users -> "Customized by each user": the signed-in user picks
    // "Last used" shortcuts or a form that opens after login; every visit to a
    // menu page is counted so "Last used" shows their 6 most used.
    // users.start_preferences = {mode, form, recent: {'/path': {c: count, t: unix}}}.
    private static function startPrefsOf($user): array
    {
        $p = json_decode((string) $user->start_preferences, true);
        $p = is_array($p) ? $p : [];

        return [
            'mode' => ($p['mode'] ?? 'recent') === 'form' ? 'form' : 'recent',
            'form' => is_string($p['form'] ?? null) ? $p['form'] : null,
            'recent' => (object) (is_array($p['recent'] ?? null) ? $p['recent'] : []),
            // Home Buttons: 8 slots -- null = company default, '*' = my most used form, '/path' = that form.
            'slots' => self::cleanSlots($p['slots'] ?? []),
        ];
    }

    private static function cleanSlots($slots): array
    {
        $slots = array_slice(is_array($slots) ? array_values($slots) : [], 0, 8);
        $slots = array_map(fn ($s) => ($s === '*' || (is_string($s) && preg_match('#^/[A-Za-z0-9_\-/]*$#', $s))) ? $s : null, $slots);

        return array_pad($slots, 8, null);
    }

    public function updateStartPage(Request $request)
    {
        $data = $request->validate([
            'mode' => 'required|in:recent,form',
            'form' => ['nullable', 'string', 'max:200', 'regex:/^\/[A-Za-z0-9_\-\/]*$/'],
        ]);
        $user = $request->user('api');
        $p = self::startPrefsOf($user);
        $p['mode'] = $data['mode'];
        $p['form'] = $data['mode'] === 'form' ? ($data['form'] ?? null) : ($p['form'] ?? null);
        if ($request->has('slots')) {
            $p['slots'] = self::cleanSlots($request->input('slots'));
        }
        $user->start_preferences = json_encode($p);
        $user->save();

        return response()->json(['start_preferences' => $p]);
    }

    public function recordStartPageVisit(Request $request)
    {
        $data = $request->validate(['path' => ['required', 'string', 'max:200', 'regex:/^\/[A-Za-z0-9_\-\/]*$/']]);
        $user = $request->user('api');
        $p = self::startPrefsOf($user);
        $recent = (array) $p['recent'];
        $hit = (array) ($recent[$data['path']] ?? []);
        $recent[$data['path']] = ['c' => (int) ($hit['c'] ?? 0) + 1, 't' => time()];
        // Keep the 30 most used, so the column never grows without bound.
        uasort($recent, fn ($a, $b) => [$b['c'], $b['t']] <=> [$a['c'], $a['t']]);
        $p['recent'] = array_slice($recent, 0, 30, true);
        $user->start_preferences = json_encode($p);
        $user->save();

        return response()->json(['ok' => true]);
    }

    // ------------ Per-user UI preference (own account only) -----------\\
    // PUT user_preferences {key, value}: sets one key of the SIGNED-IN user's
    // ui_preferences (value null removes it). No permission needed -- it can
    // only ever touch the caller's own row. Size-capped so it can't be used
    // as general storage.
    public function updateUiPreference(Request $request)
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:150', 'regex:/^[A-Za-z0-9_\-|\/.:]+$/'],
            'value' => ['nullable'],
        ]);

        $user = $request->user('api');
        $prefs = json_decode((string) $user->ui_preferences, true) ?: [];

        if ($request->input('value') === null) {
            unset($prefs[$data['key']]);
        } else {
            if (strlen(json_encode($request->input('value'))) > 20000) {
                return response()->json(['message' => 'Preference value too large.'], 422);
            }
            $prefs[$data['key']] = $request->input('value');
        }

        $encoded = json_encode((object) $prefs);
        if (strlen($encoded) > 200000) {
            return response()->json(['message' => 'Too many saved preferences.'], 422);
        }

        $user->ui_preferences = $encoded;
        $user->save();

        return response()->json(['ui_preferences' => (object) $prefs]);
    }

    // ------------ function show -----------\\

    public function show($id)
    {
        //

    }

    public function edit(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', User::class);

        $actingUser = $request->user('api');
        $user = User::where('deleted_at', '=', null)->findOrFail($id);

        // Non-superadmin admins are locked to their own company -- they
        // can't even open a user that belongs to a different company.
        if ($actingUser->type !== 'SuperAdmin') {
            abort_unless((int) $user->company_id === (int) $actingUser->company_id, 403);
        }
        $this->guardSuperAdminTarget($actingUser, (int) $id);

        $assigned_warehouses = UserWarehouse::where('user_id', $id)->pluck('warehouse_id')->toArray();
        $warehouses = Warehouse::where('deleted_at', '=', null)->whereIn('id', $assigned_warehouses)->pluck('id')->toArray();
        // Only the edited user's own company's roles (roles are per-company
        // copies; unscoped, each showed up once per company).
        $roles = Role::where('deleted_at', null)->where('company_id', $user->company_id)->orderBy('name')->get(['id', 'name']);

        // Only a SuperAdmin (or a SuperAdmin-type target user) gets every
        // company's warehouses; everyone else only gets their own company's.
        $unrestrictedWarehouses = $actingUser->type === 'SuperAdmin' || $user->type === 'SuperAdmin';
        $all_warehouses = $unrestrictedWarehouses
            ? Warehouse::where('deleted_at', '=', null)->get(['id', 'name', 'company_id'])
            : Warehouse::where('deleted_at', '=', null)->where('company_id', $user->company_id)->get(['id', 'name', 'company_id']);
        $companies = Company::where('deleted_at', null)->get(['id', 'name']);

        return response()->json([
            'user' => $user,
            // The employee this login belongs to (employees.user_id), if any -- shown read-only.
            'employee' => $this->linkedEmployee($user),
            // Cashier PIN (hidden from the user payload; only this form shows it).
            'pin' => $user->pin,
            'assigned_warehouses' => $warehouses,
            'roles' => $roles,
            'companies' => $companies,
            'warehouses' => $all_warehouses,
        ]);
    }

    /**
     * Users -> Edit: the employee linked to this login (same company), with its
     * department, position, shift and pay -- or null when the login has none.
     */
    private function linkedEmployee(User $user): ?array
    {
        $e = \Illuminate\Support\Facades\DB::table('employees as e')
            ->leftJoin('departments as d', 'd.id', '=', 'e.department_id')
            ->leftJoin('designations as g', 'g.id', '=', 'e.designation_id')
            ->leftJoin('office_shifts as s', 's.id', '=', 'e.office_shift_id')
            ->where('e.user_id', $user->id)->where('e.company_id', $user->company_id)->whereNull('e.deleted_at')
            ->orderBy('e.id')
            ->first(['e.id', 'e.firstname', 'e.lastname', 'e.email', 'e.phone', 'e.gender', 'e.birth_date', 'e.joining_date', 'e.leaving_date',
                'e.employment_type', 'e.pay_type', 'e.hourly_rate', 'e.basic_salary', 'e.commission_rate', 'e.avatar',
                'd.department', 'g.designation', 's.name as office_shift']);
        if (! $e) {
            return null;
        }
        $e = (array) $e;
        $e['status'] = $e['leaving_date'] && $e['leaving_date'] <= now()->toDateString() ? 'left' : 'active';

        return $e;
    }

    // ------------- UPDATE  USER ---------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', User::class);

        $actingUser = $request->user('api');

        // Non-superadmin admins are locked to their own company -- they can
        // neither edit a user from a different company nor move this one to
        // a different company, no matter what the request says.
        //
        // The user always stays in their own company here. `company_id` on
        // this request is the SuperAdmin's header company switcher (http.js
        // attaches it to every call), not a "move this user" field -- the
        // edit form has no company picker -- so honoring it silently moved
        // the user into whatever company the switcher happened to be on.
        $targetCompanyId = User::where('id', $id)->value('company_id');
        if ($actingUser->type !== 'SuperAdmin') {
            abort_unless((int) $targetCompanyId === (int) $actingUser->company_id, 403);
            $targetCompanyId = $actingUser->company_id;
        }
        $this->guardSuperAdminTarget($actingUser, (int) $id);

        $this->validate($request, [
            // Soft-deleted users don't hold on to their email -- otherwise a
            // deleted user sharing this email blocks every save of this one.
            'email' => ['required', 'email', Rule::unique('users')->ignore($id)->whereNull('deleted_at')],
            // Roles are per-company copies: only this user's company's own.
            'role' => ['required', Rule::exists('roles', 'id')->where('company_id', $targetCompanyId)->whereNull('deleted_at')],
            'pin' => 'nullable|digits_between:4,8',
        ], [
            'email.unique' => 'This Email already taken.',
            'role.exists' => 'This role does not belong to the user\'s company.',
            'pin.digits_between' => 'The PIN must be 4 to 8 digits.',
        ]);
        if ($request->filled('pin') && User::pinTaken($targetCompanyId, $request['pin'], $id)) {
            return response()->json([
                'message' => 'This PIN is already used by another user of this company.',
                'errors' => ['pin' => ['This PIN is already used by another user of this company.']],
            ], 422);
        }

        \DB::transaction(function () use ($id, $request, $targetCompanyId) {
            $user = User::findOrFail($id);
            $current = $user->password;

            // Blank/absent means "keep the current password". The legacy client
            // sent the literal string 'null' when unchanged; the SPA sends an
            // empty field, which ConvertEmptyStringsToNull turns into null â€”
            // both must NOT be hashed, or the password gets silently wiped.
            if ($request->filled('NewPassword') && $request->NewPassword !== 'null' && $request->NewPassword !== $current) {
                $pass = Hash::make($request->NewPassword);
            } else {
                $pass = $user->password;
            }

            $currentAvatar = $user->avatar;
            // Only treat this as an avatar change when a file was actually
            // uploaded â€” the SPA omits the field entirely when it is untouched.
            if ($request->hasFile('avatar')) {

                $image = $request->file('avatar');
                // Company-scoped: files/company_{id}/avatar, keyed off the
                // company the user is (now) being assigned to -- not the
                // acting admin's own company (a SuperAdmin may be editing a
                // user from a different company).
                $avatarDir = \App\Support\CompanyFiles::dir($targetCompanyId, 'avatar');
                $filename = rand(11111111, 99999999).$image->getClientOriginalName();

                $image_resize = Image::make($image->getRealPath());
                $image_resize->resize(128, 128);
                $image_resize->save(public_path($avatarDir.'/'.$filename));

                // Old value may be either the new "relDir/filename" format
                // or a bare filename from the old shared images/avatar/
                // folder -- resolve whichever format it is before deleting.
                $userPhoto = str_contains((string) $currentAvatar, '/')
                    ? public_path($currentAvatar)
                    : public_path('images/avatar/'.$currentAvatar);
                if (file_exists($userPhoto)) {
                    if ($user->avatar != 'no_avatar.png') {
                        @unlink($userPhoto);
                    }
                }

                // Store the full relative path so it can be resolved later
                // without guessing which company's folder it lives in.
                $filename = $avatarDir.'/'.$filename;
            } else {
                $filename = $currentAvatar;
            }

            if ($request['is_all_warehouses'] == '1' || $request['is_all_warehouses'] == 'true') {
                $is_all_warehouses = 1;
            } else {
                $is_all_warehouses = 0;
            }

            // Set record_view from request (default to false if not provided)
            $record_view = 0;
            if (isset($request['record_view'])) {
                $record_view = ($request['record_view'] == '1' || $request['record_view'] == 'true' || $request['record_view'] == 1) ? 1 : 0;
            }

            User::whereId($id)->update([
                'firstname' => $request['firstname'],
                'lastname' => $request['lastname'],
                'username' => $request['username'],
                'company_id' => $targetCompanyId,
                'email' => $request['email'],
                'phone' => $request['phone'] ?? '',
                'password' => $pass,
                'record_view' => $record_view,
                'avatar' => $filename,
                // Active / disabled (can sign in): kept when not sent; nobody disables themselves.
                'statut' => $request->has('statut') && (int) $id !== (int) optional($request->user('api'))->id
                    ? (filter_var($request['statut'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0)
                    : User::whereKey($id)->value('statut'),
                'is_all_warehouses' => $is_all_warehouses,
                'role_id' => $request['role'],

            ]);

            // role_user is what permissions are actually read from
            // (User::roles()); keep exactly one row, matching users.role_id
            // (create it if a user somehow has none).
            role_user::updateOrCreate(['user_id' => $id], ['role_id' => $request['role']]);

            // Disabled: signed out of every device now, not when their token expires.
            if (! (int) User::whereKey($id)->value('statut')) {
                \App\Support\UserAccess::signOutEverywhere((int) $id);
            }

            // Cashier PIN: the form always sends it (shown to the admin);
            // blank = no PIN. Only touched when the field is part of the request.
            if ($request->has('pin')) {
                User::whereId($id)->update(['pin' => $request->filled('pin') ? (string) $request['pin'] : null]);
            }

            $user_saved = User::where('deleted_at', '=', null)->findOrFail($id);

            // Only warehouses belonging to this user's (now-locked-in)
            // company can be assigned, regardless of what ids the request sent.
            $requestedWarehouseIds = array_map('intval', $request['assigned_to'] ?? []);
            $allowedWarehouseIds = Warehouse::whereNull('deleted_at')->where('company_id', $targetCompanyId)->pluck('id')->all();
            $requestedWarehouseIds = array_values(array_intersect($requestedWarehouseIds, $allowedWarehouseIds));
            $user_saved->assignedWarehouses()->sync($requestedWarehouseIds);

        }, 10);

        return response()->json(['success' => true]);

    }

    /**
     * Only a SuperAdmin creates, opens, edits, disables or deletes a SuperAdmin
     * login (user's rule, 2026-10-20): an Admin can never take over one (e.g.
     * reset its password) or turn anyone into a SuperAdmin.
     */
    private function guardSuperAdminTarget($actingUser, int $targetId): void
    {
        if (optional($actingUser)->type !== 'SuperAdmin'
            && User::whereKey($targetId)->value('type') === 'SuperAdmin') {
            abort(403, 'Only a SuperAdmin can change a SuperAdmin user.');
        }
    }

    // ------------- UPDATE PROFILE ---------\\

    public function updateProfile(Request $request, $id)
    {

        $this->validate($request, [
            'firstname' => 'required',
            'lastname' => 'required',
            'username' => 'required',
            'email' => 'required|email|unique:users',
            'email' => Rule::unique('users')->ignore($id),
            'phone' => 'required',
        ]
        );

        $id = Auth::user()->id;
        $user = User::findOrFail($id);

        $currentAvatar = $user->avatar;
        if ($request->hasFile('avatar')) {

            $image = $request->file('avatar');
            // A user only ever edits their own profile -- avatar is scoped
            // to their own company, not any acting/SuperAdmin company.
            $avatarDir = \App\Support\CompanyFiles::dir((int) Auth::user()->company_id, 'avatar');
            $filename = rand(11111111, 99999999).$image->getClientOriginalName();

            $image_resize = Image::make($image->getRealPath());
            $image_resize->resize(128, 128);
            $image_resize->save(public_path($avatarDir.'/'.$filename));

            // Old value may be either the new "relDir/filename" format or a
            // bare filename from the old shared images/avatar/ folder --
            // resolve whichever format it is before deleting.
            $userPhoto = str_contains((string) $currentAvatar, '/')
                ? public_path($currentAvatar)
                : public_path('images/avatar/'.$currentAvatar);

            if (file_exists($userPhoto)) {
                if ($user->avatar != 'no_avatar.png') {
                    @unlink($userPhoto);
                }
            }

            // Store the full relative path so it can be resolved later
            // without guessing which company's folder it lives in.
            $filename = $avatarDir.'/'.$filename;
        } else {
            $filename = $currentAvatar;
        }

            User::whereId($id)->update([
                'firstname' => $request['firstname'],
                'lastname' => $request['lastname'],
                'username' => $request['username'],
                'email' => $request['email'],
                'phone' => $request['phone'],
                // Password is updated via dedicated endpoint
                'avatar' => $filename,

            ]);

        return response()->json(['avatar' => $filename, 'user' => $request['username']]);

    }

    // ------------- UPDATE PASSWORD (Profile) ---------\\

    public function updatePassword(Request $request)
    {
        $user = Auth::user();

        $this->validate($request, [
            'current_password' => ['required'],
            'new_password' => ['required', 'min:6', 'max:14', 'confirmed'],
        ]);

        if (! Hash::check($request->input('current_password'), $user->password)) {
            return response()->json([
                'success' => false,
                'message' => trans('translate.CurrentPasswordIncorrect'),
            ], 422);
        }

        $user->password = Hash::make($request->input('new_password'));
        $user->save();

        return response()->json([
            'success' => true,
            'message' => trans('translate.PasswordUpdated'),
        ]);
    }

    // ----------- IsActivated (Update Statut User) -------\\

    public function IsActivated(request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'update', User::class);

        $user = Auth::user();
        if ($request['id'] !== $user->id) {
            // Non-superadmin admins can't (de)activate a user from a
            // different company.
            if ($user->type !== 'SuperAdmin') {
                $targetCompanyId = User::where('id', $id)->value('company_id');
                abort_unless((int) $targetCompanyId === (int) $user->company_id, 403);
            }
            $this->guardSuperAdminTarget($user, (int) $id);

            User::whereId($id)->update([
                'statut' => $request['statut'],
            ]);

            return response()->json([
                'success' => true,
            ]);
        } else {
            return response()->json([
                'success' => false,
            ]);
        }
    }

    // ------------- DELETE USER ---------\\
    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', User::class);

        $user = Auth::user();

        // Prevent user from deleting their own account
        if ($id == $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot delete your own account.',
            ], 403);
        }

        $userToDelete = User::where('deleted_at', '=', null)->findOrFail($id);

        // Non-superadmin admins can't delete a user from a different company.
        if ($user->type !== 'SuperAdmin') {
            abort_unless((int) $userToDelete->company_id === (int) $user->company_id, 403);
        }
        $this->guardSuperAdminTarget($user, (int) $id);

        // Soft delete the user (bypass mass-assignment on deleted_at)
        // and mark them as inactive (statut = 0) so any legacy checks
        // that still rely on statut will treat deleted users as disabled.
        $userToDelete->deleted_at = \Carbon\Carbon::now();
        $userToDelete->statut = 0;
        // Free the cashier PIN (unique per company) for other users.
        $userToDelete->pin = null;
        \Illuminate\Support\Facades\DB::transaction(function () use ($userToDelete) {
            $userToDelete->save();
            // ...and signed out of every device (tokens + login sessions).
            \App\Support\UserAccess::disable((int) $userToDelete->id);
        });

        return response()->json(['success' => true]);
    }

    /**
     * Lightweight users list for select dropdowns (e.g. linking a sales agent to a user).
     * Only requires authentication â€” no `view User` permission check, since the call
     * site only needs id + display name to render a picker.
     */
    public function listForSelect(Request $request)
    {
        $users = User::where('deleted_at', null)
            ->where('statut', 1)
            ->orderBy('firstname')
            ->orderBy('lastname')
            ->get(['id', 'firstname', 'lastname', 'username', 'email']);

        return response()->json(['users' => $users]);
    }

    public function GetPermissions()
    {
        $roles = Auth::user()->roles()->with('permissions')->first();
        $data = [];
        if ($roles) {
            foreach ($roles->permissions as $permission) {
                $item[$permission->name]['slug'] = $permission->name;
                $item[$permission->name]['id'] = $permission->id;

            }
            $data[] = $item;
        }

        return $data[0];

    }

    // ------------- GET USER Auth ---------\\

    /**
     * PUT my_header_icons {icons: {key: true|false|null}, order?: [keys]} -- Profile
     * -> Header icons: the signed-in user's own choices (null = follow the
     * company) and their left-to-right order (stored as "_order").
     */
    public function updateMyHeaderIcons(Request $request)
    {
        $data = $request->validate(['icons' => 'present|array', 'order' => 'nullable|array', 'order.*' => 'string']);
        $icons = $data['icons'];
        $choices = [];
        foreach (\App\Support\HeaderIcons::KEYS as $k) {
            if (array_key_exists($k, $icons) && $icons[$k] !== null) {
                $choices[$k] = filter_var($icons[$k], FILTER_VALIDATE_BOOLEAN);
            }
        }
        $stored = $choices;
        $order = \App\Support\HeaderIcons::cleanOrder($data['order'] ?? []);
        if ($order !== \App\Support\HeaderIcons::KEYS) {
            $stored['_order'] = $order; // only kept when it differs from the standard order
        }
        User::whereKey(Auth::id())->update(['header_icons' => $stored ? json_encode($stored) : null]);

        return response()->json(['success' => true, 'header_icons_user' => (object) $choices, 'header_icons_order' => $order]);
    }

    public function GetInfoProfile(Request $request)
    {
        $data = Auth::user();
        // 'type' is already a plain column on $data; role name needs its own
        // lookup since roles() is a belongsToMany (role_user pivot).
        $data->role_name = optional($data->roles()->first())->name;

        return response()->json(['success' => true, 'user' => $data]);
    }

    // ------------- Check Phone Duplicate (per-entity type) ---------\\

    public function checkPhoneDuplicate(Request $request)
    {
        $phone = trim((string) $request->input('phone', ''));
        $type = $request->input('type'); // 'user' | 'client' | 'provider'
        $excludeId = $request->input('exclude_id');

        if ($phone === '' || ! in_array($type, ['user', 'client', 'provider'], true)) {
            return response()->json(['exists' => false]);
        }

        switch ($type) {
            case 'client':
                $q = Client::whereNull('deleted_at')->where('phone', $phone);
                break;
            case 'provider':
                $q = Provider::whereNull('deleted_at')->where('phone', $phone);
                break;
            case 'user':
            default:
                $q = User::whereNull('deleted_at')->where('phone', $phone);
                break;
        }

        if ($excludeId) {
            $q->where('id', '!=', $excludeId);
        }

        return response()->json(['exists' => $q->exists()]);
    }

    // -------------- Get Environment Value Directly from .env File ---------------\\

    private function getEnvValue($key, $default = null)
    {
        $envFile = app()->environmentFilePath();
        if (!file_exists($envFile)) {
            return $default;
        }
        
        $content = file_get_contents($envFile);
        $lines = preg_split('/\r\n|\r|\n/', $content);
        
        foreach ($lines as $line) {
            // Skip comments and empty lines
            if (empty(trim($line)) || strpos(trim($line), '#') === 0) {
                continue;
            }
            
            // Check if line contains the key
            if (strpos($line, $key . '=') === 0) {
                $parts = explode('=', $line, 2);
                if (count($parts) === 2) {
                    $value = trim($parts[1]);
                    // Remove quotes if present
                    $value = trim($value, '"\'');
                    return $value;
                }
            }
        }
        
        return $default;
    }
}
