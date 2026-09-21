<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\PortalAccountDeletion;
use App\Models\PortalClient;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class PortalProfileController extends Controller
{
    /**
     * GET /api/portal/profile - Profile info (client + portal email).
     */
    public function show(Request $request)
    {
        $portalClient = Auth::guard('portal')->user();
        $this->assertPortalActive($portalClient);

        $portalClient->load('client');

        return response()->json([
            'portal_email' => $portalClient->email,
            'preferred_locale' => $portalClient->preferred_locale,
            'client' => [
                'id' => $portalClient->client->id,
                'name' => $portalClient->client->name,
                'email' => $portalClient->client->email,
                'phone' => $portalClient->client->phone,
                'adresse' => $portalClient->client->adresse,
                'country' => $portalClient->client->country ?? '',
                'city' => $portalClient->client->city ?? '',
                'state' => $portalClient->client->state ?? '',
                'zip' => $portalClient->client->zip ?? '',
            ],
        ]);
    }

    /**
     * PUT /api/portal/profile/password - Update portal password.
     */
    public function updatePassword(Request $request)
    {
        $portalClient = Auth::guard('portal')->user();
        $this->assertPortalActive($portalClient);

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        if (!Hash::check($data['current_password'], $portalClient->password)) {
            return response()->json(['message' => __('portal.current_password_incorrect')], 422);
        }

        $portalClient->update(['password' => Hash::make($data['password'])]);

        return response()->json(['success' => true]);
    }

    /**
     * DELETE /api/portal/profile - Close this portal account.
     *
     * Deletes the portal LOGIN only. The Client record and every sale,
     * invoice, payment, quotation and contract attached to it are accounting
     * records and are deliberately left untouched; an admin can re-issue
     * portal access later from the client's page.
     *
     * Refuses while the account still owes money, so nobody closes their way
     * out of an outstanding balance by accident.
     */
    public function destroyAccount(Request $request)
    {
        $portalClient = Auth::guard('portal')->user();
        $this->assertPortalActive($portalClient);

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'confirm_email' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if (! Hash::check($data['current_password'], $portalClient->password)) {
            return response()->json(['message' => __('portal.current_password_incorrect')], 422);
        }

        // Typing the account's own email is language-neutral and unambiguous.
        if (mb_strtolower(trim($data['confirm_email'])) !== mb_strtolower((string) $portalClient->email)) {
            return response()->json([
                'message' => __('portal.email_mismatch'),
            ], 422);
        }

        $outstanding = $this->outstandingBalance((int) $portalClient->client_id);
        if ($outstanding > 0.009) {
            return response()->json([
                'message' => __('portal.outstanding_balance'),
                'outstanding' => round($outstanding, 2),
            ], 409);
        }

        $portalClient->loadMissing('client');

        DB::transaction(function () use ($portalClient, $data, $request) {
            PortalAccountDeletion::create([
                'client_id' => $portalClient->client_id,
                'email' => $portalClient->email,
                'client_name' => optional($portalClient->client)->name,
                'reason' => $data['reason'] ?? null,
                'ip' => $request->ip(),
            ]);

            // The login row carries no accounting value — remove it outright so
            // access is revoked for good and the email is free to reuse.
            PortalClient::whereKey($portalClient->getKey())->delete();
        });

        Auth::guard('portal')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['success' => true]);
    }

    /** What this client still owes across completed, non-deleted sales. */
    private function outstandingBalance(int $clientId): float
    {
        return (float) Sale::whereNull('deleted_at')
            ->where('client_id', $clientId)
            ->where('statut', 'completed')
            ->sum(DB::raw('GrandTotal - paid_amount'));
    }

    private function assertPortalActive($portalClient): void
    {
        if ((int) $portalClient->status !== 1) {
            abort(403, __('portal.portal_disabled'));
        }
    }
}
