<?php

use App\Http\Controllers\Admin\StoreSettingsController as AdminStoreSettings;
use App\Http\Controllers\Api\Store\AccountPagesController;
use App\Http\Controllers\Api\Store\CheckoutController;
use App\Http\Controllers\Api\Store\CustomerLoyaltyController;
use App\Http\Controllers\Api\Store\CustomerReturnsController;
use App\Http\Controllers\Api\Store\CustomerQuestionsController;
use App\Http\Controllers\Api\Store\CustomerReviewsController;
use App\Http\Controllers\Api\Store\CustomerTicketsController;
use App\Http\Controllers\Api\Store\CustomerWalletController;
use App\Http\Controllers\Api\Store\QuoteRequestsController;
use App\Http\Controllers\Api\Store\MessageController;
use App\Http\Controllers\Api\Store\MyOrdersApiController;
use App\Http\Controllers\Api\Store\PaymentProofController;
use App\Http\Controllers\Api\Store\NewsletterController;
use App\Http\Controllers\QuickBooksController;
use App\Http\Controllers\RealEstateStoreController;
use App\Http\Controllers\StoreAuthController;
use App\Http\Controllers\StoreFrontController;
use App\Http\Controllers\WishlistController;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
 */

// ------------------------------------------------------------------\\
// Passport::routes();

// Login route will be defined explicitly below with middleware

Route::get('password/find/{token}', 'PasswordResetController@find');

// PWA manifests — generated from System Settings → PWA so the install prompt
// and the installed app reflect the configured name, colors and launch
// behavior instead of a hardcoded "Murano". The admin/POS surface stays at
// /manifest.webmanifest so existing <link> tags and the service worker
// precache list need no changes; the other surfaces are served from /pwa/*
// (the static public/manifest-*.webmanifest files would shadow them otherwise).
Route::get('manifest.webmanifest', [\App\Http\Controllers\PwaManifestController::class, 'manifest'])
    ->name('pwa.manifest');
Route::get('pwa/{type}.webmanifest', [\App\Http\Controllers\PwaManifestController::class, 'manifest'])
    ->where('type', 'app|store|customer-display|portal')
    ->name('pwa.manifest.surface');

// Route::middleware(['web','auth:web','Is_Active'])->group(function () {
//     Route::get('/admin/store/settings', [AdminStoreSettings::class, 'show']);
//     Route::post('/admin/store/settings', [AdminStoreSettings::class, 'update']);
// });

$installed = Storage::disk('public')->exists('installed');

// ------------------------------------------------------------------\\
// ONLINE STORE ROUTES (Only if installed)
//
// The storefront base path is configurable (Store Settings → Store URL):
//  - default: 'online_store' (backward compatible)
//  - custom:  any safe path, e.g. 'shop'
//  - root:    '' — the store is served from the domain root; reserved system
//             prefixes (see store_reserved_paths()) keep belonging to the
//             admin panel, and the customer auth pages move under /customer/*
//             so they never shadow the staff /login and /logout routes.
$storePath = store_path();
$storeAuthPrefix = $storePath === '' ? 'customer' : '';

if ($installed === true) {

    // Company websites (Website Management -> Website URL, 2026-10-06): each at its
    // own address /{url_path} (e.g. /kcoltech), not /online_store?company=ID.
    // Registered before the store so they also win when the store uses the root domain.
    foreach (\App\Support\WebsitePaths::all() as $sitePath => $siteCompany) {
        Route::middleware(['web', 'request.safety', 'store.locale', 'website.company:'.$siteCompany.','.$sitePath])
            ->prefix($sitePath)->group(function () {
                Route::get('/', [StoreFrontController::class, 'index']);
                Route::get('/pages/{slug}', [StoreFrontController::class, 'page']);
                Route::get('/gallery', [StoreFrontController::class, 'gallery']);
                Route::get('/contact', [StoreFrontController::class, 'contact']);
            });
    }

    // One-page template preview (Website settings -> One-page, 2026-10-20): a
    // signed link from the editor, so it works before the template is applied.
    Route::get('/website-preview/onepage/{company}', function (\Illuminate\Http\Request $request, int $company) {
        abort_unless($request->hasValidSignatureWhileIgnoring(['lang'], false), 403);

        return \App\Services\OnePage::response($company, $request, true) ?? abort(404);
    })->middleware(['web', 'request.safety'])->whereNumber('company')->name('website.onepage.preview');

    Route::middleware(['web', 'request.safety', 'store.enabled', 'store.locale'])->group(function () use ($storePath, $storeAuthPrefix) {

        Route::prefix($storePath)->group(function () use ($storeAuthPrefix) {

            // Header language switcher. This is a per-session override — it
            // deliberately does NOT rewrite a signed-in customer's saved
            // preference (My Account → Preferences does that).
            Route::get('/lang/{locale}', function ($locale) {
                $supported = \App\Http\Middleware\SetLocale::SUPPORTED;

                // Use provided locale if supported, otherwise fallback to 'en'
                $chosen = isset($supported[$locale]) ? $locale : 'en';

                // Store in session
                session(['locale' => $chosen]);

                // Optionally persist for a year via cookie
                Cookie::queue('locale', $chosen, 60 * 24 * 365, '/');

                return back();
            })->name('lang.switch');

            // Multi-Currency: the shopper's display currency. Validated in the
            // service (unknown id / module off leaves the base currency).
            Route::get('/currency/{id}', function ($id) {
                \App\Services\StoreCurrencyService::select($id);

                return back();
            })->whereNumber('id')->name('store.currency.switch');

            Route::get('/', [StoreFrontController::class, 'index'])->name('store.index');
            Route::get('/shop', [StoreFrontController::class, 'shop'])->name('store.shop');
            Route::get('/flash-sales', [StoreFrontController::class, 'flashSales'])->name('store.flash_sales');
            Route::get('/contact', [StoreFrontController::class, 'contact'])->name('store.contact');
            Route::get('/gallery', [StoreFrontController::class, 'gallery'])->name('store.gallery');

            // Public product reviews (approved only)
            Route::get('/products/{id}/reviews', [CustomerReviewsController::class, 'productReviews'])
                ->name('store.product.reviews');

            // Public product Q&A (published only). Asking requires a signed-in
            // customer and lives under the auth:store group below.
            Route::get('/products/{id}/questions', [CustomerQuestionsController::class, 'productQuestions'])
                ->name('store.product.questions');

            // Quotation request (service / classified-ad products; guests allowed)
            Route::post('/quote-request', [QuoteRequestsController::class, 'store'])
                ->middleware('throttle:8,1')->name('store.quote.request');
            Route::post('/store/orders', [CheckoutController::class, 'store'])->name('store.orders.store');
            Route::post('/store/payment-intent', [CheckoutController::class, 'createPaymentIntent'])->name('store.payment.intent');
            Route::post('/store/checkout-quote', [CheckoutController::class, 'quote'])->name('store.checkout.quote');
            // PayPal redirect endpoints (no auth middleware: the pending order is
            // resolved by the unguessable ?token={paypal_order_id}, so capture
            // still works even if the store session was lost mid-redirect).
            Route::get('/store/paypal/return', [CheckoutController::class, 'paypalReturn'])->name('store.paypal.return');
            Route::get('/store/paypal/cancel', [CheckoutController::class, 'paypalCancel'])->name('store.paypal.cancel');
            // Paystack has a single callback URL; success/failure/abandoned is
            // decided by the server-side Verify call, never the redirect.
            Route::get('/store/paystack/return', [CheckoutController::class, 'paystackReturn'])->name('store.paystack.return');
            // Flutterwave redirects back with ?status&tx_ref&transaction_id;
            // paid/failed is decided by the server-side verify, never the query.
            Route::get('/store/flutterwave/return', [CheckoutController::class, 'flutterwaveReturn'])->name('store.flutterwave.return');
            // Razorpay Payment Link callback; paid/failed is decided by the
            // server-side link fetch, never the query params.
            Route::get('/store/razorpay/return', [CheckoutController::class, 'razorpayReturn'])->name('store.razorpay.return');
            Route::get('/collections/{slug}', [StoreFrontController::class, 'collection'])->name('store.collection.show');

            // Full product detail page (by id) + product comparison
            Route::get('/product/{id}', [StoreFrontController::class, 'productShow'])->whereNumber('id')->name('store.product.show');
            Route::get('/compare', [StoreFrontController::class, 'compare'])->name('store.compare');

            // CMS content pages (managed from the admin Pages screen)
            Route::get('/pages/{slug}', [StoreFrontController::class, 'page'])->name('store.page');

            // Vehicle Fitment: selector + My Garage (session-based; garage
            // rows require the store login). 404s while the feature is off.
            Route::get('/vehicle/options', [\App\Http\Controllers\StoreVehicleController::class, 'options'])->name('store.vehicle.options');
            Route::post('/vehicle', [\App\Http\Controllers\StoreVehicleController::class, 'select'])->name('store.vehicle.select');
            Route::post('/vehicle/clear', [\App\Http\Controllers\StoreVehicleController::class, 'clear'])->name('store.vehicle.clear');
            Route::post('/garage/{id}/select', [\App\Http\Controllers\StoreVehicleController::class, 'selectGarage'])->whereNumber('id')->name('store.garage.select');
            Route::delete('/garage/{id}', [\App\Http\Controllers\StoreVehicleController::class, 'destroyGarage'])->whereNumber('id')->name('store.garage.destroy');

            // Wishlist mutations (return 401 JSON for guests instead of a redirect)
            Route::get('/wishlist/ids', [WishlistController::class, 'ids'])->name('store.wishlist.ids');
            Route::post('/wishlist/toggle', [WishlistController::class, 'toggle'])->name('store.wishlist.toggle');
            Route::post('/wishlist/{productId}/remove', [WishlistController::class, 'remove'])->whereNumber('productId')->name('store.wishlist.remove');

            // Real Estate theme storefront (active when StoreSetting->theme = real_estate)
            Route::get('/properties', [RealEstateStoreController::class, 'listings'])->name('store.realestate.listings');
            Route::post('/properties/inquiry', [RealEstateStoreController::class, 'inquiry'])->name('store.realestate.inquiry');
            Route::get('/properties/{slug}', [RealEstateStoreController::class, 'show'])->name('store.realestate.show');

            Route::post('/newsletter/subscribe', [NewsletterController::class, 'subscribe'])->name('newsletter.subscribe');
            Route::post('/contact/send', [MessageController::class, 'store'])->name('store.contact.send');
            Route::get('/search/suggestions', [StoreFrontController::class, 'searchSuggestions'])->name('store.search.suggestions');

            // Wholesale Pricing by Quantity: quantity ladders for the cart's
            // products, so cart/checkout can re-price lines as qty changes.
            Route::get('/wholesale-tiers', [StoreFrontController::class, 'wholesaleTiers'])->name('store.wholesale.tiers');

            // Account pages (require login on 'store' guard)
            Route::middleware(['web', 'auth:store'])->group(function () {
                Route::view('/checkout', 'store.checkout')->name('checkout');
                Route::view('/thank-you', 'store.thank-you')->name('store.thankyou');

                Route::get('/account', [AccountPagesController::class, 'account'])->name('account');

                Route::put('/account', [AccountPagesController::class, 'update'])->name('account.update');

                Route::put('/account/address', [AccountPagesController::class, 'updateAddress'])->name('account.address.update');

                Route::put('/account/preferences', [AccountPagesController::class, 'updatePreferences'])->name('account.preferences.update');

                Route::get('/account/wishlist', [StoreFrontController::class, 'wishlist'])->name('store.wishlist');

                Route::get('/account/orders', [AccountPagesController::class, 'orders'])
                    ->name('account.orders');

                Route::get('/account/orders/{id}', function ($id) {
                    // $s is likely shared via view composer; if not, fetch StoreSetting here
                    return view('store.order-show', ['id' => $id]);
                })->name('account.order.show');

                Route::get('/account/orders/{id}/invoice', [CheckoutController::class, 'invoice'])
                    ->name('account.order.invoice');

                // Returns / cancellations (customer requests only)
                Route::get('/account/orders/{id}/return-eligibility', [CustomerReturnsController::class, 'eligibility'])
                    ->name('account.order.return.eligibility');
                Route::post('/account/orders/{id}/cancel', [CustomerReturnsController::class, 'cancel'])
                    ->name('account.order.cancel');
                Route::post('/account/orders/{id}/return', [CustomerReturnsController::class, 'requestReturn'])
                    ->name('account.order.return');

                // Ask About This Item — registered customers only
                Route::post('/products/{id}/questions', [CustomerQuestionsController::class, 'ask'])
                    ->middleware('throttle:10,1')->name('store.product.questions.ask');
                Route::get('/account/questions', [CustomerQuestionsController::class, 'mine'])
                    ->name('account.questions');

                // Product reviews (verified purchase)
                Route::get('/account/orders/{id}/reviewable', [CustomerReviewsController::class, 'reviewable'])
                    ->name('account.order.reviewable');
                Route::post('/account/orders/{id}/review', [CustomerReviewsController::class, 'submit'])
                    ->name('account.order.review');

                // Customer's own orders (JSON for the account orders table)
                Route::get('/my/orders', [MyOrdersApiController::class, 'index'])
                    ->name('my_orders.index');
                // (Optional) details endpoint if you add a “view” drawer/page:
                Route::get('/my/orders/{id}', [MyOrdersApiController::class, 'show'])
                    ->name('my_orders.show');

                // Proof of payment for offline methods (GCash / bank transfer)
                Route::get('/my/orders/{id}/payment-proofs', [PaymentProofController::class, 'index'])
                    ->name('my_orders.proofs.index');
                Route::post('/my/orders/{id}/payment-proofs', [PaymentProofController::class, 'store'])
                    ->middleware('throttle:10,1')->name('my_orders.proofs.store');

                // My Returns — dedicated page listing all return/cancellation requests
                Route::view('/account/returns', 'store.account-returns')->name('account.returns');
                Route::get('/my/returns', [CustomerReturnsController::class, 'myReturns'])
                    ->name('my_returns.index');

                // My Wallet — balance, history, redeem gift cards, request withdrawals
                Route::view('/account/wallet', 'store.account-wallet')->name('account.wallet');
                Route::get('/my/wallet', [CustomerWalletController::class, 'summary'])->name('my_wallet.summary');
                Route::get('/my/wallet/transactions', [CustomerWalletController::class, 'transactions'])->name('my_wallet.transactions');
                Route::post('/my/wallet/redeem', [CustomerWalletController::class, 'redeem'])->name('my_wallet.redeem');
                Route::post('/my/wallet/withdraw', [CustomerWalletController::class, 'withdraw'])->name('my_wallet.withdraw');

                // My Loyalty — points balance, redeemable rewards, redemption history
                Route::view('/account/rewards', 'store.account-loyalty')->name('account.rewards');
                Route::get('/my/loyalty', [CustomerLoyaltyController::class, 'summary'])->name('my_loyalty.summary');
                Route::post('/my/loyalty/redeem', [CustomerLoyaltyController::class, 'redeem'])->name('my_loyalty.redeem');
                Route::get('/my/loyalty/redemptions', [CustomerLoyaltyController::class, 'redemptions'])->name('my_loyalty.redemptions');

                // Support tickets (helpdesk) -- the customer's own tickets only
                Route::get('/account/tickets', [CustomerTicketsController::class, 'index'])->name('account.tickets');
                Route::get('/account/tickets/new', [CustomerTicketsController::class, 'create'])->name('account.tickets.create');
                Route::post('/account/tickets', [CustomerTicketsController::class, 'store'])
                    ->middleware('throttle:10,1,store-tickets-open')->name('account.tickets.store');
                Route::get('/account/tickets/{id}', [CustomerTicketsController::class, 'show'])
                    ->whereNumber('id')->name('account.tickets.show');
                Route::post('/account/tickets/{id}/reply', [CustomerTicketsController::class, 'reply'])
                    ->whereNumber('id')->middleware('throttle:20,1,store-tickets-reply')->name('account.tickets.reply');
                Route::get('/account/tickets/messages/{messageId}/attachments/{index}', [CustomerTicketsController::class, 'attachment'])
                    ->whereNumber(['messageId', 'index'])->name('account.tickets.attachment');
            });

            // Auth pages (only for guests of 'store' guard). In root-domain
            // mode these live under /customer/* so the staff /login and
            // /logout keep working; links always come from the route names.
            Route::prefix($storeAuthPrefix)->middleware('guest:store')->group(function () {
                Route::get('/login', [StoreAuthController::class, 'showLogin'])->name('store.login.show');
                Route::post('/login', [StoreAuthController::class, 'login'])->name('store.login');

                Route::get('/register', [StoreAuthController::class, 'showRegister'])->name('store.register.show');
                Route::post('/register', [StoreAuthController::class, 'register'])->name('store.register');

                // Password recovery
                Route::get('/forgot-password', [StoreAuthController::class, 'showForgotPassword'])->name('store.password.request');
                Route::post('/forgot-password', [StoreAuthController::class, 'sendResetLink'])
                    ->middleware('throttle:5,1')->name('store.password.email');
                Route::get('/reset-password/{token}', [StoreAuthController::class, 'showResetForm'])->name('store.password.reset');
                Route::post('/reset-password', [StoreAuthController::class, 'resetPassword'])
                    ->middleware('throttle:5,1')->name('store.password.update');

            });

            // Email verification (signed link sent by email; works logged in or out)
            Route::get('/email/verify/{id}/{hash}', [StoreAuthController::class, 'verifyEmail'])
                ->middleware('throttle:12,1')->name('store.verification.verify');
            Route::post('/email/resend', [StoreAuthController::class, 'resendVerification'])
                ->middleware('throttle:4,1')->name('store.verification.resend');

            // Logout (must be logged in on 'store')
            Route::prefix($storeAuthPrefix)->post('/logout', [StoreAuthController::class, 'logout'])
                ->middleware('auth:store')->name('store.logout');

        });
    });

    // Backward compatibility: when the store base moved away from the default,
    // old /online_store links (bookmarks, emails, cached pages) 301-redirect to
    // the configured location, query string preserved.
    if ($storePath !== 'online_store') {
        Route::middleware('web')->any('online_store/{any?}', function ($any = null) {
            $qs = request()->getQueryString();

            // Neutralize backslashes and collapse slashes so a crafted path
            // (e.g. /online_store/%5Cevil.com) can never yield a Location of
            // "//host" (protocol-relative open redirect) in root-domain mode.
            $rest = preg_replace('#/+#', '/', str_replace('\\', '/', (string) $any));

            return redirect(
                '/'.trim(store_path().'/'.$rest, '/').($qs ? '?'.$qs : ''),
                301
            );
        })->where('any', '.*');
    }

} else {
    // if not installed: redirect all storefront requests to /setup
    Route::any('/online_store/{any?}', function () {
        return redirect('/setup');
    })->where('any', '.*');
}

// ------------------------------------------------------------------\\

$installed = Storage::disk('public')->exists('installed');

if ($installed === false) {
    Route::get('/setup', [
        'uses' => 'SetupController@viewCheck',
    ])->name('setup');

    Route::get('/setup/step-1', [
        'uses' => 'SetupController@viewStep1',
    ]);

    Route::post('/setup/step-2', [
        'as' => 'setupStep1', 'uses' => 'SetupController@setupStep1',
    ]);

    Route::post('/setup/testDB', [
        'as' => 'testDB', 'uses' => 'TestDbController@testDB',
    ]);

    Route::get('/setup/step-2', [
        'uses' => 'SetupController@viewStep2',
    ]);

    Route::get('/setup/step-3', [
        'uses' => 'SetupController@viewStep3',
    ]);

    Route::get('/setup/finish', function () {

        return view('setup.finishedSetup');
    });

    Route::get('/setup/getNewAppKey', [
        'as' => 'getNewAppKey', 'uses' => 'SetupController@getNewAppKey',
    ]);

    Route::get('/setup/getPassport', [
        'as' => 'getPassport', 'uses' => 'SetupController@getPassport',
    ]);

    Route::get('/setup/getMegrate', [
        'as' => 'getMegrate', 'uses' => 'SetupController@getMegrate',
    ]);

    Route::post('/setup/step-3', [
        'as' => 'setupStep2', 'uses' => 'SetupController@setupStep2',
    ]);

    Route::post('/setup/step-4', [
        'as' => 'setupStep3', 'uses' => 'SetupController@setupStep3',
    ]);

    Route::post('/setup/step-5', [
        'as' => 'setupStep4', 'uses' => 'SetupController@setupStep4',
    ]);

    Route::post('/setup/lastStep', [
        'as' => 'lastStep', 'uses' => 'SetupController@lastStep',
    ]);

    Route::get('setup/lastStep', function () {
        return redirect('/setup', 301);
    });

} else {
    Route::any('/setup/{vue}', function () {
        abort(403);
    });
}

Route::group(['middleware' => ['web', 'auth:web', 'Is_Active']], function () {

    // Vue 3 + Ant Design app ("Murano") — catch-all under /next/, registered
    // before the SPA /{vue?} wildcard so it isn't swallowed by it.
    Route::view('/next/{any?}', 'next')->where('any', '.*')->name('next');
    Route::redirect('/dashboard-next', '/next/dashboard');

    // QuickBooks OAuth + status
    Route::get('/quickbooks/connect', [QuickBooksController::class, 'connect'])->name('quickbooks.connect');
    Route::get('/quickbooks/callback', [QuickBooksController::class, 'callback'])->name('quickbooks.callback');

    // Google Calendar OAuth (for booking events)
    Route::get('/google-calendar/connect', [\App\Http\Controllers\GoogleCalendarConnectController::class, 'connect'])->name('google_calendar.connect');
    Route::get('/google-calendar/callback', [\App\Http\Controllers\GoogleCalendarConnectController::class, 'callback'])->name('google_calendar.callback');
    Route::get('/google-calendar/disconnect', [\App\Http\Controllers\GoogleCalendarConnectController::class, 'disconnect'])->name('google_calendar.disconnect');

    // Salla OAuth
    Route::get('/salla/connect', [\App\Http\Controllers\Integrations\SallaOAuthController::class, 'connect'])->name('salla.connect');
    Route::get('/salla/callback', [\App\Http\Controllers\Integrations\SallaOAuthController::class, 'callback'])->name('salla.callback');

    // Xero OAuth
    Route::get('/xero/connect', [\App\Http\Controllers\Integrations\XeroOAuthController::class, 'connect'])->name('xero.connect');
    Route::get('/xero/callback', [\App\Http\Controllers\Integrations\XeroOAuthController::class, 'callback'])->name('xero.callback');

    // Google Sheets OAuth
    Route::get('/google-sheets/connect', [\App\Http\Controllers\Integrations\GoogleSheetsOAuthController::class, 'connect'])->name('google_sheets.connect');
    Route::get('/google-sheets/callback', [\App\Http\Controllers\Integrations\GoogleSheetsOAuthController::class, 'callback'])->name('google_sheets.callback');
});

// ------------------------------------------------------------------\\
// Client Portal - if no portal auth, send directly to login (no Vue app load)
Route::get('/portal/{vue?}', function (\Illuminate\Http\Request $request, $vue = null) {
    $installed = Storage::disk('public')->exists('installed');
    if ($installed === false) {
        return redirect('/setup');
    }
    $guestSegments = ['login', 'set-password'];
    $isGuestPath = in_array($vue, $guestSegments, true);
    if (! \Illuminate\Support\Facades\Auth::guard('portal')->check() && ! $isGuestPath) {
        return redirect('/portal/login');
    }
    return view('portal');
})->where('vue', '.*')->middleware(['web', 'portal.locale']);

// ------------------------------------------------------------------\\

// Returns the current session's CSRF token. Used by the login form to refresh
// the _token field right before submit, so a stale cached page cannot cause 419.
// Must be registered BEFORE the authenticated /{vue?} wildcard below, otherwise
// the wildcard claims /csrf-token and the auth middleware returns 401.
Route::get('csrf-token', function (\Illuminate\Http\Request $request) {
    return response()
        ->json(['token' => csrf_token()])
        ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
})->middleware('web')->name('csrf.token');

// Session keepalive: the SPA pings this while the user is active. Passing
// through the 'web' group slides the web session forward AND makes Passport's
// CreateFreshApiToken re-issue the laravel_token cookie the SPA authenticates
// with — so the configured session timeout behaves as an *inactivity* timeout
// instead of expiring a busy cashier mid-shift.
Route::get('session/keepalive', function () {
    return response()->noContent()
        ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
})->middleware('web')->name('session.keepalive');

// Helpdesk public channel: "open a ticket" form (company = its domain or
// ?company=ID) and the private ticket link (/support/t/{token}) for
// suppliers / anyone without a login. Registered before the admin catch-all
// (and 'support' is excluded from it below).
Route::middleware(['web', 'request.safety'])->prefix('support')->group(function () {
    Route::get('/', [\App\Http\Controllers\SupportController::class, 'create'])
        ->middleware('throttle:60,1,support-form')->name('support.create');
    Route::post('/', [\App\Http\Controllers\SupportController::class, 'store'])
        ->middleware('throttle:5,1,support-open')->name('support.store');
    Route::get('/t/{token}', [\App\Http\Controllers\SupportController::class, 'show'])
        ->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:60,1,support-view')->name('support.ticket');
    Route::post('/t/{token}/reply', [\App\Http\Controllers\SupportController::class, 'reply'])
        ->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:10,1,support-reply')->name('support.reply');
    Route::get('/t/{token}/attachments/{messageId}/{index}', [\App\Http\Controllers\SupportController::class, 'attachment'])
        ->where('token', '[A-Za-z0-9]{48}')->whereNumber(['messageId', 'index'])
        ->middleware('throttle:60,1,support-file')->name('support.attachment');
});

// Forms module (2026-10-24): a public form -- own page, iframe page (embedded on any
// website by /embed/murano-forms.js) and its POST (CSRF-exempt like /book: posted
// from cross-site iframes; throttle + honeypot + time-to-submit in the controller).
Route::middleware(['web', 'request.safety'])->prefix('f/{key}')->where(['key' => '[a-z0-9]{8,24}'])->group(function () {
    Route::get('/', [\App\Http\Controllers\PublicFormController::class, 'page'])->middleware('throttle:120,1,form-view')->name('forms.public');
    Route::get('/embed', [\App\Http\Controllers\PublicFormController::class, 'embed'])->middleware('throttle:120,1,form-view')->name('forms.embed');
    Route::post('/', [\App\Http\Controllers\PublicFormController::class, 'submit'])->middleware('throttle:10,1,form-submit')->name('forms.submit');
    Route::post('/start', [\App\Http\Controllers\PublicFormController::class, 'start'])->middleware('throttle:30,1,form-start')->name('forms.start');
});

// Portfolio tablet catalogs (2026-10-25): menu / models on a tablet handed to the
// customer; its order POST is CSRF-exempt like /f (kiosk browser, no session).
Route::middleware(['web', 'request.safety'])->prefix('c/{key}')->where(['key' => '[a-z0-9]{8,24}'])->group(function () {
    Route::get('/', [\App\Http\Controllers\PortfolioCatalogPublicController::class, 'show'])->middleware('throttle:120,1,catalog-view')->name('catalog.show');
    Route::post('/order', [\App\Http\Controllers\PortfolioCatalogPublicController::class, 'order'])->middleware('throttle:20,1,catalog-order')->name('catalog.order');
});

// Bookings public channel: "Book an appointment" page (company = its domain or
// ?company=ID; &embed=1 = compact iframe layout for external websites). The
// POST is CSRF-exempt (VerifyCsrfToken) since it is posted from cross-site
// iframes; spam is held off by throttle + honeypot + time-to-submit.
Route::middleware(['web', 'request.safety'])->prefix('book')->group(function () {
    Route::get('/', [\App\Http\Controllers\PublicBookingController::class, 'create'])
        ->middleware('throttle:60,1,book-form')->name('booking.public');
    Route::post('/', [\App\Http\Controllers\PublicBookingController::class, 'store'])
        ->middleware('throttle:5,1,book-create')->name('booking.public.store');
    // Email check of the IP limits: "I agree" page (GET) and the booking (POST).
    Route::get('/verify/{token}', [\App\Http\Controllers\PublicBookingController::class, 'verify'])
        ->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:30,1,book-verify')->name('booking.public.verify');
    Route::post('/verify/{token}', [\App\Http\Controllers\PublicBookingController::class, 'agree'])
        ->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:10,1,book-agree')->name('booking.public.agree');
    // "Confirm appointment" button in the admins' email (signed link, no login).
    Route::get('/confirm/{booking}', [\App\Http\Controllers\BookingEmailConfirmController::class, 'show'])
        ->middleware(['signed', 'throttle:30,1'])->whereNumber('booking')->name('booking.email.confirm');
    Route::post('/confirm/{booking}', [\App\Http\Controllers\BookingEmailConfirmController::class, 'confirm'])
        ->middleware(['signed', 'throttle:10,1'])->whereNumber('booking')->name('booking.email.confirm.post');
});

// A parcel's public tracking (2026-11-04): the QR on its guide. No login; the token is the access.
Route::get('/encomienda/{token}', [\App\Http\Controllers\TransportParcelTrackController::class, 'show'])
    ->where('token', '[a-f0-9]{24}')->middleware('throttle:60,1');

// PayPal module (2026-10-19): the customer's "Pay now" page of an invoice. The
// page link is signed relative (built on the company's public address, see
// PayPalInvoicePayment::payLink); PayPal comes back with ?token={order id}.
Route::middleware(['web', 'request.safety'])->prefix('pay')->group(function () {
    $c = \App\Http\Controllers\PayPalInvoiceController::class;
    Route::get('/paypal/return', [$c, 'return'])->middleware('throttle:30,1')->name('invoice.pay.return');
    Route::get('/paypal/cancel', [$c, 'cancel'])->middleware('throttle:30,1')->name('invoice.pay.cancel');
    Route::post('/paypal/webhook/{company}', [$c, 'webhook'])->whereNumber('company')->middleware('throttle:120,1')->name('invoice.pay.webhook');
    Route::get('/{sale}', [$c, 'show'])->whereNumber('sale')->middleware(['signed:relative', 'throttle:60,1'])->name('invoice.pay');
    Route::post('/{sale}', [$c, 'start'])->whereNumber('sale')->middleware(['signed:relative', 'throttle:10,1'])->name('invoice.pay.start');
});

// Specialist clinic (2026-10-26): the reminder's confirm / cancel (signed, no
// login) and the satisfaction survey by its token (ClinicPublicController).
Route::middleware(['web', 'request.safety'])->group(function () {
    $c = \App\Http\Controllers\ClinicPublicController::class;
    Route::get('/appointment/{appointment}/{action}', [$c, 'answer'])->whereNumber('appointment')->where('action', 'confirm|cancel')
        ->middleware(['signed:relative', 'throttle:30,1'])->name('clinic.appointment.answer');
    Route::get('/survey/{token}', [$c, 'survey'])->where('token', '[A-Za-z0-9]{20,64}')->middleware('throttle:60,1')->name('clinic.survey');
    Route::post('/survey/{token}', [$c, 'answerSurvey'])->where('token', '[A-Za-z0-9]{20,64}')->middleware('throttle:10,1')->name('clinic.survey.answer');
    // A patient scan (insurance card / ID): private file, opened only with a signed link of a few
    // minutes that the app makes for staff with the hospital permissions (2026-10-26).
    Route::get('/hospital-scan/{id}', [\App\Http\Controllers\HospitalPatientScanController::class, 'file'])->whereNumber('id')
        ->middleware(['signed:relative', 'throttle:120,1'])->name('hospital.scan');
});

// Portfolio module: each company's public portfolio website (PortfolioSiteController).
Route::middleware(['web', 'request.safety'])->prefix('portfolio/{site}')->where(['site' => '[a-z0-9]+(?:-[a-z0-9]+)*'])->group(function () {
    $c = \App\Http\Controllers\PortfolioSiteController::class;
    Route::get('/', [$c, 'home'])->middleware('throttle:120,1')->name('portfolio.home');
    Route::get('/work', [$c, 'work'])->middleware('throttle:120,1')->name('portfolio.work');
    Route::get('/project/{slug}', [$c, 'project'])->where('slug', '[a-z0-9-]+')->middleware('throttle:120,1')->name('portfolio.project');
    Route::get('/about', [$c, 'about'])->middleware('throttle:120,1')->name('portfolio.about');
    Route::get('/contact', [$c, 'contact'])->middleware('throttle:120,1')->name('portfolio.contact');
    Route::post('/contact', [$c, 'sendContact'])->middleware('throttle:5,1,portfolio-contact')->name('portfolio.contact.send');
});

// SEO: public sitemap + robots (registered before the admin catch-all).
Route::get('sitemap.xml', [\App\Http\Controllers\SitemapController::class, 'index'])->name('store.sitemap');
Route::get('robots.txt', [\App\Http\Controllers\SitemapController::class, 'robots'])->name('store.robots');

Route::group(['middleware' => ['web', 'auth:web', 'Is_Active', 'request.safety']], function () {

    // The Vue 2 admin SPA has been retired (its bundle no longer exists). Any
    // old admin URL — including "/" — now redirects into the Vue 3 app at /next,
    // whose router lands on /next/dashboard.
    //
    // The configured store base path is excluded dynamically so unknown store
    // URLs 404 instead of bouncing to the admin login. In root-domain mode the
    // store routes are registered first and win on their own; everything else
    // keeps today's behavior.
    $storeExclusion = '';
    $storeFirstSegment = explode('/', store_path())[0] ?? '';
    if ($storeFirstSegment !== '' && $storeFirstSegment !== 'online_store') {
        $storeExclusion = preg_quote($storeFirstSegment).'|';
    }

    Route::get('/{vue?}',
        function () {
            $installed = Storage::disk('public')->exists('installed');

            if ($installed === false) {
                return redirect('/setup');
            }

            return redirect('/next');
        })->where('vue', '^(?!'.$storeExclusion.'api|setup|update|password|online_store|customer-display|order-ready|quickbooks|salla|xero|google-sheets|portal|recruit|api-docs|next|csrf-token|login|logout|set-locale|support|book(?:/|$)|invoice/).*$');
    // invoice/: the public signed invoice (/invoice/{id}/{signature}, registered further down) --
    // this wildcard swallowed it and sent the customer to /login (2026-10-26).

});

// Guest-accessible language switcher for the login page (throttled: it just
// sets a cookie and redirects, but still a write endpoint reachable with no
// auth) -- see LocaleSyncController::setGuestLocale().
Route::get('set-locale', 'LocaleSyncController@setGuestLocale')->middleware('throttle:30,1')->name('locale.guest');

// Laravel 12 compatibility: define auth routes explicitly (laravel/ui optional)
Route::get('login', 'Auth\LoginController@showLoginForm')->name('login');
// 5 attempts per minute per IP — blocks credential-stuffing / brute force.
Route::post('login', 'Auth\LoginController@login')->middleware('throttle:5,1');
Route::post('logout', 'Auth\LoginController@logout')->name('logout');
// Login security (2026-10-27): the steps after the right password -- two-step code, new password.
Route::get('login/verify', 'Auth\LoginStepsController@showVerify')->name('login.verify');
Route::post('login/verify', 'Auth\LoginStepsController@verify')->middleware('throttle:10,1');
Route::post('login/verify/resend', 'Auth\LoginStepsController@resend')->middleware('throttle:3,1')->name('login.verify.resend');
Route::get('login/password', 'Auth\LoginStepsController@showPassword')->name('login.password');
Route::post('login/password', 'Auth\LoginStepsController@password')->middleware('throttle:10,1');
Route::post('login/cancel', 'Auth\LoginStepsController@cancel')->name('login.cancel');
// SuperAdmin login chooser (2026-10-20): dashboard, or enter as a company's admin; and back.
Route::get('login/choose', 'Auth\LoginChooseController@show')->middleware('auth:web')->name('login.choose');
Route::post('login/choose', 'Auth\LoginChooseController@enter')->middleware(['auth:web', 'throttle:20,1'])->name('login.choose.enter');
Route::post('login/back', 'Auth\LoginChooseController@back')->middleware(['auth:web', 'throttle:20,1'])->name('login.back');
// Company logins -> Open (company admin works as one of its logins) / back (2026-10-25).
Route::post('login/as/{id}', 'CompanyLoginsController@open')->middleware(['auth:web', 'throttle:20,1'])->whereNumber('id');
Route::post('login/as-back', 'CompanyLoginsController@back')->middleware(['auth:web', 'throttle:20,1']);
// POS cashier PIN: switches this browser session to the PIN's user (see PosPinController).
Route::post('pos/pin-switch', 'PosPinController@switchUser')->middleware(['auth:web', 'throttle:10,1'])->name('pos.pin_switch');

// Password Reset Routes...
Route::get('password/reset', 'Auth\\ForgotPasswordController@showLinkRequestForm')->name('password.request');
// Throttle password-reset email requests to stop mailbox flooding.
Route::post('password/email', 'Auth\\ForgotPasswordController@sendResetLinkEmail')
    ->middleware('throttle:5,10')->name('password.email');
Route::get('password/reset/{token}', 'Auth\\ResetPasswordController@showResetForm')->name('password.reset');
Route::post('password/reset', 'Auth\\ResetPasswordController@reset')
    ->middleware('throttle:5,10')->name('password.update');

// ------------------------- -UPDATE ----------------------------------------\\

Route::group(['middleware' => ['web', 'auth:web', 'Is_Active']], function () {

    Route::get('/update', 'UpdateController@viewStep1');

    Route::get('/update/fix_auto_increment', 'UpdateController@fix_auto_increment');

    Route::get('/update/finish', function () {

        return view('update.finishedUpdate');
    });

    Route::post('/update/lastStep', [
        'as' => 'update_lastStep', 'uses' => 'UpdateController@lastStep',
    ]);

    // Standalone System Update recovery console. Deliberately NOT part of
    // the SPA: it must keep working when the SPA assets are broken or the
    // application is stuck in maintenance mode after an interrupted update.
    Route::get('/system-update/recovery', 'SystemUpdateController@recoveryConsole');

});

// -------------------- Public Invoice View (HMAC-signed, no login required) --------------------
Route::get('/invoice/{id}/{signature}', 'SalesController@publicInvoiceView')
    ->middleware(['web'])
    ->where('id', '[0-9]+')
    ->where('signature', '[a-f0-9]{32}');

// -------------------- Public Customer Display (token-guarded) --------------------
// Standalone public page that mounts its own Vue app. Does not affect existing SPA.
Route::get('/customer-display', function (HttpRequest $request) {
    $token = $request->query('token');
    if (! $token || $token !== cache('customer_display_token')) {
        abort(403, 'Unauthorized display access');
    }

    return view('customer_display');
})->middleware(['web']);

// -------------------- Public Order Ready Screen (token-guarded) --------------------
// Customer-facing kitchen token board (Preparing / Ready). Self-contained page,
// polls /api/kitchen/ready-screen/data with the same token.
Route::get('/order-ready', function (HttpRequest $request) {
    $token = $request->query('token');
    if (! $token || $token !== cache('order_ready_token')) {
        abort(403, 'Unauthorized display access');
    }

    // Labels come from the admin translations table in the shop's default
    // language, with English fallbacks baked into the view.
    $settings = \App\Models\Setting::whereNull('deleted_at')->first();
    $locale = $settings->default_language ?? 'en';
    // One column per locale (2026_09_21_190000_pivot_translations_to_wide_table).
    $locale = \Illuminate\Support\Facades\Schema::hasColumn('translations', $locale) ? $locale : 'en';
    $labels = \DB::table('translations')
        ->whereIn('key', ['PreparingOrders', 'ReadyOrders', 'OrderReadyScreen'])
        ->pluck($locale, 'key');

    return view('order_ready', [
        'token' => $token,
        'labels' => $labels,
        'company' => $settings->CompanyName ?? '',
    ]);
})->middleware(['web']);
