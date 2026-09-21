<?php

use App\Http\Controllers\Api\CustomerDisplayController;
use App\Http\Controllers\Api\Store\BannersApiController;
use App\Http\Controllers\Api\Store\CollectionController;
use App\Http\Controllers\Api\Store\InviteCodesController;
use App\Http\Controllers\Api\Store\MessageController;
use App\Http\Controllers\Api\Store\OnlineOrdersApiController;
use App\Http\Controllers\Api\Store\PagesApiController;
use App\Http\Controllers\Api\Store\PendingCustomersController;
use App\Http\Controllers\Api\Store\SettingsApiController;
use App\Http\Controllers\Api\Store\FlashSalesController;
use App\Http\Controllers\Api\Store\ProductQuestionsController;
use App\Http\Controllers\Api\Store\ProductReviewsController;
use App\Http\Controllers\Api\Store\QuoteRequestsController;
use App\Http\Controllers\Api\Store\GiftCardController;
use App\Http\Controllers\Api\Store\StoreCouponsController;
use App\Http\Controllers\Api\Store\StorePopupsController;
use App\Http\Controllers\Api\Store\WalletController;
use App\Http\Controllers\Api\Store\WalletWithdrawalController;
use App\Http\Controllers\Api\Store\ReturnsController;
use App\Http\Controllers\Api\Store\PickupBranchesController;
use App\Http\Controllers\Api\Store\ShippingMethodsController;
use App\Http\Controllers\Api\Store\SubscriberController;
use App\Http\Controllers\Api\Store\TaxRatesController;
use App\Http\Controllers\KnowledgeBaseArticleController;
use App\Http\Controllers\KnowledgeBaseArticleGroupController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
 */

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});

// Lightweight reachability probe for SPA/offline detection.
// Public by design: returns no sensitive data.
Route::get('/ping', function () {
    return response()->json([
        'ok' => true,
        'ts' => now()->timestamp,
    ]);
});

// Liveness probe used by the System Update post-update health check.
// Public by design (returns the literal "pong") and whitelisted from
// maintenance mode so it also answers during an update.
Route::get('/system-update/ping', 'SystemUpdateController@ping');

// --------------------------- Reset Password  ---------------------------

Route::group([
    'prefix' => 'password',
], function () {
    Route::post('create', 'PasswordResetController@create');
    Route::post('reset', 'PasswordResetController@reset');
});

Route::get('/products_clean_names', 'ProductsController@cleanNames');

Route::post('getAccessToken', 'AuthController@getAccessToken');

Route::get('/get-logo-setting', function () {
    $setting = \App\Models\Setting::first();

    return response()->json([
        'logo' => $setting->logo ?? null,
    ]);
});

Route::get('/translations/{locale}', function ($locale) {
    $translations = \DB::table('translations')
        ->where('locale', $locale)
        ->pluck('value', 'key');

    return response()->json($translations);
});

Route::get('/languages', 'LanguageController@load_language');

// Incoming webhooks (public, HMAC-signature verified per source).
// Namespaced under /webhooks/incoming/{source} — never collides with existing routes.
Route::post('/webhooks/incoming/{source}', [\App\Http\Controllers\Webhooks\IncomingWebhooksController::class, 'handle']);

// Salla webhooks (public endpoint; authenticated by the X-Salla-Signature HMAC)
Route::post('/salla/webhook', [\App\Http\Controllers\Integrations\SallaWebhookController::class, 'handle']);

// Online-store payment gateway webhooks (public; each request is
// signature-verified by the gateway service before anything is touched).
Route::post('/store/webhooks/paypal', [\App\Http\Controllers\Api\Store\WebhookController::class, 'paypal']);
Route::post('/store/webhooks/paystack', [\App\Http\Controllers\Api\Store\WebhookController::class, 'paystack']);
Route::post('/store/webhooks/flutterwave', [\App\Http\Controllers\Api\Store\WebhookController::class, 'flutterwave']);
Route::post('/store/webhooks/razorpay', [\App\Http\Controllers\Api\Store\WebhookController::class, 'razorpay']);
Route::post('/store/webhooks/sslcommerz', [\App\Http\Controllers\Api\Store\WebhookController::class, 'sslcommerz']);

// bKash / SSLCommerz browser callbacks. These live on the SESSIONLESS api
// stack on purpose: SSLCommerz posts the shopper's browser back cross-site,
// so the SameSite=Lax session cookie is not sent, and running StartSession
// would answer with a fresh session cookie that overwrites the customer's
// login mid-payment (they would land on the login page instead of thank-you).
// It also keeps them CSRF-free without an $except entry, and reachable even
// when the store is switched off — a payment already in flight must still be
// executed/validated (bKash has no IPN to recover it). Nothing here reads the
// session: the pending order is resolved by the unguessable gateway id, and
// paid is only ever written after a server-to-server execute/validate.
Route::get('/store/bkash/return', [\App\Http\Controllers\Api\Store\CheckoutController::class, 'bkashReturn'])->name('store.bkash.return');
Route::match(['get', 'post'], '/store/sslcommerz/return', [\App\Http\Controllers\Api\Store\CheckoutController::class, 'sslcommerzReturn'])->name('store.sslcommerz.return');
Route::match(['get', 'post'], '/store/sslcommerz/fail', [\App\Http\Controllers\Api\Store\CheckoutController::class, 'sslcommerzFail'])->name('store.sslcommerz.fail');
Route::match(['get', 'post'], '/store/sslcommerz/cancel', [\App\Http\Controllers\Api\Store\CheckoutController::class, 'sslcommerzCancel'])->name('store.sslcommerz.cancel');

Route::middleware(['auth:api', 'Is_Active', 'request.safety', 'token.timeout'])->group(function () {

    Route::get('/admin/store/settings', [SettingsApiController::class, 'show']);
    Route::post('/admin/store/settings', [SettingsApiController::class, 'update']);
    Route::get('/admin/store/menus', [SettingsApiController::class, 'menus']);
    Route::post('/admin/store/menus', [SettingsApiController::class, 'updateMenus']);
    Route::get('/settings/calendar', [SettingsApiController::class, 'showCalendar']);
    Route::patch('/settings/calendar', [SettingsApiController::class, 'updateCalendar']);

    Route::get('/store/orders', [OnlineOrdersApiController::class, 'index']);
    Route::get('/store/orders/{id}', [OnlineOrdersApiController::class, 'show']);
    Route::get('/store/orders/{id}/invoice', [OnlineOrdersApiController::class, 'invoice']);
    Route::get('/store/orders/{id}/confirm-options', [OnlineOrdersApiController::class, 'confirmOptions']);
    Route::patch('/store/orders/{id}', [OnlineOrdersApiController::class, 'update']);
    Route::post('/store/orders/{id}/payment-proofs/{proofId}/review', [OnlineOrdersApiController::class, 'reviewProof']);

    // Kitchen display
    Route::get('/kitchen/orders', 'KitchenOrderController@index');
    Route::get('/kitchen/orders/poll', 'KitchenOrderController@poll');
    Route::get('/kitchen/warehouses', 'KitchenOrderController@warehouses');
    Route::get('/kitchen/stations', 'KitchenOrderController@stations');
    Route::get('/kitchen/report', 'KitchenOrderController@report');
    Route::post('/kitchen/ready-screen/generate', 'KitchenOrderController@generateReadyScreen');
    Route::put('/kitchen/stations', 'KitchenOrderController@saveStations');
    Route::get('/kitchen/orders/{id}', 'KitchenOrderController@show');
    Route::post('/kitchen/orders', 'KitchenOrderController@store');
    Route::patch('/kitchen/orders/{id}/status', 'KitchenOrderController@updateStatus');
    Route::patch('/kitchen/orders/{id}/item', 'KitchenOrderController@updateItem');
    Route::patch('/kitchen/orders/{id}/assign', 'KitchenOrderController@assign');
    Route::patch('/kitchen/orders/{id}/dispatch', 'KitchenOrderController@dispatchOrder');

    Route::get('/store/pages', [PagesApiController::class, 'index']);
    Route::post('/store/pages', [PagesApiController::class, 'store']);
    Route::get('/store/pages/{id}', [PagesApiController::class, 'show']);
    Route::put('/store/pages/{id}', [PagesApiController::class, 'update']);
    Route::delete('/store/pages/{id}', [PagesApiController::class, 'destroy']);

    Route::get('/store/banners', [BannersApiController::class, 'index']);
    Route::post('/store/banners', [BannersApiController::class, 'store']);
    Route::get('/store/banners/{id}', [BannersApiController::class, 'show']);
    Route::put('/store/banners/{id}', [BannersApiController::class, 'update']);
    Route::delete('/store/banners/{id}', [BannersApiController::class, 'destroy']);

    Route::get('/store/subscribers', [SubscriberController::class, 'index']);
    Route::delete('/store/subscribers/{id}', [SubscriberController::class, 'destroy']);

    // Invite Codes
    Route::get('/store/invite-codes', [InviteCodesController::class, 'index']);
    Route::post('/store/invite-codes', [InviteCodesController::class, 'store']);
    Route::get('/store/invite-codes/{id}', [InviteCodesController::class, 'show']);
    Route::put('/store/invite-codes/{id}', [InviteCodesController::class, 'update']);
    Route::delete('/store/invite-codes/{id}', [InviteCodesController::class, 'destroy']);
    Route::post('/store/invite-codes/batch', [InviteCodesController::class, 'generateBatch']);

    // Pending Customers (admin approval)
    Route::get('/store/pending-customers', [PendingCustomersController::class, 'index']);
    Route::post('/store/pending-customers/{id}/approve', [PendingCustomersController::class, 'approve']);
    Route::post('/store/pending-customers/{id}/reject', [PendingCustomersController::class, 'reject']);
    Route::post('/store/pending-customers/approve-all', [PendingCustomersController::class, 'approveAll']);

    // Shipping Methods
    // Shipping zones + their rate rules (supersede the flat method list
    // once any zone exists).
    Route::get('/store/shipping-zones', [\App\Http\Controllers\Api\Store\ShippingZonesController::class, 'index']);
    Route::post('/store/shipping-zones', [\App\Http\Controllers\Api\Store\ShippingZonesController::class, 'store']);
    Route::put('/store/shipping-zones/{id}', [\App\Http\Controllers\Api\Store\ShippingZonesController::class, 'update']);
    Route::delete('/store/shipping-zones/{id}', [\App\Http\Controllers\Api\Store\ShippingZonesController::class, 'destroy']);
    Route::post('/store/shipping-zones/{zone}/rates', [\App\Http\Controllers\Api\Store\ShippingZonesController::class, 'storeRate']);
    Route::put('/store/shipping-zones/{zone}/rates/{rate}', [\App\Http\Controllers\Api\Store\ShippingZonesController::class, 'updateRate']);
    Route::delete('/store/shipping-zones/{zone}/rates/{rate}', [\App\Http\Controllers\Api\Store\ShippingZonesController::class, 'destroyRate']);

    Route::get('/store/pickup-branches', [PickupBranchesController::class, 'index']);
    Route::post('/store/pickup-branches', [PickupBranchesController::class, 'save']);

    Route::get('/store/shipping-methods', [ShippingMethodsController::class, 'index']);
    Route::post('/store/shipping-methods', [ShippingMethodsController::class, 'store']);
    Route::put('/store/shipping-methods/{id}', [ShippingMethodsController::class, 'update']);
    Route::delete('/store/shipping-methods/{id}', [ShippingMethodsController::class, 'destroy']);

    // Tax Rates
    Route::get('/store/tax-rates', [TaxRatesController::class, 'index']);
    Route::post('/store/tax-rates', [TaxRatesController::class, 'store']);
    Route::put('/store/tax-rates/{id}', [TaxRatesController::class, 'update']);
    Route::delete('/store/tax-rates/{id}', [TaxRatesController::class, 'destroy']);

    // Returns / cancellations (admin review + refund approval)
    Route::get('/store/returns', [ReturnsController::class, 'index']);
    Route::post('/store/returns/{id}/approve', [ReturnsController::class, 'approve']);
    Route::post('/store/returns/{id}/reject', [ReturnsController::class, 'reject']);

    // E-Wallet (admin dashboard + wallets)
    Route::get('/store/wallet/settings', [WalletController::class, 'settings']);
    Route::post('/store/wallet/settings', [WalletController::class, 'updateSettings']);
    Route::get('/store/wallet/dashboard', [WalletController::class, 'index']);
    Route::get('/store/wallets', [WalletController::class, 'wallets']);
    Route::get('/store/wallets/{id}', [WalletController::class, 'show']);
    Route::post('/store/wallets/{id}/adjust', [WalletController::class, 'adjust']);
    Route::post('/store/wallets/{id}/status', [WalletController::class, 'setStatus']);

    // E-Wallet withdrawals
    Route::get('/store/wallet-withdrawals', [WalletWithdrawalController::class, 'index']);
    Route::post('/store/wallet-withdrawals/{id}/approve', [WalletWithdrawalController::class, 'approve']);
    Route::post('/store/wallet-withdrawals/{id}/paid', [WalletWithdrawalController::class, 'markPaid']);
    Route::post('/store/wallet-withdrawals/{id}/reject', [WalletWithdrawalController::class, 'reject']);

    // Wallet items / Gift cards
    Route::get('/store/gift-cards', [GiftCardController::class, 'index']);
    Route::post('/store/gift-cards', [GiftCardController::class, 'store']);
    Route::put('/store/gift-cards/{id}', [GiftCardController::class, 'update']);
    Route::delete('/store/gift-cards/{id}', [GiftCardController::class, 'destroy']);

    // Coupons
    Route::get('/store/coupons', [StoreCouponsController::class, 'index']);
    Route::post('/store/coupons', [StoreCouponsController::class, 'store']);
    Route::put('/store/coupons/{id}', [StoreCouponsController::class, 'update']);
    Route::delete('/store/coupons/{id}', [StoreCouponsController::class, 'destroy']);

    // Quotation Requests (service / classified-ad inquiries)
    Route::get('/store/quote-requests', [QuoteRequestsController::class, 'index']);
    Route::post('/store/quote-requests/{id}/status', [QuoteRequestsController::class, 'setStatus']);
    Route::delete('/store/quote-requests/{id}', [QuoteRequestsController::class, 'destroy']);

    // Popup Messages
    Route::get('/store/popups', [StorePopupsController::class, 'index']);
    Route::post('/store/popups', [StorePopupsController::class, 'store']);
    Route::post('/store/popups/{id}', [StorePopupsController::class, 'update']); // POST for multipart update
    Route::delete('/store/popups/{id}', [StorePopupsController::class, 'destroy']);

    // Everything awaiting staff action: the bell list + sidebar badges.
    Route::get('pending-work', [\App\Http\Controllers\Api\PendingWorkController::class, 'index']);

    // Product Q&A ("Ask About This Item") — admin answers
    Route::get('/store/questions', [ProductQuestionsController::class, 'index']);
    Route::post('/store/questions/{id}/answer', [ProductQuestionsController::class, 'answer']);
    Route::post('/store/questions/{id}/status', [ProductQuestionsController::class, 'setStatus']);
    Route::delete('/store/questions/{id}', [ProductQuestionsController::class, 'destroy']);

    // Product Reviews (admin moderation)
    Route::get('/store/reviews', [ProductReviewsController::class, 'index']);
    Route::post('/store/reviews/{id}/status', [ProductReviewsController::class, 'setStatus']);
    Route::delete('/store/reviews/{id}', [ProductReviewsController::class, 'destroy']);

    // Flash Sales
    Route::get('/store/flash-sales', [FlashSalesController::class, 'index']);
    Route::get('/store/flash-sales/search-products', [FlashSalesController::class, 'searchProducts']);
    Route::get('/store/flash-sales/{id}', [FlashSalesController::class, 'show']);
    Route::post('/store/flash-sales', [FlashSalesController::class, 'store']);
    Route::put('/store/flash-sales/{id}', [FlashSalesController::class, 'update']);
    Route::delete('/store/flash-sales/{id}', [FlashSalesController::class, 'destroy']);

    Route::get('/store/messages', [MessageController::class, 'index'])->name('messages.index');
    Route::get('/store/messages/{id}', [MessageController::class, 'show'])->name('messages.show');
    Route::patch('/store/messages/{id}/toggle-read', [MessageController::class, 'toggleRead']); // optional
    Route::delete('/store/messages/{id}', [MessageController::class, 'destroy'])->name('messages.destroy');

    // Collections (manual)
    Route::get('/admin/store/collections', [CollectionController::class, 'index']);
    Route::post('/admin/store/collections', [CollectionController::class, 'store']);
    Route::get('/admin/store/collections/{collection}', [CollectionController::class, 'show']);
    Route::put('/admin/store/collections/{collection}', [CollectionController::class, 'update']);
    Route::delete('/admin/store/collections/{collection}', [CollectionController::class, 'destroy']);
    Route::post('/admin/store/collections/{collection}/products', [CollectionController::class, 'syncProducts']);

    Route::get('/admin/store/products', [CollectionController::class, 'searchProducts']);
    Route::get('/admin/products', [CollectionController::class, 'searchProducts']);

    // Knowledge Base
    Route::get('/knowledge-base/groups/for-filter', [KnowledgeBaseArticleGroupController::class, 'forFilter']);
    Route::get('/knowledge-base/groups', [KnowledgeBaseArticleGroupController::class, 'index']);
    Route::post('/knowledge-base/groups', [KnowledgeBaseArticleGroupController::class, 'store']);
    Route::get('/knowledge-base/groups/{knowledge_base_article_group}', [KnowledgeBaseArticleGroupController::class, 'show']);
    Route::put('/knowledge-base/groups/{knowledge_base_article_group}', [KnowledgeBaseArticleGroupController::class, 'update']);
    Route::delete('/knowledge-base/groups/{knowledge_base_article_group}', [KnowledgeBaseArticleGroupController::class, 'destroy']);

    Route::get('/knowledge-base/articles', [KnowledgeBaseArticleController::class, 'index']);
    Route::post('/knowledge-base/articles', [KnowledgeBaseArticleController::class, 'store']);
    Route::get('/knowledge-base/articles/{knowledge_base_article}', [KnowledgeBaseArticleController::class, 'show']);
    Route::put('/knowledge-base/articles/{knowledge_base_article}', [KnowledgeBaseArticleController::class, 'update']);
    Route::delete('/knowledge-base/articles/{knowledge_base_article}', [KnowledgeBaseArticleController::class, 'destroy']);
    Route::post('/knowledge-base/articles/{knowledge_base_article}/feedback', [KnowledgeBaseArticleController::class, 'submitFeedback']);

    Route::get('dashboard_data', 'DashboardController@dashboard_data');
    Route::get('today_summary', 'TodaySummaryController@index');
    // Profit analysis by dimension: product|category|unit|customer|date|warehouse
    Route::get('report/profit/{dimension}', 'ProfitReportController@index');
    Route::get('real_time_sales_counter_data', 'DashboardController@real_time_sales_counter_data');
    Route::get('sales_3d_dashboard_data', 'Sales3DDashboardController@data');

    Route::get('/retrieve-customer', 'StripeController@retrieveCustomer');
    Route::post('/update-customer-stripe', 'StripeController@updateCustomer');

    Route::get('/languages_setting', 'LanguageController@index');
    Route::post('/languages_setting', 'LanguageController@store');
    Route::put('/languages_setting/{language}', 'LanguageController@update');
    Route::delete('/languages_setting/{language}', 'LanguageController@destroy');
    Route::post('/languages_setting/{id}/set-default', 'LanguageController@setDefault');
    Route::post('/languages_setting/{id}/set-active', 'LanguageController@setLocaleActive');
    Route::post('/languages_setting/set-default/{locale}', 'LanguageController@setDefaultByLocale');

    // Sync Vue i18n locale to Laravel session for Blade PDFs (no translations_settings permission required)
    Route::post('/sync-locale', 'LocaleSyncController@sync');
    
    Route::get('/translations_setting/{locale}', 'LanguageController@get_translate');
    Route::put('/translations_setting/{locale}', 'LanguageController@update_translate');
    Route::put('/translations_setting/{locale}', 'LanguageController@updateOrInsert');
    Route::delete('/translations_setting/{locale}/{key}', 'LanguageController@delete_translate');

    // -------------------------- Clear Cache ---------------------------

    Route::get('clear_cache', 'SettingsController@Clear_Cache');

    // ------------------------------- subscriptions ------------------------\\

    Route::resource('subscriptions', 'SubscriptionController');
    Route::put('/subscriptions/{id}/status', 'SubscriptionController@updateStatus');

    // ------------------------------- error_logs ------------------------\\

    Route::get('/error-logs', 'ErrorLogController@index')->name('error_logs.index');

    // -------------------------- Reports ---------------------------

    Route::get('report/client', 'ReportController@Client_Report');
    Route::get('report/client/{id}', 'ReportController@Client_Report_detail');
    Route::get('report/client_sales', 'ReportController@Sales_Client');
    Route::get('report/client_payments', 'ReportController@Payments_Client');
    Route::get('report/client_quotations', 'ReportController@Quotations_Client');
    Route::get('report/client_returns', 'ReportController@Returns_Client');
    Route::get('report/provider', 'ReportController@Providers_Report');
    Route::get('report/provider/{id}', 'ReportController@Provider_Report_detail');
    Route::get('report/provider_purchases', 'ReportController@Purchases_Provider');
    Route::get('report/provider_payments', 'ReportController@Payments_Provider');
    Route::get('report/provider_returns', 'ReportController@Returns_Provider');
    Route::get('report/sales', 'ReportController@Report_Sales');
    Route::get('report/purchases', 'ReportController@Report_Purchases');
    Route::get('report/get_last_sales', 'ReportController@Get_last_Sales');
    Route::get('report/stock_alert', 'ReportController@Products_Alert');
    Route::get('report/payment_chart', 'ReportController@Payment_chart');
    Route::get('report/warehouse_report', 'ReportController@Warehouse_Report');
    Route::get('report/internal_location_report', 'ReportController@Internal_Location_Report');
    Route::get('report/sales_warehouse', 'ReportController@Sales_Warehouse');
    Route::get('report/purchases_warehouse', 'ReportController@Purchases_Warehouse');
    Route::get('report/quotations_warehouse', 'ReportController@Quotations_Warehouse');
    Route::get('report/returns_sale_warehouse', 'ReportController@Returns_Sale_Warehouse');
    Route::get('report/returns_purchase_warehouse', 'ReportController@Returns_Purchase_Warehouse');
    Route::get('report/expenses_warehouse', 'ReportController@Expenses_Warehouse');
    Route::get('report/warhouse_count_stock', 'ReportController@Warhouse_Count_Stock');
    Route::get('report/report_today', 'ReportController@report_today');
    Route::get('report/count_quantity_alert', 'ReportController@count_quantity_alert');
    Route::get('report/profit_and_loss', 'ReportController@ProfitAndLoss');
    Route::get('report/report_dashboard', 'ReportController@report_dashboard');
    Route::get('report/top_products', 'ReportController@report_top_products');
    Route::get('report/top_customers', 'ReportController@report_top_customers');
    Route::get('report/product_report', 'ReportController@product_report');
    Route::get('report/sale_products_details', 'ReportController@sale_products_details');
    Route::get('report/product_sales_report', 'ReportController@product_sales_report');
    Route::get('report/products_sold_summary', 'ReportController@products_sold_summary');
    Route::get('report/product_purchases_report', 'ReportController@product_purchases_report');

    Route::get('report/users', 'ReportController@users_Report');
    Route::get('report/stock', 'ReportController@stock_Report');
    Route::get('report/get_sales_by_user', 'ReportController@get_sales_by_user');
    Route::get('report/get_quotations_by_user', 'ReportController@get_quotations_by_user');
    Route::get('report/get_sales_return_by_user', 'ReportController@get_sales_return_by_user');
    Route::get('report/get_purchases_by_user', 'ReportController@get_purchases_by_user');
    Route::get('report/get_purchase_return_by_user', 'ReportController@get_purchase_return_by_user');
    Route::get('report/get_transfer_by_user', 'ReportController@get_transfer_by_user');
    Route::get('report/get_adjustment_by_user', 'ReportController@get_adjustment_by_user');

    Route::get('report/get_sales_by_product', 'ReportController@get_sales_by_product');
    Route::get('report/get_quotations_by_product', 'ReportController@get_quotations_by_product');

    Route::get('report/get_sales_return_by_product', 'ReportController@get_sales_return_by_product');
    Route::get('report/get_purchases_by_product', 'ReportController@get_purchases_by_product');
    Route::get('report/get_purchase_return_by_product', 'ReportController@get_purchase_return_by_product');
    Route::get('report/get_transfer_by_product', 'ReportController@get_transfer_by_product');
    Route::get('report/get_adjustment_by_product', 'ReportController@get_adjustment_by_product');
    Route::get('report/client_pdf/{id}', 'ReportController@download_report_client_pdf');
    Route::get('report/provider_pdf/{id}', 'ReportController@download_report_provider_pdf');
    Route::get('report/analytics_summary', 'ReportController@analyticsSummary');

    Route::get('report/inventory_valuation_summary', 'ReportController@inventory_valuation_summary');
    Route::get('report/stock_inventory_valuation', 'ReportController@stock_inventory_valuation');
    Route::get('report/expenses_report', 'ReportController@expenses_report');
    Route::get('report/deposits_report', 'ReportController@deposits_report');
    Route::get('report/report_transactions', 'ReportController@report_transactions');
    Route::get('report/sales_by_category_report', 'ReportController@sales_by_category_report');
    Route::get('report/sales_by_brand_report', 'ReportController@sales_by_brand_report');
    Route::get('report/seller_report', 'ReportController@seller_report');
    Route::get('report/attendance_summary', 'ReportController@attendance_summary');
    Route::get('report/inactive_customers', 'ReportController@inactiveCustomers');
    Route::get('report/zero_sales_products', 'ReportController@zeroSalesProducts');
    Route::get('report/dead_stock', 'ReportController@deadStock');
    Route::get('report/expiry', 'ReportController@expiryReport');
    // Pharmacy batch reports — register (cross-batch list) + history (per-batch movement log).
    Route::get('report/batches/register', 'BatchReportController@register');
    Route::get('report/batches/{id}/history', 'BatchReportController@history');
    Route::get('report/draft_invoices', 'ReportController@draftInvoices');
    Route::get('report/discount_summary', 'ReportController@discountSummary');
    Route::get('report/tax_summary', 'ReportController@taxSummary');
    Route::get('report/stock_aging', 'ReportController@stockAging');
    Route::get('report/stock_aging/filters', 'ReportController@stockAgingFilters');
    Route::get('report/cash_flow_report', 'ReportController@cash_flow_report');
    Route::get('report/return_ratio_report', 'ReportController@return_ratio_report');
    Route::get('report/stock_transfer', 'ReportController@stockTransferReport');
    Route::get('report/stock_adjustment', 'ReportController@stockAdjustmentReport');
    Route::get('report/top_suppliers', 'ReportController@topSuppliersReport');
    Route::get('report/customer_loyalty_points', 'ReportController@customerLoyaltyPoints');

    // Loyalty Rewards ("loyalty items") — admin catalog + redemptions + adjustments
    Route::get('loyalty/rewards', 'LoyaltyRewardController@index');
    Route::post('loyalty/rewards', 'LoyaltyRewardController@store');
    Route::put('loyalty/rewards/{id}', 'LoyaltyRewardController@update');
    Route::delete('loyalty/rewards/{id}', 'LoyaltyRewardController@destroy');
    Route::get('loyalty/redemptions', 'LoyaltyRewardController@redemptions');
    Route::post('loyalty/redemptions/{id}/fulfill', 'LoyaltyRewardController@fulfill');
    Route::post('loyalty/redemptions/{id}/cancel', 'LoyaltyRewardController@cancel');
    Route::post('loyalty/adjust', 'LoyaltyRewardController@adjust');
    Route::get('loyalty/clients', 'LoyaltyRewardController@searchClients');
    // POS reward redemption
    Route::get('loyalty/pos/rewards', 'LoyaltyRewardController@posRewards');
    Route::post('loyalty/pos/redeem', 'LoyaltyRewardController@posRedeem');
    Route::get('get_product_detail/{id}', 'ProductsController@Get_Products_Details');

    // Negative Stock
    Route::get('report/negative_stock', 'ReportController@negative_stock_report');

    // AI Reports
    Route::get('report-questions', 'ReportQuestionController@index');
    Route::post('report-questions/run', 'ReportQuestionController@run');

    // ------------------------------- Service & Maintenance ------------------------\\
    // Purely additive module: manages service jobs and dynamic checklists
    Route::resource('service_jobs', 'ServiceJobController');
    Route::resource('service_technicians', 'ServiceTechnicianController')->only(['index', 'store', 'update', 'destroy']);

    // Repair workflow actions
    Route::post('service_jobs/{id}/approve_quote', 'ServiceJobController@approveQuote');
    Route::post('service_jobs/{id}/decline_quote', 'ServiceJobController@declineQuote');
    Route::post('service_jobs/{id}/mark_delivered', 'ServiceJobController@markDelivered');
    Route::post('service_jobs/{id}/create_quotation', 'ServiceJobController@createQuotation');

    // Service job payments
    Route::get('service_jobs/{service_job}/payments', 'ServiceJobPaymentController@index');
    Route::post('service_jobs/{service_job}/payments', 'ServiceJobPaymentController@store');
    Route::put('service_jobs/{service_job}/payments/{id}', 'ServiceJobPaymentController@update');
    Route::delete('service_jobs/{service_job}/payments/{id}', 'ServiceJobPaymentController@destroy');

    // Service job photos
    Route::get('service_jobs/{service_job}/photos', 'ServiceJobPhotoController@index');
    Route::post('service_jobs/{service_job}/photos', 'ServiceJobPhotoController@store');
    Route::delete('service_jobs/{service_job}/photos/{id}', 'ServiceJobPhotoController@destroy');

    Route::get('service_checklist/categories', 'ServiceChecklistController@categoriesIndex');
    Route::post('service_checklist/categories', 'ServiceChecklistController@categoriesStore');
    Route::put('service_checklist/categories/{id}', 'ServiceChecklistController@categoriesUpdate');
    Route::delete('service_checklist/categories/{id}', 'ServiceChecklistController@categoriesDestroy');

    Route::get('service_checklist/items', 'ServiceChecklistController@itemsIndex');
    Route::post('service_checklist/items', 'ServiceChecklistController@itemsStore');
    Route::put('service_checklist/items/{id}', 'ServiceChecklistController@itemsUpdate');
    Route::delete('service_checklist/items/{id}', 'ServiceChecklistController@itemsDestroy');

    Route::get('service_checklist/options', 'ServiceChecklistController@options');

    Route::get('report/service_jobs', 'ServiceReportController@serviceJobs');
    Route::get('report/service_checklist_completion', 'ServiceReportController@checklistCompletion');
    Route::get('report/customer_maintenance_history', 'ServiceReportController@customerMaintenanceHistory');

    // ------------------------------- Serial / IMEI tracking ------------------------\\
    // Purely additive module: opt-in per product (is_imei) + global show_serial_tracking.
    Route::get('serial_numbers/available', 'SerialNumberController@available');
    Route::get('serial_numbers/for_sale', 'SerialNumberController@forSale');
    Route::get('serial_numbers/for_purchase', 'SerialNumberController@forPurchase');
    Route::post('serial_numbers/{id}/status', 'SerialNumberController@changeStatus');
    Route::resource('serial_numbers', 'SerialNumberController')->only(['index', 'show']);

    // Serial / IMEI reports
    Route::get('report/serials/available', 'SerialNumberReportController@available');
    Route::get('report/serials/sold', 'SerialNumberReportController@sold');
    Route::get('report/serials/movements', 'SerialNumberReportController@movements');
    Route::get('report/serials/inventory', 'SerialNumberReportController@inventory');

    // ------------------------------- payment_methods ------------------------\\
    // ------------------------------------------------------------------\\
    Route::put('payment_methods/{id}/active', 'PaymentMethodController@setActive');
    Route::resource('payment_methods', 'PaymentMethodController');

    // ------------------------------Employee------------------------------------\\

    Route::resource('employees', 'hrm\EmployeesController');
    Route::post('employees/import/csv', 'hrm\EmployeesController@import_employees');
    Route::post('employees/delete/by_selection', 'hrm\EmployeesController@delete_by_selection');
    Route::get('get_employees_by_department', "hrm\EmployeesController@Get_employees_by_department");
    Route::put('update_social_profile/{id}', "hrm\EmployeesController@update_social_profile");
    Route::get('get_experiences_by_employee', "hrm\EmployeesController@get_experiences_by_employee");
    Route::get('get_accounts_by_employee', "hrm\EmployeesController@get_accounts_by_employee");
    Route::get('Get_employees_by_company', "hrm\EmployeesController@Get_employees_by_company");

    // ------------------------------- Employee Experience ----------------\\
    // --------------------------------------------------------------------\\

    Route::resource('work_experience', 'hrm\EmployeeExperienceController');

    // ------------------------------- Employee Accounts bank ----------------\\
    // --------------------------------------------------------------------\\

    Route::resource('employee_account', 'hrm\EmployeeAccountController');

    // ------------------------------- company --------------------------\\
    // --------------------------------------------------------------------\\
    Route::resource('company', 'hrm\CompanyController');
    Route::get('get_all_company', "hrm\CompanyController@Get_all_Company");
    Route::post('company/delete/by_selection', "hrm\CompanyController@delete_by_selection");

    // ------------------------------- departments --------------------------\\
    // --------------------------------------------------------------------\\
    Route::resource('departments', 'hrm\DepartmentsController');
    Route::get('get_all_departments', "hrm\DepartmentsController@Get_all_Departments");
    Route::get('get_departments_by_company', "hrm\DepartmentsController@Get_departments_by_company")->name('Get_departments_by_company');
    Route::post('departments/delete/by_selection', "hrm\DepartmentsController@delete_by_selection");

    // ------------------------------- designations --------------------------\\
    // --------------------------------------------------------------------\\
    Route::resource('designations', 'hrm\DesignationsController');
    Route::get('get_designations_by_department', "hrm\DesignationsController@Get_designations_by_department");
    Route::post('designations/delete/by_selection', "hrm\DesignationsController@delete_by_selection");

    // ------------------------------- office_shift ------------------\\
    // ----------------------------------------------------------------\\

    Route::resource('office_shift', 'hrm\OfficeShiftController');
    Route::post('office_shift/delete/by_selection', "hrm\OfficeShiftController@delete_by_selection");

    // ------------------------------- Attendances ------------------------\\
    // --------------------------------------------------------------------\\
    Route::resource('attendances', 'hrm\AttendancesController');
    Route::get('daily_attendance', "hrm\AttendancesController@daily_attendance")->name('daily_attendance');
    Route::post('attendances/delete/by_selection', "hrm\AttendancesController@delete_by_selection");

    // ------------------------------- Request leave  -----------------------\\
    // ----------------------------------------------------------------\\

    Route::resource('leave', 'hrm\LeaveController');
    Route::resource('leave_type', 'hrm\LeaveTypeController');
    Route::post('leave/delete/by_selection', "hrm\LeaveController@delete_by_selection");
    Route::post('leave_type/delete/by_selection', "hrm\LeaveTypeController@delete_by_selection");

    // ------------------------------- holiday ----------------------\\
    // ----------------------------------------------------------------\\

    Route::resource('holiday', 'hrm\HolidayController');
    Route::post('holiday/delete/by_selection', "hrm\HolidayController@delete_by_selection");

    // ------------------------------- payroll ----------------------\\
    // ----------------------------------------------------------------\\

    Route::resource('payroll', 'hrm\PayrollController');

    // ------------------------------- core --------------------------\\
    // --------------------------------------------------------------------\\

    Route::prefix('core')->group(function () {

        Route::get('get_departments_by_company', "hrm\CoreController@Get_departments_by_company");
        Route::get('get_designations_by_department', "hrm\CoreController@Get_designations_by_department");
        Route::get('get_office_shift_by_company', "hrm\CoreController@Get_office_shift_by_company");
        Route::get('get_employees_by_company', "hrm\CoreController@Get_employees_by_company");

    });

    // ------------------------------- CLIENTS --------------------------\\
    // ------------------------------------------------------------------\\

    Route::resource('clients', 'ClientController');
    Route::post('customers/import', 'ClientController@import');
    Route::get('get_clients_without_paginate', 'ClientController@Get_Clients_Without_Paginate');
    Route::post('clients/delete/by_selection', 'ClientController@delete_by_selection');
    Route::post('clients_pay_due', 'ClientController@clients_pay_due');
    Route::post('clients_pay_return_due', 'ClientController@pay_sale_return_due');
    Route::get('get_client_store_data/{id}', 'ClientController@get_client_store_data');
    Route::get('get_points_client/{id}', 'ClientController@getPoints');
    Route::post('customers/{id}/update-points', 'ClientController@updatePoints');
    Route::post('customers/{id}/adjust-opening-balance', 'ClientController@adjustOpeningBalance');

    // Customer Ledger (separate endpoints)
    Route::get('/sales_client', 'ClientController@salesByClient');
    Route::get('/payments_client', 'ClientController@paymentsByClient');
    Route::get('/service_jobs_client', 'ClientController@serviceJobsByClient');
    Route::get('/quotations_client', 'ClientController@quotationsByClient');
    Route::get('/returns_client', 'ClientController@returnsByClient');
    Route::get('/payment_returns_client', 'ClientController@paymentReturnsByClient');

    // Basic client info for header (optional but recommended)
    Route::get('clients/{id}/brief', 'ClientController@clientBrief');

    // Client Portal admin controls (enable/disable)
    Route::get('clients/{id}/portal-status', [\App\Http\Controllers\Api\Portal\PortalAdminController::class, 'getStatus']);
    Route::post('clients/{id}/portal-enable', [\App\Http\Controllers\Api\Portal\PortalAdminController::class, 'enable']);
    Route::post('clients/{id}/portal-disable', [\App\Http\Controllers\Api\Portal\PortalAdminController::class, 'disable']);
    Route::get('/client_ledger_pdf', 'ClientController@export');

    // ------------------------------- CLIENTS Ecommerce--------------------------\\
    // ------------------------------------------------------------------\\

    Route::resource('clients_without_ecommerce', 'ClientsEcommerceController');
    Route::get('ecommerce_clients', 'ClientsEcommerceController@accounts');
    Route::put('ecommerce_clients/{id}', 'ClientsEcommerceController@updateAccount');
    Route::delete('ecommerce_clients/{id}', 'ClientsEcommerceController@destroyAccount');
    // ------------------------------- Providers --------------------------\\
    // --------------------------------------------------------------------\\

    Route::resource('providers', 'ProvidersController');
    Route::post('suppliers/import', 'ProvidersController@import');
    Route::post('providers/{id}/adjust-opening-balance', 'ProvidersController@adjustOpeningBalance');
    // Supplier details (People scope) — gated by Suppliers_view, separate from reports.
    Route::get('provider_details/summary/{id}', 'ProvidersController@supplierDetailsSummary');
    Route::get('provider_details/purchases', 'ProvidersController@supplierPurchases');
    Route::get('provider_details/payments', 'ProvidersController@supplierPayments');
    Route::get('provider_details/returns', 'ProvidersController@supplierReturns');

    Route::post('providers/delete/by_selection', 'ProvidersController@delete_by_selection');
    Route::post('pay_supplier_due', 'ProvidersController@pay_supplier_due');
    Route::post('pay_purchase_return_due', 'ProvidersController@pay_purchase_return_due');

    // ------------------------------- Custom Fields --------------------------\\
    // --------------------------------------------------------------------\\

    // Specific routes must come before resource route to avoid conflicts
    Route::get('custom-field-values', 'CustomFieldController@getValues');
    Route::post('custom-field-values', 'CustomFieldController@saveValues');
    Route::resource('custom-fields', 'CustomFieldController');

    // ---------------------- POS (point of sales) ----------------------\\
    // ------------------------------------------------------------------\\

    Route::post('pos/create_pos', 'PosController@CreatePOS');
    Route::get('pos/wallet-balance/{client}', 'PosController@walletBalance');
    Route::get('pos/client-history/{client}', 'PosController@clientHistory');
    Route::get('pos/client-history/sale/{sale}', 'PosController@clientHistorySale');
    Route::get('pos/get_products_pos', 'PosController@GetProductsByParametre');
    Route::get('pos/get_products_pos_changes', 'PosController@GetProductsChanges');
    Route::get('pos/data_create_pos', 'PosController@GetELementPos');

    // ----------------------Draft -------------------------------------\\
    // ------------------------------------------------------------------\\
    Route::post('pos/create_draft', 'PosController@CreateDraft');
    Route::get('get_draft_sales', 'PosController@get_draft_sales');
    Route::delete('remove_draft_sale/{id}', 'PosController@remove_draft_sale');
    Route::get('pos/data_draft_convert_sale/{id}', 'PosController@data_draft_convert_sale');
    Route::post('pos/submit_sale_from_draft', 'PosController@submit_sale_from_draft');

    // ---------------------- Cash Registers (optional module) ----------------------\\
    // Fully additive; no changes to existing tables or logic
    Route::post('cash-registers/open', 'CashRegisterController@openRegister');
    Route::post('cash-registers/close', 'CashRegisterController@closeRegister');
    Route::get('cash-registers/current/{user_id}', 'CashRegisterController@getCurrentRegister');
    Route::post('cash-registers/cash-move', 'CashRegisterController@cashInOut');
    Route::get('report/cash_registers', 'CashRegisterController@report');
    Route::get('report/warranty_guarantee', 'ReportController@warrantyGuaranteeReport');

    // ------------------------------- Contracts -----------------------\\
    Route::get('contracts/dashboard', 'ContractController@dashboard');
    Route::get('contracts/merge-fields', 'ContractController@mergeFields');
    Route::get('contracts/{id}/pdf', 'ContractController@pdf');
    Route::get('contracts/{id}/attachments', 'ContractController@attachmentsIndex');
    Route::post('contracts/{id}/attachments', 'ContractController@attachmentsStore');
    Route::delete('contracts/{contractId}/attachments/{attachmentId}', 'ContractController@attachmentsDestroy');
    Route::get('contracts/{contractId}/attachments/{attachmentId}/download', 'ContractController@attachmentsDownload');
    Route::get('contracts/{id}/comments', 'ContractController@commentsIndex');
    Route::post('contracts/{id}/comments', 'ContractController@commentsStore');
    Route::delete('contracts/{contractId}/comments/{commentId}', 'ContractController@commentsDestroy');
    Route::get('contracts/{id}/renewals', 'ContractController@renewalsIndex');
    Route::post('contracts/{id}/renewals', 'ContractController@renewalsStore');
    Route::get('contracts/{id}/tasks', 'ContractController@tasksIndex');
    Route::post('contracts/{id}/tasks', 'ContractController@tasksStore');
    Route::put('contracts/{contractId}/tasks/{taskId}', 'ContractController@tasksUpdate');
    Route::delete('contracts/{contractId}/tasks/{taskId}', 'ContractController@tasksDestroy');
    Route::get('contracts/{id}/notes', 'ContractController@notesIndex');
    Route::post('contracts/{id}/notes', 'ContractController@notesStore');
    Route::delete('contracts/{contractId}/notes/{noteId}', 'ContractController@notesDestroy');
    Route::get('contracts-templates', 'ContractController@templatesIndex');
    Route::post('contracts-templates', 'ContractController@templatesStore');
    Route::get('contracts-templates/{templateId}', 'ContractController@templatesShow');
    Route::put('contracts-templates/{templateId}', 'ContractController@templatesUpdate');
    Route::delete('contracts-templates/{templateId}', 'ContractController@templatesDestroy');
    Route::get('contracts/{contractId}/templates/{templateId}/render', 'ContractController@templateRender');
    Route::get('contracts/{contractId}/templates/{templateId}/pdf', 'ContractController@templatePdf');
    Route::post('contracts/delete/by_selection', 'ContractController@delete_by_selection');
    Route::resource('contracts', 'ContractController');

    // ------------------------------- PROMOTIONS --------------------------\\
    Route::post('promotions/preview_warehouses', 'PromotionsController@previewWarehouses');
    Route::post('promotions/applicable', 'PromotionsController@applicable');
    Route::post('promotions/validate_code', 'PromotionsController@validateCode');
    Route::get('promotions/usages', 'PromotionsController@usages');
    Route::get('promotions/usages_summary', 'PromotionsController@usageSummary');
    Route::post('promotions/{id}/toggle', 'PromotionsController@toggle');
    Route::resource('promotions', 'PromotionsController');

    // --------------------------- DOCUMENT ARCHIVE ------------------------\\
    // Literal segments come first: `documents/stats` would otherwise be eaten
    // by the resource route's {document} wildcard.
    Route::get('documents/stats', 'DocumentArchiveController@stats');
    Route::get('documents/folders', 'DocumentArchiveController@folders');
    Route::post('documents/folders', 'DocumentArchiveController@storeFolder');
    Route::put('documents/folders/{id}', 'DocumentArchiveController@updateFolder');
    Route::delete('documents/folders/{id}', 'DocumentArchiveController@destroyFolder');
    Route::post('documents/delete/by_selection', 'DocumentArchiveController@deleteBySelection');
    Route::post('documents/move/by_selection', 'DocumentArchiveController@moveBySelection');
    // No preview route: files are served directly from public/images/documents,
    // so the UI reads `url` off the row. download() only forces the attachment.
    Route::get('documents/{id}/download', 'DocumentArchiveController@download');
    Route::post('documents/{id}/star', 'DocumentArchiveController@toggleStar');
    Route::post('documents/{id}/versions', 'DocumentArchiveController@storeVersion');
    Route::get('documents/{id}/versions/{versionId}/download', 'DocumentArchiveController@downloadVersion');
    Route::post('documents/{id}/versions/{versionId}/restore', 'DocumentArchiveController@restoreVersion');
    // Metadata updates arrive as PUT/JSON; new files always go through POST
    // (multipart), which PHP cannot parse on a PUT body.
    Route::get('documents', 'DocumentArchiveController@index');
    Route::post('documents', 'DocumentArchiveController@store');
    Route::get('documents/{id}', 'DocumentArchiveController@show');
    Route::put('documents/{id}', 'DocumentArchiveController@update');
    Route::delete('documents/{id}', 'DocumentArchiveController@destroy');

    // ----------------------- VEHICLE & FLEET MANAGEMENT ------------------\\
    // Literal segments first — the {id} routes would otherwise swallow them.
    Route::get('fleet/dashboard', 'FleetVehicleController@dashboard');
    Route::get('fleet/meta', 'FleetVehicleController@meta');

    Route::get('fleet/reports/costs', 'FleetReportController@costs');
    Route::get('fleet/reports/fuel', 'FleetReportController@fuel');
    Route::get('fleet/reports/maintenance', 'FleetReportController@maintenance');
    Route::get('fleet/reports/drivers', 'FleetReportController@drivers');

    Route::post('fleet/maintenances/delete/by_selection', 'FleetMaintenanceController@deleteBySelection');
    Route::get('fleet/maintenances', 'FleetMaintenanceController@index');
    Route::post('fleet/maintenances', 'FleetMaintenanceController@store');
    Route::put('fleet/maintenances/{id}', 'FleetMaintenanceController@update');
    Route::delete('fleet/maintenances/{id}', 'FleetMaintenanceController@destroy');

    Route::post('fleet/fuel-logs/delete/by_selection', 'FleetFuelLogController@deleteBySelection');
    Route::get('fleet/fuel-logs', 'FleetFuelLogController@index');
    Route::post('fleet/fuel-logs', 'FleetFuelLogController@store');
    Route::put('fleet/fuel-logs/{id}', 'FleetFuelLogController@update');
    Route::delete('fleet/fuel-logs/{id}', 'FleetFuelLogController@destroy');

    Route::post('fleet/assignments/delete/by_selection', 'FleetAssignmentController@deleteBySelection');
    Route::post('fleet/assignments/{id}/close', 'FleetAssignmentController@close');
    Route::get('fleet/assignments', 'FleetAssignmentController@index');
    Route::post('fleet/assignments', 'FleetAssignmentController@store');
    Route::put('fleet/assignments/{id}', 'FleetAssignmentController@update');
    Route::delete('fleet/assignments/{id}', 'FleetAssignmentController@destroy');

    // Photos arrive as multipart, which PHP cannot parse from a PUT body — the
    // edit form therefore POSTs with _method spoofing handled below.
    Route::post('fleet/vehicles/delete/by_selection', 'FleetVehicleController@deleteBySelection');
    Route::get('fleet/vehicles/{id}/edit', 'FleetVehicleController@edit');
    Route::post('fleet/vehicles/{id}/update', 'FleetVehicleController@update');
    Route::get('fleet/vehicles', 'FleetVehicleController@index');
    Route::post('fleet/vehicles', 'FleetVehicleController@store');
    Route::get('fleet/vehicles/{id}', 'FleetVehicleController@show');
    Route::delete('fleet/vehicles/{id}', 'FleetVehicleController@destroy');

    // ---------------------- HOSPITAL MANAGEMENT (HMS) --------------------\\
    // Literal segments are declared before every {id} route in each group,
    // otherwise the wildcard swallows them.
    Route::get('hospital/dashboard', 'HospitalDashboardController@dashboard');
    Route::get('hospital/meta', 'HospitalDashboardController@meta');
    Route::get('hospital/available-beds', 'HospitalDashboardController@availableBeds');
    Route::get('hospital/search/patients', 'HospitalDashboardController@searchPatients');
    Route::get('hospital/search/medicines', 'HospitalDashboardController@searchMedicines');

    Route::get('hospital/reports/doctors', 'HospitalReportController@doctors');
    Route::get('hospital/reports/revenue', 'HospitalReportController@revenue');
    Route::get('hospital/reports/occupancy', 'HospitalReportController@occupancy');
    Route::get('hospital/reports/patients', 'HospitalReportController@patients');
    Route::get('hospital/reports/collections', 'HospitalReportController@collections');

    Route::post('hospital/departments/delete/by_selection', 'HospitalDepartmentController@deleteBySelection');
    Route::get('hospital/departments', 'HospitalDepartmentController@index');
    Route::post('hospital/departments', 'HospitalDepartmentController@store');
    Route::put('hospital/departments/{id}', 'HospitalDepartmentController@update');
    Route::delete('hospital/departments/{id}', 'HospitalDepartmentController@destroy');

    // Doctor photos arrive as multipart, which PHP cannot parse off a PUT body.
    Route::post('hospital/doctors/delete/by_selection', 'HospitalDoctorController@deleteBySelection');
    Route::get('hospital/doctors/{id}/schedule', 'HospitalDoctorController@schedule');
    Route::post('hospital/doctors/{id}/update', 'HospitalDoctorController@update');
    Route::get('hospital/doctors', 'HospitalDoctorController@index');
    Route::post('hospital/doctors', 'HospitalDoctorController@store');
    Route::get('hospital/doctors/{id}', 'HospitalDoctorController@show');
    Route::delete('hospital/doctors/{id}', 'HospitalDoctorController@destroy');

    Route::post('hospital/patients/delete/by_selection', 'HospitalPatientController@deleteBySelection');
    Route::get('hospital/patients/{id}/timeline', 'HospitalPatientController@timeline');
    Route::get('hospital/patients/{id}/edit', 'HospitalPatientController@edit');
    Route::post('hospital/patients/{id}/update', 'HospitalPatientController@update');
    Route::get('hospital/patients', 'HospitalPatientController@index');
    Route::post('hospital/patients', 'HospitalPatientController@store');
    Route::get('hospital/patients/{id}', 'HospitalPatientController@show');
    Route::delete('hospital/patients/{id}', 'HospitalPatientController@destroy');

    Route::get('hospital/appointments/board', 'HospitalAppointmentController@board');
    Route::post('hospital/appointments/delete/by_selection', 'HospitalAppointmentController@deleteBySelection');
    Route::post('hospital/appointments/{id}/status', 'HospitalAppointmentController@setStatus');
    Route::get('hospital/appointments', 'HospitalAppointmentController@index');
    Route::post('hospital/appointments', 'HospitalAppointmentController@store');
    Route::put('hospital/appointments/{id}', 'HospitalAppointmentController@update');
    Route::delete('hospital/appointments/{id}', 'HospitalAppointmentController@destroy');

    Route::post('hospital/visits/delete/by_selection', 'HospitalVisitController@deleteBySelection');
    Route::get('hospital/visits/from-appointment/{appointmentId}', 'HospitalVisitController@fromAppointment');
    Route::get('hospital/visits', 'HospitalVisitController@index');
    Route::post('hospital/visits', 'HospitalVisitController@store');
    Route::get('hospital/visits/{id}', 'HospitalVisitController@show');
    Route::put('hospital/visits/{id}', 'HospitalVisitController@update');
    Route::delete('hospital/visits/{id}', 'HospitalVisitController@destroy');

    Route::get('hospital/beds', 'HospitalWardController@beds');
    Route::post('hospital/beds', 'HospitalWardController@storeBed');
    Route::put('hospital/beds/{id}', 'HospitalWardController@updateBed');
    Route::delete('hospital/beds/{id}', 'HospitalWardController@destroyBed');
    Route::get('hospital/wards', 'HospitalWardController@index');
    Route::post('hospital/wards', 'HospitalWardController@store');
    Route::put('hospital/wards/{id}', 'HospitalWardController@update');
    Route::delete('hospital/wards/{id}', 'HospitalWardController@destroy');

    Route::post('hospital/admissions/{id}/discharge', 'HospitalAdmissionController@discharge');
    Route::post('hospital/admissions/{id}/transfer', 'HospitalAdmissionController@transfer');
    Route::get('hospital/admissions', 'HospitalAdmissionController@index');
    Route::post('hospital/admissions', 'HospitalAdmissionController@store');
    Route::put('hospital/admissions/{id}', 'HospitalAdmissionController@update');
    Route::delete('hospital/admissions/{id}', 'HospitalAdmissionController@destroy');

    Route::get('hospital/lab-tests', 'HospitalLabController@tests');
    Route::post('hospital/lab-tests', 'HospitalLabController@storeTest');
    Route::put('hospital/lab-tests/{id}', 'HospitalLabController@updateTest');
    Route::delete('hospital/lab-tests/{id}', 'HospitalLabController@destroyTest');
    Route::post('hospital/lab-orders/delete/by_selection', 'HospitalLabController@deleteBySelection');
    Route::post('hospital/lab-orders/{id}/results', 'HospitalLabController@saveResults');
    Route::get('hospital/lab-orders', 'HospitalLabController@orders');
    Route::post('hospital/lab-orders', 'HospitalLabController@storeOrder');
    Route::get('hospital/lab-orders/{id}', 'HospitalLabController@showOrder');
    Route::put('hospital/lab-orders/{id}', 'HospitalLabController@updateOrder');
    Route::delete('hospital/lab-orders/{id}', 'HospitalLabController@destroyOrder');

    Route::get('hospital/payments', 'HospitalBillingController@payments');
    Route::post('hospital/invoices/draft-from', 'HospitalBillingController@draftFrom');
    Route::post('hospital/invoices/{id}/payments', 'HospitalBillingController@storePayment');
    Route::delete('hospital/invoices/{invoiceId}/payments/{paymentId}', 'HospitalBillingController@destroyPayment');
    Route::get('hospital/invoices', 'HospitalBillingController@index');
    Route::post('hospital/invoices', 'HospitalBillingController@store');
    Route::get('hospital/invoices/{id}', 'HospitalBillingController@show');
    Route::put('hospital/invoices/{id}', 'HospitalBillingController@update');
    Route::delete('hospital/invoices/{id}', 'HospitalBillingController@destroy');

    // ------------------------ SCHOOL MANAGEMENT (SMS) --------------------\\
    // Literal segments are declared before every {id} route in each group,
    // otherwise the wildcard swallows them.
    Route::get('school/dashboard', 'SchoolDashboardController@dashboard');
    Route::get('school/meta', 'SchoolDashboardController@meta');
    Route::get('school/search/students', 'SchoolDashboardController@searchStudents');

    Route::get('school/reports/enrollment', 'SchoolReportController@enrollment');
    Route::get('school/reports/attendance', 'SchoolReportController@attendance');
    Route::get('school/reports/absentees', 'SchoolReportController@absentees');
    Route::get('school/reports/performance', 'SchoolReportController@performance');
    Route::get('school/reports/fees', 'SchoolReportController@fees');
    Route::get('school/reports/defaulters', 'SchoolReportController@defaulters');
    Route::get('school/reports/teachers', 'SchoolReportController@teachers');

    // Academic structure
    Route::get('school/years', 'SchoolAcademicController@years');
    Route::post('school/years', 'SchoolAcademicController@storeYear');
    Route::put('school/years/{id}', 'SchoolAcademicController@updateYear');
    Route::delete('school/years/{id}', 'SchoolAcademicController@destroyYear');
    Route::get('school/classes', 'SchoolAcademicController@classes');
    Route::post('school/classes', 'SchoolAcademicController@storeClass');
    Route::put('school/classes/{id}', 'SchoolAcademicController@updateClass');
    Route::delete('school/classes/{id}', 'SchoolAcademicController@destroyClass');
    Route::get('school/sections', 'SchoolAcademicController@sections');
    Route::post('school/sections', 'SchoolAcademicController@storeSection');
    Route::put('school/sections/{id}', 'SchoolAcademicController@updateSection');
    Route::delete('school/sections/{id}', 'SchoolAcademicController@destroySection');
    Route::get('school/subjects', 'SchoolAcademicController@subjects');
    Route::post('school/subjects', 'SchoolAcademicController@storeSubject');
    Route::put('school/subjects/{id}', 'SchoolAcademicController@updateSubject');
    Route::delete('school/subjects/{id}', 'SchoolAcademicController@destroySubject');

    // Teachers — photos are multipart, which PHP cannot parse off a PUT body.
    Route::post('school/teachers/delete/by_selection', 'SchoolTeacherController@deleteBySelection');
    Route::get('school/teachers/{id}/timetable', 'SchoolTeacherController@timetable');
    Route::post('school/teachers/{id}/update', 'SchoolTeacherController@update');
    Route::get('school/teachers', 'SchoolTeacherController@index');
    Route::post('school/teachers', 'SchoolTeacherController@store');
    Route::delete('school/teachers/{id}', 'SchoolTeacherController@destroy');

    // Students + enrolment
    Route::post('school/students/delete/by_selection', 'SchoolStudentController@deleteBySelection');
    Route::get('school/students/{id}/timeline', 'SchoolStudentController@timeline');
    Route::get('school/students/{id}/edit', 'SchoolStudentController@edit');
    Route::post('school/students/{id}/update', 'SchoolStudentController@update');
    Route::get('school/students', 'SchoolStudentController@index');
    Route::post('school/students', 'SchoolStudentController@store');
    Route::get('school/students/{id}', 'SchoolStudentController@show');
    Route::delete('school/students/{id}', 'SchoolStudentController@destroy');
    Route::post('school/enrollments/promote', 'SchoolStudentController@promote');
    Route::get('school/enrollments', 'SchoolStudentController@enrollments');
    Route::post('school/enrollments', 'SchoolStudentController@storeEnrollment');
    Route::put('school/enrollments/{id}', 'SchoolStudentController@updateEnrollment');
    Route::delete('school/enrollments/{id}', 'SchoolStudentController@destroyEnrollment');

    // Attendance
    Route::get('school/attendance/register', 'SchoolAttendanceController@register');
    Route::post('school/attendance/register', 'SchoolAttendanceController@save');
    Route::get('school/attendance/summary', 'SchoolAttendanceController@summary');

    // Timetable
    Route::get('school/timetable', 'SchoolTimetableController@index');
    Route::post('school/timetable', 'SchoolTimetableController@store');
    Route::put('school/timetable/{id}', 'SchoolTimetableController@update');
    Route::delete('school/timetable/{id}', 'SchoolTimetableController@destroy');

    // Exams, papers and results
    Route::get('school/exams/{examId}/report-card', 'SchoolExamController@reportCard');
    Route::post('school/exams/{examId}/papers/generate', 'SchoolExamController@generatePapers');
    Route::get('school/exams/{examId}/papers/{paperId}/sheet', 'SchoolExamController@sheet');
    Route::post('school/exams/{examId}/papers/{paperId}/results', 'SchoolExamController@saveResults');
    Route::get('school/exams/{examId}/papers', 'SchoolExamController@papers');
    Route::post('school/exams/{examId}/papers', 'SchoolExamController@storePaper');
    Route::put('school/exams/{examId}/papers/{paperId}', 'SchoolExamController@updatePaper');
    Route::delete('school/exams/{examId}/papers/{paperId}', 'SchoolExamController@destroyPaper');
    Route::get('school/exams', 'SchoolExamController@index');
    Route::post('school/exams', 'SchoolExamController@store');
    Route::put('school/exams/{id}', 'SchoolExamController@update');
    Route::delete('school/exams/{id}', 'SchoolExamController@destroy');

    // Fees
    Route::get('school/fee-structures', 'SchoolFeeController@structures');
    Route::post('school/fee-structures', 'SchoolFeeController@storeStructure');
    Route::put('school/fee-structures/{id}', 'SchoolFeeController@updateStructure');
    Route::delete('school/fee-structures/{id}', 'SchoolFeeController@destroyStructure');
    Route::get('school/fee-payments', 'SchoolFeeController@payments');
    Route::post('school/fee-invoices/generate', 'SchoolFeeController@generateInvoices');
    Route::post('school/fee-invoices/{id}/payments', 'SchoolFeeController@storePayment');
    Route::delete('school/fee-invoices/{invoiceId}/payments/{paymentId}', 'SchoolFeeController@destroyPayment');
    Route::get('school/fee-invoices', 'SchoolFeeController@invoices');
    Route::post('school/fee-invoices', 'SchoolFeeController@storeInvoice');
    Route::get('school/fee-invoices/{id}', 'SchoolFeeController@showInvoice');
    Route::put('school/fee-invoices/{id}', 'SchoolFeeController@updateInvoice');
    Route::delete('school/fee-invoices/{id}', 'SchoolFeeController@destroyInvoice');

    // ------------------------------- Project -----------------------\\
    // ----------------------------------------------------------------\\

    // --------------------- PROJECTS MANAGEMENT (workspace) ---------------\\
    // Declared BEFORE Route::resource('projects') — the resource's {project}
    // wildcard would otherwise swallow these literal segments.
    Route::get('projects/dashboard', 'ProjectWorkspaceController@dashboard');
    Route::get('projects/meta', 'ProjectWorkspaceController@meta');
    Route::get('projects/reports/delivery', 'ProjectWorkspaceController@projectReport');
    Route::get('projects/reports/workload', 'ProjectWorkspaceController@workloadReport');
    Route::get('projects/reports/milestones', 'ProjectWorkspaceController@milestoneReport');

    Route::post('project_milestones/delete/by_selection', 'ProjectMilestoneController@deleteBySelection');
    Route::post('project_milestones/{id}/status', 'ProjectMilestoneController@setStatus');
    Route::get('project_milestones', 'ProjectMilestoneController@index');
    Route::post('project_milestones', 'ProjectMilestoneController@store');
    Route::put('project_milestones/{id}', 'ProjectMilestoneController@update');
    Route::delete('project_milestones/{id}', 'ProjectMilestoneController@destroy');

    Route::post('project_time_logs/delete/by_selection', 'ProjectTimeLogController@deleteBySelection');
    Route::get('project_time_logs', 'ProjectTimeLogController@index');
    Route::post('project_time_logs', 'ProjectTimeLogController@store');
    Route::put('project_time_logs/{id}', 'ProjectTimeLogController@update');
    Route::delete('project_time_logs/{id}', 'ProjectTimeLogController@destroy');

    Route::resource('projects', 'ProjectController');

    Route::post('projects/delete/by_selection', 'ProjectController@delete_by_selection');
    Route::post('project_discussions', 'ProjectController@Create_project_discussions');
    Route::delete('project_discussions/{id}', 'ProjectController@destroy_project_discussion');

    Route::post('project_issues', 'ProjectController@Create_project_issues');
    Route::put('project_issues/{id}', 'ProjectController@Update_project_issues');
    Route::delete('project_issues/{id}', 'ProjectController@destroy_project_issues');

    Route::post('project_documents', 'ProjectController@Create_project_documents');
    Route::delete('project_documents/{id}', 'ProjectController@destroy_project_documents');

    // ------------------------------- Task -----------------------\\
    // ----------------------------------------------------------------\\

    Route::resource('tasks', 'TaskController');
    Route::put('update_task_status/{id}', 'TaskController@update_task_status');

    Route::post('tasks/delete/by_selection', 'TaskController@delete_by_selection');
    Route::get('tasks_kanban', 'TaskController@tasks_kanban')->name('tasks_kanban');
    Route::post('task_change_status', 'TaskController@task_change_status')->name('task_change_status');

    Route::post('task_discussions', 'TaskController@Create_task_discussions');
    Route::delete('task_discussions/{id}', 'TaskController@destroy_task_discussion');

    Route::post('task_documents', 'TaskController@Create_task_documents');
    Route::delete('task_documents/{id}', 'TaskController@destroy_task_documents');

    // ------------------------------- Bookings (simple) -----------------------\\
    // ------------------------------------------------------------------------\\
    Route::get('bookings', 'BookingController@index');
    Route::post('bookings', 'BookingController@store');
    Route::get('bookings/create', 'BookingController@create');
    Route::get('bookings/{id}', 'BookingController@show');
    Route::get('bookings/{id}/edit', 'BookingController@edit');
    Route::put('bookings/{id}', 'BookingController@update');
    Route::delete('bookings/{id}', 'BookingController@destroy');
    Route::put('bookings/{id}/status', 'BookingController@changeStatus');
    Route::post('bookings_send_email', 'BookingController@Send_Email');

    // ------------------------------- Trays --------------------------\\
    // ------------------------------------------------------------------\\
    Route::post('trays/delete/by_selection', 'TraysController@delete_by_selection');
    Route::resource('trays', 'TraysController');

    // ------------------------------- Assets --------------------------\\
    // ------------------------------------------------------------------\\

    Route::get('assets/due', 'AssetController@due');
    Route::get('assets/schedule-info', 'AssetController@scheduleInfo');
    Route::post('assets/run-validation-due', 'AssetController@runValidationDue');
    Route::resource('assets', 'AssetController');
    Route::post('assets/delete/by_selection', 'AssetController@delete_by_selection');
    Route::get('assets_warehouses', 'AssetController@warehouses');

    // ------------------------------- Asset Management --------------------------\

    // Literal segments before the resource routes, or `assets/dashboard` is
    // read as `assets/{id}`.
    Route::get('assets/workspace/dashboard', 'AssetWorkspaceController@dashboard');
    Route::get('assets/workspace/meta', 'AssetWorkspaceController@meta');
    Route::get('assets/workspace/details/{id}', 'AssetWorkspaceController@details');
    Route::post('assets/workspace/dispose/{id}', 'AssetWorkspaceController@dispose');
    Route::get('assets/workspace/report/register', 'AssetWorkspaceController@registerReport');
    Route::get('assets/workspace/report/maintenance', 'AssetWorkspaceController@maintenanceReport');
    Route::get('assets/workspace/report/custody', 'AssetWorkspaceController@custodyReport');

    Route::post('asset_assignments/delete/by_selection', 'AssetAssignmentController@deleteBySelection');
    Route::post('asset_assignments/{id}/checkin', 'AssetAssignmentController@checkin');
    Route::get('asset_assignments', 'AssetAssignmentController@index');
    Route::post('asset_assignments', 'AssetAssignmentController@store');
    Route::put('asset_assignments/{id}', 'AssetAssignmentController@update');
    Route::delete('asset_assignments/{id}', 'AssetAssignmentController@destroy');

    Route::post('asset_maintenances/delete/by_selection', 'AssetMaintenanceController@deleteBySelection');
    Route::post('asset_maintenances/{id}/status', 'AssetMaintenanceController@setStatus');
    Route::get('asset_maintenances', 'AssetMaintenanceController@index');
    Route::post('asset_maintenances', 'AssetMaintenanceController@store');
    Route::put('asset_maintenances/{id}', 'AssetMaintenanceController@update');
    Route::delete('asset_maintenances/{id}', 'AssetMaintenanceController@destroy');

    Route::post('asset_transfers/delete/by_selection', 'AssetTransferController@deleteBySelection');
    Route::get('asset_transfers', 'AssetTransferController@index');
    Route::post('asset_transfers', 'AssetTransferController@store');
    Route::delete('asset_transfers/{id}', 'AssetTransferController@destroy');

    // ------------------------------- Assets Category --------------------------\\
    // ------------------------------------------------------------------\\
    Route::resource('assets_category', 'CategoryAssetController');

    // ------------------------------- PRODUCTS --------------------------\\
    // ------------------------------------------------------------------\\

    Route::resource('products', 'ProductsController');
    Route::post('products/{id}/duplicate', 'ProductsController@duplicate');

    // Vehicle Fitment (catalog, per-product fitments, POS lookup)
    Route::get('vehicles/catalog', 'VehicleFitmentController@catalog');
    Route::get('vehicles/lookup', 'VehicleFitmentController@lookup');
    Route::post('vehicles/makes', 'VehicleFitmentController@storeMake');
    Route::put('vehicles/makes/{id}', 'VehicleFitmentController@updateMake');
    Route::delete('vehicles/makes/{id}', 'VehicleFitmentController@destroyMake');
    Route::post('vehicles/models', 'VehicleFitmentController@storeModel');
    Route::put('vehicles/models/{id}', 'VehicleFitmentController@updateModel');
    Route::delete('vehicles/models/{id}', 'VehicleFitmentController@destroyModel');
    Route::post('vehicles/incompatible_ids', 'VehicleFitmentController@incompatibleIds');
    Route::get('products/{id}/fitments', 'VehicleFitmentController@productFitments');
    Route::put('products/{id}/fitments', 'VehicleFitmentController@saveProductFitments');
    Route::post('products/warehouse_locations', 'ProductsController@storeWarehouseLocation');
    Route::post('products/import/single', 'ProductsController@import_single_products')->middleware('auth:api');
    Route::post('products/import/variants', 'ProductsController@import_variant_products')->middleware('auth:api');
    Route::post('products/import/service', 'ProductsController@import_service_products')->middleware('auth:api');
    Route::post('products/import/update-only', 'ProductsController@import_update_only')->middleware('auth:api');

    Route::get('get_Products_by_warehouse/{id}', 'ProductsController@Products_by_Warehouse');
    Route::get('get_product_detail_api/{id}', 'ProductsController@Get_Products_Details');
    Route::get('get_products_stock_alerts', 'ProductsController@Products_Alert');
    Route::get('barcode_create_page', 'ProductsController@Get_element_barcode');
    Route::post('products/delete/by_selection', 'ProductsController@delete_by_selection');
    Route::get('show_product_data/{id}/{variant_id}', 'ProductsController@show_product_data');
    Route::get('show_product_data/{id}/{variant_id}/{warehouse_id}', 'ProductsController@show_product_data');
    // Typeahead for the Related products picker (any visible product).
    Route::get('products/search-basic', 'ProductsController@search_products_basic');
    Route::get('get_products_materiels', 'ProductsController@get_products_materiels')->name('get_products_materiels');

    Route::get('opening-stock/import/meta', 'ProductsController@opening_stock_meta');
    Route::post('opening-stock/import/single', 'ProductsController@opening_stock_import_single');
    Route::post('opening-stock/import/variants', 'ProductsController@opening_stock_import_variants');

    // ---- count stock ----------
    Route::get('count_stock', 'ProductsController@count_stock_list');
    Route::post('store_count_stock', 'ProductsController@store_count_stock');

    // ------------------------------- Category --------------------------\\
    // ------------------------------------------------------------------\\

    Route::resource('categories', 'CategorieController');
    Route::post('categories/delete/by_selection', 'CategorieController@delete_by_selection');

    // Product Subcategories
    Route::resource('subcategories', 'SubCategoryController');
    Route::post('subcategories/delete/by_selection', 'SubCategoryController@delete_by_selection');
    Route::get('subcategories/by-category/{category_id}', 'SubCategoryController@getByCategory');

    // ------------------------------- Units --------------------------\\
    // ------------------------------------------------------------------\\

    Route::resource('units', 'UnitsController');
    Route::post('units/delete/by_selection', 'UnitsController@delete_by_selection');
    Route::get('get_sub_units_by_base', 'UnitsController@Get_Units_SubBase');
    Route::get('get_units', 'UnitsController@Get_sales_units');

    // ------------------------------- Brands--------------------------\\
    // ------------------------------------------------------------------\\
    Route::resource('brands', 'BrandsController');
    Route::post('brands/delete/by_selection', 'BrandsController@delete_by_selection');

    // ------------------------------- Product Size Guides --------------------------\\
    Route::get('size_guides/list', 'SizeGuideController@list');
    Route::resource('size_guides', 'SizeGuideController');
    Route::post('size_guides/delete/by_selection', 'SizeGuideController@delete_by_selection');

    // ------------------------------- Currencies --------------------------\\
    // ------------------------------------------------------------------\\

    // Lightweight list for the Multi-Currency document pickers (no `currency`
    // permission — cashiers and salespeople need it too).
    Route::get('currencies_list', 'CurrencyController@Get_Currencies');
    Route::resource('currencies', 'CurrencyController');
    Route::post('currencies/delete/by_selection', 'CurrencyController@delete_by_selection');
    Route::post('currencies/{id}/set-default', 'CurrencyController@setDefault');

    // ------------------------------- WAREHOUSES --------------------------\\

    Route::resource('warehouses', 'WarehouseController');
    Route::post('warehouses/delete/by_selection', 'WarehouseController@delete_by_selection');

    // ------------------------------- WAREHOUSE LOCATIONS (Rack/Location) --------------------------\\
    Route::resource('warehouse_locations', 'WarehouseLocationController');
    Route::get('warehouse_locations/by_warehouse/{id}', 'WarehouseLocationController@by_warehouse');

    // ------------------------------- PRODUCT BATCHES (Pharmacy mode) --------------------------\\
    Route::get('product_batches', 'ProductBatchController@index');
    Route::put('product_batches/{id}', 'ProductBatchController@update');
    Route::post('product_batches/{id}/writeoff', 'ProductBatchController@writeOff');
    Route::delete('product_batches/{id}', 'ProductBatchController@destroy');
    // Opening Batch & Expiry: label pre-tracking stock with batches (no stock movement)
    Route::get('products/{id}/opening_batches', 'ProductBatchController@openingMeta');
    Route::post('products/{id}/opening_batches', 'ProductBatchController@storeOpening');

    // ------------------------------- PURCHASES --------------------------\\
    // ------------------------------------------------------------------\\

    Route::resource('purchases', 'PurchasesController');
    Route::get('purchases/{id}/barcodes', 'PurchasesController@get_barcode_products');
    Route::get('get_payments_by_purchase/{id}', 'PurchasesController@Get_Payments');
    Route::post('purchase_send_email', 'PurchasesController@Send_Email');
    Route::post('purchase_send_sms', 'PurchasesController@Send_SMS');
    Route::post('purchases_delete_by_selection', 'PurchasesController@delete_by_selection');
    Route::get('get_Products_by_purchase/{id}', 'PurchasesController@get_Products_by_purchase');
    Route::post('purchase_send_whatsapp', 'PurchasesController@purchase_send_whatsapp');

    Route::get('get_import_purchases', 'PurchasesController@get_import_purchases');
    Route::post('preview_import_purchases', 'PurchasesController@preview_import_purchases');
    Route::post('store_import_purchases', 'PurchasesController@store_import_purchases');
    
    // ------------------------------- Purchase Documents --------------------------\\
    Route::get('purchases/{id}/documents', 'PurchasesController@getDocuments');
    Route::post('purchases/{id}/documents', 'PurchasesController@uploadDocuments');
    Route::get('purchases/documents/{id}/download', 'PurchasesController@downloadDocument');
    Route::delete('purchases/documents/{id}', 'PurchasesController@deleteDocument');

    // ------------------------------- Payments  Purchases --------------------------\\
    // ------------------------------------------------------------------------------\\

    Route::resource('payment_purchase', 'PaymentPurchasesController');
    Route::get('payment_purchase_get_number', 'PaymentPurchasesController@getNumberOrder');
    Route::post('payment_purchase_send_email', 'PaymentPurchasesController@SendEmail');
    Route::post('payment_purchase_send_sms', 'PaymentPurchasesController@Send_SMS');

    // -------------------------------  Sales --------------------------\\
    // ------------------------------------------------------------------\\

    Route::resource('sales', 'SalesController');
    Route::get('get_import_sales', 'SalesController@get_import_sales');
    Route::post('store_import_sales', 'SalesController@store_import_sales');
    Route::post('preview_import_sales', 'SalesController@preview_import_sales');
    Route::get('batches_for_sale/{product_id}/{warehouse_id}/{variant_id?}', 'SalesController@batches_for_sale');
    Route::get('convert_to_sale_data/{id}', 'SalesController@Elemens_Change_To_Sale');
    Route::get('get_payments_by_sale/{id}', 'SalesController@Payments_Sale');
    Route::post('sales_send_email', 'SalesController@Send_Email');
    Route::post('sales_send_sms', 'SalesController@Send_SMS');
    Route::post('sales_delete_by_selection', 'SalesController@delete_by_selection');
    Route::get('get_Products_by_sale/{id}', 'SalesController@get_Products_by_sale');

    // ------------------------------- Sales Documents --------------------------\\
    Route::get('sales/{id}/documents', 'SalesController@getDocuments');
    Route::post('sales/{id}/documents', 'SalesController@uploadDocuments');
    Route::get('sales/documents/{id}/download', 'SalesController@downloadDocument');
    Route::delete('sales/documents/{id}', 'SalesController@deleteDocument');
    Route::post('sales_send_whatsapp', 'SalesController@sales_send_whatsapp');
    Route::get('get_today_sales', 'SalesController@get_today_sales');

    // -------------------------------  Shipments --------------------------\\
    // ------------------------------------------------------------------\\

    Route::resource('shipments', 'ShipmentController');

    // ------------------------------- Payments  Sales --------------------------\\
    // ------------------------------------------------------------------\\

    Route::resource('payment_sale', 'PaymentSalesController');
    Route::get('payment_sale_get_number', 'PaymentSalesController@getNumberOrder');
    Route::post('payment_sale_send_email', 'PaymentSalesController@SendEmail');
    Route::post('payment_sale_send_sms', 'PaymentSalesController@Send_SMS');

    // ------------------------------- Commission Program --------------------------\\
    Route::resource('commission_programs', 'Commission\CommissionProgramController');
    Route::resource('sales_agents', 'Commission\SalesAgentController');
    Route::get('sales_agents_list_for_select', 'Commission\SalesAgentController@listForSelect');
    Route::resource('commission_rules', 'Commission\CommissionRuleController');
    Route::get('commission_receipts', 'Commission\CommissionReceiptController@index');
    Route::post('commission_receipts', 'Commission\CommissionReceiptController@store');
    Route::get('commission_receipts/new_ref', 'Commission\CommissionReceiptController@getNewRef');
    Route::get('commission_receipts/{id}', 'Commission\CommissionReceiptController@show');
    Route::get('commission_report', 'Commission\CommissionReportController@index');
    Route::get('commission_report/summary', 'Commission\CommissionReportController@summary');
    Route::get('commission_report/by_agent', 'Commission\CommissionReportController@byAgent');
    Route::post('commissions/calculate_for_sale/{saleId}', 'Commission\CommissionController@calculateForSale');
    Route::post('commissions/approve', 'Commission\CommissionController@approve');
    Route::post('commissions/cancel', 'Commission\CommissionController@cancel');

    // ------------------------------- Expenses --------------------------\\
    // ------------------------------------------------------------------\\

    Route::resource('expenses', 'ExpensesController');
    Route::post('expenses_delete_by_selection', 'ExpensesController@delete_by_selection');
    // ------------------------------- Expense Documents --------------------------\\
    Route::get('expenses/{id}/documents', 'ExpensesController@getDocuments');
    Route::post('expenses/{id}/documents', 'ExpensesController@uploadDocuments');
    Route::get('expenses/documents/{id}/download', 'ExpensesController@downloadDocument');
    Route::delete('expenses/documents/{id}', 'ExpensesController@deleteDocument');

    // ------------------------------- Expenses Category--------------------------\\
    // ------------------------------------------------------------------\\

    Route::resource('expenses_category', 'CategoryExpenseController');
    Route::post('expenses_category_delete_by_selection', 'CategoryExpenseController@delete_by_selection');

    // ------------------------------- Accounts --------------------------\\
    // ------------------------------------------------------------------\\

    Route::resource('accounts', 'AccountController');
    Route::post('accounts_delete_by_selection', 'AccountController@delete_by_selection');

    // ------------------------------- TransferMoneyController --------------------------\\
    // ------------------------------------------------------------------\\

    Route::resource('transfer_money', 'TransferMoneyController');

    // ------------------------------- Deposits --------------------------\\
    // ------------------------------------------------------------------\\

    Route::resource('deposits', 'DepositsController');
    Route::post('deposits_delete_by_selection', 'DepositsController@delete_by_selection');

    // ------------------------------- deposits Category--------------------------\\
    // ------------------------------------------------------------------\\

    Route::resource('deposits_category', 'CategoryDepositController');
    Route::post('deposits_category_delete_by_selection', 'CategoryDepositController@delete_by_selection');

    // ------------------------------- Quotations --------------------------\\
    // ------------------------------------------------------------------\\
    Route::resource('quotations', 'QuotationsController');
    Route::post('quotations_send_email', 'QuotationsController@SendEmail');
    Route::post('quotations_send_sms', 'QuotationsController@Send_SMS');
    Route::post('quotations_delete_by_selection', 'QuotationsController@delete_by_selection');
    Route::post('quotation_send_whatsapp', 'QuotationsController@quotation_send_whatsapp');
    Route::get('batches_for_quotation/{product_id}/{warehouse_id}/{variant_id?}', 'QuotationsController@batches_for_quotation');

    // ------------------------------- Sales Return --------------------------\\
    // ------------------------------------------------------------------\\

    // Declared before the resource so this single-segment GET is not captured
    // by the resource's show route (returns/sale/{sale}).
    Route::get('returns/sale/pos_search_sales', 'SalesReturnController@pos_search_sales');
    Route::resource('returns/sale', 'SalesReturnController');
    Route::post('returns/sale/send/email', 'SalesReturnController@Send_Email');
    Route::post('returns/sale/send/sms', 'SalesReturnController@Send_SMS');
    Route::get('returns/sale/payment/{id}', 'SalesReturnController@Payment_Returns');
    Route::post('returns/sale/delete/by_selection', 'SalesReturnController@delete_by_selection');
    Route::get('returns/sale/create_sell_return/{id}', 'SalesReturnController@create_sell_return');
    Route::get('returns/sale/edit_sell_return/{id}/{sale_id}', 'SalesReturnController@edit_sell_return');

    // ------------------------------- Purchases Return --------------------------\\
    // ------------------------------------------------------------------\\

    Route::resource('returns/purchase', 'PurchasesReturnController');
    Route::post('returns/purchase/send/email', 'PurchasesReturnController@Send_Email');
    Route::post('returns/purchase/send/sms', 'PurchasesReturnController@Send_SMS');
    Route::get('returns/purchase/payment/{id}', 'PurchasesReturnController@Payment_Returns');
    Route::post('returns/purchase/delete/by_selection', 'PurchasesReturnController@delete_by_selection');
    Route::get('returns/purchase/create_purchase_return/{id}', 'PurchasesReturnController@create_purchase_return');
    Route::get('returns/purchase/edit_purchase_return/{id}/{purchase_id}', 'PurchasesReturnController@edit_purchase_return');

    // ------------------------------- Payment Sale Returns --------------------------\\
    // --------------------------------------------------------------------------------\\

    Route::resource('payment/returns_sale', 'PaymentSaleReturnsController');
    Route::get('payment/returns_sale/Number/order', 'PaymentSaleReturnsController@getNumberOrder');
    Route::post('payment/returns_sale/send/email', 'PaymentSaleReturnsController@SendEmail');
    Route::post('payment/returns_sale/send/sms', 'PaymentSaleReturnsController@Send_SMS');

    // ------------------------------- Payments Purchase Returns --------------------------\\
    // ---------------------------------------------------------------------------------------\\

    Route::resource('payment/returns_purchase', 'PaymentPurchaseReturnsController');
    Route::get('payment/returns_purchase/Number/Order', 'PaymentPurchaseReturnsController@getNumberOrder');
    Route::post('payment/returns_purchase/send/email', 'PaymentPurchaseReturnsController@SendEmail');
    Route::post('payment/returns_purchase/send/sms', 'PaymentPurchaseReturnsController@Send_SMS');

    // ------------------------------- Adjustments --------------------------\\
    // ------------------------------------------------------------------\\

    Route::resource('adjustments', 'AdjustmentController');
    Route::get('adjustments/detail/{id}', 'AdjustmentController@Adjustment_detail');
    Route::post('adjustments/delete/by_selection', 'AdjustmentController@delete_by_selection');
    Route::get('batches_for_adjustment/{product_id}/{warehouse_id}/{variant_id?}', 'AdjustmentController@batches_for_adjustment');

    // ------------------------------- Damages --------------------------\\
    // ------------------------------------------------------------------\\

    Route::resource('damages', 'DamageController');
    Route::get('damages/detail/{id}', 'DamageController@Damage_detail');
    Route::post('damages/delete/by_selection', 'DamageController@delete_by_selection');
    Route::get('batches_for_damage/{product_id}/{warehouse_id}/{variant_id?}', 'DamageController@batches_for_damage');

    // ------------------------------- Transfers --------------------------\\
    // --------------------------------------------------------------------\\
    Route::resource('transfers', 'TransferController');
    Route::post('transfers/delete/by_selection', 'TransferController@delete_by_selection');
    Route::post('transfers/{id}/approve', 'TransferController@approve');
    Route::post('transfers/{id}/reject', 'TransferController@reject');
    Route::get('batches_for_transfer/{product_id}/{warehouse_id}/{variant_id?}', 'TransferController@batches_for_transfer');

    // ------------------------------- Users --------------------------\\
    // ------------------------------------------------------------------\\

    Route::get('get_user_auth', 'UserController@GetUserAuth');
    Route::get('users_list_for_select', 'UserController@listForSelect');
    Route::get('check_phone_duplicate', 'UserController@checkPhoneDuplicate');
    Route::resource('users', 'UserController');
    Route::put('users_switch_activated/{id}', 'UserController@IsActivated');
    Route::get('Get_user_profile', 'UserController@GetInfoProfile');
    Route::put('update_user_profile/{id}', 'UserController@updateProfile');

    // ------------------------------- Permission Groups user -----------\\
    // ------------------------------------------------------------------\\

    Route::resource('roles', 'PermissionsController');
    Route::resource('roles/check/create_page', 'PermissionsController@Check_Create_Page');
    Route::post('roles/delete/by_selection', 'PermissionsController@delete_by_selection');

    // ------------------------------- Settings ------------------------\\
    // ------------------------------------------------------------------\\
    Route::get('settings/dark-mode', 'SettingsController@getDarkMode');
    Route::put('settings/dark-mode', 'SettingsController@updateDarkMode');
    // Multipart FormData can't be sent as PUT (PHP won't parse it), so the SPA
    // posts settings updates; route POST to the same update action.
    Route::post('settings/{id}', 'SettingsController@update')->where('id', '[0-9]+');
    Route::resource('settings', 'SettingsController');
    Route::get('get_Settings_data_api', 'SettingsController@get_Settings_data_api');
    Route::get('get_Settings_data', 'SettingsController@getSettings');
    Route::put('settings/dashboard-grid-layout', 'SettingsController@updateDashboardGridLayout');
    // Sidebar menu order (drag-and-drop manager in System Settings)
    Route::get('sidebar_menu_order', 'SettingsController@getSidebarMenuOrder');
    Route::put('sidebar_menu_order', 'SettingsController@updateSidebarMenuOrder');
    Route::delete('sidebar_menu_order', 'SettingsController@resetSidebarMenuOrder');

    // Module toggles (System Settings → Modules)
    Route::get('module_flags', 'SettingsController@getModuleFlags');
    Route::put('module_flags', 'SettingsController@updateModuleFlags');
    Route::delete('module_flags', 'SettingsController@resetModuleFlags');

    // Feature toggles (Settings → Modules → Features tab)
    Route::get('feature_settings', 'SettingsController@getFeatureSettings');
    Route::put('feature_settings', 'SettingsController@updateFeatureSettings');

    // Barcode label print defaults (Print Barcode page)
    Route::get('barcode_label_settings', 'SettingsController@getBarcodeLabelSettings');
    Route::put('barcode_label_settings', 'SettingsController@updateBarcodeLabelSettings');

    // Direct label printing (raw TSPL to the label printer)
    Route::post('print_labels_direct', 'ProductsController@Print_Labels_Direct');
    Route::post('label_printer_test', 'SettingsController@testLabelPrinter');
    Route::get('label_printer_test_design', 'SettingsController@labelPrinterTestDesign');
    Route::post('label_printer_calibrate', 'SettingsController@calibrateLabelPrinter');
    Route::post('label_printer_dpi_test', 'SettingsController@dpiTestLabelPrinter');
    // QZ Tray request signing (silent client-side printing)
    Route::get('qz/certificate', 'SettingsController@qzCertificate');
    Route::post('qz/sign', 'SettingsController@qzSign');

    // Demo data generator (System Settings)
    Route::get('demo_data/status', 'DemoDataController@status');
    Route::post('demo_data/generate', 'DemoDataController@generate');
    Route::delete('demo_data', 'DemoDataController@reset');
    // Dedicated Dark Mode endpoints (independent from other settings APIs)
    Route::put('pos_settings/{id}', 'SettingsController@update_pos_settings');
    Route::get('get_pos_Settings', 'SettingsController@get_pos_Settings');
    Route::get('get_pos_Settings_api', 'SettingsController@get_pos_Settings_api');

    // ------------------------------- Security Settings (additive) ------------------------\\
    // Active login sessions (Passport tokens) + logout endpoints
    Route::get('security/sessions', 'SecuritySettingsController@sessions');
    Route::delete('security/sessions/{tokenId}', 'SecuritySettingsController@logoutSession');
    Route::post('security/sessions/logout-other', 'SecuritySettingsController@logoutAllOtherDevices');
    Route::get('security/login-activity-report', 'SecuritySettingsController@loginActivityReport');

    // ------------------------------- appearance_settings ------------------------\\
    // ------------------------------------------------------------------\\

    Route::get('get_appearance_settings', 'SettingsController@get_appearance_settings');
    Route::put('update_appearance_settings/{id}', 'SettingsController@update_appearance_settings');

    // ------------------------------- PWA Settings ------------------------\\

    Route::get('get_mobile_settings', 'SettingsController@get_mobile_settings');
    Route::post('update_mobile_settings', 'SettingsController@update_mobile_settings');

    Route::get('get_pwa_settings', 'SettingsController@get_pwa_settings');
    Route::post('update_pwa_settings', 'SettingsController@update_pwa_settings');

    // ------------------------------- Profile Password ------------------------\\
    Route::post('update_user_password', 'UserController@updatePassword');

    // ------------------------------- Mail Settings ------------------------\\

    Route::put('update_config_mail/{id}', 'MailSettingsController@update_config_mail');
    Route::get('get_config_mail', 'MailSettingsController@get_config_mail');
    Route::post('test_config_mail', 'MailSettingsController@test_config_mail');

    // ------------------------------- SMS Settings ------------------------\\

    Route::get('get_sms_config', 'Sms_SettingsController@get_sms_config');
    Route::get('get_sms_config_ws', 'Sms_SettingsController@get_sms_config_ws');
    Route::post('update_twilio_config', 'Sms_SettingsController@update_twilio_config');
    Route::post('update_nexmo_config', 'Sms_SettingsController@update_nexmo_config');
    Route::post('update_infobip_config', 'Sms_SettingsController@update_infobip_config');
    Route::post('update_termi_config', 'Sms_SettingsController@update_termi_config');
    Route::post('update_custom_config', 'Sms_SettingsController@update_custom_config');

    Route::put('update_Default_SMS', 'Sms_SettingsController@update_Default_SMS');

    // notifications_template
    Route::get('get_sms_template', 'Notifications_Template@get_sms_template');
    Route::put('update_sms_body', 'Notifications_Template@update_sms_body');

    Route::get('get_emails_template', 'Notifications_Template@get_emails_template');
    Route::put('update_custom_email', 'Notifications_Template@update_custom_email');

    // ------------------------------- Payment_gateway Settings ------------------------\\

    // Invoice PDF customization (System Settings > Invoice PDF)
    Route::get('pdf_templates/{type}', 'PdfTemplateController@show');
    Route::post('pdf_templates/{type}', 'PdfTemplateController@update');
    Route::post('payment_gateway', 'Payment_gateway_SettingsController@Update_payment_gateway');
    Route::get('get_payment_gateway', 'Payment_gateway_SettingsController@Get_payment_gateway');
    Route::get('get_payment_gateway_ws', 'Payment_gateway_SettingsController@get_payment_gateway_ws');

    // ------------------------------- Update Settings ------------------------\\

    Route::get('get_version_info', 'UpdateController@get_version_info');
    Route::post('one_click_update', 'AutoUpdateController@oneClickUpdate');
    Route::get('update/preflight', 'AutoUpdateController@preflight');
    Route::get('update/progress', 'AutoUpdateController@progress');

    // --------------------- System Update (upload-based, resumable) ----------\\
    // Whitelisted in CheckForMaintenanceMode so the flow keeps working while
    // the application is down for updating.
    Route::get('system-update/status', 'SystemUpdateController@status');
    Route::post('system-update/upload', 'SystemUpdateController@uploadChunk');
    Route::post('system-update/validate', 'SystemUpdateController@validatePackage');
    Route::post('system-update/start', 'SystemUpdateController@start');
    Route::post('system-update/step', 'SystemUpdateController@step');
    Route::post('system-update/resume', 'SystemUpdateController@resume');
    Route::post('system-update/rollback', 'SystemUpdateController@rollback');
    Route::post('system-update/discard', 'SystemUpdateController@discard');
    Route::delete('system-update/package', 'SystemUpdateController@deletePackage');
    Route::post('system-update/restore-backup', 'SystemUpdateController@restoreBackup');
    Route::delete('system-update/backups/{id}', 'SystemUpdateController@deleteBackup');
    Route::post('system-update/retention', 'SystemUpdateController@saveRetention');

    // ------------------------------- Backup --------------------------\\
    // ------------------------------------------------------------------\\

    Route::get('get_backup', 'BackupController@Get_Backup');
    Route::get('generate_new_backup', 'BackupController@Generate_Backup');
    Route::delete('delete_backup/{name}', 'BackupController@Delete_Backup');

    // ------------------------------- System Health --------------------------\\
    Route::get('system_health', 'SystemHealthController@index');
    Route::get('system_health/pdf', 'SystemHealthController@pdf');

    // ------------------------------- Module Settings ------------------------\\

    Route::get('get_modules_info', 'ModuleSettingsController@get_modules_info');
    Route::post('update_status_module', 'ModuleSettingsController@update_status_module');
    Route::post('upload_module', 'ModuleSettingsController@upload_module');

    // ---------------- Advanced Manufacturing MRP ----------------
    // Shared lookups.
    Route::get('mrp/meta', 'MrpBomController@meta');
    Route::get('mrp/products', 'MrpBomController@products');
    Route::get('mrp/dashboard', 'MrpPlanningController@dashboard');

    // Work centres.
    Route::get('mrp/work-centers', 'MrpBomController@workCenters');
    Route::post('mrp/work-centers', 'MrpBomController@storeWorkCenter');
    Route::put('mrp/work-centers/{id}', 'MrpBomController@updateWorkCenter');
    Route::delete('mrp/work-centers/{id}', 'MrpBomController@destroyWorkCenter');

    // Bills of materials. Literal segments before {id}.
    Route::post('mrp/boms/{id}/duplicate', 'MrpBomController@duplicate');
    Route::get('mrp/boms/{id}/explode', 'MrpBomController@explode');
    Route::get('mrp/boms', 'MrpBomController@index');
    Route::post('mrp/boms', 'MrpBomController@store');
    Route::get('mrp/boms/{id}', 'MrpBomController@show');
    Route::put('mrp/boms/{id}', 'MrpBomController@update');
    Route::delete('mrp/boms/{id}', 'MrpBomController@destroy');

    // Shop floor.
    Route::get('mrp/work-orders', 'MrpProductionController@workOrders');
    Route::post('mrp/work-orders/{id}/start', 'MrpProductionController@startWorkOrder');
    Route::post('mrp/work-orders/{id}/finish', 'MrpProductionController@finishWorkOrder');

    // Quality control.
    Route::get('mrp/quality-checks', 'MrpProductionController@qualityChecks');
    Route::post('mrp/quality-checks', 'MrpProductionController@storeQualityCheck');
    Route::delete('mrp/quality-checks/{id}', 'MrpProductionController@destroyQualityCheck');

    // Planning.
    Route::post('mrp/planning/run', 'MrpPlanningController@run');
    Route::get('mrp/planning/runs', 'MrpPlanningController@runs');
    Route::get('mrp/planning/suggestions', 'MrpPlanningController@suggestions');
    Route::post('mrp/planning/suggestions/accept-all', 'MrpPlanningController@acceptAll');
    Route::post('mrp/planning/suggestions/{id}/accept', 'MrpPlanningController@acceptSuggestion');
    Route::post('mrp/planning/suggestions/{id}/dismiss', 'MrpPlanningController@dismissSuggestion');

    // Reports.
    Route::get('mrp/reports/cost', 'MrpPlanningController@costReport');
    Route::get('mrp/reports/efficiency', 'MrpPlanningController@efficiencyReport');
    Route::get('mrp/reports/material', 'MrpPlanningController@materialReport');
    Route::get('mrp/reports/quality', 'MrpPlanningController@qualityReport');

    // Production orders. Literal segments before {id}.
    Route::post('mrp/production-orders/{id}/release', 'MrpProductionController@release');
    Route::post('mrp/production-orders/{id}/complete', 'MrpProductionController@complete');
    Route::post('mrp/production-orders/{id}/cancel', 'MrpProductionController@cancel');
    Route::get('mrp/production-orders', 'MrpProductionController@index');
    Route::post('mrp/production-orders', 'MrpProductionController@store');
    Route::get('mrp/production-orders/{id}', 'MrpProductionController@show');
    Route::put('mrp/production-orders/{id}', 'MrpProductionController@update');
    Route::delete('mrp/production-orders/{id}', 'MrpProductionController@destroy');

    // ---------------- Shopify Integration ----------------
    // Literal segments before the {id} routes.
    Route::get('shopify/meta', 'ShopifyStoreController@meta');
    Route::get('shopify/dashboard', 'ShopifySyncController@dashboard');

    Route::get('shopify/sync/runs', 'ShopifySyncController@runs');
    Route::get('shopify/sync/latest', 'ShopifySyncController@latest');
    Route::post('shopify/sync/start', 'ShopifySyncController@start');
    Route::get('shopify/sync/status/{id}', 'ShopifySyncController@status');
    Route::post('shopify/sync/cancel/{id}', 'ShopifySyncController@cancel');

    Route::get('shopify/mappings', 'ShopifySyncController@mappings');
    Route::post('shopify/mappings', 'ShopifySyncController@link');
    Route::delete('shopify/mappings/{id}', 'ShopifySyncController@unlink');

    Route::get('shopify/logs', 'ShopifySyncController@logs');
    Route::delete('shopify/logs', 'ShopifySyncController@clearLogs');

    Route::get('shopify/webhook-events', 'ShopifyWebhookController@events');
    Route::post('shopify/webhook-events/{id}/replay', 'ShopifyWebhookController@replay');

    Route::get('shopify/stores/{id}/overview', 'ShopifyStoreController@overview');
    Route::get('shopify/stores/{id}/locations', 'ShopifyStoreController@locations');
    Route::post('shopify/stores/{id}/test', 'ShopifyStoreController@testConnection');
    Route::get('shopify/stores', 'ShopifyStoreController@index');
    Route::post('shopify/stores', 'ShopifyStoreController@store');
    Route::get('shopify/stores/{id}', 'ShopifyStoreController@show');
    Route::put('shopify/stores/{id}', 'ShopifyStoreController@update');
    Route::delete('shopify/stores/{id}', 'ShopifyStoreController@destroy');

    // ---------------- WooCommerce Sync (optional module) ----------------
    Route::get('woocommerce/settings', 'WooCommerceSyncController@getSettings');
    Route::post('woocommerce/settings', 'WooCommerceSyncController@saveSettings');
    Route::post('woocommerce/test-connection', 'WooCommerceSyncController@connectStore');
    Route::post('woocommerce/sync/products', 'WooCommerceSyncController@syncProducts');
    Route::post('woocommerce/sync/stock', 'WooCommerceSyncController@syncStock');
    // Stop/cancel running sync jobs (UI calls this)
    Route::post('woocommerce/sync/products/stop', 'WooCommerceSyncController@stopProductsSync');
    Route::post('woocommerce/sync/stock/stop', 'WooCommerceSyncController@stopStockSync');
    // DB-based sync progress endpoints
    Route::get('sync/status/{id}', 'SyncJobController@status');
    Route::post('sync/{id}/cancel', 'SyncJobController@cancel');
    // Aliases (requested): /api/woo-sync/*
    Route::get('woo-sync/latest', 'SyncJobController@latest');
    Route::get('woo-sync/status/{id}', 'SyncJobController@status');
    Route::post('woo-sync/{id}/cancel', 'SyncJobController@cancel');
    // Aliases with hyphen for convenience
    Route::post('woocommerce/sync-stock', 'WooCommerceSyncController@syncStock');
    Route::get('woocommerce/sync/products/progress', 'WooCommerceSyncController@syncProductsProgress');
    Route::get('woocommerce/sync-products/progress', 'WooCommerceSyncController@syncProductsProgress');
    Route::get('woocommerce/sync/stock/progress', 'WooCommerceSyncController@syncStockProgress');
    Route::get('woocommerce/sync-stock/progress', 'WooCommerceSyncController@syncStockProgress');
    Route::get('woocommerce/stock-metrics', 'WooCommerceSyncController@stockMetrics');
    Route::post('woocommerce/sync/orders', 'WooCommerceSyncController@syncOrders');
    Route::get('woocommerce/orders', 'WooCommerceSyncController@getWooCommerceOrders');
    Route::get('woocommerce/orders/imported', 'WooCommerceSyncController@getImportedWooOrders');
    Route::get('woocommerce/orders/imported/stats', 'WooCommerceSyncController@getImportedWooOrdersStats');
    Route::post('woocommerce/sync/categories', 'WooCommerceSyncController@syncCategories');
    Route::post('woocommerce/sync/brands', 'WooCommerceSyncController@syncBrands');
    Route::get('woocommerce/brands/unsynced-count', 'WooCommerceSyncController@unsyncedBrandsCount');
    Route::post('woocommerce/reset-brands-sync', 'WooCommerceSyncController@resetBrandsSync');
    // Categories mapping (POS <-> Woo) and logs management
    Route::post('woocommerce/categories/map', 'WooCommerceSyncController@mapCategories');
    Route::delete('woocommerce/logs', 'WooCommerceSyncController@clearLogs');
    // Logs & metrics
    Route::get('woocommerce/logs', 'WooCommerceSyncController@logs');
    Route::get('woocommerce/unsynced-count', 'WooCommerceSyncController@unsyncedCount');
    Route::get('woocommerce/products/pull-stats', 'WooCommerceSyncController@getProductsPullStats');
    Route::get('woocommerce/categories/pull-stats', 'WooCommerceSyncController@getCategoriesPullStats');
    Route::get('woocommerce/brands/pull-stats', 'WooCommerceSyncController@getBrandsPullStats');
    Route::get('woocommerce/categories/unsynced-count', 'WooCommerceSyncController@unsyncedCategoriesCount');
    Route::get('woocommerce/customers/unsynced-count', 'WooCommerceSyncController@unsyncedCustomersCount');
    Route::get('woocommerce/customers/stats', 'WooCommerceSyncController@getCustomersStats');
    Route::get('woocommerce/customers', 'WooCommerceSyncController@getWooCommerceCustomers');
    Route::get('woocommerce/customers/sync-issues', 'WooCommerceSyncController@getCustomerSyncIssues');
    Route::post('woocommerce/customers/sync-issues/{id}/resolve', 'WooCommerceSyncController@resolveCustomerSyncIssue');
    Route::post('woocommerce/customers/sync-issues/{id}/link', 'WooCommerceSyncController@manualLinkCustomerSyncIssue');

    Route::post('woocommerce/sync/orders', 'WooCommerceSyncController@syncOrders');
    Route::post('woocommerce/sync/customers', 'WooCommerceSyncController@syncCustomers');
    Route::post('woocommerce/reset-customers-sync', 'WooCommerceSyncController@resetCustomersSync');
    Route::post('woocommerce/reset-sync', 'WooCommerceSyncController@resetSync');
    Route::post('woocommerce/reset-products-sync', 'WooCommerceSyncController@resetProductsSync');
    Route::post('woocommerce/reset-categories-sync', 'WooCommerceSyncController@resetCategoriesSync');
    Route::post('woocommerce/products/fix-categories', 'WooCommerceSyncController@fixProductCategories');
    Route::post('woocommerce/products/auto-link', 'WooCommerceSyncController@autoLinkProductsBySku');
    Route::get('woocommerce/products/unmapped-report', 'WooCommerceSyncController@getUnmappedItemsReport');
    Route::post('woocommerce/reset-stock-sync', 'WooCommerceSyncController@resetStockSync');

    // Customer Display: secure token generation
    Route::post('customer-display/generate', [CustomerDisplayController::class, 'generate']);

    // ------------------------------- QuickBooks Integration ------------------------\\
    // ------------------------------------------------------------------\\
    Route::get('quickbooks/status', 'QuickBooksController@status');
    Route::post('quickbooks/disconnect', 'QuickBooksController@disconnect');
    Route::get('quickbooks/settings', 'QuickBooksController@quickbookgetSettings');
    Route::post('quickbooks/settings', 'QuickBooksController@saveSettings');
    Route::get('quickbooks/audits', 'QuickBooksController@audits');
    Route::get('quickbooks/clients-stats', 'QuickBooksController@clientsStats');
    Route::get('quickbooks/clients-unsynced', 'QuickBooksController@clientsUnsynced');
    Route::post('quickbooks/sync-clients', 'QuickBooksController@syncClients');

    // ------------------------------- Webhooks (outgoing) ------------------------\\
    // Fully additive module: manages outgoing webhooks, logs, and delivery retries.
    Route::get('webhooks/available-events', [\App\Http\Controllers\Webhooks\WebhooksController::class, 'availableEvents']);
    Route::get('webhooks/deliveries', [\App\Http\Controllers\Webhooks\WebhooksController::class, 'deliveries']);
    Route::get('webhooks/deliveries/{id}', [\App\Http\Controllers\Webhooks\WebhooksController::class, 'deliveryShow']);
    Route::get('webhooks/incoming-logs', [\App\Http\Controllers\Webhooks\WebhooksController::class, 'incomingLogs']);
    Route::get('webhooks/incoming-logs/{id}', [\App\Http\Controllers\Webhooks\WebhooksController::class, 'incomingLogShow']);
    Route::get('webhooks/{id}/deliveries', [\App\Http\Controllers\Webhooks\WebhooksController::class, 'deliveries']);
    Route::post('webhooks/{id}/test', [\App\Http\Controllers\Webhooks\WebhooksController::class, 'test']);
    Route::post('webhooks/{id}/regenerate-secret', [\App\Http\Controllers\Webhooks\WebhooksController::class, 'regenerateSecret']);
    Route::post('webhooks/{id}/toggle', [\App\Http\Controllers\Webhooks\WebhooksController::class, 'toggle']);
    Route::apiResource('webhooks', \App\Http\Controllers\Webhooks\WebhooksController::class);

    // ------------------------------- Slack notifications ------------------------\\
    Route::get('slack/settings', [\App\Http\Controllers\Integrations\SlackSettingsController::class, 'show']);
    Route::put('slack/settings', [\App\Http\Controllers\Integrations\SlackSettingsController::class, 'update']);
    Route::post('slack/settings/test', [\App\Http\Controllers\Integrations\SlackSettingsController::class, 'test']);

    // ------------------------------- Telegram notifications ------------------------\\
    Route::get('telegram/settings', [\App\Http\Controllers\Integrations\TelegramSettingsController::class, 'show']);
    Route::put('telegram/settings', [\App\Http\Controllers\Integrations\TelegramSettingsController::class, 'update']);
    Route::post('telegram/settings/test', [\App\Http\Controllers\Integrations\TelegramSettingsController::class, 'test']);

    // ------------------------------- Salla (ecommerce sync) ------------------------\\
    Route::get('salla/settings', [\App\Http\Controllers\Integrations\SallaSyncController::class, 'settings']);
    Route::post('salla/settings', [\App\Http\Controllers\Integrations\SallaSyncController::class, 'saveSettings']);
    Route::post('salla/disconnect', [\App\Http\Controllers\Integrations\SallaSyncController::class, 'disconnect']);
    Route::post('salla/test-connection', [\App\Http\Controllers\Integrations\SallaSyncController::class, 'testConnection']);
    Route::get('salla/stats', [\App\Http\Controllers\Integrations\SallaSyncController::class, 'stats']);
    Route::post('salla/reset-mappings', [\App\Http\Controllers\Integrations\SallaSyncController::class, 'resetMappings']);
    Route::post('salla/sync/products', [\App\Http\Controllers\Integrations\SallaSyncController::class, 'syncProducts']);
    Route::post('salla/sync/inventory', [\App\Http\Controllers\Integrations\SallaSyncController::class, 'syncInventory']);
    Route::post('salla/sync/orders', [\App\Http\Controllers\Integrations\SallaSyncController::class, 'syncOrders']);
    Route::get('salla/logs', [\App\Http\Controllers\Integrations\SallaSyncController::class, 'logs']);
    Route::delete('salla/logs', [\App\Http\Controllers\Integrations\SallaSyncController::class, 'clearLogs']);

    // ------------------------------- Xero (accounting sync) ------------------------\\
    Route::get('xero/settings', [\App\Http\Controllers\Integrations\XeroSyncController::class, 'settings']);
    Route::post('xero/settings', [\App\Http\Controllers\Integrations\XeroSyncController::class, 'saveSettings']);
    Route::post('xero/disconnect', [\App\Http\Controllers\Integrations\XeroSyncController::class, 'disconnect']);
    Route::post('xero/test-connection', [\App\Http\Controllers\Integrations\XeroSyncController::class, 'testConnection']);
    Route::get('xero/stats', [\App\Http\Controllers\Integrations\XeroSyncController::class, 'stats']);
    Route::post('xero/reset-mappings', [\App\Http\Controllers\Integrations\XeroSyncController::class, 'resetMappings']);
    Route::post('xero/sync/contacts', [\App\Http\Controllers\Integrations\XeroSyncController::class, 'syncContacts']);
    Route::post('xero/sync/invoices', [\App\Http\Controllers\Integrations\XeroSyncController::class, 'syncInvoices']);
    Route::get('xero/logs', [\App\Http\Controllers\Integrations\XeroSyncController::class, 'logs']);
    Route::delete('xero/logs', [\App\Http\Controllers\Integrations\XeroSyncController::class, 'clearLogs']);

    // ------------------------------- PrestaShop (ecommerce sync) ------------------------\\
    Route::get('prestashop/settings', [\App\Http\Controllers\Integrations\PrestashopSyncController::class, 'settings']);
    Route::post('prestashop/settings', [\App\Http\Controllers\Integrations\PrestashopSyncController::class, 'saveSettings']);
    Route::post('prestashop/test-connection', [\App\Http\Controllers\Integrations\PrestashopSyncController::class, 'testConnection']);
    Route::get('prestashop/stats', [\App\Http\Controllers\Integrations\PrestashopSyncController::class, 'stats']);
    Route::post('prestashop/reset-mappings', [\App\Http\Controllers\Integrations\PrestashopSyncController::class, 'resetMappings']);
    Route::post('prestashop/sync/products', [\App\Http\Controllers\Integrations\PrestashopSyncController::class, 'syncProducts']);
    Route::post('prestashop/sync/inventory', [\App\Http\Controllers\Integrations\PrestashopSyncController::class, 'syncInventory']);
    Route::post('prestashop/sync/orders', [\App\Http\Controllers\Integrations\PrestashopSyncController::class, 'syncOrders']);
    Route::get('prestashop/logs', [\App\Http\Controllers\Integrations\PrestashopSyncController::class, 'logs']);
    Route::delete('prestashop/logs', [\App\Http\Controllers\Integrations\PrestashopSyncController::class, 'clearLogs']);

    // ------------------------------- Google Sheets (exports) ------------------------\\
    Route::get('google-sheets/settings', [\App\Http\Controllers\Integrations\GoogleSheetsSyncController::class, 'settings']);
    Route::post('google-sheets/settings', [\App\Http\Controllers\Integrations\GoogleSheetsSyncController::class, 'saveSettings']);
    Route::post('google-sheets/disconnect', [\App\Http\Controllers\Integrations\GoogleSheetsSyncController::class, 'disconnect']);
    Route::post('google-sheets/test-connection', [\App\Http\Controllers\Integrations\GoogleSheetsSyncController::class, 'testConnection']);
    Route::post('google-sheets/create-spreadsheet', [\App\Http\Controllers\Integrations\GoogleSheetsSyncController::class, 'createSpreadsheet']);
    Route::get('google-sheets/stats', [\App\Http\Controllers\Integrations\GoogleSheetsSyncController::class, 'stats']);
    Route::post('google-sheets/export/sales', [\App\Http\Controllers\Integrations\GoogleSheetsSyncController::class, 'exportSales']);
    Route::post('google-sheets/export/products', [\App\Http\Controllers\Integrations\GoogleSheetsSyncController::class, 'exportProducts']);
    Route::post('google-sheets/export/customers', [\App\Http\Controllers\Integrations\GoogleSheetsSyncController::class, 'exportCustomers']);
    Route::get('google-sheets/logs', [\App\Http\Controllers\Integrations\GoogleSheetsSyncController::class, 'logs']);
    Route::delete('google-sheets/logs', [\App\Http\Controllers\Integrations\GoogleSheetsSyncController::class, 'clearLogs']);

    // ------------------------------- Mailchimp (audience sync) ------------------------\\
    Route::get('mailchimp/settings', [\App\Http\Controllers\Integrations\MailchimpSettingsController::class, 'settings']);
    Route::post('mailchimp/settings', [\App\Http\Controllers\Integrations\MailchimpSettingsController::class, 'saveSettings']);
    Route::post('mailchimp/test-connection', [\App\Http\Controllers\Integrations\MailchimpSettingsController::class, 'testConnection']);
    Route::get('mailchimp/lists', [\App\Http\Controllers\Integrations\MailchimpSettingsController::class, 'lists']);
    Route::get('mailchimp/stats', [\App\Http\Controllers\Integrations\MailchimpSettingsController::class, 'stats']);
    Route::post('mailchimp/sync/customers', [\App\Http\Controllers\Integrations\MailchimpSettingsController::class, 'syncCustomers']);
    Route::get('mailchimp/logs', [\App\Http\Controllers\Integrations\MailchimpSettingsController::class, 'logs']);
    Route::delete('mailchimp/logs', [\App\Http\Controllers\Integrations\MailchimpSettingsController::class, 'clearLogs']);

    // ------------------------------- Jumia (marketplace sync) ------------------------\\
    Route::get('jumia/settings', [\App\Http\Controllers\Integrations\JumiaSyncController::class, 'settings']);
    Route::post('jumia/settings', [\App\Http\Controllers\Integrations\JumiaSyncController::class, 'saveSettings']);
    Route::post('jumia/test-connection', [\App\Http\Controllers\Integrations\JumiaSyncController::class, 'testConnection']);
    Route::get('jumia/stats', [\App\Http\Controllers\Integrations\JumiaSyncController::class, 'stats']);
    Route::post('jumia/sync/price-stock', [\App\Http\Controllers\Integrations\JumiaSyncController::class, 'syncPriceStock']);
    Route::post('jumia/sync/orders', [\App\Http\Controllers\Integrations\JumiaSyncController::class, 'syncOrders']);
    Route::get('jumia/logs', [\App\Http\Controllers\Integrations\JumiaSyncController::class, 'logs']);
    Route::delete('jumia/logs', [\App\Http\Controllers\Integrations\JumiaSyncController::class, 'clearLogs']);

    // ------------------------------- ZATCA Phase 2 e-invoicing ------------------------\\
    Route::get('zatca/settings', 'ZatcaController@settings');
    Route::post('zatca/settings', 'ZatcaController@saveSettings');
    Route::post('zatca/onboard', 'ZatcaController@onboard');
    Route::post('zatca/csr/regenerate', 'ZatcaController@regenerateCsr');
    Route::get('zatca/documents', 'ZatcaController@documents');
    Route::get('zatca/documents/{id}/xml', 'ZatcaController@downloadXml');
    Route::post('zatca/sales/{id}/submit', 'ZatcaController@submitSale');
    Route::post('zatca/sale_returns/{id}/submit', 'ZatcaController@submitSaleReturn');
    Route::get('zatca/logs', 'ZatcaController@logs');

});

// NEW FEATURE - SAFE ADDITION: Accounting V2 (isolated routes)
Route::middleware(['auth:api', 'Is_Active', 'request.safety'])->group(function () {
    Route::prefix('accounting/v2')->group(function () {
        // Dashboard
        Route::get('dashboard', 'AccountingV2\\DashboardController@summary');
        // Chart of Accounts
        Route::get('coa', 'AccountingV2\\ChartOfAccountsController@index');
        Route::post('coa', 'AccountingV2\\ChartOfAccountsController@store');
        Route::put('coa/{id}', 'AccountingV2\\ChartOfAccountsController@update');
        Route::delete('coa/{id}', 'AccountingV2\\ChartOfAccountsController@destroy');

        // Journal Entries
        Route::get('journal-entries', 'AccountingV2\\JournalEntriesController@index');
        Route::get('journal-entries/{id}', 'AccountingV2\\JournalEntriesController@show');
        Route::post('journal-entries', 'AccountingV2\\JournalEntriesController@store');
        Route::post('journal-entries/{id}/post', 'AccountingV2\\JournalEntriesController@post');
        Route::put('journal-entries/{id}', 'AccountingV2\\JournalEntriesController@update');
        Route::patch('journal-entries/{id}', 'AccountingV2\\JournalEntriesController@update');
        Route::delete('journal-entries/{id}', 'AccountingV2\\JournalEntriesController@destroy');

        // Reports
        Route::get('reports/trial-balance', 'AccountingV2\\ReportsController@trialBalance');
        Route::get('reports/profit-loss', 'AccountingV2\\ReportsController@profitAndLoss');
        Route::get('reports/balance-sheet', 'AccountingV2\\ReportsController@balanceSheet');
        Route::get('reports/tax-summary', 'AccountingV2\\ReportsController@taxSummary');
    });
});

// Public minimal endpoints for customer display (no auth)
Route::post('pos/customer-display/broadcast', [CustomerDisplayController::class, 'broadcastCart']);
Route::get('pos/customer-display/last-cart', [CustomerDisplayController::class, 'lastCart']);
// Order Ready screen polling (no auth; token-guarded inside the controller)
Route::get('kitchen/ready-screen/data', [\App\Http\Controllers\KitchenOrderController::class, 'readyScreenData']);

// -------------------------------  Print & PDF ------------------------\\
// ------------------------------------------------------------------\\

Route::get('sale_pdf/{id}', 'SalesController@Sale_PDF');
Route::get('sale_print_html/{id}', 'SalesController@Sale_PDF_Inline');
Route::get('quote_pdf/{id}', 'QuotationsController@Quotation_pdf');
Route::get('quote_print_html/{id}', 'QuotationsController@Quotation_PDF_Inline');
Route::get('booking_pdf/{id}', 'BookingController@booking_pdf');
Route::get('service_job_pdf/{id}', 'ServiceJobController@service_job_pdf');
Route::get('service_quote_pdf/{id}', 'ServiceJobController@service_quote_pdf');
Route::get('purchase_pdf/{id}', 'PurchasesController@Purchase_pdf');
Route::get('purchase_print_html/{id}', 'PurchasesController@Purchase_PDF_Inline');
Route::get('return_sale_pdf/{id}', 'SalesReturnController@Return_pdf');
Route::get('return_purchase_pdf/{id}', 'PurchasesReturnController@Return_pdf');
Route::get('payment_purchase_pdf/{id}', 'PaymentPurchasesController@Payment_purchase_pdf');
Route::get('payment_return_sale_pdf/{id}', 'PaymentSaleReturnsController@payment_return');
Route::get('payment_return_purchase_pdf/{id}', 'PaymentPurchaseReturnsController@payment_return');
Route::get('payment_sale_pdf/{id}', 'PaymentSalesController@payment_sale');
Route::get('sales_print_invoice/{id}', 'SalesController@Print_Invoice_POS');
Route::post('direct_network_print/{id}', 'SalesController@Direct_Network_Print_POS');
Route::get('transfer_pdf/{id}', 'TransferController@transfer_pdf');
Route::get('adjustment_pdf/{id}', 'AdjustmentController@adjustment_pdf');
Route::get('damage_pdf/{id}', 'DamageController@damage_pdf');

// Route::get('/available-modules', 'ModuleSettingsController@get_modules_enabled');

// ======================== RECRUIT ========================
Route::middleware(['auth:api', 'Is_Active'])->prefix('recruit')->group(function () {

    // Dashboard & Reports
    Route::get('dashboard', 'RecruitController@dashboard');
    Route::get('reports', 'RecruitController@reports');

    // Job Categories
    Route::get('categories', 'RecruitController@categories_index');
    Route::get('categories_all', 'RecruitController@categories_all');
    Route::post('categories', 'RecruitController@categories_store');
    Route::put('categories/{id}', 'RecruitController@categories_update');
    Route::delete('categories/{id}', 'RecruitController@categories_destroy');
    Route::post('categories/delete/by_selection', 'RecruitController@categories_delete_by_selection');

    // Jobs
    Route::get('jobs', 'RecruitController@jobs_index');
    Route::get('jobs_all', 'RecruitController@jobs_all');
    Route::post('jobs', 'RecruitController@jobs_store');
    Route::put('jobs/{id}', 'RecruitController@jobs_update');
    Route::delete('jobs/{id}', 'RecruitController@jobs_destroy');
    Route::post('jobs/delete/by_selection', 'RecruitController@jobs_delete_by_selection');

    // Candidates
    Route::get('candidates', 'RecruitController@candidates_index');
    Route::get('candidates_all', 'RecruitController@candidates_all');
    Route::post('candidates', 'RecruitController@candidates_store');
    Route::put('candidates/{id}', 'RecruitController@candidates_update');
    Route::delete('candidates/{id}', 'RecruitController@candidates_destroy');
    Route::post('candidates/delete/by_selection', 'RecruitController@candidates_delete_by_selection');

    // Applications
    Route::get('applications', 'RecruitController@applications_index');
    Route::get('applications_all', 'RecruitController@applications_all');
    Route::post('applications', 'RecruitController@applications_store');
    Route::put('applications/{id}', 'RecruitController@applications_update');
    Route::put('applications/{id}/stage', 'RecruitController@applications_update_stage');
    Route::delete('applications/{id}', 'RecruitController@applications_destroy');
    Route::post('applications/delete/by_selection', 'RecruitController@applications_delete_by_selection');

    // Interviews
    Route::get('interviews', 'RecruitController@interviews_index');
    Route::post('interviews', 'RecruitController@interviews_store');
    Route::put('interviews/{id}', 'RecruitController@interviews_update');
    Route::delete('interviews/{id}', 'RecruitController@interviews_destroy');
    Route::post('interviews/delete/by_selection', 'RecruitController@interviews_delete_by_selection');
});

// ======================== MEETING ========================
Route::middleware(['auth:api', 'Is_Active'])->prefix('meeting')->group(function () {

    // Dashboard, Calendar & Reports
    Route::get('dashboard', 'MeetingController@dashboard');
    Route::get('calendar', 'MeetingController@calendar');
    Route::get('reports', 'MeetingController@reports');

    // Meetings
    Route::get('meetings', 'MeetingController@meetings_index');
    Route::get('meetings_all', 'MeetingController@meetings_all');
    Route::get('meetings/{id}', 'MeetingController@meetings_show');
    Route::post('meetings', 'MeetingController@meetings_store');
    Route::put('meetings/{id}', 'MeetingController@meetings_update');
    Route::put('meetings/{id}/status', 'MeetingController@meetings_update_status');
    Route::delete('meetings/{id}', 'MeetingController@meetings_destroy');
    Route::post('meetings/delete/by_selection', 'MeetingController@meetings_delete_by_selection');

    // Invitations
    Route::post('meetings/{id}/invitations', 'MeetingController@meetings_send_invitations');

    // Attendance
    Route::post('meetings/{id}/attendance', 'MeetingController@attendance_update');

    // Notes / Minutes / Action items
    Route::post('notes', 'MeetingController@notes_store');
    Route::put('notes/{id}', 'MeetingController@notes_update');
    Route::delete('notes/{id}', 'MeetingController@notes_destroy');

    // Attachments
    Route::post('attachments', 'MeetingController@attachments_store');
    Route::delete('attachments/{id}', 'MeetingController@attachments_destroy');
});

// ======================== MARKETING ========================
Route::middleware(['auth:api', 'Is_Active'])->prefix('marketing')->group(function () {

    // Dashboard, Reports & Activity
    Route::get('dashboard', 'MarketingController@dashboard');
    Route::get('reports', 'MarketingController@reports');
    Route::get('activity_logs', 'MarketingController@activity_logs');

    // Campaigns
    Route::get('campaigns', 'MarketingController@campaigns_index');
    Route::get('campaigns/{id}', 'MarketingController@campaigns_show');
    Route::post('campaigns', 'MarketingController@campaigns_store');
    Route::post('campaigns/{id}', 'MarketingController@campaigns_update'); // POST for multipart (attachment) updates
    Route::put('campaigns/{id}', 'MarketingController@campaigns_update');
    Route::put('campaigns/{id}/status', 'MarketingController@campaigns_update_status');
    Route::post('campaigns/{id}/send', 'MarketingController@campaigns_send');
    Route::delete('campaigns/{id}', 'MarketingController@campaigns_destroy');
    Route::post('campaigns/delete/by_selection', 'MarketingController@campaigns_delete_by_selection');

    // Segments
    Route::get('segments', 'MarketingController@segments_index');
    Route::get('segments_all', 'MarketingController@segments_all');
    Route::post('segments', 'MarketingController@segments_store');
    Route::put('segments/{id}', 'MarketingController@segments_update');
    Route::delete('segments/{id}', 'MarketingController@segments_destroy');
    Route::post('segments/preview', 'MarketingController@segments_preview');

    // Templates
    Route::get('templates', 'MarketingController@templates_index');
    Route::get('templates_all', 'MarketingController@templates_all');
    Route::post('templates', 'MarketingController@templates_store');
    Route::put('templates/{id}', 'MarketingController@templates_update');
    Route::post('templates/{id}/duplicate', 'MarketingController@templates_duplicate');
    Route::delete('templates/{id}', 'MarketingController@templates_destroy');

    // Settings
    Route::get('settings', 'MarketingController@settings_show');
    Route::post('settings', 'MarketingController@settings_update');
});

// ======================== REAL ESTATE ========================
Route::middleware(['auth:api', 'Is_Active'])->prefix('realestate')->group(function () {

    // Dashboard
    Route::get('dashboard', 'RealEstateController@dashboard');

    // Properties
    Route::get('properties', 'RealEstateController@properties_index');
    Route::get('properties/{id}', 'RealEstateController@properties_show');
    Route::post('properties', 'RealEstateController@properties_store');
    Route::post('properties/{id}', 'RealEstateController@properties_update'); // POST for multipart (images) updates
    Route::put('properties/{id}', 'RealEstateController@properties_update');
    Route::delete('properties/{id}', 'RealEstateController@properties_destroy');
    Route::post('properties/delete/by_selection', 'RealEstateController@properties_delete_by_selection');

    // Categories
    Route::get('categories', 'RealEstateController@categories_index');
    Route::get('categories_all', 'RealEstateController@categories_all');
    Route::post('categories', 'RealEstateController@categories_store');
    Route::post('categories/{id}', 'RealEstateController@categories_update'); // POST for multipart (image) updates
    Route::put('categories/{id}', 'RealEstateController@categories_update');
    Route::delete('categories/{id}', 'RealEstateController@categories_destroy');

    // Inquiries
    Route::get('inquiries', 'RealEstateController@inquiries_index');
    Route::get('inquiries/{id}', 'RealEstateController@inquiries_show');
    Route::put('inquiries/{id}/status', 'RealEstateController@inquiries_update_status');
    Route::delete('inquiries/{id}', 'RealEstateController@inquiries_destroy');
    Route::post('inquiries/delete/by_selection', 'RealEstateController@inquiries_delete_by_selection');
});


// ---------------- Shopify webhooks (public) ----------------
// Deliberately outside the authenticated group: Shopify posts with no session
// and no CSRF token. The request proves itself with an HMAC signature over the
// raw body, verified in ShopifyWebhookController against the store's secret.
Route::post('shopify/webhook', 'ShopifyWebhookController@handle');

// ---------------- Mobile admin app (Flutter) ----------------
// The app authenticates with a Passport personal access token issued by
// mobile/login and sent as a Bearer header — no cookies involved. ping and
// login are public: ping validates a server URL typed into the app before
// any credentials exist, and login is throttled per IP.
Route::prefix('mobile')->group(function () {
    Route::get('ping', [\App\Http\Controllers\Api\Mobile\MobileAuthController::class, 'ping']);
    Route::post('login', [\App\Http\Controllers\Api\Mobile\MobileAuthController::class, 'login'])
        ->middleware('throttle:10,1');

    Route::middleware(['auth:api', 'Is_Active', 'request.safety', 'token.timeout'])->group(function () {
        Route::get('me', [\App\Http\Controllers\Api\Mobile\MobileAuthController::class, 'me']);
        Route::post('logout', [\App\Http\Controllers\Api\Mobile\MobileAuthController::class, 'logout']);

        Route::post('device-token', [\App\Http\Controllers\Api\Mobile\MobileDeviceTokenController::class, 'store']);
        Route::delete('device-token', [\App\Http\Controllers\Api\Mobile\MobileDeviceTokenController::class, 'destroy']);
        Route::post('device-token/test', [\App\Http\Controllers\Api\Mobile\MobileDeviceTokenController::class, 'test']);
    });
});
