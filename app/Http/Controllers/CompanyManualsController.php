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

    /**
     * A company's own manual (2026-11-09, Turismo Cautivo's guide for its meeting): a PDF in
     * files/company_{id}/manual/manual-{es|en}.pdf (private folder, not the type's generic one).
     * The book icon's "Download the manual" opens it instead of the type's PDF. It may hold the
     * company's figures, so only roles with the dashboard permission get it.
     */
    public static function ownPath(int $companyId, string $lang = 'es'): ?string
    {
        foreach (array_unique([$lang === 'en' ? 'en' : 'es', 'es', 'en']) as $l) {
            $rel = 'files/company_'.$companyId.'/manual/manual-'.$l.'.pdf';
            if (is_file(public_path($rel))) {
                return $rel;
            }
        }

        return null;
    }

    public function mine(Request $request)
    {
        $user = $request->user('api');
        abort_unless($user, 401);
        abort_unless($user->type === 'SuperAdmin' || $user->roles()->first()?->permissions->contains('name', 'dashboard'), 403);
        $companyId = \App\Support\CompanyFiles::actingCompanyId($request);
        $rel = self::ownPath($companyId, (string) $request->input('lang', 'es'));
        abort_unless($rel && \App\Support\CompanyFiles::owns($rel, $companyId, 'manual'), 404);

        return response()->file(public_path($rel), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.basename($rel).'"',
        ]);
    }
}
