<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Models\Setting;
use App\utils\helpers;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CurrencyController extends Controller
{
    // ------------ GET ALL Currency -----------\\

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Currency::class);
        // How many items do you want to display.
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = $request->SortType;
        $helpers = new helpers;

        $currencies = Currency::where('deleted_at', '=', null)

        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('name', 'LIKE', "%{$request->search}%")
                        ->orWhere('code', 'LIKE', "%{$request->search}%");
                });
            });
        $totalRows = $currencies->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }
        $currencies = $currencies->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        return response()->json([
            'currencies' => $currencies,
            'totalRows' => $totalRows,
            // The default currency lives in the general settings; expose its id
            // so the list can show/toggle which currency is the default.
            'default_currency_id' => optional(Setting::first())->currency_id,
        ]);
    }

    // -------- Set this currency as the system default (settings.currency_id) --------\\

    public function setDefault(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Currency::class);

        $currency = Currency::where('deleted_at', '=', null)->findOrFail($id);

        $setting = Setting::first();
        if ($setting) {
            $setting->currency_id = $currency->id;
            $setting->save();
        }

        // The base currency's rate is 1 by definition. Other rates are NOT
        // auto-rebased — the admin is warned in the UI to review them.
        if (($currency->exchange_rate ?? 1) != 1) {
            $currency->exchange_rate = 1;
            $currency->save();
        }

        return response()->json(['success' => true, 'default_currency_id' => $currency->id]);
    }

    // ---------------- STORE NEW Currency -------------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Currency::class);

        request()->validate([
            'code' => 'required',
            'name' => 'required',
            'symbol' => 'required',
            'exchange_rate' => 'nullable|numeric|gt:0',
        ]);

        Currency::create([
            'name' => $request['name'],
            'code' => $request['code'],
            'symbol' => $request['symbol'],
            // Rate = units of this currency per 1 base-currency unit
            'exchange_rate' => (float) ($request['exchange_rate'] ?? 1) ?: 1,
        ]);

        return response()->json(['success' => true]);

    }

    // ------------ function show -----------\\

    public function show($id)
    {
        //

    }

    // ---------------- UPDATE Currency -------------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Currency::class);

        request()->validate([
            'code' => 'required',
            'name' => 'required',
            'symbol' => 'required',
            'exchange_rate' => 'nullable|numeric|gt:0',
        ]);

        // The base currency (settings.currency_id) always keeps rate 1 — every
        // other rate is expressed relative to it.
        $isDefault = (int) optional(Setting::first())->currency_id === (int) $id;
        $rate = $isDefault ? 1 : ((float) ($request['exchange_rate'] ?? 1) ?: 1);

        Currency::whereId($id)->update([
            'name' => $request['name'],
            'code' => $request['code'],
            'symbol' => $request['symbol'],
            'exchange_rate' => $rate,
        ]);

        return response()->json(['success' => true]);

    }

    // ------------ Delete Currency -----------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Currency::class);

        Currency::whereId($id)->update([
            'deleted_at' => Carbon::now(),
        ]);

        return response()->json(['success' => true]);
    }

    // -------------- Delete by selection  ---------------\\

    public function delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Currency::class);
        $selectedIds = $request->selectedIds;

        foreach ($selectedIds as $Currency_id) {
            Currency::whereId($Currency_id)->update([
                'deleted_at' => Carbon::now(),
            ]);
        }

        return response()->json(['success' => true]);
    }

    // ------------ GET ALL Currency WITHOUT PAGINATE -----------\\

    public function Get_Currencies()
    {
        // Used by document forms / POS for the Multi-Currency picker; no
        // `currency` permission required (any authenticated user may read it).
        $Currencies = Currency::where('deleted_at', null)
            ->get(['id', 'name', 'code', 'symbol', 'exchange_rate']);

        return response()->json([
            'currencies' => $Currencies,
            'default_currency_id' => optional(Setting::first())->currency_id,
            'enabled' => helpers::multi_currency_enabled(),
        ]);
    }
}
