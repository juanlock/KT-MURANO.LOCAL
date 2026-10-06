<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    public const TEMPLATE_COMPANY_ID = 999;

    public static function forCompany(?int $companyId): self
    {
        $companyId = $companyId ?: self::TEMPLATE_COMPANY_ID;
        if ($existing = static::where('company_id', $companyId)->whereNull('deleted_at')->first()) {
            return $existing;
        }
        $template = static::where('company_id', self::TEMPLATE_COMPANY_ID)->whereNull('deleted_at')->first()
            ?? static::whereNull('deleted_at')->first();
        $attrs = $template
            ? collect($template->getAttributes())->except(['id', 'company_id', 'created_at', 'updated_at', 'deleted_at'])->all()
            : [];
        // forceFill: company_id isn't mass-assignable (and the cloned template
        // attributes may include non-fillable columns) -- create() silently
        // saved a company-less row, so every later call cloned another one.
        return tap((new static)->forceFill($attrs + ['company_id' => $companyId]))->save();
    }

    protected $fillable = [
        'currency_id', 'email', 'CompanyName', 'CompanyPhone', 'CompanyAdress', 'quotation_with_stock',
        'logo', 'footer', 'developed_by', 'client_id', 'warehouse_id', 'default_account_id', 'default_payment_method_id', 'default_language', 'show_language',
        'is_invoice_footer', 'invoice_footer', 'invoice_format', 'invoice_logo_width', 'invoice_logo_height', 'app_name', 'favicon', 'page_title_suffix', 'show_breadcrumbs', 'customize_button_visible', 'hide_site_name', 'sidebar_logo_width', 'sidebar_logo_height', 'sidebar_show_logo', 'point_to_amount_rate',
        'vat_number', 'company_name_ar', 'zatca_enabled', 'default_tax', 'default_dashboard_date_range', 'dashboard_section_order', 'dashboard_grid_layout', 'dashboard_font_size', 'dashboard_font_family', 'date_format',
        'sale_prefix', 'purchase_prefix', 'quotation_prefix', 'adjustment_prefix', 'transfer_prefix',
        'sale_return_prefix', 'purchase_return_prefix',
        'price_format', 'dark_mode', 'rtl', 'sms_gateway',
        // Pharmacy mode (batch & expiry tracking) -- opt-in, columns only
        // present once that migration has run (see pharmacySettingsPayload()).
        'pharmacy_mode', 'expiry_warning_days', 'block_expired_sale', 'print_expiry_on_receipt',
        // Login page appearance
        'login_hero_title', 'login_hero_subtitle', 'login_panel_title', 'login_panel_subtitle',
        'login_hero_badge', 'login_hero_feature_1', 'login_hero_feature_2', 'login_hero_feature_3',
        'login_btn_text', 'login_footer_text', 'login_bg_color', 'login_bg_image',
        // Admin app (/next SPA) brand color -- drives Ant Design's colorPrimary
        // token in App.vue; a per-user Customizer pick still overrides it
        // client-side (see stores/ui.js applyServerPrimaryColor()).
        'primary_color',
        // Cloud backup settings
        'backup_cloud_enabled', 'backup_keep_local', 'backup_cloud_provider', 'backup_cloud_path',
        'backup_s3_bucket', 'backup_s3_region', 'backup_s3_access_key', 'backup_s3_secret_key',
        'backup_s3_endpoint', 'backup_s3_path_style',
        'backup_gdrive_folder_id', 'backup_gdrive_access_token', 'backup_gdrive_refresh_token',
        'backup_gdrive_client_id', 'backup_gdrive_client_secret',
        'backup_dropbox_path', 'backup_dropbox_access_token',
        'google_calendar_refresh_token', 'google_calendar_calendar_id', 'timezone',
        'google_calendar_client_id', 'google_calendar_client_secret', 'google_calendar_redirect_uri',
        'offline_sync_enabled', 'enable_3_decimal_pricing',
        'enable_kitchen_display', 'kitchen_target_minutes', 'kitchen_auto_online_orders', 'kitchen_stations',
        'show_product_gtin', 'show_serial_tracking',
        'enable_multi_pack_selling', 'allow_overselling', 'sidebar_menu_order',
        'enable_pos_salesperson_switch', 'session_timeout_minutes', 'enable_multi_currency',
        'enable_wholesale_pricing', 'sale_show_price_type_dropdown', 'sale_default_quantity',
        'sale_loyalty_points_enabled', 'sale_shipping_enabled', 'sale_sales_agent_enabled', 'sale_service_jobs_enabled', 'sale_sms_enabled', 'sale_whatsapp_enabled', 'pos_require_open_register',
        // POS Settings -> Company rules: PIN to open a register; email the admins on close
        'pos_register_pin_required', 'pos_close_register_email', 'pos_booking_integration',
        // ... and confirm each sale with the PIN of who makes it (recorded under that user)
        'pos_sale_pin_required',
        // Mail Settings: SuperAdmin gets a copy of this company's admin notices
        'notify_superadmin_copy',
        // Defaults -> Users: inactivity logout, what users see after login, dashboard sections off
        'idle_logout_minutes', 'start_page_mode', 'dashboard_hidden_sections', 'start_page_shortcuts',
        // Defaults -> Expenses: what a new expense starts with
        'expense_default_payment_method_id', 'expense_default_account_id',
        // Defaults -> Email Office: hidden summary cards (JSON list)
        'email_office_hidden_cards',
        // Defaults -> Products: the company's names for "Group Name" / "Name"
        'product_group_label', 'product_name_label',
        // Defaults -> Customers: optional customer features (hide all UI when off)
        'customer_loyalty_enabled', 'customer_credit_limit_enabled', 'customer_opening_balance_enabled',
        // Defaults -> Product: auto-generated product Code (<prefix><next number>)
        'product_code_auto', 'product_code_prefix', 'product_code_digits',
        // Defaults -> Booking: optional fields on the Booking Create/Edit form
        'booking_delivery_date_enabled', 'booking_delivery_time_enabled',
        'booking_status_enabled', 'booking_delivery_address_enabled',
        'booking_decimals_enabled',
        'booking_form_logo_enabled', 'booking_form_logo', 'booking_form_logo_width',
        'booking_open_time', 'booking_close_time', 'booking_slot_minutes', 'booking_closed_days',
        'booking_capacity',
        // Defaults -> Booking: what a new booking starts with
        'booking_default_warehouse_id', 'booking_default_client_id', 'booking_default_tray_id', 'booking_default_sales_person_self',
        'booking_mode_enabled', 'booking_invoice_mode', 'booking_reminders_enabled', 'booking_reminder_hours',
        // System Settings -> DataTable: company-wide table format + per-table
        // column customization (JSON)
        'datatable_settings', 'datatable_columns', 'datatable_filters',
        // Invoice PDF -- company-scoped section show/hide toggles + editable
        // content blocks (Company Note / Terms & Conditions), distinct from
        // the global, non-company-scoped PdfTemplate "Sections" panel.
        'sale_pdf_show_disc_column', 'sale_pdf_show_tax_column', 'sale_pdf_show_shipping_row',
        'sale_pdf_show_discount_row', 'sale_pdf_show_previous_dues',
        'sale_pdf_show_company_note', 'sale_pdf_company_note',
        'sale_pdf_show_terms_conditions', 'sale_pdf_terms_conditions', 'sale_pdf_hide_time',
        'vehicle_fitment_enabled', 'module_flags', 'barcode_label_settings', 'export_settings',
        'auto_journal_enabled', 'product_image_resize', 'product_image_max_size',
        // Installable app (PWA) identity — see PwaManifestController
        'pwa_enabled', 'pwa_name', 'pwa_short_name', 'pwa_description', 'pwa_start_url',
        'pwa_display', 'pwa_orientation', 'pwa_theme_color', 'pwa_background_color',
    ];

    // Defaults -> Users: hashed default passwords of auto-created users --
    // never sent to the browser (the Settings page only gets "set / not set").
    protected $hidden = [
        'default_password_employee', 'default_password_customer', 'default_password_online_store',
        'default_password_supplier', 'default_password_sales_agent',
    ];

    /** Hashed default password for auto-created users of $type, or null. */
    public static function defaultPasswordHash(int $companyId, string $type): ?string
    {
        $key = ['employee' => 'employee', 'customer' => 'customer', 'Online_store' => 'online_store', 'online_store' => 'online_store',
            'supplier' => 'supplier', 'sales_agent' => 'sales_agent'][$type] ?? null;
        if (! $key || ! \Illuminate\Support\Facades\Schema::hasColumn('settings', 'default_password_'.$key)) {
            return null;
        }

        return \Illuminate\Support\Facades\DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->value('default_password_'.$key) ?: null;
    }

    protected $casts = [
        'currency_id' => 'integer',
        'client_id' => 'integer',
        'quotation_with_stock' => 'integer',
        'show_language' => 'integer',
        'is_invoice_footer' => 'integer',
        'invoice_logo_width' => 'integer',
        'invoice_logo_height' => 'integer',
        'warehouse_id' => 'integer',
        'default_account_id' => 'integer',
        'default_payment_method_id' => 'integer',
        'point_to_amount_rate' => 'double',
        'zatca_enabled' => 'boolean',
        'customize_button_visible' => 'boolean',
        'hide_site_name' => 'boolean',
        'sidebar_logo_width' => 'integer',
        'sidebar_logo_height' => 'integer',
        'sidebar_show_logo' => 'boolean',
        'default_tax' => 'double',
        'backup_cloud_enabled' => 'boolean',
        'backup_keep_local' => 'boolean',
        'backup_s3_path_style' => 'boolean',
        'offline_sync_enabled' => 'boolean',
        'enable_3_decimal_pricing' => 'boolean',
        'enable_kitchen_display' => 'boolean',
        'kitchen_auto_online_orders' => 'boolean',
        'show_product_gtin' => 'boolean',
        'show_serial_tracking' => 'boolean',
        'enable_multi_pack_selling' => 'boolean',
        'enable_wholesale_pricing' => 'boolean',
        'sale_show_price_type_dropdown' => 'boolean',
        'sale_default_quantity' => 'integer',
        'sale_loyalty_points_enabled' => 'boolean',
        'sale_shipping_enabled' => 'boolean',
        'sale_sales_agent_enabled' => 'boolean',
        'sale_service_jobs_enabled' => 'boolean',
        'sale_sms_enabled' => 'boolean',
        'pos_require_open_register' => 'boolean',
        'pos_register_pin_required' => 'boolean',
        'pos_sale_pin_required' => 'boolean',
        'pos_close_register_email' => 'boolean',
        'pos_booking_integration' => 'boolean',
        'notify_superadmin_copy' => 'boolean',
        'sale_whatsapp_enabled' => 'boolean',
        'customer_loyalty_enabled' => 'boolean',
        'customer_credit_limit_enabled' => 'boolean',
        'customer_opening_balance_enabled' => 'boolean',
        'product_code_auto' => 'boolean',
        'product_code_digits' => 'integer',
        'booking_delivery_date_enabled' => 'boolean',
        'booking_delivery_time_enabled' => 'boolean',
        'booking_status_enabled' => 'boolean',
        'booking_delivery_address_enabled' => 'boolean',
        'booking_decimals_enabled' => 'boolean',
        'booking_form_logo_enabled' => 'boolean',
        'booking_form_logo_width' => 'integer',
        'sale_pdf_show_disc_column' => 'boolean',
        'sale_pdf_show_tax_column' => 'boolean',
        'sale_pdf_show_shipping_row' => 'boolean',
        'sale_pdf_show_discount_row' => 'boolean',
        'sale_pdf_show_previous_dues' => 'boolean',
        'sale_pdf_show_company_note' => 'boolean',
        'sale_pdf_show_terms_conditions' => 'boolean',
        'enable_pos_salesperson_switch' => 'boolean',
        'enable_multi_currency' => 'boolean',
        'session_timeout_minutes' => 'integer',
        'allow_overselling' => 'boolean',
        'vehicle_fitment_enabled' => 'boolean',
        'product_image_resize' => 'boolean',
        'pwa_enabled' => 'boolean',
        'product_image_max_size' => 'integer',
        'google_calendar_client_secret' => 'encrypted',
        'google_calendar_refresh_token' => 'encrypted',
    ];

    public function Currency()
    {
        return $this->belongsTo('App\Models\Currency');
    }

    public function Client()
    {
        return $this->belongsTo('App\Models\Client');
    }
}
