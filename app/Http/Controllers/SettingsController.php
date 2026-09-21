<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Language;
use App\Models\PaymentMethod;
use App\Models\PosSetting;
use App\Models\Setting;
use App\Models\sms_gateway;
use App\Models\User;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Intervention\Image\ImageManagerStatic as Image;

class SettingsController extends Controller
{
    // -------------- Update  Settings ---------------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Setting::class);

        $setting = Setting::findOrFail($id);
        $currentAvatar = $setting->logo;
        // Only process logo if a file was actually uploaded
        if ($request->hasFile('logo') && $request->file('logo')->isValid()) {
            $image = $request->file('logo');
            $path = public_path().'/images';
            $filename = rand(11111111, 99999999).$image->getClientOriginalName();

            $image_resize = Image::make($image->getRealPath());

             // Resize to one standard size (800x800)
             $image_resize->resize(800, 800, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            })->save(public_path('/images/'.$filename));

            $userPhoto = $path.'/'.$currentAvatar;
            if (file_exists($userPhoto)) {
                if ($setting->logo != 'logo-default.png') {
                    @unlink($userPhoto);
                }
            }
        } else {
            $filename = $currentAvatar;
        }
        if ($request['currency'] != 'null') {
            $currency = $request['currency'];
        } else {
            $currency = null;
        }

        if ($request['client'] != 'null') {
            $client = $request['client'];
        } else {
            $client = null;
        }

        if ($request['warehouse'] != 'null') {
            $warehouse = $request['warehouse'];
        } else {
            $warehouse = null;
        }

        if ($request['sms_gateway'] != 'null') {
            $sms_gateway = $request['sms_gateway'];
        } else {
            $sms_gateway = null;
        }

        $default_account_id = null;
        if (isset($request['default_account']) && $request['default_account'] !== '' && $request['default_account'] !== 'null') {
            $default_account_id = (int) $request['default_account'];
        }
        $default_payment_method_id = null;
        if (isset($request['default_payment_method']) && $request['default_payment_method'] !== '' && $request['default_payment_method'] !== 'null') {
            $default_payment_method_id = (int) $request['default_payment_method'];
        }

        if ($request['default_language'] != 'null') {
            $default_language = $request['default_language'];
        } else {
            $default_language = 'en';
        }

        if ($request['is_invoice_footer'] == '1' || $request['is_invoice_footer'] == 'true') {
            $is_invoice_footer = 1;
        } else {
            $is_invoice_footer = 0;
        }

        if ($request['quotation_with_stock'] == '1' || $request['quotation_with_stock'] == 'true') {
            $quotation_with_stock = 1;
        } else {
            $quotation_with_stock = 0;
        }

        if ($request['show_language'] == '1' || $request['show_language'] == 'true') {
            $show_language = 1;
        } else {
            $show_language = 0;
        }

        // Dark Mode and RTL settings
        $dark_mode = ($request['dark_mode'] == '1' || $request['dark_mode'] == 'true' || $request['dark_mode'] === 1 || $request['dark_mode'] === true) ? 1 : 0;
        $rtl = ($request['rtl'] == '1' || $request['rtl'] == 'true' || $request['rtl'] === 1 || $request['rtl'] === true) ? 1 : 0;

        // Normalize invoice_format: only allow 'thermal' or 'a4', default to 'thermal'
        $invoice_format = 'thermal';
        if (isset($request['invoice_format']) && in_array($request['invoice_format'], ['thermal', 'a4'], true)) {
            $invoice_format = $request['invoice_format'];
        } elseif (!empty($setting->invoice_format) && in_array($setting->invoice_format, ['thermal', 'a4'], true)) {
            $invoice_format = $setting->invoice_format;
        }

        // Normalize A4 invoice logo dimensions: clamp to a sensible pixel range.
        // Defaults match the historical CSS values used in the A4 invoice header.
        $invoice_logo_width = (int) ($request['invoice_logo_width'] ?? $setting->invoice_logo_width ?? 180);
        if ($invoice_logo_width < 20) {
            $invoice_logo_width = 20;
        } elseif ($invoice_logo_width > 600) {
            $invoice_logo_width = 600;
        }

        $invoice_logo_height = (int) ($request['invoice_logo_height'] ?? $setting->invoice_logo_height ?? 60);
        if ($invoice_logo_height < 20) {
            $invoice_logo_height = 20;
        } elseif ($invoice_logo_height > 400) {
            $invoice_logo_height = 400;
        }

        // Sidebar brand logo dimensions: clamp to a sensible pixel range.
        $sidebar_logo_width = (int) ($request['sidebar_logo_width'] ?? $setting->sidebar_logo_width ?? 32);
        $sidebar_logo_width = max(16, min(200, $sidebar_logo_width));
        $sidebar_logo_height = (int) ($request['sidebar_logo_height'] ?? $setting->sidebar_logo_height ?? 32);
        $sidebar_logo_height = max(16, min(120, $sidebar_logo_height));

        // Sidebar brand toggles — guarded with has() so older callers of this
        // endpoint that don't send them keep the stored values.
        $sidebar_show_logo = $request->has('sidebar_show_logo')
            ? (($request['sidebar_show_logo'] == '1' || $request['sidebar_show_logo'] == 'true' || $request['sidebar_show_logo'] === 1 || $request['sidebar_show_logo'] === true) ? 1 : 0)
            : (($setting->sidebar_show_logo ?? true) ? 1 : 0);
        $hide_site_name = $request->has('hide_site_name')
            ? (($request['hide_site_name'] == '1' || $request['hide_site_name'] == 'true' || $request['hide_site_name'] === 1 || $request['hide_site_name'] === true) ? 1 : 0)
            : (($setting->hide_site_name ?? false) ? 1 : 0);

        Setting::whereId($id)->update([
            'currency_id' => $currency,
            'client_id' => $client,
            'warehouse_id' => $warehouse,
            'default_account_id' => $default_account_id,
            'default_payment_method_id' => $default_payment_method_id,
            'email' => $request['email'],
            'default_language' => $default_language,
            'CompanyName' => $request['CompanyName'],
            'CompanyPhone' => $request['CompanyPhone'],
            'CompanyAdress' => $request['CompanyAdress'],
            'company_name_ar' => $request['company_name_ar'] ?? $setting->company_name_ar,
            'vat_number' => $request['vat_number'] ?? $setting->vat_number,
            'zatca_enabled' => ($request['zatca_enabled'] == '1' || $request['zatca_enabled'] == 'true' || $request['zatca_enabled'] === 1 || $request['zatca_enabled'] === true) ? 1 : 0,
            'footer' => $request['footer'],
            'developed_by' => $request['developed_by'],
            'is_invoice_footer' => $is_invoice_footer,
            'invoice_format' => $invoice_format,
            'invoice_logo_width' => $invoice_logo_width,
            'invoice_logo_height' => $invoice_logo_height,
            'sidebar_logo_width' => $sidebar_logo_width,
            'sidebar_logo_height' => $sidebar_logo_height,
            'sidebar_show_logo' => $sidebar_show_logo,
            'hide_site_name' => $hide_site_name,
            'quotation_with_stock' => $quotation_with_stock,
            'show_language' => $show_language,
            'dark_mode' => $dark_mode,
            'rtl' => $rtl,
            'invoice_footer' => $request['invoice_footer'],
            'sms_gateway' => $sms_gateway,
            'logo' => $filename,
            'point_to_amount_rate' => $request['point_to_amount_rate'],
            'default_tax' => $request['default_tax'] ?? 0,
            'default_dashboard_date_range' => in_array($request['default_dashboard_date_range'] ?? 'week', ['today', 'week', 'month'], true) ? $request['default_dashboard_date_range'] : 'week',
            'dashboard_section_order' => $request['dashboard_section_order'] ?? null,
            'dashboard_grid_layout' => $request['dashboard_grid_layout'] ?? null,
            'dashboard_font_size' => $request['dashboard_font_size'] ?? null,
            'dashboard_font_family' => $request['dashboard_font_family'] ?? null,
            'date_format' => $request['date_format'] ?? 'YYYY-MM-DD',
            'sale_prefix' => $request['sale_prefix'] ?? null,
            'purchase_prefix' => $request['purchase_prefix'] ?? null,
            'quotation_prefix' => $request['quotation_prefix'] ?? null,
            'adjustment_prefix' => $request['adjustment_prefix'] ?? null,
            'transfer_prefix' => $request['transfer_prefix'] ?? null,
            'sale_return_prefix' => $request['sale_return_prefix'] ?? null,
            'purchase_return_prefix' => $request['purchase_return_prefix'] ?? null,
            // Optional price format for frontend display (POS, etc.)
            'price_format' => $request['price_format'] ?? $setting->price_format,
            // Cloud backup settings
            'backup_cloud_enabled' => ($request['backup_cloud_enabled'] == '1' || $request['backup_cloud_enabled'] == 'true' || $request['backup_cloud_enabled'] === 1 || $request['backup_cloud_enabled'] === true) ? 1 : 0,
            'backup_keep_local' => ($request['backup_keep_local'] == '1' || $request['backup_keep_local'] == 'true' || $request['backup_keep_local'] === 1 || $request['backup_keep_local'] === true) ? 1 : 0,
            'backup_cloud_provider' => $request['backup_cloud_provider'] ?? null,
            'backup_cloud_path' => $request['backup_cloud_path'] ?? null,
            // S3-compatible settings
            'backup_s3_bucket' => $request['backup_s3_bucket'] ?? null,
            'backup_s3_region' => $request['backup_s3_region'] ?? null,
            'backup_s3_access_key' => $request['backup_s3_access_key'] ?? null,
            'backup_s3_secret_key' => $request->has('backup_s3_secret_key') && $request['backup_s3_secret_key'] !== '' ? $request['backup_s3_secret_key'] : $setting->backup_s3_secret_key,
            'backup_s3_endpoint' => $request['backup_s3_endpoint'] ?? null,
            'backup_s3_path_style' => ($request['backup_s3_path_style'] == '1' || $request['backup_s3_path_style'] == 'true' || $request['backup_s3_path_style'] === 1 || $request['backup_s3_path_style'] === true) ? 1 : 0,
            // Google Drive settings
            'backup_gdrive_folder_id' => $request['backup_gdrive_folder_id'] ?? null,
            'backup_gdrive_access_token' => $request->has('backup_gdrive_access_token') && $request['backup_gdrive_access_token'] !== '' ? $request['backup_gdrive_access_token'] : $setting->backup_gdrive_access_token,
            'backup_gdrive_refresh_token' => $request->has('backup_gdrive_refresh_token') && $request['backup_gdrive_refresh_token'] !== '' ? $request['backup_gdrive_refresh_token'] : $setting->backup_gdrive_refresh_token,
            'backup_gdrive_client_id' => $request['backup_gdrive_client_id'] ?? null,
            'backup_gdrive_client_secret' => $request->has('backup_gdrive_client_secret') && $request['backup_gdrive_client_secret'] !== '' ? $request['backup_gdrive_client_secret'] : $setting->backup_gdrive_client_secret,
            // Dropbox settings
            'backup_dropbox_path' => $request['backup_dropbox_path'] ?? null,
            'backup_dropbox_access_token' => $request->has('backup_dropbox_access_token') && $request['backup_dropbox_access_token'] !== '' ? $request['backup_dropbox_access_token'] : $setting->backup_dropbox_access_token,
            // POS offline sync toggle (when false, POS requires online and disables offline queueing)
            'offline_sync_enabled' => $request->has('offline_sync_enabled')
                ? (($request['offline_sync_enabled'] == '1' || $request['offline_sync_enabled'] == 'true' || $request['offline_sync_enabled'] === 1 || $request['offline_sync_enabled'] === true) ? 1 : 0)
                : (int) ($setting->offline_sync_enabled ?? 1),
            // 3-decimal pricing toggle (when true, prices/costs/totals support up to 3 decimals)
            'enable_3_decimal_pricing' => $request->has('enable_3_decimal_pricing')
                ? (($request['enable_3_decimal_pricing'] == '1' || $request['enable_3_decimal_pricing'] == 'true' || $request['enable_3_decimal_pricing'] === 1 || $request['enable_3_decimal_pricing'] === true) ? 1 : 0)
                : (int) ($setting->enable_3_decimal_pricing ?? 0),
            // Kitchen display toggle (when true, POS can route orders to the Kitchen Display page)
            'enable_kitchen_display' => $request->has('enable_kitchen_display')
                ? (($request['enable_kitchen_display'] == '1' || $request['enable_kitchen_display'] == 'true' || $request['enable_kitchen_display'] === 1 || $request['enable_kitchen_display'] === true) ? 1 : 0)
                : (int) ($setting->enable_kitchen_display ?? 0),
            // Kitchen prep-time target (minutes) for the board's overdue color escalation;
            // empty/0 = no escalation
            'kitchen_target_minutes' => $request->has('kitchen_target_minutes')
                ? (($request['kitchen_target_minutes'] === '' || $request['kitchen_target_minutes'] === null || (int) $request['kitchen_target_minutes'] <= 0)
                    ? null
                    : min(1440, (int) $request['kitchen_target_minutes']))
                : ($setting->kitchen_target_minutes ?? null),
            // Auto-create a kitchen ticket when an online order is confirmed
            'kitchen_auto_online_orders' => $request->has('kitchen_auto_online_orders')
                ? (($request['kitchen_auto_online_orders'] == '1' || $request['kitchen_auto_online_orders'] == 'true' || $request['kitchen_auto_online_orders'] === 1 || $request['kitchen_auto_online_orders'] === true) ? 1 : 0)
                : (int) ($setting->kitchen_auto_online_orders ?? 0),
            // Show/hide the Barcode (GTIN / UPC / EAN / ISBN) field on the product create form (default on)
            'show_product_gtin' => $request->has('show_product_gtin')
                ? (($request['show_product_gtin'] == '1' || $request['show_product_gtin'] == 'true' || $request['show_product_gtin'] === 1 || $request['show_product_gtin'] === true) ? 1 : 0)
                : (int) ($setting->show_product_gtin ?? 1),
            // Downscale uploaded product images on save (default on). When off the
            // original file is stored untouched; product_image_max_size is the
            // longest-edge box every product/variant/gallery image is fitted into.
            'product_image_resize' => $request->has('product_image_resize')
                ? (($request['product_image_resize'] == '1' || $request['product_image_resize'] == 'true' || $request['product_image_resize'] === 1 || $request['product_image_resize'] === true) ? 1 : 0)
                : (int) ($setting->product_image_resize ?? 1),
            'product_image_max_size' => $request->has('product_image_max_size')
                ? max(50, min(5000, (int) $request['product_image_max_size']))
                : (int) ($setting->product_image_max_size ?? 800),
            // Master switch for the Serial / IMEI tracking module (default off)
            'show_serial_tracking' => $request->has('show_serial_tracking')
                ? (($request['show_serial_tracking'] == '1' || $request['show_serial_tracking'] == 'true' || $request['show_serial_tracking'] === 1 || $request['show_serial_tracking'] === true) ? 1 : 0)
                : (int) ($setting->show_serial_tracking ?? 0),
            // Master switch for the Multi-Pack Selling module (default off)
            'enable_multi_pack_selling' => $request->has('enable_multi_pack_selling')
                ? (($request['enable_multi_pack_selling'] == '1' || $request['enable_multi_pack_selling'] == 'true' || $request['enable_multi_pack_selling'] === 1 || $request['enable_multi_pack_selling'] === true) ? 1 : 0)
                : (int) ($setting->enable_multi_pack_selling ?? 0),
            'enable_wholesale_pricing' => $request->has('enable_wholesale_pricing')
                ? (($request['enable_wholesale_pricing'] == '1' || $request['enable_wholesale_pricing'] == 'true' || $request['enable_wholesale_pricing'] === 1 || $request['enable_wholesale_pricing'] === true) ? 1 : 0)
                : (int) ($setting->enable_wholesale_pricing ?? 0),
            // Master switch for the Multi-Currency module (default off)
            'enable_multi_currency' => $request->has('enable_multi_currency')
                ? (($request['enable_multi_currency'] == '1' || $request['enable_multi_currency'] == 'true' || $request['enable_multi_currency'] === 1 || $request['enable_multi_currency'] === true) ? 1 : 0)
                : (int) ($setting->enable_multi_currency ?? 0),
            // Change Salesperson During Checkout: POS shows a salesperson picker
            // and the chosen user is saved as the sale's seller (default off)
            'enable_pos_salesperson_switch' => $request->has('enable_pos_salesperson_switch')
                ? (($request['enable_pos_salesperson_switch'] == '1' || $request['enable_pos_salesperson_switch'] == 'true' || $request['enable_pos_salesperson_switch'] === 1 || $request['enable_pos_salesperson_switch'] === true) ? 1 : 0)
                : (int) ($setting->enable_pos_salesperson_switch ?? 0),
            // Session timeout (Security tab): minutes before automatic logout;
            // empty/null = never expire. Clamped to ≥5 so a typo can't lock
            // everyone out the moment they log in.
            'session_timeout_minutes' => $request->has('session_timeout_minutes')
                ? (($request['session_timeout_minutes'] === '' || $request['session_timeout_minutes'] === null || (int) $request['session_timeout_minutes'] <= 0)
                    ? null
                    : max(5, (int) $request['session_timeout_minutes']))
                : ($setting->session_timeout_minutes ?? null),
            // Allow Overselling: global switch — every stock check (POS, sales,
            // quotations, transfers, adjustments, damages, imports) is bypassed
            // and stock may go negative (default off)
            'allow_overselling' => $request->has('allow_overselling')
                ? (($request['allow_overselling'] == '1' || $request['allow_overselling'] == 'true' || $request['allow_overselling'] === 1 || $request['allow_overselling'] === true) ? 1 : 0)
                : (int) ($setting->allow_overselling ?? 0),
            // Vehicle Fitment & My Garage: storefront/POS vehicle selector,
            // compatibility filtering and purchase guards (default off)
            'vehicle_fitment_enabled' => $request->has('vehicle_fitment_enabled')
                ? (($request['vehicle_fitment_enabled'] == '1' || $request['vehicle_fitment_enabled'] == 'true' || $request['vehicle_fitment_enabled'] === 1 || $request['vehicle_fitment_enabled'] === true) ? 1 : 0)
                : (int) ($setting->vehicle_fitment_enabled ?? 0),
            // Accounting V2 auto journal entries; null (never saved) falls back
            // to the accounting_v2.auto_generate_journals config/env value
            'auto_journal_enabled' => $request->has('auto_journal_enabled')
                ? (($request['auto_journal_enabled'] == '1' || $request['auto_journal_enabled'] == 'true' || $request['auto_journal_enabled'] === 1 || $request['auto_journal_enabled'] === true) ? 1 : 0)
                : $setting->auto_journal_enabled,
            // Global export defaults (JSON: scope, totals, pdf_orientation,
            // filename_date, pdf_meta) — kept when the caller doesn't send it
            // or sends malformed JSON.
            'export_settings' => ($request->has('export_settings') && json_decode($request['export_settings'], true) !== null)
                ? $request['export_settings']
                : $setting->export_settings,
        ] + $this->pharmacySettingsPayload($request, $setting));

        if (! empty($currency)) {
            $currencyModel = \App\Models\Currency::find($currency);
            if ($currencyModel) {
                \App\Models\StoreSetting::query()->update([
                    'currency_code' => $currencyModel->symbol,
                ]);
            }
        }

        // SetLocale caches default_language (it runs on every web request), and
        // it is what guest pages like /login render in — drop it on save.
        \Illuminate\Support\Facades\Cache::forget('settings.default_language');

        // Set selected language as default (only if language is provided and exists)
        if (! empty($default_language)) {
            $language = Language::where('locale', $default_language)->first();

            // Only process if language was found
            if ($language) {
                // Skip if already default
                if (! $language->is_default) {
                    // Set this one as default
                    $language->update(['is_default' => true]);

                    // Unset others
                    Language::where('id', '!=', $language->id)
                        ->where('is_default', true)
                        ->update(['is_default' => false]);
                }
            }
        }

        // Prepare environment values
        $envValues = [
            'APP_TIMEZONE' => $request['timezone'] !== null ? '"'.$request['timezone'].'"' : '"UTC"',
        ];

        // Handle Debug Mode
        if ($request->has('debug_mode')) {
            $debug_mode = ($request['debug_mode'] == '1' || $request['debug_mode'] == 'true' || $request['debug_mode'] === 1 || $request['debug_mode'] === true) ? 'true' : 'false';
            $envValues['APP_DEBUG'] = $debug_mode;
        }

        $this->setEnvironmentValue($envValues);

        // Clear config cache first to remove old cached values
        Artisan::call('config:clear');
        
        // If APP_DEBUG was changed, also clear route and view cache for immediate effect
        if ($request->has('debug_mode')) {
            Artisan::call('route:clear');
            Artisan::call('view:clear');
        }
        
        // Re-cache config with new values
        Artisan::call('config:cache');

        return response()->json(['success' => true]);
    }

    // -------------- Dark Mode: Get current status ---------------\\

    /**
     * Return the current dark_mode flag from settings.
     * This endpoint is intentionally minimal and independent of other settings APIs.
     */
    public function getDarkMode(Request $request)
    {

        $settings = Setting::where('deleted_at', '=', null)->first();

        return response()->json([
            'dark_mode' => $settings ? (bool) ($settings->dark_mode ?? false) : false,
        ], 200);
    }

    // -------------- Dark Mode: Update status ---------------\\

    /**
     * Update the dark_mode flag only.
     * This endpoint is dedicated to Dark Mode and does not touch any other settings.
     */
    public function updateDarkMode(Request $request)
    {

        $request->validate([
            'dark_mode' => 'required',
        ]);

        $settings = Setting::where('deleted_at', '=', null)->first();
        if (! $settings) {
            return response()->json(['message' => 'Settings not found'], 404);
        }

        $dark_mode = (
            $request['dark_mode'] == '1' ||
            $request['dark_mode'] == 'true' ||
            $request['dark_mode'] === 1 ||
            $request['dark_mode'] === true
        ) ? 1 : 0;

        $settings->dark_mode = $dark_mode;
        $settings->save();

        return response()->json([
            'success'   => true,
            'dark_mode' => (bool) $settings->dark_mode,
        ], 200);
    }

    // -------------- Get Pos Settings ---------------\\

    public function get_pos_Settings(Request $request)
    {

        $PosSetting = PosSetting::where('deleted_at', '=', null)->first();
        $settings = Setting::where('deleted_at', '=', null)->first();

        // Allow Overselling is a global feature (settings row) — surface it on
        // the pos_settings payload so POS / sale forms keep reading it here.
        if ($PosSetting) {
            $PosSetting->allow_overselling = (bool) ($settings->allow_overselling ?? false);
        }

        return response()->json([
            'pos_settings' => $PosSetting,
        ], 200);

    }

    public function get_pos_Settings_api(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'pos_settings', Setting::class);

        $PosSetting = PosSetting::where('deleted_at', '=', null)->first();
        $settings = Setting::where('deleted_at', '=', null)->first();

        // Allow Overselling is a global feature (settings row) — surface it on
        // the pos_settings payload so existing consumers keep reading it here.
        if ($PosSetting) {
            $PosSetting->allow_overselling = (bool) ($settings->allow_overselling ?? false);
        }

        return response()->json([
            'pos_settings' => $PosSetting,
            // Global invoice format (thermal|a4) — editable from POS Settings
            // (update_pos_settings already persists it back to `settings`).
            'invoice_format' => $settings && in_array($settings->invoice_format, ['thermal', 'a4'], true)
                ? $settings->invoice_format : 'thermal',
        ], 200);

    }

    // -------------- Update Pos settings ---------------\\

    public function update_pos_settings(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'pos_settings', Setting::class);

        // Partial updates are supported: each field is applied only when
        // present (see $request->has() checks below). Don't hard-require
        // note_customer so callers that update a single field (e.g. the
        // Defaults tab saving only products_per_page) aren't rejected.
        $request->validate([
            'note_customer' => 'sometimes|nullable|string',
        ]);

        $posSettings = PosSetting::findOrFail($id);

        // Allow POS Settings endpoint to also update the global invoice_format
        // so that users can control the invoice layout (thermal vs A4) directly
        // from the POS Settings screens.
        if ($request->has('invoice_format')) {
            $settings = Setting::where('deleted_at', '=', null)->first();
            if ($settings) {
                // Normalize invoice_format: only allow 'thermal' or 'a4', default to existing or 'thermal'
                $invoice_format = 'thermal';
                if (isset($request['invoice_format']) && in_array($request['invoice_format'], ['thermal', 'a4'], true)) {
                    $invoice_format = $request['invoice_format'];
                } elseif (!empty($settings->invoice_format) && in_array($settings->invoice_format, ['thermal', 'a4'], true)) {
                    $invoice_format = $settings->invoice_format;
                }

                $settings->invoice_format = $invoice_format;
                $settings->save();
            }
        }

        // Build a partial update array so that each page only updates
        // the fields it actually sends. This prevents POS Receipt submit
        // from resetting POS Settings toggles (and vice versa).
        $data = [];

        // Common text field
        if ($request->has('note_customer')) {
            $data['note_customer'] = $request->input('note_customer');
        }

        // Receipt-related toggles
        if ($request->has('show_logo')) {
            $data['show_logo'] = ($request['show_logo'] == '1' || $request['show_logo'] == 'true' || $request['show_logo'] === true) ? 1 : 0;
        }

        if ($request->has('logo_size')) {
            // Validate logo_size: must be between 20 and 200 pixels
            $logoSize = (int) $request['logo_size'];
            $data['logo_size'] = ($logoSize >= 20 && $logoSize <= 200) ? $logoSize : 60;
        }

        if ($request->has('show_store_name')) {
            $data['show_store_name'] = ($request['show_store_name'] == '1' || $request['show_store_name'] == 'true' || $request['show_store_name'] === true) ? 1 : 0;
        }

        if ($request->has('show_reference')) {
            $data['show_reference'] = ($request['show_reference'] == '1' || $request['show_reference'] == 'true' || $request['show_reference'] === true) ? 1 : 0;
        }

        if ($request->has('show_date')) {
            $data['show_date'] = ($request['show_date'] == '1' || $request['show_date'] == 'true' || $request['show_date'] === true) ? 1 : 0;
        }

        if ($request->has('show_seller')) {
            $data['show_seller'] = ($request['show_seller'] == '1' || $request['show_seller'] == 'true' || $request['show_seller'] === true) ? 1 : 0;
        }

        if ($request->has('show_note')) {
            $data['show_note'] = $request['show_note'];
        }

        if ($request->has('show_barcode')) {
            $data['show_barcode'] = $request['show_barcode'];
        }

        if ($request->has('show_discount')) {
            $data['show_discount'] = $request['show_discount'];
        }

        if ($request->has('show_product_discount')) {
            $data['show_product_discount'] = ($request['show_product_discount'] == '1' || $request['show_product_discount'] == 'true' || $request['show_product_discount'] === true) ? 1 : 0;
        }

        if ($request->has('show_tax')) {
            $data['show_tax'] = ($request['show_tax'] == '1' || $request['show_tax'] == 'true' || $request['show_tax'] === true) ? 1 : 0;
        }

        if ($request->has('show_items_tax')) {
            $data['show_items_tax'] = ($request['show_items_tax'] == '1' || $request['show_items_tax'] == 'true' || $request['show_items_tax'] === true) ? 1 : 0;
        }

        if ($request->has('show_shipping')) {
            $data['show_shipping'] = ($request['show_shipping'] == '1' || $request['show_shipping'] == 'true' || $request['show_shipping'] === true) ? 1 : 0;
        }

        if ($request->has('show_customer')) {
            $data['show_customer'] = $request['show_customer'];
        }

        if ($request->has('show_email')) {
            $data['show_email'] = $request['show_email'];
        }

        if ($request->has('show_phone')) {
            $data['show_phone'] = $request['show_phone'];
        }

        if ($request->has('show_address')) {
            $data['show_address'] = $request['show_address'];
        }

        if ($request->has('receipt_paper_size')) {
            // Sanitize receipt paper size (58mm, 80mm, 88mm)
            $candidateSize = (int) $request['receipt_paper_size'];
            $allowedSizes = [58, 80, 88];
            $data['receipt_paper_size'] = in_array($candidateSize, $allowedSizes, true) ? $candidateSize : 80;
        }

        if ($request->has('show_paid')) {
            $data['show_paid'] = ($request['show_paid'] == '1' || $request['show_paid'] == 'true' || $request['show_paid'] === true) ? 1 : 0;
        }

        if ($request->has('show_due')) {
            $data['show_due'] = ($request['show_due'] == '1' || $request['show_due'] == 'true' || $request['show_due'] === true) ? 1 : 0;
        }

        if ($request->has('show_previous_dues')) {
            $data['show_previous_dues'] = ($request['show_previous_dues'] == '1' || $request['show_previous_dues'] == 'true' || $request['show_previous_dues'] === true) ? 1 : 0;
        }

        if ($request->has('show_net_balance')) {
            $data['show_net_balance'] = ($request['show_net_balance'] == '1' || $request['show_net_balance'] == 'true' || $request['show_net_balance'] === true) ? 1 : 0;
        }

        if ($request->has('show_payments')) {
            $data['show_payments'] = ($request['show_payments'] == '1' || $request['show_payments'] == 'true' || $request['show_payments'] === true) ? 1 : 0;
        }

        if ($request->has('show_zatca_qr')) {
            $data['show_zatca_qr'] = ($request['show_zatca_qr'] == '1' || $request['show_zatca_qr'] == 'true' || $request['show_zatca_qr'] === true) ? 1 : 0;
        }

        if ($request->has('cash_drawer_auto_open')) {
            $data['cash_drawer_auto_open'] = ($request['cash_drawer_auto_open'] == '1' || $request['cash_drawer_auto_open'] == 'true' || $request['cash_drawer_auto_open'] === true);
        }

        if ($request->has('cash_drawer_printer_name')) {
            $data['cash_drawer_printer_name'] = $request->input('cash_drawer_printer_name') ?: null;
        }

        // Direct Network Printing: purely additive settings used by future
        // raw-socket (RAW/9100) printing. Existing driver-based printing paths
        // continue to work unchanged when this toggle is OFF.
        if ($request->has('direct_network_printing')) {
            $data['direct_network_printing'] = ($request['direct_network_printing'] == '1' || $request['direct_network_printing'] == 'true' || $request['direct_network_printing'] === true) ? 1 : 0;
        }

        if ($request->has('network_printer_ip')) {
            $ip = trim((string) $request->input('network_printer_ip'));
            $data['network_printer_ip'] = $ip === '' ? null : $ip;
        }

        if ($request->has('network_printer_port')) {
            $port = (int) $request->input('network_printer_port');
            $data['network_printer_port'] = ($port >= 1 && $port <= 65535) ? $port : null;
        }

        // Direct label printing (raw TSPL from the Print Barcode page).
        if ($request->has('label_printer_enabled')) {
            $data['label_printer_enabled'] = ($request['label_printer_enabled'] == '1' || $request['label_printer_enabled'] == 'true' || $request['label_printer_enabled'] === true) ? 1 : 0;
        }

        if ($request->has('label_printer_connection')) {
            $connection = $request->input('label_printer_connection');
            $data['label_printer_connection'] = in_array($connection, ['network', 'qz'], true) ? $connection : 'windows';
        }

        if ($request->has('label_printer_render_mode')) {
            $data['label_printer_render_mode'] = $request->input('label_printer_render_mode') === 'raster' ? 'raster' : 'native';
        }

        if ($request->has('label_printer_name')) {
            $data['label_printer_name'] = trim((string) $request->input('label_printer_name')) ?: null;
        }

        if ($request->has('label_printer_ip')) {
            $ip = trim((string) $request->input('label_printer_ip'));
            $data['label_printer_ip'] = $ip === '' ? null : $ip;
        }

        if ($request->has('label_printer_port')) {
            $port = (int) $request->input('label_printer_port');
            $data['label_printer_port'] = ($port >= 1 && $port <= 65535) ? $port : null;
        }

        if ($request->has('label_printer_dpi')) {
            $data['label_printer_dpi'] = (int) $request->input('label_printer_dpi') === 300 ? 300 : 203;
        }

        if ($request->has('label_printer_tear')) {
            $data['label_printer_tear'] = ($request['label_printer_tear'] == '1' || $request['label_printer_tear'] == 'true' || $request['label_printer_tear'] === true) ? 1 : 0;
        }

        if ($request->has('label_printer_offset_x_mm')) {
            $data['label_printer_offset_x_mm'] = max(-10, min(10, (float) $request->input('label_printer_offset_x_mm')));
        }

        if ($request->has('label_printer_offset_y_mm')) {
            $data['label_printer_offset_y_mm'] = max(-10, min(10, (float) $request->input('label_printer_offset_y_mm')));
        }

        if ($request->has('label_printer_width_mm')) {
            $width = (float) $request->input('label_printer_width_mm');
            $data['label_printer_width_mm'] = ($width >= 20 && $width <= 200) ? $width : null;
        }

        if ($request->has('label_printer_height_mm')) {
            $height = (float) $request->input('label_printer_height_mm');
            $data['label_printer_height_mm'] = ($height >= 10 && $height <= 200) ? $height : null;
        }

        if ($request->has('label_printer_gap_mm')) {
            $data['label_printer_gap_mm'] = max(0, min(10, (float) $request->input('label_printer_gap_mm')));
        }

        if ($request->has('label_printer_density')) {
            $data['label_printer_density'] = max(0, min(15, (int) $request->input('label_printer_density')));
        }

        if ($request->has('label_printer_speed')) {
            $data['label_printer_speed'] = max(1, min(8, (int) $request->input('label_printer_speed')));
        }

        if ($request->has('label_printer_direction')) {
            $data['label_printer_direction'] = ((int) $request->input('label_printer_direction')) === 1 ? 1 : 0;
        }

        if ($request->has('is_printable')) {
            $data['is_printable'] = ($request['is_printable'] == '1' || $request['is_printable'] == 'true' || $request['is_printable'] === true) ? 1 : 0;
        }

        if ($request->has('show_Warehouse')) {
            $data['show_Warehouse'] = ($request['show_Warehouse'] == '1' || $request['show_Warehouse'] == 'true' || $request['show_Warehouse'] === true) ? 1 : 0;
        }

        // POS behaviour / display toggles (POS Settings page)
        if ($request->has('quick_add_customer')) {
            $data['quick_add_customer'] = ($request['quick_add_customer'] == '1' || $request['quick_add_customer'] == 'true' || $request['quick_add_customer'] === true) ? 1 : 0;
        }

        if ($request->has('barcode_scanning_sound')) {
            $data['barcode_scanning_sound'] = ($request['barcode_scanning_sound'] == '1' || $request['barcode_scanning_sound'] == 'true' || $request['barcode_scanning_sound'] === true) ? 1 : 0;
        }

        if ($request->has('show_product_images')) {
            $data['show_product_images'] = ($request['show_product_images'] == '1' || $request['show_product_images'] == 'true' || $request['show_product_images'] === true) ? 1 : 0;
        }

        if ($request->has('show_stock_quantity')) {
            $data['show_stock_quantity'] = ($request['show_stock_quantity'] == '1' || $request['show_stock_quantity'] == 'true' || $request['show_stock_quantity'] === true) ? 1 : 0;
        }

        if ($request->has('enable_hold_sales')) {
            $data['enable_hold_sales'] = ($request['enable_hold_sales'] == '1' || $request['enable_hold_sales'] == 'true' || $request['enable_hold_sales'] === true) ? 1 : 0;
        }

        if ($request->has('enable_customer_points')) {
            $data['enable_customer_points'] = ($request['enable_customer_points'] == '1' || $request['enable_customer_points'] == 'true' || $request['enable_customer_points'] === true) ? 1 : 0;
        }

        if ($request->has('show_categories')) {
            $data['show_categories'] = ($request['show_categories'] == '1' || $request['show_categories'] == 'true' || $request['show_categories'] === true) ? 1 : 0;
        }

        if ($request->has('show_brands')) {
            $data['show_brands'] = ($request['show_brands'] == '1' || $request['show_brands'] == 'true' || $request['show_brands'] === true) ? 1 : 0;
        }

        // Customer purchase history panel in the POS (default ON)
        if ($request->has('show_customer_history')) {
            $data['show_customer_history'] = ($request['show_customer_history'] == '1' || $request['show_customer_history'] == 'true' || $request['show_customer_history'] === true) ? 1 : 0;
        }

        // Allow Overselling moved to the global settings row (Features tab).
        // The in-POS toggle still posts it here, so persist it globally instead
        // of on pos_settings.
        if ($request->has('allow_overselling')) {
            $globalSettings = Setting::where('deleted_at', '=', null)->first();
            if ($globalSettings) {
                $globalSettings->allow_overselling = ($request['allow_overselling'] == '1' || $request['allow_overselling'] == 'true' || $request['allow_overselling'] === true) ? 1 : 0;
                $globalSettings->save();
            }
        }

        if ($request->has('products_per_page')) {
            $data['products_per_page'] = $request['products_per_page'];
        }

        // Receipt font (POS Receipt page). Family is a CSS font stack kept to a
        // safe charset (letters, digits, spaces, commas, quotes, hyphens) so it
        // can be injected into print-window <style> tags; empty → null = default.
        if ($request->has('receipt_font_family')) {
            $family = trim((string) $request->input('receipt_font_family'));
            $family = preg_replace("/[^a-zA-Z0-9 ,'\\-]/", '', $family);
            $data['receipt_font_family'] = $family !== '' ? mb_substr($family, 0, 120) : null;
        }

        // Font size in px, clamped to a printable range; out-of-range → null = default.
        if ($request->has('receipt_font_size')) {
            $fontSize = (int) $request->input('receipt_font_size');
            $data['receipt_font_size'] = ($fontSize >= 8 && $fontSize <= 24) ? $fontSize : null;
        }

        // Receipt layout (1-5) – only if sent (POS Receipt / POS Settings pages).
        // 5 (Minimal) was missing from the old clamp and silently reset to 1.
        if ($request->has('receipt_layout')) {
            $candidate = (int) $request['receipt_layout'];
            $data['receipt_layout'] = in_array($candidate, [1, 2, 3, 4, 5], true) ? $candidate : 1;
        }

        if (! empty($data)) {
            $posSettings->update($data);
        }

        return response()->json(['success' => true]);
    } 

    // -------------- Get All Settings ---------------\\

    public function get_Settings_data_api(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Setting::class);

        $settings = Setting::where('deleted_at', '=', null)->first();
        if ($settings) {
            if ($settings->currency_id) {
                if (Currency::where('id', $settings->currency_id)->where('deleted_at', '=', null)->first()) {
                    $item['currency_id'] = $settings->currency_id;
                } else {
                    $item['currency_id'] = '';
                }
            } else {
                $item['currency_id'] = '';
            }

            if ($settings->client_id) {
                if (Client::where('id', $settings->client_id)->where('deleted_at', '=', null)->first()) {
                    $item['client_id'] = $settings->client_id;
                } else {
                    $item['client_id'] = '';
                }
            } else {
                $item['client_id'] = '';
            }

            if ($settings->warehouse_id) {
                if (Warehouse::where('id', $settings->warehouse_id)->where('deleted_at', '=', null)->first()) {
                    $item['warehouse_id'] = $settings->warehouse_id;
                } else {
                    $item['warehouse_id'] = '';
                }
            } else {
                $item['warehouse_id'] = '';
            }

            if ($settings->sms_gateway) {
                if (sms_gateway::where('id', $settings->sms_gateway)->where('deleted_at', '=', null)->first()) {
                    $item['sms_gateway'] = $settings->sms_gateway;
                } else {
                    $item['sms_gateway'] = '';
                }
            } else {
                $item['sms_gateway'] = '';
            }

            $item['default_account_id'] = '';
            if (! empty($settings->default_account_id) && Account::where('id', $settings->default_account_id)->whereNull('deleted_at')->first()) {
                $item['default_account_id'] = $settings->default_account_id;
            }
            $item['default_payment_method_id'] = '';
            if (! empty($settings->default_payment_method_id) && PaymentMethod::active()->where('id', $settings->default_payment_method_id)->whereNull('deleted_at')->first()) {
                $item['default_payment_method_id'] = $settings->default_payment_method_id;
            }

            $item['id'] = $settings->id;
            $item['email'] = $settings->email;
            $item['CompanyName'] = $settings->CompanyName;
            $item['CompanyPhone'] = $settings->CompanyPhone;
            $item['CompanyAdress'] = $settings->CompanyAdress;
            $item['logo'] = $settings->logo;
            $item['footer'] = $settings->footer;
            $item['developed_by'] = $settings->developed_by;
            $item['default_language'] = $settings->default_language;
            $item['is_invoice_footer'] = $settings->is_invoice_footer;
            $item['invoice_footer'] = $settings->invoice_footer;
            // Invoice format for POS printing: 'thermal' (default) or 'a4'
            $item['invoice_format'] = in_array($settings->invoice_format, ['thermal', 'a4'], true)
                ? $settings->invoice_format
                : 'thermal';
            // A4 invoice logo dimensions (pixels). Defaults match historical CSS.
            $item['invoice_logo_width'] = (int) ($settings->invoice_logo_width ?: 180);
            $item['invoice_logo_height'] = (int) ($settings->invoice_logo_height ?: 60);
            // Sidebar brand: logo dimensions + show/hide toggles
            $item['sidebar_logo_width'] = (int) ($settings->sidebar_logo_width ?: 32);
            $item['sidebar_logo_height'] = (int) ($settings->sidebar_logo_height ?: 32);
            $item['sidebar_show_logo'] = (bool) ($settings->sidebar_show_logo ?? true);
            $item['hide_site_name'] = (bool) ($settings->hide_site_name ?? false);
            $item['quotation_with_stock'] = $settings->quotation_with_stock;
            $item['show_language'] = $settings->show_language;
            $item['dark_mode'] = (bool) ($settings->dark_mode ?? false);
            $item['rtl'] = (bool) ($settings->rtl ?? false);
            $item['point_to_amount_rate'] = $settings->point_to_amount_rate;
            $item['default_tax'] = $settings->default_tax;
            $item['default_dashboard_date_range'] = in_array($settings->default_dashboard_date_range ?? 'week', ['today', 'week', 'month'], true) ? $settings->default_dashboard_date_range : 'week';
            $item['dashboard_section_order'] = $settings->dashboard_section_order ?? null;
            $item['dashboard_font_size'] = $settings->dashboard_font_size ?? '';
            $item['dashboard_font_family'] = $settings->dashboard_font_family ?? '';
            $item['dashboard_grid_layout'] = $settings->dashboard_grid_layout ?? null;
            $item['sale_prefix'] = $settings->sale_prefix ?? '';
            $item['purchase_prefix'] = $settings->purchase_prefix ?? '';
            $item['quotation_prefix'] = $settings->quotation_prefix ?? '';
            $item['adjustment_prefix'] = $settings->adjustment_prefix ?? '';
            $item['transfer_prefix'] = $settings->transfer_prefix ?? '';
            $item['sale_return_prefix'] = $settings->sale_return_prefix ?? '';
            $item['purchase_return_prefix'] = $settings->purchase_return_prefix ?? '';
            // ZATCA settings
            $item['company_name_ar'] = $settings->company_name_ar;
            $item['vat_number'] = $settings->vat_number;
            $item['zatca_enabled'] = (bool) $settings->zatca_enabled;
            // Timezone from .env file - read directly from file to avoid cache issues
            $item['timezone'] = $this->getEnvValue('APP_TIMEZONE', 'UTC');
            // Debug Mode from .env file - read directly from file to avoid cache issues
            $item['debug_mode'] = $this->getEnvValue('APP_DEBUG', 'false') === 'true';
            // POS offline sync toggle (default true)
            $item['offline_sync_enabled'] = (bool) ($settings->offline_sync_enabled ?? true);
            // 3-decimal pricing toggle (default false)
            $item['enable_3_decimal_pricing'] = (bool) ($settings->enable_3_decimal_pricing ?? false);
            // Kitchen display toggle (default false)
            $item['enable_kitchen_display'] = (bool) ($settings->enable_kitchen_display ?? false);
            // Kitchen prep target minutes (null = no overdue escalation) + online auto-routing
            $item['kitchen_target_minutes'] = $settings->kitchen_target_minutes ?? null;
            $item['kitchen_auto_online_orders'] = (bool) ($settings->kitchen_auto_online_orders ?? false);
            // Show product GTIN/barcode field on the product create form (default true)
            $item['show_product_gtin'] = (bool) ($settings->show_product_gtin ?? true);
            // Resize uploaded product images on save (default on, 800px box) — must
            // be returned here or the form loads it undefined and every save
            // silently turns resizing off.
            $item['product_image_resize'] = (bool) ($settings->product_image_resize ?? true);
            $item['product_image_max_size'] = (int) ($settings->product_image_max_size ?? 800);
            // Serial / IMEI tracking master switch (default false)
            $item['show_serial_tracking'] = (bool) ($settings->show_serial_tracking ?? false);
            // Multi-pack selling toggle (default false)
            $item['enable_multi_pack_selling'] = (bool) ($settings->enable_multi_pack_selling ?? false);
            // Wholesale Pricing by Quantity toggle (default false) — must be
            // returned here or the System Settings form loads it undefined and
            // every save silently turns the feature off.
            $item['enable_wholesale_pricing'] = (bool) ($settings->enable_wholesale_pricing ?? false);
            // Multi-Currency toggle (default false) — must be returned here or
            // the System Settings form loads it undefined and every save
            // silently turns the feature off.
            $item['enable_multi_currency'] = (bool) ($settings->enable_multi_currency ?? false);
            // Change Salesperson During Checkout toggle (default false) — must be
            // returned here or the System Settings form loads it undefined and
            // every save silently turns the feature off.
            $item['enable_pos_salesperson_switch'] = (bool) ($settings->enable_pos_salesperson_switch ?? false);
            // Session timeout: minutes before automatic logout (null = never)
            $item['session_timeout_minutes'] = $settings->session_timeout_minutes ?? null;
            $item['allow_overselling'] = (bool) ($settings->allow_overselling ?? false);
            // Vehicle Fitment master switch (default false) — must be returned
            // here or the System Settings form loads it as undefined and every
            // save silently turns the module off.
            $item['vehicle_fitment_enabled'] = (bool) ($settings->vehicle_fitment_enabled ?? false);
            // Accounting V2 auto journal entries (null falls back to config/env)
            $item['auto_journal_enabled'] = (bool) ($settings->auto_journal_enabled ?? config('accounting_v2.auto_generate_journals', false));
            $item['date_format'] = $settings->date_format ?? 'YYYY-MM-DD';
            // Optional price format for frontend display (used by POS)
            $item['price_format'] = $settings->price_format;
            // Cloud backup settings
            $item['backup_cloud_enabled'] = (bool) ($settings->backup_cloud_enabled ?? false);
            $item['backup_keep_local'] = (bool) ($settings->backup_keep_local ?? true);
            $item['backup_cloud_provider'] = $settings->backup_cloud_provider ?? null;
            $item['backup_cloud_path'] = $settings->backup_cloud_path ?? null;
            // S3-compatible settings
            $item['backup_s3_bucket'] = $settings->backup_s3_bucket ?? null;
            $item['backup_s3_region'] = $settings->backup_s3_region ?? null;
            $item['backup_s3_access_key'] = $settings->backup_s3_access_key ?? null;
            // Only include secret key if include_secrets is true (for security)
            $includeSecrets = $request->has('include_secrets') && ($request->input('include_secrets') == '1' || $request->input('include_secrets') == 'true' || $request->input('include_secrets') === 1 || $request->input('include_secrets') === true);
            if ($includeSecrets) {
                $item['backup_s3_secret_key'] = $settings->backup_s3_secret_key ?? null;
            } else {
                $item['backup_s3_has_secret_key'] = !empty($settings->backup_s3_secret_key);
            }
            $item['backup_s3_endpoint'] = $settings->backup_s3_endpoint ?? null;
            $item['backup_s3_path_style'] = (bool) ($settings->backup_s3_path_style ?? false);
            // Google Drive settings
            $item['backup_gdrive_folder_id'] = $settings->backup_gdrive_folder_id ?? null;
            if ($includeSecrets) {
                $item['backup_gdrive_access_token'] = $settings->backup_gdrive_access_token ?? null;
                $item['backup_gdrive_refresh_token'] = $settings->backup_gdrive_refresh_token ?? null;
                $item['backup_gdrive_client_secret'] = $settings->backup_gdrive_client_secret ?? null;
            } else {
                $item['backup_gdrive_has_access_token'] = !empty($settings->backup_gdrive_access_token);
                $item['backup_gdrive_has_refresh_token'] = !empty($settings->backup_gdrive_refresh_token);
                $item['backup_gdrive_has_client_secret'] = !empty($settings->backup_gdrive_client_secret);
            }
            $item['backup_gdrive_client_id'] = $settings->backup_gdrive_client_id ?? null;
            // Dropbox settings
            $item['backup_dropbox_path'] = $settings->backup_dropbox_path ?? null;
            if ($includeSecrets) {
                $item['backup_dropbox_access_token'] = $settings->backup_dropbox_access_token ?? null;
            } else {
                $item['backup_dropbox_has_access_token'] = !empty($settings->backup_dropbox_access_token);
            }

            // Global export defaults (JSON string; the SPA parses and merges
            // with its built-in defaults)
            $item['export_settings'] = $settings->export_settings ?? null;

            // Pharmacy mode (batch & expiry tracking) — opt-in, only present once migration has run
            $item['pharmacy_mode_supported'] = \Schema::hasColumn('settings', 'pharmacy_mode');
            $item['pharmacy_mode'] = (bool) ($settings->pharmacy_mode ?? false);
            $item['expiry_warning_days'] = (int) ($settings->expiry_warning_days ?? 90);
            $item['block_expired_sale'] = (bool) ($settings->block_expired_sale ?? false);
            $item['print_expiry_on_receipt'] = (bool) ($settings->print_expiry_on_receipt ?? false);

            $zones_array = [];
            $timestamp = time();
            foreach (timezone_identifiers_list() as $key => $zone) {
                date_default_timezone_set($zone);
                $zones_array[$key]['zone'] = $zone;
                $zones_array[$key]['diff_from_GMT'] = 'UTC/GMT '.date('P', $timestamp);
                $zones_array[$key]['label'] = $zones_array[$key]['diff_from_GMT'].' - '.$zones_array[$key]['zone'];
            }

            $Currencies = Currency::where('deleted_at', null)->get(['id', 'name', 'code', 'symbol']);
            $clients = client::where('deleted_at', '=', null)->get(['id', 'name']);
            $sms_gateway = sms_gateway::where('deleted_at', '=', null)->get(['id', 'title']);

            // get warehouses assigned to user
            $user_auth = auth()->user();
            if ($user_auth->is_all_warehouses) {
                $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
            } else {
                $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)->pluck('warehouse_id')->toArray();
                $warehouses = Warehouse::where('deleted_at', '=', null)->whereIn('id', $warehouses_id)->get(['id', 'name']);
            }

            $languages = Language::where('is_active', true)->get(['name', 'locale']);
            $accounts = Account::whereNull('deleted_at')->get(['id', 'account_name', 'account_num']);
            // Default-payment-method picker: only active methods are offerable.
            $payment_methods = PaymentMethod::active()->whereNull('deleted_at')->get(['id', 'name']);

            return response()->json([
                'settings' => $item,
                'currencies' => $Currencies,
                'clients' => $clients,
                'warehouses' => $warehouses,
                'sms_gateway' => $sms_gateway,
                'accounts' => $accounts,
                'payment_methods' => $payment_methods,
                'zones_array' => $zones_array,
                'languages' => $languages,
            ], 200);
        } else {
            return response()->json(['statut' => 'error'], 500);
        }
    }

    /**
     * Update only the dashboard grid layout (drag/resize positions).
     */
    public function updateDashboardGridLayout(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', Setting::class);
        $setting = Setting::whereNull('deleted_at')->first();
        if (!$setting) {
            return response()->json(['message' => 'Settings not found'], 404);
        }
        $layout = $request->input('dashboard_grid_layout');
        if ($layout !== null && !is_string($layout)) {
            $layout = json_encode($layout);
        }
        $setting->dashboard_grid_layout = $layout;
        $setting->save();
        return response()->json(['success' => true, 'dashboard_grid_layout' => $setting->dashboard_grid_layout]);
    }

    // -------------- Sidebar menu order (drag-and-drop manager) ---------------\\
    // The order is a tree of menu-entry ids ([{id, children:[...]}]) matching
    // resources/src/config/menu.js. Null = default structure.

    public function getSidebarMenuOrder(Request $request)
    {
        $setting = Setting::whereNull('deleted_at')->first();
        $order = $setting?->sidebar_menu_order ? json_decode($setting->sidebar_menu_order, true) : null;

        return response()->json(['sidebar_menu_order' => $order], 200);
    }

    public function updateSidebarMenuOrder(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', Setting::class);
        $setting = Setting::whereNull('deleted_at')->first();
        if (! $setting) {
            return response()->json(['message' => 'Settings not found'], 404);
        }

        $request->validate(['order' => 'required|array']);

        $setting->sidebar_menu_order = json_encode($request->input('order'));
        $setting->save();

        return response()->json(['success' => true], 200);
    }

    public function resetSidebarMenuOrder(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', Setting::class);
        $setting = Setting::whereNull('deleted_at')->first();
        if (! $setting) {
            return response()->json(['message' => 'Settings not found'], 404);
        }

        $setting->sidebar_menu_order = null;
        $setting->save();

        return response()->json(['success' => true], 200);
    }

    // ----------------------- Module toggles (System Settings) ----------------\\
    // module_flags is a JSON map of module key => bool ({"hospital": false}).
    // Null / missing key = enabled. Keys match resources/src/config/modules.js.

    public function getModuleFlags(Request $request)
    {
        $setting = Setting::whereNull('deleted_at')->first();
        $flags = $setting?->module_flags ? json_decode($setting->module_flags, true) : null;

        return response()->json(['module_flags' => is_array($flags) ? $flags : null], 200);
    }

    public function updateModuleFlags(Request $request)
    {
        // business_modules OR setting_system (see SettingPolicy::business_modules)
        $this->authorizeForUser($request->user('api'), 'business_modules', Setting::class);
        $setting = Setting::whereNull('deleted_at')->first();
        if (! $setting) {
            return response()->json(['message' => 'Settings not found'], 404);
        }

        $request->validate(['flags' => 'required|array']);

        // Keep only key => bool pairs so a malformed payload can't be stored.
        $flags = [];
        foreach ($request->input('flags') as $key => $value) {
            if (is_string($key) && $key !== '') {
                $flags[$key] = (bool) $value;
            }
        }

        $setting->module_flags = json_encode($flags);
        $setting->save();

        return response()->json(['success' => true, 'module_flags' => $flags], 200);
    }

    public function resetModuleFlags(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'business_modules', Setting::class);
        $setting = Setting::whereNull('deleted_at')->first();
        if (! $setting) {
            return response()->json(['message' => 'Settings not found'], 404);
        }

        $setting->module_flags = null;
        $setting->save();

        return response()->json(['success' => true], 200);
    }

    // ----------------- Feature toggles (Settings → Modules → Features tab) -----------------\\
    // The same optional-capability switches as System Settings → Features,
    // exposed as a small standalone endpoint so the Modules page can save them
    // without posting the full settings row (the main update() clobbers any
    // unguarded field that isn't sent).

    public function getFeatureSettings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'business_modules', Setting::class);
        $settings = Setting::whereNull('deleted_at')->first();

        return response()->json(['features' => [
            'enable_3_decimal_pricing' => (bool) ($settings->enable_3_decimal_pricing ?? false),
            'enable_kitchen_display' => (bool) ($settings->enable_kitchen_display ?? false),
            'kitchen_target_minutes' => $settings->kitchen_target_minutes ?? null,
            'kitchen_auto_online_orders' => (bool) ($settings->kitchen_auto_online_orders ?? false),
            'show_product_gtin' => (bool) ($settings->show_product_gtin ?? true),
            'product_image_resize' => (bool) ($settings->product_image_resize ?? true),
            'product_image_max_size' => (int) ($settings->product_image_max_size ?? 800),
            'show_serial_tracking' => (bool) ($settings->show_serial_tracking ?? false),
            'enable_multi_pack_selling' => (bool) ($settings->enable_multi_pack_selling ?? false),
            'enable_wholesale_pricing' => (bool) ($settings->enable_wholesale_pricing ?? false),
            'enable_multi_currency' => (bool) ($settings->enable_multi_currency ?? false),
            'enable_pos_salesperson_switch' => (bool) ($settings->enable_pos_salesperson_switch ?? false),
            'vehicle_fitment_enabled' => (bool) ($settings->vehicle_fitment_enabled ?? false),
            'auto_journal_enabled' => (bool) ($settings->auto_journal_enabled ?? config('accounting_v2.auto_generate_journals', false)),
            'allow_overselling' => (bool) ($settings->allow_overselling ?? false),
        ]], 200);
    }

    public function updateFeatureSettings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'business_modules', Setting::class);
        $setting = Setting::whereNull('deleted_at')->first();
        if (! $setting) {
            return response()->json(['message' => 'Settings not found'], 404);
        }

        $boolFields = [
            'enable_3_decimal_pricing', 'enable_kitchen_display', 'kitchen_auto_online_orders', 'show_product_gtin',
            'product_image_resize', 'show_serial_tracking', 'enable_multi_pack_selling',
            'enable_wholesale_pricing', 'enable_multi_currency', 'enable_pos_salesperson_switch',
            'vehicle_fitment_enabled', 'auto_journal_enabled', 'allow_overselling',
        ];

        // Only fields present in the payload are written — anything omitted
        // keeps its stored value, mirroring the has() guards in update().
        $data = [];
        foreach ($boolFields as $field) {
            if ($request->has($field)) {
                $v = $request[$field];
                $data[$field] = ($v == '1' || $v == 'true' || $v === 1 || $v === true) ? 1 : 0;
            }
        }
        if ($request->has('product_image_max_size')) {
            $data['product_image_max_size'] = max(50, min(5000, (int) $request['product_image_max_size']));
        }
        if ($request->has('kitchen_target_minutes')) {
            $v = $request['kitchen_target_minutes'];
            $data['kitchen_target_minutes'] = ($v === '' || $v === null || (int) $v <= 0)
                ? null
                : min(1440, (int) $v);
        }

        if ($data) {
            Setting::whereId($setting->id)->update($data);
        }

        return response()->json(['success' => true], 200);
    }

    // ----------------- Barcode label print defaults (Print Barcode page) -----------------\\
    // barcode_label_settings is a JSON blob of the label layout defaults
    // (template, element toggles, barcode height, font size, bold, paper size,
    // custom sticker dimensions, auto print). Guarded by the same `barcode`
    // ability as the Print Barcode page so label users can save their defaults.

    public function getBarcodeLabelSettings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'barcode', \App\Models\Product::class);
        $setting = Setting::whereNull('deleted_at')->first();
        $labelSettings = $setting?->barcode_label_settings ? json_decode($setting->barcode_label_settings, true) : null;

        return response()->json(['barcode_label_settings' => is_array($labelSettings) ? $labelSettings : null], 200);
    }

    public function updateBarcodeLabelSettings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'barcode', \App\Models\Product::class);
        $setting = Setting::whereNull('deleted_at')->first();
        if (! $setting) {
            return response()->json(['message' => 'Settings not found'], 404);
        }

        $request->validate(['settings' => 'required|array']);
        $input = $request->input('settings');

        // Whitelist + coerce so a malformed payload can't be stored.
        $labelSettings = [
            'template' => in_array($input['template'] ?? '', ['template1', 'template2', 'template3', 'template4', 'template5', 'custom'], true)
                ? $input['template'] : 'template1',
            'show_name' => (bool) ($input['show_name'] ?? true),
            'show_price' => (bool) ($input['show_price'] ?? true),
            'show_barcode' => (bool) ($input['show_barcode'] ?? true),
            'show_barcode_number' => (bool) ($input['show_barcode_number'] ?? true),
            'barcode_height' => max(10, min(100, (int) ($input['barcode_height'] ?? 28))),
            'font_size' => max(6, min(30, (int) ($input['font_size'] ?? 10))),
            'number_font_size' => max(6, min(30, (int) ($input['number_font_size'] ?? 10))),
            'bold_font' => (bool) ($input['bold_font'] ?? true),
            'auto_print' => (bool) ($input['auto_print'] ?? true),
        ];
        // Must match the FONT_FAMILIES list on the Print Barcode page.
        $allowedFontFamilies = [
            'Arial, Helvetica, sans-serif',
            'Verdana, Geneva, sans-serif',
            'Tahoma, Geneva, sans-serif',
            "'Trebuchet MS', Helvetica, sans-serif",
            "'Times New Roman', Times, serif",
            'Georgia, serif',
            'Garamond, serif',
            "'Courier New', Courier, monospace",
            "'Lucida Console', Monaco, monospace",
            'Impact, Charcoal, sans-serif',
        ];
        $labelSettings['font_family'] = in_array($input['font_family'] ?? '', $allowedFontFamilies, true)
            ? $input['font_family'] : 'Arial, Helvetica, sans-serif';
        if (! empty($input['paper_size']) && is_string($input['paper_size'])) {
            $labelSettings['paper_size'] = $input['paper_size'];
        }
        if (! empty($input['custom_sticker_width']) && is_numeric($input['custom_sticker_width'])) {
            $labelSettings['custom_sticker_width'] = (float) $input['custom_sticker_width'];
        }
        if (! empty($input['custom_sticker_height']) && is_numeric($input['custom_sticker_height'])) {
            $labelSettings['custom_sticker_height'] = (float) $input['custom_sticker_height'];
        }

        $setting->barcode_label_settings = json_encode($labelSettings);
        $setting->save();

        return response()->json(['success' => true, 'barcode_label_settings' => $labelSettings], 200);
    }

    /**
     * Print a sample label so the user can verify printer config from the
     * settings page. Accepts the (possibly unsaved) form values as overrides
     * on top of the stored pos_settings row.
     */
    /** The sample label printed by Test Print, in both render modes. */
    const LABEL_TEST_SAMPLE = [
        'name' => 'Stocky Test Label',
        'barcode' => '628112345678',
        'Type_barcode' => 'CODE128',
        'Net_price' => '49.99',
        'qte' => 1,
    ];

    /**
     * The stored label design, filled in with the defaults and the sticker
     * size the test label is printed at. In raster mode the settings page has
     * to draw the sample itself, so it needs exactly this — hence the
     * companion endpoint below rather than the `barcode` permissioned
     * getBarcodeLabelSettings.
     */
    private function labelTestDesign(Request $request, ?\App\Models\PosSetting $posSetting): array
    {
        $setting = Setting::whereNull('deleted_at')->first();
        $design = $setting?->barcode_label_settings ? json_decode($setting->barcode_label_settings, true) : [];
        $design = is_array($design) ? $design : [];
        $design += [
            'show_name' => true, 'show_price' => true, 'show_barcode' => true,
            'show_barcode_number' => true, 'barcode_height' => 28,
            'font_size' => 10, 'number_font_size' => 10, 'bold_font' => true,
        ];
        // Physical sticker size: unsaved form value > saved printer setting >
        // the label design's sticker size (legacy fallback).
        $design['width_mm'] = (float) ($request->input('width_mm')
            ?: ($posSetting->label_printer_width_mm ?? null)
            ?: ($design['custom_sticker_width'] ?? 50));
        $design['height_mm'] = (float) ($request->input('height_mm')
            ?: ($posSetting->label_printer_height_mm ?? null)
            ?: ($design['custom_sticker_height'] ?? 30));
        // Symbol for display; ASCII code ("AED", "USD") as the fallback the
        // printer-font mode uses when the symbol has no ASCII form (د.إ, ₹…).
        $currency = optional($setting?->Currency);
        $design['currency'] = (string) ($currency->symbol ?? '') !== ''
            ? $currency->symbol : (string) ($currency->code ?? '');
        $design['currency_ascii'] = (string) ($currency->code ?? '');
        $design['printer_dpi'] = ((int) ($request->input('printer_dpi')
            ?: ($posSetting->label_printer_dpi ?? 203))) === 300 ? 300 : 203;
        $design['offset_x_mm'] = (float) ($request->input('offset_x_mm')
            ?? ($posSetting->label_printer_offset_x_mm ?? 0));
        $design['offset_y_mm'] = (float) ($request->input('offset_y_mm')
            ?? ($posSetting->label_printer_offset_y_mm ?? 0));
        // Test prints carry a hairline frame at the label edges so alignment
        // problems (offset, drift, wrong size) are visible at a glance.
        // Product labels never set this.
        $design['test_frame'] = true;

        return $design;
    }

    /** Design + sample label the settings page renders in raster test prints. */
    public function labelPrinterTestDesign(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'pos_settings', Setting::class);
        $posSetting = \App\Models\PosSetting::where('deleted_at', '=', null)->first();

        return response()->json([
            'design' => $this->labelTestDesign($request, $posSetting),
            'label' => self::LABEL_TEST_SAMPLE,
        ]);
    }

    public function testLabelPrinter(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'pos_settings', Setting::class);

        $posSetting = \App\Models\PosSetting::where('deleted_at', '=', null)->first();
        if (! $posSetting) {
            return response()->json(['success' => false, 'message' => 'POS settings not found.'], 404);
        }

        foreach (['label_printer_connection', 'label_printer_name', 'label_printer_ip',
            'label_printer_port', 'label_printer_gap_mm', 'label_printer_density',
            'label_printer_speed', 'label_printer_direction', 'label_printer_render_mode',
            'label_printer_width_mm', 'label_printer_height_mm', 'label_printer_dpi',
            'label_printer_tear', 'label_printer_offset_x_mm', 'label_printer_offset_y_mm'] as $key) {
            if ($request->has($key)) {
                $posSetting->{$key} = $request->input($key);
            }
        }

        $design = $this->labelTestDesign($request, $posSetting);

        // Raster mode: the page rendered the sample label to an image itself.
        if ($request->has('rasters')) {
            $request->validate([
                'rasters' => 'required|array|min:1|max:10',
                'rasters.*.data' => 'required|string',
                'rasters.*.width_bytes' => 'required|integer|min:1',
                'rasters.*.height' => 'required|integer|min:1',
            ]);
            try {
                $payload = (new \App\Services\TsplRasterService())
                    ->build($request->input('rasters'), $design, $posSetting);
            } catch (\InvalidArgumentException $e) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }
        } else {
            $payload = (new \App\Services\TsplLabelService())
                ->build([self::LABEL_TEST_SAMPLE], $design, $posSetting);
        }

        // QZ Tray: the browser delivers the bytes to a printer on the client
        // machine — hand the payload back instead of printing server-side.
        // Base64 because a raster payload is binary and would not survive JSON.
        if (\App\Services\LabelPrinterTransport::isClientSide($posSetting)) {
            return response()->json([
                'success' => true,
                'qz' => true,
                'printer' => (string) ($posSetting->label_printer_name ?? ''),
                'payload_base64' => base64_encode($payload),
            ]);
        }

        $result = (new \App\Services\LabelPrinterTransport())->send($posSetting, $payload);

        return response()->json(
            ['success' => $result['ok'], 'message' => $result['message']],
            $result['ok'] ? 200 : 502
        );
    }

    /**
     * Print two frames — one computed at 203 dpi, one at 300 — each labeled
     * with its number. The frame that exactly matches the sticker edges IS
     * the printhead's real resolution: the user reads the answer off the
     * label instead of anyone guessing scale factors from photos.
     */
    public function dpiTestLabelPrinter(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'pos_settings', Setting::class);

        $posSetting = \App\Models\PosSetting::where('deleted_at', '=', null)->first();
        if (! $posSetting) {
            return response()->json(['success' => false, 'message' => 'POS settings not found.'], 404);
        }

        foreach (['label_printer_connection', 'label_printer_name', 'label_printer_ip',
            'label_printer_port', 'label_printer_gap_mm', 'label_printer_direction',
            'label_printer_width_mm', 'label_printer_height_mm'] as $key) {
            if ($request->has($key)) {
                $posSetting->{$key} = $request->input($key);
            }
        }

        $width = max(20, min(200, (float) ($posSetting->label_printer_width_mm ?: 50)));
        $height = max(10, min(200, (float) ($posSetting->label_printer_height_mm ?: 30)));
        $gap = max(0, min(10, (float) ($posSetting->label_printer_gap_mm ?? 2)));
        $direction = ((int) ($posSetting->label_printer_direction ?? 0)) === 1 ? 1 : 0;

        $payload = '';
        foreach ([203 => 8, 300 => 12] as $dpi => $dotsPerMm) {
            // Inset 2 mm: die-cut stickers are narrower than the liner and
            // have rounded corners, so a border at the extreme edges prints
            // on the liner where thermal ink is barely visible.
            $inset = 2 * $dotsPerMm;
            $frameW = (int) round($width * $dotsPerMm) - $inset;
            $frameH = (int) round($height * $dotsPerMm) - $inset;
            $margin = 4 * $dotsPerMm;
            $payload .= "SIZE {$width} mm,{$height} mm\r\n"
                . "GAP {$gap} mm,0 mm\r\n"
                . "DIRECTION {$direction}\r\n"
                . "REFERENCE 0,0\r\nSHIFT 0\r\nOFFSET 0 mm\r\n"
                . "SET TEAR OFF\r\n"
                . "CLS\r\n"
                . "BOX {$inset},{$inset},{$frameW},{$frameH},4\r\n"
                . sprintf("TEXT %d,%d,\"4\",0,1,1,\"%d\"\r\n", $margin, $margin, $dpi)
                . "PRINT 1,1\r\n";
        }

        if (\App\Services\LabelPrinterTransport::isClientSide($posSetting)) {
            return response()->json([
                'success' => true,
                'qz' => true,
                'printer' => (string) ($posSetting->label_printer_name ?? ''),
                'payload_base64' => base64_encode($payload),
            ]);
        }

        $result = (new \App\Services\LabelPrinterTransport())->send($posSetting, $payload);

        return response()->json(
            ['success' => $result['ok'], 'message' => $result['message']],
            $result['ok'] ? 200 : 502
        );
    }

    /**
     * Auto-calibrate label gap sensing. Sends the configured label size plus
     * TSPL GAPDETECT: the printer feeds a few labels, measures the real
     * sticker/gap lengths and stores them — the same calibration the vendor
     * print tools perform. Run this once after loading a new roll, or when
     * label positions drift.
     */
    public function calibrateLabelPrinter(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'pos_settings', Setting::class);

        $posSetting = \App\Models\PosSetting::where('deleted_at', '=', null)->first();
        if (! $posSetting) {
            return response()->json(['success' => false, 'message' => 'POS settings not found.'], 404);
        }

        foreach (['label_printer_connection', 'label_printer_name', 'label_printer_ip',
            'label_printer_port', 'label_printer_gap_mm',
            'label_printer_width_mm', 'label_printer_height_mm'] as $key) {
            if ($request->has($key)) {
                $posSetting->{$key} = $request->input($key);
            }
        }

        $width = max(20, min(200, (float) ($posSetting->label_printer_width_mm ?: 50)));
        $height = max(10, min(200, (float) ($posSetting->label_printer_height_mm ?: 30)));
        $gap = max(0, min(10, (float) ($posSetting->label_printer_gap_mm ?? 2)));

        $payload = "SIZE {$width} mm,{$height} mm\r\n"
            . "GAP {$gap} mm,0 mm\r\n"
            . "SET TEAR OFF\r\n"
            . "GAPDETECT\r\n";

        if (\App\Services\LabelPrinterTransport::isClientSide($posSetting)) {
            return response()->json([
                'success' => true,
                'qz' => true,
                'printer' => (string) ($posSetting->label_printer_name ?? ''),
                'payload_base64' => base64_encode($payload),
            ]);
        }

        $result = (new \App\Services\LabelPrinterTransport())->send($posSetting, $payload);

        return response()->json(
            ['success' => $result['ok'], 'message' => $result['message']],
            $result['ok'] ? 200 : 502
        );
    }

    /**
     * Public certificate used to sign QZ Tray requests. The qz-tray JS lib
     * fetches this on connect; the settings page also offers it as a download
     * so the user can install it as override.crt in QZ Tray (silent printing).
     * Returns certificate:null when the server cannot generate one — the
     * frontend then falls back to unsigned (per-session Allow prompt).
     */
    public function qzCertificate(Request $request)
    {
        try {
            $certificate = (new \App\Services\QzCertificateService())->certificate();
        } catch (\Throwable $e) {
            return response()->json(['certificate' => null, 'message' => $e->getMessage()]);
        }

        return response()->json(['certificate' => $certificate]);
    }

    /** Sign a QZ Tray challenge string (called by the qz-tray JS lib per print). */
    public function qzSign(Request $request)
    {
        $request->validate(['request' => 'required|string|max:10000']);

        try {
            $signature = (new \App\Services\QzCertificateService())->sign($request->input('request'));
        } catch (\Throwable $e) {
            return response()->json(['signature' => null, 'message' => $e->getMessage()]);
        }

        return response()->json(['signature' => $signature]);
    }

    public function getSettings(Request $request)
    {

        $settings = Setting::where('deleted_at', '=', null)->first();
        if ($settings) {
            if ($settings->currency_id) {
                if (Currency::where('id', $settings->currency_id)->where('deleted_at', '=', null)->first()) {
                    $item['currency_id'] = $settings->currency_id;
                } else {
                    $item['currency_id'] = '';
                }
            } else {
                $item['currency_id'] = '';
            }

            if ($settings->client_id) {
                if (Client::where('id', $settings->client_id)->where('deleted_at', '=', null)->first()) {
                    $item['client_id'] = $settings->client_id;
                } else {
                    $item['client_id'] = '';
                }
            } else {
                $item['client_id'] = '';
            }

            if ($settings->warehouse_id) {
                if (Warehouse::where('id', $settings->warehouse_id)->where('deleted_at', '=', null)->first()) {
                    $item['warehouse_id'] = $settings->warehouse_id;
                } else {
                    $item['warehouse_id'] = '';
                }
            } else {
                $item['warehouse_id'] = '';
            }

            if ($settings->sms_gateway) {
                if (sms_gateway::where('id', $settings->sms_gateway)->where('deleted_at', '=', null)->first()) {
                    $item['sms_gateway'] = $settings->sms_gateway;
                } else {
                    $item['sms_gateway'] = '';
                }
            } else {
                $item['sms_gateway'] = '';
            }

            $item['default_account_id'] = '';
            if (! empty($settings->default_account_id) && Account::where('id', $settings->default_account_id)->whereNull('deleted_at')->first()) {
                $item['default_account_id'] = $settings->default_account_id;
            }
            $item['default_payment_method_id'] = '';
            if (! empty($settings->default_payment_method_id) && PaymentMethod::active()->where('id', $settings->default_payment_method_id)->whereNull('deleted_at')->first()) {
                $item['default_payment_method_id'] = $settings->default_payment_method_id;
            }

            $item['id'] = $settings->id;
            $item['email'] = $settings->email;
            $item['CompanyName'] = $settings->CompanyName;
            $item['CompanyPhone'] = $settings->CompanyPhone;
            $item['CompanyAdress'] = $settings->CompanyAdress;
            $item['logo'] = $settings->logo;
            $item['footer'] = $settings->footer;
            $item['developed_by'] = $settings->developed_by;
            $item['default_language'] = $settings->default_language;
            $item['is_invoice_footer'] = $settings->is_invoice_footer;
            $item['invoice_footer'] = $settings->invoice_footer;
            // Invoice format for POS printing: 'thermal' (default) or 'a4'
            $item['invoice_format'] = in_array($settings->invoice_format, ['thermal', 'a4'], true)
                ? $settings->invoice_format
                : 'thermal';
            // A4 invoice logo dimensions (pixels). Defaults match historical CSS.
            $item['invoice_logo_width'] = (int) ($settings->invoice_logo_width ?: 180);
            $item['invoice_logo_height'] = (int) ($settings->invoice_logo_height ?: 60);
            // Sidebar brand: logo dimensions + show/hide toggles
            $item['sidebar_logo_width'] = (int) ($settings->sidebar_logo_width ?: 32);
            $item['sidebar_logo_height'] = (int) ($settings->sidebar_logo_height ?: 32);
            $item['sidebar_show_logo'] = (bool) ($settings->sidebar_show_logo ?? true);
            $item['hide_site_name'] = (bool) ($settings->hide_site_name ?? false);
            $item['quotation_with_stock'] = $settings->quotation_with_stock;
            $item['show_language'] = $settings->show_language;
            $item['dark_mode'] = (bool) ($settings->dark_mode ?? false);
            $item['rtl'] = (bool) ($settings->rtl ?? false);
            $item['point_to_amount_rate'] = $settings->point_to_amount_rate;
            $item['default_tax'] = $settings->default_tax;
            $item['default_dashboard_date_range'] = in_array($settings->default_dashboard_date_range ?? 'week', ['today', 'week', 'month'], true) ? $settings->default_dashboard_date_range : 'week';
            $item['dashboard_section_order'] = $settings->dashboard_section_order ?? null;
            $item['dashboard_font_size'] = $settings->dashboard_font_size ?? '';
            $item['dashboard_font_family'] = $settings->dashboard_font_family ?? '';
            $item['dashboard_grid_layout'] = $settings->dashboard_grid_layout ?? null;
            $item['sale_prefix'] = $settings->sale_prefix ?? '';
            $item['purchase_prefix'] = $settings->purchase_prefix ?? '';
            $item['quotation_prefix'] = $settings->quotation_prefix ?? '';
            $item['adjustment_prefix'] = $settings->adjustment_prefix ?? '';
            $item['transfer_prefix'] = $settings->transfer_prefix ?? '';
            $item['sale_return_prefix'] = $settings->sale_return_prefix ?? '';
            $item['purchase_return_prefix'] = $settings->purchase_return_prefix ?? '';
            // ZATCA settings
            $item['company_name_ar'] = $settings->company_name_ar;
            $item['vat_number'] = $settings->vat_number;
            $item['zatca_enabled'] = (bool) $settings->zatca_enabled;
            // Timezone from .env file - read directly from file to avoid cache issues
            $item['timezone'] = $this->getEnvValue('APP_TIMEZONE', 'UTC');
            // Debug Mode from .env file - read directly from file to avoid cache issues
            $item['debug_mode'] = $this->getEnvValue('APP_DEBUG', 'false') === 'true';
            // POS offline sync toggle (default true)
            $item['offline_sync_enabled'] = (bool) ($settings->offline_sync_enabled ?? true);
            // 3-decimal pricing toggle (default false)
            $item['enable_3_decimal_pricing'] = (bool) ($settings->enable_3_decimal_pricing ?? false);
            // Kitchen display toggle (default false)
            $item['enable_kitchen_display'] = (bool) ($settings->enable_kitchen_display ?? false);
            // Kitchen prep target minutes (null = no overdue escalation) + online auto-routing
            $item['kitchen_target_minutes'] = $settings->kitchen_target_minutes ?? null;
            $item['kitchen_auto_online_orders'] = (bool) ($settings->kitchen_auto_online_orders ?? false);
            // Show product GTIN/barcode field on the product create form (default true)
            $item['show_product_gtin'] = (bool) ($settings->show_product_gtin ?? true);
            // Resize uploaded product images on save (default on, 800px box) — must
            // be returned here or the form loads it undefined and every save
            // silently turns resizing off.
            $item['product_image_resize'] = (bool) ($settings->product_image_resize ?? true);
            $item['product_image_max_size'] = (int) ($settings->product_image_max_size ?? 800);
            // Serial / IMEI tracking master switch (default false)
            $item['show_serial_tracking'] = (bool) ($settings->show_serial_tracking ?? false);
            // Multi-pack selling toggle (default false)
            $item['enable_multi_pack_selling'] = (bool) ($settings->enable_multi_pack_selling ?? false);
            // Wholesale Pricing by Quantity toggle (default false) — must be
            // returned here or the System Settings form loads it undefined and
            // every save silently turns the feature off.
            $item['enable_wholesale_pricing'] = (bool) ($settings->enable_wholesale_pricing ?? false);
            // Multi-Currency toggle (default false) — must be returned here or
            // the System Settings form loads it undefined and every save
            // silently turns the feature off.
            $item['enable_multi_currency'] = (bool) ($settings->enable_multi_currency ?? false);
            // Change Salesperson During Checkout toggle (default false) — must be
            // returned here or the System Settings form loads it undefined and
            // every save silently turns the feature off.
            $item['enable_pos_salesperson_switch'] = (bool) ($settings->enable_pos_salesperson_switch ?? false);
            // Session timeout: minutes before automatic logout (null = never)
            $item['session_timeout_minutes'] = $settings->session_timeout_minutes ?? null;
            $item['allow_overselling'] = (bool) ($settings->allow_overselling ?? false);
            // Vehicle Fitment master switch (default false) — must be returned
            // here or the System Settings form loads it as undefined and every
            // save silently turns the module off.
            $item['vehicle_fitment_enabled'] = (bool) ($settings->vehicle_fitment_enabled ?? false);
            // Accounting V2 auto journal entries (null falls back to config/env)
            $item['auto_journal_enabled'] = (bool) ($settings->auto_journal_enabled ?? config('accounting_v2.auto_generate_journals', false));
            $item['date_format'] = $settings->date_format ?? 'YYYY-MM-DD';
            // Optional price format for frontend display (used by POS)
            $item['price_format'] = $settings->price_format;
            // Cloud backup settings
            $item['backup_cloud_enabled'] = (bool) ($settings->backup_cloud_enabled ?? false);
            $item['backup_keep_local'] = (bool) ($settings->backup_keep_local ?? true);
            $item['backup_cloud_provider'] = $settings->backup_cloud_provider ?? null;
            $item['backup_cloud_path'] = $settings->backup_cloud_path ?? null;
            // S3-compatible settings
            $item['backup_s3_bucket'] = $settings->backup_s3_bucket ?? null;
            $item['backup_s3_region'] = $settings->backup_s3_region ?? null;
            $item['backup_s3_access_key'] = $settings->backup_s3_access_key ?? null;
            // Only include secret key if include_secrets is true (for security)
            $includeSecrets = $request->has('include_secrets') && ($request->input('include_secrets') == '1' || $request->input('include_secrets') == 'true' || $request->input('include_secrets') === 1 || $request->input('include_secrets') === true);
            if ($includeSecrets) {
                $item['backup_s3_secret_key'] = $settings->backup_s3_secret_key ?? null;
            } else {
                $item['backup_s3_has_secret_key'] = !empty($settings->backup_s3_secret_key);
            }
            $item['backup_s3_endpoint'] = $settings->backup_s3_endpoint ?? null;
            $item['backup_s3_path_style'] = (bool) ($settings->backup_s3_path_style ?? false);
            // Google Drive settings
            $item['backup_gdrive_folder_id'] = $settings->backup_gdrive_folder_id ?? null;
            if ($includeSecrets) {
                $item['backup_gdrive_access_token'] = $settings->backup_gdrive_access_token ?? null;
                $item['backup_gdrive_refresh_token'] = $settings->backup_gdrive_refresh_token ?? null;
                $item['backup_gdrive_client_secret'] = $settings->backup_gdrive_client_secret ?? null;
            } else {
                $item['backup_gdrive_has_access_token'] = !empty($settings->backup_gdrive_access_token);
                $item['backup_gdrive_has_refresh_token'] = !empty($settings->backup_gdrive_refresh_token);
                $item['backup_gdrive_has_client_secret'] = !empty($settings->backup_gdrive_client_secret);
            }
            $item['backup_gdrive_client_id'] = $settings->backup_gdrive_client_id ?? null;
            // Dropbox settings
            $item['backup_dropbox_path'] = $settings->backup_dropbox_path ?? null;
            if ($includeSecrets) {
                $item['backup_dropbox_access_token'] = $settings->backup_dropbox_access_token ?? null;
            } else {
                $item['backup_dropbox_has_access_token'] = !empty($settings->backup_dropbox_access_token);
            }

            // Global export defaults (JSON string; the SPA parses and merges
            // with its built-in defaults)
            $item['export_settings'] = $settings->export_settings ?? null;

            // Pharmacy mode (batch & expiry tracking) — opt-in, only present once migration has run
            $item['pharmacy_mode_supported'] = \Schema::hasColumn('settings', 'pharmacy_mode');
            $item['pharmacy_mode'] = (bool) ($settings->pharmacy_mode ?? false);
            $item['expiry_warning_days'] = (int) ($settings->expiry_warning_days ?? 90);
            $item['block_expired_sale'] = (bool) ($settings->block_expired_sale ?? false);
            $item['print_expiry_on_receipt'] = (bool) ($settings->print_expiry_on_receipt ?? false);

            $zones_array = [];
            $timestamp = time();
            foreach (timezone_identifiers_list() as $key => $zone) {
                date_default_timezone_set($zone);
                $zones_array[$key]['zone'] = $zone;
                $zones_array[$key]['diff_from_GMT'] = 'UTC/GMT '.date('P', $timestamp);
                $zones_array[$key]['label'] = $zones_array[$key]['diff_from_GMT'].' - '.$zones_array[$key]['zone'];
            }

            $Currencies = Currency::where('deleted_at', null)->get(['id', 'name', 'code', 'symbol']);
            $clients = client::where('deleted_at', '=', null)->get(['id', 'name']);
            $sms_gateway = sms_gateway::where('deleted_at', '=', null)->get(['id', 'title']);

            // get warehouses assigned to user
            $user_auth = auth()->user();
            if ($user_auth->is_all_warehouses) {
                $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
            } else {
                $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)->pluck('warehouse_id')->toArray();
                $warehouses = Warehouse::where('deleted_at', '=', null)->whereIn('id', $warehouses_id)->get(['id', 'name']);
            }

            $languages = Language::where('is_active', true)->get(['name', 'locale']);

            return response()->json([
                'settings' => $item,
                'currencies' => $Currencies,
                'clients' => $clients,
                'warehouses' => $warehouses,
                'sms_gateway' => $sms_gateway,
                'zones_array' => $zones_array,
                'languages' => $languages,
            ], 200);
        } else {
            return response()->json(['statut' => 'error'], 500);
        }
    }

    // -------------- Clear_Cache ---------------\\

    public function Clear_Cache(Request $request)
    {
        Artisan::call('cache:clear');
        Artisan::call('view:clear');
        Artisan::call('route:clear');
    }

    // -------------- Get Environment Value Directly from .env File ---------------\\

    private function getEnvValue($key, $default = null)
    {
        $envFile = app()->environmentFilePath();
        if (!file_exists($envFile)) {
            return $default;
        }
        
        $content = file_get_contents($envFile);
        $lines = preg_split('/\r\n|\r|\n/', $content);
        
        foreach ($lines as $line) {
            // Skip comments and empty lines
            if (empty(trim($line)) || strpos(trim($line), '#') === 0) {
                continue;
            }
            
            // Check if line contains the key
            if (strpos($line, $key . '=') === 0) {
                $parts = explode('=', $line, 2);
                if (count($parts) === 2) {
                    $value = trim($parts[1]);
                    // Remove quotes if present
                    $value = trim($value, '"\'');
                    return $value;
                }
            }
        }
        
        return $default;
    }

    // -------------- Set Environment Value ---------------\\

    public function setEnvironmentValue(array $values)
    {
        $envFile = app()->environmentFilePath();
        $str = file_get_contents($envFile);
        $str .= "\r\n";
        if (count($values) > 0) {
            foreach ($values as $envKey => $envValue) {

                $keyPosition = strpos($str, "$envKey=");
                $endOfLinePosition = strpos($str, "\n", $keyPosition);
                $oldLine = substr($str, $keyPosition, $endOfLinePosition - $keyPosition);

                if (is_bool($keyPosition) && $keyPosition === false) {
                    // variable doesnot exist
                    $str .= "$envKey=$envValue";
                    $str .= "\r\n";
                } else {
                    // variable exist
                    $str = str_replace($oldLine, "$envKey=$envValue", $str);
                }
            }
        }

        $str = substr($str, 0, -1);
        if (! file_put_contents($envFile, $str)) {
            return false;
        }

        app()->loadEnvironmentFrom($envFile);

        return true;
    }

    public function get_appearance_settings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'appearance_settings', Setting::class);

        $settings = Setting::where('deleted_at', '=', null)->first();
        if ($settings) {

            $item['id'] = $settings->id;

            $item['favicon'] = $settings->favicon;
            $item['app_name'] = $settings->app_name;
            $item['page_title_suffix'] = $settings->page_title_suffix;
            $item['customize_button_visible'] = (bool) $settings->customize_button_visible;
            $item['hide_site_name'] = (bool) ($settings->hide_site_name ?? false);
            $item['logo'] = $settings->logo;
            $item['footer'] = $settings->footer;
            $item['developed_by'] = $settings->developed_by;
            // Login page appearance
            $item['login_hero_title'] = $settings->login_hero_title;
            $item['login_hero_subtitle'] = $settings->login_hero_subtitle;
            $item['login_panel_title'] = $settings->login_panel_title;
            $item['login_panel_subtitle'] = $settings->login_panel_subtitle;
            $item['login_hero_badge'] = $settings->login_hero_badge;
            $item['login_hero_feature_1'] = $settings->login_hero_feature_1;
            $item['login_hero_feature_2'] = $settings->login_hero_feature_2;
            $item['login_hero_feature_3'] = $settings->login_hero_feature_3;
            $item['login_btn_text'] = $settings->login_btn_text;
            $item['login_footer_text'] = $settings->login_footer_text;
            $item['login_bg_color'] = $settings->login_bg_color;

            return response()->json([
                'settings' => $item,

            ], 200);
        } else {
            return response()->json(['statut' => 'error'], 500);
        }
    }

    public function update_appearance_settings(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'appearance_settings', Setting::class);

        $setting = Setting::findOrFail($id);
        $currentLogo = $setting->logo;
        $currentFavicon = $setting->favicon;

        // Only accept a valid hex color; anything else clears the override
        $login_bg_color = $request->input('login_bg_color');
        if (! is_string($login_bg_color) || ! preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $login_bg_color)) {
            $login_bg_color = null;
        }

        $logoFilename = $currentLogo;
        $faviconFilename = $currentFavicon;

        // Handle Logo Upload
        if ($request->hasFile('logo') && $request->file('logo') != $currentLogo) {
            $logo = $request->file('logo');
            $logoFilename = rand(11111111, 99999999).$logo->getClientOriginalName();
            $logoPath = public_path('/images/'.$logoFilename);

            $imageResize = Image::make($logo->getRealPath())->resize(80, 80);
            $imageResize->save($logoPath);

            if ($currentLogo && $currentLogo != 'logo-default.png') {
                $oldLogoPath = public_path('/images/'.$currentLogo);
                if (file_exists($oldLogoPath)) {
                    @unlink($oldLogoPath);
                }
            }
        }

        // Handle Favicon Upload
        if ($request->hasFile('favicon') && $request->file('favicon')->isValid()) {
            $favicon = $request->file('favicon');
            $extension = strtolower($favicon->getClientOriginalExtension());

            if (in_array($extension, ['ico', 'png'])) {
                $faviconFilename = uniqid().'.'.$extension;
                $favicon->move(public_path('images'), $faviconFilename);

                // Delete old favicon if it exists and is not default
                if ($currentFavicon && $currentFavicon !== 'favicon.ico') {
                    $oldFaviconPath = public_path('images/'.$currentFavicon);
                    if (file_exists($oldFaviconPath)) {
                        @unlink($oldFaviconPath);
                    }
                }
            }
        }

        // Update settings
        $setting->update([
            'footer' => $request->input('footer'),
            'developed_by' => $request->input('developed_by'),
            'app_name' => $request->input('app_name'),
            'page_title_suffix' => $request->input('page_title_suffix'),
            'customize_button_visible' => filter_var($request->input('customize_button_visible'), FILTER_VALIDATE_BOOLEAN),
            'hide_site_name' => filter_var($request->input('hide_site_name'), FILTER_VALIDATE_BOOLEAN),
            'logo' => $logoFilename,
            'favicon' => $faviconFilename,
            // Login page appearance
            'login_hero_title' => $request->input('login_hero_title'),
            'login_hero_subtitle' => $request->input('login_hero_subtitle'),
            'login_panel_title' => $request->input('login_panel_title'),
            'login_panel_subtitle' => $request->input('login_panel_subtitle'),
            'login_hero_badge' => $request->input('login_hero_badge'),
            'login_hero_feature_1' => $request->input('login_hero_feature_1'),
            'login_hero_feature_2' => $request->input('login_hero_feature_2'),
            'login_hero_feature_3' => $request->input('login_hero_feature_3'),
            'login_btn_text' => $request->input('login_btn_text'),
            'login_footer_text' => $request->input('login_footer_text'),
            'login_bg_color' => $login_bg_color,
        ]);

        return response()->json(['success' => true]);
    }

    // ------------------------------- PWA Settings ------------------------ \\

    /**
     * Return everything the PWA settings tab in System Settings needs: the
     * installable-app identity (name, colors, launch behavior) and the current
     * icon URLs with a cache-busting query. Icon filenames are not stored in
     * the DB — icons live at public/pwa_images/pwa-icon-{192,512}.png.
     *
     * Identity fields are returned raw (empty when unset) alongside the values
     * that would be used instead, so the form can show them as placeholders
     * rather than pretending they were configured.
     */
    public function get_pwa_settings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'appearance_settings', Setting::class);

        $setting = Setting::where('deleted_at', '=', null)->first();
        $iconDir = public_path('pwa_images');

        $icon192Path = $iconDir.DIRECTORY_SEPARATOR.'pwa-icon-192.png';
        $icon512Path = $iconDir.DIRECTORY_SEPARATOR.'pwa-icon-512.png';

        $bust192 = file_exists($icon192Path) ? filemtime($icon192Path) : null;
        $bust512 = file_exists($icon512Path) ? filemtime($icon512Path) : null;

        $appName = $setting->app_name ?? 'Stocky';
        $fallbackShortName = trim(explode('|', (string) ($setting->pwa_name ?? '') ?: $appName)[0]);

        return response()->json([
            'settings' => [
                'id' => $setting ? $setting->id : null,
                // Missing columns (migration not run) read as null and are
                // treated exactly like "not configured".
                'pwa_enabled' => (bool) ($setting->pwa_enabled ?? true),
                'pwa_name' => $setting->pwa_name ?? '',
                'pwa_short_name' => $setting->pwa_short_name ?? '',
                'pwa_description' => $setting->pwa_description ?? '',
                'pwa_start_url' => $setting->pwa_start_url ?? '',
                'pwa_display' => $setting->pwa_display ?? 'standalone',
                'pwa_orientation' => $setting->pwa_orientation ?? 'any',
                'pwa_theme_color' => $setting->pwa_theme_color ?? '',
                'pwa_background_color' => $setting->pwa_background_color ?? '',
                // Placeholders shown when the matching field is left empty.
                'app_name' => $appName,
                'default_short_name' => $fallbackShortName !== '' ? $fallbackShortName : $appName,
                'default_theme_color' => '#2f3640',
                'default_background_color' => '#ffffff',
                'icon_192_url' => '/pwa_images/pwa-icon-192.png'.($bust192 ? '?v='.$bust192 : ''),
                'icon_512_url' => '/pwa_images/pwa-icon-512.png'.($bust512 ? '?v='.$bust512 : ''),
                'icon_192_exists' => $bust192 !== null,
                'icon_512_exists' => $bust512 !== null,
            ],
        ], 200);
    }

    /**
     * Save the installable-app identity and replace the PWA icons used by all
     * manifests (admin, portal, store, customer-display). Accepts up to two
     * image uploads: icon_192 and icon_512. Each uploaded image is resized to
     * its target square size and saved as
     * public/pwa_images/pwa-icon-{192,512}.png, overwriting the previous file.
     *
     * Identity fields are optional: an empty value clears the override so the
     * manifest falls back to the app name / built-in defaults.
     */
    public function update_pwa_settings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'appearance_settings', Setting::class);

        $request->validate([
            'icon_192' => 'sometimes|file|image|mimes:png,jpg,jpeg,webp|max:1024',
            'icon_512' => 'sometimes|file|image|mimes:png,jpg,jpeg,webp|max:2048',
            'pwa_name' => 'sometimes|nullable|string|max:120',
            'pwa_short_name' => 'sometimes|nullable|string|max:60',
            'pwa_description' => 'sometimes|nullable|string|max:255',
            // Must stay inside the app's own origin, so only a path is allowed.
            'pwa_start_url' => 'sometimes|nullable|string|max:191|regex:/^\//',
            'pwa_display' => 'sometimes|nullable|in:standalone,fullscreen,minimal-ui,browser',
            'pwa_orientation' => 'sometimes|nullable|in:any,portrait,landscape',
            'pwa_theme_color' => 'sometimes|nullable|string|regex:/^#[0-9a-fA-F]{6}$/',
            'pwa_background_color' => 'sometimes|nullable|string|regex:/^#[0-9a-fA-F]{6}$/',
        ]);

        $this->savePwaIdentity($request);

        $dir = public_path('pwa_images');
        if (! file_exists($dir)) {
            @mkdir($dir, 0755, true);
        }

        $saved = [];

        if ($request->hasFile('icon_192') && $request->file('icon_192')->isValid()) {
            $img = Image::make($request->file('icon_192')->getRealPath());
            $img->fit(192, 192, function ($constraint) {
                $constraint->upsize();
            })->encode('png');
            $img->save($dir.DIRECTORY_SEPARATOR.'pwa-icon-192.png');
            $saved[] = '192';
        }

        if ($request->hasFile('icon_512') && $request->file('icon_512')->isValid()) {
            $img = Image::make($request->file('icon_512')->getRealPath());
            $img->fit(512, 512, function ($constraint) {
                $constraint->upsize();
            })->encode('png');
            $img->save($dir.DIRECTORY_SEPARATOR.'pwa-icon-512.png');
            $saved[] = '512';
        }

        return response()->json([
            'success' => true,
            'saved' => $saved,
        ]);
    }

    /**
     * Persist the installable-app identity fields sent by the PWA settings tab.
     *
     * Only fields present in the request are touched, so a client that posts
     * icons alone leaves the identity untouched. Columns missing from the
     * schema (PWA migration not run) are skipped rather than throwing, keeping
     * icon upload working on un-migrated installs.
     */
    protected function savePwaIdentity(Request $request): void
    {
        $setting = Setting::where('deleted_at', '=', null)->first();
        if (! $setting) {
            return;
        }

        $columns = [
            'pwa_name', 'pwa_short_name', 'pwa_description', 'pwa_start_url',
            'pwa_display', 'pwa_orientation', 'pwa_theme_color', 'pwa_background_color',
        ];

        $payload = [];

        foreach ($columns as $column) {
            if (! $request->has($column) || ! \Schema::hasColumn('settings', $column)) {
                continue;
            }
            $value = trim((string) $request->input($column));
            // Empty means "no override" — store NULL so the manifest falls back.
            $payload[$column] = $value === '' ? null : $value;
        }

        if ($request->has('pwa_enabled') && \Schema::hasColumn('settings', 'pwa_enabled')) {
            $payload['pwa_enabled'] = filter_var($request->input('pwa_enabled'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }

        if ($payload) {
            $setting->update($payload);
        }
    }

    /**
     * Build the pharmacy-mode column updates to merge into the main settings update.
     * Returns an empty array if the pharmacy migration hasn't been run, so the main
     * UPDATE silently skips columns that don't exist (preserves backward compatibility).
     */
    protected function pharmacySettingsPayload(Request $request, $setting): array
    {
        if (! \Schema::hasColumn('settings', 'pharmacy_mode')) {
            return [];
        }
        $bool = function ($v) {
            return ($v === '1' || $v === 'true' || $v === 1 || $v === true) ? 1 : 0;
        };
        $warningDays = (int) ($request->input('expiry_warning_days', $setting->expiry_warning_days ?? 90));
        if ($warningDays < 0) {
            $warningDays = 0;
        } elseif ($warningDays > 3650) {
            $warningDays = 3650;
        }

        return [
            'pharmacy_mode' => $request->has('pharmacy_mode') ? $bool($request->input('pharmacy_mode')) : (int) ($setting->pharmacy_mode ?? 0),
            'expiry_warning_days' => $warningDays,
            'block_expired_sale' => $request->has('block_expired_sale') ? $bool($request->input('block_expired_sale')) : (int) ($setting->block_expired_sale ?? 0),
            'print_expiry_on_receipt' => $request->has('print_expiry_on_receipt') ? $bool($request->input('print_expiry_on_receipt')) : (int) ($setting->print_expiry_on_receipt ?? 0),
        ];
    }

    // ------------------ Mobile app settings ------------------\\

    /** Modules the app can hide; these keys are what the Flutter app checks. */
    public const MOBILE_MODULE_KEYS = [
        'dashboard', 'pos', 'products', 'sales', 'purchases', 'quotations',
        'returns', 'customers', 'suppliers', 'expenses', 'adjustments',
        'transfers', 'reports', 'accounting', 'store_orders', 'hrm', 'users',
    ];

    /**
     * Every mobile-app knob shown in System Settings → Mobile App.
     * Missing columns (migration not run) read as null and behave exactly
     * like "not configured", so the app keeps its built-in defaults.
     */
    public function get_mobile_settings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'appearance_settings', Setting::class);

        $setting = Setting::where('deleted_at', '=', null)->first();
        $logo = $setting->mobile_logo ?? null;
        $logoPath = $logo ? public_path('images'.DIRECTORY_SEPARATOR.$logo) : null;

        return response()->json([
            'settings' => [
                'id' => $setting ? $setting->id : null,
                'mobile_app_enabled' => (bool) ($setting->mobile_app_enabled ?? true),
                'mobile_app_name' => $setting->mobile_app_name ?? '',
                'mobile_logo' => $logo ?? '',
                'mobile_logo_url' => ($logo && $logoPath && file_exists($logoPath))
                    ? url('/images/'.$logo).'?v='.filemtime($logoPath)
                    : null,
                'mobile_primary_color' => $setting->mobile_primary_color ?? '',
                'mobile_theme_mode' => $setting->mobile_theme_mode ?? 'system',
                'mobile_min_version' => $setting->mobile_min_version ?? '',
                'mobile_maintenance_message' => $setting->mobile_maintenance_message ?? '',
                'mobile_modules' => ($setting && $setting->mobile_modules)
                    ? json_decode($setting->mobile_modules, true)
                    : null,
                'mobile_offline_enabled' => (bool) ($setting->mobile_offline_enabled ?? true),
                'mobile_scanner_enabled' => (bool) ($setting->mobile_scanner_enabled ?? true),
                'mobile_allow_price_edit' => (bool) ($setting->mobile_allow_price_edit ?? true),
                'mobile_support_phone' => $setting->mobile_support_phone ?? '',
                'mobile_support_email' => $setting->mobile_support_email ?? '',
                // Shown as placeholders when the matching field is left empty.
                'app_name' => $setting->app_name ?? config('app.name'),
                'default_logo_url' => ($setting && $setting->logo)
                    ? url('/images/'.$setting->logo)
                    : null,
                'module_keys' => self::MOBILE_MODULE_KEYS,
            ],
        ]);
    }

    public function update_mobile_settings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'appearance_settings', Setting::class);

        $request->validate([
            'logo' => 'sometimes|file|image|mimes:png,jpg,jpeg,webp|max:2048',
            'mobile_app_name' => 'sometimes|nullable|string|max:120',
            'mobile_primary_color' => 'sometimes|nullable|string|regex:/^#[0-9a-fA-F]{6}$/',
            'mobile_theme_mode' => 'sometimes|nullable|in:system,light,dark',
            'mobile_min_version' => 'sometimes|nullable|string|max:20',
            'mobile_maintenance_message' => 'sometimes|nullable|string|max:500',
            'mobile_modules' => 'sometimes|nullable|string',
            'mobile_support_phone' => 'sometimes|nullable|string|max:40',
            'mobile_support_email' => 'sometimes|nullable|email|max:120',
        ]);

        $setting = Setting::where('deleted_at', '=', null)->firstOrFail();

        $boolean = function ($value, $default = true) {
            if ($value === null) {
                return $default;
            }

            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        };

        $setting->mobile_app_enabled = $boolean($request->input('mobile_app_enabled'));
        $setting->mobile_app_name = $request->input('mobile_app_name') ?: null;
        $setting->mobile_primary_color = $request->input('mobile_primary_color') ?: null;
        $setting->mobile_theme_mode = $request->input('mobile_theme_mode') ?: 'system';
        $setting->mobile_min_version = $request->input('mobile_min_version') ?: null;
        $setting->mobile_maintenance_message = $request->input('mobile_maintenance_message') ?: null;
        $setting->mobile_offline_enabled = $boolean($request->input('mobile_offline_enabled'));
        $setting->mobile_scanner_enabled = $boolean($request->input('mobile_scanner_enabled'));
        $setting->mobile_allow_price_edit = $boolean($request->input('mobile_allow_price_edit'));
        $setting->mobile_support_phone = $request->input('mobile_support_phone') ?: null;
        $setting->mobile_support_email = $request->input('mobile_support_email') ?: null;

        // Only keys the app knows about are stored, so a stale browser tab
        // cannot introduce unknown module flags.
        if ($request->filled('mobile_modules')) {
            $decoded = json_decode((string) $request->input('mobile_modules'), true);
            if (is_array($decoded)) {
                $clean = [];
                foreach (self::MOBILE_MODULE_KEYS as $key) {
                    if (array_key_exists($key, $decoded)) {
                        $clean[$key] = (bool) $decoded[$key];
                    }
                }
                $setting->mobile_modules = json_encode($clean);
            }
        }

        // Uploads live in public/images like every other Stocky upload.
        if ($request->hasFile('logo') && $request->file('logo')->isValid()) {
            $dir = public_path('images');
            if (! file_exists($dir)) {
                @mkdir($dir, 0755, true);
            }

            $filename = 'mobile-app-logo-'.time().'.png';
            Image::make($request->file('logo')->getRealPath())
                ->resize(512, 512, function ($constraint) {
                    $constraint->aspectRatio();
                    $constraint->upsize();
                })
                ->encode('png')
                ->save($dir.DIRECTORY_SEPARATOR.$filename);

            // Drop the previous file so uploads do not pile up.
            $old = $setting->mobile_logo;
            if ($old && $old !== $filename) {
                $oldPath = $dir.DIRECTORY_SEPARATOR.$old;
                if (file_exists($oldPath)) {
                    @unlink($oldPath);
                }
            }

            $setting->mobile_logo = $filename;
        }

        if ($request->boolean('remove_logo')) {
            $old = $setting->mobile_logo;
            if ($old) {
                $oldPath = public_path('images'.DIRECTORY_SEPARATOR.$old);
                if (file_exists($oldPath)) {
                    @unlink($oldPath);
                }
            }
            $setting->mobile_logo = null;
        }

        $setting->save();

        return response()->json(['success' => true]);
    }
}
