<?php

use App\Support\CompanyFiles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KcolTech's new logo (user, 2026-11-16). Each old logo is replaced by its new
 * equivalent: the dark square one where the old one was for a dark background
 * (sidebar / documents, the company record, the website), the light wide one on
 * the forms (white page). The old files stay on disk; down() points back to them.
 * Safe to run twice: a company whose logo is already the new one is skipped.
 */
return new class extends Migration
{
    private const DARK = 'kcoltech-oscuro-cuadrado.png';

    private const LIGHT = 'kcoltech-claro.png';

    private const OLD = [
        'settings.logo' => 'files/company_1/logos/96910994CENTER DARK.png',
        'companies.logo' => 'files/company_1/logos/92579364logo8080.png',
        'companies.logo_form' => 'files/company_1/logos/53421603KCOL Tech -V2.jpg',
        'website_settings.logo_path' => 'files/company_1/website/rid3nv-92579364logo8080.png',
    ];

    public function up(): void
    {
        $cid = DB::table('companies')->where('name', 'KcolTech')->whereNull('deleted_at')->value('id');
        if (! $cid) {
            return;
        }
        $current = (string) DB::table('settings')->where('company_id', $cid)->value('logo');
        if (str_contains($current, self::DARK)) {
            return;
        }
        $dark = $this->put($cid, 'logos', self::DARK);
        $light = $this->put($cid, 'logos', self::LIGHT);
        if (! $dark || ! $light) {
            return;
        }

        DB::transaction(function () use ($cid, $dark, $light) {
            DB::table('settings')->where('company_id', $cid)->update(['logo' => $dark]);
            DB::table('companies')->where('id', $cid)->update(['logo' => $dark, 'logo_form' => $light, 'updated_at' => now()]);
            if (Schema::hasTable('website_settings')) {
                $site = $this->put($cid, 'website', self::DARK);
                if ($site) {
                    DB::table('website_settings')->where('company_id', $cid)->update(['logo_path' => $site]);
                }
            }
        });
    }

    public function down(): void
    {
        $cid = DB::table('companies')->where('name', 'KcolTech')->value('id');
        if (! $cid) {
            return;
        }
        $back = fn ($key) => is_file(public_path(self::OLD[$key])) ? self::OLD[$key] : null;
        if ($v = $back('settings.logo')) {
            DB::table('settings')->where('company_id', $cid)->update(['logo' => $v]);
        }
        $c = array_filter(['logo' => $back('companies.logo'), 'logo_form' => $back('companies.logo_form')]);
        if ($c) {
            DB::table('companies')->where('id', $cid)->update($c);
        }
        if (Schema::hasTable('website_settings') && ($v = $back('website_settings.logo_path'))) {
            DB::table('website_settings')->where('company_id', $cid)->update(['logo_path' => $v]);
        }
    }

    private function put(int $cid, string $module, string $name): ?string
    {
        $file = database_path('assets/kcoltech/'.$name);

        return is_file($file) ? CompanyFiles::putBytes($cid, $module, $name, file_get_contents($file)) : null;
    }
};
