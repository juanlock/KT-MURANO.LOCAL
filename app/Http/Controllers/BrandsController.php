<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Brand;
use App\utils\helpers;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Intervention\Image\ImageManagerStatic as Image;

class BrandsController extends Controller
{
    use ScopesToCompany;

    // ------------ GET ALL Brands -----------\\

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Brand::class);
        $companyId = $this->resolveActingCompanyId($request);
        // How many items do you want to display.
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField ?: 'id';
        $dir = strtolower($request->SortType ?: 'desc') === 'asc' ? 'asc' : 'desc';
        $helpers = new helpers;

        $brands = Brand::where('deleted_at', '=', null)->where('company_id', $companyId)

        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('name', 'LIKE', "%{$request->search}%")
                        ->orWhere('description', 'LIKE', "%{$request->search}%");
                });
            });
        $totalRows = $brands->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }
        $brands = $brands->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        return response()->json([
            'brands' => $brands,
            'totalRows' => $totalRows,
        ]);

    }

    // ---------------- STORE NEW Brand -------------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Brand::class);
        $companyId = $this->resolveActingCompanyId($request);

        request()->validate([
            'name' => 'required',
            'image' => 'nullable|image|max:2048',
        ]);

        $createdBrand = \DB::transaction(function () use ($request, $companyId) {

            if ($request->hasFile('image')) {

                $image = $request->file('image');
                $filename = rand(11111111, 99999999).$image->getClientOriginalName();

                $image_resize = Image::make($image->getRealPath());
                $image_resize->resize(200, 200);
                $image_resize->save(public_path('/images/brands/'.$filename));

            } else {
                $filename = 'no-image.png';
            }

            $Brand = new Brand;

            $Brand->company_id = $companyId;
            $Brand->name = $request['name'];
            $Brand->description = $request['description'];
            $Brand->image = $filename;
            $Brand->save();

            return $Brand; // Return the created brand
        }, 10);

        return response()->json([
            'success' => true,
            'brand' => $createdBrand
        ], 201);

    }

    // ------------ function show -----------\\

    public function show($id)
    {
        //

    }

    // ---------------- UPDATE Brand -------------\\

    public function update(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'update', Brand::class);
        $companyId = $this->resolveActingCompanyId($request);

        request()->validate([
            'name' => 'required',
            'image' => 'nullable|image|max:2048',
        ]);
        \DB::transaction(function () use ($request, $id, $companyId) {
            $Brand = Brand::findOrFail($id);
            abort_unless($Brand->company_id === $companyId, 404);
            $currentImage = $Brand->image;

            if ($currentImage && $request->image != $currentImage) {
                $image = $request->file('image');
                $path = public_path().'/images/brands';
                $filename = rand(11111111, 99999999).$image->getClientOriginalName();

                $image_resize = Image::make($image->getRealPath());
                $image_resize->resize(200, 200);
                $image_resize->save(public_path('/images/brands/'.$filename));

                $BrandImage = $path.'/'.$currentImage;
                if (file_exists($BrandImage)) {
                    if ($currentImage != 'no-image.png') {
                        @unlink($BrandImage);
                    }
                }
            } elseif (! $currentImage && $request->image != 'null') {
                $image = $request->file('image');
                $path = public_path().'/images/brands';
                $filename = rand(11111111, 99999999).$image->getClientOriginalName();

                $image_resize = Image::make($image->getRealPath());
                $image_resize->resize(200, 200);
                $image_resize->save(public_path('/images/brands/'.$filename));
            } else {
                $filename = $currentImage ? $currentImage : 'no-image.png';
            }

            Brand::whereId($id)->update([
                'name' => $request['name'],
                'description' => $request['description'],
                'image' => $filename,
            ]);

        }, 10);

        return response()->json(['success' => true]);
    }

    // ------------ Delete Brand -----------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Brand::class);
        abort_unless(
            optional(Brand::find($id))->company_id === $this->resolveActingCompanyId($request),
            404
        );

        Brand::whereId($id)->update([
            'deleted_at' => Carbon::now(),
        ]);

        return response()->json(['success' => true]);
    }

    // -------------- Delete by selection  ---------------\\

    public function delete_by_selection(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'delete', Brand::class);

        $selectedIds = $request->selectedIds;
        $companyId = $this->resolveActingCompanyId($request);
        Brand::whereIn('id', $selectedIds)
            ->where('company_id', $companyId)
            ->update([
                'deleted_at' => Carbon::now(),
            ]);

        return response()->json(['success' => true]);

    }
}
