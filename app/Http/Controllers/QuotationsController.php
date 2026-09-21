<?php

namespace App\Http\Controllers;

use App\Mail\CustomEmail;
use App\Models\Client;
use App\Models\EmailMessage;
use App\Models\Product;
use App\Models\product_warehouse;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\QuotationDetail;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\sms_gateway;
use App\Models\SMSMessage;
use App\Models\Unit;
use App\Models\User;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use App\Services\BatchService;
use App\utils\helpers;
use ArPHP\I18N\Arabic;
use Carbon\Carbon;
use DB;
use GuzzleHttp\Client as Client_guzzle;
use GuzzleHttp\Client as Client_termi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Infobip\Api\SendSmsApi;
use Infobip\Configuration;
use Infobip\Model\SmsAdvancedTextualRequest;
use Infobip\Model\SmsDestination;
use Infobip\Model\SmsTextualMessage;
use PDF;
use Twilio\Rest\Client as Client_Twilio;

class QuotationsController extends BaseController
{
    // ---------------- GET ALL QUOTATIONS ---------------\\
    public function index(request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Quotation::class);
        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();
        $is_all_warehouses = $user->is_all_warehouses;
        // If the user is restricted, fetch their assigned warehouse IDs once and reuse below.
        if (! $is_all_warehouses) {
            $warehouse_ids = UserWarehouse::where('user_id', $user->id)
                ->pluck('warehouse_id')
                ->toArray();
        }

        // How many items do you want to display.
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = $request->SortType;
        $helpers = new helpers;
        // Filter fields With Params to retrieve
        $param = [
            0 => 'like',
            1 => 'like',
            2 => '=',
            3 => '=',
            4 => '=',
        ];
        $columns = [
            0 => 'Ref',
            1 => 'statut',
            2 => 'client_id',
            3 => 'date',
            4 => 'warehouse_id',
        ];
        $data = [];

        // Check If User Has Permission View  All Records
        $Quotations = Quotation::with('client', 'warehouse')
            ->where('deleted_at', '=', null)
            ->where(function ($query) use ($view_records) {
                if (! $view_records) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            });

        if (! $is_all_warehouses) {
            $Quotations->whereIn('warehouse_id', $warehouse_ids);
        }

        // Multiple Filter
        $Filtred = $helpers->filter($Quotations, $columns, $param, $request)
        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('Ref', 'LIKE', "%{$request->search}%")
                        ->orWhere('statut', 'LIKE', "%{$request->search}%")
                        ->orWhere('GrandTotal', $request->search)
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('client', function ($q) use ($request) {
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

        $totalRows = $Filtred->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }

        // Global sums over the SAME filtered set (all pages) for the summary cards.
        $sums = (clone $Filtred)
            ->selectRaw("COALESCE(SUM(GrandTotal),0) as grand, SUM(statut = 'sent') as sent, SUM(statut = 'pending') as pending")
            ->reorder()
            ->first();
        $stats = [
            'count' => $totalRows,
            'total' => (float) ($sums->grand ?? 0),
            'sent' => (int) ($sums->sent ?? 0),
            'pending' => (int) ($sums->pending ?? 0),
        ];

        $Quotations = $Filtred->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        // Converted-quotation flags: resolve the linked sales' refs in one query;
        // a link whose sale was deleted counts as not converted (re-convertible).
        $convertedIds = $Quotations->pluck('converted_sale_id')->filter()->unique()->values()->all();
        $convertedRefs = ! empty($convertedIds)
            ? Sale::whereIn('id', $convertedIds)->whereNull('deleted_at')->pluck('Ref', 'id')
            : collect();

        foreach ($Quotations as $Quotation) {

            $item['id'] = $Quotation->id;
            $item['date'] = $Quotation['date'].' '.$Quotation['time'];
            $item['Ref'] = $Quotation->Ref;
            $item['statut'] = $Quotation->statut;
            $item['warehouse_name'] = $Quotation['warehouse']->name;
            $item['client_name'] = $Quotation['client']->name;
            $item['client_email'] = $Quotation['client']->email;
            $item['GrandTotal'] = $Quotation->GrandTotal;

            $convertedSaleId = $Quotation->converted_sale_id ?? null;
            $item['converted_sale_id'] = $convertedSaleId && isset($convertedRefs[$convertedSaleId]) ? $convertedSaleId : null;
            $item['converted_sale_ref'] = $convertedSaleId ? ($convertedRefs[$convertedSaleId] ?? null) : null;

            $data[] = $item;
        }

        $customers = client::where('deleted_at', '=', null)->get();

        // get warehouses assigned to user
        $user_auth = auth()->user();
        if ($user_auth->is_all_warehouses) {
            $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
        } else {
            $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)->pluck('warehouse_id')->toArray();
            $warehouses = Warehouse::where('deleted_at', '=', null)->whereIn('id', $warehouses_id)->get(['id', 'name']);
        }

        return response()->json([
            'totalRows' => $totalRows,
            'quotations' => $data,
            'stats' => $stats,
            'customers' => $customers,
            'warehouses' => $warehouses,
        ]);
    }

    // ------------ Store new Quotation -------------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Quotation::class);

        request()->validate([
            'client_id' => 'required',
            'warehouse_id' => 'required',
        ]);

        \DB::transaction(function () use ($request) {

            $order = new Quotation;

            $order->date = $request->date;
            $order->time = now()->toTimeString();
            $order->Ref = $this->getNumberOrder();
            $order->statut = $request->statut;
            $order->client_id = $request->client_id;
            $order->GrandTotal = $request->GrandTotal;
            $order->warehouse_id = $request->warehouse_id;
            $order->tax_rate = $request->tax_rate;
            $order->TaxNet = $request->TaxNet;
            $order->discount = $request->Discount;
            $order->shipping = $request->shipping;
            $order->notes = $request->notes;
            $order->user_id = Auth::user()->id;
            // Multi-Currency snapshot (NULL/NULL = base currency)
            $docCurrency = helpers::resolve_request_currency($request);
            $order->currency_id = $docCurrency['currency_id'];
            $order->exchange_rate = $docCurrency['exchange_rate'];

            $order->save();

            $data = $request['details'];

            $persistedDetails = [];
            foreach ($data as $key => $value) {
                $unit = Unit::where('id', $value['sale_unit_id'])->first();

                $persistedDetails[$key] = QuotationDetail::create([
                    'quotation_id' => $order->id,
                    'quantity' => $value['quantity'],
                    'sale_unit_id' => $value['sale_unit_id'] ? $value['sale_unit_id'] : null,
                    'price' => $value['Unit_price'],
                    'TaxNet' => $value['tax_percent'],
                    'tax_method' => $value['tax_method'],
                    'discount' => $value['discount'],
                    'discount_method' => $value['discount_Method'],
                    'product_id' => $value['product_id'],
                    'product_variant_id' => $value['product_variant_id'] ? $value['product_variant_id'] : null,
                    'total' => $value['subtotal'],
                    'imei_number' => $value['imei_number'],
                ]);
            }

            // Pharmacy: record user-picked batches against the quotation. No stock
            // movement here — quotations are proposals; the conversion to a sale
            // is what actually debits batches.
            $batchService = app(BatchService::class);
            if ($batchService->isSupported()) {
                $batchService->assignForQuotationWithAutoFallback(
                    $order,
                    array_values($data),
                    $persistedDetails
                );
            }
        }, 10);

        return response()->json(['success' => true]);
    }

    // ------------ Update Quotation -------------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Quotation::class);

        request()->validate([
            'warehouse_id' => 'required',
            'client_id' => 'required',
        ]);

        \DB::transaction(function () use ($request, $id) {
            $user = Auth::user();
            // New way: Check user's record_view field (user-level boolean)
            // Backward compatibility: If record_view is null, fall back to role permission check
            $view_records = $user->hasRecordView();
            $current_Quotation = Quotation::findOrFail($id);

             /**
             * Warehouses restriction
             * Allow if:
             * - user has access to all warehouses (is_all_warehouses = 1)
             * - OR sale warehouse_id is in user's assigned warehouses
            */
            $user_auth = auth()->user();

            if (! $user_auth->is_all_warehouses) {
                $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)
                    ->pluck('warehouse_id')
                    ->toArray();

                if (empty($current_Quotation->warehouse_id) || ! in_array($current_Quotation->warehouse_id, $warehouses_id)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You are not allowed to access this sale (warehouse restriction).',
                    ], 403);
                }
            }

            // Check If User Has Permission view All Records
            // Warehouse half of the same rule: record_view says whose documents,
            // the assigned warehouses say which warehouses they may come from.
            $this->abortIfDocumentWarehouseDenied($current_Quotation);

            if (! $view_records) {
                // Check If User->id === Quotation->id
                $this->authorizeForUser($request->user('api'), 'check_record', $current_Quotation);
            }

            $old_quotation_details = QuotationDetail::where('quotation_id', $id)->get();
            $new_quotation_details = $request['details'];
            $length = count($new_quotation_details);

            // Get Ids details
            $new_details_id = [];
            foreach ($new_quotation_details as $new_detail) {
                $new_details_id[] = $new_detail['id'];
            }

            // Pharmacy: clear old quotation-batch picks before re-applying the new
            // ones. No stock to reverse since quotations don't move stock.
            $batchService = app(BatchService::class);
            if ($batchService->isSupported()) {
                $batchService->clearForQuotationDetails($old_quotation_details);
            }

            // Init quotation with old Parametre
            $old_detail_id = [];
            foreach ($old_quotation_details as $key => $value) {
                $old_detail_id[] = $value->id;

                // Delete Detail
                if (! in_array($old_detail_id[$key], $new_details_id)) {
                    $QuotationDetail = QuotationDetail::findOrFail($value->id);
                    $QuotationDetail->delete();
                }

            }

            // Update quotation with New request
            $newPersistedDetails = [];
            foreach ($new_quotation_details as $key => $product_detail) {

                $QuoteDetail['quotation_id'] = $id;
                $QuoteDetail['quantity'] = $product_detail['quantity'];
                $QuoteDetail['sale_unit_id'] = $product_detail['sale_unit_id'];
                $QuoteDetail['product_id'] = $product_detail['product_id'];
                $QuoteDetail['product_variant_id'] = $product_detail['product_variant_id'];
                $QuoteDetail['price'] = $product_detail['Unit_price'];
                $QuoteDetail['TaxNet'] = $product_detail['tax_percent'];
                $QuoteDetail['tax_method'] = $product_detail['tax_method'];
                $QuoteDetail['discount'] = $product_detail['discount'];
                $QuoteDetail['discount_method'] = $product_detail['discount_Method'];
                $QuoteDetail['total'] = $product_detail['subtotal'];
                $QuoteDetail['imei_number'] = $product_detail['imei_number'];

                if (! in_array($product_detail['id'], $old_detail_id)) {
                    $persistedDetail = QuotationDetail::Create($QuoteDetail);
                } else {
                    QuotationDetail::where('id', $product_detail['id'])->update($QuoteDetail);
                    $persistedDetail = QuotationDetail::find($product_detail['id']);
                }
                $newPersistedDetails[$key] = $persistedDetail;
            }

            // Pharmacy: re-assign batches against the freshly persisted detail rows.
            if ($batchService->isSupported()) {
                $alignedInput = [];
                $alignedPersisted = [];
                foreach ($new_quotation_details as $key => $product_detail) {
                    if (isset($newPersistedDetails[$key])) {
                        $alignedInput[] = $product_detail;
                        $alignedPersisted[] = $newPersistedDetails[$key];
                    }
                }
                $batchService->assignForQuotationWithAutoFallback(
                    $current_Quotation,
                    $alignedInput,
                    $alignedPersisted
                );
            }

            $current_Quotation->update([
                'client_id' => $request['client_id'],
                'warehouse_id' => $request['warehouse_id'],
                'statut' => $request['statut'],
                'notes' => $request['notes'],
                'tax_rate' => $request['tax_rate'],
                'TaxNet' => $request['TaxNet'],
                'date' => $request['date'],
                'discount' => $request['discount'],
                'shipping' => $request['shipping'],
                'GrandTotal' => $request['GrandTotal'],
            ] + helpers::resolve_request_currency($request, $current_Quotation->currency_id, $current_Quotation->exchange_rate));

        }, 10);

        return response()->json(['success' => true]);
    }

    // ------------ Delete Quotation -------------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Quotation::class);

        \DB::transaction(function () use ($id, $request) {

            $user = Auth::user();
            // New way: Check user's record_view field (user-level boolean)
            // Backward compatibility: If record_view is null, fall back to role permission check
            $view_records = $user->hasRecordView();
            $Quotation = Quotation::findOrFail($id);

             /**
             * Warehouses restriction
             * Allow if:
             * - user has access to all warehouses (is_all_warehouses = 1)
             * - OR sale warehouse_id is in user's assigned warehouses
            */
            $user_auth = auth()->user();

            if (! $user_auth->is_all_warehouses) {
                $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)
                    ->pluck('warehouse_id')
                    ->toArray();

                if (empty($Quotation->warehouse_id) || ! in_array($Quotation->warehouse_id, $warehouses_id)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You are not allowed to access this sale (warehouse restriction).',
                    ], 403);
                }
            }

            // Check If User Has Permission view All Records
            // Warehouse half of the same rule: record_view says whose documents,
            // the assigned warehouses say which warehouses they may come from.
            $this->abortIfDocumentWarehouseDenied($Quotation);

            if (! $view_records) {
                // Check If User->id === Quotation->id
                $this->authorizeForUser($request->user('api'), 'check_record', $Quotation);
            }

            // Pharmacy: clear quotation-batch picks before soft-deleting the
            // quotation. No stock to reverse — quotations don't move stock.
            $batchService = app(BatchService::class);
            if ($batchService->isSupported()) {
                $batchService->clearForQuotationDetails(
                    QuotationDetail::where('quotation_id', $id)->get()
                );
            }

            $Quotation->details()->delete();
            $Quotation->update([
                'deleted_at' => Carbon::now(),
            ]);

        }, 10);

        return response()->json(['success' => true]);
    }

    // -------------- Delete by selection  ---------------\\

    public function delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Quotation::class);

        \DB::transaction(function () use ($request) {

            $user = Auth::user();
            // New way: Check user's record_view field (user-level boolean)
            // Backward compatibility: If record_view is null, fall back to role permission check
            $view_records = $user->hasRecordView();
            $selectedIds = $request->selectedIds;
            foreach ($selectedIds as $Quotation_id) {
                $Quotation = Quotation::findOrFail($Quotation_id);

                /**
                 * Warehouses restriction
                 * Allow if:
                 * - user has access to all warehouses (is_all_warehouses = 1)
                 * - OR sale warehouse_id is in user's assigned warehouses
                */
                $user_auth = auth()->user();

                if (! $user_auth->is_all_warehouses) {
                    $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)
                        ->pluck('warehouse_id')
                        ->toArray();

                    if (empty($Quotation->warehouse_id) || ! in_array($Quotation->warehouse_id, $warehouses_id)) {
                        return response()->json([
                            'success' => false,
                            'message' => 'You are not allowed to access this sale (warehouse restriction).',
                        ], 403);
                    }
                }

                // Check If User Has Permission view All Records
                // Warehouse half of the same rule: record_view says whose documents,
                // the assigned warehouses say which warehouses they may come from.
                $this->abortIfDocumentWarehouseDenied($Quotation);

                if (! $view_records) {
                    // Check If User->id === Quotation->id
                    $this->authorizeForUser($request->user('api'), 'check_record', $Quotation);
                }

                // Pharmacy: clear quotation-batch picks for this quotation.
                $batchService = app(BatchService::class);
                if ($batchService->isSupported()) {
                    $batchService->clearForQuotationDetails(
                        QuotationDetail::where('quotation_id', $Quotation_id)->get()
                    );
                }

                $Quotation->details()->delete();
                $Quotation->update([
                    'deleted_at' => Carbon::now(),
                ]);
            }

        }, 10);

        return response()->json(['success' => true]);
    }

    // ---------------- Get Details Quotation-----------------\\

    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Quotation::class);
        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();
        $quotation_data = Quotation::with('details.product.unitSale')
            ->where('deleted_at', '=', null)
            ->findOrFail($id);

        // Document currency: stored amounts are base currency, display = stored * rate.
        $docCurrency = helpers::Get_Document_Currency($quotation_data);

        $details = [];

        // Check If User Has Permission view All Records
        // Warehouse half of the same rule: record_view says whose documents,
        // the assigned warehouses say which warehouses they may come from.
        $this->abortIfDocumentWarehouseDenied($quotation_data);

        if (! $view_records) {
            // Check If User->id === Quotation->id
            $this->authorizeForUser($request->user('api'), 'check_record', $quotation_data);
        }

        // The detail page builds its PDF / delete / edit links from this, so the
        // payload has to identify the record it describes.
        $quote['id'] = $quotation_data->id;
        $quote['Ref'] = $quotation_data->Ref;
        // Multi-Currency: amounts in this payload are converted into the
        // document currency — the page must render them with THIS symbol.
        $quote['currency_symbol'] = $docCurrency['symbol'];
        $quote['currency_code'] = $docCurrency['code'];
        $quote['currency_id'] = $docCurrency['id'];
        $quote['currency_rate'] = $docCurrency['rate'];
        $quote['date'] = $quotation_data->date.' '.$quotation_data->time;
        $quote['note'] = $quotation_data->notes;
        $quote['statut'] = $quotation_data->statut;
        $quote['discount'] = $quotation_data->discount * $docCurrency['rate'];
        $quote['shipping'] = $quotation_data->shipping * $docCurrency['rate'];
        $quote['tax_rate'] = $quotation_data->tax_rate;
        $quote['TaxNet'] = $quotation_data->TaxNet * $docCurrency['rate'];
        $quote['client_name'] = $quotation_data['client']->name;
        $quote['client_phone'] = $quotation_data['client']->phone;
        $quote['client_adr'] = $quotation_data['client']->adresse;
        $quote['client_email'] = $quotation_data['client']->email;
        $quote['client_tax'] = $quotation_data['client']->tax_number;
        $quote['warehouse'] = $quotation_data['warehouse']->name;
        $quote['GrandTotal'] = number_format($quotation_data['GrandTotal'] * $docCurrency['rate'], helpers::price_decimals(), '.', '');

        // Converted flag (null when the linked sale no longer exists, so the
        // quotation becomes convertible again — same rule as the list).
        $convertedSaleId = $quotation_data->converted_sale_id ?? null;
        $convertedSale = $convertedSaleId ? Sale::whereNull('deleted_at')->find($convertedSaleId, ['id', 'Ref']) : null;
        $quote['converted_sale_id'] = $convertedSale->id ?? null;
        $quote['converted_sale_ref'] = $convertedSale->Ref ?? null;

        $batchesByDetail = app(BatchService::class)->batchesForQuotationDetails($quotation_data['details']);

        foreach ($quotation_data['details'] as $detail) {

            // check if detail has sale_unit_id Or Null
            if ($detail->sale_unit_id !== null) {
                $unit = Unit::where('id', $detail->sale_unit_id)->first();
            } else {
                $product_unit_sale_id = Product::with('unitSale')
                    ->where('id', $detail->product_id)
                    ->first();

                if ($product_unit_sale_id['unitSale']) {
                    $unit = Unit::where('id', $product_unit_sale_id['unitSale']->id)->first();
                }
                $unit = null;

            }

            if ($detail->product_variant_id) {

                $productsVariants = ProductVariant::where('product_id', $detail->product_id)
                    ->where('id', $detail->product_variant_id)->first();

                $data['code'] = $productsVariants->code;
                $data['name'] = '['.$productsVariants->name.']'.$detail['product']['name'];

            } else {
                $data['code'] = $detail['product']['code'];
                $data['name'] = $detail['product']['name'];
            }

            // unit price in document currency; the whole line stays consistent from here on
            $price = $detail->price * $docCurrency['rate'];

            $data['quantity'] = $detail->quantity;
            $data['total'] = $detail->total * $docCurrency['rate'];
            $data['price'] = $price;
            $data['unit_sale'] = $unit ? $unit->ShortName : '';

            if ($detail->discount_method == '2') {
                $data['DiscountNet'] = $detail->discount * $docCurrency['rate'];
            } else {
                $data['DiscountNet'] = $price * $detail->discount / 100;
            }

            $tax_price = $detail->TaxNet * (($price - $data['DiscountNet']) / 100);
            $data['Unit_price'] = $price;
            // fixed discounts are amounts (convert); percentage discounts stay as-is
            $data['discount'] = $detail->discount_method == '2' ? $detail->discount * $docCurrency['rate'] : $detail->discount;

            if ($detail->tax_method == '1') {
                $data['Net_price'] = $price - $data['DiscountNet'];
                $data['taxe'] = $tax_price;
            } else {
                $data['Net_price'] = ($price - $data['DiscountNet'] - $tax_price);
                $data['taxe'] = $price - $data['Net_price'] - $data['DiscountNet'];
            }

            $data['is_imei'] = $detail['product']['is_imei'];
            $data['imei_number'] = $detail->imei_number;
            $data['is_batch_tracked'] = (bool) ($detail['product']['is_batch_tracked'] ?? false);
            $data['batches'] = array_map(function ($b) use ($docCurrency) {
                if (isset($b['unit_cost'])) {
                    $b['unit_cost'] *= $docCurrency['rate'];
                }

                return $b;
            }, $batchesByDetail[(int) $detail->id] ?? []);

            $details[] = $data;
        }

        $company = Setting::where('deleted_at', '=', null)->first();

        return response()->json([
            'quote' => $quote,
            'details' => $details,
            'company' => $company,
        ]);

    }

    // ---------------- Reference Number Of Quotation  ---------------\\

    public function getNumberOrder()
    {
        // Get prefix from settings, fallback to 'QT' if not set
        $setting = \App\Models\Setting::where('deleted_at', '=', null)->first();
        $prefix = !empty($setting->quotation_prefix) ? $setting->quotation_prefix : 'QT';
        
        // Get the last quotation with a reference that starts with the prefix
        $last = DB::table('quotations')
            ->where('Ref', 'like', $prefix.'_%')
            ->latest('id')
            ->first();

        if ($last) {
            $item = $last->Ref;
            $nwMsg = explode('_', $item);
            
            // Ensure valid structure before processing
            if (isset($nwMsg[1]) && is_numeric($nwMsg[1])) {
                $inMsg = $nwMsg[1] + 1;
                $code = $nwMsg[0].'_'.str_pad($inMsg, 4, '0', STR_PAD_LEFT);
            } else {
                $code = $prefix.'_0001'; // Fallback if reference is corrupted
            }
        } else {
            $code = $prefix.'_0001';
        }

        return $code;

    }

    // ---------------- Quotation PDF ---------------\\

    public function Quotation_pdf(Request $request, $id)
    {

        $details = [];
        $Quotation = Quotation::with('details.product.unitSale')
            ->where('deleted_at', '=', null)
            ->findOrFail($id);

        // Document currency: stored amounts are base currency, display = stored * rate.
        $docCurrency = helpers::Get_Document_Currency($Quotation);

        $quote['client_name'] = $Quotation['client']->name;
        $quote['client_phone'] = $Quotation['client']->phone;
        $quote['client_adr'] = $Quotation['client']->adresse;
        $quote['client_email'] = $Quotation['client']->email;
        $quote['client_tax'] = $Quotation['client']->tax_number;
        $quote['TaxNet'] = number_format($Quotation->TaxNet * $docCurrency['rate'], helpers::price_decimals(), '.', '');
        $quote['discount'] = number_format($Quotation->discount * $docCurrency['rate'], helpers::price_decimals(), '.', '');
        $quote['shipping'] = number_format($Quotation->shipping * $docCurrency['rate'], helpers::price_decimals(), '.', '');
        $quote['statut'] = $Quotation->statut;
        $quote['Ref'] = $Quotation->Ref;
        $quote['date'] = $Quotation->date.' '.$Quotation->time;
        $quote['GrandTotal'] = number_format($Quotation->GrandTotal * $docCurrency['rate'], helpers::price_decimals(), '.', '');

        $detail_id = 0;
        foreach ($Quotation['details'] as $detail) {

            // check if detail has sale_unit_id Or Null
            if ($detail->sale_unit_id !== null) {
                $unit = Unit::where('id', $detail->sale_unit_id)->first();
            } else {
                $product_unit_sale_id = Product::with('unitSale')
                    ->where('id', $detail->product_id)
                    ->first();

                if ($product_unit_sale_id['unitSale']) {
                    $unit = Unit::where('id', $product_unit_sale_id['unitSale']->id)->first();
                }
                $unit = null;

            }

            if ($detail->product_variant_id) {

                $productsVariants = ProductVariant::where('product_id', $detail->product_id)
                    ->where('id', $detail->product_variant_id)->first();

                $data['code'] = $productsVariants->code;
                $data['name'] = '['.$productsVariants->name.']'.$detail['product']['name'];

            } else {
                $data['code'] = $detail['product']['code'];
                $data['name'] = $detail['product']['name'];
            }

            $data['detail_id'] = $detail_id += 1;
            $data['quantity'] = number_format($detail->quantity, helpers::price_decimals(), '.', '');
            $data['total'] = number_format($detail->total * $docCurrency['rate'], helpers::price_decimals(), '.', '');
            $data['unitSale'] = $unit ? $unit->ShortName : '';
            // unit price in document currency; the whole line stays consistent from here on
            $price = $detail->price * $docCurrency['rate'];
            $data['price'] = number_format($price, helpers::price_decimals(), '.', '');

            if ($detail->discount_method == '2') {
                $data['DiscountNet'] = number_format($detail->discount * $docCurrency['rate'], helpers::price_decimals(), '.', '');
            } else {
                $data['DiscountNet'] = number_format($price * $detail->discount / 100, helpers::price_decimals(), '.', '');
            }

            $tax_price = $detail->TaxNet * (($price - $data['DiscountNet']) / 100);
            $data['Unit_price'] = number_format($price, helpers::price_decimals(), '.', '');
            // fixed discounts are amounts (convert); percentage discounts stay as-is
            $data['discount'] = number_format($detail->discount_method == '2' ? $detail->discount * $docCurrency['rate'] : $detail->discount, helpers::price_decimals(), '.', '');

            if ($detail->tax_method == '1') {
                $data['Net_price'] = $price - $data['DiscountNet'];
                $data['taxe'] = number_format($tax_price, helpers::price_decimals(), '.', '');
            } else {
                $data['Net_price'] = ($price - $data['DiscountNet'] - $tax_price);
                $data['taxe'] = number_format($price - $data['Net_price'] - $data['DiscountNet'], helpers::price_decimals(), '.', '');
            }

            $data['is_imei'] = $detail['product']['is_imei'];
            $data['imei_number'] = $detail->imei_number;

            $details[] = $data;
        }

        $settings = Setting::where('deleted_at', '=', null)->first();
        $symbol = $docCurrency['code'];

        $Html = view('pdf.quotation_pdf', [
            'symbol' => $symbol,
            'setting' => $settings,
            'quote' => $quote,
            'details' => $details,
        ])->render();

        $arabic = new Arabic;
        $p = $arabic->arIdentify($Html);

        for ($i = count($p) - 1; $i >= 0; $i -= 2) {
            $utf8ar = $arabic->utf8Glyphs(substr($Html, $p[$i - 1], $p[$i] - $p[$i - 1]));
            $Html = substr_replace($Html, $utf8ar, $p[$i - 1], $p[$i] - $p[$i - 1]);
        }

        $pdf = PDF::loadHTML($Html);

        return $pdf->download('quotation.pdf');

    }

    /**
     * Returns the quotation HTML (rendered from pdf.quotation_pdf template) instead of a PDF.
     * Uses the same template (`pdf.quotation_pdf`) but returns raw HTML instead of a PDF.
     * This is used by the print functionality, which opens a popup, injects the HTML, and
     * calls window.print().
     */
    public function Quotation_PDF_Inline(Request $request, $id)
    {
        $details = [];
        $Quotation = Quotation::with('details.product.unitSale')
            ->where('deleted_at', '=', null)
            ->findOrFail($id);

        // Document currency: stored amounts are base currency, display = stored * rate.
        $docCurrency = helpers::Get_Document_Currency($Quotation);

        $quote['client_name'] = $Quotation['client']->name;
        $quote['client_phone'] = $Quotation['client']->phone;
        $quote['client_adr'] = $Quotation['client']->adresse;
        $quote['client_email'] = $Quotation['client']->email;
        $quote['client_tax'] = $Quotation['client']->tax_number;
        $quote['TaxNet'] = number_format($Quotation->TaxNet * $docCurrency['rate'], helpers::price_decimals(), '.', '');
        $quote['discount'] = number_format($Quotation->discount * $docCurrency['rate'], helpers::price_decimals(), '.', '');
        $quote['shipping'] = number_format($Quotation->shipping * $docCurrency['rate'], helpers::price_decimals(), '.', '');
        $quote['statut'] = $Quotation->statut;
        $quote['Ref'] = $Quotation->Ref;
        $quote['date'] = $Quotation->date.' '.$Quotation->time;
        $quote['GrandTotal'] = number_format($Quotation->GrandTotal * $docCurrency['rate'], helpers::price_decimals(), '.', '');

        $detail_id = 0;
        foreach ($Quotation['details'] as $detail) {

            // check if detail has sale_unit_id Or Null
            if ($detail->sale_unit_id !== null) {
                $unit = Unit::where('id', $detail->sale_unit_id)->first();
            } else {
                $product_unit_sale_id = Product::with('unitSale')
                    ->where('id', $detail->product_id)
                    ->first();

                if ($product_unit_sale_id['unitSale']) {
                    $unit = Unit::where('id', $product_unit_sale_id['unitSale']->id)->first();
                }
                $unit = null;

            }

            if ($detail->product_variant_id) {

                $productsVariants = ProductVariant::where('product_id', $detail->product_id)
                    ->where('id', $detail->product_variant_id)->first();

                $data['code'] = $productsVariants->code;
                $data['name'] = '['.$productsVariants->name.']'.$detail['product']['name'];

            } else {
                $data['code'] = $detail['product']['code'];
                $data['name'] = $detail['product']['name'];
            }

            $data['detail_id'] = $detail_id += 1;
            $data['quantity'] = number_format($detail->quantity, helpers::price_decimals(), '.', '');
            $data['total'] = number_format($detail->total * $docCurrency['rate'], helpers::price_decimals(), '.', '');
            $data['unitSale'] = $unit ? $unit->ShortName : '';
            // unit price in document currency; the whole line stays consistent from here on
            $price = $detail->price * $docCurrency['rate'];
            $data['price'] = number_format($price, helpers::price_decimals(), '.', '');

            if ($detail->discount_method == '2') {
                $data['DiscountNet'] = number_format($detail->discount * $docCurrency['rate'], helpers::price_decimals(), '.', '');
            } else {
                $data['DiscountNet'] = number_format($price * $detail->discount / 100, helpers::price_decimals(), '.', '');
            }

            $tax_price = $detail->TaxNet * (($price - $data['DiscountNet']) / 100);
            $data['Unit_price'] = number_format($price, helpers::price_decimals(), '.', '');
            // fixed discounts are amounts (convert); percentage discounts stay as-is
            $data['discount'] = number_format($detail->discount_method == '2' ? $detail->discount * $docCurrency['rate'] : $detail->discount, helpers::price_decimals(), '.', '');

            if ($detail->tax_method == '1') {
                $data['Net_price'] = $price - $data['DiscountNet'];
                $data['taxe'] = number_format($tax_price, helpers::price_decimals(), '.', '');
            } else {
                $data['Net_price'] = ($price - $data['DiscountNet'] - $tax_price);
                $data['taxe'] = number_format($price - $data['Net_price'] - $data['DiscountNet'], helpers::price_decimals(), '.', '');
            }

            $data['is_imei'] = $detail['product']['is_imei'];
            $data['imei_number'] = $detail->imei_number;

            $details[] = $data;
        }

        $settings = Setting::where('deleted_at', '=', null)->first();
        $symbol = $docCurrency['code'];

        $Html = view('pdf.quotation_pdf', [
            'symbol' => $symbol,
            'setting' => $settings,
            'quote' => $quote,
            'details' => $details,
        ])->render();

        $arabic = new Arabic;
        $p = $arabic->arIdentify($Html);

        for ($i = count($p) - 1; $i >= 0; $i -= 2) {
            $utf8ar = $arabic->utf8Glyphs(substr($Html, $p[$i - 1], $p[$i] - $p[$i - 1]));
            $Html = substr_replace($Html, $utf8ar, $p[$i - 1], $p[$i] - $p[$i - 1]);
        }

        // When rendering as HTML in the browser, filesystem paths like public_path('images/...')
        // do not work as <img src>. Convert any ".../public/images/<file>" path (Windows or Unix)
        // into a proper web URL so logos/images display.
        try {
            $webImagesPath = rtrim(url('images'), '/').'/';
            $Html = preg_replace_callback(
                '~(?:[A-Za-z]:)?[\/\\\\][^"\']*?[\/\\\\]public[\/\\\\]images[\/\\\\]([^"\'>]+)~',
                function ($m) use ($webImagesPath) {
                    $file = ltrim($m[1], '/\\');
                    return $webImagesPath.$file;
                },
                $Html
            );
        } catch (\Throwable $e) {
            // If anything goes wrong, fall back to the original HTML.
        }

        // Return raw HTML so the print popup can inject it and call window.print().
        return response($Html);
    }

    // ---------------- Show Form Create Quotation ---------------\\

    public function create(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'create', Quotation::class);

        // get warehouses assigned to user
        $user_auth = auth()->user();
        if ($user_auth->is_all_warehouses) {
            $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
        } else {
            $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)->pluck('warehouse_id')->toArray();
            $warehouses = Warehouse::where('deleted_at', '=', null)->whereIn('id', $warehouses_id)->get(['id', 'name']);
        }

        $clients = Client::where('deleted_at', '=', null)->get(['id', 'name']);
        $quotation_with_stock = Setting::where('deleted_at', '=', null)->first()->quotation_with_stock;

        return response()->json([
            'clients' => $clients,
            'warehouses' => $warehouses,
            'quotation_with_stock' => $quotation_with_stock,
        ]);
    }

    // ------------- Show Form Edit Quotation -----------\\

    public function edit(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'update', Quotation::class);
        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();
        $Quotation = Quotation::with('details.product.unitSale')
            ->where('deleted_at', '=', null)
            ->findOrFail($id);

        /**
         * Warehouses restriction
         * Allow if:
         * - user has access to all warehouses (is_all_warehouses = 1)
         * - OR sale warehouse_id is in user's assigned warehouses
        */
        $user_auth = auth()->user();

        if (! $user_auth->is_all_warehouses) {
            $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)
                ->pluck('warehouse_id')
                ->toArray();

            if (empty($Quotation->warehouse_id) || ! in_array($Quotation->warehouse_id, $warehouses_id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not allowed to access this sale (warehouse restriction).',
                ], 403);
            }
        }

        $details = [];
        // Check If User Has Permission view All Records
        // Warehouse half of the same rule: record_view says whose documents,
        // the assigned warehouses say which warehouses they may come from.
        $this->abortIfDocumentWarehouseDenied($Quotation);

        if (! $view_records) {
            // Check If User->id === Quotation->id
            $this->authorizeForUser($request->user('api'), 'check_record', $Quotation);
        }

        if ($Quotation->client_id) {
            if (Client::where('id', $Quotation->client_id)
                ->where('deleted_at', '=', null)
                ->first()) {
                $quote['client_id'] = $Quotation->client_id;
            } else {
                $quote['client_id'] = '';
            }
        } else {
            $quote['client_id'] = '';
        }

        if ($Quotation->warehouse_id) {
            if (Warehouse::where('id', $Quotation->warehouse_id)
                ->where('deleted_at', '=', null)
                ->first()) {
                $quote['warehouse_id'] = $Quotation->warehouse_id;
            } else {
                $quote['warehouse_id'] = '';
            }
        } else {
            $quote['warehouse_id'] = '';
        }

        $quote['date'] = $Quotation->date;
        $quote['tax_rate'] = $Quotation->tax_rate;
        $quote['discount'] = $Quotation->discount;
        $quote['shipping'] = $Quotation->shipping;
        $quote['statut'] = $Quotation->statut;
        $quote['notes'] = $Quotation->notes;
        // Multi-Currency snapshot (null = base currency)
        $quote['currency_id'] = $Quotation->currency_id;
        $quote['exchange_rate'] = $Quotation->exchange_rate;

        $batchesByDetail = app(BatchService::class)->batchesForQuotationDetails($Quotation['details']);

        $detail_id = 0;
        foreach ($Quotation['details'] as $detail) {

            // check if detail has sale_unit_id Or Null
            if ($detail->sale_unit_id !== null) {
                $unit = Unit::where('id', $detail->sale_unit_id)->first();
                $data['no_unit'] = 1;
            } else {
                $product_unit_sale_id = Product::with('unitSale')
                    ->where('id', $detail->product_id)
                    ->first();

                if ($product_unit_sale_id['unitSale']) {
                    $unit = Unit::where('id', $product_unit_sale_id['unitSale']->id)->first();
                }
                $unit = null;

                $data['no_unit'] = 0;
            }

            if ($detail->product_variant_id) {
                $item_product = product_warehouse::where('product_id', $detail->product_id)
                    ->where('product_variant_id', $detail->product_variant_id)
                    ->where('warehouse_id', $Quotation->warehouse_id)
                    ->where('deleted_at', '=', null)
                    ->first();

                $productsVariants = ProductVariant::where('product_id', $detail->product_id)
                    ->where('id', $detail->product_variant_id)->first();

                $item_product ? $data['del'] = 0 : $data['del'] = 1;
                $data['product_variant_id'] = $detail->product_variant_id;

                $data['code'] = $productsVariants->code;
                $data['name'] = '['.$productsVariants->name.']'.$detail['product']['name'];

                if ($unit && $unit->operator == '/') {
                    $stock = $item_product ? $item_product->qte * $unit->operator_value : 0;
                } elseif ($unit && $unit->operator == '*') {
                    $stock = $item_product ? $item_product->qte / $unit->operator_value : 0;
                } else {
                    $stock = 0;
                }

            } else {
                $item_product = product_warehouse::where('product_id', $detail->product_id)
                    ->where('deleted_at', '=', null)
                    ->where('warehouse_id', $Quotation->warehouse_id)
                    ->where('product_variant_id', '=', null)
                    ->first();

                $item_product ? $data['del'] = 0 : $data['del'] = 1;
                $data['product_variant_id'] = null;
                $data['code'] = $detail['product']['code'];
                $data['name'] = $detail['product']['name'];

                if ($unit && $unit->operator == '/') {
                    $stock = $item_product ? $item_product->qte * $unit->operator_value : 0;
                } elseif ($unit && $unit->operator == '*') {
                    $stock = $item_product ? $item_product->qte / $unit->operator_value : 0;
                } else {
                    $stock = 0;
                }

            }

            $data['id'] = $detail->id;
            $data['stock'] = $detail['product']['type'] != 'is_service' ? $stock : '---';
            $data['product_type'] = $detail['product']['type'];
            $data['detail_id'] = $detail_id += 1;
            $data['product_id'] = $detail->product_id;
            $data['quantity'] = $detail->quantity;
            $data['etat'] = 'current';
            $data['qte_copy'] = $detail->quantity;
            $data['total'] = $detail->total;
            $data['unitSale'] = $unit ? $unit->ShortName : '';
            $data['sale_unit_id'] = $unit ? $unit->id : '';
            $data['is_imei'] = $detail['product']['is_imei'];
            $data['imei_number'] = $detail->imei_number;

            if ($detail->discount_method == '2') {
                $data['DiscountNet'] = $detail->discount;
            } else {
                $data['DiscountNet'] = $detail->price * $detail->discount / 100;
            }

            $tax_price = $detail->TaxNet * (($detail->price - $data['DiscountNet']) / 100);
            $data['Unit_price'] = $detail->price;
            $data['tax_percent'] = $detail->TaxNet;
            $data['tax_method'] = $detail->tax_method;
            $data['discount'] = $detail->discount;
            $data['discount_Method'] = $detail->discount_method;

            if ($detail->tax_method == '1') {
                $data['Net_price'] = $detail->price - $data['DiscountNet'];
                $data['taxe'] = $tax_price;
                $data['subtotal'] = ($data['Net_price'] * $data['quantity']) + ($tax_price * $data['quantity']);
            } else {
                $data['Net_price'] = ($detail->price - $data['DiscountNet'] - $tax_price);
                $data['taxe'] = $detail->price - $data['Net_price'] - $data['DiscountNet'];
                $data['subtotal'] = ($data['Net_price'] * $data['quantity']) + ($tax_price * $data['quantity']);
            }

            $data['is_batch_tracked'] = (bool) ($detail['product']['is_batch_tracked'] ?? false);
            $data['batches'] = $batchesByDetail[(int) $detail->id] ?? [];

            $details[] = $data;
        }

        // get warehouses assigned to user
        $user_auth = auth()->user();
        if ($user_auth->is_all_warehouses) {
            $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
        } else {
            $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)->pluck('warehouse_id')->toArray();
            $warehouses = Warehouse::where('deleted_at', '=', null)->whereIn('id', $warehouses_id)->get(['id', 'name']);
        }

        $clients = Client::where('deleted_at', '=', null)->get(['id', 'name']);

        return response()->json([
            'details' => $details,
            'quote' => $quote,
            'clients' => $clients,
            'warehouses' => $warehouses,
        ]);
    }

    // ------------- Send Quotation on Email -----------\\

    public function SendEmail(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Quotation::class);

        // Quotation
        $quotation = Quotation::with('client')->where('deleted_at', '=', null)->findOrFail($request->id);

        // Document currency: stored amounts are base currency, display = stored * rate.
        $docCurrency = helpers::Get_Document_Currency($quotation);
        $currency = $docCurrency['symbol'];

        // settings
        $settings = Setting::where('deleted_at', '=', null)->first();

        // the custom msg of quotation
        $emailMessage = EmailMessage::getForLocale('quotation');

        if ($emailMessage) {
            $message_body = $emailMessage->body;
            $message_subject = $emailMessage->subject;
        } else {
            $message_body = '';
            $message_subject = '';
        }

        // Tags
        $random_number = Str::random(10);
        $quotation_url = url('/api/quote_pdf/'.$request->id.'?'.$random_number);
        $quotation_number = $quotation->Ref;

        $total_amount = $currency.' '.number_format($quotation->GrandTotal * $docCurrency['rate'], helpers::price_decimals(), '.', ',');

        $contact_name = $quotation['client']->name;
        $business_name = $settings->CompanyName;

        // receiver email
        $receiver_email = $quotation['client']->email;

        // replace the text with tags
        $message_body = str_replace('{contact_name}', $contact_name, $message_body);
        $message_body = str_replace('{business_name}', $business_name, $message_body);
        $message_body = str_replace('{quotation_url}', $quotation_url, $message_body);
        $message_body = str_replace('{quotation_number}', $quotation_number, $message_body);
        $message_body = str_replace('{total_amount}', $total_amount, $message_body);

        $email['subject'] = $message_subject;
        $email['body'] = $message_body;
        $email['company_name'] = $business_name;

        $this->Set_config_mail();

        Mail::to($receiver_email)->send(new CustomEmail($email));

        return response()->json(['message' => 'Email sent successfully'], 200);

        // return $mail;
    }

    // -------------------Sms Notifications -----------------\\

    public function Send_SMS(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Quotation::class);

        // Quotation
        $quotation = Quotation::with('client')->where('deleted_at', '=', null)->findOrFail($request->id);

        // Document currency: stored amounts are base currency, display = stored * rate.
        $docCurrency = helpers::Get_Document_Currency($quotation);
        $currency = $docCurrency['symbol'];

        // settings
        $settings = Setting::where('deleted_at', '=', null)->first();

        $default_sms_gateway = sms_gateway::where('id', $settings->sms_gateway)
            ->where('deleted_at', '=', null)->first();

        // the custom msg of quotation
        $smsMessage = SMSMessage::getForLocale('quotation');

        if ($smsMessage) {
            $message_text = $smsMessage->text;
        } else {
            $message_text = '';
        }

        // Tags
        $random_number = Str::random(10);
        $quotation_url = url('/api/quote_pdf/'.$request->id.'?'.$random_number);
        $quotation_number = $quotation->Ref;

        $total_amount = $currency.' '.number_format($quotation->GrandTotal * $docCurrency['rate'], helpers::price_decimals(), '.', ',');

        $contact_name = $quotation['client']->name;
        $business_name = $settings->CompanyName;

        // receiver phone
        $receiverNumber = $quotation['client']->phone;

        // replace the text with tags
        $message_text = str_replace('{contact_name}', $contact_name, $message_text);
        $message_text = str_replace('{business_name}', $business_name, $message_text);
        $message_text = str_replace('{quotation_url}', $quotation_url, $message_text);
        $message_text = str_replace('{quotation_number}', $quotation_number, $message_text);
        $message_text = str_replace('{total_amount}', $total_amount, $message_text);

        // twilio
        if ($default_sms_gateway->title == 'twilio') {
            try {

                $account_sid = env('TWILIO_SID');
                $auth_token = env('TWILIO_TOKEN');
                $twilio_number = env('TWILIO_FROM');

                $client = new Client_Twilio($account_sid, $auth_token);
                $client->messages->create($receiverNumber, [
                    'from' => $twilio_number,
                    'body' => $message_text]);

            } catch (Exception $e) {
                return response()->json(['message' => $e->getMessage()], 500);
            }
        }
        // termii
        elseif ($default_sms_gateway->title == 'termii') {

            $client = new Client_termi;
            $url = 'https://api.ng.termii.com/api/sms/send';

            $payload = [
                'to' => $receiverNumber,
                'from' => env('TERMI_SENDER'),
                'sms' => $message_text,
                'type' => 'plain',
                'channel' => 'generic',
                'api_key' => env('TERMI_KEY'),
            ];

            try {
                $response = $client->post($url, [
                    'json' => $payload,
                ]);

                $result = json_decode($response->getBody(), true);

                return response()->json($result);
            } catch (\Exception $e) {
                Log::error('Termii SMS Error: '.$e->getMessage());

                return response()->json(['status' => 'error', 'message' => 'Failed to send SMS'], 500);
            }

        } elseif ($default_sms_gateway->title == 'infobip') {

            $BASE_URL = env('base_url');
            $API_KEY = env('api_key');
            $SENDER = env('sender_from');

            $configuration = (new Configuration)
                ->setHost($BASE_URL)
                ->setApiKeyPrefix('Authorization', 'App')
                ->setApiKey('Authorization', $API_KEY);

            $client = new Client_guzzle;

            $sendSmsApi = new SendSMSApi($client, $configuration);
            $destination = (new SmsDestination)->setTo($receiverNumber);
            $message = (new SmsTextualMessage)
                ->setFrom($SENDER)
                ->setText($message_text)
                ->setDestinations([$destination]);

            $request = (new SmsAdvancedTextualRequest)->setMessages([$message]);

            try {
                $smsResponse = $sendSmsApi->sendSmsMessage($request);
                echo 'Response body: '.$smsResponse;
            } catch (Throwable $apiException) {
                echo 'HTTP Code: '.$apiException->getCode()."\n";
            }

        } elseif ($default_sms_gateway->title == 'custom') {
            try {
                (new \App\Services\CustomSmsGateway)->send($receiverNumber, $message_text);
            } catch (\Throwable $e) {
                Log::error('Custom SMS Error: '.$e->getMessage());

                return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
            }
        }

        return response()->json(['success' => true]);
    }

    // quotation_send_whatsapp
    public function quotation_send_whatsapp(Request $request)
    {

        // Quotation
        $quotation = Quotation::with('client')->where('deleted_at', '=', null)->findOrFail($request->id);

        // Document currency: stored amounts are base currency, display = stored * rate.
        $docCurrency = helpers::Get_Document_Currency($quotation);
        $currency = $docCurrency['symbol'];

        // settings
        $settings = Setting::where('deleted_at', '=', null)->first();

        // the custom msg of quotation
        $smsMessage = SMSMessage::getForLocale('quotation');

        if ($smsMessage) {
            $message_text = $smsMessage->text;
        } else {
            $message_text = '';
        }

        // Tags
        $random_number = Str::random(10);
        $quotation_url = url('/api/quote_pdf/'.$request->id.'?'.$random_number);
        $quotation_number = $quotation->Ref;

        $total_amount = $currency.' '.number_format($quotation->GrandTotal * $docCurrency['rate'], helpers::price_decimals(), '.', ',');

        $contact_name = $quotation['client']->name;
        $business_name = $settings->CompanyName;

        // receiver phone
        $receiverNumber = $quotation['client']->phone;

        // Check if the phone number is empty or null
        if (empty($receiverNumber) || $receiverNumber == null || $receiverNumber == 'null' || $receiverNumber == '') {
            return response()->json(['error' => 'Phone number is missing'], 400);
        }

        // replace the text with tags
        $message_text = str_replace('{contact_name}', $contact_name, $message_text);
        $message_text = str_replace('{business_name}', $business_name, $message_text);
        $message_text = str_replace('{quotation_url}', $quotation_url, $message_text);
        $message_text = str_replace('{quotation_number}', $quotation_number, $message_text);
        $message_text = str_replace('{total_amount}', $total_amount, $message_text);

        return response()->json(['message' => $message_text, 'phone' => $receiverNumber]);

    }

    // ------ batches_for_quotation ---------------\\
    //
    // Returns FEFO-ordered active batches at the warehouse for batch-tracked
    // products. Mirrors batches_for_sale but authorized via the Quotation policy.
    public function batches_for_quotation(Request $request, $product_id, $warehouse_id, $variant_id = null)
    {
        $this->authorizeForUser($request->user('api'), 'create', Quotation::class);

        $productId = (int) $product_id;
        $warehouseId = (int) $warehouse_id;
        $variantId = ($variant_id !== null && $variant_id !== '' && $variant_id !== 'null' && (int) $variant_id > 0)
            ? (int) $variant_id
            : null;

        $batchService = app(BatchService::class);

        return response()->json([
            'supported' => $batchService->isSupported(),
            'batches' => $batchService->availableBatchesForSale($productId, $variantId, $warehouseId),
        ]);
    }
}
