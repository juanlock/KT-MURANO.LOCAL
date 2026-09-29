<?php

namespace App\Http\Controllers\Api\Intranet;

use App\Models\IntranetPost;
use App\Support\CompanyFiles;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Intranet Management -> News (intranet_manage, acting company only).
 *
 *  GET    intranet-admin/posts              ?search=&category=&page=
 *  GET    intranet-admin/posts/{id}
 *  POST   intranet-admin/posts              JSON {title, category, excerpt, body, pinned, published, published_at}
 *  PUT    intranet-admin/posts/{id}
 *  DELETE intranet-admin/posts/{id}
 *  POST   intranet-admin/posts/{id}/cover   multipart {cover}
 *  DELETE intranet-admin/posts/{id}/cover
 */
class IntranetPostsApiController extends IntranetBaseController
{
    public function index(Request $request)
    {
        $companyId = $this->manageCompany($request);
        $request->validate([
            'search' => 'nullable|string|max:100',
            'category' => ['nullable', Rule::in(IntranetPost::CATEGORIES)],
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        $q = IntranetPost::where('company_id', $companyId)->with('author');
        if ($s = trim((string) $request->input('search'))) {
            $q->where('title', 'like', '%'.$s.'%');
        }
        if ($c = $request->input('category')) {
            $q->where('category', $c);
        }
        $page = $q->feedOrder()->paginate((int) $request->input('per_page', 20));
        $page->getCollection()->each->makeHidden('body');

        return response()->json($page);
    }

    public function show(Request $request, $id)
    {
        $companyId = $this->manageCompany($request);

        return response()->json($this->own(IntranetPost::class, $companyId, $id)->load('author'));
    }

    private function validated(Request $request): array
    {
        $request->merge([
            'pinned' => $request->boolean('pinned'),
            'published' => $request->boolean('published', true),
        ]);
        $data = $request->validate([
            'title' => 'required|string|max:190',
            'category' => ['required', Rule::in(IntranetPost::CATEGORIES)],
            'excerpt' => 'nullable|string|max:500',
            'body' => 'nullable|string|max:2000000',
            'pinned' => 'boolean',
            'published' => 'boolean',
            'published_at' => 'nullable|date',
        ]);
        $data['body'] = $this->purify($data['body'] ?? null);
        $data['excerpt'] = isset($data['excerpt']) ? trim(strip_tags($data['excerpt'])) ?: null : null;

        return $data;
    }

    public function store(Request $request)
    {
        $companyId = $this->manageCompany($request);
        $data = $this->validated($request);
        if ($data['published'] && empty($data['published_at'])) {
            $data['published_at'] = now();
        }
        $post = IntranetPost::create($data + ['company_id' => $companyId, 'user_id' => $request->user('api')->id]);

        return response()->json($post->load('author'), 201);
    }

    public function update(Request $request, $id)
    {
        $companyId = $this->manageCompany($request);
        $post = $this->own(IntranetPost::class, $companyId, $id);
        $data = $this->validated($request);
        if ($data['published'] && empty($data['published_at'])) {
            $data['published_at'] = $post->published_at ?? now();
        }
        $post->fill($data)->save();

        return response()->json($post->load('author'));
    }

    public function destroy(Request $request, $id)
    {
        $companyId = $this->manageCompany($request);
        $post = $this->own(IntranetPost::class, $companyId, $id);
        $this->unlinkPublic($companyId, $post->cover_image, 'news');
        $post->delete();

        return response()->json(['ok' => true]);
    }

    public function uploadCover(Request $request, $id)
    {
        $companyId = $this->manageCompany($request);
        $post = $this->own(IntranetPost::class, $companyId, $id);
        $request->validate(['cover' => 'required|file|mimes:jpg,jpeg,png,webp,gif|max:8192']);

        $file = $request->file('cover');
        $relDir = CompanyFiles::dir($companyId, 'intranet/news');
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $name = 'post-'.$post->id.'-'.Str::lower(Str::random(8)).'.'.$ext;
        $file->move(public_path($relDir), $name);

        $this->unlinkPublic($companyId, $post->cover_image, 'news');
        $post->cover_image = $relDir.'/'.$name;
        $post->save();

        return response()->json($post);
    }

    public function removeCover(Request $request, $id)
    {
        $companyId = $this->manageCompany($request);
        $post = $this->own(IntranetPost::class, $companyId, $id);
        $this->unlinkPublic($companyId, $post->cover_image, 'news');
        $post->cover_image = null;
        $post->save();

        return response()->json($post);
    }
}
