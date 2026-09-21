<?php

namespace Database\Seeders;

use App\Models\Property;
use App\Models\PropertyCategory;
use App\Models\StoreSetting;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Demo listings for the Real Estate storefront theme: 6 categories, 36
 * properties across 8 cities (sale + rent, a few sold/rented) with Unsplash
 * photo LINKS for the featured image and gallery, amenities, agents and
 * coordinates, and the theme switched on with matching branding.
 *
 * Run:  php artisan db:seed --class=RealEstateDemoSeeder
 *
 * Idempotent: demo rows carry the "demo-" slug prefix and are skipped when
 * present (the theme is still activated).
 */
class RealEstateDemoSeeder extends Seeder
{
    /** Validated Unsplash photo ids by subject. */
    private const IMG = [
        // exteriors
        'modern_dusk' => '1568605114967-8130f3a36994', 'colonial' => '1570129477492-45c003edd2be', 'villa_pool' => '1580587771525-78b9dba3b914',
        'modern_white' => '1512917774080-9991f1c4c750', 'modern_pool' => '1600596542815-ffad4c1539a9', 'modern_house' => '1600585154340-be6161a56a0c',
        'flat_roof' => '1600566753190-17f0baa2a6c3', 'villa_pool2' => '1613490493576-7fde63acd811', 'modern2' => '1600047509807-ba8f99d2cdde',
        'dark_modern' => '1600585154526-990dced4db0d', 'brick_suburb' => '1605276374104-dee2a0ed3cd6', 'suburban' => '1583608205776-bfd35f0d9f83',
        'villa_palm' => '1564013799919-ab600027ffc6', 'modern_box' => '1600585153490-76fb20a32601', 'modern_black' => '1600607688969-a5bfcd646154',
        'old_villa' => '1571939228382-b2f2b585ce15', 'red_brick' => '1449844908441-8829872d2607', 'white_modern' => '1523217582562-09d0def993a6',
        'spanish_villa' => '1416331108676-a22ccb276e35', 'house_dusk' => '1494526585095-c41746248156', 'white_palm' => '1512915922686-57c11dde9b6b',
        'house_sunset' => '1549517045-bc93de075e53', 'modern3' => '1600047509358-9dc75507daeb', 'dark_modern2' => '1600585154363-67eb9e2e2099',
        'brick_colonial' => '1598228723793-52759bba239c', 'farmhouse' => '1592595896551-12b371d546d5', 'bungalow' => '1628744448840-55bdb2497bd4',
        'villa_pool3' => '1582268611958-ebfd161ef9cf', 'cabin' => '1589129140837-67287c22521b', 'red_cabin' => '1518780664697-55e3ad937233',
        'forest_cabin' => '1449158743715-0a90ebb6d2d8', 'lake_cabin' => '1470770903676-69b98201ea1c', 'mountain_cabin' => '1510798831971-661eb04b3739',
        // apartments / buildings
        'apt_building' => '1515263487990-61b07816b324', 'apt_balconies' => '1574362848149-11496d93a7c7', 'apt_facade' => '1460317442991-0ec209397118',
        'apt_complex' => '1527030280862-64139fba04ca', 'towers' => '1523192193543-6e7296d960e4',
        // offices / commercial
        'office_glass' => '1497366754035-f200968a6e72', 'office_hall' => '1497366216548-37526070297c', 'office_plants' => '1497215728101-856f4ea42174',
        'skyscrapers' => '1486406146926-c627a92ad1ab', 'building' => '1511818966892-d7d671e672a2', 'city_night' => '1486325212027-8081e485255e',
        'skyline' => '1477959858617-67f85cf4f1df', 'skyline2' => '1444084316824-dc26d6657664', 'office_dining' => '1593696140826-c58b021acf8b',
        // land
        'construction' => '1541888946425-d81bb19240f5', 'site' => '1504307651254-35680f356dfd', 'vineyard' => '1506377247377-2a5b3b417ebb',
        // interiors
        'living1' => '1600607687939-ce8a6c25118c', 'bedroom1' => '1600607687644-c7171b42498f', 'interior1' => '1600573472591-ee6b68d14c68',
        'kitchen1' => '1600585152220-90363fe7e115', 'bathroom1' => '1600566752355-35792bedcfea', 'living2' => '1600210491892-03d54c0aaf87',
        'living3' => '1600566753086-00f18fb6b3ea', 'living4' => '1600121848594-d8644e57abab', 'living5' => '1600210492486-724fe5c67fb0',
        'living6' => '1560448204-e02f11c3d0e2', 'dining1' => '1560185007-cde436f6a4d0', 'porch' => '1560184897-ae75f418493e',
        'living7' => '1560185127-6ed189bf02f4', 'bedroom2' => '1560185893-a55cbc8c57e8', 'living8' => '1502672260266-1c1ef2d93688',
        'living9' => '1493809842364-78817add7ffb', 'kitchen2' => '1484154218962-a197022b5858', 'living10' => '1522708323590-d24dbb6b0267',
        'living11' => '1567767292278-a4f21aa2d36e', 'living12' => '1554995207-c18c203602cb', 'loft1' => '1505873242700-f289a29e1e0f',
        'loft2' => '1524758631624-e2822e304c36', 'bedroom3' => '1512918728675-ed5a9ecdebfd', 'stairs' => '1502005229762-cf1b2da7c5d6',
        'dining2' => '1519643381401-22c77e60520e', 'living13' => '1513694203232-719a280e022f', 'living14' => '1505691938895-1758d7feb511',
        'bedroom4' => '1556020685-ae41abfc9365', 'kitchen3' => '1556911220-bff31c812dba', 'kitchen4' => '1556909212-d5b604d0c90d',
        'kitchen5' => '1588854337115-1c67d9247e4d', 'kitchen6' => '1588854337221-4cf9fa96059c', 'hallway' => '1502005097973-6a7082348e28',
        'living15' => '1501183638710-841dd1904471', 'living16' => '1467987506553-8f3916508521', 'bedroom5' => '1521783988139-89397d761dce',
        'kitchen7' => '1507089947368-19c1da9775ae', 'living17' => '1523755231516-e43fd2e8dca5', 'living18' => '1536376072261-38c75010e6c9',
        'kitchen8' => '1600566752229-250ed79470f8', 'pool_interior' => '1600573472550-8090b5e0745e', 'living19' => '1600210491369-e753d80a41f3',
        'kitchen9' => '1600489000022-c2086d79f9d4', 'house_int' => '1600563438938-a9a27216b4f5', 'kitchen10' => '1600607686527-6fb886090705',
        'living20' => '1600210492493-0946911123ea', 'living21' => '1616486338812-3dadae4b4ace', 'bedroom6' => '1616594039964-ae9021a400a0',
        'bedroom7' => '1615874959474-d609969a20ed', 'living22' => '1615873968403-89e068629265', 'dining3' => '1617806118233-18e1de247200',
        'living23' => '1618221195710-dd6b41faaea6', 'bedroom8' => '1595526114035-0d45ed16cfbf', 'living24' => '1599619351208-3e6c839d6828',
    ];

    private static function img(string $key, int $w = 1200): string
    {
        return 'https://images.unsplash.com/photo-'.self::IMG[$key].'?w='.$w.'&q=80&auto=format&fit=crop';
    }

    public function run(): void
    {
        if (Property::where('slug', 'like', 'demo-%')->exists()) {
            $this->command?->warn('Real estate demo listings already present — activating the theme only. (Delete demo-* properties to reseed.)');
            $this->activateTheme();

            return;
        }

        DB::transaction(function () {
            $cats = [];
            foreach ([
                'Apartment' => 'Modern apartments and condos in the heart of the city.',
                'House' => 'Family homes with gardens, garages and room to grow.',
                'Villa' => 'Luxury villas with pools, views and privacy.',
                'Office' => 'Bright, connected workspaces for teams of every size.',
                'Commercial Property' => 'Retail, warehouses and mixed-use investments.',
                'Land' => 'Plots and development land ready to build.',
            ] as $name => $desc) {
                $cats[$name] = PropertyCategory::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name, 'description' => $desc]);
            }

            $agents = [
                ['Sarah Mitchell', '+1 305 555 0142', 'sarah@estate.test', '13055550142'],
                ['Daniel Ortega', '+1 512 555 0177', 'daniel@estate.test', '15125550177'],
                ['Emily Chen', '+1 619 555 0119', 'emily@estate.test', '16195550119'],
                ['Marcus Reed', '+1 206 555 0163', 'marcus@estate.test', '12065550163'],
            ];
            $cities = [
                'Miami' => ['Florida', 25.7617, -80.1918],
                'Austin' => ['Texas', 30.2672, -97.7431],
                'San Diego' => ['California', 32.7157, -117.1611],
                'Seattle' => ['Washington', 47.6062, -122.3321],
                'Denver' => ['Colorado', 39.7392, -104.9903],
                'Chicago' => ['Illinois', 41.8781, -87.6298],
                'Nashville' => ['Tennessee', 36.1627, -86.7816],
                'Scottsdale' => ['Arizona', 33.4942, -111.9261],
            ];
            $amenityPool = ['Air Conditioning', 'Central Heating', 'Swimming Pool', 'Garden', 'Garage', 'Balcony', 'Gym', 'Security 24/7', 'Elevator', 'Fireplace', 'Smart Home', 'Solar Panels', 'Walk-in Closet', 'Home Office', 'Sea View', 'Mountain View', 'Laundry Room', 'Pet Friendly', 'EV Charging', 'Rooftop Terrace'];

            // [title, category, purpose, price, area, beds, baths, garage, city, address, featured, status, images(featured first)]
            $rows = [
                ['Modern Waterfront Villa with Infinity Pool', 'Villa', 'sale', 2850000, 420, 5, 5, 3, 'Miami', '1420 Bay Harbor Drive', 1, 'available', ['villa_pool', 'pool_interior', 'living1', 'kitchen1', 'bedroom1']],
                ['Contemporary Glass House in Coconut Grove', 'House', 'sale', 1950000, 360, 4, 4, 2, 'Miami', '88 Tigertail Ave', 1, 'available', ['modern_pool', 'living2', 'kitchen8', 'bedroom6']],
                ['Sky-High 2BR Condo with Ocean Views', 'Apartment', 'rent', 4800, 118, 2, 2, 1, 'Miami', '900 Biscayne Blvd, Unit 4102', 1, 'available', ['towers', 'living21', 'bedroom7', 'kitchen9']],
                ['Boutique Office Floor in Brickell', 'Office', 'rent', 12500, 540, null, 2, 8, 'Miami', '1111 Brickell Ave, 15th Floor', 0, 'available', ['office_glass', 'office_hall', 'office_plants']],
                ['Charming Palm-Shaded Bungalow', 'House', 'sale', 725000, 165, 3, 2, 1, 'Miami', '2210 SW 22nd St', 0, 'sold', ['white_palm', 'living6', 'kitchen2']],

                ['Hill Country Modern Farmhouse', 'House', 'sale', 1180000, 310, 4, 3, 2, 'Austin', '5501 Barton Creek Trail', 1, 'available', ['farmhouse', 'living9', 'kitchen3', 'porch', 'bedroom2']],
                ['Downtown Loft with Exposed Brick', 'Apartment', 'rent', 2650, 96, 1, 1, 1, 'Austin', '360 Nueces St, Loft 7B', 1, 'available', ['loft1', 'loft2', 'kitchen4']],
                ['Family Home near Zilker Park', 'House', 'sale', 865000, 240, 4, 3, 2, 'Austin', '1809 Kinney Ave', 0, 'available', ['suburban', 'living13', 'kitchen5', 'bedroom4']],
                ['Creative Studio Office – East Austin', 'Office', 'rent', 3900, 210, null, 1, 4, 'Austin', '2000 E 6th St', 0, 'available', ['office_dining', 'office_plants']],
                ['Vineyard Acreage with Building Permit', 'Land', 'sale', 640000, 24000, null, null, null, 'Austin', 'Fitzhugh Rd, Dripping Springs', 0, 'available', ['vineyard', 'site']],

                ['La Jolla Cliffside Villa', 'Villa', 'sale', 4200000, 510, 5, 6, 3, 'San Diego', '7700 Coast Blvd', 1, 'available', ['villa_pool2', 'living3', 'kitchen6', 'bedroom3', 'bathroom1']],
                ['Mid-Century Home with Canyon Views', 'House', 'sale', 1420000, 260, 3, 2, 2, 'San Diego', '4410 Sunset Canyon Rd', 0, 'available', ['modern_house', 'living7', 'dining1']],
                ['Gaslamp Quarter Penthouse', 'Apartment', 'rent', 6900, 190, 3, 3, 2, 'San Diego', '575 Fifth Ave, PH2', 1, 'available', ['apt_building', 'living4', 'kitchen7', 'bedroom5']],
                ['Cozy Beach Cottage – Pacific Beach', 'House', 'rent', 3400, 90, 2, 1, 1, 'San Diego', '1215 Grand Ave', 0, 'rented', ['bungalow', 'living10']],
                ['Retail Corner Unit on India Street', 'Commercial Property', 'sale', 1150000, 280, null, 2, 2, 'San Diego', '2100 India St', 0, 'available', ['building', 'hallway']],

                ['Scandinavian-Style Home in Queen Anne', 'House', 'sale', 1690000, 285, 4, 3, 2, 'Seattle', '1900 8th Ave W', 1, 'available', ['modern_black', 'living11', 'kitchen10', 'bedroom8']],
                ['Lakefront Cabin Retreat', 'Villa', 'sale', 980000, 175, 3, 2, 1, 'Seattle', '4420 Lake Washington Blvd', 0, 'available', ['lake_cabin', 'cabin', 'living16']],
                ['Capitol Hill 1BR with City Skyline', 'Apartment', 'rent', 2350, 68, 1, 1, 0, 'Seattle', '1100 Pike St, #905', 0, 'available', ['apt_balconies', 'living12', 'bedroom2']],
                ['Tech Campus Office Suite – South Lake Union', 'Office', 'rent', 18900, 820, null, 4, 12, 'Seattle', '400 Fairview Ave N, Suite 900', 1, 'available', ['skyscrapers', 'office_hall', 'office_glass']],
                ['Mountain Cabin with Hot Tub', 'House', 'rent', 2900, 120, 2, 2, 1, 'Seattle', '9200 Snoqualmie Pass Rd', 0, 'available', ['mountain_cabin', 'forest_cabin', 'living15']],

                ['Modern Ranch Home with Mountain Views', 'House', 'sale', 1250000, 330, 4, 3, 3, 'Denver', '2200 Cherry Creek Dr S', 1, 'available', ['modern_dusk', 'living17', 'kitchen1', 'bedroom1']],
                ['LoDo Warehouse Conversion Loft', 'Apartment', 'sale', 745000, 140, 2, 2, 1, 'Denver', '1660 Wynkoop St, #3F', 0, 'available', ['apt_facade', 'loft2', 'living18']],
                ['Highlands Craftsman with Garden', 'House', 'sale', 910000, 215, 3, 2, 2, 'Denver', '3540 W 32nd Ave', 0, 'available', ['red_brick', 'living8', 'kitchen2']],
                ['Light-Industrial Warehouse – RiNo', 'Commercial Property', 'rent', 9800, 1400, null, 2, 10, 'Denver', '3300 Larimer St', 0, 'available', ['construction', 'site']],
                ['Red Cabin on Two Wooded Acres', 'House', 'sale', 560000, 110, 2, 1, 1, 'Denver', '18 Evergreen Pkwy', 0, 'available', ['red_cabin', 'forest_cabin']],

                ['Gold Coast Greystone Townhouse', 'House', 'sale', 2380000, 400, 5, 4, 2, 'Chicago', '1340 N Astor St', 1, 'available', ['brick_colonial', 'living5', 'dining2', 'stairs', 'bedroom3']],
                ['River North High-Rise 2BR', 'Apartment', 'rent', 3950, 105, 2, 2, 1, 'Chicago', '405 N Wabash Ave, 3801', 1, 'available', ['apt_complex', 'living19', 'kitchen8']],
                ['Lincoln Park Victorian', 'House', 'sale', 1575000, 295, 4, 3, 2, 'Chicago', '2130 N Cleveland Ave', 0, 'sold', ['colonial', 'living14', 'kitchen6']],
                ['Loop Corner Office with Lake View', 'Office', 'rent', 22000, 950, null, 4, 15, 'Chicago', '233 S Wacker Dr, 72nd Floor', 0, 'available', ['city_night', 'skyline', 'office_hall']],
                ['Mixed-Use Building – Wicker Park', 'Commercial Property', 'sale', 2950000, 900, null, 6, 6, 'Chicago', '1500 N Milwaukee Ave', 0, 'available', ['skyline2', 'building']],

                ['Music Row Modern with Rooftop Deck', 'House', 'sale', 1090000, 250, 4, 3, 2, 'Nashville', '1010 16th Ave S', 1, 'available', ['modern2', 'living20', 'kitchen9', 'bedroom4']],
                ['East Nashville Cottage', 'House', 'rent', 2400, 115, 3, 2, 1, 'Nashville', '1211 Holly St', 0, 'available', ['house_sunset', 'living23', 'kitchen3']],
                ['The Gulch Luxury 1BR', 'Apartment', 'rent', 2750, 74, 1, 1, 1, 'Nashville', '1212 Laurel St, #1104', 0, 'available', ['modern_white', 'living22', 'bedroom7']],
                ['Desert Modern Estate with Pool', 'Villa', 'sale', 3600000, 480, 5, 5, 4, 'Scottsdale', '10040 E Happy Valley Rd', 1, 'available', ['villa_palm', 'villa_pool3', 'living24', 'kitchen10', 'bedroom6']],
                ['Spanish-Style Villa in Old Town', 'Villa', 'sale', 1980000, 340, 4, 4, 2, 'Scottsdale', '7135 E Camelback Rd', 0, 'available', ['spanish_villa', 'old_villa', 'dining3']],
                ['North Scottsdale Building Lot – 1.2 Acres', 'Land', 'sale', 385000, 4900, null, null, null, 'Scottsdale', 'Lot 14, Pinnacle Peak Vistas', 0, 'available', ['site', 'construction']],
            ];

            $i = 0;
            foreach ($rows as $r) {
                [$title, $cat, $purpose, $price, $area, $beds, $baths, $garage, $city, $address, $featured, $status, $imgs] = $r;
                [$region, $lat, $lng] = $cities[$city];
                $agent = $agents[$i % count($agents)];
                $amen = collect($amenityPool)->shuffle()->take(random_int(5, 9))->values()->all();
                if ($cat === 'Land') {
                    $amen = ['Road Access', 'Utilities at Lot Line', 'Zoned Residential', 'Survey Available'];
                }

                Property::create([
                    'title' => $title,
                    'slug' => 'demo-'.Str::slug($title),
                    'property_category_id' => $cats[$cat]->id,
                    'description' => self::description($title, $cat, $purpose, $city, $region, $beds, $baths, $area),
                    'purpose' => $purpose,
                    'status' => $status,
                    'featured' => $featured,
                    'price' => $price,
                    'area' => $area,
                    'area_unit' => 'm²',
                    'bedrooms' => $beds,
                    'bathrooms' => $baths,
                    'garage' => $garage,
                    'address' => $address,
                    'city' => $city,
                    'region' => $region,
                    'latitude' => $lat + (random_int(-400, 400) / 10000),
                    'longitude' => $lng + (random_int(-400, 400) / 10000),
                    'featured_image' => self::img($imgs[0]),
                    'gallery' => array_map(fn ($k) => self::img($k), array_slice($imgs, 1)),
                    'amenities' => $amen,
                    'agent_name' => $agent[0],
                    'agent_phone' => $agent[1],
                    'agent_email' => $agent[2],
                    'agent_whatsapp' => $agent[3],
                    'seo_title' => $title.' – '.$city,
                    'seo_description' => Str::limit(self::description($title, $cat, $purpose, $city, $region, $beds, $baths, $area), 150),
                    'views' => random_int(40, 900),
                    'created_at' => Carbon::now()->subDays(random_int(0, 60))->subMinutes($i),
                    'updated_at' => Carbon::now(),
                ]);
                $i++;
            }
        });

        $this->activateTheme();
        $this->command?->info('Real estate demo listings seeded and the Real Estate theme activated.');
    }

    private static function description(string $title, string $cat, string $purpose, string $city, string $region, $beds, $baths, $area): string
    {
        $verb = $purpose === 'rent' ? 'available to rent' : 'offered for sale';
        $rooms = $beds ? "{$beds} bedrooms and {$baths} bathrooms" : 'flexible open-plan space';
        $intro = match ($cat) {
            'Apartment' => "Bright, well-appointed apartment {$verb} in one of {$city}'s most sought-after addresses.",
            'Villa' => "An exceptional villa {$verb}, combining privacy, generous outdoor living and premium finishes throughout.",
            'Office' => "Professional office space {$verb} with excellent transport links and natural light on every floor.",
            'Commercial Property' => "High-visibility commercial property {$verb}, ideal for retail, hospitality or investment.",
            'Land' => "Buildable land {$verb} in a fast-growing part of {$region} with utilities nearby.",
            default => "A welcoming family home {$verb} in a quiet, tree-lined neighbourhood of {$city}.",
        };

        return $intro."\n\n".ucfirst($title)." offers {$rooms} across ".rtrim(rtrim(number_format((float) $area, 2), '0'), '.')." m², with quality fittings, ample storage and ".($purpose === 'rent' ? 'flexible lease terms' : 'clear title and immediate availability').".\n\nMinutes from schools, shopping and green spaces, with easy access to the rest of {$region}. Contact our agent to arrange a private viewing.";
    }

    protected function activateTheme(): void
    {
        $s = StoreSetting::first();
        if (! $s) {
            return;
        }
        // Replace the stock e-commerce hero with a property photo unless the
        // admin uploaded their own.
        $hero = (string) ($s->hero_image_path ?? '');
        $heroIsDefault = $hero === '' || str_contains($hero, 'hero_image.') || str_contains($hero, 'images.unsplash.com');
        $s->forceFill([
            'theme' => 'real_estate',
            'hero_image_path' => $heroIsDefault ? self::img('modern_pool', 1800) : $hero,
            'store_name' => in_array($s->store_name, [null, '', 'StoreX', 'TechNova', 'LittleJoy', 'HavenHomes', 'FreshMart'], true) ? 'HavenHomes' : $s->store_name,
            'primary_color' => '#0f766e',
            'secondary_color' => '#c9a24d',
            'hero_title' => 'Find a place you will love to call home',
            'hero_subtitle' => 'Verified listings, trusted local agents and transparent pricing across the most desirable neighbourhoods.',
            'footer_text' => 'Helping families and investors find the perfect property since 2012. Buy, sell or rent with confidence.',
            'contact_phone' => $s->contact_phone ?: '+1 305 555 0100',
            'contact_email' => $s->contact_email ?: 'hello@havenhomes.test',
            'contact_address' => $s->contact_address ?: '1200 Brickell Avenue, Suite 400, Miami, FL',
        ])->save();

        if (function_exists('store_url_config_clear')) {
            store_url_config_clear();
        }
    }
}
