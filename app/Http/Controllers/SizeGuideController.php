<?php

namespace App\Http\Controllers;

use App\Models\SizeGuide;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Intervention\Image\ImageManagerStatic as Image;

class SizeGuideController extends Controller
{
    // ------------ GET ALL Size Guides (datatable) -----------\\
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', SizeGuide::class);

        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField ?: 'id';
        $dir = $request->SortType ?: 'desc';

        $query = SizeGuide::where('deleted_at', '=', null)
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('name', 'LIKE', "%{$request->search}%");
                });
            });

        $totalRows = $query->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }

        $guides = $query->offset($offSet)->limit($perPage)->orderBy($order, $dir)->get()
            ->map(function (SizeGuide $g) {
                return [
                    'id' => $g->id,
                    'name' => $g->name,
                    'image' => $g->image,
                    'columns' => $g->columns ?: [],
                    'rows' => $g->rows ?: [],
                    'rows_count' => is_array($g->rows) ? count($g->rows) : 0,
                    'status' => (bool) $g->status,
                    'created_at' => optional($g->created_at)->toDateTimeString(),
                ];
            });

        return response()->json([
            'size_guides' => $guides,
            'totalRows' => $totalRows,
        ]);
    }

    // ---------------- STORE -------------\\
    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', SizeGuide::class);

        request()->validate(['name' => 'required']);

        \DB::transaction(function () use ($request) {
            $guide = new SizeGuide;
            $guide->name = $request['name'];
            $guide->columns = $this->normalizeColumns($request->input('columns'));
            $guide->rows = $this->normalizeRows($request->input('rows'));
            $guide->status = filter_var($request->input('status', true), FILTER_VALIDATE_BOOLEAN);
            $guide->image = $this->saveImage($request);
            $guide->save();
        }, 10);

        return response()->json(['success' => true], 201);
    }

    // ---------------- UPDATE -------------\\
    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', SizeGuide::class);

        request()->validate(['name' => 'required']);

        \DB::transaction(function () use ($request, $id) {
            $guide = SizeGuide::findOrFail($id);

            $guide->name = $request['name'];
            $guide->columns = $this->normalizeColumns($request->input('columns'));
            $guide->rows = $this->normalizeRows($request->input('rows'));
            $guide->status = filter_var($request->input('status', true), FILTER_VALIDATE_BOOLEAN);
            $guide->image = $this->saveImage($request, $guide->image);
            $guide->save();
        }, 10);

        return response()->json(['success' => true]);
    }

    // ------------ DELETE -----------\\
    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', SizeGuide::class);

        SizeGuide::whereId($id)->update(['deleted_at' => Carbon::now()]);

        return response()->json(['success' => true]);
    }

    public function delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', SizeGuide::class);

        foreach ((array) $request->selectedIds as $guide_id) {
            SizeGuide::whereId($guide_id)->update(['deleted_at' => Carbon::now()]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Lightweight active-guides list for the product form dropdown.
     */
    public function list(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', SizeGuide::class);

        return response()->json([
            'size_guides' => SizeGuide::where('deleted_at', null)->where('status', 1)
                ->orderBy('name')->get(['id', 'name']),
        ]);
    }

    // ---------------------------------------------------------------------

    private function saveImage(Request $request, ?string $currentImage = null): ?string
    {
        $dir = public_path('/images/size_guides');
        if (! File::exists($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        // A new file was uploaded.
        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $filename = rand(11111111, 99999999).$image->getClientOriginalName();
            Image::make($image->getRealPath())->resize(700, 700, function ($c) {
                $c->aspectRatio();
                $c->upsize();
            })->save($dir.'/'.$filename);

            if ($currentImage && $currentImage !== 'no-image.png' && File::exists($dir.'/'.$currentImage)) {
                @unlink($dir.'/'.$currentImage);
            }

            return $filename;
        }

        // No new file: keep the existing one on update, null on create.
        return $currentImage;
    }

    /** Column headers → clean list of non-empty strings. */
    private function normalizeColumns($value): array
    {
        $value = $this->decodeJson($value);
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(fn ($c) => trim((string) $c), $value), fn ($c) => $c !== ''));
    }

    /** Rows → array of rows, each an array of cell strings. */
    private function normalizeRows($value): array
    {
        $value = $this->decodeJson($value);
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $row) {
            if (! is_array($row)) {
                continue;
            }
            $cells = array_map(fn ($c) => trim((string) $c), $row);
            // Drop fully-empty rows.
            if (count(array_filter($cells, fn ($c) => $c !== '')) > 0) {
                $out[] = array_values($cells);
            }
        }

        return $out;
    }

    private function decodeJson($value)
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return json_last_error() === JSON_ERROR_NONE ? $decoded : [];
        }

        return $value;
    }
}
