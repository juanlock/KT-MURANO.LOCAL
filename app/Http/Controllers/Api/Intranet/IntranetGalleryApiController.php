<?php

namespace App\Http\Controllers\Api\Intranet;

use App\Models\IntranetAlbum;
use App\Models\IntranetPhoto;
use App\Support\CompanyFiles;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Intranet Management -> Gallery (intranet_manage, acting company only).
 * Pictures live in public/files/company_{id}/intranet/gallery/album_{id}.
 *
 *  GET    intranet-admin/albums
 *  POST   intranet-admin/albums                     {title, description, album_date, published}
 *  POST   intranet-admin/albums/reorder             {ids}
 *  GET    intranet-admin/albums/{id}                -> album + photos
 *  PUT    intranet-admin/albums/{id}                {title, description, album_date, published, cover_photo_id}
 *  DELETE intranet-admin/albums/{id}                (deletes its photos + files)
 *  POST   intranet-admin/albums/{id}/photos         multipart {images[]}
 *  POST   intranet-admin/albums/{id}/photos/reorder {ids}
 *  PUT    intranet-admin/photos/{id}                {title}
 *  DELETE intranet-admin/photos/{id}
 */
class IntranetGalleryApiController extends IntranetBaseController
{
    public static function withCover($albums)
    {
        $albums->loadCount('photos');
        $ids = $albums->pluck('id');
        $first = IntranetPhoto::whereIn('album_id', $ids)->orderBy('sort_order')->orderBy('id')->get()->groupBy('album_id');
        $covers = IntranetPhoto::whereIn('id', $albums->pluck('cover_photo_id')->filter())->get()->keyBy('id');
        foreach ($albums as $a) {
            $cover = ($a->cover_photo_id ? $covers->get($a->cover_photo_id) : null) ?? optional($first->get($a->id))->first();
            $a->setAttribute('cover_url', $cover?->image_url);
        }

        return $albums;
    }

    public function index(Request $request)
    {
        $companyId = $this->manageCompany($request);
        $albums = IntranetAlbum::where('company_id', $companyId)->orderBy('sort_order')->orderByDesc('id')->get();

        return response()->json(['data' => self::withCover($albums)]);
    }

    private function validated(Request $request, ?IntranetAlbum $album = null): array
    {
        $request->merge(['published' => $request->boolean('published', $album ? $album->published : true)]);

        return $request->validate([
            'title' => 'required|string|max:190',
            'description' => 'nullable|string|max:2000',
            'album_date' => 'nullable|date',
            'published' => 'boolean',
            'cover_photo_id' => 'nullable|integer',
        ]);
    }

    public function store(Request $request)
    {
        $companyId = $this->manageCompany($request);
        $data = $this->validated($request);
        unset($data['cover_photo_id']);
        $data['sort_order'] = 0;
        IntranetAlbum::where('company_id', $companyId)->increment('sort_order');
        $album = IntranetAlbum::create($data + ['company_id' => $companyId]);

        return response()->json($album, 201);
    }

    public function show(Request $request, $id)
    {
        $companyId = $this->manageCompany($request);
        $album = $this->own(IntranetAlbum::class, $companyId, $id)->load('photos');

        return response()->json($album);
    }

    public function update(Request $request, $id)
    {
        $companyId = $this->manageCompany($request);
        $album = $this->own(IntranetAlbum::class, $companyId, $id);
        $data = $this->validated($request, $album);
        if (! empty($data['cover_photo_id'])) {
            abort_unless(IntranetPhoto::where('company_id', $companyId)->where('album_id', $album->id)->whereKey($data['cover_photo_id'])->exists(), 422, 'That photo is not in this album.');
        }
        $album->fill($data)->save();

        return response()->json($album);
    }

    public function destroy(Request $request, $id)
    {
        $companyId = $this->manageCompany($request);
        $album = $this->own(IntranetAlbum::class, $companyId, $id);
        foreach (IntranetPhoto::where('company_id', $companyId)->where('album_id', $album->id)->get() as $p) {
            $this->unlinkPublic($companyId, $p->image, 'gallery');
            $p->delete();
        }
        $dir = public_path('files/company_'.$companyId.'/intranet/gallery/album_'.$album->id);
        if (is_dir($dir) && count(scandir($dir)) === 2) {
            @rmdir($dir);
        }
        $album->delete();

        return response()->json(['ok' => true]);
    }

    public function reorder(Request $request)
    {
        $companyId = $this->manageCompany($request);
        $ids = $request->validate(['ids' => 'required|array|max:1000', 'ids.*' => 'integer'])['ids'];
        $own = IntranetAlbum::where('company_id', $companyId)->whereIn('id', $ids)->pluck('id')->flip();
        $i = 0;
        foreach ($ids as $id) {
            if (isset($own[$id])) {
                IntranetAlbum::whereKey($id)->update(['sort_order' => ++$i]);
            }
        }

        return response()->json(['ok' => true]);
    }

    public function storePhotos(Request $request, $id)
    {
        $companyId = $this->manageCompany($request);
        $album = $this->own(IntranetAlbum::class, $companyId, $id);
        $request->validate([
            'images' => 'required|array|min:1|max:30',
            'images.*' => 'file|mimes:jpg,jpeg,png,webp,gif|max:8192',
        ]);

        $relDir = CompanyFiles::dir($companyId, 'intranet/gallery/album_'.$album->id);
        $order = (int) IntranetPhoto::where('album_id', $album->id)->max('sort_order');
        $created = [];
        foreach ($request->file('images') as $file) {
            $base = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());
            $name = Str::limit(Str::slug($base) ?: 'photo', 60, '').'-'.Str::lower(Str::random(8)).'.'.$ext;
            $file->move(public_path($relDir), $name);
            $size = @getimagesize(public_path($relDir.'/'.$name)) ?: [null, null];

            $created[] = IntranetPhoto::create([
                'company_id' => $companyId,
                'album_id' => $album->id,
                'title' => Str::limit(Str::title(trim(preg_replace('/[-_]+/', ' ', $base))), 190, '') ?: null,
                'image' => $relDir.'/'.$name,
                'width' => $size[0],
                'height' => $size[1],
                'sort_order' => ++$order,
            ]);
        }

        return response()->json(['data' => $created], 201);
    }

    public function reorderPhotos(Request $request, $id)
    {
        $companyId = $this->manageCompany($request);
        $album = $this->own(IntranetAlbum::class, $companyId, $id);
        $ids = $request->validate(['ids' => 'required|array|max:2000', 'ids.*' => 'integer'])['ids'];
        $own = IntranetPhoto::where('company_id', $companyId)->where('album_id', $album->id)->whereIn('id', $ids)->pluck('id')->flip();
        $i = 0;
        foreach ($ids as $pid) {
            if (isset($own[$pid])) {
                IntranetPhoto::whereKey($pid)->update(['sort_order' => ++$i]);
            }
        }

        return response()->json(['ok' => true]);
    }

    public function updatePhoto(Request $request, $id)
    {
        $companyId = $this->manageCompany($request);
        $p = $this->own(IntranetPhoto::class, $companyId, $id);
        $p->fill($request->validate(['title' => 'nullable|string|max:190']))->save();

        return response()->json($p);
    }

    public function destroyPhoto(Request $request, $id)
    {
        $companyId = $this->manageCompany($request);
        $p = $this->own(IntranetPhoto::class, $companyId, $id);
        $this->unlinkPublic($companyId, $p->image, 'gallery');
        IntranetAlbum::where('company_id', $companyId)->where('cover_photo_id', $p->id)->update(['cover_photo_id' => null]);
        $p->delete();

        return response()->json(['ok' => true]);
    }
}
