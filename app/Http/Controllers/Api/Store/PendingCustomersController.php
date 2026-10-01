<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\BaseController;
use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Services\StoreAccountService;
use App\Mail\StoreAccountApproved;
use App\Mail\StoreAccountRejected;
use App\Models\EcommerceClient;
use App\Models\StoreSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PendingCustomersController extends BaseController
{
    use ScopesToCompany;

    /**
     * Web store accounts of the acting company only (multicompany rule --
     * this used to list/approve every company's sign-ups): an account
     * belongs to its customer's company.
     */
    private function companyAccounts(Request $request)
    {
        $companyId = $this->resolveActingCompanyId($request);

        return EcommerceClient::whereHas('client', fn ($q) => $q->where('company_id', $companyId));
    }

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        $query = $this->companyAccounts($request)->with('client', 'inviteCode')
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

        $ecomClient = $this->companyAccounts($request)->findOrFail($id);
        $ecomClient->status = 1;
        $ecomClient->save();

        // Approved: creates the linked user now (unless the company also
        // requires email verification the customer hasn't done yet).
        $userId = StoreAccountService::activateIfConfirmed($ecomClient);

        $this->notifyDecision($ecomClient->email, true, $ecomClient);

        return response()->json([
            'success' => true,
            'message' => $userId ? 'Customer approved; their account was created.' : 'Customer approved successfully.',
            'account_created' => (bool) $userId,
            'customer' => $ecomClient->load('client'),
        ]);
    }

    public function reject(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        $ecomClient = $this->companyAccounts($request)->findOrFail($id);
        $email = $ecomClient->email;
        $ecomClient->delete();

        $this->notifyDecision($email, false, $ecomClient);

        return response()->json([
            'success' => true,
            'message' => 'Customer registration rejected.',
        ]);
    }

    public function approveAll(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        $pending = $this->companyAccounts($request)->where('status', 0)
            ->whereNull('deleted_at')
            ->get();

        $created = 0;
        foreach ($pending as $ecomClient) {
            $ecomClient->status = 1;
            $ecomClient->save();
            if (StoreAccountService::activateIfConfirmed($ecomClient)) {
                $created++;
            }
            $this->notifyDecision($ecomClient->email, true, $ecomClient);
        }

        return response()->json([
            'success' => true,
            'approved_count' => $pending->count(),
            'accounts_created' => $created,
        ]);
    }

    /**
     * Email the customer about the approval decision; failures are logged
     * so the admin action never fails because of SMTP issues.
     */
    protected function notifyDecision(string $email, bool $approved, ?EcommerceClient $account = null): void
    {
        try {
            $companyId = optional(optional($account)->client)->company_id;
            // The account's company's mail server and store name.
            $this->Set_config_mail($companyId);

            $storeName = optional(StoreAccountService::settingsFor($companyId))->store_name;

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
