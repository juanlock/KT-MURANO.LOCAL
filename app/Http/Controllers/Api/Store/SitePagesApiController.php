<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Http\Controllers\Controller;
use App\Models\StoreSetting;
use App\Services\SitePageEditor;
use Illuminate\Http\Request;

/**
 * Store Settings -> Homepage Blocks -> Website pages: edit the texts,
 * pictures and links of the acting company's imported website pages (see
 * SitePageEditor -- only the edited spots of the HTML change).
 *
 *  GET  admin/store/site-pages                  -> {available, pages, base_url}
 *  GET  admin/store/site-pages/{file}           -> {version, texts, images, links, url}
 *  PUT  admin/store/site-pages/{file}           -> {version, texts{id:text}, images{id:{src,alt}}, links{id:href}}
 *  POST admin/store/site-pages/{file}/restore   -> back to the imported version
 *  POST admin/store/site-pages/upload           -> picture for a page (image) -> {src}
 */
class SitePagesApiController extends Controller
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
        $available = SitePageEditor::dir($companyId) !== null;

        return response()->json([
            'available' => $available,
            'pages' => $available ? SitePageEditor::pages($companyId) : [],
        ]);
    }

    public function show(Request $request, string $file)
    {
        return response()->json(SitePageEditor::load($this->companyId($request), $file, $request->boolean('edit')));
    }

    public function update(Request $request, string $file)
    {
        $companyId = $this->companyId($request);
        $data = $request->validate([
            'version' => 'required|string|size:32',
            'texts' => 'nullable|array',
            'texts.*' => 'nullable|string|max:5000',
            'images' => 'nullable|array',
            'images.*.src' => 'nullable|string|max:500',
            'images.*.alt' => 'nullable|string|max:250',
            'links' => 'nullable|array',
            'links.*' => 'nullable|string|max:500',
        ]);

        return response()->json(SitePageEditor::save(
            $companyId, $file, $data['version'], $data['texts'] ?? [], $data['images'] ?? [], $data['links'] ?? []
        ));
    }

    public function restore(Request $request, string $file)
    {
        return response()->json(SitePageEditor::restore($this->companyId($request), $file));
    }

    public function upload(Request $request)
    {
        $companyId = $this->companyId($request);
        $request->validate(['image' => 'required|file|mimes:jpg,jpeg,png,webp,gif|max:8192']);

        return response()->json(['src' => SitePageEditor::storeUpload($companyId, $request->file('image'))]);
    }
}
