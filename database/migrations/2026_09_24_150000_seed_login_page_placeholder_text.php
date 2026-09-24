<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\Setting;

/**
 * Copies the hardcoded fallback text from resources/views/auth/login.blade.php
 * into the shared template company's (999) Setting row, so the Login Page
 * Settings form shows real, editable starting values instead of blank
 * fields backed only by an invisible fallback. Guarded per-field (only fills
 * an empty one) so it never overwrites anything a SuperAdmin has already set.
 *
 * login_footer_text is deliberately NOT seeded here -- its fallback splices
 * in the CURRENT year dynamically ('© {date('Y')} ...'); copying it verbatim
 * would freeze that year instead of letting it keep auto-updating.
 * app_name is also left alone -- "Stocky | Ultimate Inventory With POS" is a
 * branding decision, not placeholder copy, and wasn't part of what was asked
 * to be copied here.
 */
return new class extends Migration
{
    public function up(): void
    {
        $defaults = [
            'login_hero_badge' => 'Secure & Reliable',
            'login_hero_title' => 'Manage your business smarter.',
            'login_hero_subtitle' => 'Streamline inventory, track sales, and grow your business — all from one powerful dashboard.',
            'login_hero_feature_1' => 'Real-time inventory tracking',
            'login_hero_feature_2' => 'Multi-location POS support',
            'login_hero_feature_3' => 'Advanced reporting & analytics',
            'login_panel_title' => 'Welcome back',
            'login_panel_subtitle' => 'Sign in to your account to continue',
            'login_btn_text' => 'Sign in',
        ];

        $setting = Setting::forCompany(Setting::TEMPLATE_COMPANY_ID);
        $updates = [];
        foreach ($defaults as $field => $value) {
            if (empty($setting->$field)) {
                $updates[$field] = $value;
            }
        }
        if ($updates) {
            $setting->update($updates);
        }
    }

    public function down(): void
    {
        // Not reversible -- these are now legitimate saved values, not test
        // data, and "down" can't distinguish them from a later deliberate edit.
    }
};
