<?php

namespace App\Http\Controllers;

use App\Models\MenuPosition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MenuPositionController extends Controller
{
    // Every signed-in user needs this to render their own sidebar.
    public function index()
    {
        return response()->json(
            MenuPosition::select('item_key as key', 'parent_key', 'sort_order', 'label', 'icon', 'route', 'external', 'is_custom', 'template', 'dynamic_table', 'hidden')->get()
        );
    }

    // Saves the full menu layout as one atomic replace. Only an admin
    // (setting_system permission, enforced client-side via the menu itself
    // and by hiding the Manage Menus screen) is expected to call this.
    public function store(Request $request)
    {
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

        DB::transaction(function () use ($positions) {
            // NOT truncate(): TRUNCATE is DDL and implicitly commits in MySQL,
            // which breaks this transaction ("There is no active transaction").
            MenuPosition::query()->delete();
            $now = now();
            $rows = array_map(function ($row) use ($now) {
                return [
                    'item_key' => $row['key'],
                    'parent_key' => $row['parent_key'] ?? null,
                    'sort_order' => $row['sort_order'],
                    'label' => $row['label'] ?? null,
                    'icon' => $row['icon'] ?? null,
                    'route' => $row['route'] ?? null,
                    'external' => $row['external'] ?? false,
                    'is_custom' => $row['is_custom'] ?? false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }, $positions);

            foreach (array_chunk($rows, 500) as $chunk) {
                MenuPosition::insert($chunk);
            }
        });

        return response()->json(['message' => 'Menu layout saved.']);
    }
}
