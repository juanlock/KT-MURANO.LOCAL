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
                // Keep in sync with MAX_DEPTH in the Vue sidebar's menu-merge logic.
                if ($depth > 4) {
                    throw ValidationException::withMessages([
                        'positions' => 'Menu items can only be nested up to 4 levels deep.',
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
                abort(403, 'Only a SuperAdmin can add or remove menu items -- you can reorder or hide/show existing ones.');
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
                $otherCompanyIds = Company::where('id', '!=', $companyId)->pluck('id');
                foreach ($otherCompanyIds as $otherId) {
                    $this->writeCompanyPositions($otherId, $positions);
                }
            }
        });

        return response()->json([
            'message' => $toAllCompanies ? 'Menu layout saved for every company.' : 'Menu layout saved for this company.',
            'scope' => $toAllCompanies ? 'all' : 'company',
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

        $data = $request->validate([
            'key' => 'required|string|max:191',
            'hidden' => 'required|boolean',
        ]);

        $updated = MenuPosition::where('item_key', $data['key'])->update(['hidden' => (bool) $data['hidden'], 'updated_at' => now()]);

        return response()->json(['success' => true, 'companies' => $updated]);
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

        DB::transaction(function () use ($companyId, $toAllCompanies) {
            MenuPosition::where('company_id', $companyId)->delete();
            if ($toAllCompanies) {
                $otherCompanyIds = Company::where('id', '!=', $companyId)->pluck('id');
                foreach ($otherCompanyIds as $otherId) {
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
