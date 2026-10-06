<?php

namespace App\Services\DemoData;

/**
 * Portfolio demo works per business type (Demo Data -> Portfolio). The photos
 * live in resources/demo/portfolio/{set}/ (git-tracked, not web-accessible) and
 * are copied into the company's own files/company_{id}/portfolio/demo/ when the
 * demo is generated. Credits: resources/demo/portfolio/credits.json.
 *
 * Photo sources (all free for commercial use, see credits.json):
 *  landscaping / it   Openverse, CC0 (StockSnap, rawpixel, WordPress Photos) -- no attribution required.
 *  salon / barber     Pexels (Pexels license) -- the photos already used for New Era's services.
 * 'from' = where database/scripts/portfolio_demo_photos.php gets each photo:
 *  ov:<set>:<n>   the n-th pick of the Openverse search (ov_picked.json)
 *  pexels:<name>  storage/app/company3_service_images.json entry (local file)
 */
class PortfolioDemoCatalog
{
    /** Business type (App\Support\BusinessTypes) => photo set. */
    public const SET_OF = [
        'landscaping' => 'landscaping',
        'salon' => 'salon',
        'barber' => 'barber',
        'it_services' => 'it',
        'professional' => 'it',
    ];

    public static function setFor(string $businessType): string
    {
        return self::SET_OF[$businessType] ?? 'it';
    }

    public const SETS = [
        'landscaping' => [
            'categories' => ['Lawn care', 'Garden design', 'Hardscaping', 'Hedges & trees'],
            'works' => [
                ['Weekly lawn maintenance', 'Lawn care', 'The Miller family', 'Montclair, NJ', 'Mowing, edging and blowing every week from April to November, plus spring and fall fertilizing.', [['lawn-mowing.jpg', 'ov:landscaping:2'], ['lawn-mower.jpg', 'ov:landscaping:4']]],
                ['Striped lawn renovation', 'Lawn care', 'Oak Ridge HOA', 'Wayne, NJ', 'Core aeration, overseeding and a new cutting pattern for the common lawns of the community.', [['striped-lawn.jpg', 'ov:landscaping:62'], ['park-lawn.jpg', 'ov:landscaping:73']]],
                ['Watering plan & irrigation check', 'Lawn care', 'Garcia residence', 'Clifton, NJ', 'Hand watering schedule for the new sod and a full check of the sprinkler zones.', [['watering.jpg', 'ov:landscaping:36'], ['mower-lawn.jpg', 'ov:landscaping:9']]],
                ['Cottage flower garden', 'Garden design', 'Rose Bakery', 'Nutley, NJ', 'Perennial beds in front of the shop: color from spring to fall with low maintenance.', [['flower-garden.jpg', 'ov:landscaping:26'], ['garden-beds.jpg', 'ov:landscaping:16']]],
                ['Backyard patio garden', 'Garden design', 'The Johnsons', 'Bloomfield, NJ', 'A small backyard turned into an outdoor dining room surrounded by plants.', [['patio-garden.jpg', 'ov:landscaping:12'], ['garden-path.jpg', 'ov:landscaping:18'], ['secret-garden.jpg', 'ov:landscaping:72']]],
                ['Natural stone garden path', 'Hardscaping', 'Willow Lane estate', 'Short Hills, NJ', 'Flagstone and stepping-stone paths connecting the house, the garden and the pond.', [['stone-path.jpg', 'ov:landscaping:45'], ['stepping-stones.jpg', 'ov:landscaping:46'], ['pavers.jpg', 'ov:landscaping:47']]],
                ['Retaining wall & patio', 'Hardscaping', 'Patel residence', 'Livingston, NJ', 'Dry-stacked stone wall to level the yard and a new paver patio with furniture area.', [['stone-wall.jpg', 'ov:landscaping:42'], ['patio.jpg', 'ov:landscaping:11'], ['pergola-patio.jpg', 'ov:landscaping:59']]],
                ['Formal hedge sculpting', 'Hedges & trees', 'Greenfield Park', 'Paramus, NJ', 'Shaping and trimming of boxwood hedges, topiary and maze paths twice a season.', [['hedges.jpg', 'ov:landscaping:70'], ['hedge-garden.jpg', 'ov:landscaping:71'], ['topiary.jpg', 'ov:landscaping:74'], ['maze.jpg', 'ov:landscaping:76']]],
                ['Seasonal clean-up', 'Hedges & trees', 'Brooks family', 'Verona, NJ', 'Fall leaf removal, pruning and bed preparation before winter.', [['garden-tools.jpg', 'ov:landscaping:77']]],
            ],
        ],
        'it' => [
            'categories' => ['Networks', 'Servers & cloud', 'Repair & support', 'Software & web', 'Security', 'Consulting'],
            'works' => [
                ['Office network cabling (48 ports)', 'Networks', 'Hudson Dental Group', 'Newark, NJ', 'Cat6 cabling, labeled patch panels and a clean rack for a new 3-floor office.', [['patch-cables.jpg', 'ov:it:9'], ['rack-cables.jpg', 'ov:it:12'], ['network-cables.jpg', 'ov:it:10']]],
                ['Managed switch upgrade', 'Networks', 'Bergen Logistics', 'Secaucus, NJ', 'Replaced unmanaged switches with managed PoE switches and VLANs for phones, cameras and guests.', [['network-switch.jpg', 'ov:it:63'], ['switch-ports.jpg', 'ov:it:64'], ['switch-row.jpg', 'ov:it:71']]],
                ['Server room build-out', 'Servers & cloud', 'Summit Accounting', 'Morristown, NJ', 'New server rack, UPS, backups to the cloud and remote monitoring 24/7.', [['server-room.jpg', 'ov:it:1'], ['server-aisle.jpg', 'ov:it:2'], ['server-team.jpg', 'ov:it:0']]],
                ['Laptop & desktop repair', 'Repair & support', 'Walk-in customers', 'Paterson, NJ', 'Same-day diagnosis, part replacement and clean-up for laptops and desktops.', [['pc-repair.jpg', 'ov:it:18'], ['motherboard-repair.jpg', 'ov:it:19'], ['wiring-repair.jpg', 'ov:it:26']]],
                ['Hard drive data recovery', 'Repair & support', 'Lopez Law Office', 'Hackensack, NJ', 'Recovered 12 years of case files from a failed drive and set up automatic backups.', [['hard-drive.jpg', 'ov:it:20']]],
                ['Custom web application', 'Software & web', 'FreshFarm Co-op', 'Hoboken, NJ', 'Ordering and delivery app for members, connected to the co-op inventory.', [['coding.jpg', 'ov:it:76'], ['programming.jpg', 'ov:it:79']]],
                ['Business website relaunch', 'Software & web', 'Garden State Realty', 'Jersey City, NJ', 'New fast website with property search, contact forms and SEO.', [['web-code.jpg', 'ov:it:80'], ['code-laptop.jpg', 'ov:it:75']]],
                ['Cyber-security audit', 'Security', 'Riverside Clinic', 'Fort Lee, NJ', 'Passwords, VPN, firewall rules and staff training to meet HIPAA requirements.', [['security.jpg', 'ov:it:98'], ['vpn.jpg', 'ov:it:101']]],
                ['IT strategy consulting', 'Consulting', 'Northstar Builders', 'Edison, NJ', 'A 3-year technology plan: budget, cloud move and support contract.', [['meeting.jpg', 'ov:it:59'], ['planning.jpg', 'ov:it:57'], ['remote-work.jpg', 'ov:it:60']]],
            ],
        ],
        'salon' => [
            'categories' => ['Hair style', 'Color', 'Nails', 'Skin care', 'Makeup & lashes'],
            'works' => [
                ['Balayage & toner', 'Color', 'Sofia M.', 'In the salon', 'Hand-painted balayage with a gloss toner for a soft, natural grow-out.', [['balayage.jpg', 'pexels:Balayage'], ['ombre.jpg', 'pexels:Ombre']]],
                ['Highlights refresh', 'Color', 'Laura P.', 'In the salon', 'Fine foil highlights to brighten the face and blend the gray.', [['highlights.jpg', 'pexels:Highlights / Lowlights']]],
                ['Bridal updo', 'Hair style', 'Emily & James wedding', 'The Grand Hall', 'Trial and wedding-day updo, plus makeup for the bride and two bridesmaids.', [['updo.jpg', 'pexels:Updo'], ['special-occasion.jpg', 'pexels:Special Occasion Style'], ['bridal-makeup.jpg', 'pexels:Bridal Makeup']]],
                ["Women's cut & blowout", 'Hair style', 'Ana R.', 'In the salon', 'Long layers and a smooth blowout with volume at the roots.', [['womens-haircut.jpg', "pexels:Women's Haircut"], ['blowout.jpg', 'pexels:Blowout'], ['long-haircut.jpg', 'pexels:Long Haircut']]],
                ['Curly cut & silk press', 'Hair style', 'Maya T.', 'In the salon', 'Dry curly cut to shape the curls, and a silk press for a straight look that lasts.', [['curly-cut.jpg', 'pexels:Curly Cut'], ['silk-press.jpg', 'pexels:Silk Press'], ['box-braids.jpg', 'pexels:Box Braids']]],
                ['Acrylic full set', 'Nails', 'Daniela G.', 'In the salon', 'Almond-shape acrylic set with gel color and hand-painted details.', [['acrylic-nails.jpg', 'pexels:Acrylic Full Set'], ['manicure.jpg', 'pexels:Classic Manicure']]],
                ['Gel pedicure', 'Nails', 'Carmen L.', 'In the salon', 'Spa pedicure with gel polish that lasts three weeks.', [['pedicure.jpg', 'pexels:Gel Pedicure']]],
                ['Deep cleansing facial', 'Skin care', 'Valeria S.', 'In the salon', 'Extraction, mask and hydration for clear, glowing skin.', [['facial.jpg', 'pexels:Deep Cleansing Facial'], ['express-facial.jpg', 'pexels:Express Facial']]],
                ['Lashes & brows', 'Makeup & lashes', 'Nicole B.', 'In the salon', 'Full set of lash extensions, brow shaping and a natural makeup look.', [['lashes.jpg', 'pexels:Eyelash Extensions (Full Set)'], ['brows.jpg', 'pexels:Brow Shaping'], ['makeup.jpg', 'pexels:Makeup Application']]],
            ],
        ],
        'barber' => [
            'categories' => ['Haircuts', 'Beard', 'Shaves', 'Kids'],
            'works' => [
                ['Classic barber cut', 'Haircuts', 'Mike D.', 'In the shop', 'Scissor-over-comb on top, clean taper on the sides and a hot lather neck shave.', [['barber-haircut.jpg', 'pexels:Barber Haircut'], ['barbershop.jpg', 'pexels:General Barber Service']]],
                ['Hair design & line up', 'Haircuts', 'Andre W.', 'In the shop', 'Skin fade with a custom razor design and a sharp line up.', [['hair-design.jpg', 'pexels:Barber Hair Design'], ['line-up.jpg', 'pexels:Line Up / Edge Up']]],
                ["Senior gentleman's cut", 'Haircuts', 'Mr. Thompson', 'In the shop', 'A classic short cut, ears and neckline cleaned, every two weeks.', [['senior-cut.jpg', 'pexels:Senior Cut']]],
                ['Beard sculpting', 'Beard', 'Carlos R.', 'In the shop', 'Beard shaped with clippers and razor, hot towel and beard oil.', [['beard.jpg', 'pexels:Beard Treatment'], ['haircut-beard.jpg', 'pexels:Haircut & Beard Combo']]],
                ['Hot towel shave', 'Shaves', 'David K.', 'In the shop', 'Traditional straight razor shave with hot towels and aftershave balm.', [['hot-towel-shave.jpg', 'pexels:Hot Towel Shave'], ['razor-shave.jpg', 'pexels:Straight Razor Shave']]],
                ['Head shave', 'Shaves', 'Marcus J.', 'In the shop', 'Smooth head shave with a straight razor and a cooling finish.', [['head-shave.jpg', 'pexels:Head Shave']]],
                ['First haircut', 'Kids', 'Little Leo (4)', 'In the shop', "A patient first haircut with a certificate and a lock of hair for the parents.", [['kids-cut.jpg', 'pexels:Kids Barber Cut']]],
            ],
        ],
    ];

    /** About / Contact texts used only when the company's site has none yet. */
    public const ABOUT = [
        'landscaping' => ['Beautiful outdoor spaces, all year', "We design, build and care for lawns, gardens and stone work for homes and businesses.\n\nOne crew, one schedule and one invoice: we take care of everything from the weekly mowing to the new patio.", [['Lawn care', 100], ['Garden design', 90], ['Hardscaping', 85]]],
        'it' => ['Technology that just works', "We are an IT consulting team for small and medium businesses: networks, servers, cloud, security and support.\n\nWe plan with you, install it right and stay with you with monitoring and fast support.", [['Networks', 95], ['Cloud & servers', 90], ['Security', 85]]],
        'salon' => ['Hair, nails and beauty for everyone', "A unisex salon where every client gets time, advice and a look that fits them.\n\nColor, cuts, nails, skin care and makeup for every day and for the big days.", [['Color', 95], ['Cuts & styling', 100], ['Nails', 90]]],
        'barber' => ['Classic cuts, sharp fades', "A barber shop with the classics done right: haircuts, beards and hot towel shaves.\n\nWalk in or book online, relax and leave looking sharp.", [['Fades', 100], ['Beard work', 95], ['Shaves', 90]]],
    ];
}
