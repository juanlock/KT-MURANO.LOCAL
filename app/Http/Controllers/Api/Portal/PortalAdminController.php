<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\PortalClient;
use App\Services\LinkedUserService;
use App\utils\helpers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PortalAdminController extends Controller
{
    /**
     * Company boundary: only a SuperAdmin may manage another company's
     * customer's portal access (this had no company check at all).
     */
    private function assertSameCompany(Request $request, Client $client): void
    {
        $user = $request->user('api');
        if ($user->type !== 'SuperAdmin') {
            abort_unless((int) $client->company_id === (int) $user->company_id, 403);
        }
    }

    /**
     * The customer's own portal login (its type=customer users row). Portal
     * access lives on users since portal_clients was merged into it; a
     * customer without a linked user (e.g. an old walk-in) gets one now.
     */
    private function portalUserFor(Client $client, bool $create): ?PortalClient
    {
        $user = $client->user_id ? PortalClient::find($client->user_id) : null;
        if (! $user && $create) {
            $createdId = LinkedUserService::provision((int) $client->company_id, 'customer', 'Customer', (string) $client->name, $client->email, $client->phone);
            $client->update(['user_id' => $createdId]);
            $user = PortalClient::find($createdId);
        }

        return $user;
    }

    /**
     * GET /api/clients/{id}/portal-status - Get portal status for a client (admin only).
     */
    public function getStatus(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Client::class);

        $client = Client::findOrFail($id);
        $this->assertSameCompany($request, $client);
        $portalClient = $this->portalUserFor($client, false);

        return response()->json([
            'portal_enabled' => $portalClient && (int) $portalClient->status === 1,
            'portal_email' => $portalClient ? $portalClient->email : null,
            'has_password' => (bool) $portalClient,
            'invitation_pending' => $portalClient && $portalClient->invitation_token !== null,
        ]);
    }

    /**
     * POST /api/clients/{id}/portal-enable - Enable portal (admin only).
     * Optionally sets the login email/password (password required the first
     * time the portal is turned on -- the auto-created user's is random).
     */
    public function enable(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Client::class);

        $client = Client::findOrFail($id);
        $this->assertSameCompany($request, $client);
        $portalClient = $this->portalUserFor($client, true);

        $data = $request->validate([
            'email' => [
                'required', 'email', 'max:190',
                Rule::unique('users', 'email')->ignore($portalClient->id)->whereNull('deleted_at'),
            ],
            'password' => [(int) $portalClient->status === 1 ? 'nullable' : 'required', 'string', 'min:8'],
        ], [
            'email.unique' => 'This email is already used by another account.',
            'password.required' => 'Set a password to turn the portal on for this customer.',
        ]);

        $portalClient->email = $data['email'];
        $portalClient->invitation_token = null;
        $portalClient->invitation_sent_at = null;
        $portalClient->status = 1;
        if (! empty($data['password'])) {
            $portalClient->password = Hash::make($data['password']);
        }
        $portalClient->save();

        return response()->json([
            'success' => true,
            'message' => ! empty($data['password'])
                ? 'Portal enabled. Client can log in with the password you set.'
                : 'Portal settings saved.',
        ]);
    }

    /**
     * POST /api/clients/{id}/portal-disable - Disable portal access (admin only).
     */
    public function disable(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Client::class);

        $client = Client::findOrFail($id);
        $this->assertSameCompany($request, $client);
        $portalClient = $this->portalUserFor($client, false);

        if ($portalClient) {
            $portalClient->update(['status' => 0]);
        }

        return response()->json(['success' => true, 'message' => __('Portal access disabled')]);
    }
}
