<?php

namespace App\Services\DemoData;

/**
 * Website Management demo pages per business type (Demo Data -> Website):
 * the CMS pages of the company website (store_pages, /online_store/pages/{slug}).
 *
 *  it           written from www.kcoltech.com (KcolTech LLC: mission, services,
 *               process, pricing, testimonials, locations).
 *  landscaping  a lawn care / landscaping company (Ruba Garden style).
 *  salon        a unisex hair & beauty salon (New Era style); barber shops get it too.
 *
 * Placeholders: {{company}}, {{phone}}, {{email}} -> the company's own values
 * (companies table; the KcolTech ones when empty); {{img:file.jpg}} -> a photo
 * of resources/demo/portfolio/{set}/ copied into files/company_{id}/website/demo/.
 */
class WebsiteDemoCatalog
{
    public const SET_OF = ['it_services' => 'it', 'professional' => 'it', 'general' => 'it', 'landscaping' => 'landscaping', 'salon' => 'salon', 'barber' => 'salon'];

    public const FALLBACK = ['phone' => '+1 732-547-5393', 'email' => 'info@kcoltech.com'];

    public static function setFor(string $businessType): string
    {
        return self::SET_OF[$businessType] ?? 'it';
    }

    /** set => [[title, slug, seo description, html], ...] */
    public const PAGES = [
        'it' => [
            ['About Us', 'about-us', 'IT expert support, services and guidance for businesses small and large since 2001.', <<<'HTML'
<h2>To serve, secure, and empower the world through people and technology; one customer at a time.</h2>
<p>Since 2001, {{company}} has provided IT expert support, services and guidance to businesses small and large as they grow and adapt to today's complex IT, cybersecurity and compliance needs.</p>
<img src="{{img:server-team.jpg}}" alt="Our team in the server room">
<h3>The team behind your technology</h3>
<ul>
<li><strong>Network development</strong> — more than 25 years designing LANs, WANs and cloud networks.</li>
<li><strong>Cybersecurity</strong> — penetration testing and security assessments since 2001.</li>
<li><strong>Cloud architecture</strong> — 17+ years moving and running business workloads in the cloud.</li>
<li><strong>Full-stack development</strong> — over 18,000 hours building web applications and websites.</li>
</ul>
<p>We work in English and Spanish, from New Jersey, Florida and Peru.</p>
HTML],
            ['Services', 'services', 'Web design, network design, cybersecurity, 24/7 support, call centers and online help desk.', <<<'HTML'
<h2>What we do</h2>
<img src="{{img:network-cables.jpg}}" alt="Network cabling">
<h3>Web Design</h3>
<p>We create the look and the user experience of your website, integrated with the development so it works as well as it looks.</p>
<h3>Network Design</h3>
<p>Intelligent, converged platforms for modern business demands across LANs, WANs and cloud infrastructure.</p>
<h3>Cybersecurity</h3>
<p>Multi-phase risk assessments: edge evaluation, internal vulnerabilities and an analysis of your security posture, with a clear plan to fix what we find.</p>
<h3>24/7 Support</h3>
<p>Technical assistance across multiple channels (phone, email, remote and on-site) for a secure working environment, day and night.</p>
<h3>Call Center Implementation</h3>
<p>Professional design and management of call center operations: phone systems, routing, recording and reporting.</p>
<h3>Help Desk Online</h3>
<p>A technology resource for your staff that increases adoption and satisfaction: one place to ask, follow and solve every IT request.</p>
HTML],
            ['Our Process', 'our-process', 'How we work: collect the idea, design, develop and deliver.', <<<'HTML'
<h2>From idea to delivery</h2>
<img src="{{img:planning.jpg}}" alt="Planning session">
<ol>
<li><strong>Collect the idea</strong> — we listen: your goals, your users, your budget and your deadlines.</li>
<li><strong>Design</strong> — architecture, mock-ups and a written plan you approve before any work starts.</li>
<li><strong>Development</strong> — we build, test and document, with regular demos so there are no surprises.</li>
<li><strong>Client delivery</strong> — installation, training for your team and support after go-live.</li>
</ol>
<blockquote>Without {{company}} insights, we would be stuck on the ground floor forever. — Frank Amelinckx, Vanclem CEO</blockquote>
HTML],
            ['Pricing', 'pricing', 'Simple hourly rates: Standard, Professional and Cybersecurity.', <<<'HTML'
<h2>Simple, transparent pricing</h2>
<h3>Standard — $65 / hour</h3>
<p>Help desk, workstation and printer support, software installs, accounts and day-to-day IT tasks.</p>
<h3>Professional — $150 / hour</h3>
<p>Network and server work, cloud migrations, web development and project work by senior engineers.</p>
<h3>Cybersecurity — $250 / hour</h3>
<p>Risk assessments, penetration testing, incident response and compliance work by our security specialists.</p>
<p>Monthly support plans and fixed-price projects are available — ask us for a quote.</p>
HTML],
            ['Testimonials', 'testimonials', 'What our customers say about us.', <<<'HTML'
<h2>What our customers say</h2>
<img src="{{img:meeting.jpg}}" alt="Customer meeting">
<blockquote>Without {{company}} insights, we would be stuck on the ground floor forever.<br>— Frank Amelinckx, CEO, Vanclem</blockquote>
<blockquote>I always have the best experience with {{company}}. Thank you for making sure our technical needs are always taken care of.<br>— Gabriel Miranda, CEO, IberikaTech</blockquote>
HTML],
            ['Contact', 'contact', 'Call, email or visit us in New Jersey and Florida.', <<<'HTML'
<h2>Let's talk about your project</h2>
<p><strong>Email:</strong> {{email}}<br><strong>Phone:</strong> {{phone}}</p>
<h3>Offices</h3>
<ul>
<li>Little Falls, New Jersey</li>
<li>Boca Raton, Florida</li>
<li>Lima, Peru (Spanish-speaking support)</li>
</ul>
<p>Support is available 24/7 for customers with a support plan.</p>
HTML],
        ],
        'landscaping' => [
            ['About Us', 'about-us', 'A family landscaping company caring for lawns and gardens all year.', <<<'HTML'
<h2>Beautiful outdoor spaces, all year round</h2>
<img src="{{img:striped-lawn.jpg}}" alt="A freshly striped lawn">
<p>{{company}} is a family-owned landscaping company. For years we have designed, built and cared for lawns, gardens and stone work for homes, HOAs and businesses.</p>
<p>One crew, one schedule and one invoice: we take care of everything from the weekly mowing to a brand-new patio, so you can simply enjoy your yard.</p>
<ul><li>Licensed and insured</li><li>Free estimates</li><li>English and Spanish</li></ul>
HTML],
            ['Services', 'services', 'Lawn care, garden design, hardscaping, hedges, clean-ups and irrigation.', <<<'HTML'
<h2>Our services</h2>
<img src="{{img:lawn-mowing.jpg}}" alt="Lawn mowing">
<h3>Lawn care</h3>
<p>Weekly mowing, edging and blowing, fertilizing, aeration and overseeding from spring to fall.</p>
<h3>Garden design</h3>
<p>Flower beds, perennials, shrubs and trees chosen for your sun, soil and style — with low maintenance in mind.</p>
<h3>Hardscaping</h3>
<p>Paver patios, natural stone paths, retaining walls and steps built to last.</p>
<img src="{{img:stone-path.jpg}}" alt="Natural stone path">
<h3>Hedges &amp; trees</h3>
<p>Hedge trimming and sculpting, shrub pruning and small tree care.</p>
<h3>Seasonal clean-ups</h3>
<p>Spring and fall clean-ups, leaf removal and bed preparation.</p>
<h3>Watering &amp; irrigation</h3>
<p>Sprinkler checks, watering plans for new sod and plants, and winterizing.</p>
HTML],
            ['How We Work', 'how-we-work', 'Free estimate, design, installation and care.', <<<'HTML'
<h2>Simple from start to finish</h2>
<ol>
<li><strong>Free estimate</strong> — we visit your property, listen to what you want and send you a written quote.</li>
<li><strong>Design</strong> — for new gardens and patios we prepare a plan with plants and materials.</li>
<li><strong>Installation</strong> — our crew does the work on the agreed dates and leaves everything clean.</li>
<li><strong>Care</strong> — weekly or monthly maintenance so your yard always looks its best.</li>
</ol>
<img src="{{img:patio.jpg}}" alt="Finished patio">
HTML],
            ['Service Areas', 'service-areas', 'Where we work in New Jersey.', <<<'HTML'
<h2>Where we work</h2>
<p>We serve homes and businesses across Northern New Jersey, including:</p>
<ul><li>Montclair, Bloomfield, Nutley and Clifton</li><li>Wayne, Little Falls and Verona</li><li>Livingston, Short Hills and Morristown</li><li>Paramus and the rest of Bergen County</li></ul>
<p>Not on the list? Call us — we probably come to your town too.</p>
HTML],
            ['FAQ', 'faq', 'Answers to common questions.', <<<'HTML'
<h2>Frequently asked questions</h2>
<h3>Do you give free estimates?</h3><p>Yes — every estimate is free and without obligation.</p>
<h3>Which day do you come?</h3><p>Weekly customers get a fixed day; we tell you in advance if the weather moves it.</p>
<h3>Do I need to be home?</h3><p>No. We only need access to the yard.</p>
<h3>How do I pay?</h3><p>We send one monthly invoice. You can pay by card, Zelle, check or bank transfer.</p>
HTML],
            ['Free Estimate', 'free-estimate', 'Ask for your free landscaping estimate.', <<<'HTML'
<h2>Get your free estimate</h2>
<img src="{{img:flower-garden.jpg}}" alt="Flower garden">
<p>Tell us about your yard and what you would like to do. We answer within one business day.</p>
<p><strong>Phone / text:</strong> {{phone}}<br><strong>Email:</strong> {{email}}</p>
<p>Office hours: Monday to Friday 8:00 AM – 6:00 PM, Saturday 8:00 AM – 1:00 PM.</p>
HTML],
        ],
        'salon' => [
            ['About Us', 'about-us', 'A unisex salon for hair, color, nails, skin care and makeup.', <<<'HTML'
<h2>Hair, nails and beauty for everyone</h2>
<img src="{{img:balayage.jpg}}" alt="Balayage result">
<p>{{company}} is a unisex salon where every client gets time, honest advice and a look that fits them.</p>
<p>Women, men and kids are welcome: cuts, color, braids, nails, facials, lashes and makeup for every day and for the big days.</p>
HTML],
            ['Services & Prices', 'services-prices', 'Hair, color, nails, skin care, makeup and lashes with prices.', <<<'HTML'
<h2>Services &amp; prices</h2>
<h3>Hair</h3>
<ul><li>Women's cut &amp; style — from $45</li><li>Men's cut — from $25</li><li>Kids' cut — from $18</li><li>Blowout — from $35</li><li>Updo / special occasion — from $65</li><li>Silk press — from $70</li></ul>
<img src="{{img:womens-haircut.jpg}}" alt="Haircut">
<h3>Color</h3>
<ul><li>Root touch-up — from $55</li><li>Full color — from $75</li><li>Highlights — from $95</li><li>Balayage / ombre — from $150</li></ul>
<h3>Nails</h3>
<ul><li>Classic manicure — $25</li><li>Gel manicure — $38</li><li>Acrylic full set — from $50</li><li>Gel pedicure — $48</li></ul>
<h3>Skin care, makeup &amp; lashes</h3>
<ul><li>Express facial — $45</li><li>Deep cleansing facial — $75</li><li>Makeup application — from $60</li><li>Lash extensions, full set — from $120</li><li>Brow shaping — $18</li></ul>
<p>Prices may change with hair length and the products used — we always tell you before we start.</p>
HTML],
            ['Our Team', 'our-team', 'Meet our stylists and beauty specialists.', <<<'HTML'
<h2>Meet our team</h2>
<img src="{{img:makeup.jpg}}" alt="Makeup artist at work">
<p>Our stylists, colorists, nail technicians and estheticians train every year in the latest cuts, color techniques and skin treatments.</p>
<ul><li><strong>Stylists</strong> — cuts, blowouts, updos and textured hair.</li><li><strong>Colorists</strong> — highlights, balayage and color correction.</li><li><strong>Nail technicians</strong> — manicures, pedicures and nail art.</li><li><strong>Estheticians</strong> — facials, brows, lashes and makeup.</li></ul>
HTML],
            ['Booking & Policies', 'booking-policies', 'How to book, cancellations and late arrivals.', <<<'HTML'
<h2>Booking &amp; policies</h2>
<p>Book online, call us or walk in — walk-ins are welcome when a chair is free.</p>
<ul><li><strong>Cancellations:</strong> please tell us at least 24 hours before.</li><li><strong>Late arrivals:</strong> after 15 minutes we may need to shorten or move your service.</li><li><strong>Color services:</strong> a patch test may be needed 48 hours before.</li><li><strong>Payment:</strong> cash, card and Zelle.</li></ul>
HTML],
            ['FAQ', 'faq', 'Answers to common questions.', <<<'HTML'
<h2>Frequently asked questions</h2>
<h3>Do you take walk-ins?</h3><p>Yes, when a stylist is free. Booking guarantees your time.</p>
<h3>Do you work with curly and textured hair?</h3><p>Yes — curly cuts, silk press, braids and protective styles.</p>
<h3>Do you do bridal parties?</h3><p>Yes: trials, hair and makeup for the bride and the bridal party, in the salon or on location.</p>
<h3>Can I buy gift cards?</h3><p>Yes, in the salon for any amount.</p>
HTML],
            ['Contact & Hours', 'contact-hours', 'Phone, email and opening hours.', <<<'HTML'
<h2>Visit us</h2>
<img src="{{img:blowout.jpg}}" alt="Blowout">
<p><strong>Phone:</strong> {{phone}}<br><strong>Email:</strong> {{email}}</p>
<h3>Opening hours</h3>
<ul><li>Tuesday – Friday: 9:00 AM – 7:00 PM</li><li>Saturday: 8:00 AM – 6:00 PM</li><li>Sunday: 10:00 AM – 4:00 PM</li><li>Monday: closed</li></ul>
HTML],
        ],
    ];
}
