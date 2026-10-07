<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Http\Controllers\Controller;
use App\Models\StoreSetting;
use App\Services\GalleryImporter;
use App\Services\Portfolio;
use Illuminate\Http\Request;

/**
 * The Portfolio as a WEBSITE section (Website Management -> Homepage blocks):
 * where it shows on the front page, and the import of an imported website's own
 * portfolio. The pictures themselves are the Portfolio module's works (one
 * Portfolio per company since 2026-10-06 -- the old store groups / items were
 * merged into Portfolio -> Works / Categories). Permission Store_settings_view.
 *
 *  GET    store/portfolio              -> {groups, items_count, front_mode, section, can_import, site_candidates, site_has_section, front_url}
 *  POST   store/portfolio/import-site  -> {added, groups, skipped, found} (into Portfolio -> Works)
 *  PUT    store/portfolio/section      {enabled, placement, title, subtitle, limit}
 */
class PortfolioApiController extends Controller
{
    use ScopesToCompany;

    private function companyId(Request $request): int
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        return (int) $this->resolveActingCompanyId($request);
    }

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);
        $d = Portfolio::data($companyId, 500);
        $imported = GalleryImporter::siteDir($companyId) !== null;

        return response()->json([
            'groups' => $d['groups'],
            'items_count' => $d['items']->count(),
            'front_mode' => $imported ? 'imported' : 'sections',
            'section' => Portfolio::sectionSettings($companyId),
            'can_import' => $imported,
            'site_candidates' => $imported ? count(Portfolio::candidates($companyId)['items']) : 0,
            'site_has_section' => $imported && Portfolio::siteHasPortfolioSection($companyId),
            'front_url' => url('/online_store').'?company='.$companyId,
        ]);
    }

    public function importSite(Request $request)
    {
        $companyId = $this->companyId($request);
        abort_unless(GalleryImporter::siteDir($companyId) !== null, 422, 'This company has no imported website (Website settings -> Front page).');

        return response()->json(Portfolio::import($companyId));
    }

    public function updateSection(Request $request)
    {
        $companyId = $this->companyId($request);
        $data = $request->validate([
            'enabled' => 'boolean',
            'placement' => 'required|in:replace,before_footer,end',
            'title' => 'nullable|string|max:120',
            'subtitle' => 'nullable|string|max:200',
            'limit' => 'nullable|integer|min:1|max:60',
        ]);

        return response()->json(Portfolio::saveSectionSettings($companyId, $data));
    }
}
