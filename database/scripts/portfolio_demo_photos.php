<?php

/**
 * Builds the Portfolio demo photo library: resources/demo/portfolio/{set}/*.jpg
 * + credits.json, from App\Services\DemoData\PortfolioDemoCatalog. Run once on a
 * dev machine (the result is committed; Demo Data only copies those files):
 *   php artisan tinker --execute="require '<abs path>\database\scripts\portfolio_demo_photos.php';"
 *
 *  ov:<set>:<n>   downloaded from Openverse (CC0 photos, picks in data/portfolio_demo_openverse.json)
 *  pexels:<name>  copied from the Pexels photos already in public/ (storage/app/company3_service_images.json)
 * Every photo is resized to max 1600 px, JPEG 80. Existing files are kept (re-runnable).
 */

use App\Services\DemoData\PortfolioDemoCatalog;
use Illuminate\Support\Facades\Http;

$base = resource_path('demo/portfolio');
$ov = json_decode(file_get_contents(database_path('scripts/data/portfolio_demo_openverse.json')), true);
$pexelsFile = storage_path('app/company3_service_images.json');
$pexels = is_file($pexelsFile) ? (json_decode(file_get_contents($pexelsFile), true) ?: []) : [];
$creditsFile = $base.'/credits.json';
$credits = is_file($creditsFile) ? (json_decode(file_get_contents($creditsFile), true) ?: []) : [];
$done = $skipped = 0;
$errors = [];

foreach (PortfolioDemoCatalog::SETS as $set => $data) {
    if (! is_dir("$base/$set")) {
        mkdir("$base/$set", 0755, true);
    }
    foreach ($data['works'] as [$title, , , , , $photos]) {
        foreach ($photos as [$file, $from]) {
            $dest = "$base/$set/$file";
            if (is_file($dest)) {
                $skipped++;
                continue;
            }
            try {
                [$kind, $a, $b] = array_pad(explode(':', $from, 3), 3, null);
                if ($kind === 'ov') {
                    $pick = collect($ov[$a] ?? [])->firstWhere('n', (int) $b);
                    throw_unless($pick, new RuntimeException("no Openverse pick $from"));
                    $res = Http::timeout(60)->withHeaders(['User-Agent' => 'KT-Murano demo builder'])->get($pick['url']);
                    throw_unless($res->successful(), new RuntimeException("download {$res->status()} {$pick['url']}"));
                    $bytes = $res->body();
                    $credit = ['source' => 'Openverse / '.$pick['source'], 'license' => strtoupper($pick['license']), 'creator' => $pick['creator'], 'title' => $pick['title'], 'page' => $pick['landing'], 'url' => $pick['url']];
                } else {
                    $name = substr($from, 7);
                    $p = $pexels[$name] ?? null;
                    throw_unless($p && is_file(public_path($p['image'])), new RuntimeException("no local Pexels photo for $name"));
                    $bytes = file_get_contents(public_path($p['image']));
                    $credit = ['source' => 'Pexels', 'license' => 'Pexels license', 'creator' => $p['photographer'], 'title' => $p['alt'], 'page' => $p['pexels_url']];
                }
                $img = \Intervention\Image\ImageManagerStatic::make($bytes);
                $img->resize(1600, 1600, function ($c) {
                    $c->aspectRatio();
                    $c->upsize();
                })->save($dest, 80, 'jpg');
                $credits["$set/$file"] = $credit + ['work' => $title];
                $done++;
            } catch (Throwable $e) {
                $errors[] = "$set/$file: ".$e->getMessage();
            }
        }
    }
}
ksort($credits);
file_put_contents($creditsFile, json_encode($credits, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
$size = 0;
foreach (glob("$base/*/*.jpg") as $f) {
    $size += filesize($f);
}
echo "photos written: $done, kept: $skipped, total: ".count(glob("$base/*/*.jpg")).' ('.round($size / 1048576, 1)." MB)\n";
echo $errors ? "ERRORS:\n  ".implode("\n  ", $errors)."\n" : "no errors\n";
