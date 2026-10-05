<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Http\Controllers\Concerns\ResolvesEmailRecipients;
use App\Models\Account;
use App\Models\PaymentMethod;
use App\Models\PaymentPurchase;
use App\Models\PaymentPurchaseReturns;
use App\Models\Provider;
use App\Models\ProviderPaymentOpeningBalance;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Setting;
use App\utils\helpers;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

class ProvidersController extends BaseController
{
    use ScopesToCompany, ResolvesEmailRecipients;

    // ----------- Get ALL Suppliers-------\\

    public function index(request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Provider::class);

        // How many items do you want to display.
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField ?: 'id';
        $dir = strtolower($request->SortType ?: 'desc') === 'asc' ? 'asc' : 'desc';
        $helpers = new helpers;
        // Filter fields With Params to retrieve
        $columns = [0 => 'name', 1 => 'code', 2 => 'phone', 3 => 'email'];
        $param = [0 => 'like', 1 => 'like', 2 => 'like', 3 => 'like'];
        $data = [];

        // Company boundary: SuperAdmin may narrow via ?company_id=; everyone
        // else is always scoped to their own company. Suppliers previously had
        // no company_id column at all -- this closes the same class of gap
        // already fixed on ClientController/WarehouseController.
        $companyId = $this->resolveActingCompanyId($request);

        $providers = Provider::where('deleted_at', '=', null)->where('company_id', $companyId);

        // Multiple Filter
        $Filtred = $helpers->filter($providers, $columns, $param, $request)
        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('name', 'LIKE', "%{$request->search}%")
                        ->orWhere('code', 'LIKE', "%{$request->search}%")
                        ->orWhere('phone', 'LIKE', "%{$request->search}%")
                        ->orWhere('email', 'LIKE', "%{$request->search}%");
                });
            });
        $totalRows = $Filtred->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }
        $providers = $Filtred->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        // Date filter (Suppliers list date buttons): ?date_from=&date_to=
        // limit the purchases / returns figures below to documents dated in
        // that range -- every supplier stays listed. Empty = lifetime, as
        // before. due_all / return_due_all stay lifetime: Pay Due settles the
        // whole balance, not one period's.
        $from = $request->input('date_from');
        $to = $request->input('date_to');
        $inRange = fn ($q) => $q->when($from, fn ($q) => $q->whereDate('date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('date', '<=', $to));

        foreach ($providers as $provider) {

            $purchases = fn () => DB::table('purchases')
                ->where('deleted_at', '=', null)
                ->where('statut', 'received')
                ->where('provider_id', $provider->id);
            $returns = fn () => DB::table('purchase_returns')
                ->where('deleted_at', '=', null)
                ->where('provider_id', $provider->id);

            $item['total_amount'] = $inRange($purchases())->sum('GrandTotal');
            $item['total_paid'] = $inRange($purchases())->sum('paid_amount');
            $item['due'] = $item['total_amount'] - $item['total_paid'];
            $item['due_all'] = ($from || $to) ? $purchases()->sum('GrandTotal') - $purchases()->sum('paid_amount') : $item['due'];

            $item['total_amount_return'] = $inRange($returns())->sum('GrandTotal');
            $item['total_paid_return'] = $inRange($returns())->sum('paid_amount');
            $item['return_Due'] = $item['total_amount_return'] - $item['total_paid_return'];
            $item['return_due_all'] = ($from || $to) ? $returns()->sum('GrandTotal') - $returns()->sum('paid_amount') : $item['return_Due'];

            $item['id'] = $provider->id;
            $item['name'] = $provider->name;
            $item['phone'] = $provider->phone;
            $item['tax_number'] = $provider->tax_number;
            $item['code'] = $provider->code;
            $item['email'] = $provider->email;
            $item['country'] = $provider->country;
            $item['city'] = $provider->city;
            $item['adresse'] = $provider->adresse;
            $item['credit_limit'] = $provider->credit_limit ?? 0;
            $item['opening_balance'] = $provider->opening_balance ?? 0;
            $data[] = $item;
        }

        $company_info = Setting::where('deleted_at', '=', null)->first();
        $accounts = Account::where('deleted_at', '=', null)->orderBy('id', 'desc')->get(['id', 'account_name']);
        $payment_methods = PaymentMethod::active()->whereNull('deleted_at')->get(['id', 'name']);

        return response()->json([
            'providers' => $data,
            'company_info' => $company_info,
            'totalRows' => $totalRows,
            'accounts' => $accounts,
            'payment_methods' => $payment_methods,
        ]);
    }

    // ----------- Store new Supplier -------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Provider::class);

        request()->validate([
            'name' => 'required',
            'additional_emails' => ['nullable', 'array', 'max:10'],
            'additional_emails.*' => ['email', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:190'],
        ]);
        $provider = Provider::create([
            'name' => $request['name'],
            'code' => $this->getNumberOrder(),
            'adresse' => $request['adresse'],
            'phone' => $request['phone'],
            'email' => $request['email'],
            'additional_emails' => $this->normalizeAdditionalEmails($request->input('additional_emails'), $request['email']),
            'country' => $request['country'],
            'city' => $request['city'],
            'state' => $request['state'],
            'zip' => $request['zip'],
            'contact_name' => $request['contact_name'],
            'contact_email' => $request['contact_email'],
            'contact_phone' => $request['contact_phone'],
            'tax_number' => $request['tax_number'],
            'opening_balance' => $request['opening_balance'] ?? 0,
            'credit_limit' => $request['credit_limit'] ?? 0,
            // Company boundary: force from the acting company, never trust a
            // client-submitted value.
            'company_id' => $this->resolveActingCompanyId($request),
        ]);

        // Every supplier gets a matching users row (type=supplier, the
        // company's "Supplier" role) -- same as customers and employees.
        $provider->user_id = \App\Services\LinkedUserService::provision(
            (int) $provider->company_id, 'supplier', 'Supplier', $provider->name, $provider->email, $provider->phone
        );
        $provider->save();

        return response()->json([
            'success' => true,
            'id' => $provider->id,
            'provider' => $provider
        ]);

    }

    // ------------ function show -----------\\

    public function show($id)
    {
        $this->authorizeForUser(request()->user('api'), 'view', Provider::class);

        $provider = Provider::where('deleted_at', '=', null)->findOrFail($id);

        // Company boundary: a non-SuperAdmin can't open a supplier from a
        // different company.
        $requester = request()->user('api');
        if ($requester->type !== 'SuperAdmin') {
            abort_unless((int) $provider->company_id === (int) $requester->company_id, 403);
        }

        return response()->json([
            'provider' => $provider,
        ]);

    }

    // ----------- Update Supplier-------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Provider::class);

        request()->validate([
            'name' => 'required',
            'additional_emails' => ['nullable', 'array', 'max:10'],
            'additional_emails.*' => ['email', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:190'],
        ]);

        // Company boundary: a non-SuperAdmin can't update a supplier from a
        // different company.
        $requester = $request->user('api');
        if ($requester->type !== 'SuperAdmin') {
            $targetCompanyId = Provider::where('id', $id)->value('company_id');
            abort_unless((int) $targetCompanyId === (int) $requester->company_id, 403);
        }

        // Only touch additional_emails when the caller sent it -- older
        // callers of this endpoint don't know about the field.
        if ($request->has('additional_emails')) {
            $extra = $this->normalizeAdditionalEmails($request->input('additional_emails'), $request['email']);
            Provider::whereId($id)->update(['additional_emails' => $extra ? json_encode($extra) : null]);
        }

        Provider::whereId($id)->update([
            'name' => $request['name'],
            'adresse' => $request['adresse'],
            'phone' => $request['phone'],
            'email' => $request['email'],
            'country' => $request['country'],
            'city' => $request['city'],
            'state' => $request['state'],
            'zip' => $request['zip'],
            'contact_name' => $request['contact_name'],
            'contact_email' => $request['contact_email'],
            'contact_phone' => $request['contact_phone'],
            'tax_number' => $request['tax_number'],
            'credit_limit' => $request->input('credit_limit', 0),
        ]);

        return response()->json(['success' => true]);

    }

    // ----------- Adjust Supplier Opening Balance -------\\
    // Mirrors ClientController@adjustOpeningBalance: the update() above does not
    // touch opening_balance, so the edit form syncs it here.
    public function adjustOpeningBalance(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Provider::class);

        $request->validate([
            'mode' => 'required|in:add,subtract,set',
            'amount' => 'required|numeric',
        ]);

        $provider = Provider::findOrFail($id);

        // Company boundary: a non-SuperAdmin can't adjust a supplier's opening
        // balance from a different company.
        $requester = $request->user('api');
        if ($requester->type !== 'SuperAdmin') {
            abort_unless((int) $provider->company_id === (int) $requester->company_id, 403);
        }

        $current = (float) ($provider->opening_balance ?? 0);
        $amount = (float) $request->input('amount');

        switch ($request->input('mode')) {
            case 'add':
                $new = $current + abs($amount);
                break;
            case 'subtract':
                $new = $current - abs($amount);
                break;
            case 'set':
            default:
                $new = $amount;
                break;
        }

        $provider->opening_balance = round($new, 2);
        $provider->save();

        return response()->json([
            'success' => true,
            'previous_opening_balance' => round($current, 2),
            'opening_balance' => round((float) $provider->opening_balance, 2),
        ]);
    }

    // =========================================================================
    // Supplier Details (People scope) — gated by Suppliers_view (the `view`
    // ability), kept separate from the Reports_suppliers report endpoints.
    // =========================================================================

    // ----------- Supplier details summary -------\\
    public function supplierDetailsSummary(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Provider::class);

        $provider = Provider::where('deleted_at', '=', null)->findOrFail($id);

        // Company boundary: a non-SuperAdmin can't view supplier details from
        // a different company.
        $requester = $request->user('api');
        if ($requester->type !== 'SuperAdmin') {
            abort_unless((int) $provider->company_id === (int) $requester->company_id, 403);
        }

        $data['total_purchase'] = DB::table('purchases')
            ->where('deleted_at', '=', null)
            ->where('provider_id', $id)
            ->count();

        $data['total_amount'] = DB::table('purchases')
            ->where('deleted_at', '=', null)
            ->where('statut', 'received')
            ->where('provider_id', $id)
            ->sum('GrandTotal');

        $data['total_paid'] = DB::table('purchases')
            ->where('deleted_at', '=', null)
            ->where('statut', 'received')
            ->where('provider_id', $id)
            ->sum('paid_amount');

        $data['due'] = $data['total_amount'] - $data['total_paid'];
        $data['opening_balance'] = $provider->opening_balance ?? 0;
        $data['credit_limit'] = $provider->credit_limit ?? 0;
        $data['name'] = $provider->name;

        // Purchase-return due (same formula as the suppliers list) so the
        // details page can offer the same pay-due actions.
        $data['return_Due'] = DB::table('purchase_returns')
            ->where('deleted_at', '=', null)
            ->where('provider_id', $id)
            ->sum('GrandTotal')
            - DB::table('purchase_returns')
                ->where('deleted_at', '=', null)
                ->where('provider_id', $id)
                ->sum('paid_amount');

        return response()->json([
            'report' => $data,
            'accounts' => Account::where('deleted_at', '=', null)->orderBy('id', 'desc')->get(['id', 'account_name']),
            'payment_methods' => PaymentMethod::active()->whereNull('deleted_at')->get(['id', 'name']),
        ]);
    }

    // ----------- Supplier details: purchases -------\\
    public function supplierPurchases(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Provider::class);

        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $data = [];

        $ShowRecord = Auth::user()->hasRecordView();

        // ✅ record_view decides whose documents; the assigned warehouses
        // decide which warehouses they may come from. Both apply.
        $scopeIds = $this->warehouseScopeIds();

        // Company boundary: a non-SuperAdmin only ever sees purchases scoped to
        // their own company, regardless of the (client-supplied) provider_id.
        $requester = $request->user('api');
        $companyId = $this->resolveActingCompanyId($request);

        $purchases = Purchase::where('deleted_at', '=', null)
            ->when($requester->type !== 'SuperAdmin' || $request->filled('company_id'), fn ($q) => $q->where('company_id', $companyId))
            ->when($scopeIds !== null, fn ($q) => $q->whereIn('warehouse_id', $scopeIds))
            ->with('provider', 'warehouse')
            ->where('provider_id', $request->id)
            ->where(function ($query) use ($ShowRecord) {
                if (! $ShowRecord) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            })
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('Ref', 'LIKE', "%{$request->search}%")
                        ->orWhere('statut', 'LIKE', "%{$request->search}%")
                        ->orWhere('payment_statut', 'like', "$request->search")
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('provider', function ($q) use ($request) {
                                $q->where('name', 'LIKE', "%{$request->search}%");
                            });
                        })
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('warehouse', function ($q) use ($request) {
                                $q->where('name', 'LIKE', "%{$request->search}%");
                            });
                        });
                });
            });

        $totalRows = $purchases->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }
        $purchases = $purchases->offset($offSet)
            ->limit($perPage)
            ->orderBy('id', 'desc')
            ->get();

        foreach ($purchases as $purchase) {
            $item['id'] = $purchase->id;
            $item['Ref'] = $purchase->Ref;
            $item['warehouse_name'] = $purchase['warehouse']->name;
            $item['provider_name'] = $purchase['provider']->name;
            $item['statut'] = $purchase->statut;
            $item['GrandTotal'] = $purchase->GrandTotal;
            $item['paid_amount'] = $purchase->paid_amount;
            $item['due'] = $purchase->GrandTotal - $purchase->paid_amount;
            $item['payment_status'] = $purchase->payment_statut;

            $data[] = $item;
        }

        return response()->json([
            'totalRows' => $totalRows,
            'purchases' => $data,
        ]);
    }

    // ----------- Supplier details: payments -------\\
    public function supplierPayments(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Provider::class);

        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;

        $ShowRecord = Auth::user()->hasRecordView();

        // ✅ record_view decides whose documents; the assigned warehouses
        // decide which warehouses they may come from. Both apply.
        $scopeIds = $this->warehouseScopeIds();

        // Company boundary: a non-SuperAdmin only ever sees payments for
        // purchases scoped to their own company.
        $requester = $request->user('api');
        $companyId = $this->resolveActingCompanyId($request);

        $payments = DB::table('payment_purchases')
            ->when($requester->type !== 'SuperAdmin' || $request->filled('company_id'), fn ($q) => $q->where('purchases.company_id', $companyId))
            ->when($scopeIds !== null, fn ($q) => $q->whereIn('purchases.warehouse_id', $scopeIds))
            ->where(function ($query) use ($ShowRecord) {
                if (! $ShowRecord) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            })
            ->where('payment_purchases.deleted_at', '=', null)
            ->join('purchases', 'payment_purchases.purchase_id', '=', 'purchases.id')
            ->join('payment_methods', 'payment_purchases.payment_method_id', '=', 'payment_methods.id')
            ->where('purchases.provider_id', $request->id)
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('payment_purchases.Ref', 'LIKE', "%{$request->search}%")
                        ->orWhere('payment_purchases.date', 'LIKE', "%{$request->search}%")
                        ->orWhere('payment_methods.name', 'LIKE', "%{$request->search}%");
                });
            })
            ->select(
                'payment_purchases.date', 'payment_purchases.Ref AS Ref', 'purchases.Ref AS purchase_Ref',
                'payment_methods.name as payment_method', 'payment_purchases.montant'
            );

        $totalRows = $payments->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }
        $payments = $payments->offset($offSet)
            ->limit($perPage)
            ->orderBy('payment_purchases.id', 'desc')
            ->get();

        return response()->json([
            'payments' => $payments,
            'totalRows' => $totalRows,
        ]);
    }

    // ----------- Supplier details: returns -------\\
    public function supplierReturns(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Provider::class);

        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $data = [];

        $ShowRecord = Auth::user()->hasRecordView();

        // ✅ record_view decides whose documents; the assigned warehouses
        // decide which warehouses they may come from. Both apply.
        $scopeIds = $this->warehouseScopeIds();

        // Company boundary: purchase_returns now has its own company_id column
        // (2026_09_22_100000_add_company_id_to_purchase_returns_table), so scope
        // directly on it instead of the old indirect provider-company check.
        $companyId = $this->resolveActingCompanyId($request);

        $PurchaseReturn = PurchaseReturn::where('deleted_at', '=', null)
            ->where('company_id', $companyId)
            ->when($scopeIds !== null, fn ($q) => $q->whereIn('warehouse_id', $scopeIds))
            ->with('purchase', 'provider', 'warehouse')
            ->where('provider_id', $request->id)
            ->where(function ($query) use ($ShowRecord) {
                if (! $ShowRecord) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            })
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('Ref', 'LIKE', "%{$request->search}%")
                        ->orWhere('statut', 'LIKE', "%{$request->search}%")
                        ->orWhere('payment_statut', 'like', "$request->search")
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('provider', function ($q) use ($request) {
                                $q->where('name', 'LIKE', "%{$request->search}%");
                            });
                        })
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('purchase', function ($q) use ($request) {
                                $q->where('Ref', 'LIKE', "%{$request->search}%");
                            });
                        })
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('warehouse', function ($q) use ($request) {
                                $q->where('name', 'LIKE', "%{$request->search}%");
                            });
                        });
                });
            });

        $totalRows = $PurchaseReturn->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }
        $PurchaseReturn = $PurchaseReturn->offset($offSet)
            ->limit($perPage)
            ->orderBy('id', 'desc')
            ->get();

        foreach ($PurchaseReturn as $Purchase_Return) {
            $item['id'] = $Purchase_Return->id;
            $item['Ref'] = $Purchase_Return->Ref;
            $item['statut'] = $Purchase_Return->statut;
            $item['purchase_ref'] = $Purchase_Return['purchase'] ? $Purchase_Return['purchase']->Ref : '---';
            $item['purchase_id'] = $Purchase_Return['purchase'] ? $Purchase_Return['purchase']->id : null;
            $item['provider_name'] = $Purchase_Return['provider']->name;
            $item['warehouse_name'] = $Purchase_Return['warehouse']->name;
            $item['GrandTotal'] = $Purchase_Return->GrandTotal;
            $item['paid_amount'] = $Purchase_Return->paid_amount;
            $item['due'] = $Purchase_Return->GrandTotal - $Purchase_Return->paid_amount;
            $item['payment_status'] = $Purchase_Return->payment_statut;

            $data[] = $item;
        }

        return response()->json([
            'totalRows' => $totalRows,
            'returns_supplier' => $data,
        ]);
    }

    // ----------- Remdeleteove Provider-------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Provider::class);

        // Company boundary: a non-SuperAdmin can't delete a supplier from a
        // different company.
        $requester = $request->user('api');
        if ($requester->type !== 'SuperAdmin') {
            $targetCompanyId = Provider::where('id', $id)->value('company_id');
            abort_unless((int) $targetCompanyId === (int) $requester->company_id, 403);
        }

        Provider::whereId($id)->update([
            'deleted_at' => Carbon::now(),
        ]);

        return response()->json(['success' => true]);

    }

    // -------------- Delete by selection  ---------------\\

    public function delete_by_selection(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'delete', Provider::class);

        $selectedIds = $request->selectedIds;

        // Company boundary: a non-SuperAdmin can only bulk-delete suppliers
        // belonging to their own company -- ids outside it are silently
        // dropped from the batch rather than trusted from the request.
        $requester = $request->user('api');
        if ($requester->type !== 'SuperAdmin') {
            $selectedIds = Provider::whereIn('id', $selectedIds)->where('company_id', $requester->company_id)->pluck('id')->all();
        }

        foreach ($selectedIds as $Provider_id) {
            Provider::whereId($Provider_id)->update([
                'deleted_at' => Carbon::now(),
            ]);
        }

        return response()->json(['success' => true]);
    }

    // ----------- get Number Order Of Suppliers-------\\

    public function getNumberOrder()
    {

        $last = DB::table('providers')->latest('id')->first();

        if ($last) {
            $code = $last->code + 1;
        } else {
            $code = 1;
        }

        return $code;
    }

    // ----------- get Ref Order Of Supplier Opening Balance Payments -------\\

    public function getOpeningBalancePaymentNumberOrder()
    {
        $last = DB::table('provider_opening_balance_payments')->latest('id')->first();

        if ($last) {
            $item = $last->Ref;
            $nwMsg = explode('_', $item);
            $lastPart = end($nwMsg);
            $prefix = implode('_', array_slice($nwMsg, 0, -1));
            $inMsg = (int) $lastPart + 1;
            $code = $prefix . '_' . $inMsg;
        } else {
            $code = 'SUP_OB_PAY_1111';
        }

        return $code;
    }

    public function import(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Suppliers_import', Provider::class);

        // 1) File-level validation
        $v = Validator::make($request->all(), [
            'suppliers' => 'required|file|mimes:xls,xlsx|max:20480', // 20MB
        ]);
        if ($v->fails()) {
            return response()->json([
                'status' => false,
                // intentionally omit "message" to avoid leaking "Validation failed"
                'errors' => $v->errors()->all(),
            ], 422);
        }

        $rows = Excel::toArray([], $request->file('suppliers'));
        $sheet = $rows[0] ?? [];
        if (empty($sheet)) {
            return response()->json([
                'status' => false,
                'errors' => ['No data found in the uploaded file.'],
            ], 422);
        }

        // Header detection
        $first = $sheet[0] ?? [];
        $assocInput = is_array($first) && count(array_filter(array_keys($first), 'is_string')) > 0;

        $normalized = [];
        if ($assocInput) {
            foreach ($sheet as $r) {
                $normalized[] = $this->normalizeAssocRowSupplier($r);
            }
            $lineBase = 1; // header already applied in source
        } else {
            $header = array_map(function ($h) {
                return $this->normalizeKeySupplier((string) $h);
            }, $first);
            for ($i = 1; $i < count($sheet); $i++) {
                $row = $sheet[$i];
                $assoc = [];
                foreach ($header as $idx => $key) {
                    $assoc[$key] = $row[$idx] ?? null;
                }
                $normalized[] = $this->normalizeAssocRowSupplier($assoc);
            }
            $lineBase = 2; // data start line if first row was header labels
        }

        $errors = [];
        $prepared = [];
        $codesInFile = [];

        foreach ($normalized as $i => $row) {
            $line = $i + $lineBase;

            $name = isset($row['name']) ? trim((string) $row['name']) : '';
            $codeRaw = array_key_exists('code', $row) ? $row['code'] : null;
            $email = isset($row['email']) ? trim((string) $row['email']) : '';
            $phone = isset($row['phone']) ? trim((string) $row['phone']) : '';
            $country = isset($row['country']) ? trim((string) $row['country']) : '';
            $city = isset($row['city']) ? trim((string) $row['city']) : '';
            $adresse = isset($row['adresse']) ? trim((string) $row['adresse']) : '';
            $tax_number = isset($row['tax_number']) ? trim((string) $row['tax_number']) : '';
            $opening_balance = isset($row['opening_balance']) && is_numeric($row['opening_balance']) ? (float) $row['opening_balance'] : 0;
            $credit_limit = isset($row['credit_limit']) && is_numeric($row['credit_limit']) ? (float) $row['credit_limit'] : 0;

            if ($name === '') {
                $errors[] = "Row {$line}: name is required.";
            }

            // code must be integer
            $code = null;
            if ($codeRaw === null || $codeRaw === '') {
                $errors[] = "Row {$line}: code is required and must be an integer.";
            } elseif (is_numeric($codeRaw) && intval($codeRaw) == $codeRaw) {
                $code = intval($codeRaw);
            } else {
                $errors[] = "Row {$line}: code '{$codeRaw}' is not a valid integer.";
            }

            if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Row {$line}: email '{$email}' is not valid.";
            }

            // in-file duplicate codes
            if ($code !== null) {
                if (isset($codesInFile[$code])) {
                    $errors[] = "Row {$line}: duplicate code '{$code}' found in the file (also on row {$codesInFile[$code]}).";
                } else {
                    $codesInFile[$code] = $line;
                }
            }

            $prepared[] = [
                'name' => $name,
                'code' => $code,
                'email' => $email ?: null,
                'phone' => $phone ?: null,
                'country' => $country ?: null,
                'city' => $city ?: null,
                'adresse' => $adresse ?: null,
                'tax_number' => $tax_number ?: null,
                'opening_balance' => $opening_balance,
                'credit_limit' => $credit_limit,
            ];
        }

        // Company boundary: imported suppliers belong to the acting company;
        // the duplicate-code check below is scoped the same way, since a code
        // reused in another company is not actually a conflict here.
        $companyId = $this->resolveActingCompanyId($request);

        // DB duplicate codes
        $codes = array_values(array_filter(array_map(function ($r) {
            return $r['code'];
        }, $prepared), function ($v) {
            return $v !== null;
        }));
        if (! empty($codes)) {
            $dupes = Provider::whereNull('deleted_at')->where('company_id', $companyId)->whereIn('code', $codes)->pluck('code')->all();
            foreach ($dupes as $dup) {
                $errors[] = "code '{$dup}' already exists in the system.";
            }
        }

        if (! empty($errors)) {
            return response()->json([
                'status' => false,
                'errors' => $errors, // ONLY errors[]
            ], 422);
        }

        // Insert suppliers
        $now = now();
        $insertRows = [];
        foreach ($prepared as $r) {
            $insertRows[] = [
                'name' => $r['name'],
                'code' => $r['code'],
                'email' => $r['email'],
                'phone' => $r['phone'],
                'country' => $r['country'],
                'city' => $r['city'],
                'adresse' => $r['adresse'],
                'tax_number' => $r['tax_number'],
                'opening_balance' => $r['opening_balance'],
                'credit_limit' => $r['credit_limit'],
                'company_id' => $companyId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($insertRows, $companyId, $now) {
            foreach (array_chunk($insertRows, 1000) as $chunk) {
                Provider::insert($chunk);
            }

            // Linked users for the suppliers just inserted (same as store()):
            // they are this company's rows stamped with this import's time.
            $imported = Provider::where('company_id', $companyId)->whereNull('user_id')
                ->where('created_at', $now)->get();
            foreach ($imported as $provider) {
                $provider->user_id = \App\Services\LinkedUserService::provision(
                    $companyId, 'supplier', 'Supplier', $provider->name, $provider->email, $provider->phone
                );
                $provider->save();
            }
        });

        return response()->json([
            'status' => true,
            'imported' => count($insertRows),
            // optionally: 'warnings' => [...]
        ]);
    }

    // --- helpers (private methods in the same controller) ---
    private function normalizeAssocRowSupplier(array $row): array
    {
        $out = [];
        foreach ($row as $k => $v) {
            $key = $this->normalizeKeySupplier((string) $k);
            $out[$this->resolveSupplierSynonym($key)] = $v;
        }

        return $out;
    }

    private function normalizeKeySupplier(string $key): string
    {
        $key = trim(mb_strtolower($key));

        return preg_replace('/[.\s\-]+/u', '_', $key);
    }

    private function resolveSupplierSynonym(string $key): string
    {
        $map = [
            'supplier' => 'name',
            'vendor' => 'name',
            'supplier_name' => 'name',
            'vendor_name' => 'name',

            'supplier_code' => 'code',
            'vendor_code' => 'code',

            'address' => 'adresse',
            'addr' => 'adresse',

            'tax' => 'tax_number',
            'taxno' => 'tax_number',
            'tax_no' => 'tax_number',
            'vat' => 'tax_number',

            'openingbalance' => 'opening_balance',
            'opening_bal' => 'opening_balance',
            'opening_balances' => 'opening_balance',
            'previous_due' => 'opening_balance',
            'previous_dues' => 'opening_balance',

            'creditlimit' => 'credit_limit',
            'credit' => 'credit_limit',
        ];

        return isset($map[$key]) ? $map[$key] : $key;
    }

    // ------------- pay_supplier_due -------------\\

    public function pay_supplier_due(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'pay_supplier_due', Provider::class);

        if ($request['amount'] > 0) {
            $provider = Provider::findOrFail($request->provider_id);

            // Company boundary: a non-SuperAdmin can't pay down another
            // company's supplier.
            $requester = $request->user('api');
            if ($requester->type !== 'SuperAdmin') {
                abort_unless((int) $provider->company_id === (int) $requester->company_id, 403);
            }

            $paid_amount_total = $request->amount;

            // Pay opening balance first (only when this supplier has one).
            // Suppliers created before this feature have opening_balance = 0,
            // so this block is skipped and the original purchase-payment flow runs unchanged.
            if ($provider->opening_balance > 0 && $paid_amount_total > 0) {
                if ($paid_amount_total >= $provider->opening_balance) {
                    $opening_balance_paid = $provider->opening_balance;
                    $paid_amount_total -= $provider->opening_balance;
                    $provider->opening_balance = 0;
                } else {
                    $opening_balance_paid = $paid_amount_total;
                    $provider->opening_balance -= $paid_amount_total;
                    $paid_amount_total = 0;
                }
                $provider->save();

                $payment_opening_balance = new ProviderPaymentOpeningBalance;
                $payment_opening_balance->provider_id = $request->provider_id;
                $payment_opening_balance->account_id = $request['account_id'] ? $request['account_id'] : null;
                $payment_opening_balance->Ref = $this->getOpeningBalancePaymentNumberOrder();
                $payment_opening_balance->date = Carbon::now();
                $payment_opening_balance->payment_method_id = $request['payment_method_id'];
                $payment_opening_balance->montant = $opening_balance_paid;
                $payment_opening_balance->change = 0;
                $payment_opening_balance->notes = $request['notes'];
                $payment_opening_balance->user_id = Auth::user()->id;
                $payment_opening_balance->save();

                $account = Account::where('id', $request['account_id'])->exists();
                if ($account) {
                    $account = Account::find($request['account_id']);
                    $account->update([
                        'balance' => $account->balance - $opening_balance_paid,
                    ]);
                }
            }

            $provider_purchases_due = Purchase::where('deleted_at', '=', null)
                ->where('company_id', $provider->company_id)
                ->where('statut', 'received')
                ->where([
                    ['payment_statut', '!=', 'paid'],
                    ['provider_id', $request->provider_id],
                ])->get();

            foreach ($provider_purchases_due as $key => $provider_purchase) {
                if ($paid_amount_total == 0) {
                    break;
                }
                $due = $provider_purchase->GrandTotal - $provider_purchase->paid_amount;

                if ($paid_amount_total >= $due) {
                    $amount = $due;
                    $payment_status = 'paid';
                } else {
                    $amount = $paid_amount_total;
                    $payment_status = 'partial';
                }

                $payment_purchase = new PaymentPurchase;
                $payment_purchase->purchase_id = $provider_purchase->id;
                $payment_purchase->account_id = $request['account_id'] ? $request['account_id'] : null;
                $payment_purchase->Ref = app('App\Http\Controllers\PaymentPurchasesController')->getNumberOrder();
                $payment_purchase->date = Carbon::now();
                $payment_purchase->payment_method_id = $request['payment_method_id'];
                $payment_purchase->montant = $amount;
                $payment_purchase->change = 0;
                $payment_purchase->notes = $request['notes'];
                $payment_purchase->user_id = Auth::user()->id;
                $payment_purchase->save();

                $account = Account::where('id', $request['account_id'])->exists();

                if ($account) {
                    // Account exists, perform the update
                    $account = Account::find($request['account_id']);
                    $account->update([
                        'balance' => $account->balance - $amount,
                    ]);
                }

                $provider_purchase->paid_amount += $amount;
                $provider_purchase->payment_statut = $payment_status;
                $provider_purchase->save();

                $paid_amount_total -= $amount;
            }
        }

        return response()->json(['success' => true]);

    }

    // ------------- pay_purchase_return_due -------------\\

    public function pay_purchase_return_due(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'pay_purchase_return_due', Provider::class);

        if ($request['amount'] > 0) {
            $provider = Provider::findOrFail($request->provider_id);

            // Company boundary: a non-SuperAdmin can't pay down another
            // company's supplier's purchase returns. purchase_returns now has
            // its own company_id column (2026_09_22_100000_add_company_id_to_
            // purchase_returns_table), so scope the fetched rows directly on
            // it rather than only gating via the provider.
            $companyId = $this->resolveActingCompanyId($request);
            $requester = $request->user('api');
            if ($requester->type !== 'SuperAdmin') {
                abort_unless((int) $provider->company_id === (int) $requester->company_id, 403);
            }

            $supplier_purchase_return_due = PurchaseReturn::where('deleted_at', '=', null)
                ->where('company_id', $companyId)
                ->where([
                    ['payment_statut', '!=', 'paid'],
                    ['provider_id', $request->provider_id],
                ])->get();

            $paid_amount_total = $request->amount;

            foreach ($supplier_purchase_return_due as $key => $supplier_purchase_return) {
                if ($paid_amount_total == 0) {
                    break;
                }
                $due = $supplier_purchase_return->GrandTotal - $supplier_purchase_return->paid_amount;

                if ($paid_amount_total >= $due) {
                    $amount = $due;
                    $payment_status = 'paid';
                } else {
                    $amount = $paid_amount_total;
                    $payment_status = 'partial';
                }

                $payment_purchase_return = new PaymentPurchaseReturns;
                $payment_purchase_return->purchase_return_id = $supplier_purchase_return->id;
                $payment_purchase_return->account_id = $request['account_id'] ? $request['account_id'] : null;
                $payment_purchase_return->Ref = app('App\Http\Controllers\PaymentPurchaseReturnsController')->getNumberOrder();
                $payment_purchase_return->date = Carbon::now();
                $payment_purchase_return->payment_method_id = $request['payment_method_id'];
                $payment_purchase_return->montant = $amount;
                $payment_purchase_return->change = 0;
                $payment_purchase_return->notes = $request['notes'];
                $payment_purchase_return->user_id = Auth::user()->id;
                $payment_purchase_return->save();

                $account = Account::where('id', $request['account_id'])->exists();

                if ($account) {
                    // Account exists, perform the update
                    $account = Account::find($request['account_id']);
                    $account->update([
                        'balance' => $account->balance + $amount,
                    ]);
                }

                $supplier_purchase_return->paid_amount += $amount;
                $supplier_purchase_return->payment_statut = $payment_status;
                $supplier_purchase_return->save();

                $paid_amount_total -= $amount;
            }
        }

        return response()->json(['success' => true]);

    }
}
