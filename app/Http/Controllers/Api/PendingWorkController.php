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
