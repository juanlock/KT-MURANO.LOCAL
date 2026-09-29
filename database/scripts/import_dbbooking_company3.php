<?php

/**
 * One-off import: NewEra's booking site (database `dbbooking`, NEWERABOOKING.LOCAL)
 * -> Murano company 3 (NewEraUnisexSalon).
 *
 *   spa_booking_service_categories -> categories        (company 3)
 *   spa_booking_services           -> products, type is_service, brand "Company service",
 *                                     code from the company's product-code sequence,
 *                                     picture copied into files/company_3/products/,
 *                                     stock rows (qty 0, not stock-managed) for company 3's warehouses
 *   spa_booking_customers          -> clients           (company 3; all rows, vendors included)
 *   spa_booking_staff              -> employees         (company 3, department "Salon",
 *                                     one designation per staff title, the company's office shift)
 *
 * Re-runnable: anything already imported (same name / email in company 3) is skipped.
 * The booking site has no Group Name -> run company3_service_catalog.php afterwards: it
 * puts these services into their product group and adds the rest of the salon catalog.
 * Run: php artisan tinker --execute="require '<abs path>\database\scripts\import_dbbooking_company3.php';"
 */

use App\Support\CompanyFiles;
use App\Support\ProductCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

$CO = 3;
$SRC = 'dbbooking';
$SRC_PUBLIC = 'D:/WEBSITES-LOCAL/NEWERABOOKING.LOCAL/public';
$now = now();
$clean = fn ($s) => trim(html_entity_decode((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
$stats = ['categories' => 0, 'services' => 0, 'images' => 0, 'customers' => 0, 'employees' => 0, 'designations' => 0, 'skipped' => 0, 'missing_images' => []];

abort_unless(DB::table('companies')->where('id', $CO)->exists(), 500, 'Company 3 not found');

// Find a source picture: public/storage/<path>, public/<path>, or the theme's image folders.
$findImage = function (?string $rel) use ($SRC_PUBLIC) {
    if (! $rel) {
        return null;
    }
    foreach (["storage/$rel", $rel, 'themes/velura/images/resource/'.basename($rel), 'storage/'.basename($rel)] as $cand) {
        $p = $SRC_PUBLIC.'/'.$cand;
        if (is_file($p)) {
            return $p;
        }
    }

    return null;
};
$copyImage = function (?string $rel, string $module) use ($findImage, $CO, &$stats) {
    $src = $findImage($rel);
    if (! $src) {
        if ($rel) {
            $stats['missing_images'][] = $rel;
        }

        return null;
    }
    $dir = CompanyFiles::dir($CO, $module);
    $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION)) ?: 'jpg';
    $name = Str::limit(Str::slug(pathinfo($src, PATHINFO_FILENAME)) ?: 'image', 50, '').'-'.Str::lower(Str::random(6)).'.'.$ext;
    copy($src, public_path($dir.'/'.$name));
    $stats['images']++;

    return $dir.'/'.$name;
};

DB::transaction(function () use ($CO, $SRC, $now, $clean, $copyImage, &$stats) {
    // ---------------- categories ----------------
    $catMap = [];
    foreach (DB::select("SELECT * FROM {$SRC}.spa_booking_service_categories ORDER BY `order`, id") as $c) {
        $name = $clean($c->name);
        $id = DB::table('categories')->where('company_id', $CO)->whereNull('deleted_at')->where('name', $name)->value('id');
        if (! $id) {
            $id = DB::table('categories')->insertGetId([
                'company_id' => $CO, 'name' => $name, 'code' => Str::upper(Str::limit(Str::slug($name, ''), 10, '')),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $stats['categories']++;
        }
        $catMap[$c->id] = $id;
    }

    // ---------------- services -> products ----------------
    $brandId = DB::table('brands')->where('company_id', $CO)->whereNull('deleted_at')->where('name', 'Company service')->value('id');
    $warehouses = DB::table('warehouses')->where('company_id', $CO)->whereNull('deleted_at')->pluck('id');
    $pwCols = Schema::getColumnListing('product_warehouse');
    foreach (DB::select("SELECT * FROM {$SRC}.spa_booking_services ORDER BY `order`, id") as $s) {
        $name = $clean($s->name);
        if (DB::table('products')->where('company_id', $CO)->whereNull('deleted_at')->where('name', $name)->exists()) {
            $stats['skipped']++;
            continue;
        }
        $price = ($s->sale_price !== null && (float) $s->sale_price > 0) ? (float) $s->sale_price : (float) $s->price;
        $note = trim($clean($s->description)
            ."\nDuration: ".(int) $s->duration_minutes.' min'
            .($s->highlights ? "\n".str_replace("\r", '', $clean($s->highlights)) : ''));
        $productId = DB::table('products')->insertGetId([
            'company_id' => $CO, 'type' => 'is_service', 'name' => $name,
            'code' => ProductCode::next($CO) ?: Str::upper(Str::random(8)), 'Type_barcode' => 'CODE128',
            'cost' => 0, 'price' => $price, 'wholesale_price' => 0, 'min_price' => 0,
            'category_id' => $catMap[$s->category_id] ?? null, 'brand_id' => $brandId,
            'unit_id' => null, 'unit_sale_id' => null, 'unit_purchase_id' => null,
            'TaxNet' => 0, 'tax_method' => '1', 'discount' => 0, 'discount_method' => '1',
            'image' => $copyImage($s->image, 'products') ?: 'no-image.png',
            'note' => Str::limit($note, 60000, ''), 'stock_alert' => 0,
            'is_variant' => 0, 'is_imei' => 0, 'not_selling' => 0,
            'is_active' => $s->status === 'published' ? 1 : 0, 'is_featured' => (int) $s->is_featured,
            'hide_from_online_store' => 0, 'is_returnable' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ($warehouses as $wid) {
            $row = ['product_id' => $productId, 'warehouse_id' => $wid, 'qte' => 0, 'manage_stock' => 0, 'created_at' => $now, 'updated_at' => $now];
            DB::table('product_warehouse')->insert(array_intersect_key($row, array_flip($pwCols)));
        }
        $stats['services']++;
    }

    // ---------------- customers -> clients ----------------
    $code = (int) DB::table('clients')->max('code');
    foreach (DB::select("SELECT * FROM {$SRC}.spa_booking_customers ORDER BY id") as $u) {
        $email = strtolower(trim((string) $u->email));
        if ($email && DB::table('clients')->where('company_id', $CO)->whereNull('deleted_at')->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            $stats['skipped']++;
            continue;
        }
        $name = $clean($u->name);
        $isPerson = ! $u->is_vendor || $u->customer_type === 'individual';
        [$first, $last] = $isPerson && str_contains($name, ' ') ? explode(' ', $name, 2) : [$name, null];
        DB::table('clients')->insert([
            'company_id' => $CO, 'name' => $name, 'firstname' => $first, 'lastname' => $last,
            'code' => ++$code, 'email' => $email ?: null, 'phone' => $u->phone,
            'country' => 'United States', 'opening_balance' => 0, 'credit_limit' => 0, 'points' => 0, 'is_royalty_eligible' => 0,
            'notes' => 'Imported from the booking site (dbbooking #'.$u->id.($u->is_vendor ? ', vendor' : '').($u->status !== 'activated' ? ', '.$u->status : '').').',
            'created_at' => $u->created_at ?: $now, 'updated_at' => $now,
        ]);
        $stats['customers']++;
    }

    // ---------------- staff -> employees ----------------
    $deptId = DB::table('departments')->where('company_id', $CO)->whereNull('deleted_at')->orderBy('id')->value('id')
        ?: DB::table('departments')->insertGetId(['company_id' => $CO, 'department' => 'Salon', 'created_at' => $now, 'updated_at' => $now]);
    $shiftId = DB::table('office_shifts')->where('company_id', $CO)->whereNull('deleted_at')->orderBy('id')->value('id');
    foreach (DB::select("SELECT * FROM {$SRC}.spa_booking_staff ORDER BY `order`, id") as $st) {
        $name = $clean($st->name);
        [$first, $last] = str_contains($name, ' ') ? explode(' ', $name, 2) : [$name, ''];
        if (DB::table('employees')->where('company_id', $CO)->whereNull('deleted_at')->where('firstname', $first)->where('lastname', $last)->exists()) {
            $stats['skipped']++;
            continue;
        }
        $title = $clean($st->title) ?: 'Stylist';
        $desId = DB::table('designations')->where('company_id', $CO)->whereNull('deleted_at')->where('designation', $title)->value('id');
        if (! $desId) {
            $desId = DB::table('designations')->insertGetId(['company_id' => $CO, 'department_id' => $deptId, 'designation' => $title, 'created_at' => $now, 'updated_at' => $now]);
            $stats['designations']++;
        }
        $social = json_decode((string) $st->social_links, true) ?: [];
        DB::table('employees')->insert([
            'company_id' => $CO, 'firstname' => $first, 'lastname' => $last,
            'username' => Str::limit(Str::slug($name, ''), 60, '').$CO,
            'gender' => 'other', 'country' => 'United States',
            'avatar' => ($p = $copyImage($st->image, 'employees')) ? $p : 'no_avatar.png',
            'joining_date' => substr((string) ($st->created_at ?: $now), 0, 10),
            'department_id' => $deptId, 'designation_id' => $desId, 'office_shift_id' => $shiftId,
            'facebook' => $social['facebook'] ?? null, 'twitter' => $social['twitter'] ?? null, 'linkedin' => $social['linkedin'] ?? null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $stats['employees']++;
    }
});

echo json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
