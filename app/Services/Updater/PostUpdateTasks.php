<?php

namespace App\Services\Updater;

use App\Models\Permission;
use App\Models\Role;
use App\Models\sms_gateway;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Everything that must run after the new files are in place and migrations
 * have completed: permission sync, gateway/template seeding, translation
 * seeders and data clean-ups.
 *
 * This is the single source of truth for the upgrade finalization — the
 * legacy /update wizard (UpdateController::lastStep) and the System Update
 * flow both call it. When adding a new module permission, add it HERE (and
 * to PermissionsSeeder + permissions.js as usual).
 */
class PostUpdateTasks
{
    /**
     * Data fixes that must run BEFORE migrations (they detect the pre-
     * migration schema — e.g. the discount_method backfill only applies
     * while the column does not exist yet).
     */
    public function preMigration(): void
    {
        // Backward compatibility for old sales (NO discount_method)
        if (! Schema::hasColumn('sales', 'discount_method')) {
            if (Schema::hasColumn('sales', 'discount_from_points')) {
                DB::table('sales')
                    ->where('discount_from_points', '>', 0)
                    ->update([
                        'discount' => DB::raw('GREATEST(discount - discount_from_points, 0)'),
                    ]);
            }
        }
    }

    /** Post-migration finalization. */
    public function run(): void
    {
        $this->syncPermissions();
        $this->syncSmsGateways();
        $this->syncMessageTemplates();

        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\TranslationSeeder', '--force' => true]);
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\StoreSettingSeeder', '--force' => true]);

        $this->cleanProductNames();
    }

    public function syncPermissions(): void
    {
        $role = Role::findOrFail(1);
        $role->permissions()->detach();

        foreach ($this->permissionList() as $permission_slug) {
            Permission::firstOrCreate(['name' => $permission_slug]);
        }

        $permissions_data = Permission::pluck('id')->toArray();
        $role->permissions()->attach($permissions_data);
    }

    public function syncSmsGateways(): void
    {
        sms_gateway::firstOrCreate(['title' => 'infobip']);
        sms_gateway::firstOrCreate(['title' => 'termii']);
        sms_gateway::firstOrCreate(['title' => 'custom']);

        $nexmoGateway = sms_gateway::where('title', 'nexmo')->first();
        if ($nexmoGateway) {
            $nexmoGateway->delete();
        }
    }

    public function syncMessageTemplates(): void
    {
        DB::table('sms_messages')->updateOrInsert(
            [
                'name' => 'subscription_reminder',
                'text' => "Hello {client_name},\nThis is a reminder from {business_name} that your subscription will renew automatically on {next_billing_date}. \nPlease ensure your payment method is up-to-date to avoid interruptions.\nThank you!",
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $smsAssetValidationKeys = ['name' => 'asset_validation_due'];
        if (Schema::hasColumn('sms_messages', 'locale')) {
            $smsAssetValidationKeys['locale'] = 'en';
        }
        DB::table('sms_messages')->updateOrInsert(
            $smsAssetValidationKeys,
            [
                'text' => 'Reminder from {business_name}: Asset {asset_name} ({asset_tag}) is due for validation on {next_validation}. Please verify the tool or equipment.',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('email_messages')->updateOrInsert(
            ['name' => 'booking'],
            [
                'subject' => 'Your Booking Confirmation',
                'body' => '<p><b><span style="font-size:14px;">Dear {contact_name},</span></b></p><p>Your booking has been confirmed. Booking number: {booking_number}.</p><p><span style="font-size:14px;">Date: {booking_date}<br>Time: {start_time} - {end_time}<br>Service: {service_name}</span></p><p>If you have any questions, please don\'t hesitate to contact us.</p><p>Best regards,</p><p><b><span style="font-size:14px;">{business_name}</span></b></p>',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $emailAssetValidationKeys = ['name' => 'asset_validation_due'];
        if (Schema::hasColumn('email_messages', 'locale')) {
            $emailAssetValidationKeys['locale'] = 'en';
        }
        DB::table('email_messages')->updateOrInsert(
            $emailAssetValidationKeys,
            [
                'subject' => 'Asset validation due: {asset_name}',
                'body' => '<p>The following asset is due for validation (or is overdue). Please verify the tool or equipment.</p><p><b>Asset:</b> {asset_name} ({asset_tag})<br><b>Next validation date:</b> {next_validation}</p><p><a href="{asset_edit_url}">View / Edit asset</a></p><p>Best regards,<br><b>{business_name}</b></p>',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function cleanProductNames(): void
    {
        \App\Models\Product::where('name', 'REGEXP', '<|>')
            ->chunkById(200, function ($products) {
                foreach ($products as $p) {
                    $old = $p->name;
                    $clean = str_replace(['<', '>'], ['‹', '›'], strip_tags($old));
                    if ($clean !== $old) {
                        $p->name = $clean;
                        $p->save();
                    }
                }
            });
    }

    public function permissionList(): array
    {
        return [
            'view_employee',
            'add_employee',
            'edit_employee',
            'delete_employee',
            'company',
            'department',
            'designation',
            'office_shift',
            'attendance',
            'leave',
            'holiday',
            'Top_products',
            'Top_customers',
            'shipment',
            'users_report',
            'stock_report',
            'sms_settings',
            'pos_settings',
            'payment_gateway',
            'mail_settings',
            'dashboard',
            'pay_due',
            'pay_sale_return_due',
            'pay_supplier_due',
            'pay_purchase_return_due',
            'product_report',
            'product_sales_report',
            'product_purchases_report',
            'notification_template',
            'edit_product_sale',
            'edit_product_purchase',
            'edit_product_quotation',
            'edit_tax_discount_shipping_sale',
            'edit_tax_discount_shipping_purchase',
            'edit_tax_discount_shipping_quotation',
            'module_settings',
            'count_stock',
            'deposit_add',
            'deposit_delete',
            'deposit_edit',
            'deposit_view',
            'account',
            'inventory_valuation',
            'expenses_report',
            'deposits_report',
            'transfer_money',
            'payroll',
            'projects',
            'tasks',
            'appearance_settings',
            'translations_settings',
            'subscription_product',
            'report_error_logs',
            'payment_methods',
            'report_transactions',
            'report_sales_by_category',
            'report_sales_by_brand',
            'opening_stock_import',
            'seller_report',
            'Store_settings_view',
            'Orders_view',
            'Collections_view',
            'Banners_view',
            'inactive_customers_report',
            'zeroSalesProducts',
            'Dead_Stock_Report',
            'draft_invoices_report',
            'discount_summary_report',
            'tax_summary_report',
            'Stock_Aging_Report',
            'Stock_Transfer_Report',
            'Stock_Adjustment_Report',
            'Top_Suppliers_Report',
            'Subscribers_view',
            'Messages_view',
            'cash_register_report',
            'woocommerce_settings',
            'customer_display_screen_setup',
            'quickbooks_settings',
            'customer_loyalty_points_report',
            'assets',
            'damage_view',
            'cash_flow_report',
            'report_attendance_summary',
            'return_ratio_report',
            'negative_stock_report',
            'accounting_dashboard',
            'chart_of_accounts',
            'journal_entries',
            'trial_balance',
            'accounting_profit_loss',
            'balance_sheet',
            'accounting_tax_report',
            'service_jobs',
            'service_jobs_report',
            'checklist_completion_report',
            'customer_maintenance_history_report',
            'bookings',
            'subcategory',
            'login_device_management',
            'report_device_management',
            'update_settings',
            'analytics_report',
            'Stock_Inventory_Valuation',
            'AI_Reports',
            'report_warranty',
            'system_health_view',
            'real_time_sales_counter',
            'warehouse_locations',
            'internal_location_report',
            'contracts',
            'commissions_view',
            'commissions_add',
            'commissions_edit',
            'commissions_delete',
            'knowledge_base_view',
            'webhooks_view',
            'webhooks_add',
            'webhooks_edit',
            'webhooks_delete',
            'sales_3d_dashboard',
            'batch_view',
            'batch_manage',
            'batch_writeoff',
            'batch_force_override',
            'expiry_report',
            'Batch_Register_Report',
            'kitchen_display_view',
            'kitchen_display_manage',

            // Recruit module
            'recruit_job',
            'recruit_category',
            'recruit_candidate',
            'recruit_application',
            'recruit_interview',

            // Meeting module
            'meeting',
            'meeting_attendance',
            'meeting_report',

            // Marketing module
            'marketing_dashboard',
            'marketing_campaigns',
            'marketing_segments',
            'marketing_templates',
            'marketing_reports',
            'marketing_settings',

            // Serial / IMEI tracking module
            'serial_numbers',
            'serial_numbers_report',

            // Real Estate module
            'realestate_properties',
            'realestate_categories',
            'realestate_inquiries',

            // Trays module (Booking Orders)
            'trays',
            'size_guides',
            'loyalty_rewards',
            'ewallet',

            // Promotions module
            'promotion',

            // Document Archive module
            'documents_view',
            'documents_add',
            'documents_edit',
            'documents_delete',

            // Vehicle & Fleet Management module
            'fleet_vehicles_view',
            'fleet_vehicles_add',
            'fleet_vehicles_edit',
            'fleet_vehicles_delete',
            'fleet_maintenance',
            'fleet_fuel',
            'fleet_assignments',
            'fleet_reports',

            // Hospital Management module
            'hms_dashboard',
            'hms_patients_view',
            'hms_patients_add',
            'hms_patients_edit',
            'hms_patients_delete',
            'hms_doctors',
            'hms_departments',
            'hms_appointments',
            'hms_visits',
            'hms_admissions',
            'hms_wards',
            'hms_lab',
            'hms_billing',
            'hms_reports',

            // School Management module
            'school_dashboard',
            'school_students_view',
            'school_students_add',
            'school_students_edit',
            'school_students_delete',
            'school_teachers',
            'school_academics',
            'school_enrollment',
            'school_attendance',
            'school_exams',
            'school_timetable',
            'school_fees',
            'school_reports',

            // Projects Management additions
            'project_milestones',
            'project_timesheets',
            'project_reports',

            // Asset Management additions
            'asset_assignments',
            'asset_maintenance',
            'asset_transfers',
            'asset_reports',

            // Shopify Integration
            'shopify_stores',
            'shopify_sync',
            'shopify_logs',

            // Advanced Manufacturing MRP
            'mrp_boms',
            'mrp_production',
            'mrp_quality',
            'mrp_planning',
            'mrp_reports',

            // Settings → Modules (business module toggles)
            'business_modules',

            // Products sold summary (per item day report)
            'products_sold_summary',

            // ZATCA e-invoicing (Phase 2) settings
            'zatca_settings',

            // Multi-Currency: change the currency of a document
            // (sale/purchase/quotation/POS pickers)
            'multi_currency',

            // Integrations platform
            'slack_settings',
            'telegram_settings',
            'salla_settings',
            'xero_settings',
            'prestashop_settings',
            'google_sheets_settings',
            'mailchimp_settings',
            'jumia_settings',
        ];
    }
}
