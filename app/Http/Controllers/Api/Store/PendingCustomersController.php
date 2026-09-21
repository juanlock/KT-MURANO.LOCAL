<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\BaseController;
use App\Mail\StoreAccountApproved;
use App\Mail\StoreAccountRejected;
use App\Models\EcommerceClient;
use App\Models\StoreSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PendingCustomersController extends BaseController
{
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        $query = EcommerceClient::with('client', 'inviteCode')
            ->where('status', 0)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'desc');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('email', 'like', "%{$search}%")
                  ->orWhere('username', 'like', "%{$search}%")
                  ->orWhereHas('client', function ($q2) use ($search) {
                      $q2->where('name', 'like', "%{$search}%");
                  });
            });
        }

        $customers = $query->paginate($request->input('per_page', 15));

        return response()->json($customers);
    }

    public function approve(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        $ecomClient = EcommerceClient::findOrFail($id);
        $ecomClient->status = 1;
        $ecomClient->save();

        $this->notifyDecision($ecomClient->email, true);

        return response()->json([
            'success' => true,
            'message' => 'Customer approved successfully.',
            'customer' => $ecomClient->load('client'),
        ]);
    }

    public function reject(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        $ecomClient = EcommerceClient::findOrFail($id);
        $email = $ecomClient->email;
        $ecomClient->delete();

        $this->notifyDecision($email, false);

        return response()->json([
            'success' => true,
            'message' => 'Customer registration rejected.',
        ]);
    }

    public function approveAll(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        $pending = EcommerceClient::where('status', 0)
            ->whereNull('deleted_at')
            ->get();

        $count = EcommerceClient::where('status', 0)
            ->whereNull('deleted_at')
            ->update(['status' => 1]);

        foreach ($pending as $ecomClient) {
            $this->notifyDecision($ecomClient->email, true);
        }

        return response()->json([
            'success' => true,
            'approved_count' => $count,
        ]);
    }

    /**
     * Email the customer about the approval decision; failures are logged
     * so the admin action never fails because of SMTP issues.
     */
    protected function notifyDecision(string $email, bool $approved): void
    {
        try {
            $this->Set_config_mail();

            $storeName = StoreSetting::first()->store_name ?? null;

            if ($approved) {
                Mail::to($email)->send(new StoreAccountApproved(route('store.login.show'), $storeName));
            } else {
                Mail::to($email)->send(new StoreAccountRejected($storeName));
            }
        } catch (\Throwable $e) {
            Log::warning('Store approval decision email failed: '.$e->getMessage());
        }
    }
}
