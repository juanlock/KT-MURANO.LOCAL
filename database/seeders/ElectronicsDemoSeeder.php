<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Client;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductReview;
use App\Models\StoreSetting;
use App\Models\SubCategory;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\product_warehouse;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Demo catalog for the Electronics storefront theme: 9 categories with
 * subcategories, 14 brands, ~80 products (phones, laptops, tablets, audio,
 * gaming, cameras, smart home, accessories, PC components) with Unsplash
 * photo LINKS (no files on disk), stock in every warehouse, a set of approved
 * reviews (drives ratings / Best Rated / testimonials), and the theme itself
 * switched on with matching hero slides and promo banners.
 *
 * Run:  php artisan db:seed --class=ElectronicsDemoSeeder
 *
 * Idempotent: products carry EL- codes and are skipped when present.
 */
class ElectronicsDemoSeeder extends Seeder
{
    /** Unsplash photo ids (validated). Referenced by index below. */
    private const IMG = [
        1 => '1511707171634-5f897ff02aa9', 2 => '1517336714731-489689fd1ca8', 3 => '1496181133206-80ce9b88a853', 4 => '1505740420928-5e560c06d30e',
        5 => '1523275335684-37898b6baf30', 6 => '1546868871-7041f2a55e12', 7 => '1606813907291-d86efa9b94db', 8 => '1526170375885-4d8ecf77b99f',
        9 => '1516035069371-29a1b244cc32', 10 => '1502920917128-1aa500764cbd', 11 => '1544244015-0df4b3ffc6b0', 12 => '1593642632823-8f785ba67e45',
        13 => '1588872657578-7efd1f1555ed', 14 => '1542751371-adc38448a05e', 15 => '1587202372775-e229f172b9d7', 16 => '1518770660439-4636190af475',
        17 => '1592750475338-74b7b21085ab', 18 => '1574944985070-8f3ebc6b79d2', 19 => '1598327105666-5b89351aff97', 20 => '1610945415295-d9bbf067e59c',
        21 => '1583394838336-acd977736f90', 22 => '1546435770-a3e426bf472b', 23 => '1572569511254-d8f925fe2cbb', 24 => '1608043152269-423dbba4e7e1',
        25 => '1527864550417-7fd91fc51a46', 26 => '1615663245857-ac93bb7c39e7', 27 => '1527443224154-c4a3942d3acf', 28 => '1541807084-5c52b6b3adef',
        29 => '1611186871348-b1ce696e52c9', 30 => '1592286927505-1def25115558', 31 => '1543512214-318c7553f230', 32 => '1473968512647-3e447244af8f',
        33 => '1507582020474-9a35b7d455d9', 34 => '1508614589041-895b88991e3e', 35 => '1579586337278-3befd40fd17a', 36 => '1434493789847-2f02dc6ca35d',
        37 => '1603302576837-37561b2e2302', 38 => '1593640408182-31c70c8268f5', 39 => '1625842268584-8f3296236761', 40 => '1593359677879-a4bb92f829d1',
        41 => '1567690187548-f07b1d7bf5a9', 42 => '1600494603989-9650cf6ddd3d', 43 => '1622297845775-5ff3fef71d13', 44 => '1605901309584-818e25960a8f',
        45 => '1578303512597-81e6cc155b3e', 46 => '1621259182978-fbf93132d53d', 47 => '1558618666-fcd25c85cd64', 48 => '1558002038-1055907df827',
        49 => '1585771724684-38269d6639fd', 50 => '1512499617640-c74ae3a79d37', 51 => '1523206489230-c012c64b2b48', 52 => '1556656793-08538906a9f8',
        53 => '1587033411391-5d9e51cce126', 54 => '1625948515291-69613efd103f', 55 => '1618410320928-25228d811631', 56 => '1484704849700-f032a568e944',
        57 => '1524678606370-a47ad25cb82a', 58 => '1545127398-14699f92334b', 59 => '1560343090-f0409e92791a', 60 => '1491933382434-500287f9b54b',
        61 => '1585790050230-5dd28404ccb9', 62 => '1600003263720-95b45a4035d5', 63 => '1531297484001-80022131f5a1', 64 => '1498049794561-7780e7231661',
        65 => '1491975474562-1f4e30bc9468', 66 => '1601784551446-20c9e07cdbdb', 67 => '1618384887929-16ec33fab9ef', 68 => '1580910051074-3eb694886505',
        69 => '1604671801908-6f0c6a092c05', 70 => '1595225476474-87563907a212', 71 => '1616348436168-de43ad0db179', 72 => '1580894732444-8ecded7900cd',
        73 => '1614624532983-4ce03382d63d', 74 => '1573739022854-abceaeb585dc', 75 => '1555617766-c94804975da3', 76 => '1591488320449-011701bb6704',
        77 => '1519389950473-47ba0277781c', 78 => '1550745165-9bc0b252726f', 79 => '1563770660941-20978e870e26', 80 => '1611472173362-3f53dbd65d80',
        81 => '1526738549149-8e07eca6c147', 82 => '1585298723682-7115561c51b7', 83 => '1526406915894-7bcd65f60845', 84 => '1548484352-ea579e5233a8',
        85 => '1512428559087-560fa5ceab42', 86 => '1553406830-ef2513450d76', 87 => '1519085360753-af0119f7cbe7', 88 => '1531973576160-7125cd663d86',
        89 => '1530893609608-32a9af3aa95c', 90 => '1520045892732-304bc3ac5d8e', 91 => '1523170335258-f5ed11844a49', 92 => '1614680376593-902f74cf0d41',
        93 => '1595941069915-4ebc5197c14a', 94 => '1602080858428-57174f9431cf', 95 => '1562408590-e32931084e23', 96 => '1586953208448-b95a79798f07',
        97 => '1547394765-185e1e68f34e', 98 => '1541140532154-b024d705b90a', 99 => '1555421689-491a97ff2040', 100 => '1585155770447-2f66e2a397b5',
        101 => '1588508065123-287b28e013da', 102 => '1597872200969-2b65d56bd16b', 103 => '1524592094714-0f0654e20314', 104 => '1519183071298-a2962feb14f4',
        105 => '1609081219090-a6d81d3085bf', 106 => '1605236453806-6ff36851218e', 107 => '1550029402-226115b7c579', 108 => '1588702547923-7093a6c3ba33',
        109 => '1581591524425-c7e0978865fc', 110 => '1608156639585-b3a032ef9689', 111 => '1631729371254-42c2892f0e6e', 112 => '1596727147705-61a532a659bd',
        113 => '1583863788434-e58a36330cf0', 114 => '1571380401583-72ca84994796', 115 => '1631867675167-90a456a90863', 116 => '1598550476439-6847785fcea6',
        117 => '1598928506311-c55ded91a20c', 118 => '1547082299-de196ea013d6', 119 => '1591370874773-6702e8f12fd8', 120 => '1577375729152-4c8b5fcda381',
        121 => '1606400082777-ef05f3c5cde2', 122 => '1593508512255-86ab42a8e620', 123 => '1560393464-5c69a73c5770', 124 => '1609091839311-d5365f9ff1c5',
        125 => '1543487945-139a97f387d5', 126 => '1567581935884-3349723552ca', 127 => '1543163521-1bf539c55dd2', 128 => '1512054502232-10a0a035d672',
        129 => '1556740758-90de374c12ad', 130 => '1614064641938-3bbee52942c7', 131 => '1517059224940-d4af9eec41b7', 132 => '1563206767-5b18f218e8de',
        133 => '1509395176047-4a66953fd231', 134 => '1519996529931-28324d5a630e', 135 => '1621768216002-5ac171876625', 136 => '1585386959984-a4155224a1ad',
        137 => '1555664424-778a1e5e1b48', 138 => '1601524909162-ae8725290836', 139 => '1591405351990-4726e331f141', 140 => '1502877338535-766e1452684a',
    ];

    private static function img(int $i, int $w = 900): string
    {
        return 'https://images.unsplash.com/photo-'.self::IMG[$i].'?w='.$w.'&q=80&auto=format&fit=crop';
    }

    public function run(): void
    {
        if (Product::where('code', 'like', 'EL-%')->exists()) {
            $this->command?->warn('Electronics demo products already present — nothing to do. (Delete EL-* products to reseed.)');
            $this->activateTheme();

            return;
        }

        DB::transaction(function () {
            $unit = Unit::firstOrCreate(
                ['ShortName' => 'pc'],
                ['name' => 'Piece', 'base_unit' => null, 'operator' => '*', 'operator_value' => 1, 'is_active' => 1]
            );
            $warehouses = Warehouse::all();
            if ($warehouses->isEmpty()) {
                $warehouses = collect([Warehouse::create(['name' => 'Main Warehouse', 'city' => 'Main', 'country' => '', 'mobile' => '', 'email' => '', 'zip' => ''])]);
            }

            /* ------------------------------------------------ categories */
            $catDefs = [
                'Smartphones' => ['icon' => 'smartphone', 'subs' => ['Apple iPhone', 'Samsung Galaxy', 'Android Phones', 'Refurbished']],
                'Laptops' => ['icon' => 'laptop', 'subs' => ['MacBook', 'Ultrabooks', 'Gaming Laptops', 'Desktops & All-in-One']],
                'Tablets' => ['icon' => 'tablet', 'subs' => ['iPad', 'Android Tablets']],
                'Audio' => ['icon' => 'headphones', 'subs' => ['Headphones', 'Earbuds', 'Speakers']],
                'Gaming' => ['icon' => 'gamepad', 'subs' => ['Consoles', 'Gaming PCs', 'Controllers & Peripherals', 'VR']],
                'Cameras' => ['icon' => 'camera', 'subs' => ['Mirrorless & DSLR', 'Instant Cameras', 'Drones']],
                'Smart Home' => ['icon' => 'home-smart', 'subs' => ['Smart Speakers', 'Smart TVs', 'Security']],
                'Accessories' => ['icon' => 'watch', 'subs' => ['Wearables', 'Keyboards & Mice', 'Chargers & Cables', 'Cases']],
                'PC Components' => ['icon' => 'cpu', 'subs' => ['Graphics Cards', 'Processors', 'Monitors', 'Cooling & Boards']],
            ];
            $cats = [];
            $subs = [];
            $n = 1;
            foreach ($catDefs as $name => $def) {
                $c = Category::firstOrCreate(['name' => $name], ['code' => 'EL-C'.$n, 'icon' => $def['icon']]);
                if (! $c->icon) {
                    $c->update(['icon' => $def['icon']]);
                }
                $cats[$name] = $c;
                foreach ($def['subs'] as $sn) {
                    $subs[$name][$sn] = SubCategory::firstOrCreate(['category_id' => $c->id, 'name' => $sn], ['description' => '', 'status' => 1]);
                }
                $n++;
            }

            /* ---------------------------------------------------- brands */
            $brandNames = ['Apple', 'Samsung', 'Sony', 'Dell', 'HP', 'Lenovo', 'ASUS', 'Logitech', 'Anker', 'DJI', 'Bose', 'JBL', 'Microsoft', 'Nintendo', 'Canon', 'Google', 'Xiaomi', 'Razer', 'Beats', 'NVIDIA', 'Intel', 'Corsair', 'Keychron', 'Amazon', 'Meta', 'Polaroid', 'LG', 'Marshall', 'Huawei'];
            $brands = [];
            foreach ($brandNames as $b) {
                $brands[$b] = Brand::firstOrCreate(['name' => $b], ['description' => $b.' official products']);
            }

            /* -------------------------------------------------- products */
            // [name, brand, category, sub, cost, price, discount%, images[], featured, tags]
            $rows = [
                // Smartphones
                ['Apple iPhone 15 Pro Max 256GB – Titanium Blue', 'Apple', 'Smartphones', 'Apple iPhone', 980, 1249, 12, [17, 18, 71], 1, 'A17 Pro chip, 6.7" Super Retina XDR, titanium design, 48MP camera system, USB-C.'],
                ['Apple iPhone 15 128GB – Yellow', 'Apple', 'Smartphones', 'Apple iPhone', 640, 799, 0, [30, 52], 1, '6.1" display, Dynamic Island, 48MP main camera, all-day battery, USB-C.'],
                ['Apple iPhone 14 Pro 256GB – Space Black', 'Apple', 'Smartphones', 'Apple iPhone', 780, 999, 10, [71, 18], 0, 'Always-On display, ProMotion, 48MP Pro camera system, Emergency SOS.'],
                ['Apple iPhone 13 128GB – Midnight', 'Apple', 'Smartphones', 'Apple iPhone', 520, 699, 0, [106, 126], 0, 'A15 Bionic, dual 12MP cameras, Ceramic Shield, 5G.'],
                ['Apple iPhone 12 Mini 64GB – Black (Boxed)', 'Apple', 'Smartphones', 'Apple iPhone', 380, 549, 15, [80], 0, 'Compact 5.4" OLED, A14 Bionic, MagSafe, 5G.'],
                ['Apple iPhone X 64GB – Refurbished', 'Apple', 'Smartphones', 'Refurbished', 200, 349, 20, [50], 0, 'Certified refurbished, 12-month warranty, 5.8" OLED, Face ID.'],
                ['Samsung Galaxy S24 Ultra 512GB – Titanium Black', 'Samsung', 'Smartphones', 'Samsung Galaxy', 900, 1149, 0, [20], 1, 'Galaxy AI, 200MP camera, built-in S Pen, 6.8" QHD+ 120Hz, titanium frame.'],
                ['Samsung Galaxy S23 256GB – Phantom Black', 'Samsung', 'Smartphones', 'Samsung Galaxy', 560, 749, 8, [107], 0, 'Snapdragon 8 Gen 2, 50MP camera, 6.1" Dynamic AMOLED 2X.'],
                ['Xiaomi 13T Pro 256GB', 'Xiaomi', 'Smartphones', 'Android Phones', 460, 649, 10, [19, 124], 0, 'Leica optics, 120W HyperCharge, 144Hz CrystalRes AMOLED.'],
                ['Huawei P40 Pro 256GB – Silver Frost', 'Huawei', 'Smartphones', 'Android Phones', 420, 599, 0, [93], 0, 'Leica Ultra Vision quad camera, 90Hz Overflow display, 40W SuperCharge.'],
                ['Google Pixel 8a 128GB – Porcelain', 'Google', 'Smartphones', 'Android Phones', 360, 499, 0, [52, 51], 1, 'Google Tensor G3, 7 years of updates, Best Take & Magic Eraser.'],
                ['Apple iPhone SE (3rd Gen) 64GB', 'Apple', 'Smartphones', 'Apple iPhone', 300, 429, 0, [128, 114], 0, 'A15 Bionic, Touch ID, 5G, 4.7" Retina HD display.'],
                // Laptops
                ['Apple MacBook Air M3 13-inch 8GB/256GB – Silver', 'Apple', 'Laptops', 'MacBook', 880, 1099, 0, [29, 3], 1, 'M3 chip, 18-hour battery, Liquid Retina display, fanless design.'],
                ['Apple MacBook Pro 14-inch M3 Pro 18GB/512GB', 'Apple', 'Laptops', 'MacBook', 1650, 1999, 5, [2, 37], 1, 'M3 Pro, Liquid Retina XDR, 3 Thunderbolt 4 ports, up to 18 hrs battery.'],
                ['Apple MacBook Pro 16-inch M3 Max 36GB/1TB', 'Apple', 'Laptops', 'MacBook', 2900, 3499, 0, [28, 27], 0, 'M3 Max, 16.2" Liquid Retina XDR, 22-hour battery, six-speaker sound.'],
                ['Apple MacBook Pro 13-inch M2 8GB/512GB', 'Apple', 'Laptops', 'MacBook', 1050, 1299, 12, [94, 55], 0, 'M2 chip, Touch Bar, 20-hour battery, active cooling.'],
                ['Dell XPS 15 – i7 / 16GB / 1TB / RTX 4050', 'Dell', 'Laptops', 'Ultrabooks', 1350, 1699, 0, [13], 1, '15.6" 3.5K OLED, 13th Gen Intel Core i7, CNC aluminum chassis.'],
                ['HP Spectre x360 14 2-in-1', 'HP', 'Laptops', 'Ultrabooks', 1000, 1299, 8, [12], 0, '2.8K OLED touch, Intel Core Ultra 7, 360° hinge, Poly camera.'],
                ['Lenovo ThinkPad X1 Carbon Gen 11', 'Lenovo', 'Laptops', 'Ultrabooks', 1150, 1449, 0, [120], 0, '14" WUXGA, Intel vPro, 1.12kg carbon fiber, MIL-STD 810H.'],
                ['ASUS ROG Zephyrus G14 – Ryzen 9 / RTX 4070', 'ASUS', 'Laptops', 'Gaming Laptops', 1250, 1599, 0, [63], 1, '14" 165Hz ROG Nebula display, AniMe Matrix lid, 32GB RAM.'],
                ['Microsoft Surface Laptop 5 13.5"', 'Microsoft', 'Laptops', 'Ultrabooks', 780, 999, 10, [108], 0, 'PixelSense touchscreen, 12th Gen Intel Core, Alcantara keyboard.'],
                ['Apple iMac 24-inch M3 8GB/256GB – Silver', 'Apple', 'Laptops', 'Desktops & All-in-One', 1080, 1299, 0, [27, 131], 0, '4.5K Retina display, M3 chip, 1080p FaceTime HD camera, six speakers.'],
                ['Alienware Aurora R16 Gaming Desktop', 'Dell', 'Laptops', 'Desktops & All-in-One', 1500, 1899, 0, [38], 0, 'Intel Core i7-14700F, RTX 4070, 32GB DDR5, liquid cooling.'],
                // Tablets
                ['Apple iPad Pro 12.9-inch M2 256GB Wi-Fi', 'Apple', 'Tablets', 'iPad', 890, 1099, 0, [11, 61], 1, 'Liquid Retina XDR, M2 chip, Apple Pencil hover, Thunderbolt.'],
                ['Apple iPad Air 11-inch M2 128GB', 'Apple', 'Tablets', 'iPad', 480, 599, 0, [53], 0, 'M2 chip, 12MP landscape front camera, Apple Pencil Pro support.'],
                ['Apple iPad Pro 11-inch M2 128GB', 'Apple', 'Tablets', 'iPad', 640, 799, 8, [61, 11], 0, 'Liquid Retina display with ProMotion, M2 chip, Face ID.'],
                ['Samsung Galaxy Tab S9 11-inch 128GB', 'Samsung', 'Tablets', 'Android Tablets', 600, 799, 12, [124], 0, 'Dynamic AMOLED 2X, Snapdragon 8 Gen 2, IP68, S Pen included.'],
                // Audio
                ['Sony WH-1000XM5 Wireless Noise Cancelling Headphones', 'Sony', 'Audio', 'Headphones', 260, 399, 20, [22], 1, 'Industry-leading noise cancelling, 30-hour battery, 8 microphones for calls.'],
                ['Sony WH-1000XM4 – Black', 'Sony', 'Audio', 'Headphones', 220, 349, 15, [82], 0, 'Adaptive Sound Control, DSEE Extreme, 30-hour battery, multipoint.'],
                ['Sony WH-CH720N Wireless Headphones', 'Sony', 'Audio', 'Headphones', 90, 149, 13, [21], 0, 'Lightweight noise cancelling, 35-hour battery, Dual Noise Sensor.'],
                ['Apple AirPods Pro (2nd Gen) with USB-C', 'Apple', 'Audio', 'Earbuds', 180, 249, 0, [23, 100], 1, 'H2 chip, Adaptive Audio, Personalized Spatial Audio, MagSafe charging case.'],
                ['Apple AirPods Max – Silver', 'Apple', 'Audio', 'Headphones', 420, 549, 0, [105], 0, 'High-fidelity audio, Active Noise Cancellation, 20-hour battery, Digital Crown.'],
                ['Apple EarPods (USB-C)', 'Apple', 'Audio', 'Earbuds', 10, 19, 0, [110], 0, 'Built-in remote, comfortable fit, USB-C connector.'],
                ['Bose QuietComfort Ultra Headphones', 'Bose', 'Audio', 'Headphones', 320, 429, 0, [4], 1, 'Immersive Audio, world-class noise cancellation, 24-hour battery.'],
                ['Beats Solo3 Wireless – Rose Gold', 'Beats', 'Audio', 'Headphones', 120, 199, 25, [58], 0, 'Apple W1 chip, 40-hour battery, Fast Fuel charging.'],
                ['JBL Flip 6 Portable Bluetooth Speaker', 'JBL', 'Audio', 'Speakers', 80, 129, 0, [24], 1, 'Bold JBL Original Pro Sound, IP67 waterproof, 12-hour playtime.'],
                ['JBL Tune 500 On-Ear Headphones – Pink', 'JBL', 'Audio', 'Headphones', 22, 39, 0, [57], 0, 'JBL Pure Bass sound, lightweight, foldable, 1-button remote.'],
                ['Marshall Major IV Wireless Headphones', 'Marshall', 'Audio', 'Headphones', 95, 149, 0, [49], 0, '80+ hours wireless playtime, wireless charging, iconic Marshall sound.'],
                ['Master & Dynamic MH40 Wireless', 'Sony', 'Audio', 'Headphones', 260, 399, 0, [56], 0, 'Custom 40mm titanium drivers, lambskin leather, 30-hour battery.'],
                ['Samsung Galaxy Buds2 Pro – Graphite', 'Samsung', 'Audio', 'Earbuds', 130, 229, 30, [121], 0, '24-bit Hi-Fi audio, intelligent ANC, IPX7, 360 audio.'],
                // Gaming
                ['Sony PlayStation 5 Console – Slim Edition', 'Sony', 'Gaming', 'Consoles', 400, 499, 0, [7, 43], 1, 'Ultra-high speed SSD, ray tracing, 4K-TV gaming, DualSense controller included.'],
                ['Microsoft Xbox Series X 1TB', 'Microsoft', 'Gaming', 'Consoles', 400, 499, 0, [46], 1, '12 teraflops, 4K at 120 FPS, Xbox Velocity Architecture, Quick Resume.'],
                ['Microsoft Xbox Series S 512GB – White', 'Microsoft', 'Gaming', 'Consoles', 230, 299, 10, [44], 0, 'All-digital, 1440p at up to 120 FPS, Xbox Game Pass ready.'],
                ['Nintendo Switch OLED – Neon Red/Blue', 'Nintendo', 'Gaming', 'Consoles', 280, 349, 0, [45], 1, '7" OLED screen, 64GB storage, wide adjustable stand, enhanced audio.'],
                ['Meta Quest 3 128GB VR Headset', 'Meta', 'Gaming', 'VR', 380, 499, 0, [122], 0, 'Mixed reality, 4K+ Infinite Display, Touch Plus controllers, Snapdragon XR2 Gen 2.'],
                ['Custom RGB Gaming PC – i7 / RTX 4070 / 32GB', 'ASUS', 'Gaming', 'Gaming PCs', 1800, 2299, 0, [15, 102], 0, 'Intel Core i7-14700K, RTX 4070 12GB, 32GB DDR5, 1TB NVMe, 360mm AIO, tempered glass.'],
                ['Logitech G Pro X Superlight 2 Wireless Mouse', 'Logitech', 'Gaming', 'Controllers & Peripherals', 100, 159, 6, [26], 0, 'HERO 2 sensor 32K DPI, 60g, 95-hour battery, LIGHTSPEED wireless.'],
                ['Razer BlackWidow V4 Mechanical Keyboard', 'Razer', 'Gaming', 'Controllers & Peripherals', 110, 169, 0, [84], 0, 'Razer Green switches, Chroma RGB underglow, magnetic wrist rest, media roller.'],
                ['Retro Gaming Console Bundle (2 Controllers)', 'Nintendo', 'Gaming', 'Consoles', 45, 89, 10, [78], 0, 'Plug-and-play retro console with 600+ classic games, HDMI output.'],
                // Cameras
                ['Canon EOS R6 Mark II Mirrorless Body', 'Canon', 'Cameras', 'Mirrorless & DSLR', 2000, 2499, 0, [104, 10], 1, '24.2MP full-frame, 40 fps electronic shutter, 6K oversampled 4K video.'],
                ['Sony Alpha a7 IV Mirrorless Body', 'Sony', 'Cameras', 'Mirrorless & DSLR', 2000, 2499, 0, [9, 109], 0, '33MP full-frame, 4K 60p 10-bit, Real-time Eye AF, 5-axis stabilization.'],
                ['Canon EOS Rebel T7 DSLR with 18-55mm Lens', 'Canon', 'Cameras', 'Mirrorless & DSLR', 350, 479, 12, [10], 0, '24.1MP APS-C, Wi-Fi/NFC, Full HD video, 9-point AF.'],
                ['Sony ZV-E10 Vlog Camera with 16-50mm Lens', 'Sony', 'Cameras', 'Mirrorless & DSLR', 540, 699, 0, [109], 0, '24.2MP APS-C, vari-angle screen, Product Showcase mode, directional 3-capsule mic.'],
                ['Polaroid OneStep+ Instant Camera – White', 'Polaroid', 'Cameras', 'Instant Cameras', 90, 139, 0, [8], 0, 'Bluetooth app, portrait lens, double exposure, built-in flash.'],
                ['DJI Mini 4 Pro Drone – Fly More Combo', 'DJI', 'Cameras', 'Drones', 760, 959, 0, [33, 32], 1, '4K/60fps HDR, omnidirectional obstacle sensing, 34-min flight, 3 batteries.'],
                ['DJI Phantom 4 Pro V2.0', 'DJI', 'Cameras', 'Drones', 1350, 1799, 8, [34, 32], 0, '1-inch 20MP sensor, 4K 60fps, OcuSync 2.0, 30-min flight time.'],
                // Smart home
                ['Amazon Echo (4th Gen) Smart Speaker – Charcoal', 'Amazon', 'Smart Home', 'Smart Speakers', 60, 99, 20, [31], 1, 'Premium sound, built-in Zigbee hub, Alexa voice control, temperature sensor.'],
                ['Samsung 55" QLED 4K Smart TV Q70C', 'Samsung', 'Smart Home', 'Smart TVs', 700, 899, 10, [40, 41], 1, 'Quantum Processor 4K, Motion Xcelerator Turbo+, Dual LED, Object Tracking Sound.'],
                ['LG 65" OLED evo C3 4K Smart TV', 'LG', 'Smart Home', 'Smart TVs', 1400, 1799, 0, [41], 0, 'α9 AI Processor Gen6, Dolby Vision & Atmos, 120Hz, webOS 23.'],
                ['August Wi-Fi Smart Lock (4th Gen)', 'Amazon', 'Smart Home', 'Security', 150, 229, 0, [48], 0, 'Auto-lock/unlock, remote access, fits over existing deadbolt, Wi-Fi built-in.'],
                ['Google Nest Hub (2nd Gen) – Chalk', 'Google', 'Smart Home', 'Smart Speakers', 60, 99, 0, [47], 0, '7" display, Sleep Sensing, Google Assistant, Thread built-in.'],
                // Accessories
                ['Apple Watch Series 9 GPS 45mm – Midnight', 'Apple', 'Accessories', 'Wearables', 310, 399, 0, [6, 36], 1, 'S9 SiP, Double Tap gesture, Always-On Retina, Blood Oxygen & ECG apps.'],
                ['Apple Watch SE (2nd Gen) 40mm', 'Apple', 'Accessories', 'Wearables', 190, 249, 0, [35], 0, 'Crash Detection, Fitness+, water resistant to 50m.'],
                ['Google Pixel Watch 2 – Polished Silver', 'Google', 'Accessories', 'Wearables', 260, 349, 8, [5], 0, 'Fitbit heart rate tracking, Safety Check, 24-hour battery.'],
                ['Withings ScanWatch Hybrid Smartwatch 42mm', 'Google', 'Accessories', 'Wearables', 190, 279, 0, [103], 0, 'Clinically validated ECG, SpO2, 30-day battery, sapphire glass.'],
                ['Logitech MX Master 3S Wireless Mouse', 'Logitech', 'Accessories', 'Keyboards & Mice', 65, 99, 0, [25], 1, '8K DPI, quiet clicks, MagSpeed scrolling, multi-device.'],
                ['Keychron K2 (V2) Wireless Mechanical Keyboard', 'Keychron', 'Accessories', 'Keyboards & Mice', 55, 89, 0, [67], 0, '75% layout, Gateron switches, hot-swappable, RGB, Mac/Windows.'],
                ['Apple Magic Keyboard with Touch ID', 'Apple', 'Accessories', 'Keyboards & Mice', 120, 149, 0, [98, 99], 0, 'Touch ID, scissor mechanism, rechargeable, USB-C to Lightning.'],
                ['Ducky One 3 Mechanical Keyboard – Retro', 'Keychron', 'Accessories', 'Keyboards & Mice', 85, 129, 0, [70], 0, 'Cherry MX switches, QUACK Mechanics, hot-swap, PBT keycaps.'],
                ['Logitech MX Mechanical Mini – Pale Gray', 'Logitech', 'Accessories', 'Keyboards & Mice', 95, 149, 10, [54], 0, 'Tactile Quiet switches, smart backlighting, Bluetooth + Logi Bolt.'],
                ['Apple 20W USB-C Power Adapter', 'Apple', 'Accessories', 'Chargers & Cables', 10, 19, 0, [113], 0, 'Fast charging for iPhone, iPad and AirPods, compact design.'],
                ['Anker 737 Power Bank (PowerCore 24K, 140W)', 'Anker', 'Accessories', 'Chargers & Cables', 70, 109, 0, [74], 1, '24,000mAh, 140W two-way fast charging, smart display, USB-C x2.'],
                ['Apple Silicone Case with MagSafe – iPhone 15', 'Apple', 'Accessories', 'Cases', 25, 49, 0, [114], 0, 'Silky soft-touch finish, MagSafe magnets, microfiber lining.'],
                // PC Components
                ['NVIDIA GeForce RTX 4070 Founders Edition 12GB', 'NVIDIA', 'PC Components', 'Graphics Cards', 480, 599, 0, [139, 76], 1, 'Ada Lovelace, DLSS 3, 12GB GDDR6X, dual axial flow-through cooling.'],
                ['NVIDIA GeForce RTX 3080 10GB (Dual Fan)', 'NVIDIA', 'PC Components', 'Graphics Cards', 450, 649, 15, [76], 0, 'Ampere architecture, 10GB GDDR6X, ray tracing, 2nd gen RT cores.'],
                ['Intel Core i9-14900K Processor', 'Intel', 'PC Components', 'Processors', 470, 589, 0, [75, 62], 0, '24 cores (8P+16E), up to 6.0 GHz, LGA1700, unlocked.'],
                ['ASUS ROG STRIX B650-A Gaming WiFi Motherboard', 'ASUS', 'PC Components', 'Cooling & Boards', 220, 299, 0, [95, 16], 0, 'AM5, DDR5, PCIe 5.0 M.2, WiFi 6E, Aura Sync.'],
                ['Corsair iCUE H150i Elite LCD Liquid Cooler', 'Corsair', 'PC Components', 'Cooling & Boards', 140, 189, 0, [102], 0, '360mm radiator, IPS LCD pump cap, AF120 RGB ELITE fans.'],
                ['Samsung Odyssey G7 32" 240Hz Curved Gaming Monitor', 'Samsung', 'PC Components', 'Monitors', 420, 549, 10, [73], 1, 'QHD 1000R curve, 1ms, G-Sync compatible, QLED.'],
                ['ASUS TUF Gaming VG27AQ 27" 165Hz Monitor', 'ASUS', 'PC Components', 'Monitors', 250, 329, 0, [39], 0, 'WQHD IPS, ELMB Sync, G-SYNC compatible, HDR10.'],
                ['Arduino UNO R3 Development Board', 'Intel', 'PC Components', 'Cooling & Boards', 15, 27, 0, [86], 0, 'ATmega328P, 14 digital I/O, USB, original Arduino board.'],
                ['Raspberry Pi Starter Electronics Kit', 'Intel', 'PC Components', 'Cooling & Boards', 55, 89, 0, [137], 0, 'Breadboard, sensors, LCD, jumper wires — everything to start prototyping.'],
            ];

            $created = [];
            $i = 1;
            foreach ($rows as $r) {
                [$name, $brand, $cat, $sub, $cost, $price, $disc, $imgs, $featured, $note] = $r;
                $code = 'EL-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT);
                $mainUrl = self::img($imgs[0]);
                $data = [
                    'code' => $code,
                    'Type_barcode' => 'CODE128',
                    'name' => $name,
                    'cost' => $cost,
                    'price' => $price,
                    'unit_id' => $unit->id,
                    'unit_sale_id' => $unit->id,
                    'unit_purchase_id' => $unit->id,
                    'stock_alert' => 5,
                    'category_id' => $cats[$cat]->id,
                    'brand_id' => $brands[$brand]->id ?? null,
                    'is_variant' => 0,
                    'tax_method' => '1',
                    'TaxNet' => 0,
                    'type' => 'is_single',
                    'is_active' => 1,
                    'image' => $mainUrl,
                    'note' => $note,
                    'discount' => $disc > 0 ? $disc : null,
                    'discount_method' => '1',
                    // Spread creation dates so "New Arrivals" is meaningful.
                    'created_at' => Carbon::now()->subDays(random_int(0, 120))->subMinutes($i),
                    'updated_at' => Carbon::now(),
                ];
                if (Schema::hasColumn('products', 'is_featured')) {
                    $data['is_featured'] = $featured;
                }
                if (Schema::hasColumn('products', 'sub_category_id') && isset($subs[$cat][$sub])) {
                    $data['sub_category_id'] = $subs[$cat][$sub]->id;
                }
                if (Schema::hasColumn('products', 'hide_from_online_store')) {
                    $data['hide_from_online_store'] = 0;
                }
                if (Schema::hasColumn('products', 'not_selling')) {
                    $data['not_selling'] = 0;
                }
                if (Schema::hasColumn('products', 'tags')) {
                    $data['tags'] = json_encode([strtolower($brand), strtolower($cat)]);
                }
                if (Schema::hasColumn('products', 'labels') && $featured) {
                    $data['labels'] = null;
                }

                $product = new Product;
                $product->forceFill($data);
                $product->save();

                // Gallery rows — pasted links, exactly what the admin form now supports.
                if (Schema::hasTable('product_images')) {
                    foreach (array_values($imgs) as $k => $idx) {
                        ProductImage::create([
                            'product_id' => $product->id,
                            'image_path' => self::img($idx),
                            'is_main' => $k === 0,
                            'sort_order' => $k,
                        ]);
                    }
                }

                foreach ($warehouses as $wh) {
                    product_warehouse::create([
                        'product_id' => $product->id,
                        'warehouse_id' => $wh->id,
                        'qte' => random_int(6, 80),
                        'manage_stock' => 1,
                    ]);
                }

                $created[] = $product;
                $i++;
            }

            /* --------------------------------------------------- reviews */
            if (Schema::hasTable('product_reviews')) {
                $clients = Client::query()->take(12)->get();
                $needed = 8 - $clients->count();
                $names = ['Daniel Kim', 'Sophia Lopez', 'James Turner', 'Amira Haddad', 'Lucas Meyer', 'Emma Rossi', 'Noah Carter', 'Yara Saleh'];
                for ($k = 0; $k < $needed; $k++) {
                    $nm = $names[$k];
                    $clients->push(Client::create([
                        'code' => 97001 + $k,
                        'name' => $nm,
                        'firstname' => explode(' ', $nm)[0],
                        'lastname' => explode(' ', $nm)[1] ?? '',
                        'email' => strtolower(str_replace(' ', '.', $nm)).'@example.com',
                        'phone' => '+1 555 01'.str_pad((string) $k, 2, '0', STR_PAD_LEFT),
                        'country' => 'United States',
                        'city' => 'San Francisco',
                        'opening_balance' => 0,
                    ]));
                }

                $comments = [
                    5 => [
                        'Absolutely love it. Fast shipping, genuine product and excellent customer service.',
                        'Exceeded my expectations — build quality is superb and it arrived two days early.',
                        'Best purchase this year. Setup took minutes and everything just works.',
                        'Great price for what you get. Packaging was perfect and the item is flawless.',
                        'Top quality and exactly as described. Highly recommended store for tech lovers!',
                    ],
                    4 => [
                        'Very good overall. Slightly pricey but the performance makes up for it.',
                        'Solid product, does everything I need. Would have liked a longer cable in the box.',
                        'Happy with it. Battery life is a bit lower than advertised but still great.',
                    ],
                    3 => ['Decent, works as expected. Nothing special but no complaints either.'],
                ];

                $reviewerNames = ['Daniel K.', 'Sophia L.', 'James T.', 'Amira H.', 'Lucas M.', 'Emma R.', 'Noah C.', 'Yara S.', 'Omar B.', 'Lina P.', 'Chris W.', 'Mia F.'];
                $seq = 0;
                foreach ($created as $idx => $product) {
                    // Featured / discounted products get more reviews; some get none.
                    $count = $product->is_featured ? random_int(3, 6) : ($idx % 3 === 0 ? 0 : random_int(1, 3));
                    for ($k = 0; $k < $count; $k++) {
                        $rating = $product->is_featured ? (random_int(1, 10) > 2 ? 5 : 4) : [5, 5, 4, 4, 4, 3][random_int(0, 5)];
                        $client = $clients[$seq % $clients->count()];
                        $seq++;
                        ProductReview::create([
                            'product_id' => $product->id,
                            'client_id' => $client->id,
                            'online_order_id' => null,
                            'reviewer_name' => $reviewerNames[$seq % count($reviewerNames)],
                            'rating' => $rating,
                            'comment' => $comments[$rating][array_rand($comments[$rating])],
                            'status' => 'approved',
                            'created_at' => Carbon::now()->subDays(random_int(1, 90)),
                            'updated_at' => Carbon::now(),
                        ]);
                    }
                }
            }
        });

        $this->activateTheme();

        $this->command?->info('Electronics demo catalog seeded and the Electronics theme activated.');
    }

    /** Switch the storefront to the Electronics theme with matching branding. */
    protected function activateTheme(): void
    {
        $s = StoreSetting::first();
        if (! $s) {
            return;
        }

        $opts = is_array($s->theme_options) ? $s->theme_options : [];
        $opts['electronics'] = [
            'slides' => [
                [
                    'kicker' => 'New Arrivals '.date('Y'),
                    'title' => 'Upgrade Your Tech Lifestyle',
                    'subtitle' => 'Discover the latest electronics, smart devices, and accessories designed for performance and innovation.',
                    'image' => self::img(60, 1400),
                    'primary_text' => 'Shop Now', 'primary_url' => '/shop',
                    'secondary_text' => 'Explore Deals', 'secondary_url' => '/shop?deals=1',
                ],
                [
                    'kicker' => 'Gaming Season',
                    'title' => 'Play Without Limits',
                    'subtitle' => 'Consoles, RGB rigs and pro peripherals — everything you need for the next level.',
                    'image' => self::img(116, 1400),
                    'primary_text' => 'Shop Gaming', 'primary_url' => '/shop',
                    'secondary_text' => 'See Consoles', 'secondary_url' => '/shop',
                ],
                [
                    'kicker' => 'Immersive Sound',
                    'title' => 'Hear Every Detail',
                    'subtitle' => 'Premium noise-cancelling headphones and earbuds from Sony, Apple, Bose and more.',
                    'image' => self::img(105, 1400),
                    'primary_text' => 'Shop Audio', 'primary_url' => '/shop',
                    'secondary_text' => 'View Deals', 'secondary_url' => '/shop?deals=1',
                ],
            ],
            'banners' => [
                ['kicker' => 'Level Up Your Game', 'title' => 'Gaming Gear', 'subtitle' => 'Up to 35% Off', 'button_text' => 'Shop Gaming', 'url' => '/shop', 'image' => self::img(119), 'tone' => 'dark'],
                ['kicker' => 'Smarter Living', 'title' => 'Smart Home', 'subtitle' => 'Up to 30% Off', 'button_text' => 'Shop Now', 'url' => '/shop', 'image' => self::img(117), 'tone' => 'light'],
                ['kicker' => 'Immersive Sound', 'title' => 'Audio Deals', 'subtitle' => 'Up to 40% Off', 'button_text' => 'Shop Audio', 'url' => '/shop', 'image' => self::img(21), 'tone' => 'dark'],
            ],
        ];

        $s->forceFill([
            'theme' => 'electronics',
            'theme_options' => $opts,
            'store_name' => in_array($s->store_name, [null, '', 'StoreX', 'TechNova', 'LittleJoy', 'HavenHomes', 'FreshMart'], true) ? 'TechNova' : $s->store_name,
            'primary_color' => '#2563eb',
            'secondary_color' => '#1d4ed8',
            'topbar_text_left' => '🔥 Summer Tech Sale – Up to 40% Off on Laptops, Headphones & More!',
            'topbar_text_right' => 'Free Shipping on Orders $49+',
            'footer_text' => 'Your destination for premium electronics and smart gadgets. Quality you can trust, service you can rely on.',
        ])->save();

        // Point the banner/category links at real categories now that they exist.
        $gaming = Category::where('name', 'Gaming')->value('id');
        $smart = Category::where('name', 'Smart Home')->value('id');
        $audio = Category::where('name', 'Audio')->value('id');
        if ($gaming || $smart || $audio) {
            $opts = $s->theme_options;
            $opts['electronics']['banners'][0]['url'] = $gaming ? '/shop?category='.$gaming : '/shop';
            $opts['electronics']['banners'][1]['url'] = $smart ? '/shop?category='.$smart : '/shop';
            $opts['electronics']['banners'][2]['url'] = $audio ? '/shop?category='.$audio : '/shop';
            $opts['electronics']['slides'][1]['primary_url'] = $gaming ? '/shop?category='.$gaming : '/shop';
            $opts['electronics']['slides'][1]['secondary_url'] = $gaming ? '/shop?category='.$gaming : '/shop';
            $opts['electronics']['slides'][2]['primary_url'] = $audio ? '/shop?category='.$audio : '/shop';
            $s->theme_options = $opts;
            $s->save();
        }

        // Only one demo catalog should be visible at a time; the other stays
        // in the database but hidden from the storefront.
        if (Schema::hasColumn('products', 'hide_from_online_store')) {
            Product::where('code', 'like', 'EL-%')->update(['hide_from_online_store' => 0]);
            Product::where('code', 'like', 'TY-%')->orWhere('code', 'like', 'GR-%')->update(['hide_from_online_store' => 1]);
        }

        if (function_exists('store_url_config_clear')) {
            store_url_config_clear();
        }
    }
}
