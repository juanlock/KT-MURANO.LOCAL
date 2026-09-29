<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Http\Controllers\Controller;
use App\Models\StorePortfolioGroup;
use App\Models\StorePortfolioItem;
use App\Models\StoreSetting;
use App\Services\GalleryImporter;
use App\Services\Portfolio;
use App\Support\CompanyFiles;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Store -> Portfolio (per company): groups + pictures, the website import,
 * and the imported-front-page section setting. Same permission as the other
 * store content screens (Store_settings_view); rows are the acting company's.
 *
 *  GET    store/portfolio                  -> {groups, items, front_mode, section, can_import, site_candidates, site_has_section}
 *  POST   store/portfolio/groups           {name}
 *  PUT    store/portfolio/groups/{id}      {name}
 *  DELETE store/portfolio/groups/{id}      (its pictures become "No group")
 *  POST   store/portfolio/groups/reorder   {ids}
 *  POST   store/portfolio/items            images[] + group_id
 *  PUT    store/portfolio/items/{id}       {title, subtitle, group_id, link, active}
 *  DELETE store/portfolio/items/{id}
 *  POST   store/portfolio/items/reorder    {ids}
 *  POST   store/portfolio/import-site
 *  PUT    store/portfolio/section          {enabled, placement, title, subtitle, limit}
 */
class PortfolioApiController extends Controller
{
    use ScopesToCompany;

    private function companyId(Request $request): int
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        return (int) $this->resolveActingCompanyId($request);
    }

    private function own($model, int $companyId, $id)
    {
        $row = $model::findOrFail($id);
        abort_unless((int) $row->company_id === $companyId, 404);

        return $row;
    }

    private function ownGroupId(int $companyId, $groupId): ?int
    {
        if ($groupId === null || $groupId === '' || (int) $groupId === 0) {
            return null;
        }

        return StorePortfolioGroup::where('company_id', $companyId)->whereKey((int) $groupId)->exists() ? (int) $groupId : null;
    }

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);
        $groups = StorePortfolioGroup::where('company_id', $companyId)->withCount('items')->orderBy('sort_order')->orderBy('id')->get();
        $items = StorePortfolioItem::where('company_id', $companyId)->orderBy('sort_order')->orderBy('id')->get();
        $imported = GalleryImporter::siteDir($companyId) !== null;

        return response()->json([
            'groups' => $groups,
            'items' => $items,
            'front_mode' => $imported ? 'imported' : 'sections',
            'section' => Portfolio::sectionSettings($companyId),
            'can_import' => $imported,
            'site_candidates' => $imported ? count(Portfolio::candidates($companyId)['items']) : 0,
            'site_has_section' => $imported && Portfolio::siteHasPortfolioSection($companyId),
            'front_url' => url('/online_store').'?company='.$companyId,
        ]);
    }

    /* ---------------- groups ---------------- */

    public function storeGroup(Request $request)
    {
        $companyId = $this->companyId($request);
        $name = trim($request->validate(['name' => 'required|string|max:120'])['name']);
        $slug = $this->uniqueSlug($companyId, $name);

        return response()->json(StorePortfolioGroup::create([
            'company_id' => $companyId, 'name' => $name, 'slug' => $slug,
            'sort_order' => (int) StorePortfolioGroup::where('company_id', $companyId)->max('sort_order') + 1,
        ]), 201);
    }

    public function updateGroup(Request $request, $id)
    {
        $companyId = $this->companyId($request);
        $g = $this->own(StorePortfolioGroup::class, $companyId, $id);
        $g->fill(['name' => trim($request->validate(['name' => 'required|string|max:120'])['name'])])->save();

        return response()->json($g);
    }

    public function destroyGroup(Request $request, $id)
    {
        $companyId = $this->companyId($request);
        $g = $this->own(StorePortfolioGroup::class, $companyId, $id);
        StorePortfolioItem::where('company_id', $companyId)->where('group_id', $g->id)->update(['group_id' => null]);
        $g->delete();

        return response()->json(['ok' => true]);
    }

    public function reorderGroups(Request $request)
    {
        $companyId = $this->companyId($request);
        $this->reorder(StorePortfolioGroup::class, $companyId, $request->validate(['ids' => 'required|array', 'ids.*' => 'integer'])['ids']);

        return response()->json(['ok' => true]);
    }

    /* ---------------- pictures ---------------- */

    public function storeItems(Request $request)
    {
        $companyId = $this->companyId($request);
        $request->validate([
            'images' => 'required|array|min:1|max:30',
            'images.*' => 'file|mimes:jpg,jpeg,png,webp,gif|max:8192',
            'group_id' => 'nullable',
        ]);
        $groupId = $this->ownGroupId($companyId, $request->input('group_id'));
        $relDir = CompanyFiles::dir($companyId, 'portfolio');
        $order = (int) StorePortfolioItem::where('company_id', $companyId)->max('sort_order');
        $created = [];
        foreach ($request->file('images') as $file) {
            $base = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());
            $name = Str::limit(Str::slug($base) ?: 'photo', 60, '').'-'.Str::lower(Str::random(6)).'.'.$ext;
            $file->move(public_path($relDir), $name);
            $size = @getimagesize(public_path($relDir.'/'.$name)) ?: [null, null];
            $created[] = StorePortfolioItem::create([
                'company_id' => $companyId, 'group_id' => $groupId,
                'title' => Str::limit(Str::title(trim(preg_replace('/[-_]+/', ' ', $base))), 190, ''),
                'image' => $relDir.'/'.$name, 'width' => $size[0], 'height' => $size[1],
                'sort_order' => ++$order, 'active' => true, 'source' => 'upload',
            ]);
        }

        return response()->json(['data' => $created], 201);
    }

    public function updateItem(Request $request, $id)
    {
        $companyId = $this->companyId($request);
        $it = $this->own(StorePortfolioItem::class, $companyId, $id);
        $request->merge(['active' => $request->boolean('active', $it->active)]);
        $data = $request->validate([
            'title' => 'nullable|string|max:190',
            'subtitle' => 'nullable|string|max:190',
            'link' => ['nullable', 'string', 'max:500', 'regex:~^(https?://|/|#)~i'],
            'active' => 'boolean',
        ]);
        if ($request->has('group_id')) {
            $data['group_id'] = $this->ownGroupId($companyId, $request->input('group_id'));
        }
        $it->fill($data)->save();

        return response()->json($it);
    }

    public function destroyItem(Request $request, $id)
    {
        $companyId = $this->companyId($request);
        $it = $this->own(StorePortfolioItem::class, $companyId, $id);
        if (str_starts_with($it->image, 'files/company_'.$companyId.'/portfolio/') && is_file(public_path($it->image))) {
            @unlink(public_path($it->image));
        }
        $it->delete();

        return response()->json(['ok' => true]);
    }

    public function reorderItems(Request $request)
    {
        $companyId = $this->companyId($request);
        $this->reorder(StorePortfolioItem::class, $companyId, $request->validate(['ids' => 'required|array', 'ids.*' => 'integer'])['ids']);

        return response()->json(['ok' => true]);
    }

    /* ---------------- website + front page ---------------- */

    public function importSite(Request $request)
    {
        $companyId = $this->companyId($request);
        abort_unless(GalleryImporter::siteDir($companyId) !== null, 422, 'This company has no imported website (Store Settings -> Front page).');

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

    private function reorder(string $model, int $companyId, array $ids): void
    {
        $own = $model::where('company_id', $companyId)->whereIn('id', $ids)->pluck('id')->flip();
        $i = 0;
        foreach ($ids as $id) {
            if (isset($own[$id])) {
                $model::whereKey($id)->update(['sort_order' => ++$i]);
            }
        }
    }

    private function uniqueSlug(int $companyId, string $name): string
    {
        $base = Str::slug($name) ?: 'group';
        $slug = $base;
        for ($n = 2; StorePortfolioGroup::where('company_id', $companyId)->where('slug', $slug)->exists(); $n++) {
            $slug = $base.'-'.$n;
        }

        return $slug;
    }
}
