<?php

namespace App\Http\Middleware;

use App\Support\ModuleFlags;
use Closure;
use Illuminate\Http\Request;

/**
 * System Settings -> Business Modules, enforced on the server: an API request
 * belonging to a module that is switched off for the acting company gets a
 * 403 ("The X module is turned off for this company."), not just a hidden
 * menu / blocked SPA route. Role permissions are never touched -- turning the
 * module back on restores exactly the access each role had.
 *
 * The module is found from the first segment of the API path (api/mrp/... ->
 * manufacturing), keeping the same module keys as
 * resources/src/config/modules.js. Only module-specific endpoints are listed:
 * shared lists other screens use (employees, users, sales agents, products,
 * POS) are deliberately not mapped, so one module switched off never breaks
 * another screen.
 *
 * Acting company: a SuperAdmin's selected company (company_id sent by the
 * SPA), else the user's own. A SuperAdmin with no company selected, or acting
 * as AdminSystem (999), is never blocked; unauthenticated requests pass (the
 * route's own auth decides).
 */
class EnforceModuleFlags
{
    /** API path first segment => module key (Business Modules). */
    private const PREFIXES = [
        'intranet' => 'intranet', 'intranet-admin' => 'intranet',
        'helpdesk' => 'helpdesk',
        'email-office' => 'email_office',
        'portfolio' => 'portfolio',
        'recruit' => 'recruit',
        'meeting' => 'meeting',
        'marketing' => 'marketing',
        'accounting' => 'accounting',
        'commissions' => 'commissions', 'commission_programs' => 'commissions', 'commission_rules' => 'commissions',
        'commission_receipts' => 'commissions', 'commission_report' => 'commissions',
        'promotions' => 'promotions',
        'woocommerce' => 'woocommerce_settings', 'woo-sync' => 'woocommerce_settings',
        'shopify' => 'shopify',
        'salla' => 'salla',
        'jumia' => 'jumia',
        'documents' => 'documents',
        'subscriptions' => 'subscription_product',
        'mrp' => 'manufacturing',
        'assets' => 'assets', 'assets_category' => 'assets', 'asset_assignments' => 'assets',
        'asset_maintenances' => 'assets', 'asset_transfers' => 'assets', 'assets_warehouses' => 'assets',
        'projects' => 'projects', 'project_discussions' => 'projects', 'project_documents' => 'projects',
        'project_issues' => 'projects', 'project_milestones' => 'projects', 'project_time_logs' => 'projects',
        'tasks' => 'projects', 'task_discussions' => 'projects', 'task_documents' => 'projects',
        'tasks_kanban' => 'projects', 'task_change_status' => 'projects', 'update_task_status' => 'projects',
        'bookings' => 'bookings', 'bookings_availability' => 'bookings', 'bookings_public_link' => 'bookings',
        'bookings_send_email' => 'bookings', 'booking_pdf' => 'bookings', 'trays' => 'bookings',
        'service_jobs' => 'service', 'service_checklist' => 'service', 'service_technicians' => 'service',
        'service_job_pdf' => 'service', 'service_quote_pdf' => 'service', 'service_jobs_client' => 'service',
        'fleet' => 'fleet',
        'hospital' => 'hospital',
        'school' => 'school',
        // HRM: its own records only (employees / departments / designations
        // feed other screens and stay open).
        'attendances' => 'hrm', 'daily_attendance' => 'hrm', 'holiday' => 'hrm', 'leave' => 'hrm',
        'leave_type' => 'hrm', 'payroll' => 'hrm', 'contracts' => 'hrm', 'contracts-templates' => 'hrm',
        'store' => 'Store', 'realestate' => 'Store',
    ];

    /** store/<second segment> that belongs elsewhere: E-Wallet, or never blocked (null). */
    private const STORE_OVERRIDES = [
        'wallet' => 'EWallet', 'wallets' => 'EWallet', 'wallet-withdrawals' => 'EWallet', 'gift-cards' => 'EWallet',
        'questions' => null, // product Q&A on Products -> Details
    ];

    public function handle(Request $request, Closure $next)
    {
        $segments = explode('/', trim(preg_replace('#^api/#', '', $request->path()), '/'));
        $module = self::PREFIXES[$segments[0] ?? ''] ?? null;
        if ($module === 'Store' && $segments[0] === 'store' && array_key_exists($segments[1] ?? '', self::STORE_OVERRIDES)) {
            $module = self::STORE_OVERRIDES[$segments[1]];
        }
        if (! $module) {
            return $next($request);
        }

        $user = $request->user('api') ?? $request->user();
        if (! $user) {
            return $next($request);
        }
        $companyId = $user->type === 'SuperAdmin'
            ? (int) $request->input('company_id', 0)
            : (int) $user->company_id;
        if (! $companyId || $companyId === 999) {
            return $next($request);
        }

        ModuleFlags::abortUnlessEnabled($companyId, $module);

        return $next($request);
    }
}
