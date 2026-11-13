<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\User;
use App\Models\UserLoginSession;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * POS cashier PIN (users.pin, unique per company).
 *
 * The POS can be left signed in as any user; when a register is opened the
 * cashier types THEIR PIN and the browser session switches to that user, so
 * the register, the sales and the close-out are recorded under the person
 * who is actually at the till. A web route (session + CSRF): the API's
 * Passport cookie follows the session user on the next page load.
 */
class PosPinController extends BaseController
{
    public function switchUser(Request $request)
    {
        $current = Auth::guard('web')->user();
        abort_unless($current, 401);

        $data = $request->validate([
            'warehouse_id' => 'required|integer',
            'pin' => 'required|digits_between:4,8',
        ]);

        $warehouse = Warehouse::whereNull('deleted_at')->findOrFail($data['warehouse_id']);
        // Multicompany: an Admin's POS only ever works in their own company.
        if ($current->type !== 'SuperAdmin') {
            abort_unless((int) $warehouse->company_id === (int) $current->company_id, 403);
        }

        $target = User::whereNull('deleted_at')->where('statut', 1)
            ->where('company_id', $warehouse->company_id)
            ->where('type', '!=', 'customer')
            ->where('pin', (string) $data['pin'])
            ->first();
        // A SuperAdmin belongs to company 999: their own PIN works anywhere.
        if (! $target && $current->pinMatches($data['pin'])) {
            $target = $current;
        }
        if (! $target) {
            return response()->json(['success' => false, 'message' => __('Wrong PIN.')], 422);
        }

        try {
            $this->authorizeForUser($target, 'Sales_pos', Sale::class);
        } catch (AuthorizationException $e) {
            return response()->json(['success' => false, 'message' => __('This user is not allowed to use the POS.')], 403);
        }
        if ($target->type !== 'SuperAdmin' && ! $target->is_all_warehouses
            && ! UserWarehouse::where('user_id', $target->id)->where('warehouse_id', $warehouse->id)->exists()) {
            return response()->json(['success' => false, 'message' => __('This user has no access to this location.')], 403);
        }

        // Lets the next "open register" of this user at this location through
        // without typing the PIN again (5 minutes; consumed on open).
        Cache::put('pos_pin_ok:'.$target->id, (int) $warehouse->id, now()->addMinutes(5));

        $name = trim(($target->firstname ?? '').' '.($target->lastname ?? '')) ?: $target->username;
        if ((int) $target->id === (int) $current->id) {
            return response()->json(['success' => true, 'switched' => false, 'name' => $name]);
        }

        Auth::guard('web')->login($target);
        $request->session()->regenerate();
        try {
            UserLoginSession::query()->updateOrCreate(
                ['user_id' => (int) $target->id, 'session_id' => $request->session()->getId()],
                [
                    'access_token_id' => $request->session()->getId(),
                    'ip_address' => $request->ip(),
                    'user_agent' => (string) ($request->userAgent() ?? ''),
                    'logged_in_at' => now(),
                    'last_activity_at' => now(),
                    'revoked_at' => null,
                ]
            );
        } catch (\Throwable $e) {
            // Tracking never blocks the switch.
        }

        return response()->json(['success' => true, 'switched' => true, 'name' => $name]);
    }
}
