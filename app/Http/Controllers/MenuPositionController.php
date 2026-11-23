<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Company;
use App\Models\MenuPosition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MenuPositionController extends Controller
{
    use ScopesToCompany;

    /**
     * Every signed-in user needs this to render their own sidebar --
     * company-scoped, same as every other per-company resource: SuperAdmin
     * sees whichever company a ?company_id= picker sends (their own
     * company otherwise), everyone else always sees their own.
     */
    public function index(Request $request)
    {
        $companyId = $this->resolveActingCompanyId($request);

        return response()->json(
            MenuPosition::where('company_id', $companyId)
                ->select('item_key as key', 'parent_key', 'sort_order', 'label', 'icon', 'route', 'external', 'is_custom', 'template', 'dynamic_table', 'hidden', 'location')
                ->get()
        );
    }

    /**
     * Saves the full menu layout as one atomic replace, scoped to the
     * acting company. Only SuperAdmin and Admin-type users may call this at
     * all; a plain Admin may only reorder (parent_key/sort_order) and
     * hide/show items -- adding, removing, renaming or re-typing any item
     * is SuperAdmin-only (checked below via a diff against the current
     * rows, since this endpoint always saves the whole tree at once).
     */
    public function store(Request $request)
    {
        $user = $request->user('api');
        // Stored user types are 'SuperAdmin' and lowercase 'admin' (not
        // 'Admin') -- verified against real data, not assumed.
        abort_unless($user->type === 'SuperAdmin' || strtolower($user->type) === 'admin', 403);
        $companyId = $this->resolveActingCompanyId($request);

        $data = $request->validate([
            'positions' => 'required|array|min:1',
            'positions.*.key' => 'required|string',
            'positions.*.parent_key' => 'nullable|string',
            'positions.*.sort_order' => 'required|integer',
            'positions.*.label' => 'nullable|string|max:191',
            'positions.*.icon' => 'nullable|string|max:191',
            'positions.*.route' => 'nullable|string|max:191',
            'positions.*.external' => 'nullable|boolean',
            'positions.*.is_custom' => 'nullable|boolean',
            'positions.*.hidden' => 'nullable|boolean',
            'positions.*.location' => 'nullable|string|in:sidebar,topnav,footer',
        ]);

        $positions = $data['positions'];
        $keys = array_column($positions, 'key');

        if (count($keys) !== count(array_unique($keys))) {
            throw ValidationException::withMessages([
                'positions' => 'Duplicate menu item keys are not allowed.',
            ]);
        }

        foreach ($positions as $row) {
            if (!empty($row['is_custom']) && empty($row['label'])) {
                throw ValidationException::withMessages([
                    'positions' => "Custom item '{$row['key']}' needs a label.",
                ]);
            }
        }

        $keySet = array_flip($keys);
        foreach ($positions as $row) {
            $depth = 1;
            $parent = $row['parent_key'] ?? null;
            $guard = 0;
            while ($parent !== null) {
                $depth++;
                // Keep in sync with MAX_MENU_DEPTH in SidebarMenuManager.vue (5 since 2026-11-20; the sidebar renders any depth).
                if ($depth > 5) {
                    throw ValidationException::withMessages([
                        'positions' => 'Menu items can only be nested up to 5 levels deep.',
                    ]);
                }
                if (!isset($keySet[$parent])) {
                    throw ValidationException::withMessages([
                        'positions' => "Unknown parent_key '{$parent}'.",
                    ]);
                }
                $parentRow = $positions[$keySet[$parent]];
                $parent = $parentRow['parent_key'] ?? null;
                if (++$guard > 10) {
                    throw ValidationException::withMessages([
                        'positions' => 'Circular parent reference detected.',
                    ]);
                }
            }
        }

        // A plain Admin may reorder/hide but not add, remove, rename or
        // re-type anything -- diff the incoming tree against this company's
        // current rows and reject the whole save if it tries to.
        if ($user->type !== 'SuperAdmin') {
            $current = MenuPosition::where('company_id', $companyId)
                ->get(['item_key', 'label', 'icon', 'route', 'external', 'is_custom'])
                ->keyBy('item_key');

            // Only CUSTOM items count as added / removed: built-in items come
            // from the code's menu (config/menu.js), so a module added after
            // this company's layout was saved (e.g. Settings -> PayPal,
            // 2026-10-19) arrives as a new key, and one removed from the code
            // disappears -- neither is the Admin adding or removing anything.
            $currentCustom = $current->filter(fn ($r) => (bool) $r->is_custom)->keys()->all();
            $incomingCustom = array_column(array_filter($positions, fn ($r) => ! empty($r['is_custom'])), 'key');
            sort($currentCustom);
            sort($incomingCustom);
            if ($currentCustom !== $incomingCustom) {
                abort(403, __('Only a SuperAdmin can add or remove menu items -- you can reorder or hide/show existing ones.'));
            }

            foreach ($positions as $row) {
                $existing = $current[$row['key']] ?? null;
                if (! $existing) {
                    // New built-in item: position / visibility only (a "raw" item
                    // sends the code's own label; keys the code doesn't know are
                    // ignored by the sidebar, menuTree.js).
                    if (! empty($row['icon']) || ! empty($row['route']) || ! empty($row['external'])) {
                        abort(403, "Only a SuperAdmin can rename or edit menu items -- '{$row['key']}' was changed beyond its position/visibility.");
                    }
                    continue;
                }
                $changed = (string) ($row['label'] ?? '') !== (string) ($existing->label ?? '')
                    || (string) ($row['icon'] ?? '') !== (string) ($existing->icon ?? '')
                    || (string) ($row['route'] ?? '') !== (string) ($existing->route ?? '')
                    || (bool) ($row['external'] ?? false) !== (bool) $existing->external
                    || (bool) ($row['is_custom'] ?? false) !== (bool) $existing->is_custom;
                if ($changed) {
                    abort(403, "Only a SuperAdmin can rename or edit menu items -- '{$row['key']}' was changed beyond its position/visibility.");
                }
            }
        }

        $toAllCompanies = $this->appliesToAllCompanies($user, $companyId);
        $this->requireAllConfirmed($request, $toAllCompanies);

        DB::transaction(function () use ($positions, $companyId, $toAllCompanies) {
            $this->writeCompanyPositions($companyId, $positions);

            // Only a SuperAdmin working as AdminSystem (the template company,
            // 999) sets the platform-wide menu: the exact same layout is
            // copied to EVERY company. Any other save -- an Admin's, or a
            // SuperAdmin acting as one real company -- stays in that company.
            if ($toAllCompanies) {
                // Company has no SoftDeletes trait/global scope (deleted_at is
                // filtered manually elsewhere), so this already includes
                // soft-deleted rows like 999 with no withTrashed() needed.
                // Companies with their own menu ("Menú propio", on for those in production) are left out (2026-11-23).
                foreach (self::sharedMenuCompanyIds($companyId) as $otherId) {
                    $this->writeCompanyPositions($otherId, $positions);
                }
            }
        });

        return response()->json([
            'message' => $toAllCompanies ? 'Menu layout saved for every company without its own menu.' : 'Menu layout saved for this company.',
            'scope' => $toAllCompanies ? 'all' : 'company',
            'skipped' => $toAllCompanies ? self::ownMenuCompanies()->pluck('name') : [],
        ]);
    }

    /**
     * POST menu-positions/visibility {key, hidden}: force ONE menu item shown
     * or hidden for everyone -- used by the role editor for items that need
     * no permission (they used to read "Always visible" with no way to turn
     * them off). SuperAdmin acting as AdminSystem (999) only; flips the
     * item's `hidden` flag in every company's layout, nothing else.
     */
    public function visibility(Request $request)
    {
        $user = $request->user('api');
        $companyId = $this->resolveActingCompanyId($request);
        abort_unless($this->appliesToAllCompanies($user, $companyId), 403, 'Only a SuperAdmin working as AdminSystem can force a menu item for everyone.');
        $this->requireAllConfirmed($request, true);

        $data = $request->validate([
            'key' => 'required|string|max:191',
            'hidden' => 'required|boolean',
        ]);

        // Not in the companies with their own menu (2026-11-23).
        $updated = MenuPosition::where('item_key', $data['key'])->whereNotIn('company_id', self::ownMenuCompanies()->pluck('id'))
            ->update(['hidden' => (bool) $data['hidden'], 'updated_at' => now()]);

        return response()->json(['success' => true, 'companies' => $updated]);
    }

    /**
     * GET menu-positions/superadmin-impact?modules[]=&permissions[]= (user, 2026-11-23): before a menu item becomes
     * "SuperAdmin only", what it would take away -- every company (AdminSystem excluded) where the item's module is on,
     * and how many of its active logins (never the SuperAdmin) have a role with one of the item's permissions.
     * superadmin/AdminSystem only.
     */
    public function superAdminImpact(Request $request)
    {
        $user = $request->user('api');
        abort_unless($this->appliesToAllCompanies($user, $this->resolveActingCompanyId($request)), 403);
        // Comma-separated lists (lib/http.js sends query values as strings).
        $list = fn ($v) => array_values(array_filter(array_map('trim', explode(',', (string) $v)), fn ($x) => $x !== ''));
        $modules = $list($request->input('modules', ''));
        $perms = $list($request->input('permissions', ''));

        $tpl = \App\Models\Setting::TEMPLATE_COMPANY_ID;
        $companies = DB::table('companies')->whereNull('deleted_at')->where('id', '!=', $tpl)->orderBy('name')->get(['id', 'name', 'is_live']);
        // Logins whose role carries one of the permissions, per company.
        $users = $perms ? DB::table('users')
            ->join('permission_role', 'permission_role.role_id', '=', 'users.role_id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->whereIn('permissions.name', $perms)->whereNull('users.deleted_at')->where('users.statut', 1)
            ->where('users.type', '!=', 'SuperAdmin')
            ->groupBy('users.company_id')->selectRaw('users.company_id, count(distinct users.id) n')->pluck('n', 'company_id') : collect();

        $rows = [];
        foreach ($companies as $c) {
            $on = array_values(array_filter($modules, fn ($m) => \App\Support\ModuleFlags::enabled((int) $c->id, $m)));
            $n = (int) ($users[$c->id] ?? 0);
            // In use = its module is on there (an item without a switchable module: someone there can open it).
            if (($modules && $on) || (! $modules && $n)) {
                $rows[] = ['id' => (int) $c->id, 'name' => $c->name, 'live' => (bool) $c->is_live, 'modules' => $on, 'users' => $n];
            }
        }

        return response()->json(['companies' => $rows]);
    }

    /**
     * POST menu-positions/superadmin-only {key, on} (user, 2026-11-23): a menu item (or a whole group) seen only by the
     * SuperAdmin, in every company. The keys live in AdminSystem's settings (`settings.superadmin_only_menu`, JSON list)
     * and reach every user with get_user_auth (UserController); the menu and the router hide / refuse them. Needs the
     * apply_to_all confirmation like any AdminSystem change.
     */
    public function superAdminOnly(Request $request)
    {
        $user = $request->user('api');
        abort_unless($this->appliesToAllCompanies($user, $this->resolveActingCompanyId($request)), 403, 'Only a SuperAdmin working as AdminSystem can restrict a menu item.');
        $this->requireAllConfirmed($request, true);
        $data = $request->validate(['key' => 'required|string|max:191', 'on' => 'required|boolean']);

        $tpl = \App\Models\Setting::TEMPLATE_COMPANY_ID;
        $keys = self::superAdminOnlyKeys();
        $keys = $data['on'] ? array_values(array_unique([...$keys, $data['key']])) : array_values(array_diff($keys, [$data['key']]));
        DB::table('settings')->where('company_id', $tpl)->whereNull('deleted_at')
            ->update(['superadmin_only_menu' => json_encode($keys), 'updated_at' => now()]);

        return response()->json(['success' => true, 'keys' => $keys]);
    }

    /**
     * GET menu-positions/own-menu (2026-11-23): the companies AdminSystem's Save / Reset leave alone ("Menú propio"),
     * and how many it reaches -- for the confirmation. superadmin/AdminSystem only.
     */
    public function ownMenu(Request $request)
    {
        $companyId = $this->resolveActingCompanyId($request);
        abort_unless($this->appliesToAllCompanies($request->user('api'), $companyId), 403);

        return response()->json([
            'own' => self::ownMenuCompanies()->map(fn ($c) => ['id' => (int) $c->id, 'name' => $c->name, 'live' => (bool) $c->is_live])->values(),
            'shared' => Company::whereIn('id', self::sharedMenuCompanyIds($companyId))->whereNull('deleted_at')->count(),
        ]);
    }

    /** Companies whose menu is their own (companies.menu_own): AdminSystem's saves never touch them. */
    public static function ownMenuCompanies()
    {
        return DB::table('companies')->where('menu_own', 1)->whereNull('deleted_at')->orderBy('name')->get(['id', 'name', 'is_live']);
    }

    /** Every company AdminSystem's menu save reaches: all but itself and the ones with their own menu. */
    private static function sharedMenuCompanyIds(int $companyId)
    {
        return Company::where('id', '!=', $companyId)->where(fn ($q) => $q->where('menu_own', 0)->orWhereNull('menu_own'))->pluck('id');
    }

    /** The menu keys only the SuperAdmin sees (install-wide, AdminSystem's settings). */
    public static function superAdminOnlyKeys(): array
    {
        $raw = DB::table('settings')->where('company_id', \App\Models\Setting::TEMPLATE_COMPANY_ID)->whereNull('deleted_at')->value('superadmin_only_menu');
        $keys = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($keys) ? array_values(array_filter($keys, 'is_string')) : [];
    }

    /**
     * POST menu-positions/default (2026-10-20): "Default order" -- the acting
     * company's menu becomes a copy of AdminSystem's (template company 999):
     * same order, groups, names and hidden items, at every level. SuperAdmin
     * acting as a real company only; one transaction.
     */
    public function copyDefault(Request $request)
    {
        $user = $request->user('api');
        abort_unless($user->type === 'SuperAdmin', 403);
        $companyId = $this->resolveActingCompanyId($request);
        $template = \App\Models\Setting::TEMPLATE_COMPANY_ID;
        abort_if($companyId === $template, 422, 'Choose a company in the switcher: AdminSystem is the default itself.');
        abort_unless(MenuPosition::where('company_id', $template)->exists(), 422, 'AdminSystem has no saved menu to copy.');

        $count = DB::transaction(function () use ($companyId, $template) {
            MenuPosition::where('company_id', $companyId)->delete();
            $n = \App\Services\CompanyProvisioner::copyTemplateMenu($companyId);
            // The older top-level order follows too, so both layouts agree.
            DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->update([
                'sidebar_menu_order' => DB::table('settings')->where('company_id', $template)->whereNull('deleted_at')->value('sidebar_menu_order'),
                'updated_at' => now(),
            ]);
            // A hospital / school: its module menu first (BusinessTypes::MENU_FIRST).
            \App\Support\BusinessTypes::promoteMenu($companyId);

            return $n;
        });

        return response()->json([
            'message' => "Menu order copied from AdminSystem ({$count} items).",
            'count' => $count,
            'sidebar_menu_order' => json_decode((string) DB::table('settings')->where('company_id', $companyId)->value('sidebar_menu_order'), true),
        ]);
    }

    /**
     * A change that reaches EVERY company (AdminSystem) needs the user's explicit
     * yes (user's rule, 2026-10-25): the screen asks "this applies to all
     * companies" and sends apply_to_all=1. Without it nothing is written, so a
     * company's own menu (saved by its admin or superadmin/<company>) is never
     * replaced by accident.
     */
    private function requireAllConfirmed(Request $request, bool $toAllCompanies): void
    {
        if ($toAllCompanies && ! $request->boolean('apply_to_all')) {
            abort(409, __('This change applies to EVERY company and must be confirmed (apply_to_all).'));
        }
    }

    /** SuperAdmin acting as AdminSystem (template company 999) -> every company. */
    private function appliesToAllCompanies($user, int $companyId): bool
    {
        return $user->type === 'SuperAdmin' && $companyId === \App\Models\Setting::TEMPLATE_COMPANY_ID;
    }

    /**
     * NOT truncate(): TRUNCATE is DDL and implicitly commits in MySQL, which
     * breaks the caller's transaction ("There is no active transaction").
     */
    private function writeCompanyPositions(int $companyId, array $positions): void
    {
        MenuPosition::where('company_id', $companyId)->delete();
        $now = now();
        $rows = array_map(function ($row) use ($now, $companyId) {
            return [
                'company_id' => $companyId,
                'item_key' => $row['key'],
                'parent_key' => $row['parent_key'] ?? null,
                'sort_order' => $row['sort_order'],
                'label' => $row['label'] ?? null,
                'icon' => $row['icon'] ?? null,
                'route' => $row['route'] ?? null,
                'external' => $row['external'] ?? false,
                'is_custom' => $row['is_custom'] ?? false,
                'hidden' => $row['hidden'] ?? false,
                'location' => $row['location'] ?? 'sidebar',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $positions);

        foreach (array_chunk($rows, 500) as $chunk) {
            MenuPosition::insert($chunk);
        }
    }

    /**
     * Wipes this company's rows -- restores the factory menu structure from
     * config/menu.js (no custom items, no renames, nothing hidden, at any
     * level). SuperAdmin only: this discards every add/remove/rename an
     * Admin isn't allowed to make in the first place, so undoing it is the
     * same permission tier as making it. Like store(), a SuperAdmin's reset
     * replicates to every company, not just the one being viewed.
     */
    public function reset(Request $request)
    {
        $user = $request->user('api');
        abort_unless($user->type === 'SuperAdmin', 403);
        $companyId = $this->resolveActingCompanyId($request);

        // Same scope rule as store(): as AdminSystem -> every company;
        // acting as one company -> only that company.
        $toAllCompanies = $this->appliesToAllCompanies($user, $companyId);
        $this->requireAllConfirmed($request, $toAllCompanies);

        DB::transaction(function () use ($companyId, $toAllCompanies) {
            MenuPosition::where('company_id', $companyId)->delete();
            if ($toAllCompanies) {
                foreach (self::sharedMenuCompanyIds($companyId) as $otherId) {
                    MenuPosition::where('company_id', $otherId)->delete();
                }
            }
        });

        return response()->json([
            'message' => $toAllCompanies ? 'Menu layout reset for every company.' : 'Menu layout reset for this company.',
            'scope' => $toAllCompanies ? 'all' : 'company',
        ]);
    }
}
