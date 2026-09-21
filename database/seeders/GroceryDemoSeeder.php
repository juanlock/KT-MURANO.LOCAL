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
 * Demo catalog for the Grocery & Supermarket theme: 10 categories with
 * subcategories, brands, ~70 products with Unsplash photo LINKS, stock in
 * every warehouse, reviews, and the theme activated with matching hero, tiles
 * and branding. Run:  php artisan db:seed --class=GroceryDemoSeeder
 * Idempotent: products carry GR- codes and are skipped when present.
 */
class GroceryDemoSeeder extends Seeder
{
    private const IMG = [
        'fruit_mix' => '1610832958506-aa56368176cf', 'bananas' => '1571771894821-ce9b6c11b08e', 'bananas_dark' => '1574226516831-e1dff420e562',
        'red_apples' => '1567306226416-28f0efdc88ce', 'potatoes' => '1518977676601-b53f82aba655', 'banana' => '1587132137056-bfbf0166836e',
        'pineapple' => '1550258987-190a2d41a8ba', 'apples' => '1560806887-1e4cd0b6cbd6', 'apple' => '1568702846914-96b305d2aaeb',
        'tropical' => '1619566636858-adf3ef46400b', 'strawberries' => '1464965911861-746a04b4bca6', 'orange' => '1557800636-894a64c1696f',
        'oranges' => '1547514701-42782101795e', 'berries_frozen' => '1596591606975-97ee5cef3a1e', 'bananas_bunch' => '1603833665858-e61d17a86224',
        'milk_carton' => '1563636619-e9143da7973b', 'milk_pour' => '1550583724-b2692b85b150', 'cheese_wheels' => '1486297678162-eb2a19b0a32d',
        'eggs' => '1582722872445-44dc5f7e3c8f', 'bread_loaves' => '1509440159596-0249088772ff', 'sliced_bread' => '1549931319-a545dcf3bc73',
        'sourdough' => '1586444248902-2f64eddc13df', 'croissant' => '1555507036-ab1f4038808a', 'croissants' => '1608198093002-ad4e005484ec',
        'blue_cheese' => '1452195100486-9cc805987862', 'meat_board' => '1607623814075-e51df1bdc82f', 'steak_raw' => '1603048297172-c92544798d5a',
        'salmon' => '1519708227418-c8fd9a32b7a2', 'fish' => '1544943910-4c1dc44aab44', 'ribs' => '1544025162-d76694265947',
        'orange_juice' => '1600271886742-f049cd451bba', 'smoothies' => '1622597467836-f3285f2131b8', 'juice_bottles' => '1554866585-cd94860d1ecb',
        'chips' => '1571091718767-18b5b1457add', 'crackers' => '1566478989037-eec170784d0b', 'cake' => '1599490659213-e2b9527bd087',
        'chocolates' => '1582716401301-b2407dc7563d', 'pasta' => '1587241321921-91a834d6d191', 'olive_oil' => '1551462147-ff29053bfc14',
        'spices' => '1474979266404-7eaacbcd87c5', 'shelves' => '1596040033229-a9821ebd058d', 'salad_bowl' => '1583258292688-d0213dc5a3a8',
        'veg_bowl' => '1540420773420-3366772f4999', 'vegetables' => '1512621776951-a57141f2eefd', 'veg_basket' => '1518843875459-f738682238a6',
        'asparagus' => '1597362925123-77861d3fbac7', 'veg_board' => '1595855759920-86582396756a', 'veg_mix' => '1610348725531-843dff563e2c',
        'produce_shelves' => '1573246123716-6b1782bfc499', 'grocery_bag' => '1542838132-92c53300491e', 'spray' => '1584622650111-993a426fbf0a',
        'water_glass' => '1563453392212-326f5e854473', 'coffee_cookies' => '1548839140-29a749e1cf4d', 'green_smoothie' => '1601050690597-df0568f70950',
        'strawberry' => '1610970881699-44a5587cabec', 'pie' => '1580910051074-3eb694886505', 'cherries' => '1594328906905-3d3fbd0e1f95',
        'broccoli' => '1601000938259-9e92002320b2', 'tomatoes' => '1559181567-c3190ca9959b', 'cola_ice' => '1615485290382-441e4d049cb5',
        'coffee_bag' => '1592924357228-91a4daadcfea', 'veg2' => '1533007716222-4b465613a984', 'salad' => '1559056199-641a0ac8b55e',
        'eggs2' => '1592417817098-8fd3d9eb14a5', 'onions' => '1598965675045-45c5e72c7d05', 'sweet_potatoes' => '1602858707092-364211809c11',
        'lychee' => '1508747703725-719777637510', 'peppers' => '1590779033100-9f60a05a013d', 'steak_cooked' => '1604329760661-e71dc83f8f26',
        'kiwi' => '1574856344991-aaa31b6f4ce3', 'avocado' => '1601648764658-cf37e8c89b70', 'pizza' => '1593280405106-e438ebe93f5b',
        'soup' => '1618897996318-5a901fa6ca71', 'sushi' => '1519162808019-7de1683fa2ad', 'pizza2' => '1593280405106-e438ebe93f5b',
        'fruit_bowl' => '1607301405390-d831c242f59b', 'juices' => '1622597467836-f3285f2131b8', 'bowls' => '1571066811602-716837d681de',
        'latte' => '1626200419199-391ae4be7a41', 'banana_pink' => '1519996529931-28324d5a630e', 'smoothie_bowls' => '1607301405390-d831c242f59b',
        'puppy' => '1603569283847-aa295f0d016a', 'melon' => '1590301157890-4810ed352733', 'lemonade' => '1541167760496-1628856ab772',
        'carrots' => '1481349518771-20055b2a7b24', 'espresso' => '1553530666-ba11a7da3888', 'brownie' => '1598511726623-d2e9996892f0',
    ];

    private static function img(string $key, int $w = 900): string
    {
        return 'https://images.unsplash.com/photo-'.self::IMG[$key].'?w='.$w.'&q=80&auto=format&fit=crop';
    }

    public function run(): void
    {
        if (Product::where('code', 'like', 'GR-%')->exists()) {
            $this->command?->warn('Grocery demo products already present — activating the theme only. (Delete GR-* products to reseed.)');
            $this->activateTheme();

            return;
        }

        DB::transaction(function () {
            $units = [];
            foreach (['pc' => 'Piece', 'kg' => 'Kilogram', 'L' => 'Litre', 'pack' => 'Pack'] as $short => $name) {
                $units[$short] = Unit::firstOrCreate(['ShortName' => $short], ['name' => $name, 'base_unit' => null, 'operator' => '*', 'operator_value' => 1, 'is_active' => 1]);
            }
            $warehouses = Warehouse::all();
            if ($warehouses->isEmpty()) {
                $warehouses = collect([Warehouse::create(['name' => 'Main Warehouse', 'city' => 'Main', 'country' => '', 'mobile' => '', 'email' => '', 'zip' => ''])]);
            }

            $catDefs = [
                'Fruits & Vegetables' => ['icon' => 'apple-icon', 'subs' => ['Fresh Fruits', 'Fresh Vegetables', 'Herbs & Salads', 'Organic']],
                'Dairy & Eggs' => ['icon' => 'milk', 'subs' => ['Milk', 'Cheese', 'Yogurt', 'Eggs', 'Butter & Cream']],
                'Bakery' => ['icon' => 'bread', 'subs' => ['Bread', 'Pastries', 'Cakes']],
                'Meat & Seafood' => ['icon' => 'fish', 'subs' => ['Beef', 'Poultry', 'Fish', 'Seafood']],
                'Beverages' => ['icon' => 'cup', 'subs' => ['Juices', 'Soft Drinks', 'Water', 'Coffee & Tea']],
                'Snacks & Sweets' => ['icon' => 'cookie', 'subs' => ['Chips', 'Chocolate', 'Biscuits', 'Candy']],
                'Pantry' => ['icon' => 'package', 'subs' => ['Pasta & Rice', 'Oils & Sauces', 'Spices', 'Canned Goods', 'Cereals']],
                'Frozen' => ['icon' => 'snowflake', 'subs' => ['Frozen Fruit', 'Ready Meals', 'Ice Cream']],
                'Household' => ['icon' => 'home', 'subs' => ['Cleaning', 'Paper & Tissue', 'Laundry']],
                'Pet Care' => ['icon' => 'heart', 'subs' => ['Dog Food', 'Cat Food', 'Treats']],
            ];
            $cats = [];
            $subs = [];
            $n = 1;
            foreach ($catDefs as $name => $def) {
                $c = Category::firstOrCreate(['name' => $name], ['code' => 'GR-C'.$n, 'icon' => $def['icon']]);
                if (! $c->icon) {
                    $c->update(['icon' => $def['icon']]);
                }
                $cats[$name] = $c;
                foreach ($def['subs'] as $sn) {
                    $subs[$name][$sn] = SubCategory::firstOrCreate(['category_id' => $c->id, 'name' => $sn], ['description' => '', 'status' => 1]);
                }
                $n++;
            }

            $brandNames = ['FreshFarm', 'Organic Valley', 'Danone', 'Nestlé', 'Coca-Cola', "Kellogg's", 'Barilla', 'Heinz', 'Lipton', 'Lavazza', 'Tropicana', "Lay's", 'Lindt', 'Oreo', 'Philadelphia', 'Bonne Maman', 'Persil', 'Fairy', 'Pedigree', 'Whiskas', 'Evian', "Ben & Jerry's"];
            $brands = [];
            foreach ($brandNames as $b) {
                $brands[$b] = Brand::firstOrCreate(['name' => $b], ['description' => $b.' products']);
            }

            // [name (with unit suffix), brand, category, sub, unit, cost, price, discount%, images, featured, note]
            $rows = [
                ['Bananas – 1 kg', 'FreshFarm', 'Fruits & Vegetables', 'Fresh Fruits', 'kg', 0.9, 1.49, 0, ['bananas', 'bananas_bunch'], 1, 'Sweet Cavendish bananas, ripened naturally. Perfect for snacks and smoothies.'],
                ['Red Apples – 1 kg', 'FreshFarm', 'Fruits & Vegetables', 'Fresh Fruits', 'kg', 1.6, 2.49, 10, ['red_apples', 'apples'], 1, 'Crisp, juicy Royal Gala apples from local orchards.'],
                ['Strawberries – 400 g', 'FreshFarm', 'Fruits & Vegetables', 'Fresh Fruits', 'pack', 2.2, 3.99, 15, ['strawberries', 'strawberry'], 1, 'Hand-picked strawberries, sweet and fragrant.'],
                ['Navel Oranges – 1.5 kg', 'FreshFarm', 'Fruits & Vegetables', 'Fresh Fruits', 'pack', 2.0, 3.29, 0, ['oranges', 'orange'], 0, 'Seedless navel oranges, ideal for juicing.'],
                ['Golden Pineapple – 1 pc', 'FreshFarm', 'Fruits & Vegetables', 'Fresh Fruits', 'pc', 1.5, 2.79, 0, ['pineapple'], 0, 'Extra-sweet golden pineapple, ready to eat.'],
                ['Tropical Fruit Box – 2 kg', 'FreshFarm', 'Fruits & Vegetables', 'Fresh Fruits', 'pack', 6.5, 11.99, 20, ['tropical', 'fruit_mix'], 1, 'Mango, kiwi, pineapple and grapes in one box.'],
                ['Avocados – Pack of 2', 'Organic Valley', 'Fruits & Vegetables', 'Organic', 'pack', 1.4, 2.69, 0, ['avocado'], 0, 'Ripe-and-ready Hass avocados, organically grown.'],
                ['Cherries – 500 g', 'FreshFarm', 'Fruits & Vegetables', 'Fresh Fruits', 'pack', 3.5, 5.99, 0, ['cherries'], 0, 'Dark sweet cherries at peak season.'],
                ['Kiwi Fruit – Pack of 6', 'FreshFarm', 'Fruits & Vegetables', 'Fresh Fruits', 'pack', 1.5, 2.49, 0, ['kiwi'], 0, 'Green kiwis, rich in vitamin C.'],
                ['Cantaloupe Melon – 1 pc', 'FreshFarm', 'Fruits & Vegetables', 'Fresh Fruits', 'pc', 1.6, 2.99, 12, ['melon'], 0, 'Fragrant, sweet cantaloupe.'],
                ['Lychees – 400 g', 'FreshFarm', 'Fruits & Vegetables', 'Fresh Fruits', 'pack', 2.8, 4.49, 0, ['lychee'], 0, 'Fresh lychees, juicy and floral.'],
                ['Potatoes – 2.5 kg', 'FreshFarm', 'Fruits & Vegetables', 'Fresh Vegetables', 'pack', 1.4, 2.49, 0, ['potatoes'], 0, 'All-rounder potatoes for roasting, mashing and chips.'],
                ['Vine Tomatoes – 500 g', 'FreshFarm', 'Fruits & Vegetables', 'Fresh Vegetables', 'pack', 1.1, 1.99, 0, ['tomatoes'], 1, 'Sun-ripened vine tomatoes, full of flavour.'],
                ['Broccoli – 1 pc', 'Organic Valley', 'Fruits & Vegetables', 'Organic', 'pc', 0.8, 1.49, 0, ['broccoli'], 0, 'Organic broccoli crowns.'],
                ['Carrots – 1 kg', 'FreshFarm', 'Fruits & Vegetables', 'Fresh Vegetables', 'kg', 0.6, 1.09, 0, ['carrots'], 0, 'Sweet, crunchy carrots.'],
                ['Bell Peppers – Pack of 3', 'FreshFarm', 'Fruits & Vegetables', 'Fresh Vegetables', 'pack', 1.3, 2.29, 0, ['peppers'], 0, 'Red, yellow and green peppers.'],
                ['Red Onions – 1 kg', 'FreshFarm', 'Fruits & Vegetables', 'Fresh Vegetables', 'kg', 0.7, 1.29, 0, ['onions'], 0, 'Mild and sweet red onions.'],
                ['Sweet Potatoes – 1 kg', 'FreshFarm', 'Fruits & Vegetables', 'Fresh Vegetables', 'kg', 1.2, 1.99, 0, ['sweet_potatoes'], 0, 'Orange-flesh sweet potatoes.'],
                ['Asparagus – 250 g', 'Organic Valley', 'Fruits & Vegetables', 'Organic', 'pack', 2.2, 3.79, 0, ['asparagus'], 0, 'Tender green asparagus spears.'],
                ['Mixed Salad Leaves – 150 g', 'FreshFarm', 'Fruits & Vegetables', 'Herbs & Salads', 'pack', 1.0, 1.89, 0, ['salad', 'salad_bowl'], 0, 'Washed and ready-to-eat salad mix.'],
                ['Seasonal Veg Box – 4 kg', 'Organic Valley', 'Fruits & Vegetables', 'Organic', 'pack', 9.0, 15.99, 18, ['veg_basket', 'veg_board', 'veg_mix'], 1, 'A week of organic vegetables, picked this morning.'],

                ['Whole Milk – 1 L', 'Organic Valley', 'Dairy & Eggs', 'Milk', 'L', 0.8, 1.29, 0, ['milk_carton', 'milk_pour'], 1, 'Fresh whole milk from grass-fed cows.'],
                ['Semi-Skimmed Milk – 2 L', 'Organic Valley', 'Dairy & Eggs', 'Milk', 'L', 1.3, 2.09, 0, ['milk_pour'], 0, 'Lighter milk, same great taste.'],
                ['Free-Range Eggs – Pack of 12', 'FreshFarm', 'Dairy & Eggs', 'Eggs', 'pack', 2.1, 3.49, 10, ['eggs', 'eggs2'], 1, 'Large free-range eggs from happy hens.'],
                ['Aged Cheddar – 400 g', 'Organic Valley', 'Dairy & Eggs', 'Cheese', 'pack', 3.2, 5.49, 0, ['cheese_wheels'], 0, 'Matured 12 months for a rich, nutty flavour.'],
                ['Blue Cheese – 200 g', 'Organic Valley', 'Dairy & Eggs', 'Cheese', 'pack', 2.6, 4.29, 15, ['blue_cheese'], 0, 'Creamy, tangy blue cheese.'],
                ['Cream Cheese – 300 g', 'Philadelphia', 'Dairy & Eggs', 'Butter & Cream', 'pack', 1.8, 2.99, 0, ['cheese_wheels'], 0, 'Smooth and spreadable.'],
                ['Greek Yogurt – 500 g', 'Danone', 'Dairy & Eggs', 'Yogurt', 'pack', 1.4, 2.39, 0, ['smoothie_bowls'], 0, 'Thick and creamy, 10% fat.'],

                ['Sourdough Loaf – 800 g', 'FreshFarm', 'Bakery', 'Bread', 'pc', 1.9, 3.49, 0, ['sourdough', 'bread_loaves'], 1, 'Slow-fermented sourdough, baked daily.'],
                ['Wholemeal Sliced Bread – 800 g', 'FreshFarm', 'Bakery', 'Bread', 'pc', 1.0, 1.79, 0, ['sliced_bread'], 0, 'Soft wholemeal loaf, pre-sliced.'],
                ['Butter Croissants – Pack of 4', 'FreshFarm', 'Bakery', 'Pastries', 'pack', 1.7, 2.99, 10, ['croissant', 'croissants'], 1, 'Flaky, all-butter croissants.'],
                ['Chocolate Fudge Cake – 900 g', 'FreshFarm', 'Bakery', 'Cakes', 'pc', 5.5, 9.99, 0, ['cake'], 0, 'Layers of moist sponge and fudge frosting.'],
                ['Apple Pie – 700 g', 'FreshFarm', 'Bakery', 'Cakes', 'pc', 3.4, 5.99, 0, ['pie'], 0, 'Classic pie with cinnamon-spiced apples.'],
                ['Fudge Brownies – Pack of 6', 'FreshFarm', 'Bakery', 'Cakes', 'pack', 2.3, 3.99, 0, ['brownie'], 0, 'Rich, gooey chocolate brownies.'],

                ['Beef Ribeye Steak – 500 g', 'FreshFarm', 'Meat & Seafood', 'Beef', 'pack', 8.5, 13.99, 0, ['steak_raw', 'steak_cooked'], 1, 'Grass-fed ribeye, well marbled.'],
                ['Pork Ribs – 1 kg', 'FreshFarm', 'Meat & Seafood', 'Beef', 'kg', 5.0, 8.49, 12, ['ribs'], 0, 'Meaty baby-back ribs for the grill.'],
                ['Atlantic Salmon Fillet – 400 g', 'FreshFarm', 'Meat & Seafood', 'Fish', 'pack', 6.5, 10.99, 0, ['salmon'], 1, 'Skin-on salmon fillets, responsibly sourced.'],
                ['Red Snapper – 1 kg', 'FreshFarm', 'Meat & Seafood', 'Fish', 'kg', 7.0, 11.49, 0, ['fish'], 0, 'Whole red snapper, cleaned.'],
                ['Butcher\'s Selection Box – 2 kg', 'FreshFarm', 'Meat & Seafood', 'Beef', 'pack', 18.0, 29.99, 15, ['meat_board'], 0, 'Steaks, mince and sausages for the week.'],
                ['Sushi Platter – 16 pcs', 'FreshFarm', 'Meat & Seafood', 'Seafood', 'pack', 6.0, 10.49, 0, ['sushi'], 0, 'Freshly rolled salmon and tuna sushi.'],

                ['Orange Juice – 1 L', 'Tropicana', 'Beverages', 'Juices', 'L', 1.5, 2.49, 0, ['orange_juice', 'juice_bottles'], 1, '100% squeezed, not from concentrate.'],
                ['Cold-Pressed Juice Trio – 3 × 250 ml', 'FreshFarm', 'Beverages', 'Juices', 'pack', 4.0, 6.99, 20, ['juices', 'smoothies'], 0, 'Green, orange and berry blends.'],
                ['Green Smoothie – 500 ml', 'FreshFarm', 'Beverages', 'Juices', 'pc', 1.8, 2.99, 0, ['green_smoothie'], 0, 'Spinach, kiwi and apple.'],
                ['Cola – 6 × 330 ml', 'Coca-Cola', 'Beverages', 'Soft Drinks', 'pack', 2.4, 3.99, 10, ['cola_ice'], 0, 'Ice-cold classic cola.'],
                ['Lemonade – 1 L', 'FreshFarm', 'Beverages', 'Soft Drinks', 'L', 1.1, 1.89, 0, ['lemonade'], 0, 'Cloudy lemonade with real lemons.'],
                ['Natural Mineral Water – 6 × 1.5 L', 'Evian', 'Beverages', 'Water', 'pack', 2.6, 4.29, 0, ['water_glass'], 0, 'Still mineral water from the Alps.'],
                ['Ground Coffee – 500 g', 'Lavazza', 'Beverages', 'Coffee & Tea', 'pack', 4.5, 7.49, 15, ['coffee_bag', 'espresso'], 1, 'Medium roast, smooth and aromatic.'],
                ['Oat Latte – 250 ml', 'Nestlé', 'Beverages', 'Coffee & Tea', 'pc', 1.2, 1.99, 0, ['latte'], 0, 'Ready-to-drink oat milk latte.'],
                ['Black Tea – 100 bags', 'Lipton', 'Beverages', 'Coffee & Tea', 'pack', 1.8, 2.99, 0, ['coffee_cookies'], 0, 'Classic everyday black tea.'],

                ['Potato Chips – 150 g', "Lay's", 'Snacks & Sweets', 'Chips', 'pack', 1.0, 1.79, 0, ['chips'], 0, 'Salted, crunchy and moreish.'],
                ['Rice Crackers – 100 g', 'Nestlé', 'Snacks & Sweets', 'Chips', 'pack', 0.9, 1.49, 0, ['crackers'], 0, 'Light and crispy sea-salt crackers.'],
                ['Assorted Chocolates – 300 g', 'Lindt', 'Snacks & Sweets', 'Chocolate', 'pack', 4.0, 6.99, 20, ['chocolates'], 1, 'Pralines, truffles and caramels.'],
                ['Chocolate Cookies – 176 g', 'Oreo', 'Snacks & Sweets', 'Biscuits', 'pack', 0.9, 1.49, 0, ['coffee_cookies'], 0, 'Sandwich cookies with vanilla cream.'],

                ['Spaghetti – 1 kg', 'Barilla', 'Pantry', 'Pasta & Rice', 'pack', 1.2, 1.99, 0, ['pasta'], 1, 'Bronze-cut durum wheat spaghetti.'],
                ['Extra Virgin Olive Oil – 750 ml', 'FreshFarm', 'Pantry', 'Oils & Sauces', 'pc', 4.5, 7.99, 10, ['olive_oil'], 1, 'Cold-pressed, peppery and fruity.'],
                ['Spice Rack Set – 12 jars', 'FreshFarm', 'Pantry', 'Spices', 'pack', 6.0, 9.99, 0, ['spices'], 0, 'Twelve essential spices in glass jars.'],
                ['Tomato Ketchup – 570 g', 'Heinz', 'Pantry', 'Oils & Sauces', 'pc', 1.5, 2.49, 0, ['tomatoes'], 0, 'The classic squeeze bottle.'],
                ['Corn Flakes – 750 g', "Kellogg's", 'Pantry', 'Cereals', 'pack', 2.0, 3.29, 0, ['bowls'], 0, 'Golden, crunchy corn flakes.'],
                ['Strawberry Jam – 370 g', 'Bonne Maman', 'Pantry', 'Oils & Sauces', 'pc', 1.9, 3.19, 0, ['strawberry'], 0, 'Made with whole strawberries.'],

                ['Frozen Mixed Berries – 1 kg', 'FreshFarm', 'Frozen', 'Frozen Fruit', 'pack', 3.0, 4.99, 0, ['berries_frozen'], 1, 'Blueberries, raspberries and blackberries.'],
                ['Margherita Pizza – 350 g', 'FreshFarm', 'Frozen', 'Ready Meals', 'pc', 1.8, 2.99, 12, ['pizza', 'pizza2'], 0, 'Stone-baked base with mozzarella.'],
                ['Butternut Soup – 600 g', 'FreshFarm', 'Frozen', 'Ready Meals', 'pc', 1.6, 2.79, 0, ['soup'], 0, 'Roasted butternut squash soup.'],
                ['Chocolate Fudge Ice Cream – 465 ml', "Ben & Jerry's", 'Frozen', 'Ice Cream', 'pc', 3.2, 5.49, 0, ['brownie'], 0, 'Chocolate ice cream with fudge brownies.'],

                ['Multi-Surface Spray – 750 ml', 'Fairy', 'Household', 'Cleaning', 'pc', 1.4, 2.49, 0, ['spray'], 0, 'Cuts through grease on every surface.'],
                ['Laundry Detergent – 2.5 L', 'Persil', 'Household', 'Laundry', 'pc', 4.5, 7.99, 15, ['spray'], 0, '50 washes, colour-safe.'],
                ['Reusable Grocery Bag', 'FreshFarm', 'Household', 'Paper & Tissue', 'pc', 0.8, 1.99, 0, ['grocery_bag'], 0, 'Cotton mesh bag, holds 15 kg.'],

                ['Puppy Dry Food – 3 kg', 'Pedigree', 'Pet Care', 'Dog Food', 'pack', 6.0, 9.99, 0, ['puppy'], 1, 'Complete nutrition for growing puppies.'],
                ['Cat Food Pouches – 12 × 85 g', 'Whiskas', 'Pet Care', 'Cat Food', 'pack', 3.0, 4.99, 10, ['puppy'], 0, 'Mixed selection in gravy.'],
            ];

            $created = [];
            $i = 1;
            foreach ($rows as $r) {
                [$name, $brand, $cat, $sub, $unitKey, $cost, $price, $disc, $imgs, $featured, $note] = $r;
                $unit = $units[$unitKey] ?? $units['pc'];
                $data = [
                    'code' => 'GR-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                    'Type_barcode' => 'CODE128',
                    'name' => $name,
                    'cost' => $cost,
                    'price' => $price,
                    'unit_id' => $unit->id,
                    'unit_sale_id' => $unit->id,
                    'unit_purchase_id' => $unit->id,
                    'stock_alert' => 10,
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
                    'created_at' => Carbon::now()->subDays(random_int(0, 90))->subMinutes($i),
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
                    $urls = array_values(array_unique(array_map(fn ($key) => self::img($key), $imgs)));
                    foreach ($urls as $k => $url) {
                        ProductImage::create(['product_id' => $product->id, 'image_path' => $url, 'is_main' => $k === 0, 'sort_order' => $k]);
                    }
                }
                foreach ($warehouses as $wh) {
                    product_warehouse::create(['product_id' => $product->id, 'warehouse_id' => $wh->id, 'qte' => random_int(20, 200), 'manage_stock' => 1]);
                }
                $created[] = $product;
                $i++;
            }

            if (Schema::hasTable('product_reviews')) {
                $clients = Client::query()->take(12)->get();
                $names = ['Hannah Moore', 'Omar Haddad', 'Julia Weber', 'Samuel Okafor', 'Ines Garcia', 'Tom Becker', 'Priya Nair', 'Leo Martin'];
                for ($k = $clients->count(); $k < 8; $k++) {
                    $nm = $names[$k];
                    $clients->push(Client::create(['code' => 99001 + $k, 'name' => $nm, 'firstname' => explode(' ', $nm)[0], 'lastname' => explode(' ', $nm)[1] ?? '', 'email' => strtolower(str_replace(' ', '.', $nm)).'@example.com', 'phone' => '+1 555 03'.str_pad((string) $k, 2, '0', STR_PAD_LEFT), 'country' => 'United States', 'city' => 'Portland', 'opening_balance' => 0]));
                }
                $comments = [
                    5 => ['Always fresh and delivered on time. Our weekly order is sorted!', 'Great quality, tastes just like the farmers market.', 'Perfectly ripe and well packed. Will order again.', 'Excellent value for organic produce.'],
                    4 => ['Very good, one item was slightly bruised but the rest was perfect.', 'Good price and quick delivery.', 'Tasty and fresh, packaging could use less plastic.'],
                    3 => ['Fine, nothing special. Does the job.'],
                ];
                $reviewerNames = ['Hannah M.', 'Omar H.', 'Julia W.', 'Samuel O.', 'Ines G.', 'Tom B.', 'Priya N.', 'Leo M.'];
                $seq = 0;
                foreach ($created as $idx => $product) {
                    $count = $product->is_featured ? random_int(3, 7) : ($idx % 3 === 0 ? 0 : random_int(1, 3));
                    for ($k = 0; $k < $count; $k++) {
                        $rating = $product->is_featured ? (random_int(1, 10) > 2 ? 5 : 4) : [5, 5, 4, 4, 4, 3][random_int(0, 5)];
                        $client = $clients[$seq % $clients->count()];
                        $seq++;
                        ProductReview::create(['product_id' => $product->id, 'client_id' => $client->id, 'online_order_id' => null, 'reviewer_name' => $reviewerNames[$seq % count($reviewerNames)], 'rating' => $rating, 'comment' => $comments[$rating][array_rand($comments[$rating])], 'status' => 'approved', 'created_at' => Carbon::now()->subDays(random_int(1, 60)), 'updated_at' => Carbon::now()]);
                    }
                }
            }
        });

        $this->activateTheme();
        $this->command?->info('Grocery demo catalog seeded and the Grocery theme activated.');
    }

    protected function activateTheme(): void
    {
        $s = StoreSetting::first();
        if (! $s) {
            return;
        }
        $catId = fn ($name) => Category::where('name', $name)->value('id');
        $link = fn ($name) => ($id = $catId($name)) ? '/shop?category='.$id : '/shop';

        $opts = is_array($s->theme_options) ? $s->theme_options : [];
        $opts['grocery'] = [
            'delivery_text' => 'Free delivery on orders over $49 · Order before 2pm for same-day delivery',
            'delivery_time' => 'Delivery in 30–60 min',
            'slides' => [
                ['kicker' => 'Fresh every day', 'title' => 'Farm-fresh groceries delivered to your door', 'subtitle' => 'Fruit, vegetables, dairy and pantry staples at supermarket prices — picked fresh, delivered fast.', 'image' => self::img('produce_shelves', 1400), 'badge' => 'Up to 30% off this week', 'primary_text' => 'Shop Now', 'primary_url' => '/shop', 'secondary_text' => 'Weekly Deals', 'secondary_url' => '/shop?deals=1'],
                ['kicker' => 'Weekend brunch', 'title' => 'Bakery favourites, baked this morning', 'subtitle' => 'Sourdough, croissants and cakes from our in-store bakery.', 'image' => self::img('croissants', 1400), 'badge' => 'Croissants 10% off', 'primary_text' => 'Shop Bakery', 'primary_url' => $link('Bakery'), 'secondary_text' => 'See All Deals', 'secondary_url' => '/shop?deals=1'],
            ],
            'tiles' => [
                ['title' => 'Fresh Fruits', 'subtitle' => 'Picked this morning', 'button_text' => 'Shop Fruits', 'url' => $link('Fruits & Vegetables'), 'image' => self::img('fruit_mix', 800), 'tone' => 'green'],
                ['title' => 'Bakery', 'subtitle' => 'Baked fresh daily', 'button_text' => 'Shop Bakery', 'url' => $link('Bakery'), 'image' => self::img('bread_loaves', 800), 'tone' => 'orange'],
                ['title' => 'Dairy & Eggs', 'subtitle' => 'From local farms', 'button_text' => 'Shop Dairy', 'url' => $link('Dairy & Eggs'), 'image' => self::img('milk_pour', 800), 'tone' => 'blue'],
                ['title' => 'Meat & Seafood', 'subtitle' => 'Butcher quality', 'button_text' => 'Shop Meat', 'url' => $link('Meat & Seafood'), 'image' => self::img('salmon', 800), 'tone' => 'red'],
                ['title' => 'Snacks & Sweets', 'subtitle' => 'Treat yourself', 'button_text' => 'Shop Snacks', 'url' => $link('Snacks & Sweets'), 'image' => self::img('chocolates', 800), 'tone' => 'yellow'],
            ],
        ];

        $s->forceFill([
            'theme' => 'grocery',
            'theme_options' => $opts,
            'store_name' => in_array($s->store_name, [null, '', 'StoreX', 'TechNova', 'LittleJoy', 'HavenHomes', 'FreshMart'], true) ? 'FreshMart' : $s->store_name,
            'primary_color' => '#16a34a',
            'secondary_color' => '#f97316',
            'topbar_text_left' => 'Free delivery on orders over $49',
            'topbar_text_right' => 'Order before 2pm for same-day delivery',
            'footer_text' => 'Fresh groceries, fair prices and friendly delivery — from our shelves to your kitchen in under an hour.',
        ])->save();

        // Only one demo catalog visible at a time.
        if (Schema::hasColumn('products', 'hide_from_online_store')) {
            Product::where('code', 'like', 'GR-%')->update(['hide_from_online_store' => 0]);
            Product::where('code', 'like', 'EL-%')->orWhere('code', 'like', 'TY-%')->update(['hide_from_online_store' => 1]);
        }
        if (function_exists('store_url_config_clear')) {
            store_url_config_clear();
        }
    }
}
