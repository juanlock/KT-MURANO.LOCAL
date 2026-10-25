<?php

/**
 * Company 3 (New Era): model photos for the tablet catalog's barber and kids services,
 * from Pexels (free for commercial use, no attribution required -- photographers
 * listed in CREDITS.txt anyway). Photos that show the CUT / service, not the
 * barber: hand-written queries per service (back / side of the head, fades,
 * designs, beards close up). 12 per service, never the same photo twice.
 *
 * Saved into files/company_3/portfolio/models/pexels/; the items' photos list
 * (portfolio_catalog_items.photos) of the "Servicios - New Era" catalog is
 * replaced. The API key is NOT stored in the repo -- set it for the run only:
 *   $env:PEXELS_API_KEY = '<key>'; php artisan tinker --execute="require '<abs path>\database\scripts\company3_barber_models_pexels.php';"
 * Optional: $env:PEXELS_ONLY = 'Name A|Name B' redoes only those services.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

$CO = 3;
$PER = 12;
$KEY = getenv('PEXELS_API_KEY');
abort_unless($KEY, 500, 'Set $env:PEXELS_API_KEY for this run.');
$only = array_filter(explode('|', (string) getenv('PEXELS_ONLY')));

// The first query of each service leans to the salon's clientele (user's request,
// 2026-10-25: mostly white customers in the area); the others keep some variety.
$Q = [
    'Barber Hair Design' => ['white man haircut design lines', 'haircut design lines', 'hair tattoo design barber', 'fade haircut design'],
    'Barber Haircut' => ['caucasian man short haircut', 'men haircut back view', 'mens short haircut', 'men hairstyle short'],
    'Beard Treatment' => ['caucasian man groomed beard', 'groomed beard close up', 'well groomed beard'],
    'Beard Trim' => ['caucasian man trimmed beard', 'beard trim close up', 'short beard man'],
    'Fades & Tapers' => ['white man fade haircut', 'taper fade haircut', 'skin fade haircut', 'low fade haircut'],
    'General Barber Service' => ['caucasian man hairstyle', 'man fresh haircut', 'men haircut style'],
    'Haircut & Beard Combo' => ['white man fade haircut beard', 'haircut and beard man', 'beard fade man'],
    'Head Shave' => ['caucasian bald man', 'shaved head man', 'clean shaved head'],
    'Hot Towel Shave' => ['hot towel shave', 'straight razor shave close up', 'shaving foam face razor'],
    // Kids: a style catalog of finished cuts on children (user's example, 2026-10-25: "112 modern haircuts for kids").
    'Kids Barber Cut' => ['blonde toddler boy haircut', 'boy side part haircut', 'little boy fade haircut', 'boy short haircut', 'boy undercut hairstyle', 'kid boy hairstyle'],
    'Kids Haircut' => ['little girl haircut', 'girl bob haircut', 'little girl short hair', 'girl hairstyle kids'],
    'Kids Braids' => ['little girl braids hairstyle', 'girl braided hair', 'kids braids hairstyle'],
    'Line Up / Edge Up' => ['white man line up haircut', 'hairline edge up', 'sharp hairline haircut'],
    'Senior Cut' => ['older white man haircut', 'gray hair man short haircut', 'senior man hairstyle'],
];

$catalog = DB::table('portfolio_catalogs')->where('company_id', $CO)->where('name', 'Servicios - New Era')->whereNull('deleted_at')->first();
abort_unless($catalog, 404, 'New Era catalog not found -- run php artisan portfolio:catalog-demo first.');

$used = [];
$credits = [];
foreach ($Q as $service => $queries) {
    if ($only && ! in_array($service, $only, true)) {
        continue;
    }
    $item = DB::table('portfolio_catalog_items as i')->join('products as p', 'p.id', '=', 'i.product_id')
        ->where('i.catalog_id', $catalog->id)->where('p.name', $service)->first(['i.id']);
    if (! $item) {
        echo "skip $service (not in the catalog)\n";
        continue;
    }
    $picked = [];
    foreach ($queries as $q) {
        if (count($picked) >= $PER) {
            break;
        }
        $res = Http::withHeaders(['Authorization' => $KEY])->timeout(30)
            ->get('https://api.pexels.com/v1/search', ['query' => $q, 'per_page' => 30, 'orientation' => 'portrait']);
        if (! $res->ok()) {
            echo "  query '$q' failed: HTTP {$res->status()}\n";
            continue;
        }
        foreach ($res->json('photos') ?? [] as $ph) {
            if (count($picked) >= $PER) {
                break;
            }
            if (isset($used[$ph['id']])) {
                continue;
            }
            $used[$ph['id']] = true;
            $picked[] = $ph;
        }
        usleep(300000);
    }
    $paths = [];
    foreach ($picked as $ph) {
        $bytes = Http::timeout(60)->get($ph['src']['large'])->body();
        if (strlen($bytes) < 5000) {
            continue;
        }
        $name = Str::slug($service).'-'.$ph['id'].'.jpg';
        $dir = public_path('files/company_'.$CO.'/portfolio/models/pexels');
        @mkdir($dir, 0775, true);
        file_put_contents($dir.'/'.$name, $bytes);
        $paths[] = 'files/company_'.$CO.'/portfolio/models/pexels/'.$name;
        $credits[] = "$name\tPexels License\t{$ph['photographer']}\t{$ph['url']}";
    }
    DB::table('portfolio_catalog_items')->where('id', $item->id)->update(['photos' => json_encode($paths), 'updated_at' => now()]);
    echo "$service: ".count($paths)." photos\n";
}
$file = public_path('files/company_'.$CO.'/portfolio/models/pexels/CREDITS.txt');
$old = is_file($file) ? array_slice(file($file, FILE_IGNORE_NEW_LINES), 2) : [];
$names = array_map(fn ($l) => explode("\t", $l)[0], $credits);
$keep = array_filter($old, fn ($l) => ! in_array(explode("\t", $l)[0], $names, true));
file_put_contents($file, "Model photos for the New Era tablet catalog, from Pexels (free to use, no attribution required).\nfile\tlicense\tphotographer\tsource\n".implode("\n", array_merge($keep, $credits))."\n");
echo "Done. CREDITS: $file\n";
