<?php

/**
 * Company 3: a picture for every service still on "no-image.png", from Pexels
 * (free for commercial use, no attribution required -- the photographer is still
 * credited in the product note). Run AFTER company3_service_catalog.php and
 * company3_recover_service_images.php.
 *
 * The API key is NOT stored anywhere in the repo (.env is tracked in git): pass it
 * for the run only --
 *   $env:PEXELS_API_KEY = '<key>'; php artisan tinker --execute="require '<abs path>\database\scripts\company3_service_images_pexels.php';"
 * Optional: $env:PEXELS_REPLACE = 'Name A|Name B' re-picks those services (next unused result).
 *
 * Each service has a hand-written search query (plain names like "Wrap" or
 * "General Nail Service" return random photos); one landscape photo per service,
 * never the same photo twice. Saved into files/company_3/products/. Re-runnable:
 * services that already have a picture are skipped. Writes a JSON manifest
 * (storage/app/company3_service_images.json) with the photo chosen for each service.
 */

use App\Support\CompanyFiles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

$CO = 3;
$KEY = getenv('PEXELS_API_KEY');
abort_unless($KEY, 500, 'Set $env:PEXELS_API_KEY for this run.');
$replace = array_filter(explode('|', (string) getenv('PEXELS_REPLACE')));

$Q = [
    // Barber
    'Barber Haircut' => 'barber cutting hair', 'Beard Treatment' => 'beard oil barber', 'Barber Hair Design' => 'barber shaved hair design',
    'General Barber Service' => 'barbershop interior chairs', 'Hot Towel Shave' => 'hot towel shave barber', 'Head Shave' => 'head shave razor',
    'Line Up / Edge Up' => 'barber line up trimmer', 'Haircut & Beard Combo' => 'barber beard trim', 'Kids Barber Cut' => 'child haircut barber',
    'Senior Cut' => 'older man haircut barber',
    // Braids
    'Box Braids' => 'box braids hairstyle', 'Cornrows' => 'cornrows braids', 'Crochet Braids' => 'crochet braids hair', 'Braid Design' => 'braided hairstyle design',
    'Knotless Braids' => 'long braids hairstyle', 'Twists' => 'twist braids hairstyle', 'General Braiding Service' => 'braiding hair salon',
    'Feed-in Braids' => 'braids black woman', 'Kids Braids' => 'girl braids hairstyle',
    // Face treatments
    'Acne Facial' => 'acne facial treatment', 'Botox (per area)' => 'botox injection face', 'Microdermabrasion' => 'microdermabrasion facial',
    'NuFACE Facial' => 'microcurrent facial device', 'Facial Peel' => 'facial peel mask', 'General Facial Treatment' => 'facial treatment spa',
    'Deep Cleansing Facial' => 'facial cleansing spa', 'Express Facial' => 'facial mask spa woman',
    // Hair removal
    'Hair Removal Consultation' => 'beauty consultation salon', 'Electrolysis' => 'electrolysis hair removal', 'Hair Bleaching' => 'beauty cream face',
    'Laser Hair Removal (per area)' => 'laser hair removal', 'Straight Razor Shave' => 'straight razor shave', 'Sugaring' => 'sugaring hair removal',
    'Face Threading' => 'face threading', 'Body Waxing' => 'body waxing spa', 'General Hair Removal' => 'waxing hair removal',
    'Eyebrow Wax' => 'eyebrow wax beautician', 'Upper Lip Wax' => 'wax strip face', 'Underarm Wax' => 'waxing treatment spa', 'Brazilian Wax' => 'leg waxing spa',
    // Hair salon
    'Bang Trim' => 'bangs haircut', 'Blowout' => 'blow dry hair salon', 'Hair Salon Consultation' => 'hair stylist consultation',
    'Faux Locs' => 'faux locs hairstyle', 'Hair Extensions' => 'hair extensions salon', 'Highlights / Lowlights' => 'hair highlights foil salon',
    'Wig Installation' => 'wig hair', 'Kids Haircut' => 'kid haircut salon', 'Loc Retwist' => 'dreadlocks hairstyle', 'Long Haircut' => 'long hair haircut salon',
    'Medium Haircut' => 'shoulder length haircut salon', "Men's Haircut" => 'men haircut salon', 'Ombre' => 'ombre hair', 'Relaxer' => 'straight hair treatment salon',
    'Roller Set' => 'hair rollers', 'Sew-in' => 'hair weave stylist', 'Short Haircut' => 'short haircut woman salon', 'Special Occasion Style' => 'wedding hairstyle',
    'Steam Treatment' => 'hair steamer treatment', 'Updo' => 'updo hairstyle', 'Weave' => 'long straight hair salon', "Women's Haircut" => 'woman haircut salon',
    'Wrap' => 'silk hair wrap', 'General Salon Service' => 'hair salon', 'Balayage' => 'balayage hair', 'Toner / Gloss' => 'shiny hair salon',
    'Deep Conditioning' => 'hair mask treatment', 'Root Touch-up' => 'hair dye roots', 'Wash & Style' => 'hair wash salon', 'Perm' => 'curly hair salon stylist',
    // Makeup / lashes / brows
    'Bridal Makeup' => 'bridal makeup', 'Brow Lamination' => 'eyebrow brush brows close up', 'Brow Shaping' => 'eyebrow shaping', 'Brow Tint' => 'eyebrow tint',
    'Eyelash Extensions (Full Set)' => 'eyelash extensions', 'Lash Fill' => 'eyelash extension application', 'Lash Lift and Tint' => 'lash lift',
    'Lash Removal' => 'eyelash technician', 'Lash Tint' => 'eyelashes close up', 'Latisse' => 'long eyelashes', 'Makeup Application' => 'makeup artist applying makeup',
    'Microblading' => 'microblading eyebrows', 'Permanent Makeup' => 'permanent makeup lips', 'Semi-permanent Makeup' => 'eyebrow permanent makeup procedure',
    'Brow Threading' => 'eyebrow threading', 'Makeup Trial' => 'makeup brushes palette', 'General Makeup Service' => 'makeup',
    // Med spa
    'Medical Acne Treatment' => 'dermatologist skin treatment', 'Body Contouring' => 'body contouring', 'Botox (Med Spa)' => 'botox injection',
    'Chemical Peel' => 'chemical peel facial treatment clinic', 'Dermabrasion / Microdermabrasion' => 'dermabrasion', 'Dermal Fillers' => 'lip filler injection',
    'Dermaplaning' => 'dermaplaning', 'Non-surgical Face Lift' => 'face lift treatment', 'Medical Hair Removal' => 'laser treatment clinic',
    'Hair Replacement (PRP)' => 'hair loss treatment', 'Juvederm' => 'dermal filler', 'Laser Hair Removal (Med Spa)' => 'laser hair removal clinic',
    'LED Light Therapy' => 'led light therapy face', 'Liposuction Consultation' => 'cosmetic clinic consultation', 'Microblading (Med Spa)' => 'eyebrow microblading',
    'NuFACE Treatment (Med Spa)' => 'facial massage device', 'Permanent Makeup (Med Spa)' => 'lip blush permanent makeup procedure', 'Skin Rejuvenation' => 'skin rejuvenation',
    'Scar Removal' => 'skin laser treatment', 'Sclerotherapy' => 'leg treatment clinic', 'Skin Tightening' => 'radiofrequency skin treatment',
    'General Med Spa Service' => 'med spa',
    // Nails
    'Acrylic Full Set' => 'acrylic nails', 'Classic Manicure' => 'manicure', 'General Nail Service' => 'nail salon', 'Dip Powder Manicure' => 'dip powder nails',
    'Acrylic Fill' => 'nail technician', 'Gel Pedicure' => 'pedicure nail polish', 'Gel Polish Removal' => 'nail polish removal', 'Polish Change' => 'nail polish',
    'Regular Pedicure' => 'pedicure salon',
    // Tanning
    'Organic Spray Tan' => 'spray tan', 'Spray Tan' => 'spray tanning', 'Sunless Bed Session' => 'tanning salon', 'General Tanning Service' => 'tanned skin',
    // Tattoo / piercing
    'Body Piercing' => 'piercing jewelry', 'Body Art Tattoo (per hour)' => 'tattoo artist', 'Tattoo Consultation' => 'tattoo design sketch',
    'Ear Piercing' => 'ear piercing', 'Henna' => 'henna hand', 'Tattoo Removal (per session)' => 'tattoo removal laser', 'General Tattoo / Piercing Service' => 'tattoo studio',
    // Textured hair
    'Coily Hair Styling' => 'coily natural hair', 'Curly Cut' => 'curly hair cut', 'General Textured Hair Service' => 'natural hair salon',
    'Protective Style' => 'protective hairstyle braids', 'Silk Press' => 'silk press natural hair', 'Texturizer' => 'afro hair',
    'Textured Hair Treatment' => 'natural hair care', 'Twist Out' => 'twist out natural hair',
];

$manifestPath = storage_path('app/company3_service_images.json');
$manifest = is_file($manifestPath) ? (json_decode(file_get_contents($manifestPath), true) ?: []) : [];
$used = array_column($manifest, 'pexels_id');
$stats = ['added' => 0, 'replaced' => 0, 'skipped_has_image' => 0, 'no_result' => []];

$products = DB::table('products')->where('company_id', $CO)->whereNull('deleted_at')->where('type', 'is_service')
    ->orderBy('name')->get(['id', 'name', 'image', 'note']);

foreach ($products as $p) {
    $isReplace = in_array($p->name, $replace, true);
    if (! $isReplace && $p->image && $p->image !== 'no-image.png') {
        $stats['skipped_has_image']++;
        continue;
    }
    $query = $Q[$p->name] ?? trim(preg_replace('/\s*\(.*?\)/', '', $p->name)).' salon';
    $res = Http::timeout(30)->withHeaders(['Authorization' => $KEY])
        ->get('https://api.pexels.com/v1/search', ['query' => $query, 'orientation' => 'landscape', 'per_page' => 15]);
    if (! $res->ok()) {
        $stats['no_result'][] = "{$p->name} (HTTP {$res->status()})";
        continue;
    }
    $photo = collect($res->json('photos') ?? [])->first(fn ($ph) => ! in_array($ph['id'], $used, true));
    if (! $photo) {
        $stats['no_result'][] = "{$p->name} (\"$query\")";
        continue;
    }
    $img = Http::timeout(60)->get($photo['src']['large']);
    if (! $img->ok()) {
        $stats['no_result'][] = "{$p->name} (download HTTP {$img->status()})";
        continue;
    }
    $dir = CompanyFiles::dir($CO, 'products');
    $file = Str::limit(Str::slug($p->name), 50, '').'-'.Str::lower(Str::random(6)).'.jpg';
    file_put_contents(public_path($dir.'/'.$file), $img->body());

    $credit = 'Photo: '.$photo['photographer'].' / Pexels';
    $note = trim(preg_replace('/^Photo: .* \/ Pexels$/m', '', (string) $p->note))."\n".$credit;
    $old = $p->image;
    DB::table('products')->where('id', $p->id)->update(['image' => $dir.'/'.$file, 'note' => trim($note), 'updated_at' => now()]);
    // A replaced picture is only deleted when it was one of ours (Pexels-picked, under the company folder).
    if ($isReplace && $old && isset($manifest[$p->name]) && str_starts_with($old, $dir.'/') && is_file(public_path($old))) {
        @unlink(public_path($old));
    }

    $used[] = $photo['id'];
    $manifest[$p->name] = ['pexels_id' => $photo['id'], 'query' => $query, 'alt' => $photo['alt'] ?? '', 'photographer' => $photo['photographer'],
        'pexels_url' => $photo['url'], 'image' => $dir.'/'.$file];
    $isReplace ? $stats['replaced']++ : $stats['added']++;
    usleep(150000);
}

file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
$stats['services_without_image'] = DB::table('products')->where('company_id', $CO)->whereNull('deleted_at')->where('type', 'is_service')
    ->where(fn ($q) => $q->whereNull('image')->orWhere('image', 'no-image.png'))->pluck('name');
echo json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
