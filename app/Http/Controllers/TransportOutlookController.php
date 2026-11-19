<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Services\Helpdesk\HelpdeskAccess;
use App\Services\TransportOutlook;
use App\Support\ModuleFlags;
use App\Traits\ScopesWarehouseAccess;
use Illuminate\Http\Request;

/**
 * "Mando operativo" (2026-11-18): the departures still to leave, how they are selling and whether they will cover
 * their costs (App\Services\TransportOutlook). Same company / location scope as the other transport dashboard tabs.
 *
 *  GET transport/outlook?days   permission transport_outlook or transport_profit
 */
class TransportOutlookController extends Controller
{
    use ScopesToCompany, ScopesWarehouseAccess;

    public function index(Request $request)
    {
        $user = $request->user('api');
        // A permission row that does not exist yet simply grants nothing (HelpdeskAccess::can reads the role's list).
        abort_unless(HelpdeskAccess::can($user, 'transport_outlook') || HelpdeskAccess::can($user, 'transport_profit'), 403, __('You do not have permission for this.'));
        $cid = (int) $this->resolveActingCompanyId($request);
        if ($cid === 999 || ! ModuleFlags::enabled($cid, 'transport')) {
            return response()->json(['enabled' => false]);
        }
        $days = max(7, min(42, (int) ($request->input('days') ?: 21)));

        $out = TransportOutlook::compute($cid, $this->warehouseScopeIds(), $days);

        // Amounts (income, costs, projected profit) only for a role that sees profit (2026-11-19: not Operaciones).
        return response()->json(HelpdeskAccess::can($user, 'transport_profit') ? $out : TransportOutlook::withoutMoney($out));
    }
}
