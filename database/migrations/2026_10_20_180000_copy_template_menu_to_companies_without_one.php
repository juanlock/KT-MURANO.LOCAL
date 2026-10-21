<?php

use App\Services\CompanyProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Companies with no menu layout (2026-10-20): the companies created on a
 * developer PC on 2026-10-19 / 20 reached production through the old data
 * push, which never sent menu_positions -- so in production they have no
 * layout and their menu showed the factory order with a stale top-level order
 * ("super desordenado"; e.g. Colegio Bicentenario). Each company without any
 * menu_positions gets AdminSystem's (template company 999), like a new company
 * does (CompanyProvisioner::copyTemplateMenu), and AdminSystem's top-level
 * order. Companies that have their own layout are not touched. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('menu_positions')) {
            return;
        }
        $template = \App\Models\Setting::TEMPLATE_COMPANY_ID;
        if (! DB::table('menu_positions')->where('company_id', $template)->exists()) {
            return;
        }
        $order = DB::table('settings')->where('company_id', $template)->whereNull('deleted_at')->value('sidebar_menu_order');
        $ids = DB::table('companies')->whereNull('deleted_at')->where('id', '!=', $template)
            ->whereNotIn('id', DB::table('menu_positions')->select('company_id')->distinct())->pluck('id');
        foreach ($ids as $cid) {
            DB::transaction(function () use ($cid, $order) {
                CompanyProvisioner::copyTemplateMenu((int) $cid);
                DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->update(['sidebar_menu_order' => $order, 'updated_at' => now()]);
            });
        }
    }

    public function down(): void
    {
        // Menu layouts stay (Manage Menu -> Reset clears one).
    }
};
