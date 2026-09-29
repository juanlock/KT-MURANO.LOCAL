<?php

/**
 * Company 3 (NewEraUnisexSalon) salon / barber service catalog, by Group Name.
 *
 *  - creates the product groups (Barber, Braids, Face Treatments, ... Textured Hair);
 *  - puts the 11 services imported from dbbooking (import_dbbooking_company3.php, which
 *    had no group) into their group -- nothing else about them changes;
 *  - adds every other service of the catalog (+ a few common barber/salon extras) as a
 *    service product: typical US mid-market price, duration in the note ("Duration: N min",
 *    which the public booking page /book reads), brand "Company service", stock rows
 *    (qty 0, not stock-managed) for the company's warehouses.
 *
 * Re-runnable: groups are firstOrCreate'd by name, a service that already exists in the
 * company (same name) is only (re)assigned to its group -- never duplicated or re-priced.
 * Run: php artisan tinker --execute="require '<abs path>\database\scripts\company3_service_catalog.php';"
 */

use App\Support\ProductCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

$CO = 3;
$now = now();

// Group => [category, [service name, price USD, minutes], ...]
// Categories that do not exist yet in company 3 are created.
$CATALOG = [
    'Barber' => ['Barber Mens', [
        ['Barber Haircut', 30, 30], ['Beard Trim', 25, 20], ['Beard Treatment', 30, 30], ['Fades & Tapers', 40, 45],
        ['Barber Hair Design', 15, 15], ['General Barber Service', 25, 30],
        ['Hot Towel Shave', 35, 30], ['Head Shave', 30, 30], ['Line Up / Edge Up', 15, 15],
        ['Haircut & Beard Combo', 50, 45], ['Kids Barber Cut', 22, 30], ['Senior Cut', 22, 30],
    ]],
    'Braids' => ['Braids & Textured Hair', [
        ['Box Braids', 200, 300], ['Cornrows', 70, 90], ['Crochet Braids', 150, 180], ['Braid Design', 30, 30],
        ['Knotless Braids', 250, 360], ['Twists', 150, 180], ['General Braiding Service', 80, 120],
        ['Feed-in Braids', 120, 150], ['Kids Braids', 80, 120],
    ]],
    'Face Treatments' => ['Facials & Skincare', [
        ['Acne Facial', 95, 60], ['Botox (per area)', 350, 30], ['Hydrating Facial', 89, 60], ['Microdermabrasion', 150, 60],
        ['NuFACE Facial', 150, 60], ['Facial Peel', 125, 45], ['General Facial Treatment', 75, 60],
        ['Deep Cleansing Facial', 100, 75], ['Express Facial', 50, 30],
    ]],
    'Hair Removal' => ['Hair Removal', [
        ['Hair Removal Consultation', 0, 15], ['Electrolysis', 60, 30], ['Hair Bleaching', 30, 30],
        ['Laser Hair Removal (per area)', 150, 30], ['Straight Razor Shave', 35, 30], ['Sugaring', 55, 30],
        ['Face Threading', 25, 20], ['Body Waxing', 50, 30], ['General Hair Removal', 40, 30],
        ['Eyebrow Wax', 18, 15], ['Upper Lip Wax', 12, 10], ['Underarm Wax', 20, 15], ['Brazilian Wax', 65, 30],
    ]],
    'Hair Salon' => ['Hair & Beauty', [
        ['Bang Trim', 15, 15], ['Blowout', 50, 45], ['Hair color', 150, 120], ['Hair Salon Consultation', 0, 15],
        ['Faux Locs', 200, 300], ['Haircut', 35, 30], ['Hair Extensions', 400, 180], ['Hair Treatment', 60, 25],
        ['Highlights / Lowlights', 150, 150], ['Wig Installation', 150, 90], ['Kids Haircut', 25, 30],
        ['Loc Retwist', 85, 90], ['Long Haircut', 65, 60], ['Medium Haircut', 55, 45], ["Men's Haircut", 35, 30],
        ['Ombre', 180, 180], ['Relaxer', 90, 120], ['Roller Set', 55, 60], ['Sew-in', 200, 180],
        ['Short Haircut', 45, 45], ['Special Occasion Style', 85, 60], ['Steam Treatment', 45, 30], ['Updo', 85, 60],
        ['Weave', 180, 180], ["Women's Haircut", 60, 60], ['Wrap', 55, 60], ['General Salon Service', 50, 60],
        ['Balayage', 220, 180], ['Toner / Gloss', 60, 45], ['Deep Conditioning', 35, 30], ['Root Touch-up', 85, 90],
        ['Wash & Style', 45, 45], ['Perm', 120, 150],
    ]],
    'Makeup / Lashes / Brows' => ['Make -up', [
        ['Bridal Makeup', 175, 90], ['Brow Lamination', 75, 45], ['Brow Shaping', 25, 20], ['Brow Tint', 20, 15],
        ['Eyelash Extensions (Full Set)', 180, 120], ['Lash Fill', 70, 60], ['Lash Lift and Tint', 90, 60],
        ['Lash Removal', 30, 30], ['Lash Tint', 25, 20], ['Latisse', 150, 15], ['Makeup Application', 80, 60],
        ['Microblading', 450, 150], ['Permanent Makeup', 400, 120], ['Semi-permanent Makeup', 250, 90],
        ['Brow Threading', 18, 15], ['Makeup Trial', 75, 60], ['General Makeup Service', 60, 45],
    ]],
    'Med Spa' => ['Med Spa', [
        ['Medical Acne Treatment', 150, 45], ['Body Contouring', 400, 60], ['Botox (Med Spa)', 400, 30],
        ['Chemical Peel', 200, 45], ['Dermabrasion / Microdermabrasion', 175, 60], ['Dermal Fillers', 700, 60],
        ['Dermaplaning', 90, 45], ['Non-surgical Face Lift', 500, 60], ['Medical Hair Removal', 150, 30],
        ['Hair Replacement (PRP)', 600, 90], ['Juvederm', 700, 45], ['Laser Hair Removal (Med Spa)', 200, 45],
        ['LED Light Therapy', 80, 30], ['Liposuction Consultation', 100, 30], ['Microblading (Med Spa)', 500, 150],
        ['NuFACE Treatment (Med Spa)', 175, 60], ['Permanent Makeup (Med Spa)', 450, 120], ['Skin Rejuvenation', 250, 60],
        ['Scar Removal', 300, 60], ['Sclerotherapy', 350, 45], ['Skin Tightening', 400, 60], ['General Med Spa Service', 150, 60],
    ]],
    'Nails' => ['Nail Care', [
        ['Acrylic Full Set', 55, 75], ['Gel Manicure', 45, 45], ['Classic Manicure', 25, 30], ['Nail Art Design', 80, 120],
        ['Regular Pedicure', 35, 45], ['Luxury Pedicure', 55, 45], ['General Nail Service', 30, 30],
        ['Dip Powder Manicure', 45, 60], ['Acrylic Fill', 40, 60], ['Gel Pedicure', 55, 60],
        ['Gel Polish Removal', 15, 15], ['Polish Change', 15, 15],
    ]],
    'Tanning' => ['Tanning', [
        ['Organic Spray Tan', 55, 30], ['Spray Tan', 45, 30], ['Sunless Bed Session', 30, 20], ['General Tanning Service', 35, 20],
    ]],
    'Tattoo / Piercing' => ['Tattoo & Piercing', [
        ['Body Piercing', 50, 30], ['Body Art Tattoo (per hour)', 150, 60], ['Tattoo Consultation', 0, 30],
        ['Ear Piercing', 30, 15], ['Henna', 40, 30], ['Tattoo Removal (per session)', 200, 30],
        ['General Tattoo / Piercing Service', 80, 60],
    ]],
    'Textured Hair' => ['Braids & Textured Hair', [
        ['Coily Hair Styling', 85, 90], ['Curly Cut', 85, 75], ['General Textured Hair Service', 60, 60],
        ['Hair Keratin', 200, 180], ['Protective Style', 150, 180], ['Silk Press', 85, 90], ['Texturizer', 75, 90],
        ['Textured Hair Treatment', 55, 45], ['Twist Out', 70, 75],
    ]],
];

$stats = ['groups_created' => 0, 'categories_created' => 0, 'grouped_existing' => [], 'services_created' => 0];

DB::transaction(function () use ($CO, $now, $CATALOG, &$stats) {
    $brandId = DB::table('brands')->where('company_id', $CO)->whereNull('deleted_at')->where('name', 'Company service')->value('id');
    $warehouses = DB::table('warehouses')->where('company_id', $CO)->whereNull('deleted_at')->pluck('id');
    $pwCols = Schema::getColumnListing('product_warehouse');

    foreach ($CATALOG as $groupName => [$categoryName, $services]) {
        $groupId = DB::table('product_groups')->where('company_id', $CO)->whereNull('deleted_at')->where('name', $groupName)->value('id');
        if (! $groupId) {
            $groupId = DB::table('product_groups')->insertGetId(['company_id' => $CO, 'name' => $groupName, 'created_at' => $now, 'updated_at' => $now]);
            $stats['groups_created']++;
        }
        $categoryId = DB::table('categories')->where('company_id', $CO)->whereNull('deleted_at')->where('name', $categoryName)->value('id');
        if (! $categoryId) {
            $categoryId = DB::table('categories')->insertGetId([
                'company_id' => $CO, 'name' => $categoryName, 'code' => Str::upper(Str::limit(Str::slug($categoryName, ''), 10, '')),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $stats['categories_created']++;
        }

        foreach ($services as [$name, $price, $minutes]) {
            $existing = DB::table('products')->where('company_id', $CO)->whereNull('deleted_at')->where('name', $name)->first(['id', 'product_group_id']);
            if ($existing) {
                if ((int) $existing->product_group_id !== (int) $groupId) {
                    DB::table('products')->where('id', $existing->id)->update(['product_group_id' => $groupId, 'updated_at' => $now]);
                    $stats['grouped_existing'][] = "$name -> $groupName";
                }
                continue;
            }
            $productId = DB::table('products')->insertGetId([
                'company_id' => $CO, 'product_group_id' => $groupId, 'type' => 'is_service', 'name' => $name,
                'code' => ProductCode::next($CO) ?: Str::upper(Str::random(8)), 'Type_barcode' => 'CODE128',
                'cost' => 0, 'price' => $price, 'wholesale_price' => 0, 'min_price' => 0,
                'category_id' => $categoryId, 'brand_id' => $brandId,
                'unit_id' => null, 'unit_sale_id' => null, 'unit_purchase_id' => null,
                'TaxNet' => 0, 'tax_method' => '1', 'discount' => 0, 'discount_method' => '1',
                'image' => 'no-image.png', 'note' => "Duration: {$minutes} min", 'stock_alert' => 0,
                'is_variant' => 0, 'is_imei' => 0, 'not_selling' => 0, 'is_active' => 1, 'is_featured' => 0,
                'hide_from_online_store' => 0, 'is_returnable' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($warehouses as $wid) {
                $row = ['product_id' => $productId, 'warehouse_id' => $wid, 'qte' => 0, 'manage_stock' => 0, 'created_at' => $now, 'updated_at' => $now];
                DB::table('product_warehouse')->insert(array_intersect_key($row, array_flip($pwCols)));
            }
            $stats['services_created']++;
        }
    }
});

$stats['company3_services_without_group'] = DB::table('products')->where('company_id', $CO)->whereNull('deleted_at')
    ->where('type', 'is_service')->whereNull('product_group_id')->pluck('name');
echo json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
