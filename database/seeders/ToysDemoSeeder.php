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
 * Demo catalog for the Toys & Baby storefront theme: 8 categories with
 * subcategories, brands, ~60 products with Unsplash photo LINKS (no files on
 * disk), stock in every warehouse, approved reviews, and the theme switched
 * on with matching hero, promo tiles and branding.
 *
 * Run:  php artisan db:seed --class=ToysDemoSeeder
 *
 * Idempotent: products carry TY- codes and are skipped when present (the
 * theme is still activated).
 */
class ToysDemoSeeder extends Seeder
{
    /** Validated Unsplash photo ids, keyed by subject. */
    private const IMG = [
        'teddy' => '1602734846297-9299fc2d4703',
        'baby_blanket' => '1566004100631-35d015d6a491',
        'wooden_rings' => '1618842676088-c4d48a6a7c9d',
        'lego_car' => '1594736797933-d0501ba2fe65',
        'toys_flatlay' => '1545558014-8692077e9b5c',
        'wooden_train' => '1560859251-d563a49c5e4a',
        'baby_feet' => '1555252333-9f8e92e65df9',
        'lego_bricks' => '1587654780291-39c9404d746b',
        'figures_sw' => '1608889825103-eb5ed706fc64',
        'lego_heroes' => '1611604548018-d56bbd85d681',
        'figures_mario' => '1566576912321-d58ddd7a6088',
        'lego_abbey' => '1585366119957-e9730b6d0f60',
        'train_set' => '1558877385-81a1c7e67d72',
        'red_car' => '1594787318286-3d835c1d207f',
        'baby_toys' => '1515488042361-ee00e0ddd4e4',
        'baby_float' => '1519689680058-324335c77eba',
        'bear_onesie' => '1522771930-78848d9293e8',
        'kids_outdoors' => '1502086223501-7ea6ecd79368',
        'kids_reading' => '1544776193-352d25ca82cd',
        'sprinkler' => '1489710437720-ebb67ec84dd2',
        'boy_red' => '1471286174890-9c112ffca5b4',
        'girl_paint' => '1503454537195-1dcabb73ffb9',
        'superheroes' => '1519340241574-2cec6aef0c01',
        'crayons' => '1596464716127-f2a82984de30',
        'baby_clothes' => '1622290291468-a28f7a7dc6a8',
        'sleeping_baby' => '1544126592-807ade215a0b',
        'bedroom' => '1616594039964-ae9021a400a0',
        'boy_blue' => '1519238263530-99bdd11df2ea',
        'kids_two' => '1503944583220-79d8926ad5e2',
        'books_stack' => '1512820790803-83ca734da794',
        'books_row' => '1495446815901-a7297e633e8d',
        'books_hand' => '1519682337058-a94d519337bc',
        'book' => '1544947950-fa07a98d237f',
        'skateboard' => '1596870230751-ebdfce98ec42',
        'kids_guitar' => '1502781252888-9143ba7f074e',
        'toddler_laugh' => '1472162072942-cd5147eb3902',
        'mother_reading' => '1476234251651-f353703a034d',
        'abc_blocks' => '1535572290543-960a8046f5af',
        'toy_story' => '1599623560574-39d485900c95',
        'gift_box' => '1512909006721-3d6018887383',
        'toy_camera' => '1516627145497-ae6968895b74',
        'toy_cars' => '1532330393533-443990a51d10',
        'toddler_flowers' => '1518831959646-742c3a14ebf7',
        'folded_clothes' => '1567113463300-102a7eb3cb26',
        'toys_pile' => '1558060370-d644479cb6f7',
        'white_car' => '1581235720704-06d3acfcb36f',
        'parachute' => '1606092195730-5d7b9af1efc5',
        'room_chair' => '1586023492125-27b2c045efd7',
        'clothes_rack' => '1560506840-ec148e82a604',
        'chess' => '1611195974226-a6a9be9dd763',
        'open_book' => '1524504388940-b1c1722653e1',
    ];

    private static function img(string $key, int $w = 900): string
    {
        return 'https://images.unsplash.com/photo-'.self::IMG[$key].'?w='.$w.'&q=80&auto=format&fit=crop';
    }

    public function run(): void
    {
        if (Product::where('code', 'like', 'TY-%')->exists()) {
            $this->command?->warn('Toys demo products already present — activating the theme only. (Delete TY-* products to reseed.)');
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

            $catDefs = [
                'Baby Gear' => ['icon' => 'stroller', 'subs' => ['Strollers', 'Car Seats', 'Carriers & Wraps', 'Playmats & Bouncers']],
                'Toys & Games' => ['icon' => 'blocks', 'subs' => ['Plush & Soft Toys', 'Building & Blocks', 'Vehicles & Trains', 'Figures & Playsets', 'Board Games & Puzzles', 'Arts & Crafts']],
                'Clothing' => ['icon' => 'shirt', 'subs' => ['Baby (0–24m)', 'Toddler (2–5y)', 'Kids (6–12y)', 'Shoes & Accessories']],
                'Nursery' => ['icon' => 'bed', 'subs' => ['Cribs & Bedding', 'Decor & Lighting', 'Storage']],
                'Feeding' => ['icon' => 'baby-bottle', 'subs' => ['Bottles & Sippy Cups', 'High Chairs', 'Tableware']],
                'Bath & Care' => ['icon' => 'bath', 'subs' => ['Bath Time', 'Skincare', 'Health & Safety']],
                'Books' => ['icon' => 'book-open', 'subs' => ['Picture Books', 'Early Learning', 'Activity Books']],
                'Outdoor' => ['icon' => 'trees', 'subs' => ['Ride-ons & Bikes', 'Water Play', 'Garden Games']],
            ];
            $cats = [];
            $subs = [];
            $n = 1;
            foreach ($catDefs as $name => $def) {
                $c = Category::firstOrCreate(['name' => $name], ['code' => 'TY-C'.$n, 'icon' => $def['icon']]);
                if (! $c->icon) {
                    $c->update(['icon' => $def['icon']]);
                }
                $cats[$name] = $c;
                foreach ($def['subs'] as $sn) {
                    $subs[$name][$sn] = SubCategory::firstOrCreate(['category_id' => $c->id, 'name' => $sn], ['description' => '', 'status' => 1]);
                }
                $n++;
            }

            $brandNames = ['LittleJoy', 'Fisher-Price', 'LEGO', 'Melissa & Doug', 'Hasbro', 'Mattel', 'Chicco', 'Graco', 'Philips Avent', "Carter's", 'Skip Hop', 'Munchkin', 'Ravensburger', 'Usborne', 'BRIO', 'Playmobil', 'Radio Flyer', 'Micro', 'Hape', 'Tommee Tippee'];
            $brands = [];
            foreach ($brandNames as $b) {
                $brands[$b] = Brand::firstOrCreate(['name' => $b], ['description' => $b.' products']);
            }

            // [name, brand, category, sub, cost, price, discount%, image keys, featured, note]
            $rows = [
                // Baby gear
                ['Baby Stroller Comfort Ride – Graphite', 'Graco', 'Baby Gear', 'Strollers', 140, 199, 0, ['baby_feet', 'sleeping_baby'], 1, 'Lightweight aluminium frame, one-hand fold, reclining seat, extra-large basket and UV50+ canopy.'],
                ['Compact Travel Stroller', 'Chicco', 'Baby Gear', 'Strollers', 95, 149, 10, ['toddler_laugh'], 0, 'Cabin-size fold, 6.5 kg, adjustable backrest, cup holder and rain cover included.'],
                ['Infant Car Seat with Base (0–13 kg)', 'Chicco', 'Baby Gear', 'Car Seats', 110, 169, 0, ['baby_blanket'], 1, 'Side-impact protection, newborn insert, 5-point harness, click-in ISOFIX base.'],
                ['Convertible Car Seat 0–36 kg', 'Graco', 'Baby Gear', 'Car Seats', 160, 229, 12, ['bear_onesie'], 0, 'Grows with your child from birth to 12 years. 10-position headrest, washable cover.'],
                ['Soft Baby Carrier – 4 Positions', 'Skip Hop', 'Baby Gear', 'Carriers & Wraps', 45, 79, 0, ['baby_feet', 'baby_blanket'], 0, 'Ergonomic M-position seat, breathable mesh, padded straps, 3.5–15 kg.'],
                ['Stretchy Baby Wrap – Grey Melange', 'LittleJoy', 'Baby Gear', 'Carriers & Wraps', 18, 34, 0, ['sleeping_baby'], 0, 'Ultra-soft cotton jersey wrap for newborns, keeps baby close and calm.'],
                ['Activity Playmat with Arches', 'Fisher-Price', 'Baby Gear', 'Playmats & Bouncers', 32, 54, 15, ['baby_toys'], 1, 'Padded mat, 5 removable toys, mirror and crinkle textures for tummy time.'],
                ['Rocking Baby Bouncer – Sage', 'Chicco', 'Baby Gear', 'Playmats & Bouncers', 48, 79, 0, ['toddler_laugh', 'baby_blanket'], 0, 'Gentle rocking, 3 recline positions, removable toy bar, machine-washable seat.'],
                // Toys & games
                ['Soft Plush Elephant – 30 cm', 'LittleJoy', 'Toys & Games', 'Plush & Soft Toys', 8, 18.99, 0, ['teddy', 'toys_flatlay'], 1, 'Super-soft plush with embroidered eyes, suitable from birth, machine washable.'],
                ['Classic Teddy Bear with Bow – 40 cm', 'LittleJoy', 'Toys & Games', 'Plush & Soft Toys', 12, 24.99, 0, ['teddy'], 1, 'Huggable honey-coloured bear with satin bow. A first friend for life.'],
                ['Bunny Comforter Blanket', 'Skip Hop', 'Toys & Games', 'Plush & Soft Toys', 7, 14.99, 0, ['baby_blanket', 'teddy'], 0, 'Velvety bunny head on a soft security blanket, perfect for naptime.'],
                ['Wooden Building Blocks Set – 50 pcs', 'Melissa & Doug', 'Toys & Games', 'Building & Blocks', 16, 29.99, 0, ['abc_blocks', 'toys_flatlay'], 1, '50 solid wood blocks in 4 colours and 9 shapes, storage bag included. Ages 2+.'],
                ['Rainbow Stacking Rings', 'Hape', 'Toys & Games', 'Building & Blocks', 9, 17.99, 0, ['wooden_rings'], 1, '8 chunky wooden rings that teach colours, sizes and coordination. Ages 12m+.'],
                ['Alphabet Blocks – 26 pcs', 'Melissa & Doug', 'Toys & Games', 'Building & Blocks', 11, 19.99, 10, ['abc_blocks'], 0, 'Hand-painted wooden ABC blocks with letters, numbers and pictures.'],
                ['Classic Brick Box – 500 pcs', 'LEGO', 'Toys & Games', 'Building & Blocks', 28, 44.99, 0, ['lego_bricks'], 1, 'Colourful bricks, windows, wheels and eyes for endless building. Ages 4+.'],
                ['Super Hero Racing Car Building Kit', 'LEGO', 'Toys & Games', 'Building & Blocks', 22, 34.99, 8, ['lego_car', 'lego_heroes'], 0, 'Build and race your own hero car, 2 minifigures included. Ages 6+.'],
                ['Mini Figures Collector Pack', 'LEGO', 'Toys & Games', 'Figures & Playsets', 14, 24.99, 0, ['lego_heroes', 'lego_abbey'], 0, 'Set of 6 collectable minifigures with accessories and display base.'],
                ['Galaxy Adventure Figure Set', 'Hasbro', 'Toys & Games', 'Figures & Playsets', 19, 32.99, 0, ['figures_sw'], 0, '4 articulated 10 cm figures with blasters and capes. Ages 5+.'],
                ['Kart Racing Heroes – 4 Figures', 'Playmobil', 'Toys & Games', 'Figures & Playsets', 20, 34.99, 15, ['figures_mario'], 1, 'Iconic characters ready for adventure, moveable arms and swap-able parts.'],
                ['Toy Story Friends Set', 'Mattel', 'Toys & Games', 'Figures & Playsets', 24, 39.99, 0, ['toy_story'], 0, 'Woody, Buzz and friends in a 5-piece posable set. Ages 3+.'],
                ['Wooden Train Set – 45 pcs', 'BRIO', 'Toys & Games', 'Vehicles & Trains', 38, 64.99, 0, ['train_set', 'wooden_train'], 1, 'Classic beechwood tracks, magnetic engine, bridge and station. Ages 3+.'],
                ['Push-Along Wooden Cars – 3 pack', 'Hape', 'Toys & Games', 'Vehicles & Trains', 9, 16.99, 0, ['wooden_train', 'toy_cars'], 0, 'Chunky wooden vehicles sized for little hands, non-toxic paint.'],
                ['Retro Convertible Die-cast Car', 'LittleJoy', 'Toys & Games', 'Vehicles & Trains', 6, 12.99, 0, ['red_car', 'white_car'], 0, '1:36 scale die-cast with opening doors and pull-back motor.'],
                ['Race Track Cars – 12 pack', 'Mattel', 'Toys & Games', 'Vehicles & Trains', 10, 19.99, 20, ['toy_cars'], 0, '12 assorted die-cast racers with authentic decos. Ages 3+.'],
                ['Family Chess & Checkers Set', 'Ravensburger', 'Toys & Games', 'Board Games & Puzzles', 14, 26.99, 0, ['chess'], 0, 'Folding wooden board with weighted pieces and a beginner\'s guide.'],
                ['Superhero Dress-up Masks – 4 pack', 'Hasbro', 'Toys & Games', 'Figures & Playsets', 8, 15.99, 0, ['superheroes'], 0, 'Soft felt masks with elastic bands, machine washable. Ages 3+.'],
                ['Washable Crayons & Markers Art Set', 'LittleJoy', 'Toys & Games', 'Arts & Crafts', 9, 17.99, 0, ['crayons', 'girl_paint'], 1, '64-piece set: crayons, markers, paints and a sketch pad in a carry case.'],
                ['Finger Paint Kit – 6 Colours', 'Melissa & Doug', 'Toys & Games', 'Arts & Crafts', 6, 11.99, 0, ['girl_paint'], 0, 'Non-toxic washable paints with sponges and stamps for messy fun.'],
                ['My First Camera – Kids Digital Camera', 'LittleJoy', 'Toys & Games', 'Figures & Playsets', 22, 39.99, 10, ['toy_camera'], 1, 'Shock-proof, 12 MP, fun frames and games, 32 GB card included. Ages 4+.'],
                ['Toy Storage Bin with Lid – Rainbow', 'Skip Hop', 'Toys & Games', 'Plush & Soft Toys', 12, 22.99, 0, ['toys_pile'], 0, 'Collapsible fabric bin keeps the playroom tidy, 40 litres.'],
                // Clothing
                ['Baby Romper Set (Pack of 3)', "Carter's", 'Clothing', 'Baby (0–24m)', 12, 24.99, 0, ['baby_clothes', 'folded_clothes'], 1, '100% organic cotton rompers with easy snaps. Sizes 0–24 months.'],
                ['Bear Hooded Onesie – Brown', "Carter's", 'Clothing', 'Baby (0–24m)', 14, 27.99, 0, ['bear_onesie'], 1, 'Cosy fleece onesie with bear ears, zip front and fold-over cuffs.'],
                ['Toddler T-shirt 3-Pack – Bright', 'LittleJoy', 'Clothing', 'Toddler (2–5y)', 10, 19.99, 0, ['boy_red', 'kids_two'], 0, 'Soft jersey tees in red, blue and yellow. Sizes 2–5 years.'],
                ['Kids Cardigan & Shorts Set – Navy', 'LittleJoy', 'Clothing', 'Kids (6–12y)', 18, 34.99, 12, ['boy_blue'], 0, 'Smart cotton cardigan with matching shorts for special occasions.'],
                ['Floral Summer Dress – Toddler', "Carter's", 'Clothing', 'Toddler (2–5y)', 13, 24.99, 0, ['toddler_flowers'], 0, 'Lightweight cotton dress with ruffle sleeves and back buttons.'],
                ['Kids Capsule Wardrobe – 6 pcs', 'LittleJoy', 'Clothing', 'Kids (6–12y)', 32, 59.99, 0, ['clothes_rack', 'folded_clothes'], 0, 'Mix-and-match set of tees, leggings and a hoodie in seasonal colours.'],
                ['Newborn Essentials Gift Box', 'LittleJoy', 'Clothing', 'Baby (0–24m)', 22, 44.99, 0, ['gift_box', 'baby_clothes'], 1, 'Bodysuit, hat, mittens, bib and muslin in a keepsake box.'],
                // Nursery
                ['Convertible Crib – Natural Wood', 'Graco', 'Nursery', 'Cribs & Bedding', 180, 269, 0, ['sleeping_baby', 'bedroom'], 1, '4-in-1 crib converts to toddler bed and daybed, 3 mattress heights.'],
                ['Musical Crib Mobile – Clouds & Stars', 'Fisher-Price', 'Nursery', 'Decor & Lighting', 19, 34.99, 0, ['sleeping_baby'], 1, 'Soothing lullabies, soft plush clouds and a night-light projector.'],
                ['Fitted Crib Sheets – 2 pack', 'LittleJoy', 'Nursery', 'Cribs & Bedding', 11, 21.99, 0, ['baby_blanket'], 0, 'Breathable 100% cotton, elasticated corners, fits standard mattresses.'],
                ['Nursery Reading Chair – Mustard', 'LittleJoy', 'Nursery', 'Decor & Lighting', 95, 159, 15, ['room_chair'], 0, 'Compact velvet armchair for bedtime stories and night feeds.'],
                ['Kids Room Storage Baskets – Set of 3', 'Skip Hop', 'Nursery', 'Storage', 15, 27.99, 0, ['bedroom'], 0, 'Woven cotton baskets with handles for toys, books and blankets.'],
                // Feeding
                ['Silicone Feeding Set – Sage', 'Munchkin', 'Feeding', 'Tableware', 11, 21.99, 0, ['baby_toys', 'toddler_laugh'], 1, 'Suction bowl, plate, spoon and cup in food-grade silicone. Dishwasher safe.'],
                ['Anti-Colic Baby Bottles – 3 pack', 'Philips Avent', 'Feeding', 'Bottles & Sippy Cups', 14, 26.99, 10, ['sleeping_baby'], 0, '260 ml bottles with AirFree vent to reduce colic and gas.'],
                ['Insulated Sippy Cup – Blush', 'Tommee Tippee', 'Feeding', 'Bottles & Sippy Cups', 6, 12.99, 0, ['toddler_flowers'], 0, 'Leak-proof, keeps drinks cool for 8 hours, easy-grip handles.'],
                ['Wooden High Chair – Adjustable', 'Hape', 'Feeding', 'High Chairs', 85, 139, 0, ['room_chair', 'toddler_laugh'], 0, 'Grows with your child, removable tray, 5-point harness, beechwood.'],
                ['Bamboo Divided Plates – 4 pack', 'Munchkin', 'Feeding', 'Tableware', 9, 17.99, 0, ['toys_flatlay'], 0, 'Eco-friendly bamboo fibre plates in pastel colours.'],
                // Bath & care
                ['Inflatable Baby Pool Float – Sunny', 'Munchkin', 'Bath & Care', 'Bath Time', 8, 15.99, 0, ['baby_float'], 1, 'Soft inflatable seat with canopy, ages 6–24 months. Adult supervision required.'],
                ['Bath Toys – Ocean Friends 8 pack', 'Munchkin', 'Bath & Care', 'Bath Time', 7, 13.99, 0, ['baby_float', 'toys_pile'], 0, 'Squirting sea animals, mould-resistant, BPA-free.'],
                ['Gentle Baby Skincare Set', 'LittleJoy', 'Bath & Care', 'Skincare', 12, 22.99, 0, ['baby_feet'], 0, 'Wash, lotion and balm with oat extract, fragrance-free, dermatologist tested.'],
                ['Hooded Baby Towel – Bunny', 'Skip Hop', 'Bath & Care', 'Bath Time', 9, 16.99, 0, ['baby_blanket', 'bear_onesie'], 0, 'Bamboo-cotton towel with bunny ears, extra absorbent and soft.'],
                ['Digital Ear Thermometer', 'Chicco', 'Bath & Care', 'Health & Safety', 18, 32.99, 10, ['sleeping_baby'], 0, '1-second reading, fever alert, memory of last 10 readings.'],
                // Books
                ['Goodnight Little Bear – Picture Book', 'Usborne', 'Books', 'Picture Books', 5, 9.99, 0, ['mother_reading', 'book'], 1, 'A gentle bedtime story with lift-the-flap surprises. Ages 1–4.'],
                ['My First 100 Words – Board Book', 'Usborne', 'Books', 'Early Learning', 4, 8.99, 0, ['kids_reading'], 1, 'Chunky board book with bright pictures to build vocabulary.'],
                ['Sticker & Activity Book Bundle', 'Usborne', 'Books', 'Activity Books', 8, 15.99, 0, ['crayons', 'kids_reading'], 0, '4 activity books with 1,000+ stickers, mazes and colouring.'],
                ['Classic Fairy Tales Collection', 'Usborne', 'Books', 'Picture Books', 12, 22.99, 0, ['books_stack', 'open_book'], 0, '20 beloved tales in a beautiful hardback gift edition.'],
                ['Learn to Read – Level 1 Box Set', 'Usborne', 'Books', 'Early Learning', 15, 27.99, 15, ['books_row', 'books_hand'], 0, '12 phonics readers with parent notes and progress stickers.'],
                // Outdoor
                ['Kids Balance Bike – Mint', 'Micro', 'Outdoor', 'Ride-ons & Bikes', 55, 89.99, 0, ['skateboard', 'kids_outdoors'], 1, 'Lightweight steel frame, puncture-proof tyres, adjustable seat. Ages 2–5.'],
                ['Classic Red Wagon', 'Radio Flyer', 'Outdoor', 'Ride-ons & Bikes', 68, 109, 0, ['red_car'], 0, 'Steel body, controlled-turning front axle, extra-long handle.'],
                ['Kids Skateboard – Starter', 'Micro', 'Outdoor', 'Ride-ons & Bikes', 25, 44.99, 10, ['skateboard'], 0, '22-inch cruiser with soft wheels, ages 5+. Helmet recommended.'],
                ['Garden Sprinkler Splash Pad', 'LittleJoy', 'Outdoor', 'Water Play', 14, 24.99, 0, ['sprinkler', 'kids_outdoors'], 1, '170 cm splash mat connects to any hose, hours of summer fun.'],
                ['Rainbow Play Parachute – 3.5 m', 'LittleJoy', 'Outdoor', 'Garden Games', 16, 29.99, 0, ['parachute'], 0, '8 handles, bright nylon panels, group games for parties and school.'],
                ['Outdoor Explorer Kit', 'Melissa & Doug', 'Outdoor', 'Garden Games', 12, 22.99, 0, ['kids_guitar', 'kids_outdoors'], 0, 'Binoculars, magnifier, bug jar and field notebook for little adventurers.'],
            ];

            $created = [];
            $i = 1;
            foreach ($rows as $r) {
                [$name, $brand, $cat, $sub, $cost, $price, $disc, $imgs, $featured, $note] = $r;
                $code = 'TY-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT);
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
                    'image' => self::img($imgs[0]),
                    'note' => $note,
                    'discount' => $disc > 0 ? $disc : null,
                    'discount_method' => '1',
                    'created_at' => Carbon::now()->subDays(random_int(0, 120))->subMinutes($i),
                    'updated_at' => Carbon::now(),
                ];
                foreach (['is_featured' => $featured, 'hide_from_online_store' => 0, 'not_selling' => 0] as $col => $val) {
                    if (Schema::hasColumn('products', $col)) {
                        $data[$col] = $val;
                    }
                }
                if (Schema::hasColumn('products', 'sub_category_id') && isset($subs[$cat][$sub])) {
                    $data['sub_category_id'] = $subs[$cat][$sub]->id;
                }
                if (Schema::hasColumn('products', 'tags')) {
                    $data['tags'] = json_encode([strtolower($brand), strtolower($cat)]);
                }

                $product = new Product;
                $product->forceFill($data);
                $product->save();

                if (Schema::hasTable('product_images')) {
                    foreach (array_values(array_unique($imgs)) as $k => $key) {
                        ProductImage::create([
                            'product_id' => $product->id,
                            'image_path' => self::img($key),
                            'is_main' => $k === 0,
                            'sort_order' => $k,
                        ]);
                    }
                }

                foreach ($warehouses as $wh) {
                    product_warehouse::create([
                        'product_id' => $product->id,
                        'warehouse_id' => $wh->id,
                        'qte' => random_int(8, 90),
                        'manage_stock' => 1,
                    ]);
                }

                $created[] = $product;
                $i++;
            }

            if (Schema::hasTable('product_reviews')) {
                $clients = Client::query()->take(12)->get();
                $names = ['Emma Wilson', 'Liam Carter', 'Olivia Brown', 'Noah Davis', 'Ava Martin', 'Mia Lopez', 'Lucas Meyer', 'Sofia Rossi'];
                for ($k = $clients->count(); $k < 8; $k++) {
                    $nm = $names[$k];
                    $clients->push(Client::create([
                        'code' => 98001 + $k,
                        'name' => $nm,
                        'firstname' => explode(' ', $nm)[0],
                        'lastname' => explode(' ', $nm)[1] ?? '',
                        'email' => strtolower(str_replace(' ', '.', $nm)).'@example.com',
                        'phone' => '+1 555 02'.str_pad((string) $k, 2, '0', STR_PAD_LEFT),
                        'country' => 'United States',
                        'city' => 'Austin',
                        'opening_balance' => 0,
                    ]));
                }
                $comments = [
                    5 => [
                        'My daughter has not put it down since it arrived. Beautiful quality and fast delivery!',
                        'Exactly as pictured, safe materials and lovely packaging. Perfect gift.',
                        'Sturdy, colourful and easy to clean. Our toddler loves it.',
                        'Great value and it arrived two days early. Will definitely order again.',
                        'Soft, safe and adorable. Our little one sleeps with it every night.',
                    ],
                    4 => [
                        'Really nice, slightly smaller than I expected but well made.',
                        'Good quality for the price. Assembly took a few minutes.',
                        'Lovely product, my son enjoys it. Wish it came in more colours.',
                    ],
                    3 => ['Fine for the price. Does what it says, nothing extra.'],
                ];
                $reviewerNames = ['Emma W.', 'Liam C.', 'Olivia B.', 'Noah D.', 'Ava M.', 'Mia L.', 'Lucas M.', 'Sofia R.', 'Zoe P.', 'Ethan K.'];
                $seq = 0;
                foreach ($created as $idx => $product) {
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
        $this->command?->info('Toys & Baby demo catalog seeded and the Toys theme activated.');
    }

    protected function activateTheme(): void
    {
        $s = StoreSetting::first();
        if (! $s) {
            return;
        }
        $catId = fn ($name) => Category::where('name', $name)->value('id');
        $link = fn ($name, $extra = '') => ($id = $catId($name)) ? '/shop?category='.$id.$extra : '/shop';

        $opts = is_array($s->theme_options) ? $s->theme_options : [];
        $opts['toys'] = [
            'coupon_text' => 'Welcome Offer! Get 15% OFF on your first order',
            'coupon_code' => 'HELLO15',
            'tagline' => 'for happy little ones',
            'slides' => [
                [
                    'kicker' => 'Everything for your', 'title' => "Little Ones'", 'title_accent' => 'Big Smiles',
                    'subtitle' => "Safe, quality products for every stage of your child's journey.",
                    'image' => self::img('teddy', 1200), 'badge' => 'Up to 40% OFF',
                    'primary_text' => 'Shop Now', 'primary_url' => '/shop', 'secondary_text' => 'Explore Deals', 'secondary_url' => '/shop?deals=1',
                ],
                [
                    'kicker' => 'Build, stack, imagine', 'title' => 'Play That', 'title_accent' => 'Grows With Them',
                    'subtitle' => 'Wooden blocks, trains and building sets loved by little hands.',
                    'image' => self::img('abc_blocks', 1200), 'badge' => 'New in',
                    'primary_text' => 'Shop Toys', 'primary_url' => $link('Toys & Games'), 'secondary_text' => 'Gift Ideas', 'secondary_url' => '/shop',
                ],
            ],
            'tiles' => [
                ['title' => 'New Arrivals', 'subtitle' => 'Fresh picks just for you', 'button_text' => 'Shop Now', 'url' => '/shop?sort=latest', 'image' => self::img('teddy', 600), 'color' => '0'],
                ['title' => 'Summer Fun', 'subtitle' => 'Outdoor toys & essentials', 'button_text' => 'Shop Now', 'url' => $link('Outdoor'), 'image' => self::img('sprinkler', 600), 'color' => '3'],
                ['title' => 'Nursery Must-Haves', 'subtitle' => 'Create the perfect space for baby', 'button_text' => 'Shop Now', 'url' => $link('Nursery'), 'image' => self::img('sleeping_baby', 600), 'color' => '2'],
                ['title' => 'Feeding Time', 'subtitle' => 'Smart choices for happy meals', 'button_text' => 'Shop Now', 'url' => $link('Feeding'), 'image' => self::img('toddler_laugh', 600), 'color' => '1'],
            ],
        ];

        $s->forceFill([
            'theme' => 'toys',
            'theme_options' => $opts,
            'store_name' => in_array($s->store_name, [null, '', 'StoreX', 'TechNova', 'LittleJoy', 'HavenHomes', 'FreshMart'], true) ? 'LittleJoy' : $s->store_name,
            'primary_color' => '#7b6fd0',
            'secondary_color' => '#e8557a',
            'topbar_text_left' => 'Welcome Offer! Get 15% OFF on your first order',
            'topbar_text_right' => 'Free shipping on orders over $75',
            'footer_text' => 'Your one-stop shop for everything your little one needs. Quality, safety and happiness, always.',
        ])->save();

        // Only one demo catalog should be visible at a time; the other stays
        // in the database but hidden from the storefront.
        if (Schema::hasColumn('products', 'hide_from_online_store')) {
            Product::where('code', 'like', 'TY-%')->update(['hide_from_online_store' => 0]);
            Product::where('code', 'like', 'EL-%')->orWhere('code', 'like', 'GR-%')->update(['hide_from_online_store' => 1]);
        }

        if (function_exists('store_url_config_clear')) {
            store_url_config_clear();
        }
    }
}
