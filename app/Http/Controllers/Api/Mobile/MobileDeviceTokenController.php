<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\BaseController;
use App\Models\MobileDeviceToken;
use App\Services\FcmService;
use Illuminate\Http\Request;

/**
 * FCM registration tokens for the mobile admin app. The app calls
 * store() on every launch (and whenever Firebase rotates its token),
 * destroy() from its logout flow, and test() from a "send test
 * notification" button in the app's settings screen.
 */
class MobileDeviceTokenController extends BaseController
{
    public function store(Request $request)
    {
        $request->validate([
            'token' => 'required|string|max:255',
            'platform' => 'nullable|string|in:android,ios',
            'device_name' => 'nullable|string|max:191',
            'app_version' => 'nullable|string|max:32',
        ]);

        // Upsert by token: a token that moves between accounts on the same
        // device is re-assigned to the newly logged-in user.
        MobileDeviceToken::updateOrCreate(
            ['token' => $request->input('token')],
            [
                'user_id' => $request->user()->id,
                'platform' => $request->input('platform'),
                'device_name' => $request->input('device_name'),
                'app_version' => $request->input('app_version'),
                'last_used_at' => now(),
            ]
        );

        return response()->json(['status' => true]);
    }

    public function destroy(Request $request)
    {
        $request->validate([
            'token' => 'required|string|max:255',
        ]);

        MobileDeviceToken::where('user_id', $request->user()->id)
            ->where('token', $request->input('token'))
            ->delete();

        return response()->json(['status' => true]);
    }

    public function test(Request $request, FcmService $fcm)
    {
        $devices = MobileDeviceToken::where('user_id', $request->user()->id)->count();

        if (! $fcm->enabled()) {
            return response()->json([
                'status' => false,
                'enabled' => false,
                'devices' => $devices,
                'message' => 'Push is not configured on this server (missing Firebase service-account file).',
            ]);
        }

        $fcm->sendToUsers([$request->user()->id], 'Test notification', 'Push notifications are working.', [
            'type' => 'test',
        ]);

        return response()->json([
            'status' => true,
            'enabled' => true,
            'devices' => $devices,
        ]);
    }
}
