<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Http\Controllers\Controller;
use App\Models\StoreGalleryImage;
use App\Models\StoreSetting;
use App\Services\GalleryImporter;
use App\Support\CompanyFiles;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Store -> Gallery (per company): pictures shown on the online store's
 * /gallery page. Same permission as the other store content screens
 * (Store_settings_view); every row is the acting company's only.
 *
 *  GET    store/gallery               -> {data, can_import, site_candidates}
 *  POST   store/gallery               -> upload one or more images (images[])
 *  PUT    store/gallery/{id}          -> title / active
 *  DELETE store/gallery/{id}
 *  POST   store/gallery/reorder       -> {ids: [...]} in the new order
 *  POST   store/gallery/import-site   -> copy the imported website's pictures in
 */
class GalleryApiController extends Controller
{
    use ScopesToCompany;

    private function companyId(Request $request): int
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        return (int) $this->resolveActingCompanyId($request);
    }

    private function find(int $companyId, $id): StoreGalleryImage
    {
        $g = StoreGalleryImage::findOrFail($id);
        abort_unless((int) $g->company_id === $companyId, 404);

        return $g;
    }

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);
        $rows = StoreGalleryImage::where('company_id', $companyId)->orderBy('sort_order')->orderBy('id')->get();
        $hasSite = GalleryImporter::siteDir($companyId) !== null;

        return response()->json([
            'data' => $rows,
            'can_import' => $hasSite,
            'site_candidates' => $hasSite ? count(GalleryImporter::candidates($companyId)) : 0,
            'public_url' => route('store.gallery').'?company='.$companyId,
        ]);
    }

    public function store(Request $request)
    {
        $companyId = $this->companyId($request);
        $request->validate([
            'images' => 'required|array|min:1|max:30',
            'images.*' => 'file|mimes:jpg,jpeg,png,webp,gif|max:8192',
        ]);

        $relDir = CompanyFiles::dir($companyId, 'gallery');
        $order = (int) StoreGalleryImage::where('company_id', $companyId)->max('sort_order');
        $created = [];
        foreach ($request->file('images') as $file) {
            $base = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());
            $name = Str::limit(Str::slug($base) ?: 'photo', 60, '').'-'.Str::lower(Str::random(6)).'.'.$ext;
            $file->move(public_path($relDir), $name);
            $size = @getimagesize(public_path($relDir.'/'.$name)) ?: [null, null];

            $created[] = StoreGalleryImage::create([
                'company_id' => $companyId,
                'title' => Str::limit(Str::title(trim(preg_replace('/[-_]+/', ' ', $base))), 190, ''),
                'image' => $relDir.'/'.$name,
                'width' => $size[0],
                'height' => $size[1],
                'sort_order' => ++$order,
                'active' => true,
                'source' => 'upload',
            ]);
        }

        return response()->json(['data' => $created], 201);
    }

    public function update(Request $request, $id)
    {
        $companyId = $this->companyId($request);
        $g = $this->find($companyId, $id);
        $request->merge(['active' => $request->boolean('active', $g->active)]);
        $g->fill($request->validate([
            'title' => 'nullable|string|max:190',
            'active' => 'boolean',
        ]))->save();

        return response()->json($g);
    }

    public function destroy(Request $request, $id)
    {
        $companyId = $this->companyId($request);
        $g = $this->find($companyId, $id);
        // Only ever delete our own copy inside this company's gallery folder.
        if (str_starts_with($g->image, 'files/company_'.$companyId.'/gallery/') && is_file(public_path($g->image))) {
            @unlink(public_path($g->image));
        }
        $g->delete();

        return response()->json(['ok' => true]);
    }

    public function reorder(Request $request)
    {
        $companyId = $this->companyId($request);
        $ids = $request->validate(['ids' => 'required|array', 'ids.*' => 'integer'])['ids'];
        $own = StoreGalleryImage::where('company_id', $companyId)->whereIn('id', $ids)->pluck('id')->flip();
        $i = 0;
        foreach ($ids as $id) {
            if (isset($own[$id])) {
                StoreGalleryImage::whereKey($id)->update(['sort_order' => ++$i]);
            }
        }

        return response()->json(['ok' => true]);
    }

    public function importSite(Request $request)
    {
        $companyId = $this->companyId($request);
        abort_unless(GalleryImporter::siteDir($companyId) !== null, 422, 'This company has no imported website (Store Settings -> Front page).');

        return response()->json(GalleryImporter::import($companyId));
    }
}
