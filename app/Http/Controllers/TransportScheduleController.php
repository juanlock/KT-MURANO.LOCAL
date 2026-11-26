<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Services\Helpdesk\HelpdeskAccess;
use App\Services\TransportSchedule;
use App\Support\ModuleFlags;
use Illuminate\Http\Request;

/**
 * Programación de buses (2026-11-25): the base timetable and the season plans (Fiestas Patrias, December) that Operaciones
 * made in Excel. Permission transport_schedule; the acting company (never AdminSystem).
 *
 *  GET    transport/schedule                         plans
 *  POST   transport/schedule                         new plan {name, kind, date_from, date_to, buses, notes, copy_base}
 *  GET    transport/schedule/{id}                    the plan's grid
 *  PUT    transport/schedule/{id}                    name, dates, buses, notes      DELETE transport/schedule/{id}
 *  POST   transport/schedule/{id}/slots              a departure      PUT / DELETE transport/schedule/{id}/slots/{slot}
 *  PUT    transport/schedule/{id}/day/{day}          {prices: {CIX: {SEMICAMA: 100}}, fleet: {lima, chiclayo}}
 *  POST   transport/schedule/import                  file + kind base|season (+ year, month, name)
 *  GET    transport/schedule/{id}/export             the plan as an Excel list
 */
class TransportScheduleController extends Controller
{
    use ScopesToCompany;

    private function company(Request $request): int
    {
        abort_unless(HelpdeskAccess::can($request->user('api'), 'transport_schedule'), 403, __('You do not have permission for this.'));
        $cid = (int) $this->resolveActingCompanyId($request);
        abort_if($cid === 999, 422, 'Pick a company in the header first.');
        ModuleFlags::abortUnlessEnabled($cid, 'transport');

        return $cid;
    }

    public function index(Request $request)
    {
        return ['plans' => TransportSchedule::plans($this->company($request))];
    }

    public function show(Request $request, int $id)
    {
        return TransportSchedule::show($this->company($request), $id);
    }

    public function store(Request $request)
    {
        $cid = $this->company($request);
        $d = $request->validate(['name' => 'required|string|max:120', 'kind' => 'nullable|in:base,season', 'date_from' => 'nullable|date', 'date_to' => 'nullable|date',
            'buses' => 'nullable|integer|min:1|max:200', 'notes' => 'nullable|string|max:5000', 'copy_base' => 'nullable|boolean']);

        return ['id' => TransportSchedule::savePlan($cid, null, $d, (int) $request->user('api')->id)];
    }

    public function update(Request $request, int $id)
    {
        $cid = $this->company($request);
        $d = $request->validate(['name' => 'required|string|max:120', 'date_from' => 'nullable|date', 'date_to' => 'nullable|date',
            'buses' => 'nullable|integer|min:1|max:200', 'notes' => 'nullable|string|max:5000']);

        return ['id' => TransportSchedule::savePlan($cid, $id, $d, (int) $request->user('api')->id)];
    }

    public function destroy(Request $request, int $id)
    {
        TransportSchedule::deletePlan($this->company($request), $id);

        return ['ok' => true];
    }

    public function saveSlot(Request $request, int $id, ?int $slot = null)
    {
        $cid = $this->company($request);
        $d = $request->validate(['day' => 'required|string|max:10', 'side' => 'nullable|in:lima,chiclayo', 'terminal' => 'required|string|max:40',
            'time' => 'required|string|max:10', 'route_id' => 'nullable|integer', 'tag' => 'nullable|string|max:30', 'vehicle_id' => 'nullable|integer',
            'note' => 'nullable|string|max:255', 'boardings' => 'nullable|array|max:6', 'boardings.*.terminal' => 'nullable|string|max:40',
            'boardings.*.time' => 'nullable|string|max:10']);

        return ['id' => TransportSchedule::saveSlot($cid, $id, $slot, $d)];
    }

    public function deleteSlot(Request $request, int $id, int $slot)
    {
        TransportSchedule::deleteSlot($this->company($request), $id, $slot);

        return ['ok' => true];
    }

    public function saveDay(Request $request, int $id, string $day)
    {
        $cid = $this->company($request);
        $d = $request->validate(['prices' => 'nullable|array', 'fleet' => 'nullable|array', 'fleet.lima' => 'nullable|string|max:20', 'fleet.chiclayo' => 'nullable|string|max:20']);
        TransportSchedule::saveDay($cid, $id, $day, $d);

        return ['ok' => true];
    }

    public function import(Request $request)
    {
        $cid = $this->company($request);
        $request->validate(['file' => 'required|file|max:10240|mimes:xlsx,xls', 'kind' => 'required|in:base,season', 'year' => 'nullable|integer|min:2020|max:2100',
            'month' => 'nullable|integer|min:1|max:12', 'name' => 'nullable|string|max:120']);
        $file = $request->file('file');
        $uid = (int) $request->user('api')->id;

        return $request->input('kind') === 'base'
            ? TransportSchedule::importBase($cid, $file->getRealPath(), $file->getClientOriginalName(), $uid)
            : TransportSchedule::importSeason($cid, $file->getRealPath(), $file->getClientOriginalName(), $uid,
                $request->integer('year') ?: null, $request->integer('month') ?: null, $request->input('name'));
    }

    public function export(Request $request, int $id)
    {
        $cid = $this->company($request);
        $plan = TransportSchedule::plan($cid, $id);
        $file = TransportSchedule::export($cid, $id);

        return response()->download($file, 'Programación - '.preg_replace('/[\\\\\/:*?"<>|]/', '-', $plan->name).'.xlsx')->deleteFileAfterSend(true);
    }
}
