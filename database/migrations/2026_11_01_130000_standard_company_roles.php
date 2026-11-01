<?php

use App\Support\CompanyRoles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * User's rule, 2026-11-01: every company has its own group of roles, by default
 * Admin Compañía, Helpdesk, Website, Formularios, Customer, Employee
 * (CompanyRoles). Owner / Admin / AdminRG / AdminNE / Admin-ALL -> Admin Compañía,
 * Info -> Formularios (users moved, roles removed); unused Supplier / Sales Agent /
 * Online Store removed (created again on demand); a business's own roles stay.
 * The template (AdminSystem 999) first, so new companies copy the new set.
 * One transaction per company; safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tpl = \App\Models\Setting::TEMPLATE_COMPANY_ID;
        // The template (999) is a soft-deleted companies row: always included, and first.
        $ids = collect([$tpl])->merge(DB::table('companies')->whereNull('deleted_at')->where('id', '!=', $tpl)->pluck('id'))
            ->map(fn ($i) => (int) $i)->values();
        foreach ($ids as $cid) {
            $log = DB::transaction(fn () => CompanyRoles::normalize($cid));
            if ($log) {
                Log::info("standard roles, company $cid: ".implode('; ', $log));
            }
        }
    }

    public function down(): void
    {
        // Not reversible automatically: the production backup taken before the deploy has the old roles.
    }
};
