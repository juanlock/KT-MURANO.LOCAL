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
    /** $kind: manual (the guide) or flyer (2026-11-13: the owner's flyer, files/company_{id}/manual/flyer-{lang}.pdf). */
    public static function ownPath(int $companyId, string $lang = 'es', string $kind = 'manual'): ?string
    {
        $kind = $kind === 'flyer' ? 'flyer' : 'manual';
        foreach (array_unique([$lang === 'en' ? 'en' : 'es', 'es', 'en']) as $l) {
            $rel = 'files/company_'.$companyId.'/manual/'.$kind.'-'.$l.'.pdf';
            if (is_file(public_path($rel))) {
                return $rel;
            }
        }

        return null;
    }

    /**
     * GET company-manual?lang&kind -- a signed link (30 minutes) to the company's own manual or flyer, so the browser
     * opens a real address ending in .pdf with the document's name (2026-11-13: it opened as an unnamed "blob:").
     */
    public function mine(Request $request)
    {
        $user = $request->user('api');
        abort_unless($user, 401);
        abort_unless($user->type === 'SuperAdmin' || $user->roles()->first()?->permissions->contains('name', 'dashboard'), 403);
        $kind = (string) $request->input('kind', 'manual');
        abort_if($kind === 'flyer' && ! self::seesFlyer($user), 403);
        $companyId = \App\Support\CompanyFiles::actingCompanyId($request);
        $rel = self::ownPath($companyId, (string) $request->input('lang', 'es'), $kind);
        abort_unless($rel && \App\Support\CompanyFiles::owns($rel, $companyId, 'manual'), 404);
        $name = self::downloadName($companyId, basename($rel));

        return response()->json(['url' => \Illuminate\Support\Facades\URL::temporarySignedRoute('company-manual.file', now()->addMinutes(30),
            ['company' => $companyId, 'file' => basename($rel), 'name' => $name], false)]);
    }

    /** The company's own flyer is the owner's (user's rule, 2026-11-13): only the Manager role and the SuperAdmin. */
    public static function seesFlyer($user): bool
    {
        return $user && ($user->type === 'SuperAdmin' || $user->roles()->first()?->name === 'Manager');
    }

    /** "Turismo-Cautivo-Flyer.pdf", "Turismo-Cautivo-Manual.pdf". */
    private static function downloadName(int $companyId, string $file): string
    {
        $company = \Illuminate\Support\Str::slug((string) DB::table('companies')->where('id', $companyId)->value('name')) ?: 'empresa';
        $kind = str_starts_with($file, 'flyer') ? 'Flyer' : 'Manual';

        return str_replace(' ', '-', \Illuminate\Support\Str::title(str_replace('-', ' ', $company))).'-'.$kind;
    }

    /** GET /company-docs/{company}/{name}.pdf?file&signature -- the signed link above (no session needed). */
    public function file(Request $request, int $company, string $name)
    {
        $file = (string) $request->query('file');
        abort_unless(preg_match('/^(manual|flyer)-(es|en)\.pdf$/', $file), 404);
        $rel = 'files/company_'.$company.'/manual/'.$file;
        abort_unless(is_file(public_path($rel)), 404);

        return response()->file(public_path($rel), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.str_replace(' ', '-', $name).'.pdf"',
        ]);
    }
}
