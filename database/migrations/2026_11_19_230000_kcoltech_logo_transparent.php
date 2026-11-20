<?php

use App\Support\CompanyFiles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * KcolTech's square logo without its dark background (user, 2026-11-19): transparent background, the white text kept white
 * (the logo is made for dark backgrounds, user's correction) and the icon's edges unmixed. Made from
 * kcoltech-oscuro-cuadrado.png; the file travels in database/assets/kcoltech. settings.logo and companies.logo point to it;
 * the website keeps its own logo. Safe to run twice; down() points back to the dark square one.
 */
return new class extends Migration
{
    private const NAME = 'kcoltech-cuadrado-transparente.png';

    public function up(): void
    {
        $cid = DB::table('companies')->where('name', 'KcolTech')->whereNull('deleted_at')->value('id');
        $file = database_path('assets/kcoltech/'.self::NAME);
        if (! $cid || ! is_file($file) || str_contains((string) DB::table('settings')->where('company_id', $cid)->value('logo'), self::NAME)) {
            return;
        }
        $path = CompanyFiles::putBytes($cid, 'logos', self::NAME, file_get_contents($file));
        DB::transaction(function () use ($cid, $path) {
            DB::table('settings')->where('company_id', $cid)->update(['logo' => $path]);
            DB::table('companies')->where('id', $cid)->update(['logo' => $path, 'updated_at' => now()]);
        });
    }

    public function down(): void
    {
        $cid = DB::table('companies')->where('name', 'KcolTech')->value('id');
        $dark = $cid ? DB::table('companies')->where('id', $cid)->value('logo_form') : null; // fallback only
        $old = collect(glob(public_path('files/company_'.$cid.'/logos/*/*/*-kcoltech-oscuro-cuadrado.png')) ?: [])->sort()->last();
        $rel = $old ? 'files/'.str_replace('\\', '/', substr($old, strlen(public_path('files')) + 1)) : $dark;
        if ($cid && $rel) {
            DB::table('settings')->where('company_id', $cid)->update(['logo' => $rel]);
            DB::table('companies')->where('id', $cid)->update(['logo' => $rel]);
        }
    }
};
