<?php

namespace App\Http\Controllers\Api\Intranet;

use App\Models\IntranetEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Intranet Management -> Events (intranet_manage, acting company only).
 *
 *  GET    intranet-admin/events        ?scope=upcoming|past|all &search=
 *  POST   intranet-admin/events        {title, description, location, starts_at, ends_at, all_day, color}
 *  PUT    intranet-admin/events/{id}
 *  DELETE intranet-admin/events/{id}
 */
class IntranetEventsApiController extends IntranetBaseController
{
    public function index(Request $request)
    {
        $companyId = $this->manageCompany($request);
        $request->validate(['scope' => 'nullable|in:upcoming,past,all', 'search' => 'nullable|string|max:100']);
        $q = IntranetEvent::where('company_id', $companyId);
        $now = now();
        $scope = $request->input('scope', 'upcoming');
        if ($scope === 'upcoming') {
            $q->whereRaw('COALESCE(ends_at, starts_at) >= ?', [$now->copy()->startOfDay()])->orderBy('starts_at');
        } elseif ($scope === 'past') {
            $q->whereRaw('COALESCE(ends_at, starts_at) < ?', [$now->copy()->startOfDay()])->orderByDesc('starts_at');
        } else {
            $q->orderByDesc('starts_at');
        }
        if ($s = trim((string) $request->input('search'))) {
            $q->where(fn ($w) => $w->where('title', 'like', '%'.$s.'%')->orWhere('location', 'like', '%'.$s.'%'));
        }

        return response()->json(['data' => $q->limit(500)->get()]);
    }

    private function validated(Request $request): array
    {
        $request->merge(['all_day' => $request->boolean('all_day')]);
        $data = $request->validate([
            'title' => 'required|string|max:190',
            'description' => 'nullable|string|max:5000',
            'location' => 'nullable|string|max:190',
            'starts_at' => 'required|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'all_day' => 'boolean',
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
        if ($data['all_day']) {
            $data['starts_at'] = Carbon::parse($data['starts_at'])->startOfDay();
            $data['ends_at'] = ! empty($data['ends_at']) ? Carbon::parse($data['ends_at'])->endOfDay()->startOfSecond() : null;
        }

        return $data;
    }

    public function store(Request $request)
    {
        $companyId = $this->manageCompany($request);
        $ev = IntranetEvent::create($this->validated($request) + ['company_id' => $companyId, 'user_id' => $request->user('api')->id]);

        return response()->json($ev, 201);
    }

    public function update(Request $request, $id)
    {
        $companyId = $this->manageCompany($request);
        $ev = $this->own(IntranetEvent::class, $companyId, $id);
        $ev->fill($this->validated($request))->save();

        return response()->json($ev);
    }

    public function destroy(Request $request, $id)
    {
        $companyId = $this->manageCompany($request);
        $this->own(IntranetEvent::class, $companyId, $id)->delete();

        return response()->json(['ok' => true]);
    }
}
