<?php

namespace App\Http\Controllers;

use App\Mail\CustomEmail;
use App\Models\Booking;
use App\Models\Client;
use App\Models\EmailMessage;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Tray;
use App\Models\User;
use App\utils\helpers;
use ArPHP\I18N\Arabic;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PDF;

class BookingController extends BaseController
{
    /**
     * List bookings with optional filters.
     *
     * Query params:
     * - page, limit, SortField, SortType, search
     * - status: pending|confirmed|cancelled|completed
     * - date: YYYY-MM-DD (booking_date)
     * - from, to: optional date range (YYYY-MM-DD) for calendar views
     */
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Booking::class);
        $perPage = $request->limit ?: 10;
        $pageStart = (int) $request->get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;

        $order = $request->SortField ?: 'id';
        $dir = strtolower($request->SortType ?: 'desc');
        if (! in_array($dir, ['asc', 'desc'], true)) {
            $dir = 'desc';
        }

        $sortableMap = [
            'id' => 'bookings.id',
            'booking_date' => 'bookings.booking_date',
            'booking_time' => 'bookings.booking_time',
            'status' => 'bookings.status',
        ];
        $order = $sortableMap[$order] ?? 'bookings.id';

        $query = Booking::leftJoin('clients', 'clients.id', '=', 'bookings.customer_id')
            ->leftJoin('products', 'products.id', '=', 'bookings.product_id')
            ->whereNull('bookings.deleted_at')
            ->select(
                'bookings.*',
                'clients.name as customer_name',
                'products.name as service_name'
            )
            ->when($request->filled('status'), function ($q) use ($request) {
                $q->where('bookings.status', $request->status);
            })
            ->when($request->filled('date'), function ($q) use ($request) {
                $q->whereDate('bookings.booking_date', $request->date);
            })
            ->when($request->filled('from'), function ($q) use ($request) {
                $q->whereDate('bookings.booking_date', '>=', $request->from);
            })
            ->when($request->filled('to'), function ($q) use ($request) {
                $q->whereDate('bookings.booking_date', '<=', $request->to);
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;

                $q->where(function ($sub) use ($s) {
                    $sub->where('clients.name', 'LIKE', "%{$s}%")
                        ->orWhere('products.name', 'LIKE', "%{$s}%")
                        ->orWhere('bookings.notes', 'LIKE', "%{$s}%");
                });
            });

        $totalRows = $query->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }

        $rows = $query->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        // Summary stats for the header cards. Respect date/search filters but
        // ignore the status filter so the breakdown always shows every status.
        $statsQuery = Booking::leftJoin('clients', 'clients.id', '=', 'bookings.customer_id')
            ->leftJoin('products', 'products.id', '=', 'bookings.product_id')
            ->whereNull('bookings.deleted_at')
            ->when($request->filled('date'), function ($q) use ($request) {
                $q->whereDate('bookings.booking_date', $request->date);
            })
            ->when($request->filled('from'), function ($q) use ($request) {
                $q->whereDate('bookings.booking_date', '>=', $request->from);
            })
            ->when($request->filled('to'), function ($q) use ($request) {
                $q->whereDate('bookings.booking_date', '<=', $request->to);
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;
                $q->where(function ($sub) use ($s) {
                    $sub->where('clients.name', 'LIKE', "%{$s}%")
                        ->orWhere('products.name', 'LIKE', "%{$s}%")
                        ->orWhere('bookings.notes', 'LIKE', "%{$s}%");
                });
            });

        $statusCounts = (clone $statsQuery)
            ->select('bookings.status', DB::raw('count(*) as cnt'))
            ->groupBy('bookings.status')
            ->pluck('cnt', 'status');

        $stats = [
            'total' => (int) $statusCounts->sum(),
            'pending' => (int) ($statusCounts['pending'] ?? 0),
            'confirmed' => (int) ($statusCounts['confirmed'] ?? 0),
            'completed' => (int) ($statusCounts['completed'] ?? 0),
            'cancelled' => (int) ($statusCounts['cancelled'] ?? 0),
            'revenue' => (float) (clone $statsQuery)
                ->whereIn('bookings.status', ['confirmed', 'completed'])
                ->sum('bookings.price'),
        ];

        $data = [];
        foreach ($rows as $booking) {
            $item['id'] = $booking->id;
            $item['Ref'] = $booking->Ref;
            $item['customer_id'] = $booking->customer_id;
            $item['customer_name'] = $booking->customer_name;
            $item['product_id'] = $booking->product_id;
            // Prefer the free-text product name, fall back to the service product name.
            $item['product_name'] = $booking->product_name ?: $booking->service_name;
            $item['tray_id'] = $booking->tray_id;
            $item['sales_person_id'] = $booking->sales_person_id;
            $item['price'] = $booking->price;
            $item['amount'] = $booking->amount;
            $item['booking_date'] = $booking->booking_date;
            $item['booking_time'] = $booking->booking_time;
            $item['booking_end_time'] = $booking->booking_end_time;
            $item['delivery_date'] = $booking->delivery_date;
            $item['delivery_time'] = $booking->delivery_time;
            $item['delivery_address'] = $booking->delivery_address;
            $item['status'] = $booking->status;
            $item['notes'] = $booking->notes;

            $data[] = $item;
        }

        return response()->json([
            'totalRows' => $totalRows,
            'bookings' => $data,
            'stats' => $stats,
        ]);
    }

    /**
     * Metadata for create form: customers, service products, statuses.
     */
    public function create(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Booking::class);
        $customers = Client::whereNull('deleted_at')
            ->orderBy('name', 'asc')
            ->get(['id', 'name']);

        $products = Product::whereNull('deleted_at')
            ->where('type', 'is_service')
            ->orderBy('name', 'asc')
            ->get(['id', 'name', 'code', 'price']);

        return response()->json([
            'customers' => $customers,
            'products' => $products,
            'trays' => $this->getTrays(),
            'sales_persons' => $this->getSalesPersons(),
            'statuses' => ['pending', 'confirmed', 'cancelled', 'completed'],
        ]);
    }

    /**
     * Active trays for the booking form dropdown.
     */
    private function getTrays()
    {
        return Tray::whereNull('deleted_at')
            ->orderBy('name', 'asc')
            ->get(['id', 'name']);
    }

    /**
     * Active users (staff) for the Sales Person dropdown.
     */
    private function getSalesPersons()
    {
        return User::whereNull('deleted_at')
            ->where('statut', 1)
            ->orderBy('firstname', 'asc')
            ->orderBy('lastname', 'asc')
            ->get(['id', 'firstname', 'lastname', 'username'])
            ->map(function ($user) {
                $name = trim(($user->firstname ?? '').' '.($user->lastname ?? ''));

                return [
                    'id' => $user->id,
                    'name' => $name !== '' ? $name : $user->username,
                ];
            });
    }

    /**
     * Store a new booking.
     */
    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Booking::class);
        // Normalize time formats before validation - handle H:i:s format and empty strings
        $requestData = $request->all();
        
        // Normalize booking_time (H:i:s to H:i)
        if (isset($requestData['booking_time']) && $requestData['booking_time'] !== null) {
            if (preg_match('/^(\d{2}:\d{2}):\d{2}$/', $requestData['booking_time'], $matches)) {
                $requestData['booking_time'] = $matches[1];
            }
        }
        
        // Normalize booking_end_time - handle empty strings and H:i:s format
        if (isset($requestData['booking_end_time']) && $requestData['booking_end_time'] === '') {
            $requestData['booking_end_time'] = null;
        } elseif (isset($requestData['booking_end_time']) && $requestData['booking_end_time'] !== null) {
            // If it's in H:i:s format, convert to H:i
            if (preg_match('/^(\d{2}:\d{2}):\d{2}$/', $requestData['booking_end_time'], $matches)) {
                $requestData['booking_end_time'] = $matches[1];
            }
        }

        // Normalize delivery_time - handle empty strings and H:i:s format
        if (isset($requestData['delivery_time']) && $requestData['delivery_time'] === '') {
            $requestData['delivery_time'] = null;
        } elseif (isset($requestData['delivery_time']) && $requestData['delivery_time'] !== null) {
            if (preg_match('/^(\d{2}:\d{2}):\d{2}$/', $requestData['delivery_time'], $matches)) {
                $requestData['delivery_time'] = $matches[1];
            }
        }

        $validated = validator($requestData, [
            'customer_id' => 'required|integer|exists:clients,id',
            'product_id' => 'nullable|integer|exists:products,id',
            'tray_id' => 'nullable|integer|exists:trays,id',
            'sales_person_id' => 'nullable|integer|exists:users,id',
            'product_name' => 'nullable|string|max:255',
            'product_description' => 'nullable|string',
            'price' => 'nullable|numeric|min:0',
            'amount' => 'nullable|numeric|min:0',
            'booking_date' => 'required|date',
            'booking_time' => 'required|date_format:H:i',
            'booking_end_time' => 'nullable|date_format:H:i',
            'delivery_date' => 'nullable|date',
            'delivery_time' => 'nullable|date_format:H:i',
            'delivery_address' => 'nullable|string',
            'status' => 'required|string|in:pending,confirmed,cancelled,completed',
            'notes' => 'nullable|string',
        ])->validate();

        // Generate reference number
        $validated['Ref'] = $this->getNumberOrder();

        $booking = Booking::create($validated);

        return response()->json(['success' => true, 'id' => $booking->id], 201);
    }

    /**
     * Show a single booking with relations.
     */
    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Booking::class);
        $booking = Booking::with(['customer', 'product', 'tray', 'salesPerson'])
            ->whereNull('deleted_at')
            ->findOrFail($id);

        $salesPerson = $booking->salesPerson;
        $salesPersonName = null;
        if ($salesPerson) {
            $salesPersonName = trim(($salesPerson->firstname ?? '').' '.($salesPerson->lastname ?? ''));
            $salesPersonName = $salesPersonName !== '' ? $salesPersonName : $salesPerson->username;
        }

        return response()->json([
            'booking' => [
                'id' => $booking->id,
                'Ref' => $booking->Ref,
                'customer_id' => $booking->customer_id,
                'customer_name' => optional($booking->customer)->name,
                'product_id' => $booking->product_id,
                // Service product name (legacy dropdown)
                'service_name' => optional($booking->product)->name,
                // Free-text product details
                'product_name' => $booking->product_name,
                'product_description' => $booking->product_description,
                'tray_id' => $booking->tray_id,
                'tray_name' => optional($booking->tray)->name,
                'sales_person_id' => $booking->sales_person_id,
                'sales_person_name' => $salesPersonName,
                'price' => $booking->price,
                'amount' => $booking->amount,
                'booking_date' => $booking->booking_date,
                'booking_time' => $booking->booking_time,
                'booking_end_time' => $booking->booking_end_time,
                'delivery_date' => $booking->delivery_date,
                'delivery_time' => $booking->delivery_time,
                'delivery_address' => $booking->delivery_address,
                'status' => $booking->status,
                'notes' => $booking->notes,
            ],
        ]);
    }

    /**
     * Metadata + existing booking for edit form.
     */
    public function edit(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Booking::class);
        $booking = Booking::whereNull('deleted_at')->findOrFail($id);

        $customers = Client::whereNull('deleted_at')
            ->orderBy('name', 'asc')
            ->get(['id', 'name']);

        $products = Product::whereNull('deleted_at')
            ->where('type', 'is_service')
            ->orderBy('name', 'asc')
            ->get(['id', 'name', 'code', 'price']);

        return response()->json([
            'booking' => [
                'id' => $booking->id,
                'Ref' => $booking->Ref,
                'customer_id' => $booking->customer_id,
                'product_id' => $booking->product_id,
                'tray_id' => $booking->tray_id,
                'sales_person_id' => $booking->sales_person_id,
                'product_name' => $booking->product_name,
                'product_description' => $booking->product_description,
                'price' => $booking->price,
                'amount' => $booking->amount,
                'booking_date' => $booking->booking_date,
                'booking_time' => $booking->booking_time,
                'booking_end_time' => $booking->booking_end_time,
                'delivery_date' => $booking->delivery_date,
                'delivery_time' => $booking->delivery_time,
                'delivery_address' => $booking->delivery_address,
                'status' => $booking->status,
                'notes' => $booking->notes,
            ],
            'customers' => $customers,
            'products' => $products,
            'trays' => $this->getTrays(),
            'sales_persons' => $this->getSalesPersons(),
            'statuses' => ['pending', 'confirmed', 'cancelled', 'completed'],
        ]);
    }

    /**
     * Update an existing booking.
     */
    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Booking::class);
        $booking = Booking::whereNull('deleted_at')->findOrFail($id);

        // Normalize time formats before validation - handle H:i:s format and empty strings
        $requestData = $request->all();
        
        // Normalize booking_time (H:i:s to H:i)
        if (isset($requestData['booking_time']) && $requestData['booking_time'] !== null) {
            if (preg_match('/^(\d{2}:\d{2}):\d{2}$/', $requestData['booking_time'], $matches)) {
                $requestData['booking_time'] = $matches[1];
            }
        }
        
        // Normalize booking_end_time - handle empty strings and H:i:s format
        if (isset($requestData['booking_end_time']) && $requestData['booking_end_time'] === '') {
            $requestData['booking_end_time'] = null;
        } elseif (isset($requestData['booking_end_time']) && $requestData['booking_end_time'] !== null) {
            // If it's in H:i:s format, convert to H:i
            if (preg_match('/^(\d{2}:\d{2}):\d{2}$/', $requestData['booking_end_time'], $matches)) {
                $requestData['booking_end_time'] = $matches[1];
            }
        }

        // Normalize delivery_time - handle empty strings and H:i:s format
        if (isset($requestData['delivery_time']) && $requestData['delivery_time'] === '') {
            $requestData['delivery_time'] = null;
        } elseif (isset($requestData['delivery_time']) && $requestData['delivery_time'] !== null) {
            if (preg_match('/^(\d{2}:\d{2}):\d{2}$/', $requestData['delivery_time'], $matches)) {
                $requestData['delivery_time'] = $matches[1];
            }
        }

        $validated = validator($requestData, [
            'customer_id' => 'required|integer|exists:clients,id',
            'product_id' => 'nullable|integer|exists:products,id',
            'tray_id' => 'nullable|integer|exists:trays,id',
            'sales_person_id' => 'nullable|integer|exists:users,id',
            'product_name' => 'nullable|string|max:255',
            'product_description' => 'nullable|string',
            'price' => 'nullable|numeric|min:0',
            'amount' => 'nullable|numeric|min:0',
            'booking_date' => 'required|date',
            'booking_time' => 'required|date_format:H:i',
            'booking_end_time' => 'nullable|date_format:H:i',
            'delivery_date' => 'nullable|date',
            'delivery_time' => 'nullable|date_format:H:i',
            'delivery_address' => 'nullable|string',
            'status' => 'required|string|in:pending,confirmed,cancelled,completed',
            'notes' => 'nullable|string',
        ])->validate();

        $booking->update($validated);

        return response()->json(['success' => true]);
    }

    /**
     * Soft-delete a booking.
     */
    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Booking::class);
        $booking = Booking::withTrashed()->findOrFail($id);
        
        // Check if already deleted
        if ($booking->trashed()) {
            return response()->json(['success' => true, 'message' => 'Booking already deleted']);
        }
        
        $booking->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Change only the status of a booking.
     */
    public function changeStatus(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Booking::class);
        $booking = Booking::whereNull('deleted_at')->findOrFail($id);

        $validated = $request->validate([
            'status' => 'required|string|in:pending,confirmed,cancelled,completed',
        ]);

        $booking->status = $validated['status'];
        $booking->save();

        return response()->json(['success' => true]);
    }

    /**
     * Generate PDF for a booking.
     */
    public function booking_pdf(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Booking::class);
        $helpers = new helpers;
        $booking = Booking::with(['customer', 'product'])
            ->whereNull('deleted_at')
            ->findOrFail($id);

        $bookingData = [
            'id' => $booking->id,
            'Ref' => $booking->Ref,
            'customer_name' => optional($booking->customer)->name ?? '-',
            'customer_email' => optional($booking->customer)->email ?? '-',
            'customer_phone' => optional($booking->customer)->phone ?? '-',
            'customer_adr' => optional($booking->customer)->adresse ?? '-',
            'product_name' => optional($booking->product)->name ?? '-',
            'price' => $booking->price,
            'booking_date' => $booking->booking_date,
            'booking_time' => $booking->booking_time,
            'booking_end_time' => $booking->booking_end_time,
            'status' => $booking->status,
            'notes' => $booking->notes,
        ];

        $settings = Setting::whereNull('deleted_at')->first();
        $symbol = $helpers->Get_Currency_Code();

        $Html = view('pdf.booking_pdf', [
            'symbol' => $symbol,
            'setting' => $settings,
            'booking' => $bookingData,
        ])->render();

        $arabic = new Arabic;
        $p = $arabic->arIdentify($Html);
        for ($i = count($p) - 1; $i >= 0; $i -= 2) {
            $utf8ar = $arabic->utf8Glyphs(substr($Html, $p[$i - 1], $p[$i] - $p[$i - 1]));
            $Html = substr_replace($Html, $utf8ar, $p[$i - 1], $p[$i] - $p[$i - 1]);
        }

        $pdf = PDF::loadHTML($Html);

        return $pdf->download('Booking_'.$booking->id.'.pdf');
    }

    /**
     * Generate reference number for bookings.
     */
    public function getNumberOrder()
    {
        $last = DB::table('bookings')->latest('id')->first();

        if ($last && $last->Ref) {
            $item = $last->Ref;
            $nwMsg = explode('_', $item);
            $inMsg = isset($nwMsg[1]) ? ($nwMsg[1] + 1) : 1112;
            $code = 'BK_'.$inMsg;
        } else {
            $code = 'BK_1111';
        }

        return $code;
    }

    /**
     * Send booking confirmation email to the customer using the 'booking' email template.
     */
    public function Send_Email(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Booking::class);

        $id = $request->input('id');
        if (! $id) {
            return response()->json(['message' => 'Booking ID is required.'], 422);
        }

        $booking = Booking::with(['customer', 'product'])
            ->whereNull('deleted_at')
            ->findOrFail($id);

        $customer = $booking->customer;
        if (! $customer) {
            return response()->json(['message' => 'Booking has no customer.'], 422);
        }

        $receiver_email = $customer->email;
        if (empty($receiver_email)) {
            return response()->json(['message' => 'Customer has no email address.'], 422);
        }

        $settings = Setting::whereNull('deleted_at')->first();
        $emailMessage = EmailMessage::getForLocale('booking');

        if ($emailMessage) {
            $message_body = $emailMessage->body;
            $message_subject = $emailMessage->subject;
        } else {
            $message_body = '';
            $message_subject = 'Your Booking Confirmation';
        }

        $contact_name = $customer->name ?? '';
        $business_name = $settings ? $settings->CompanyName : '';
        $booking_number = $booking->Ref ?? (string) $booking->id;
        $booking_date = $booking->booking_date ?? '';
        $start_time = $booking->booking_time ?? '';
        $end_time = $booking->booking_end_time ?? '';
        $service_name = $booking->product ? $booking->product->name : ($settings ? __('Not_Applicable') : 'N/A');
        $random = Str::random(10);
        $booking_url = url('/api/booking_pdf/'.$booking->id.'?'.$random);

        $message_body = str_replace('{contact_name}', $contact_name, $message_body);
        $message_body = str_replace('{business_name}', $business_name, $message_body);
        $message_body = str_replace('{booking_number}', $booking_number, $message_body);
        $message_body = str_replace('{booking_date}', $booking_date, $message_body);
        $message_body = str_replace('{start_time}', $start_time, $message_body);
        $message_body = str_replace('{end_time}', $end_time, $message_body);
        $message_body = str_replace('{service_name}', $service_name, $message_body);
        $message_body = str_replace('{booking_url}', $booking_url, $message_body);

        $message_subject = str_replace('{contact_name}', $contact_name, $message_subject);
        $message_subject = str_replace('{business_name}', $business_name, $message_subject);
        $message_subject = str_replace('{booking_number}', $booking_number, $message_subject);

        $email = [
            'subject' => $message_subject,
            'body' => $message_body,
            'company_name' => $business_name,
        ];

        $this->Set_config_mail();

        Mail::to($receiver_email)->send(new CustomEmail($email));

        return response()->json(['message' => 'Email sent successfully.'], 200);
    }
}


