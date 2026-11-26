<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Services\Helpdesk\HelpdeskAccess;
use App\Services\TransportProgramming;
use App\Support\ModuleFlags;
use Illuminate\Http\Request;

/**
 * Dashboard board "Programación" (2026-11-26). Seeing it: any transport role (transport_view / transport_profit /
 * transport_schedule / transport_monitor); programming (a date's changes): transport_schedule. The acting company.
 *
 *  GET    transport/programming?start=YYYY-MM-DD         the week (Monday to Sunday) of that date
 *  POST   transport/programming/changes                 {day, action add|remove|change, slot_id, side, terminal, time, tag, vehicle_id, note}
 *  DELETE transport/programming/changes/{id}            undo a change
 *  GET    transport/programming/export?start=            the week as Excel
 */
class TransportProgrammingController extends Controller
{
    use ScopesToCompany;

    private function company(Request $request, bool $edit = false): int
    {
        $u = $request->user('api');
        $ok = $edit ? HelpdeskAccess::can($u, 'transport_schedule')
            : collect(['transport_view', 'transport_profit', 'transport_schedule', 'transport_monitor', 'transport_manage'])->contains(fn ($p) => HelpdeskAccess::can($u, $p));
        abort_unless($ok, 403, __('You do not have permission for this.'));
        $cid = (int) $this->resolveActingCompanyId($request);
        abort_if($cid === 999, 422, __('Pick a company in the header first.'));
        ModuleFlags::abortUnlessEnabled($cid, 'transport');

        return $cid;
    }

    public function week(Request $request)
    {
        $cid = $this->company($request);
        $start = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->input('start')) ? $request->input('start') : now()->toDateString();

        return TransportProgramming::week($cid, $start) + ['can_edit' => HelpdeskAccess::can($request->user('api'), 'transport_schedule')];
    }

    public function change(Request $request)
    {
        $cid = $this->company($request, true);
        $d = $request->validate(['day' => 'required|date', 'action' => 'required|in:add,remove,change', 'slot_id' => 'nullable|integer',
            'side' => 'nullable|in:lima,chiclayo', 'terminal' => 'nullable|string|max:40', 'time' => 'nullable|string|max:5', 'tag' => 'nullable|string|max:20',
            'vehicle_id' => 'nullable|integer', 'note' => 'nullable|string|max:250']);
        abort_if($d['day'] < now()->toDateString(), 422, __('Only today or a later day can be programmed.'));

        return ['id' => TransportProgramming::change($cid, $d, (int) $request->user('api')->id)];
    }

    public function undo(Request $request, int $id)
    {
        TransportProgramming::undo($this->company($request, true), $id);

        return ['ok' => true];
    }

    public function export(Request $request)
    {
        $cid = $this->company($request);
        $start = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->input('start')) ? $request->input('start') : now()->toDateString();
        $path = TransportProgramming::export($cid, $start);

        return response()->download($path, basename($path))->deleteFileAfterSend();
    }
}
