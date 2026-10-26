<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EcommerceClient;
use App\Models\Message;
use App\Models\OnlineOrder;
use App\Models\OnlineOrderReturn;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\StoreQuoteRequest;
use App\Models\WalletWithdrawal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

/**
 * Everything waiting on a member of staff, in one payload: the notification
 * bell renders the list, the sidebar renders the same numbers as badges.
 *
 * Counts are DERIVED from the live rows (pending status / unread flag), never
 * stored — so an item disappears by itself the moment it is processed, with no
 * "mark as read" bookkeeping to drift out of sync.
 */
class PendingWorkController extends Controller
{
    use \App\Traits\ScopesWarehouseAccess;
    use \App\Http\Controllers\Concerns\ScopesToCompany;

    public function index(Request $request)
    {
        $user = $request->user('api');
        abort_unless($user, 401);

        $items = [];
        $companyId = $this->resolveActingCompanyId($request);

        foreach ($this->sources() as $key => $source) {
            // Skip anything this user may not see, and anything whose table is
            // not present (modules differ between installs / upgrade states).
            if (! empty($source['table']) && ! Schema::hasTable($source['table'])) {
                continue;
            }
            if (! $this->allows($user, $source)) {
                continue;
            }
            // A module the company has switched off: no alert -- its page can't be
            // opened (user's rule, 2026-10-20). The SuperAdmin sees every module.
            if (! empty($source['module']) && $user->type !== 'SuperAdmin'
                && ! \App\Support\ModuleFlags::enabled($companyId, $source['module'])) {
                continue;
            }

            try {
                $count = (int) ($source['count'])();
            } catch (\Throwable $e) {
                continue;
            }

            if ($count <= 0) {
                continue;
            }

            $items[] = [
                'key' => $key,
                // The admin SPA translates from its own bundle, so hand it the
                // key rather than a PHP-side string.
                'label_key' => $source['label'],
                'count' => $count,
                'route' => $source['route'],
                'severity' => $source['severity'] ?? 'info',
            ];
        }

        return response()->json([
            'items' => $items,
            'total' => array_sum(array_column($items, 'count')),
        ]);
    }

    /**
     * key => [count closure, label key, SPA route, permission/policy, table, module].
     * `permission` is a plain permission name; `policy` is [ability, class].
     * The permission must be the one the route's page asks for (router meta.permission),
     * and `module` the Business Module the page belongs to (config/modules.js key):
     * an alert is only shown when its page can actually be opened.
     */
    private function sources(): array
    {
        return [
            'quantity_alerts' => [
                'label' => 'Product_Quantity_Alerts',
                'route' => '/reports/quantity-alerts',
                'permission' => 'Reports_quantity_alerts',
                'table' => 'product_warehouse',
                'severity' => 'warning',
                // Same query as ReportController@count_quantity_alert: stock
                // lives on product_warehouse, and only the user's warehouses
                // count.
                'count' => fn () => \App\Models\product_warehouse::join('products', 'product_warehouse.product_id', '=', 'products.id')
                    ->whereRaw('qte <= stock_alert')
                    ->whereIn('product_warehouse.warehouse_id', $this->userWarehouseIds())
                    ->count(),
            ],
            'new_orders' => [
                'label' => 'Pending_Online_Orders',
                'route' => '/store/orders',
                'permission' => 'Orders_view',
                'module' => 'Store',
                'table' => 'online_orders',
                'count' => fn () => OnlineOrder::where('status', 'pending')->count(),
            ],
            'payment_proofs' => [
                'label' => 'Payment_Proofs_To_Verify',
                'route' => '/store/orders?proof=pending',
                'permission' => 'Orders_view',
                'module' => 'Store',
                'table' => 'online_order_payment_proofs',
                'count' => fn () => \App\Models\OnlineOrderPaymentProof::where('status', 'pending')->count(),
            ],
            'pending_customers' => [
                'label' => 'Pending_Customers',
                'route' => '/store/pending-customers',
                'permission' => 'Store_settings_view',
                'module' => 'Store',
                'table' => 'ecommerce_clients',
                'count' => fn () => EcommerceClient::whereNull('deleted_at')->where('status', 0)->count(),
            ],
            'return_requests' => [
                'label' => 'Return_Requests',
                'route' => '/store/returns',
                'permission' => 'Orders_view',
                'module' => 'Store',
                'table' => 'online_order_returns',
                'count' => fn () => OnlineOrderReturn::where('status', 'requested')->count(),
            ],
            'quote_requests' => [
                'label' => 'Quote_Requests',
                'route' => '/store/quote-requests',
                'permission' => 'Orders_view',
                'module' => 'Store',
                'table' => 'store_quote_requests',
                'count' => fn () => StoreQuoteRequest::where('status', 'new')->count(),
            ],
            'product_reviews' => [
                'label' => 'Product_Reviews',
                'route' => '/store/reviews',
                'permission' => 'Store_settings_view',
                'module' => 'Store',
                'table' => 'product_reviews',
                'count' => fn () => ProductReview::where('status', 'pending')->count(),
            ],
            // Customer questions (product_questions): no admin page answers them
            // yet (there is no /store/questions), so no alert -- it could never be
            // opened. Add it back with the route once that page exists.
            'messages' => [
                'label' => 'Messages',
                'route' => '/store/messages',
                'permission' => 'Messages_view',
                'module' => 'Store',
                'table' => 'messages',
                'count' => fn () => Message::where('is_read', 0)->count(),
            ],
            // Network security: computers asking to be approved (acting company).
            'computers_to_approve' => [
                'label' => 'Computers_To_Approve',
                'route' => '/settings/network-security',
                'permission' => 'network_security',
                'table' => 'trusted_devices',
                'severity' => 'warning',
                'count' => function () {
                    $u = request()->user('api');
                    $cid = $u->type === 'SuperAdmin' ? (int) request('company_id', $u->company_id) : (int) $u->company_id;

                    return \Illuminate\Support\Facades\DB::table('trusted_devices')->where('company_id', $cid)->where('status', 'pending')->count();
                },
            ],
            // Hospital (2026-10-25): the control-tower alerts and what waits on a doctor / nurse.
            'hms_beds_cleaning_late' => [
                'label' => 'Hms_beds_cleaning_late', 'route' => '/hospital/wards', 'permission' => 'hms_wards', 'module' => 'hospital',
                'table' => 'hospital_beds', 'severity' => 'warning',
                'count' => fn () => \App\Models\HospitalBed::whereNull('deleted_at')->where('status', 'cleaning')->where('status_changed_at', '<', now()->subMinutes(30))->count(),
            ],
            'hms_rooms_to_free' => [
                'label' => 'Hms_rooms_to_free', 'route' => '/hospital/admissions', 'permission' => 'hms_admissions', 'module' => 'hospital',
                'table' => 'admissions',
                'count' => fn () => \App\Models\Admission::whereNull('deleted_at')->where('status', '!=', 'admitted')->whereNull('left_at')->whereNotNull('bed_id')->count(),
            ],
            'hms_consults_pending' => [
                'label' => 'Hms_consults_pending', 'route' => '/hospital/my-patients', 'permission' => 'hms_my_patients', 'module' => 'hospital',
                'table' => 'hms_consults', 'severity' => 'warning',
                'count' => function () {
                    [, $docIds] = \App\Support\HospitalShifts::staffOf(request()->user('api'));
                    if (! $docIds) {
                        return 0;
                    }
                    $specs = \Illuminate\Support\Facades\DB::table('doctors')->whereIn('id', $docIds)->pluck('specialty')->filter()->all();

                    return \Illuminate\Support\Facades\DB::table('hms_consults')->whereIn('status', ['requested'])
                        ->where(fn ($w) => $w->whereIn('to_doctor_id', $docIds)->orWhere(fn ($x) => $x->whereNull('to_doctor_id')->whereIn('specialty', $specs ?: ['~'])))->count();
                },
            ],
            'hms_lab_critical' => [
                'label' => 'Hms_lab_critical', 'route' => '/hospital/lab-orders', 'permission' => 'hms_lab', 'module' => 'hospital',
                'table' => 'lab_order_items', 'severity' => 'warning',
                'count' => fn () => \Illuminate\Support\Facades\DB::table('lab_order_items as i')->join('lab_orders as o', 'o.id', '=', 'i.lab_order_id')
                    ->whereNull('o.deleted_at')->where('i.flag', 'critical')->whereNull('i.critical_seen_at')
                    ->when(\App\Models\LabOrder::actingCompanyId(), fn ($q, $cid) => $q->where('o.company_id', $cid))->count(),
            ],
            'hms_discharges_due' => [
                'label' => 'Hms_discharges_due', 'route' => '/hospital/admissions', 'permission' => 'hms_admissions', 'module' => 'hospital',
                'table' => 'hms_discharge_plans',
                'count' => fn () => \Illuminate\Support\Facades\DB::table('hms_discharge_plans as p')->join('admissions as a', 'a.id', '=', 'p.admission_id')
                    ->where('a.status', 'admitted')->where('p.planned_at', '<=', now()->addHours(48))
                    ->when(\App\Models\Admission::actingCompanyId(), fn ($q, $cid) => $q->where('a.company_id', $cid))
                    ->where('p.items', 'like', '%"done":false%')->count(),
            ],
            // Patients registered at the window waiting for triage (2026-10-25).
            'hms_triage_waiting' => [
                'label' => 'Hms_triage_waiting', 'route' => '/hospital/triage', 'permission' => 'hms_triage', 'module' => 'hospital',
                'table' => 'er_cases', 'severity' => 'warning',
                'count' => fn () => \App\Models\ErCase::whereNull('deleted_at')->where('status', '!=', 'closed')
                    ->whereIn('pathway', ['emergency', 'critical'])->whereIn('triage_status', ['pending', 'called'])->count(),
            ],
            // Shifts of the next 7 days with a position short -- only for a company that keeps a roster.
            'hms_shift_gaps' => [
                'label' => 'Hms_shift_gaps', 'route' => '/hospital/shifts', 'permission' => 'hms_shifts', 'module' => 'hospital',
                'table' => 'hms_staff_shifts',
                'count' => fn () => \App\Models\HmsStaffShift::actingCompanyId('hms_staff_shifts')
                    && \App\Models\HmsStaffShift::whereNull('deleted_at')->whereDate('shift_date', '>=', now()->subDays(30)->toDateString())->exists()
                    ? \App\Support\HospitalShifts::coverage(now()->toDateString(), now()->addDays(6)->toDateString())['gaps'] : 0,
            ],
            // Specialist clinic (2026-10-26): the logged-in doctor's patients ready to go in.
            'hms_clinic_ready' => [
                'label' => 'Hms_clinic_ready', 'route' => '/hospital/specialist', 'permission' => 'hms_specialist', 'module' => 'hospital',
                'table' => 'appointments', 'severity' => 'warning',
                'count' => function () {
                    [, $docIds] = \App\Support\HospitalShifts::staffOf(request()->user('api'));

                    return $docIds ? \App\Models\Appointment::whereNull('deleted_at')->whereIn('doctor_id', $docIds)
                        ->whereDate('scheduled_at', today())->where('flow_status', 'ready')->count() : 0;
                },
            ],
            // Follow-ups / referrals / portal requests waiting to be scheduled by reception.
            'hms_clinic_requests' => [
                'label' => 'Hms_clinic_requests', 'route' => '/hospital/agenda', 'permission' => 'hms_reception', 'module' => 'hospital',
                'table' => 'hms_appointment_requests',
                'count' => fn () => \Illuminate\Support\Facades\DB::table('hms_appointment_requests')->where('status', 'pending')
                    ->when(\App\Models\Patient::actingCompanyId('patients'), fn ($q, $cid) => $q->where('company_id', $cid))->count(),
            ],
            // Patients ready more than 15 minutes and the doctor has not called them in.
            'hms_clinic_late' => [
                'label' => 'Hms_clinic_late', 'route' => '/hospital/agenda', 'permission' => 'hms_reception', 'module' => 'hospital',
                'table' => 'appointments', 'severity' => 'warning',
                'count' => fn () => \App\Models\Appointment::whereNull('deleted_at')->whereDate('scheduled_at', today())->where('flow_status', 'ready')
                    ->where('ready_at', '<', now()->subMinutes(\App\Http\Controllers\HospitalClinicController::LATE_MINUTES))->count(),
            ],
            // Withdrawals are approved on E-Wallet -> Dashboard (there was no /store/wallet page).
            'wallet_withdrawals' => [
                'label' => 'Pending_Withdrawals',
                'route' => '/ewallet/dashboard',
                'permission' => 'ewallet',
                'module' => 'EWallet',
                'table' => 'wallet_withdrawals',
                'count' => fn () => WalletWithdrawal::where('status', 'pending')->count(),
            ],
        ];
    }

    /** Permission name or policy ability, whichever the source declares. */
    private function allows($user, array $source): bool
    {
        if (! empty($source['policy'])) {
            [$ability, $class] = $source['policy'];

            return Gate::forUser($user)->allows($ability, $class);
        }

        if (! empty($source['permission'])) {
            try {
                $permission = \App\Models\Permission::where('name', $source['permission'])->first();

                return $permission ? (bool) $user->hasRole($permission->roles) : false;
            } catch (\Throwable $e) {
                return false;
            }
        }

        return true;
    }
}
