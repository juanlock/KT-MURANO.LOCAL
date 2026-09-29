<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Http\Controllers\Controller;
use App\Models\StorePage;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Store -> Pages (admin). Multicompany: every page belongs to one company;
 * the list/edit/delete only ever reach the acting company's pages (Admins:
 * their own company; SuperAdmin: the header switcher's company), and slugs
 * are unique per company.
 */
class PagesApiController extends Controller
{
    use ScopesToCompany;

    private function companyId(Request $req): int
    {
        // Same permission as the Store -> Pages menu/route (Store_settings_view);
        // this API had no permission check at all.
        $user = $req->user('api');
        $allowed = $user && ($user->type === 'SuperAdmin'
            || (bool) optional($user->roles()->first())->permissions?->contains('name', 'Store_settings_view'));
        abort_unless($allowed, 403);

        return (int) $this->resolveActingCompanyId($req);
    }

    private function findOwn(Request $req, $id): StorePage
    {
        return StorePage::where('company_id', $this->companyId($req))->findOrFail($id);
    }

    public function index(Request $req)
    {
        $q = trim((string) $req->query('q', ''));
        $sort = in_array($req->query('sort'), ['id', 'title', 'slug', 'published', 'created_at', 'updated_at'], true)
            ? $req->query('sort') : 'updated_at';
        $dir = strtolower((string) $req->query('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $per = max(1, min(200, (int) $req->query('per_page', 10)));

        $pages = StorePage::query()
            ->where('company_id', $this->companyId($req))
            ->when($q !== '', fn ($qq) => $qq->where(fn ($w) => $w->where('title', 'like', "%{$q}%")
                ->orWhere('slug', 'like', "%{$q}%")))
            ->orderBy($sort, $dir)
            ->paginate($per);

        return response()->json([
            'data' => $pages->items(),
            'meta' => ['total' => $pages->total()],
            'locales' => store_locales(),
        ]);
    }

    private function rules(Request $req, ?int $ignoreId = null): array
    {
        $companyId = $this->companyId($req);
        $unique = Rule::unique('store_pages', 'slug')->where('company_id', $companyId);

        return [
            'title' => 'required|string|max:190',
            'slug' => ['nullable', 'string', 'max:190', $ignoreId ? $unique->ignore($ignoreId) : $unique],
            'content' => 'nullable|string',
            'seo_title' => 'nullable|string|max:190',
            'seo_description' => 'nullable|string|max:255',
            'published' => 'boolean',
            // Imported-website pages only: show the original design or the
            // editable content (site_path itself is set by the importer).
            'use_site_design' => 'sometimes|boolean',
            'title_translations' => 'nullable|array',
            'title_translations.*' => 'nullable|string|max:190',
            'content_translations' => 'nullable|array',
            'content_translations.*' => 'nullable|string',
            'seo_title_translations' => 'nullable|array',
            'seo_title_translations.*' => 'nullable|string|max:190',
            'seo_description_translations' => 'nullable|array',
            'seo_description_translations.*' => 'nullable|string|max:255',
        ];
    }

    public function store(Request $req)
    {
        $data = $req->validate($this->rules($req));

        if (empty($data['slug'])) {
            $data['slug'] = str($data['title'])->slug('-');
        }

        $data = StorePage::applyTranslations($data, $req);
        $data['company_id'] = $this->companyId($req);

        $page = StorePage::create($data);

        return response()->json($page, 201);
    }

    public function show(Request $req, $id)
    {
        return response()->json($this->findOwn($req, $id));
    }

    public function update(Request $req, $id)
    {
        $page = $this->findOwn($req, $id);
        $data = $req->validate($this->rules($req, (int) $page->id));

        if (empty($data['slug'])) {
            $data['slug'] = str($data['title'])->slug('-');
        }

        $data = StorePage::applyTranslations($data, $req);
        unset($data['company_id']);

        $page->fill($data)->save();

        return response()->json($page);
    }

    public function destroy(Request $req, $id)
    {
        $this->findOwn($req, $id)->delete();

        return response()->json(['ok' => true]);
    }
}
