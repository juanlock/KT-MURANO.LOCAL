<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Http\Controllers\Controller;
use App\Models\StoreBanner;
use App\Support\CompanyFiles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Intervention\Image\Facades\Image;

class BannersApiController extends Controller
{
    use ScopesToCompany;

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreBanner::class);
        $companyId = $this->resolveActingCompanyId($request);

        $per = (int) $request->query('per_page', 10);
        $sort = $request->query('sort', 'updated_at');
        $dir = $request->query('dir', 'desc');

        $q = StoreBanner::query()->where('company_id', $companyId)->orderBy($sort, $dir)->paginate($per);

        $rows = collect($q->items())->map(function (StoreBanner $b) {
            return [
                'id' => $b->id,
                'title' => $b->title,
                'position' => $b->position,
                'active' => $b->active,
                'updated_at' => $b->updated_at->toDateTimeString(),
                'image' => $b->image,
                'image_url' => $b->image ? asset($b->image) : asset('images/brands/no-image.png'),
            ];
        });

        return response()->json([
            'data' => $rows,
            'meta' => ['total' => $q->total()],
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreBanner::class);
        $companyId = $this->resolveActingCompanyId($request);

        $request->merge(['active' => $request->boolean('active')]);

        $data = $request->validate([
            'title' => 'nullable|string|max:190',
            'position' => 'nullable|string|max:60',
            'active' => 'boolean',
            // Laravel 12: image rule excludes SVG by default; use file + mimes
            'image' => 'nullable|file|mimes:jpg,jpeg,png,webp,gif|max:4096',
        ]);

        if ($request->hasFile('image')) {
            $image = $request->file('image');

            // Generate unique name
            $filename = rand(11111111, 99999999).$image->getClientOriginalName();

            // Company-scoped: files/company_{id}/banners, not one shared
            // folder for the whole install.
            $uploadRel = CompanyFiles::dir($companyId, 'banners');
            $path = public_path($uploadRel);

            // Resize / process with Intervention
            $image_resize = Image::make($image->getRealPath());
            $image_resize->save($path.'/'.$filename);

            $data['image'] = $uploadRel.'/'.$filename;
        } else {
            $data['image'] = 'images/brands/no-image.png'; // shared placeholder, not company-scoped
        }

        $data['company_id'] = $companyId;

        $banner = StoreBanner::create($data);

        return response()->json(array_merge($banner->toArray(), [
            'image_url' => asset($data['image']),
        ]), 201);
    }

    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreBanner::class);
        $companyId = $this->resolveActingCompanyId($request);
        $b = StoreBanner::findOrFail($id);
        abort_unless((int) $b->company_id === $companyId, 404);
        $arr = $b->toArray();
        $arr['image_url'] = $b->image_url;

        return response()->json($arr);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreBanner::class);
        $companyId = $this->resolveActingCompanyId($request);
        $b = StoreBanner::findOrFail($id);
        abort_unless((int) $b->company_id === $companyId, 404);

        // Normalize boolean to satisfy validator
        $request->merge(['active' => $request->boolean('active')]);

        $data = $request->validate([
            'title' => 'nullable|string|max:190',
            'position' => 'nullable|string|max:60',
            'link' => 'nullable|url',
            'active' => 'boolean',
            // Laravel 12: image rule excludes SVG by default; use file + mimes
            'image' => 'nullable|file|mimes:jpg,jpeg,png,webp,gif|max:4096',
        ]);

        // If a new image is uploaded, delete the old one (simple unlink pattern) and save the new one.
        if ($request->hasFile('image')) {
            // delete old
            if (! empty($b->image) && basename($b->image) !== 'no-image.png') {
                $oldAbs = public_path($b->image);
                if (file_exists($oldAbs)) {
                    @unlink($oldAbs);
                }
            }

            // save new, company-scoped
            $image = $request->file('image');
            $filename = rand(11111111, 99999999).$image->getClientOriginalName();
            $uploadRel = CompanyFiles::dir($companyId, 'banners');

            $img = Image::make($image->getRealPath());
            $img->save(public_path($uploadRel).'/'.$filename);

            // store the relative path (not bare filename) in the DB
            $data['image'] = $uploadRel.'/'.$filename;
        }

        $b->fill($data)->save();

        // respond with a ready-to-use URL
        $arr = $b->toArray();
        $arr['image_url'] = $b->image
            ? asset($b->image)
            : asset('images/brands/no-image.png');

        return response()->json($arr);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreBanner::class);
        $companyId = $this->resolveActingCompanyId($request);
        $b = StoreBanner::findOrFail($id);
        abort_unless((int) $b->company_id === $companyId, 404);

        if (! empty($b->image) && basename($b->image) !== 'no-image.png') {
            $pathIMG = public_path($b->image);
            if (file_exists($pathIMG)) {
                @unlink($pathIMG);
            }
        }

        $b->delete();

        return response()->json(['ok' => true]);
    }
}
