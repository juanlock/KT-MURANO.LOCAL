<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Account;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Language;
use App\Models\PaymentMethod;
use App\Models\PosSetting;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use App\Models\sms_gateway;
use App\Models\User;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Intervention\Image\ImageManagerStatic as Image;

class SettingsController extends Controller
{
    use ScopesToCompany;

    /** A boolean setting from the request (1/'1'/'true'/true = on), else its saved value (default on). */
    private function settingFlag(Request $request, $setting, string $key): int
    {
        if (! $request->has($key)) {
            return (int) ($setting->{$key} ?? 1);
        }
        $v = $request[$key];

        return ($v == '1' || $v == 'true' || $v === 1 || $v === true) ? 1 : 0;
    }

    // -------------- Update  Settings ---------------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Setting::class);

        $companyId = $this->resolveActingCompanyId($request);
        $setting = Setting::forCompany($companyId);
        $currentAvatar = $setting->logo;
        // Only process logo if a file was actually uploaded
        if ($request->hasFile('logo') && $request->file('logo')->isValid()) {
            $image = $request->file('logo');
            // Company-scoped: files/company_{id}/logos, not one shared
            // folder for the whole install.
            $logosDir = \App\Support\CompanyFiles::dir($companyId, 'logos');
            $rawFilename = rand(11111111, 99999999).$image->getClientOriginalName();
            $filename = $logosDir.'/'.$rawFilename;

            $image_resize = Image::make($image->getRealPath());

             // Resize to one standard size (800x800)
             $image_resize->resize(800, 800, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            })->save(public_path($filename));

            // Old value may be an old-format bare filename (shared /images/
            // root) or the new company-scoped relative path.
            $oldLogoPath = str_contains((string) $currentAvatar, '/')
                ? public_path($currentAvatar)
                : public_path('images/'.$currentAvatar);
            if ($currentAvatar && $currentAvatar != 'logo-default.png' && file_exists($oldLogoPath)) {
                @unlink($oldLogoPath);
            }
        } else {
            $filename = $currentAvatar;
        }
        // Defaults -> Booking: logo of the public "Make an appointment" form
        // (booking_form_logo). Upload replaces it, remove_booking_form_logo=1
        // clears it (the form then falls back to the company logo).
        $bookingFormLogo = $setting->booking_form_logo;
        $dropBookingFormLogo = function () use (&$bookingFormLogo) {
            if ($bookingFormLogo && file_exists(public_path($bookingFormLogo))) {
                @unlink(public_path($bookingFormLogo));
            }
            $bookingFormLogo = null;
        };
        if ($request->hasFile('booking_form_logo') && $request->file('booking_form_logo')->isValid()) {
            $request->validate(['booking_form_logo' => 'image|mimes:png,jpg,jpeg,webp,gif|max:4096']);
            $dropBookingFormLogo();
            $file = $request->file('booking_form_logo');
            $bookingFormLogo = \App\Support\CompanyFiles::dir($companyId, 'logos').'/booking-form-'.time().'.'.strtolower($file->getClientOriginalExtension() ?: 'png');
            Image::make($file->getRealPath())->resize(800, 400, function ($c) {
                $c->aspectRatio();
                $c->upsize();
            })->save(public_path($bookingFormLogo));
        } elseif ($request->boolean('remove_booking_form_logo')) {
            $dropBookingFormLogo();
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
        // Company boundary: the default customer must be one of this
        // company's own customers -- otherwise keep (or create) its own.
        if ($client && ! Client::where('id', $client)->where('company_id', $companyId)->whereNull('deleted_at')->exists()) {
            $client = $this->ensureDefaultClient((int) $companyId, $setting);
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

        // No language button in the top bar any more (user's rule, 2026-11-10): whatever a form sends, it stays off.
        $show_language = 0;

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

        // Sidebar brand toggles Ã¢â‚¬â€ guarded with has() so older callers of this
        // endpoint that don't send them keep the stored values.
        $sidebar_show_logo = $request->has('sidebar_show_logo')
            ? (($request['sidebar_show_logo'] == '1' || $request['sidebar_show_logo'] == 'true' || $request['sidebar_show_logo'] === 1 || $request['sidebar_show_logo'] === true) ? 1 : 0)
            : (($setting->sidebar_show_logo ?? true) ? 1 : 0);
        $hide_site_name = $request->has('hide_site_name')
            ? (($request['hide_site_name'] == '1' || $request['hide_site_name'] == 'true' || $request['hide_site_name'] === 1 || $request['hide_site_name'] === true) ? 1 : 0)
            : (($setting->hide_site_name ?? false) ? 1 : 0);

        $setting->update([
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
            // Default -- Sales: show/hide the Retail/Wholesale price dropdown on
            // each sale line when adding a product (default on)
            'sale_show_price_type_dropdown' => $request->has('sale_show_price_type_dropdown')
                ? (($request['sale_show_price_type_dropdown'] == '1' || $request['sale_show_price_type_dropdown'] == 'true' || $request['sale_show_price_type_dropdown'] === 1 || $request['sale_show_price_type_dropdown'] === true) ? 1 : 0)
                : (int) ($setting->sale_show_price_type_dropdown ?? 1),
            // Default -- Sales: quantity a line starts at when a product is
            // added to a sale (default 1)
            'sale_default_quantity' => $request->has('sale_default_quantity')
                ? max(1, (int) $request['sale_default_quantity'])
                : (int) ($setting->sale_default_quantity ?: 1),
            // Default -- Sales: enable/disable the Loyalty Points widget and
            // hide its dropdown/options on the Sales Create/Edit form (default on)
            'sale_loyalty_points_enabled' => $request->has('sale_loyalty_points_enabled')
                ? (($request['sale_loyalty_points_enabled'] == '1' || $request['sale_loyalty_points_enabled'] == 'true' || $request['sale_loyalty_points_enabled'] === 1 || $request['sale_loyalty_points_enabled'] === true) ? 1 : 0)
                : (int) ($setting->sale_loyalty_points_enabled ?? 1),
            // Default -- Sales: enable/disable the Shipping field and hide it
            // on the Sales Create/Edit form (for service-only companies, default on)
            'sale_shipping_enabled' => $request->has('sale_shipping_enabled')
                ? (($request['sale_shipping_enabled'] == '1' || $request['sale_shipping_enabled'] == 'true' || $request['sale_shipping_enabled'] === 1 || $request['sale_shipping_enabled'] === true) ? 1 : 0)
                : (int) ($setting->sale_shipping_enabled ?? 1),
            // Default -- Sales: enable/disable the Sales Agent field on the
            // Sales Create/Edit form (like Shipping, default on)
            'sale_sales_agent_enabled' => $request->has('sale_sales_agent_enabled')
                ? (($request['sale_sales_agent_enabled'] == '1' || $request['sale_sales_agent_enabled'] == 'true' || $request['sale_sales_agent_enabled'] === 1 || $request['sale_sales_agent_enabled'] === true) ? 1 : 0)
                : (int) ($setting->sale_sales_agent_enabled ?? 1),
            // Default -- Sales: service jobs on sales (create / link jobs on the
            // sale, link invoices on a job). Default off; kept when not sent.
            'sale_service_jobs_enabled' => $request->has('sale_service_jobs_enabled')
                ? (filter_var($request['sale_service_jobs_enabled'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0)
                : (int) ($setting->sale_service_jobs_enabled ?? 0),
            // Default -- Sales: show/hide the SMS and WhatsApp row-action
            // icons on the Sales list (default on).
            'sale_sms_enabled' => $this->settingFlag($request, $setting, 'sale_sms_enabled'),
            // Defaults -> POS: lock the POS until a cash register is open.
            'pos_require_open_register' => $this->settingFlag($request, $setting, 'pos_require_open_register'),
            'sale_whatsapp_enabled' => $this->settingFlag($request, $setting, 'sale_whatsapp_enabled'),
            // Default -- Booking: show/hide optional fields on the Booking
            // Create/Edit form (default on)
            'booking_delivery_date_enabled' => $this->settingFlag($request, $setting, 'booking_delivery_date_enabled'),
            'booking_delivery_time_enabled' => $this->settingFlag($request, $setting, 'booking_delivery_time_enabled'),
            'booking_status_enabled' => $this->settingFlag($request, $setting, 'booking_status_enabled'),
            'booking_delivery_address_enabled' => $this->settingFlag($request, $setting, 'booking_delivery_address_enabled'),
            // Booking Price/Amount with decimals (off: whole numbers only)
            'booking_decimals_enabled' => $this->settingFlag($request, $setting, 'booking_decimals_enabled'),
            // Public booking form logo (default off; see upload handling above)
            'booking_form_logo_enabled' => $request->has('booking_form_logo_enabled')
                ? $this->settingFlag($request, $setting, 'booking_form_logo_enabled')
                : (int) ($setting->booking_form_logo_enabled ?? 0),
            'booking_form_logo' => $bookingFormLogo,
            // Online booking hours (public form's day / time-slot picker)
            'booking_open_time' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $request['booking_open_time'])
                ? $request['booking_open_time'] : ($setting->booking_open_time ?: '09:00'),
            'booking_close_time' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $request['booking_close_time'])
                ? $request['booking_close_time'] : ($setting->booking_close_time ?: '19:00'),
            'booking_slot_minutes' => in_array((int) $request['booking_slot_minutes'], [15, 30, 45, 60], true)
                ? (int) $request['booking_slot_minutes'] : ($setting->booking_slot_minutes ?: 30),
            'booking_capacity' => $request->filled('booking_capacity')
                ? max(1, min(50, (int) $request['booking_capacity'])) : ($setting->booking_capacity ?: 1),
            'booking_closed_days' => $request->has('booking_closed_days')
                ? collect(explode(',', (string) $request['booking_closed_days']))
                    ->map(fn ($d) => trim($d))->filter(fn ($d) => $d !== '' && ctype_digit($d) && (int) $d <= 6)
                    ->unique()->sort()->implode(',')
                : ($setting->booking_closed_days ?? ''),
            'booking_form_logo_width' => $request->filled('booking_form_logo_width')
                ? max(40, min(400, (int) $request['booking_form_logo_width']))
                : ($setting->booking_form_logo_width ?? 160),
            // Defaults -> Booking: new booking starts with this location /
            // customer / station; sales person = the signed-in user (switch).
            'booking_default_warehouse_id' => $request->has('booking_default_warehouse_id')
                ? ($request->filled('booking_default_warehouse_id') ? (int) $request['booking_default_warehouse_id'] : null)
                : $setting->booking_default_warehouse_id,
            'booking_default_client_id' => $request->has('booking_default_client_id')
                ? ($request->filled('booking_default_client_id') ? (int) $request['booking_default_client_id'] : null)
                : $setting->booking_default_client_id,
            'booking_default_tray_id' => $request->has('booking_default_tray_id')
                ? ($request->filled('booking_default_tray_id') ? (int) $request['booking_default_tray_id'] : null)
                : $setting->booking_default_tray_id,
            'booking_default_sales_person_self' => $request->has('booking_default_sales_person_self')
                ? $this->settingFlag($request, $setting, 'booking_default_sales_person_self')
                : (int) ($setting->booking_default_sales_person_self ?? 1),
            // Defaults -> Booking: appointment mode (office / remote / on-site),
            // what completing a booking invoices, and reminder emails.
            'booking_mode_enabled' => $request->has('booking_mode_enabled')
                ? $this->settingFlag($request, $setting, 'booking_mode_enabled')
                : (int) ($setting->booking_mode_enabled ?? 0),
            'booking_invoice_mode' => in_array($request['booking_invoice_mode'], ['cash', 'unpaid', 'none'], true)
                ? $request['booking_invoice_mode'] : ($setting->booking_invoice_mode ?: 'cash'),
            'booking_reminders_enabled' => $request->has('booking_reminders_enabled')
                ? $this->settingFlag($request, $setting, 'booking_reminders_enabled')
                : (int) ($setting->booking_reminders_enabled ?? 0),
            'booking_reminder_hours' => $request->has('booking_reminder_hours')
                ? (collect(explode(',', (string) $request['booking_reminder_hours']))
                    ->map(fn ($h) => (int) trim($h))->filter(fn ($h) => $h >= 1 && $h <= 168)
                    ->unique()->sortDesc()->take(3)->implode(',') ?: '24')
                : ($setting->booking_reminder_hours ?? '24,1'),
            // Default -- Customers: optional customer features; off hides all
            // UI tied to them (form fields, columns, stats, POS/sale widgets)
            'customer_loyalty_enabled' => $request->has('customer_loyalty_enabled')
                ? (($request['customer_loyalty_enabled'] == '1' || $request['customer_loyalty_enabled'] == 'true' || $request['customer_loyalty_enabled'] === 1 || $request['customer_loyalty_enabled'] === true) ? 1 : 0)
                : (int) ($setting->customer_loyalty_enabled ?? 1),
            'customer_credit_limit_enabled' => $request->has('customer_credit_limit_enabled')
                ? (($request['customer_credit_limit_enabled'] == '1' || $request['customer_credit_limit_enabled'] == 'true' || $request['customer_credit_limit_enabled'] === 1 || $request['customer_credit_limit_enabled'] === true) ? 1 : 0)
                : (int) ($setting->customer_credit_limit_enabled ?? 1),
            'customer_opening_balance_enabled' => $request->has('customer_opening_balance_enabled')
                ? (($request['customer_opening_balance_enabled'] == '1' || $request['customer_opening_balance_enabled'] == 'true' || $request['customer_opening_balance_enabled'] === 1 || $request['customer_opening_balance_enabled'] === true) ? 1 : 0)
                : (int) ($setting->customer_opening_balance_enabled ?? 1),
            // Default -- Product: auto-generated product Code (<prefix><next number>)
            'product_code_auto' => $request->has('product_code_auto')
                ? (($request['product_code_auto'] == '1' || $request['product_code_auto'] == 'true' || $request['product_code_auto'] === 1 || $request['product_code_auto'] === true) ? 1 : 0)
                : (int) ($setting->product_code_auto ?? 1),
            'product_code_prefix' => $request->has('product_code_prefix')
                ? mb_substr(trim((string) $request['product_code_prefix']), 0, 20)
                : $setting->product_code_prefix,
            'product_code_digits' => $request->has('product_code_digits')
                ? max(1, min(12, (int) $request['product_code_digits']))
                : (int) ($setting->product_code_digits ?? 4),
            // Invoice PDF -- section show/hide toggles (all default on)
            'sale_pdf_show_disc_column' => $request->has('sale_pdf_show_disc_column')
                ? (($request['sale_pdf_show_disc_column'] == '1' || $request['sale_pdf_show_disc_column'] == 'true' || $request['sale_pdf_show_disc_column'] === 1 || $request['sale_pdf_show_disc_column'] === true) ? 1 : 0)
                : (int) ($setting->sale_pdf_show_disc_column ?? 1),
            'sale_pdf_show_tax_column' => $request->has('sale_pdf_show_tax_column')
                ? (($request['sale_pdf_show_tax_column'] == '1' || $request['sale_pdf_show_tax_column'] == 'true' || $request['sale_pdf_show_tax_column'] === 1 || $request['sale_pdf_show_tax_column'] === true) ? 1 : 0)
                : (int) ($setting->sale_pdf_show_tax_column ?? 1),
            'sale_pdf_show_shipping_row' => $request->has('sale_pdf_show_shipping_row')
                ? (($request['sale_pdf_show_shipping_row'] == '1' || $request['sale_pdf_show_shipping_row'] == 'true' || $request['sale_pdf_show_shipping_row'] === 1 || $request['sale_pdf_show_shipping_row'] === true) ? 1 : 0)
                : (int) ($setting->sale_pdf_show_shipping_row ?? 1),
            'sale_pdf_show_discount_row' => $request->has('sale_pdf_show_discount_row')
                ? (($request['sale_pdf_show_discount_row'] == '1' || $request['sale_pdf_show_discount_row'] == 'true' || $request['sale_pdf_show_discount_row'] === 1 || $request['sale_pdf_show_discount_row'] === true) ? 1 : 0)
                : (int) ($setting->sale_pdf_show_discount_row ?? 1),
            'sale_pdf_show_previous_dues' => $request->has('sale_pdf_show_previous_dues')
                ? (($request['sale_pdf_show_previous_dues'] == '1' || $request['sale_pdf_show_previous_dues'] == 'true' || $request['sale_pdf_show_previous_dues'] === 1 || $request['sale_pdf_show_previous_dues'] === true) ? 1 : 0)
                : (int) ($setting->sale_pdf_show_previous_dues ?? 1),
            'sale_pdf_show_company_note' => $request->has('sale_pdf_show_company_note')
                ? (($request['sale_pdf_show_company_note'] == '1' || $request['sale_pdf_show_company_note'] == 'true' || $request['sale_pdf_show_company_note'] === 1 || $request['sale_pdf_show_company_note'] === true) ? 1 : 0)
                : (int) ($setting->sale_pdf_show_company_note ?? 1),
            'sale_pdf_company_note' => $request->has('sale_pdf_company_note')
                ? $request['sale_pdf_company_note']
                : $setting->sale_pdf_company_note,
            'sale_pdf_show_terms_conditions' => $request->has('sale_pdf_show_terms_conditions')
                ? (($request['sale_pdf_show_terms_conditions'] == '1' || $request['sale_pdf_show_terms_conditions'] == 'true' || $request['sale_pdf_show_terms_conditions'] === 1 || $request['sale_pdf_show_terms_conditions'] === true) ? 1 : 0)
                : (int) ($setting->sale_pdf_show_terms_conditions ?? 1),
            'sale_pdf_terms_conditions' => $request->has('sale_pdf_terms_conditions')
                ? $request['sale_pdf_terms_conditions']
                : $setting->sale_pdf_terms_conditions,
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
            // empty/null = never expire. Clamped to Ã¢â€°Â¥5 so a typo can't lock
            // everyone out the moment they log in.
            'session_timeout_minutes' => $request->has('session_timeout_minutes')
                ? (($request['session_timeout_minutes'] === '' || $request['session_timeout_minutes'] === null || (int) $request['session_timeout_minutes'] <= 0)
                    ? null
                    : max(5, (int) $request['session_timeout_minutes']))
                : ($setting->session_timeout_minutes ?? null),
            // Allow Overselling: global switch Ã¢â‚¬â€ every stock check (POS, sales,
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
            // filename_date, pdf_meta) Ã¢â‚¬â€ kept when the caller doesn't send it
            // or sends malformed JSON.
            'export_settings' => ($request->has('export_settings') && json_decode($request['export_settings'], true) !== null)
                ? $request['export_settings']
                : $setting->export_settings,
            // System Settings -> DataTable: company-wide table format (JSON),
            // same keep-on-absent/malformed rule as export_settings.
            'datatable_settings' => ($request->has('datatable_settings') && json_decode($request['datatable_settings'], true) !== null)
                ? $request['datatable_settings']
                : $setting->datatable_settings,
        ] + $this->pharmacySettingsPayload($request, $setting));

        if (! empty($currency)) {
            $currencyModel = \App\Models\Currency::find($currency);
            if ($currencyModel) {
                \App\Models\StoreSetting::forCompany($this->resolveActingCompanyId($request))->update([
                    'currency_code' => $currencyModel->symbol,
                ]);
            }
        }

        // SetLocale caches default_language per acting company (it runs on
        // every web request), and is what guest pages like /login render in
        // Ã¢â‚¬â€ drop this company's cached value on save.
        \Illuminate\Support\Facades\Cache::forget('settings.default_language.'.$this->resolveActingCompanyId($request));

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

        // Defaults -> Users: default passwords of auto-created users (hashed).
        // `default_password_{type}_new` sets/replaces it, `_clear` removes it;
        // neither sent = unchanged (the page never receives the stored hash).
        $pwChanged = false;
        foreach (['employee', 'customer', 'online_store', 'supplier', 'sales_agent'] as $t) {
            if ($request->filled("default_password_{$t}_new")) {
                $request->validate(["default_password_{$t}_new" => 'string|min:6|max:100'], [
                    "default_password_{$t}_new.min" => 'Default passwords need at least 6 characters.',
                ]);
                $setting->{"default_password_{$t}"} = \Illuminate\Support\Facades\Hash::make((string) $request["default_password_{$t}_new"]);
                $pwChanged = true;
            } elseif ($request->boolean("default_password_{$t}_clear")) {
                $setting->{"default_password_{$t}"} = null;
                $pwChanged = true;
            }
        }
        if ($pwChanged) {
            $setting->save();
        }

        // Defaults -> Users: inactivity logout (minutes, empty = off), what
        // users see after login, dashboard sections switched off. Each only
        // when sent, so other callers of this endpoint keep the stored values.
        if ($request->has('idle_logout_minutes')) {
            $m = (int) $request['idle_logout_minutes'];
            $setting->idle_logout_minutes = $m > 0 ? max(1, min(1440, $m)) : null;
        }
        if ($request->has('start_page_mode')) {
            $setting->start_page_mode = $request['start_page_mode'] === 'custom' ? 'custom' : 'dashboard';
        }
        if ($request->has('dashboard_hidden_sections')) {
            $hidden = json_decode((string) $request['dashboard_hidden_sections'], true);
            $setting->dashboard_hidden_sections = json_encode(array_values(array_filter(is_array($hidden) ? $hidden : [], 'is_string')));
        }
        // 8 shortcut slots (SPA path or null = that user's most used form).
        if ($request->has('start_page_shortcuts')) {
            $slots = json_decode((string) $request['start_page_shortcuts'], true);
            $slots = array_slice(is_array($slots) ? $slots : [], 0, 8);
            $slots = array_map(fn ($p) => (is_string($p) && preg_match('#^/[A-Za-z0-9_\-/]*$#', $p)) ? $p : null, $slots);
            $setting->start_page_shortcuts = json_encode(array_pad($slots, 8, null));
        }
        // Defaults -> Expenses: payment method / account a new expense starts with
        // (only when sent; empty = none).
        foreach (['expense_default_payment_method' => 'expense_default_payment_method_id', 'expense_default_account' => 'expense_default_account_id'] as $in => $col) {
            if ($request->has($in) && \Illuminate\Support\Facades\Schema::hasColumn('settings', $col)) {
                $v = $request[$in];
                $setting->{$col} = ($v !== '' && $v !== 'null' && $v !== null) ? (int) $v : null;
            }
        }
        // Defaults -> Email Office: the hidden summary cards ("in:spam", "out:reminder", "in:f12").
        if ($request->has('email_office_hidden_cards') && \Illuminate\Support\Facades\Schema::hasColumn('settings', 'email_office_hidden_cards')) {
            $cards = json_decode((string) $request['email_office_hidden_cards'], true);
            $cards = array_values(array_unique(array_filter(is_array($cards) ? $cards : [], fn ($c) => is_string($c) && preg_match('/^(in|out):[a-z0-9_]{1,20}$/', $c))));
            $setting->email_office_hidden_cards = json_encode($cards);
        }
        // Defaults -> Products: the company's names for "Group Name" / "Name" (empty = standard).
        foreach (['product_group_label', 'product_name_label'] as $col) {
            if ($request->has($col) && \Illuminate\Support\Facades\Schema::hasColumn('settings', $col)) {
                $v = trim(strip_tags((string) $request[$col]));
                $setting->{$col} = ($v !== '' && $v !== 'null') ? mb_substr($v, 0, 60) : null;
            }
        }
        if ($setting->isDirty(['idle_logout_minutes', 'start_page_mode', 'dashboard_hidden_sections', 'start_page_shortcuts', 'expense_default_payment_method_id', 'expense_default_account_id', 'email_office_hidden_cards', 'product_group_label', 'product_name_label'])) {
            $setting->save();
        }
        // Defaults -> Users -> Header icons (whole company; language = show_language above).
        if ($request->has('header_icons_hidden') && \Illuminate\Support\Facades\Schema::hasColumn('settings', 'header_icons')) {
            $hiddenIcons = json_decode((string) $request['header_icons_hidden'], true);
            \App\Support\HeaderIcons::saveCompanyHidden((int) $setting->company_id, is_array($hiddenIcons) ? array_filter($hiddenIcons, 'is_string') : []);
        }

        // Defaults -> General -> Public address: this company's own address for links
        // given to customers (booking codes). Empty / invalid = the server address.
        if ($request->has('public_base_url') && \Illuminate\Support\Facades\Schema::hasColumn('settings', 'public_base_url')) {
            $pub = rtrim(trim((string) $request['public_base_url']), '/');
            \Illuminate\Support\Facades\DB::table('settings')->where('id', $setting->id)
                ->update(['public_base_url' => \App\Support\PublicUrl::valid($pub) ? $pub : null]);
        }

        // Prepare environment values
        $envValues = [
            'APP_TIMEZONE' => $request['timezone'] !== null ? '"'.$request['timezone'].'"' : '"UTC"',
        ];
        // General -> Server address (APP_URL): the whole install, so SuperAdmin only.
        $appUrl = rtrim(trim((string) $request['app_url']), '/');
        if ($appUrl !== '' && optional($request->user('api'))->type === 'SuperAdmin' && \App\Support\PublicUrl::valid($appUrl)
            && $appUrl !== rtrim((string) $this->getEnvValue('APP_URL', ''), '/')) {
            // Its own whole-line replace (setEnvironmentValue matches "APP_URL=" anywhere,
            // e.g. inside VITE_APP_URL=).
            $envFile = app()->environmentFilePath();
            $env = (string) file_get_contents($envFile);
            $env = preg_match('/^APP_URL=.*$/m', $env) ? preg_replace('/^APP_URL=.*$/m', 'APP_URL='.$appUrl, $env, 1) : rtrim($env)."\nAPP_URL=".$appUrl."\n";
            file_put_contents($envFile, $env);
        }

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
        
        // No config:cache here: caching from a web request froze whatever .env
        // held at that moment (production lost its DB password that way, 2026-10-17)
        // and later .env fixes were ignored until the cache file was deleted.

        return response()->json(['success' => true]);
    }

    // -------------- Dark Mode: Get current status ---------------\\

    /**
     * Return the current dark_mode flag from settings.
     * This endpoint is intentionally minimal and independent of other settings APIs.
     */
    public function getDarkMode(Request $request)
    {

        $settings = Setting::forCompany($this->resolveActingCompanyId($request));

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

        $settings = Setting::forCompany($this->resolveActingCompanyId($request));
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

        $PosSetting = PosSetting::forCompany($this->resolveActingCompanyId($request));
        $settings = Setting::forCompany($this->resolveActingCompanyId($request));

        // Allow Overselling is a global feature (settings row) Ã¢â‚¬â€ surface it on
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

        $PosSetting = PosSetting::forCompany($this->resolveActingCompanyId($request));
        $settings = Setting::forCompany($this->resolveActingCompanyId($request));

        // Allow Overselling is a global feature (settings row) Ã¢â‚¬â€ surface it on
        // the pos_settings payload so existing consumers keep reading it here.
        if ($PosSetting) {
            $PosSetting->allow_overselling = (bool) ($settings->allow_overselling ?? false);
        }

        return response()->json([
            'pos_settings' => $PosSetting,
            // Global invoice format (thermal|a4) Ã¢â‚¬â€ editable from POS Settings
            // (update_pos_settings already persists it back to `settings`).
            'invoice_format' => $settings && in_array($settings->invoice_format, ['thermal', 'a4'], true)
                ? $settings->invoice_format : 'thermal',
            // Company-level POS rules shown on the POS Settings page (they live
            // on the settings row; update_pos_settings saves them back).
            'enable_3_decimal_pricing' => (bool) ($settings->enable_3_decimal_pricing ?? false),
            'pos_require_open_register' => (bool) ($settings->pos_require_open_register ?? true),
            'pos_register_pin_required' => (bool) ($settings->pos_register_pin_required ?? false),
            'pos_close_register_email' => (bool) ($settings->pos_close_register_email ?? true),
            'pos_booking_integration' => (bool) ($settings->pos_booking_integration ?? false),
            'pos_sale_pin_required' => (bool) ($settings->pos_sale_pin_required ?? false),
            // Who can open / close the register (2026-11-01) + the company's roles and staff logins to pick from.
            'pos_register_openers' => \App\Support\PosRegisterOpeners::get((int) $this->resolveActingCompanyId($request)),
            'register_role_options' => \Illuminate\Support\Facades\DB::table('roles')->where('company_id', $this->resolveActingCompanyId($request))->whereNull('deleted_at')
                ->orderBy('name')->get(['id', 'name'])->map(fn ($r) => ['value' => (int) $r->id, 'label' => $r->name])->values(),
            'register_user_options' => \Illuminate\Support\Facades\DB::table('users')->where('company_id', $this->resolveActingCompanyId($request))->whereNull('deleted_at')
                ->where('statut', 1)->whereIn(\Illuminate\Support\Facades\DB::raw('lower(type)'), ['admin', 'employee'])
                ->orderBy('username')->get(['id', 'username', 'email'])->map(fn ($u) => ['value' => (int) $u->id, 'label' => $u->username.' ('.$u->email.')'])->values(),
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

        $posSettings = PosSetting::forCompany($this->resolveActingCompanyId($request));

        // Allow POS Settings endpoint to also update the global invoice_format
        // so that users can control the invoice layout (thermal vs A4) directly
        // from the POS Settings screens.
        if ($request->has('invoice_format')) {
            $settings = Setting::forCompany($this->resolveActingCompanyId($request));
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

        // Company-level POS rules edited on the POS Settings page (settings
        // row): price decimals (2/3) and "Require an open register".
        $ruleKeys = ['enable_3_decimal_pricing', 'pos_require_open_register', 'pos_register_pin_required', 'pos_close_register_email', 'pos_booking_integration', 'pos_sale_pin_required'];
        if ($request->hasAny($ruleKeys)) {
            $settings = Setting::forCompany($this->resolveActingCompanyId($request));
            if ($settings) {
                if ($request->has('enable_3_decimal_pricing')) {
                    $settings->enable_3_decimal_pricing = filter_var($request->input('enable_3_decimal_pricing'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
                }
                foreach (['pos_require_open_register', 'pos_register_pin_required', 'pos_close_register_email', 'pos_booking_integration', 'pos_sale_pin_required'] as $k) {
                    if ($request->has($k)) {
                        $settings->{$k} = filter_var($request->input($k), FILTER_VALIDATE_BOOLEAN);
                    }
                }
                $settings->save();
            }
        }

        if ($request->has('pos_register_openers')) {
            $cid = (int) $this->resolveActingCompanyId($request);
            $o = (array) $request->input('pos_register_openers');
            $clean = \App\Support\PosRegisterOpeners::clean($cid, (array) ($o['roles'] ?? []), (array) ($o['users'] ?? []));
            \Illuminate\Support\Facades\DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')
                ->update(['pos_register_openers' => json_encode($clean)]);
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

        // POS toolbar customer selector (off = sales use the default customer)
        if ($request->has('show_customer_filter')) {
            $data['show_customer_filter'] = filter_var($request['show_customer_filter'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }

        // Blue POS button in the admin top header (default ON)
        if ($request->has('show_pos_button')) {
            $data['show_pos_button'] = ($request['show_pos_button'] == '1' || $request['show_pos_button'] == 'true' || $request['show_pos_button'] === true) ? 1 : 0;
        }

        // Customer purchase history panel in the POS (default ON)
        if ($request->has('show_customer_history')) {
            $data['show_customer_history'] = ($request['show_customer_history'] == '1' || $request['show_customer_history'] == 'true' || $request['show_customer_history'] === true) ? 1 : 0;
        }

        // Allow Overselling moved to the global settings row (Features tab).
        // The in-POS toggle still posts it here, so persist it globally instead
        // of on pos_settings.
        if ($request->has('allow_overselling')) {
            $globalSettings = Setting::forCompany($this->resolveActingCompanyId($request));
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
        // can be injected into print-window <style> tags; empty Ã¢â€ â€™ null = default.
        if ($request->has('receipt_font_family')) {
            $family = trim((string) $request->input('receipt_font_family'));
            $family = preg_replace("/[^a-zA-Z0-9 ,'\\-]/", '', $family);
            $data['receipt_font_family'] = $family !== '' ? mb_substr($family, 0, 120) : null;
        }

        // Font size in px, clamped to a printable range; out-of-range Ã¢â€ â€™ null = default.
        if ($request->has('receipt_font_size')) {
            $fontSize = (int) $request->input('receipt_font_size');
            $data['receipt_font_size'] = ($fontSize >= 8 && $fontSize <= 24) ? $fontSize : null;
        }

        // Receipt layout (1-5) Ã¢â‚¬â€œ only if sent (POS Receipt / POS Settings pages).
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

    /**
     * Make sure a company has a valid Default Customer: its settings.client_id
     * must point at one of THIS company's own live customers. If it's empty,
     * deleted, or belongs to another company (e.g. a Setting row cloned from
     * the template still pointing at company 1's walk-in customer), reuse the
     * company's own "Walk-in Customer" or create it, and save it as the
     * default. Returns the resulting client id (null for the template row).
     */
    private function ensureDefaultClient(int $companyId, $settings): ?int
    {
        if (! $settings || $companyId === Setting::TEMPLATE_COMPANY_ID) {
            return $settings->client_id ?? null;
        }

        $valid = $settings->client_id && Client::where('id', $settings->client_id)
            ->where('company_id', $companyId)->whereNull('deleted_at')->exists();
        if ($valid) {
            return (int) $settings->client_id;
        }

        $client = Client::where('company_id', $companyId)->whereNull('deleted_at')
            ->whereIn('name', ['Walk-in Customer', 'walk-in-customer'])->first();
        if (! $client) {
            $client = Client::create([
                'company_id' => $companyId,
                'name' => 'Walk-in Customer',
                'code' => ((int) \Illuminate\Support\Facades\DB::table('clients')->max('code')) + 1,
            ]);
        }

        $settings->update(['client_id' => $client->id]);

        return (int) $client->id;
    }

    /**
     * DataTable "Customize Columns": save (or, with columns=null, reset) one
     * table's column setup for the acting company -- display order + per
     * column {key, title, width, hidden}. Admins/SuperAdmin only, via the
     * `datatable_customize` permission. Served back to every user of the
     * company through get_user_auth (datatable_columns).
     */
    public function updateDatatableColumns(Request $request)
    {
        $user = $request->user('api');
        $allowed = $user->type === 'SuperAdmin'
            || (bool) optional($user->roles()->first())->permissions?->contains('name', 'datatable_customize');
        abort_unless($allowed, 403);

        $data = $request->validate([
            'table_key' => ['required', 'string', 'max:120'],
            'columns' => ['nullable', 'array', 'max:80'],
            'columns.*.key' => ['required', 'string', 'max:80'],
            'columns.*.title' => ['nullable', 'string', 'max:80'],
            'columns.*.width' => ['nullable', 'integer', 'min:40', 'max:800'],
            'columns.*.hidden' => ['nullable', 'boolean'],
        ]);

        $setting = Setting::forCompany($this->resolveActingCompanyId($request));
        $all = json_decode($setting->datatable_columns ?: '{}', true) ?: [];

        if (empty($data['columns'])) {
            unset($all[$data['table_key']]);
        } else {
            $all[$data['table_key']] = array_map(fn ($c) => [
                'key' => $c['key'],
                'title' => isset($c['title']) && trim($c['title']) !== '' ? trim($c['title']) : null,
                'width' => $c['width'] ?? null,
                'hidden' => (bool) ($c['hidden'] ?? false),
            ], $data['columns']);
        }

        $setting->update(['datatable_columns' => $all ? json_encode($all) : null]);

        return response()->json(['success' => true, 'datatable_columns' => (object) $all]);
    }

    /**
     * "Customize Filters": save (or, with filters=null, reset) one list's
     * filter-row setup for the acting company -- display order + per filter
     * {key, hidden}. Same idea and same `datatable_customize` permission as
     * updateDatatableColumns() above, just for the filter inputs instead of
     * the table's own columns (a filter has no title/width to customize).
     * Served back to every user of the company through get_user_auth
     * (datatable_filters).
     */
    public function updateDatatableFilters(Request $request)
    {
        $user = $request->user('api');
        $allowed = $user->type === 'SuperAdmin'
            || (bool) optional($user->roles()->first())->permissions?->contains('name', 'datatable_customize');
        abort_unless($allowed, 403);

        $data = $request->validate([
            'table_key' => ['required', 'string', 'max:120'],
            'filters' => ['nullable', 'array', 'max:40'],
            'filters.*.key' => ['required', 'string', 'max:80'],
            'filters.*.hidden' => ['nullable', 'boolean'],
        ]);

        $setting = Setting::forCompany($this->resolveActingCompanyId($request));
        $all = json_decode($setting->datatable_filters ?: '{}', true) ?: [];

        if (empty($data['filters'])) {
            unset($all[$data['table_key']]);
        } else {
            $all[$data['table_key']] = array_map(fn ($f) => [
                'key' => $f['key'],
                'hidden' => (bool) ($f['hidden'] ?? false),
            ], $data['filters']);
        }

        $setting->update(['datatable_filters' => $all ? json_encode($all) : null]);

        return response()->json(['success' => true, 'datatable_filters' => (object) $all]);
    }

    public function get_Settings_data_api(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Setting::class);

        $companyId = $this->resolveActingCompanyId($request);
        $settings = Setting::forCompany($companyId);
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

            $item['client_id'] = $this->ensureDefaultClient((int) $companyId, $settings) ?? '';

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
            $item['sale_show_price_type_dropdown'] = (bool) ($settings->sale_show_price_type_dropdown ?? true);
            $item['sale_default_quantity'] = (int) ($settings->sale_default_quantity ?: 1);
            $item['sale_loyalty_points_enabled'] = (bool) ($settings->sale_loyalty_points_enabled ?? true);
            $item['customer_loyalty_enabled'] = (bool) ($settings->customer_loyalty_enabled ?? true);
            $item['customer_credit_limit_enabled'] = (bool) ($settings->customer_credit_limit_enabled ?? true);
            $item['customer_opening_balance_enabled'] = (bool) ($settings->customer_opening_balance_enabled ?? true);
            $item['product_code_auto'] = (bool) ($settings->product_code_auto ?? true);
            $item['product_code_prefix'] = $settings->product_code_prefix ?? '';
            $item['product_code_digits'] = (int) ($settings->product_code_digits ?? 4);
            $item['product_code_next'] = \App\Support\ProductCode::next((int) $companyId);
            $item['sale_shipping_enabled'] = (bool) ($settings->sale_shipping_enabled ?? true);
            $item['sale_sales_agent_enabled'] = (bool) ($settings->sale_sales_agent_enabled ?? true);
            $item['sale_service_jobs_enabled'] = (bool) ($settings->sale_service_jobs_enabled ?? false);
            // Saved by the Defaults -> Sales switches; without them here the page showed them on again.
            $item['sale_sms_enabled'] = (bool) ($settings->sale_sms_enabled ?? true);
            $item['sale_whatsapp_enabled'] = (bool) ($settings->sale_whatsapp_enabled ?? true);
            foreach (['booking_delivery_date_enabled', 'booking_delivery_time_enabled', 'booking_status_enabled', 'booking_delivery_address_enabled', 'booking_decimals_enabled'] as $flag) {
                $item[$flag] = (bool) ($settings->{$flag} ?? true);
            }
            // Defaults -> Booking: public form logo (url = preview in Settings).
            $item['booking_form_logo_enabled'] = (bool) ($settings->booking_form_logo_enabled ?? false);
            $item['booking_form_logo_width'] = (int) ($settings->booking_form_logo_width ?: 160);
            // Defaults -> Booking: online booking hours (public form slot picker).
            $item['booking_open_time'] = $settings->booking_open_time ?: '09:00';
            $item['booking_close_time'] = $settings->booking_close_time ?: '19:00';
            $item['booking_slot_minutes'] = (int) ($settings->booking_slot_minutes ?: 30);
            $item['booking_closed_days'] = (string) ($settings->booking_closed_days ?? '');
            $item['booking_capacity'] = (int) ($settings->booking_capacity ?: 1);
            $item['booking_default_warehouse_id'] = $settings->booking_default_warehouse_id ? (int) $settings->booking_default_warehouse_id : null;
            $item['booking_default_client_id'] = $settings->booking_default_client_id ? (int) $settings->booking_default_client_id : null;
            $item['booking_default_tray_id'] = $settings->booking_default_tray_id ? (int) $settings->booking_default_tray_id : null;
            $item['booking_default_sales_person_self'] = (bool) ($settings->booking_default_sales_person_self ?? true);
            // Defaults -> Booking: appointment mode, invoicing on completion, reminders.
            $item['booking_mode_enabled'] = (bool) ($settings->booking_mode_enabled ?? false);
            $item['booking_invoice_mode'] = $settings->booking_invoice_mode ?: 'cash';
            $item['booking_reminders_enabled'] = (bool) ($settings->booking_reminders_enabled ?? false);
            $item['booking_reminder_hours'] = (string) ($settings->booking_reminder_hours ?? '24,1');
            // Defaults -> Users: only whether each default password is set (never the hash).
            foreach (['employee', 'customer', 'online_store', 'supplier', 'sales_agent'] as $pwType) {
                $item["default_password_{$pwType}_set"] = ! empty(\Illuminate\Support\Facades\DB::table('settings')->where('id', $settings->id)->value("default_password_{$pwType}"));
            }
            $item['idle_logout_minutes'] = $settings->idle_logout_minutes ? (int) $settings->idle_logout_minutes : null;
            $item['expense_default_payment_method_id'] = $settings->expense_default_payment_method_id ? (int) $settings->expense_default_payment_method_id : null;
            $item['expense_default_account_id'] = $settings->expense_default_account_id ? (int) $settings->expense_default_account_id : null;
            $item['email_office_hidden_cards'] = json_decode((string) ($settings->email_office_hidden_cards ?? '[]'), true) ?: [];
            $item['product_group_label'] = $settings->product_group_label ?? null;
            $item['product_name_label'] = $settings->product_name_label ?? null;
            $item['start_page_mode'] = $settings->start_page_mode ?: 'dashboard';
            $item['header_icons_hidden'] = \App\Support\HeaderIcons::companyHidden((int) $settings->company_id);
            $item['dashboard_hidden_sections'] = json_decode((string) ($settings->dashboard_hidden_sections ?? '[]'), true) ?: [];
            $item['start_page_shortcuts'] = array_pad(array_slice(json_decode((string) ($settings->start_page_shortcuts ?? '[]'), true) ?: [], 0, 8), 8, null);
            $item['booking_form_logo_url'] = ($settings->booking_form_logo && file_exists(public_path($settings->booking_form_logo))) ? asset($settings->booking_form_logo).'?v='.filemtime(public_path($settings->booking_form_logo)) : null;
            $item['sale_pdf_show_disc_column'] = (bool) ($settings->sale_pdf_show_disc_column ?? true);
            $item['sale_pdf_show_tax_column'] = (bool) ($settings->sale_pdf_show_tax_column ?? true);
            $item['sale_pdf_show_shipping_row'] = (bool) ($settings->sale_pdf_show_shipping_row ?? true);
            $item['sale_pdf_show_discount_row'] = (bool) ($settings->sale_pdf_show_discount_row ?? true);
            $item['sale_pdf_show_previous_dues'] = (bool) ($settings->sale_pdf_show_previous_dues ?? true);
            $item['sale_pdf_show_company_note'] = (bool) ($settings->sale_pdf_show_company_note ?? true);
            $item['sale_pdf_company_note'] = $settings->sale_pdf_company_note;
            $item['sale_pdf_show_terms_conditions'] = (bool) ($settings->sale_pdf_show_terms_conditions ?? true);
            $item['sale_pdf_terms_conditions'] = $settings->sale_pdf_terms_conditions;
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
            // ZATCA only when the company has the module on (Business Modules; off by default)
            $item['zatca_module'] = \App\Support\ModuleFlags::enabled((int) $settings->company_id, 'zatca');
            $item['zatca_enabled'] = (bool) $settings->zatca_enabled && $item['zatca_module'];
            // Timezone from .env file - read directly from file to avoid cache issues
            $item['timezone'] = $this->getEnvValue('APP_TIMEZONE', 'UTC');
            $item['app_url'] = rtrim((string) $this->getEnvValue('APP_URL', config('app.url')), '/');
            $item['public_base_url'] = $settings->public_base_url ?? null;
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
            // Resize uploaded product images on save (default on, 800px box) Ã¢â‚¬â€ must
            // be returned here or the form loads it undefined and every save
            // silently turns resizing off.
            $item['product_image_resize'] = (bool) ($settings->product_image_resize ?? true);
            $item['product_image_max_size'] = (int) ($settings->product_image_max_size ?? 800);
            // Serial / IMEI tracking master switch (default false)
            $item['show_serial_tracking'] = (bool) ($settings->show_serial_tracking ?? false);
            // Multi-pack selling toggle (default false)
            $item['enable_multi_pack_selling'] = (bool) ($settings->enable_multi_pack_selling ?? false);
            // Wholesale Pricing by Quantity toggle (default false) Ã¢â‚¬â€ must be
            // returned here or the System Settings form loads it undefined and
            // every save silently turns the feature off.
            $item['enable_wholesale_pricing'] = (bool) ($settings->enable_wholesale_pricing ?? false);
            // Multi-Currency toggle (default false) Ã¢â‚¬â€ must be returned here or
            // the System Settings form loads it undefined and every save
            // silently turns the feature off.
            $item['enable_multi_currency'] = (bool) ($settings->enable_multi_currency ?? false);
            // Change Salesperson During Checkout toggle (default false) Ã¢â‚¬â€ must be
            // returned here or the System Settings form loads it undefined and
            // every save silently turns the feature off.
            $item['enable_pos_salesperson_switch'] = (bool) ($settings->enable_pos_salesperson_switch ?? false);
            // Session timeout: minutes before automatic logout (null = never)
            $item['session_timeout_minutes'] = $settings->session_timeout_minutes ?? null;
            $item['allow_overselling'] = (bool) ($settings->allow_overselling ?? false);
            // Vehicle Fitment master switch (default false) Ã¢â‚¬â€ must be returned
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
            $item['datatable_settings'] = $settings->datatable_settings ?? null;

            // Pharmacy mode (batch & expiry tracking) Ã¢â‚¬â€ opt-in, only present once migration has run
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
            // Company boundary: only this company's customers are offerable
            // as its Default Customer.
            $clients = client::where('deleted_at', '=', null)->where('company_id', $companyId)->orderBy('name')->get(['id', 'name']);
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
                // Defaults -> Booking: default station picker
                'trays' => \App\Models\Tray::whereNull('deleted_at')->where('company_id', $companyId)->orderBy('name')->get(['id', 'name']),
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
        $setting = Setting::forCompany($this->resolveActingCompanyId($request));
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
        $setting = Setting::forCompany($this->resolveActingCompanyId($request));
        $order = $setting?->sidebar_menu_order ? json_decode($setting->sidebar_menu_order, true) : null;

        return response()->json(['sidebar_menu_order' => $order], 200);
    }

    public function updateSidebarMenuOrder(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', Setting::class);
        $setting = Setting::forCompany($this->resolveActingCompanyId($request));
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
        $setting = Setting::forCompany($this->resolveActingCompanyId($request));
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
        abort_unless(optional($request->user('api'))->type === 'SuperAdmin', 403, 'Only a SuperAdmin can view module settings.');
        $companyId = $this->resolveActingCompanyId($request);
        $setting = Setting::forCompany($companyId);
        $flags = $setting?->module_flags ? json_decode($setting->module_flags, true) : null;
        // The company's business type and the modules that type starts with
        // (Business Modules -> "Modules of <type>").
        $type = \App\Support\BusinessTypes::of((int) $companyId);

        return response()->json([
            'module_flags' => is_array($flags) ? $flags : null,
            'business_type' => $type,
            'business_type_label' => \App\Support\BusinessTypes::LABELS[$type] ?? $type,
            'type_modules' => \App\Support\BusinessTypes::modules($type),
        ], 200);
    }

    public function updateModuleFlags(Request $request)
    {
        abort_unless(optional($request->user('api'))->type === 'SuperAdmin', 403, 'Only a SuperAdmin can change module settings.');
        // business_modules OR setting_system (see SettingPolicy::business_modules)
        $this->authorizeForUser($request->user('api'), 'business_modules', Setting::class);
        $setting = Setting::forCompany($this->resolveActingCompanyId($request));
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
        abort_unless(optional($request->user('api'))->type === 'SuperAdmin', 403, 'Only a SuperAdmin can change module settings.');
        $this->authorizeForUser($request->user('api'), 'business_modules', Setting::class);
        $setting = Setting::forCompany($this->resolveActingCompanyId($request));
        if (! $setting) {
            return response()->json(['message' => 'Settings not found'], 404);
        }

        $setting->module_flags = null;
        $setting->save();

        return response()->json(['success' => true], 200);
    }

    /**
     * Business Modules -> "Copy from another company" (user's rule, 2026-10-25):
     * ONLY a SuperAdmin, acting on a company, copies another company's modules
     * (settings.module_flags) and / or its menu (Manage Menu rows in
     * menu_positions + the top-level order settings.sidebar_menu_order), in one
     * transaction. Roles and their permissions are not touched.
     *   POST settings/copy_company_layout {from_company_id, modules: bool, menu: bool}
     */
    public function copyCompanyLayout(Request $request)
    {
        abort_unless(optional($request->user('api'))->type === 'SuperAdmin', 403, 'Only a SuperAdmin can copy another company\'s modules and menu.');
        $to = (int) $this->resolveActingCompanyId($request);
        $v = $request->validate(['from_company_id' => 'required|integer', 'modules' => 'boolean', 'menu' => 'boolean']);
        $from = (int) $v['from_company_id'];
        $modules = (bool) ($v['modules'] ?? true);
        $menu = (bool) ($v['menu'] ?? true);
        abort_if($to === 999 || ! $to, 422, 'Pick the company to copy INTO in the top bar first.');
        abort_if($from === $to, 422, 'Pick a different company to copy from.');
        abort_unless($modules || $menu, 422, 'Choose what to copy: modules, menu or both.');
        abort_unless(DB::table('companies')->where('id', $from)->whereNull('deleted_at')->exists(), 404, 'Company not found.');
        $src = DB::table('settings')->where('company_id', $from)->whereNull('deleted_at')->first(['module_flags', 'sidebar_menu_order']);
        $dst = Setting::forCompany($to);
        abort_unless($src && $dst, 404, 'Settings not found.');

        $copied = DB::transaction(function () use ($src, $dst, $from, $to, $modules, $menu) {
            $n = 0;
            if ($modules) {
                $dst->module_flags = $src->module_flags;
            }
            if ($menu) {
                $dst->sidebar_menu_order = $src->sidebar_menu_order;
                DB::table('menu_positions')->where('company_id', $to)->delete();
                $now = now();
                $rows = DB::table('menu_positions')->where('company_id', $from)->orderBy('id')->get()
                    ->map(fn ($r) => array_merge(array_diff_key((array) $r, ['id' => 1]), ['company_id' => $to, 'created_at' => $now, 'updated_at' => $now]))->all();
                foreach (array_chunk($rows, 200) as $chunk) {
                    DB::table('menu_positions')->insert($chunk);
                }
                $n = count($rows);
            }
            $dst->save();

            return $n;
        });

        return response()->json(['success' => true, 'menu_items' => $copied,
            'module_flags' => $dst->module_flags ? json_decode($dst->module_flags, true) : null]);
    }

    // ----------------- Feature toggles (SettingsÃ¢â€ â€™ Modules Ã¢â€ â€™ Features tab) -----------------\\
    // The same optional-capability switches as System Settings Ã¢â€ â€™ Features,
    // exposed as a small standalone endpoint so the Modules page can save them
    // without posting the full settings row (the main update() clobbers any
    // unguarded field that isn't sent).

    public function getFeatureSettings(Request $request)
    {
        abort_unless(optional($request->user('api'))->type === 'SuperAdmin', 403, 'Only a SuperAdmin can view feature settings.');
        $this->authorizeForUser($request->user('api'), 'business_modules', Setting::class);
        $settings = Setting::forCompany($this->resolveActingCompanyId($request));

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
        abort_unless(optional($request->user('api'))->type === 'SuperAdmin', 403, 'Only a SuperAdmin can change feature settings.');
        $this->authorizeForUser($request->user('api'), 'business_modules', Setting::class);
        $setting = Setting::forCompany($this->resolveActingCompanyId($request));
        if (! $setting) {
            return response()->json(['message' => 'Settings not found'], 404);
        }

        $boolFields = [
            'enable_3_decimal_pricing', 'enable_kitchen_display', 'kitchen_auto_online_orders', 'show_product_gtin',
            'product_image_resize', 'show_serial_tracking', 'enable_multi_pack_selling',
            'enable_wholesale_pricing', 'enable_multi_currency', 'enable_pos_salesperson_switch',
            'vehicle_fitment_enabled', 'auto_journal_enabled', 'allow_overselling',
        ];

        // Only fields present in the payload are written Ã¢â‚¬â€ anything omitted
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
            $setting->update($data);
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
        $setting = Setting::forCompany($this->resolveActingCompanyId($request));
        $labelSettings = $setting?->barcode_label_settings ? json_decode($setting->barcode_label_settings, true) : null;

        return response()->json(['barcode_label_settings' => is_array($labelSettings) ? $labelSettings : null], 200);
    }

    public function updateBarcodeLabelSettings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'barcode', \App\Models\Product::class);
        $setting = Setting::forCompany($this->resolveActingCompanyId($request));
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
        'name' => 'KcolTech - Murano Test Label',
        'barcode' => '628112345678',
        'Type_barcode' => 'CODE128',
        'Net_price' => '49.99',
        'qte' => 1,
    ];

    /**
     * The stored label design, filled in with the defaults and the sticker
     * size the test label is printed at. In raster mode the settings page has
     * to draw the sample itself, so it needs exactly this Ã¢â‚¬â€ hence the
     * companion endpoint below rather than the `barcode` permissioned
     * getBarcodeLabelSettings.
     */
    private function labelTestDesign(Request $request, ?\App\Models\PosSetting $posSetting): array
    {
        $setting = Setting::forCompany($this->resolveActingCompanyId($request));
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
        // printer-font mode uses when the symbol has no ASCII form (Ã˜Â¯.Ã˜Â¥, Ã¢â€šÂ¹Ã¢â‚¬Â¦).
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
        $posSetting = \App\Models\PosSetting::forCompany($this->resolveActingCompanyId($request));

        return response()->json([
            'design' => $this->labelTestDesign($request, $posSetting),
            'label' => self::LABEL_TEST_SAMPLE,
        ]);
    }

    public function testLabelPrinter(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'pos_settings', Setting::class);

        $posSetting = \App\Models\PosSetting::forCompany($this->resolveActingCompanyId($request));
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
        // machine Ã¢â‚¬â€ hand the payload back instead of printing server-side.
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
     * Print two frames Ã¢â‚¬â€ one computed at 203 dpi, one at 300 Ã¢â‚¬â€ each labeled
     * with its number. The frame that exactly matches the sticker edges IS
     * the printhead's real resolution: the user reads the answer off the
     * label instead of anyone guessing scale factors from photos.
     */
    public function dpiTestLabelPrinter(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'pos_settings', Setting::class);

        $posSetting = \App\Models\PosSetting::forCompany($this->resolveActingCompanyId($request));
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
     * sticker/gap lengths and stores them Ã¢â‚¬â€ the same calibration the vendor
     * print tools perform. Run this once after loading a new roll, or when
     * label positions drift.
     */
    public function calibrateLabelPrinter(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'pos_settings', Setting::class);

        $posSetting = \App\Models\PosSetting::forCompany($this->resolveActingCompanyId($request));
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
     * Returns certificate:null when the server cannot generate one Ã¢â‚¬â€ the
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

        $companyId = $this->resolveActingCompanyId($request);
        $settings = Setting::forCompany($companyId);
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

            $item['client_id'] = $this->ensureDefaultClient((int) $companyId, $settings) ?? '';

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
            $item['sale_show_price_type_dropdown'] = (bool) ($settings->sale_show_price_type_dropdown ?? true);
            $item['sale_default_quantity'] = (int) ($settings->sale_default_quantity ?: 1);
            $item['sale_loyalty_points_enabled'] = (bool) ($settings->sale_loyalty_points_enabled ?? true);
            $item['customer_loyalty_enabled'] = (bool) ($settings->customer_loyalty_enabled ?? true);
            $item['customer_credit_limit_enabled'] = (bool) ($settings->customer_credit_limit_enabled ?? true);
            $item['customer_opening_balance_enabled'] = (bool) ($settings->customer_opening_balance_enabled ?? true);
            $item['product_code_auto'] = (bool) ($settings->product_code_auto ?? true);
            $item['product_code_prefix'] = $settings->product_code_prefix ?? '';
            $item['product_code_digits'] = (int) ($settings->product_code_digits ?? 4);
            $item['product_code_next'] = \App\Support\ProductCode::next((int) $companyId);
            $item['sale_shipping_enabled'] = (bool) ($settings->sale_shipping_enabled ?? true);
            $item['sale_sales_agent_enabled'] = (bool) ($settings->sale_sales_agent_enabled ?? true);
            $item['sale_service_jobs_enabled'] = (bool) ($settings->sale_service_jobs_enabled ?? false);
            // Saved by the Defaults -> Sales switches; without them here the page showed them on again.
            $item['sale_sms_enabled'] = (bool) ($settings->sale_sms_enabled ?? true);
            $item['sale_whatsapp_enabled'] = (bool) ($settings->sale_whatsapp_enabled ?? true);
            foreach (['booking_delivery_date_enabled', 'booking_delivery_time_enabled', 'booking_status_enabled', 'booking_delivery_address_enabled', 'booking_decimals_enabled'] as $flag) {
                $item[$flag] = (bool) ($settings->{$flag} ?? true);
            }
            // Defaults -> Booking: public form logo (url = preview in Settings).
            $item['booking_form_logo_enabled'] = (bool) ($settings->booking_form_logo_enabled ?? false);
            $item['booking_form_logo_width'] = (int) ($settings->booking_form_logo_width ?: 160);
            // Defaults -> Booking: online booking hours (public form slot picker).
            $item['booking_open_time'] = $settings->booking_open_time ?: '09:00';
            $item['booking_close_time'] = $settings->booking_close_time ?: '19:00';
            $item['booking_slot_minutes'] = (int) ($settings->booking_slot_minutes ?: 30);
            $item['booking_closed_days'] = (string) ($settings->booking_closed_days ?? '');
            $item['booking_capacity'] = (int) ($settings->booking_capacity ?: 1);
            $item['booking_default_warehouse_id'] = $settings->booking_default_warehouse_id ? (int) $settings->booking_default_warehouse_id : null;
            $item['booking_default_client_id'] = $settings->booking_default_client_id ? (int) $settings->booking_default_client_id : null;
            $item['booking_default_tray_id'] = $settings->booking_default_tray_id ? (int) $settings->booking_default_tray_id : null;
            $item['booking_default_sales_person_self'] = (bool) ($settings->booking_default_sales_person_self ?? true);
            // Defaults -> Booking: appointment mode, invoicing on completion, reminders.
            $item['booking_mode_enabled'] = (bool) ($settings->booking_mode_enabled ?? false);
            $item['booking_invoice_mode'] = $settings->booking_invoice_mode ?: 'cash';
            $item['booking_reminders_enabled'] = (bool) ($settings->booking_reminders_enabled ?? false);
            $item['booking_reminder_hours'] = (string) ($settings->booking_reminder_hours ?? '24,1');
            // Defaults -> Users: only whether each default password is set (never the hash).
            foreach (['employee', 'customer', 'online_store', 'supplier', 'sales_agent'] as $pwType) {
                $item["default_password_{$pwType}_set"] = ! empty(\Illuminate\Support\Facades\DB::table('settings')->where('id', $settings->id)->value("default_password_{$pwType}"));
            }
            $item['idle_logout_minutes'] = $settings->idle_logout_minutes ? (int) $settings->idle_logout_minutes : null;
            $item['expense_default_payment_method_id'] = $settings->expense_default_payment_method_id ? (int) $settings->expense_default_payment_method_id : null;
            $item['expense_default_account_id'] = $settings->expense_default_account_id ? (int) $settings->expense_default_account_id : null;
            $item['email_office_hidden_cards'] = json_decode((string) ($settings->email_office_hidden_cards ?? '[]'), true) ?: [];
            $item['product_group_label'] = $settings->product_group_label ?? null;
            $item['product_name_label'] = $settings->product_name_label ?? null;
            $item['start_page_mode'] = $settings->start_page_mode ?: 'dashboard';
            $item['header_icons_hidden'] = \App\Support\HeaderIcons::companyHidden((int) $settings->company_id);
            $item['dashboard_hidden_sections'] = json_decode((string) ($settings->dashboard_hidden_sections ?? '[]'), true) ?: [];
            $item['start_page_shortcuts'] = array_pad(array_slice(json_decode((string) ($settings->start_page_shortcuts ?? '[]'), true) ?: [], 0, 8), 8, null);
            $item['booking_form_logo_url'] = ($settings->booking_form_logo && file_exists(public_path($settings->booking_form_logo))) ? asset($settings->booking_form_logo).'?v='.filemtime(public_path($settings->booking_form_logo)) : null;
            $item['sale_pdf_show_disc_column'] = (bool) ($settings->sale_pdf_show_disc_column ?? true);
            $item['sale_pdf_show_tax_column'] = (bool) ($settings->sale_pdf_show_tax_column ?? true);
            $item['sale_pdf_show_shipping_row'] = (bool) ($settings->sale_pdf_show_shipping_row ?? true);
            $item['sale_pdf_show_discount_row'] = (bool) ($settings->sale_pdf_show_discount_row ?? true);
            $item['sale_pdf_show_previous_dues'] = (bool) ($settings->sale_pdf_show_previous_dues ?? true);
            $item['sale_pdf_show_company_note'] = (bool) ($settings->sale_pdf_show_company_note ?? true);
            $item['sale_pdf_company_note'] = $settings->sale_pdf_company_note;
            $item['sale_pdf_show_terms_conditions'] = (bool) ($settings->sale_pdf_show_terms_conditions ?? true);
            $item['sale_pdf_terms_conditions'] = $settings->sale_pdf_terms_conditions;
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
            // ZATCA only when the company has the module on (Business Modules; off by default)
            $item['zatca_module'] = \App\Support\ModuleFlags::enabled((int) $settings->company_id, 'zatca');
            $item['zatca_enabled'] = (bool) $settings->zatca_enabled && $item['zatca_module'];
            // Timezone from .env file - read directly from file to avoid cache issues
            $item['timezone'] = $this->getEnvValue('APP_TIMEZONE', 'UTC');
            $item['app_url'] = rtrim((string) $this->getEnvValue('APP_URL', config('app.url')), '/');
            $item['public_base_url'] = $settings->public_base_url ?? null;
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
            // Resize uploaded product images on save (default on, 800px box) Ã¢â‚¬â€ must
            // be returned here or the form loads it undefined and every save
            // silently turns resizing off.
            $item['product_image_resize'] = (bool) ($settings->product_image_resize ?? true);
            $item['product_image_max_size'] = (int) ($settings->product_image_max_size ?? 800);
            // Serial / IMEI tracking master switch (default false)
            $item['show_serial_tracking'] = (bool) ($settings->show_serial_tracking ?? false);
            // Multi-pack selling toggle (default false)
            $item['enable_multi_pack_selling'] = (bool) ($settings->enable_multi_pack_selling ?? false);
            // Wholesale Pricing by Quantity toggle (default false) Ã¢â‚¬â€ must be
            // returned here or the System Settings form loads it undefined and
            // every save silently turns the feature off.
            $item['enable_wholesale_pricing'] = (bool) ($settings->enable_wholesale_pricing ?? false);
            // Multi-Currency toggle (default false) Ã¢â‚¬â€ must be returned here or
            // the System Settings form loads it undefined and every save
            // silently turns the feature off.
            $item['enable_multi_currency'] = (bool) ($settings->enable_multi_currency ?? false);
            // Change Salesperson During Checkout toggle (default false) Ã¢â‚¬â€ must be
            // returned here or the System Settings form loads it undefined and
            // every save silently turns the feature off.
            $item['enable_pos_salesperson_switch'] = (bool) ($settings->enable_pos_salesperson_switch ?? false);
            // Session timeout: minutes before automatic logout (null = never)
            $item['session_timeout_minutes'] = $settings->session_timeout_minutes ?? null;
            $item['allow_overselling'] = (bool) ($settings->allow_overselling ?? false);
            // Vehicle Fitment master switch (default false) Ã¢â‚¬â€ must be returned
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
            $item['datatable_settings'] = $settings->datatable_settings ?? null;

            // Pharmacy mode (batch & expiry tracking) Ã¢â‚¬â€ opt-in, only present once migration has run
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
            // Company boundary: only this company's customers are offerable
            // as its Default Customer.
            $clients = client::where('deleted_at', '=', null)->where('company_id', $companyId)->orderBy('name')->get(['id', 'name']);
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
                // Defaults -> Booking: default station picker
                'trays' => \App\Models\Tray::whereNull('deleted_at')->where('company_id', $companyId)->orderBy('name')->get(['id', 'name']),
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
        // The button's help says "views, config and route caches".
        Artisan::call('config:clear');

        // A JSON answer: the SPA's http.get() parses the body, and the old
        // empty 200 made the button report "Failed" although it had worked.
        return response()->json(['success' => true]);
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

        $settings = Setting::forCompany($this->resolveActingCompanyId($request));
        if ($settings) {

            $item['id'] = $settings->id;

            $item['favicon'] = $settings->favicon;
            $item['app_name'] = $settings->app_name;
            $item['page_title_suffix'] = $settings->page_title_suffix;
            $item['show_breadcrumbs'] = (bool) ($settings->show_breadcrumbs ?? true);
            $item['customize_button_visible'] = (bool) $settings->customize_button_visible;
            $item['hide_site_name'] = (bool) ($settings->hide_site_name ?? false);
            $item['logo'] = $settings->logo;
            $item['footer'] = $settings->footer;
            $item['developed_by'] = $settings->developed_by;
            $item['primary_color'] = $settings->primary_color;

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

        $companyId = $this->resolveActingCompanyId($request);
        $setting = Setting::forCompany($companyId);
        $currentLogo = $setting->logo;
        $currentFavicon = $setting->favicon;

        // Only accept a valid hex color; anything else clears the override
        $primary_color = $request->input('primary_color');
        if (! is_string($primary_color) || ! preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $primary_color)) {
            $primary_color = null;
        }

        $logoFilename = $currentLogo;
        $faviconFilename = $currentFavicon;

        // Handle Logo Upload
        if ($request->hasFile('logo') && $request->file('logo') != $currentLogo) {
            $logo = $request->file('logo');
            $brandingDir = \App\Support\CompanyFiles::dir($companyId, 'logos');
            $logoFilename = rand(11111111, 99999999).$logo->getClientOriginalName();
            $logoPath = public_path($brandingDir.'/'.$logoFilename);

            $imageResize = Image::make($logo->getRealPath())->resize(80, 80);
            $imageResize->save($logoPath);

            // Old value may be either the new "relDir/filename" format or a
            // bare filename from the old shared images/ folder.
            if ($currentLogo && $currentLogo != 'logo-default.png') {
                $oldLogoPath = str_contains((string) $currentLogo, '/')
                    ? public_path($currentLogo)
                    : public_path('/images/'.$currentLogo);
                if (file_exists($oldLogoPath)) {
                    @unlink($oldLogoPath);
                }
            }

            $logoFilename = $brandingDir.'/'.$logoFilename;
        }

        // Handle Favicon Upload
        if ($request->hasFile('favicon') && $request->file('favicon')->isValid()) {
            $favicon = $request->file('favicon');
            $extension = strtolower($favicon->getClientOriginalExtension());

            if (in_array($extension, ['ico', 'png'])) {
                $brandingDir = \App\Support\CompanyFiles::dir($companyId, 'logos');
                $faviconFilename = uniqid().'.'.$extension;
                $favicon->move(public_path($brandingDir), $faviconFilename);

                // Delete old favicon if it exists and is not default. Old
                // value may be either the new "relDir/filename" format or a
                // bare filename from the old shared images/ folder.
                if ($currentFavicon && $currentFavicon !== 'favicon.ico') {
                    $oldFaviconPath = str_contains((string) $currentFavicon, '/')
                        ? public_path($currentFavicon)
                        : public_path('images/'.$currentFavicon);
                    if (file_exists($oldFaviconPath)) {
                        @unlink($oldFaviconPath);
                    }
                }

                $faviconFilename = $brandingDir.'/'.$faviconFilename;
            }
        }

        // Update settings
        $setting->update([
            'footer' => $request->input('footer'),
            'developed_by' => $request->input('developed_by'),
            'app_name' => $request->input('app_name'),
            'page_title_suffix' => $request->input('page_title_suffix'),
            // Not sent by an older page = keep the current value.
            'show_breadcrumbs' => $request->has('show_breadcrumbs') ? filter_var($request->input('show_breadcrumbs'), FILTER_VALIDATE_BOOLEAN) : ($setting->show_breadcrumbs ?? true),
            'customize_button_visible' => filter_var($request->input('customize_button_visible'), FILTER_VALIDATE_BOOLEAN),
            'hide_site_name' => filter_var($request->input('hide_site_name'), FILTER_VALIDATE_BOOLEAN),
            'logo' => $logoFilename,
            'favicon' => $faviconFilename,
            'primary_color' => $primary_color,
        ]);

        return response()->json(['success' => true]);
    }

    // ------------------------------- Login Page Settings ------------------------ \\
    // A separate form from Appearance Settings above, and SuperAdmin-only,
    // on purpose: the login screen renders before any user is authenticated,
    // so there's no company to resolve yet (no session, no acting-company
    // topbar switcher) -- it can only ever be ONE shared, install-wide look,
    // never a per-company one. That's different in kind from every other
    // field on the Appearance Settings page (which really do follow the
    // acting company), so it doesn't belong on that page, and a company-level
    // Admin -- who may well hold the general 'appearance_settings' permission
    // -- must not be able to change what EVERY other company's users see on
    // their own login screen. Always reads/writes the reserved template
    // company row (Setting::TEMPLATE_COMPANY_ID = 999), matching
    // AppServiceProvider's login/password view-composer branch.

    public function get_login_page_settings(Request $request)
    {
        abort_unless(optional($request->user('api'))->type === 'SuperAdmin', 403, 'Only a SuperAdmin can view the login page settings.');

        $settings = Setting::forCompany(Setting::TEMPLATE_COMPANY_ID);

        return response()->json(['settings' => [
            'id' => $settings->id,
            // Page title (<title>) and the logo shown above the login form --
            // same "Murano | Ultimate Inventory With POS" placeholder every
            // fresh company's Setting row is cloned with (Setting::forCompany's
            // template-clone), left unedited on the reserved template row
            // specifically. Editable here now instead of only through a
            // per-company field that never actually reached this screen.
            'app_name' => $settings->app_name,
            'logo' => $settings->logo,
            'favicon' => $settings->favicon,
            // Guests (no session/user yet) resolve their default locale off
            // THIS same row -- see SetLocale::settingsLocale().
            'default_language' => $settings->default_language ?: 'en',
            // Each of these is a per-locale JSON map ({"en": "...", "es": "..."}
            // -- 2026_09_24_160000_make_login_page_text_multilingual), decoded
            // here so the form can show/edit every language at once instead of
            // just whichever one happens to be the admin's own browser locale.
            'login_hero_title' => $this->decodeLoginLocaleMap($settings->login_hero_title),
            'login_hero_subtitle' => $this->decodeLoginLocaleMap($settings->login_hero_subtitle),
            'login_panel_title' => $this->decodeLoginLocaleMap($settings->login_panel_title),
            'login_panel_subtitle' => $this->decodeLoginLocaleMap($settings->login_panel_subtitle),
            'login_hero_badge' => $this->decodeLoginLocaleMap($settings->login_hero_badge),
            'login_hero_feature_1' => $this->decodeLoginLocaleMap($settings->login_hero_feature_1),
            'login_hero_feature_2' => $this->decodeLoginLocaleMap($settings->login_hero_feature_2),
            'login_hero_feature_3' => $this->decodeLoginLocaleMap($settings->login_hero_feature_3),
            'login_btn_text' => $this->decodeLoginLocaleMap($settings->login_btn_text),
            'login_footer_text' => $this->decodeLoginLocaleMap($settings->login_footer_text),
            'login_bg_color' => $settings->login_bg_color,
            'login_bg_color_es' => $settings->login_bg_color_es ?? null,
            // Background is EITHER a color OR an uploaded image (image wins
            // when both are set -- see login.blade.php); background_mode
            // just tells the frontend which control to show, derived from
            // which one is actually populated rather than stored separately.
            'login_bg_image' => $settings->login_bg_image,
            'background_mode' => $settings->login_bg_image ? 'image' : 'color',
        ]]);
    }

    /** Decodes a Login Page Settings text field's per-locale JSON map, tolerating null/malformed values. */
    private function decodeLoginLocaleMap(?string $json): array
    {
        if (! $json) {
            return [];
        }
        $map = json_decode($json, true);

        return is_array($map) ? $map : [];
    }

    /**
     * Saves an uploaded file into the fixed global public/images/loginpage/
     * folder (never per-company -- CompanyFiles::dir() does not apply here,
     * this is the one shared login screen) and deletes the previous file IF
     * it also lived in that folder (a bare legacy filename like "logo.png"
     * under the shared images/ root is a default, never deleted).
     */
    private function saveLoginPageImage(\Illuminate\Http\UploadedFile $file, ?string $oldValue, ?int $maxWidth = null, ?int $maxHeight = null): string
    {
        $dir = 'images/loginpage';
        if (! is_dir(public_path($dir))) {
            mkdir(public_path($dir), 0755, true);
        }
        $filename = rand(11111111, 99999999).'.'.strtolower($file->getClientOriginalExtension());
        $image = Image::make($file->getRealPath());
        if ($maxWidth && $maxHeight) {
            $image->resize($maxWidth, $maxHeight, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });
        }
        $image->save(public_path($dir.'/'.$filename));

        if ($oldValue && str_contains($oldValue, '/')) {
            $oldPath = public_path($oldValue);
            if (file_exists($oldPath)) {
                @unlink($oldPath);
            }
        }

        return $dir.'/'.$filename;
    }

    public function update_login_page_settings(Request $request)
    {
        abort_unless(optional($request->user('api'))->type === 'SuperAdmin', 403, 'Only a SuperAdmin can edit the login page settings.');

        $setting = Setting::forCompany(Setting::TEMPLATE_COMPANY_ID);

        // Only accept a valid hex color; anything else clears the override.
        $login_bg_color = $request->input('login_bg_color');
        if (! is_string($login_bg_color) || ! preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $login_bg_color)) {
            $login_bg_color = null;
        }
        // Spanish background colour (2026-10-20); empty = same as English.
        $login_bg_color_es = $request->input('login_bg_color_es');
        if (! is_string($login_bg_color_es) || ! preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $login_bg_color_es)) {
            $login_bg_color_es = null;
        }

        $logoFilename = $setting->logo;
        if ($request->hasFile('logo') && $request->file('logo')->isValid()) {
            $logoFilename = $this->saveLoginPageImage($request->file('logo'), $setting->logo, 220, 80);
        }

        // Favicon: a raw file move, not saveLoginPageImage()'s Intervention
        // Image resize -- Intervention doesn't handle .ico reliably, and a
        // favicon needs no resizing anyway. Same ico/png restriction as the
        // per-company favicon upload in update_appearance_settings() above.
        $faviconFilename = $setting->favicon;
        if ($request->hasFile('favicon') && $request->file('favicon')->isValid()) {
            $favicon = $request->file('favicon');
            $extension = strtolower($favicon->getClientOriginalExtension());
            if (in_array($extension, ['ico', 'png'])) {
                $dir = 'images/loginpage';
                if (! is_dir(public_path($dir))) {
                    mkdir(public_path($dir), 0755, true);
                }
                $newFilename = uniqid().'.'.$extension;
                $favicon->move(public_path($dir), $newFilename);

                if ($faviconFilename && str_contains($faviconFilename, '/')) {
                    $oldPath = public_path($faviconFilename);
                    if (file_exists($oldPath)) {
                        @unlink($oldPath);
                    }
                }

                $faviconFilename = $dir.'/'.$newFilename;
            }
        }

        // Background: color and image are mutually exclusive. background_mode
        // ('color'|'image') says which the SuperAdmin has selected; switching
        // to 'color' clears any previously uploaded image (and deletes the
        // file) so the color takes effect instead of a stale image winning.
        $backgroundMode = $request->input('background_mode', 'color');
        $bgImage = $setting->login_bg_image;
        if ($backgroundMode === 'image') {
            if ($request->hasFile('login_bg_image') && $request->file('login_bg_image')->isValid()) {
                // A hero background is the full height of one side of the
                // screen -- wide-gamut resize (not the small logo crop), cap
                // just to keep the file from being unreasonably huge.
                $bgImage = $this->saveLoginPageImage($request->file('login_bg_image'), $setting->login_bg_image, 2400, 3200);
            }
        } else {
            if ($bgImage && str_contains($bgImage, '/')) {
                $oldPath = public_path($bgImage);
                if (file_exists($oldPath)) {
                    @unlink($oldPath);
                }
            }
            $bgImage = null;
        }

        // Guests resolve their default locale off THIS row too (see
        // app\Http\Middleware\SetLocale::settingsLocale() -- Setting::forCompany(null)
        // lands on this same template row) -- only accept a locale this app
        // actually ships, same validation SetLocale itself applies.
        $defaultLanguage = $request->input('default_language');
        if (! is_string($defaultLanguage) || ! array_key_exists($defaultLanguage, \App\Http\Middleware\SetLocale::SUPPORTED)) {
            $defaultLanguage = 'en';
        }

        // Which language's copy this submission is editing -- each text
        // field is a per-locale JSON map, and saving must only touch THIS
        // locale's entry, leaving every other language's text untouched.
        $textLocale = $request->input('text_locale');
        if (! is_string($textLocale) || ! array_key_exists($textLocale, \App\Http\Middleware\SetLocale::SUPPORTED)) {
            $textLocale = 'en';
        }
        $textFields = [
            'login_hero_title', 'login_hero_subtitle', 'login_panel_title', 'login_panel_subtitle',
            'login_hero_badge', 'login_hero_feature_1', 'login_hero_feature_2', 'login_hero_feature_3',
            'login_btn_text', 'login_footer_text',
        ];
        $textUpdates = [];
        foreach ($textFields as $field) {
            $map = $this->decodeLoginLocaleMap($setting->$field);
            $value = $request->input($field);
            if ($value === null || $value === '') {
                unset($map[$textLocale]);
            } else {
                $map[$textLocale] = $value;
            }
            $textUpdates[$field] = $map ? json_encode($map, JSON_UNESCAPED_UNICODE) : null;
        }

        $setting->update(array_merge([
            'app_name' => $request->input('app_name'),
            'logo' => $logoFilename,
            'favicon' => $faviconFilename,
            'default_language' => $defaultLanguage,
            'login_bg_color' => $login_bg_color,
            'login_bg_image' => $bgImage,
        ], \Illuminate\Support\Facades\Schema::hasColumn('settings', 'login_bg_color_es') ? ['login_bg_color_es' => $login_bg_color_es] : [], $textUpdates));

        // SetLocale::settingsLocale() caches the guest default for 10
        // minutes -- without this, a SuperAdmin changing the default
        // language here would not take effect for guests until that expires.
        \Illuminate\Support\Facades\Cache::forget('settings.default_language.guest');

        return response()->json(['success' => true]);
    }

    // ------------------------------- PWA Settings ------------------------ \\

    /**
     * Return everything the PWA settings tab in System Settings needs: the
     * installable-app identity (name, colors, launch behavior) and the current
     * icon URLs with a cache-busting query. Icon filenames are not stored in
     * the DB Ã¢â‚¬â€ icons live at public/pwa_images/pwa-icon-{192,512}.png.
     *
     * Identity fields are returned raw (empty when unset) alongside the values
     * that would be used instead, so the form can show them as placeholders
     * rather than pretending they were configured.
     */
    public function get_pwa_settings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'appearance_settings', Setting::class);

        $setting = Setting::forCompany($this->resolveActingCompanyId($request));
        $iconDir = public_path('pwa_images');

        $icon192Path = $iconDir.DIRECTORY_SEPARATOR.'pwa-icon-192.png';
        $icon512Path = $iconDir.DIRECTORY_SEPARATOR.'pwa-icon-512.png';

        $bust192 = file_exists($icon192Path) ? filemtime($icon192Path) : null;
        $bust512 = file_exists($icon512Path) ? filemtime($icon512Path) : null;

        $appName = $setting->app_name ?? 'KcolTech - Murano';
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

        // The icons are install-wide files in public/pwa_images: only a
        // SuperAdmin may replace them (project standard -- company admins
        // never write outside their own files/company_{id}/).
        abort_if(($request->hasFile('icon_192') || $request->hasFile('icon_512')) && optional($request->user('api'))->type !== 'SuperAdmin',
            403, 'Only a SuperAdmin can change the app icons (they are shared by every company).');

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
        $setting = Setting::forCompany($this->resolveActingCompanyId($request));
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
            // Empty means "no override" Ã¢â‚¬â€ store NULL so the manifest falls back.
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

}
