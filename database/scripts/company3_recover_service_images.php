<?php

/**
 * Company 3: recover the pictures of the services imported from dbbooking whose image file
 * was missing locally at import time (import_dbbooking_company3.php left them "no-image.png").
 * The originals are still served by the live booking site at
 * https://booking.neweraunisexsalon.com/storage/<path> -- downloaded into files/company_3/products/.
 * The source's "logo300x241.png" (Regular Pedicure) is the salon logo, not a service photo: skipped.
 * Re-runnable: only products still on no-image.png are touched.
 */

use App\Support\CompanyFiles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

$CO = 3;
$BASE = 'https://booking.neweraunisexsalon.com/storage/';
$clean = fn ($s) => trim(html_entity_decode((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
$stats = ['recovered' => [], 'skipped' => [], 'failed' => []];

foreach (DB::select('SELECT name, image FROM dbbooking.spa_booking_services WHERE image IS NOT NULL') as $s) {
    $name = $clean($s->name);
    $p = DB::table('products')->where('company_id', $CO)->whereNull('deleted_at')->where('name', $name)->first(['id', 'image']);
    if (! $p || ($p->image && $p->image !== 'no-image.png')) {
        continue;
    }
    if (str_contains($s->image, 'logo')) {
        $stats['skipped'][] = "$name ({$s->image}: logo, not a photo)";
        continue;
    }
    $res = Http::timeout(30)->withHeaders(['User-Agent' => 'Mozilla/5.0'])->get($BASE.ltrim($s->image, '/'));
    if (! $res->ok() || ! str_starts_with((string) $res->header('Content-Type'), 'image/')) {
        $stats['failed'][] = "$name ({$s->image}: HTTP {$res->status()})";
        continue;
    }
    $dir = CompanyFiles::dir($CO, 'products');
    $ext = strtolower(pathinfo($s->image, PATHINFO_EXTENSION)) ?: 'jpg';
    $file = Str::limit(Str::slug(pathinfo($s->image, PATHINFO_FILENAME)) ?: 'image', 50, '').'-'.Str::lower(Str::random(6)).'.'.$ext;
    file_put_contents(public_path($dir.'/'.$file), $res->body());
    DB::table('products')->where('id', $p->id)->update(['image' => $dir.'/'.$file, 'updated_at' => now()]);
    $stats['recovered'][] = "$name -> $dir/$file (".round(strlen($res->body()) / 1024).' KB)';
}

echo json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
