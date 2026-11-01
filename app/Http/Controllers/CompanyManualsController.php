<?php

namespace App\Http\Controllers;

use App\Support\BusinessTypes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Settings -> Company manuals (superadmin/AdminSystem, 2026-11-01): every company with
 * its business type and the links to its type's textual manual and 2-page flyer
 * (public/manual/murano-{type}-{lang}.pdf, flyer-{type}-{lang}.pdf).
 */
class CompanyManualsController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user('api');
        abort_unless($user && $user->type === 'SuperAdmin', 403);

        $rows = DB::table('companies')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name'])
            ->map(function ($c) {
                $type = BusinessTypes::of((int) $c->id);

                return ['id' => (int) $c->id, 'name' => $c->name, 'type' => $type, 'type_label' => BusinessTypes::LABELS[$type] ?? $type];
            })->values();
        $types = collect(BusinessTypes::LABELS)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values();

        return response()->json(['companies' => $rows, 'types' => $types]);
    }
}
