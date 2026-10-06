<?php

namespace App\Http\Controllers\hrm;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Holiday;
use Carbon\Carbon;
use Illuminate\Http\Request;

class HolidayController extends Controller
{
    use \App\Http\Controllers\Concerns\ScopesToCompany;

    /**
     * Multicompany: an Admin works on their own company only; a SuperAdmin on
     * the switcher's company (null = all companies).
     */
    private function holidayCompany(Request $request): ?int
    {
        $isSA = optional($request->user('api'))->type === 'SuperAdmin';
        $cid = $this->resolveActingCompanyId($request);

        return $isSA && ($cid === 999 || ! $request->filled('company_id')) ? null : $cid;
    }

    /** The companies the form may offer: the acting one (a SuperAdmin on all: every company). */
    private function companyChoices(Request $request)
    {
        $cid = $this->holidayCompany($request);

        return Company::where('deleted_at', '=', null)->where('id', '!=', 999)
            ->when($cid, fn ($q) => $q->where('id', $cid))->orderBy('name')->get(['id', 'name']);
    }

    /** A company id sent by the form must be one of those choices. */
    private function checkCompany(Request $request): void
    {
        abort_unless($this->companyChoices($request)->pluck('id')->contains((int) $request['company_id']), 422, 'Choose your own company.');
    }

    /** The holiday must belong to the acting company (404 otherwise). */
    private function scopedHoliday(Request $request, $id): Holiday
    {
        $cid = $this->holidayCompany($request);

        return Holiday::whereNull('deleted_at')->when($cid, fn ($q) => $q->where('company_id', $cid))->findOrFail($id);
    }

    // ----------- GET ALL Holidays --------------\\

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Holiday::class);
        $companyId = $this->holidayCompany($request);

        // How many items do you want to display.
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = $request->SortType;

        $holidays = Holiday::with('company')->where('deleted_at', '=', null)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))

        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('title', 'LIKE', "%{$request->search}%");
                });
            });
        $totalRows = $holidays->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }
        $holidays = $holidays->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        $data = [];
        foreach ($holidays as $holiday) {

            $item['id'] = $holiday->id;
            $item['title'] = $holiday->title;
            $item['company_id'] = $holiday->company_id;
            $item['company_name'] = optional($holiday['company'])->name;
            $item['start_date'] = $holiday->start_date;
            $item['end_date'] = $holiday->end_date;
            $item['description'] = $holiday->description;

            $data[] = $item;
        }

        return response()->json([
            'holidays' => $data,
            'totalRows' => $totalRows,
        ]);
    }

    public function create(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Holiday::class);

        return response()->json([
            'companies' => $this->companyChoices($request),
        ]);
    }

    // ----------- Store new Holiday --------------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Holiday::class);

        request()->validate([
            'title' => 'required|string',
            'start_date' => 'required',
            'end_date' => 'required',
            'company_id' => 'required',
        ]);
        $this->checkCompany($request);

        Holiday::create([
            'company_id' => $request['company_id'],
            'title' => $request['title'],
            'start_date' => $request['start_date'],
            'end_date' => $request['end_date'],
            'description' => $request['description'],
        ]);

        return response()->json(['success' => true]);
    }

    // ------------ function show -----------\\

    public function show($id)
    {
        //

    }

    public function edit(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Holiday::class);
        $this->scopedHoliday($request, $id);

        return response()->json([
            'companies' => $this->companyChoices($request),
        ]);

    }

    // -----------Update Holiday --------------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Holiday::class);

        request()->validate([
            'title' => 'required|string|max:255',
            'start_date' => 'required',
            'end_date' => 'required',
            'company_id' => 'required',
        ]);
        $this->scopedHoliday($request, $id);
        $this->checkCompany($request);

        Holiday::whereId($id)->update([
            'company_id' => $request['company_id'],
            'title' => $request['title'],
            'start_date' => $request['start_date'],
            'end_date' => $request['end_date'],
            'description' => $request['description'],
        ]);

        return response()->json(['success' => true]);
    }

    // ----------- Delete  Holiday --------------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Holiday::class);
        $this->scopedHoliday($request, $id);

        Holiday::whereId($id)->update([
            'deleted_at' => Carbon::now(),
        ]);

        return response()->json(['success' => true]);
    }

    // -------------- Delete by selection  ---------------\\

    public function delete_by_selection(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'delete', Holiday::class);

        $selectedIds = $request->selectedIds;
        $companyId = $this->holidayCompany($request);
        foreach ($selectedIds as $holiday_id) {
            // Only the acting company's holidays.
            Holiday::whereId($holiday_id)->when($companyId, fn ($q) => $q->where('company_id', $companyId))->update([
                'deleted_at' => Carbon::now(),
            ]);
        }

        return response()->json(['success' => true]);
    }
}
