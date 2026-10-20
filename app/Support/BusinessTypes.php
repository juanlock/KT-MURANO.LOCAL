<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a company does (companies.business_type) -- so demo data and the
 * default accounting fit it instead of the generic (IT-services-like) set.
 *
 * Each type adds, on top of App\Support\DefaultAccounting:
 *  - accounts in the company's chart + expense categories for its typical
 *    spending (ensure(): idempotent, real setup -- NOT demo rows, they stay
 *    after "Remove demo data");
 *  - the monthly bills Demo Data -> Regular expenses generates;
 *  - wording for the Demo Data -> Accounting journal entries.
 */
class BusinessTypes
{
    public const DEFAULT = 'general';

    /** key => label (Demo Data dropdown, sorted there by label). */
    public const LABELS = [
        'general' => 'General / other',
        'it_services' => 'IT / technology services',
        'landscaping' => 'Landscaping / lawn care',
        'salon' => 'Hair salon / beauty',
        'barber' => 'Barber shop',
        'retail' => 'Retail store',
        'restaurant' => 'Restaurant / food',
        'professional' => 'Office / professional services (consulting, accounting)',
        // 2026-10-19
        'laundry' => 'Laundry services',
        'delivery' => 'Delivery services',
        'catering' => 'Catering / buffet',
        'call_center' => 'Call center',
        'real_estate' => 'Real estate (selling houses)',
        'legal' => 'Law services / law firm',
        'hospital' => 'Hospital / clinic',
        'factory' => 'Factory / manufacturing',
        'school' => 'School / academy',
    ];

    /**
     * Business Modules a new company of each type starts with (user's rule,
     * 2026-10-19): BASE_MODULES for every type + the type's own; every other
     * switchable module starts OFF. Core (sales, purchases, products, people,
     * suppliers, reports, settings) is always on. Keys = config/modules.js.
     * Companies -> Add shows them pre-ticked; the SuperAdmin adds / removes
     * others before creating. Business Modules -> "Modules of <type>" applies
     * them to an existing company.
     */
    public const BASE_MODULES = ['accounting', 'assets', 'email_office', 'gallery', 'helpdesk', 'hrm', 'website'];

    public const MODULES = [
        'general' => [],
        'it_services' => ['documents', 'projects', 'portfolio', 'service'],
        'landscaping' => ['bookings', 'fleet', 'portfolio', 'service'],
        'salon' => ['bookings', 'commissions', 'portfolio', 'promotions'],
        'barber' => ['bookings', 'commissions', 'promotions'],
        'retail' => ['EWallet', 'marketing', 'promotions', 'Store'],
        'restaurant' => ['bookings', 'kitchen', 'promotions'],
        'professional' => ['bookings', 'documents', 'meeting', 'projects'],
        'laundry' => ['bookings', 'promotions', 'service'],
        'delivery' => ['fleet', 'service'],
        'catering' => ['bookings', 'fleet', 'kitchen', 'promotions'],
        'call_center' => ['marketing', 'meeting', 'projects', 'recruit'],
        // Listings + inquiries (Real Estate), showings (Bookings), agents' commissions, contracts / deeds (Documents).
        'real_estate' => ['bookings', 'commissions', 'documents', 'marketing', 'meeting', 'realestate'],
        // Consultations (Bookings), cases (Projects & Tasks, timesheets), case files (Documents), client meetings.
        'legal' => ['bookings', 'documents', 'meeting', 'projects'],
        // Hospital Management (patients, doctors, appointments, admissions, wards, lab, billing), records, staff meetings.
        'hospital' => ['bookings', 'documents', 'hospital', 'meeting'],
        // MRP (bills of materials, production orders, work centers, quality), delivery trucks, machine maintenance, projects.
        'factory' => ['documents', 'fleet', 'manufacturing', 'projects', 'service'],
        // School Management (students, teachers, academics, exams, timetable, fees), staff intranet, parent / staff meetings, records.
        'school' => ['documents', 'intranet', 'meeting', 'school'],
    ];

    /** The modules a company of $type starts with (base + the type's own). */
    public static function modules(?string $type): array
    {
        return array_values(array_unique(array_merge(self::BASE_MODULES, self::MODULES[self::valid($type)] ?? [])));
    }

    /** Accounts: [code, parent header, English, Spanish]. */
    private const ACCOUNTS = [
        'landscaping' => [
            ['6040', '6000-6999', 'Materials and Supplies', 'Materiales y suministros'],
            ['6050', '6000-6999', 'Subcontractors / Outside Services', 'Subcontratistas / servicios externos'],
            ['7560', '7500-7999', 'Equipment and Tool Expense', 'Herramientas y equipo menor'],
            ['7570', '7500-7999', 'Fuel, Oil and Vehicle Maintenance', 'Combustible, aceite y mantenimiento de vehículos'],
        ],
        'salon' => [
            ['6060', '6000-6999', 'Salon Products and Supplies', 'Productos y suministros de salón'],
            ['7580', '7500-7999', 'Salon Tools and Small Equipment', 'Herramientas y equipo menor de salón'],
            ['8590', '8500-8999', 'Laundry and Cleaning', 'Lavandería y limpieza'],
        ],
        'retail' => [
            ['8595', '8500-8999', 'Packaging and Store Supplies', 'Empaques y suministros de tienda'],
        ],
        'restaurant' => [
            ['6070', '6000-6999', 'Food and Beverage Supplies', 'Alimentos y bebidas'],
            ['7590', '7500-7999', 'Kitchen Supplies and Smallwares', 'Utensilios y suministros de cocina'],
            ['8590', '8500-8999', 'Laundry and Cleaning', 'Lavandería y limpieza'],
        ],
        'laundry' => [
            ['6080', '6000-6999', 'Laundry Supplies', 'Suministros de lavandería'],
            ['7570', '7500-7999', 'Fuel, Oil and Vehicle Maintenance', 'Combustible, aceite y mantenimiento de vehículos'],
            ['7595', '7500-7999', 'Machine Repairs and Maintenance', 'Reparación y mantenimiento de máquinas'],
        ],
        'delivery' => [
            ['6055', '6000-6999', 'Contract Drivers / Couriers', 'Conductores / mensajeros contratados'],
            ['7570', '7500-7999', 'Fuel, Oil and Vehicle Maintenance', 'Combustible, aceite y mantenimiento de vehículos'],
            ['7575', '7500-7999', 'Vehicle Leasing and Registration', 'Arrendamiento y registro de vehículos'],
        ],
        'call_center' => [
            ['7598', '7500-7999', 'Headsets and Call Equipment', 'Auriculares y equipo de llamadas'],
            ['8597', '8500-8999', 'Telephony and VoIP Services', 'Telefonía y servicios VoIP'],
        ],
        'real_estate' => [
            ['6090', '6000-6999', 'Co-broker and Referral Fees', 'Honorarios de co-corretaje y referidos'],
            ['8592', '8500-8999', 'Property Listing and Marketing', 'Publicidad de propiedades'],
            ['8593', '8500-8999', 'MLS and Board Dues', 'Cuotas de MLS y asociaciones'],
        ],
        'legal' => [
            ['6095', '6000-6999', 'Court Filing Fees and Case Costs', 'Tasas judiciales y costos de casos'],
            ['8594', '8500-8999', 'Legal Research and Library', 'Investigación jurídica y biblioteca'],
            ['8596', '8500-8999', 'Bar Dues and Malpractice Insurance', 'Cuotas del colegio y seguro de responsabilidad'],
        ],
        'hospital' => [
            ['6062', '6000-6999', 'Medical Supplies and Pharmaceuticals', 'Suministros médicos y medicamentos'],
            ['7596', '7500-7999', 'Medical Equipment Maintenance', 'Mantenimiento de equipo médico'],
            ['8598', '8500-8999', 'Medical Waste and Sanitation', 'Desechos médicos y sanitización'],
        ],
        'factory' => [
            ['6031', '6000-6999', 'Raw Materials', 'Materias primas'],
            ['6033', '6000-6999', 'Factory Supplies and Consumables', 'Suministros y consumibles de planta'],
            ['7577', '7500-7999', 'Freight and Shipping', 'Fletes y envíos'],
            ['7595', '7500-7999', 'Machine Repairs and Maintenance', 'Reparación y mantenimiento de máquinas'],
        ],
        'school' => [
            ['6064', '6000-6999', 'Educational Materials and Textbooks', 'Material educativo y libros de texto'],
            ['7597', '7500-7999', 'Classroom and Lab Equipment', 'Equipo de aulas y laboratorios'],
            ['8599', '8500-8999', 'Student Activities and Field Trips', 'Actividades estudiantiles y excursiones'],
        ],
    ];

    /** Categories: [name, Spanish, type, group, typical account, period, common, account code, reference]. */
    private const CATEGORIES = [
        'landscaping' => [
            ['Materials and supplies', 'Materiales y suministros', 'cogs', 'COGS', 'Cost of Goods Sold — Materials and Supplies', 'As purchased / recurring', true, '6040',
                'Items bought for a specific client project or installation: plants, trees, sod, mulch, soil, fertilizer, pavers, stone.'],
            ['Subcontractors and outside services', 'Subcontratistas y servicios externos', 'cogs', 'COGS', 'Cost of Goods Sold — Subcontractors', 'As incurred', false, '6050',
                'An independent contractor doing specialized labor on a job (e.g. a licensed electrician for outdoor lighting, hardscaping sub-crews).'],
            ['Equipment and tool expense', 'Herramientas y equipo menor', 'operating_expense', 'Technology and Equipment Expenses', 'Small Tools and Equipment Expense', 'As needed / one-time', false, '7560',
                'Small tools, safety gear or replacement parts that are not capitalized as fixed assets.'],
            ['Fuel, oil and vehicle maintenance', 'Combustible, aceite y mantenimiento de vehículos', 'operating_expense', 'Travel and Transportation Expenses', 'Fuel and Vehicle Maintenance Expense', 'As incurred / recurring', true, '7570',
                'Gas, oil, truck or trailer parts bought from a supplier or fuel station for the daily routes.'],
        ],
        'salon' => [
            ['Salon products and supplies', 'Productos y suministros de salón', 'cogs', 'COGS', 'Cost of Goods Sold — Salon Products', 'As purchased / recurring', true, '6060',
                'Hair color, developer, shampoo, treatments and retail products used on or sold to clients.'],
            ['Salon tools and small equipment', 'Herramientas y equipo menor de salón', 'operating_expense', 'Technology and Equipment Expenses', 'Small Tools and Equipment Expense', 'As needed / one-time', false, '7580',
                'Scissors, clippers, dryers, brushes and other small tools not capitalized as fixed assets.'],
            ['Laundry and cleaning', 'Lavandería y limpieza', 'operating_expense', 'Facilities and Office Expenses', 'Laundry and Cleaning Expense', 'As incurred / recurring', false, '8590',
                'Towels, capes, laundry service and cleaning / disinfecting supplies.'],
        ],
        'retail' => [
            ['Packaging and store supplies', 'Empaques y suministros de tienda', 'operating_expense', 'Facilities and Office Expenses', 'Packaging and Store Supplies Expense', 'As purchased / recurring', false, '8595',
                'Bags, boxes, tags, receipt paper and other supplies used in the store.'],
        ],
        'restaurant' => [
            ['Food and beverage supplies', 'Alimentos y bebidas', 'cogs', 'COGS', 'Cost of Goods Sold — Food and Beverage', 'Weekly or biweekly', true, '6070',
                'Ingredients, produce, meat, dairy and drinks bought to prepare and sell.'],
            ['Kitchen supplies and smallwares', 'Utensilios y suministros de cocina', 'operating_expense', 'Technology and Equipment Expenses', 'Kitchen Supplies Expense', 'As needed / one-time', false, '7590',
                'Pans, utensils, containers, to-go packaging and other small kitchen items.'],
            ['Laundry and cleaning', 'Lavandería y limpieza', 'operating_expense', 'Facilities and Office Expenses', 'Laundry and Cleaning Expense', 'As incurred / recurring', false, '8590',
                'Linens, uniforms laundry, cleaning and sanitizing supplies.'],
        ],
        'laundry' => [
            ['Laundry supplies', 'Suministros de lavandería', 'cogs', 'COGS', 'Cost of Goods Sold — Laundry Supplies', 'As purchased / recurring', true, '6080',
                'Detergent, softener, stain removers, hangers, bags and wrapping used on customers\' orders.'],
            ['Fuel, oil and vehicle maintenance', 'Combustible, aceite y mantenimiento de vehículos', 'operating_expense', 'Travel and Transportation Expenses', 'Fuel and Vehicle Maintenance Expense', 'As incurred / recurring', true, '7570',
                'Gas and repairs for the pick-up and delivery vans.'],
            ['Machine repairs and maintenance', 'Reparación y mantenimiento de máquinas', 'operating_expense', 'Technology and Equipment Expenses', 'Machine Repairs Expense', 'As needed / one-time', false, '7595',
                'Washer, dryer, press and boiler repairs and service visits.'],
        ],
        'delivery' => [
            ['Contract drivers and couriers', 'Conductores y mensajeros contratados', 'cogs', 'COGS', 'Cost of Goods Sold — Contract Drivers', 'Weekly or biweekly', true, '6055',
                'Independent drivers / couriers paid per route or per delivery.'],
            ['Fuel, oil and vehicle maintenance', 'Combustible, aceite y mantenimiento de vehículos', 'operating_expense', 'Travel and Transportation Expenses', 'Fuel and Vehicle Maintenance Expense', 'As incurred / recurring', true, '7570',
                'Gas, oil, tires and repairs for the delivery vehicles.'],
            ['Vehicle leasing and registration', 'Arrendamiento y registro de vehículos', 'operating_expense', 'Travel and Transportation Expenses', 'Vehicle Leasing Expense', 'Monthly', false, '7575',
                'Van / truck leases, registration, plates and permits.'],
        ],
        'call_center' => [
            ['Headsets and call equipment', 'Auriculares y equipo de llamadas', 'operating_expense', 'Technology and Equipment Expenses', 'Small Tools and Equipment Expense', 'As needed / one-time', false, '7598',
                'Headsets, desk phones and small call-floor equipment not capitalized as fixed assets.'],
            ['Telephony and VoIP services', 'Telefonía y servicios VoIP', 'operating_expense', 'Facilities and Office Expenses', 'Telephony Expense', 'Monthly', true, '8597',
                'Phone lines, VoIP minutes, dialer and contact-center software.'],
        ],
        'real_estate' => [
            ['Co-broker and referral fees', 'Honorarios de co-corretaje y referidos', 'cogs', 'COGS', 'Cost of Sales — Co-broker / Referral Fees', 'As incurred', true, '6090',
                'The share of a sale\'s commission paid to the buyer\'s agent / another brokerage, and referral fees.'],
            ['Property listing and marketing', 'Publicidad de propiedades', 'operating_expense', 'Sales and Marketing Expenses', 'Listing and Marketing Expense', 'As incurred / recurring', true, '8592',
                'Listing portals, photos, video and drone tours, signs, open-house flyers and staging.'],
            ['MLS and board dues', 'Cuotas de MLS y asociaciones', 'operating_expense', 'Facilities and Office Expenses', 'Dues and Memberships Expense', 'Monthly or annual', false, '8593',
                'MLS access, realtor board / association dues and licence renewals.'],
        ],
        'legal' => [
            ['Court filing fees and case costs', 'Tasas judiciales y costos de casos', 'cogs', 'COGS', 'Cost of Services — Case Costs', 'As incurred', true, '6095',
                'Filing and service fees, court reporters, expert witnesses and copies billed to a client\'s case.'],
            ['Legal research and library', 'Investigación jurídica y biblioteca', 'operating_expense', 'Professional and Administrative Services', 'Legal Research Expense', 'Monthly', false, '8594',
                'Legal research databases, case-law subscriptions and law books.'],
            ['Bar dues and malpractice insurance', 'Cuotas del colegio y seguro de responsabilidad', 'operating_expense', 'Professional and Administrative Services', 'Dues and Insurance Expense', 'Monthly or annual', false, '8596',
                'Bar association dues, CLE courses and professional liability (malpractice) insurance.'],
        ],
        'hospital' => [
            ['Medical supplies and pharmaceuticals', 'Suministros médicos y medicamentos', 'cogs', 'COGS', 'Cost of Services — Medical Supplies', 'Weekly or biweekly', true, '6062',
                'Medicines, gloves, syringes, dressings, lab reagents and other supplies used on patients.'],
            ['Medical equipment maintenance', 'Mantenimiento de equipo médico', 'operating_expense', 'Technology and Equipment Expenses', 'Equipment Maintenance Expense', 'As needed / one-time', false, '7596',
                'Service contracts, calibration and repairs of monitors, X-ray, lab and other medical equipment.'],
            ['Medical waste and sanitation', 'Desechos médicos y sanitización', 'operating_expense', 'Facilities and Office Expenses', 'Waste and Sanitation Expense', 'Monthly', true, '8598',
                'Biohazard / sharps waste pickup, sterilization and sanitation supplies.'],
        ],
        'factory' => [
            ['Raw materials', 'Materias primas', 'cogs', 'COGS', 'Cost of Goods Sold — Raw Materials', 'As purchased / recurring', true, '6031',
                'Materials and components that go into the products made (steel, plastic, fabric, wood, parts).'],
            ['Factory supplies and consumables', 'Suministros y consumibles de planta', 'cogs', 'COGS', 'Cost of Goods Sold — Factory Supplies', 'As purchased / recurring', false, '6033',
                'Lubricants, packaging, labels, safety gear and other consumables used on the production line.'],
            ['Freight and shipping', 'Fletes y envíos', 'operating_expense', 'Travel and Transportation Expenses', 'Freight Expense', 'As incurred / recurring', true, '7577',
                'Inbound freight of materials and outbound shipping of finished goods.'],
            ['Machine repairs and maintenance', 'Reparación y mantenimiento de máquinas', 'operating_expense', 'Technology and Equipment Expenses', 'Machine Repairs Expense', 'As needed / one-time', false, '7595',
                'Spare parts, service visits and repairs of production machines.'],
        ],
        'school' => [
            ['Educational materials and textbooks', 'Material educativo y libros de texto', 'cogs', 'COGS', 'Cost of Services — Educational Materials', 'As purchased / recurring', true, '6064',
                'Textbooks, workbooks, printed material, art and lab supplies used in classes.'],
            ['Classroom and lab equipment', 'Equipo de aulas y laboratorios', 'operating_expense', 'Technology and Equipment Expenses', 'Small Tools and Equipment Expense', 'As needed / one-time', false, '7597',
                'Projectors, whiteboards, lab instruments, sports gear and other equipment not capitalized as fixed assets.'],
            ['Student activities and field trips', 'Actividades estudiantiles y excursiones', 'operating_expense', 'General Expenses', 'Student Activities Expense', 'As incurred', false, '8599',
                'Field trips, events, graduations, competitions and their transport.'],
        ],
    ];

    /** Regular monthly bills: [category, base amount, variation 0-1, day of the month]. */
    private const BILLS = [
        'general' => [
            ['Office rent', 1800, 0, 1], ['Software subscriptions', 180, 0.2, 3], ['Internet and telecommunications', 95, 0, 5],
            ['Utilities', 260, 0.35, 12], ['Insurance', 240, 0, 15], ['Office supplies', 110, 0.6, 18], ['Advertising', 450, 0.5, 20],
            ['Accounting services', 350, 0, 25], ['Credit card processing fees', 140, 0.4, 28], ['Bank fees', 28, 0.4, 28],
        ],
        'landscaping' => [
            ['Office rent', 1500, 0, 1], ['Software subscriptions', 60, 0, 3], ['Internet and telecommunications', 95, 0, 5],
            ['Fuel, oil and vehicle maintenance', 650, 0.35, 8], ['Materials and supplies', 2400, 0.5, 10], ['Utilities', 180, 0.3, 12],
            ['Equipment and tool expense', 180, 0.7, 14], ['Insurance', 380, 0, 15], ['Advertising', 300, 0.5, 20],
            ['Subcontractors and outside services', 1200, 0.6, 22], ['Accounting services', 300, 0, 25],
            ['Credit card processing fees', 120, 0.4, 28], ['Bank fees', 25, 0.4, 28],
        ],
        'salon' => [
            ['Office rent', 2200, 0, 1], ['Software subscriptions', 120, 0, 3], ['Internet and telecommunications', 80, 0, 5],
            ['Salon products and supplies', 900, 0.4, 9], ['Utilities', 300, 0.3, 12], ['Laundry and cleaning', 120, 0.3, 14],
            ['Insurance', 180, 0, 15], ['Salon tools and small equipment', 90, 0.7, 17], ['Advertising', 250, 0.5, 20],
            ['Accounting services', 250, 0, 25], ['Credit card processing fees', 220, 0.4, 28], ['Bank fees', 25, 0.4, 28],
        ],
        'retail' => [
            ['Office rent', 2500, 0, 1], ['Software subscriptions', 150, 0, 3], ['Internet and telecommunications', 90, 0, 5],
            ['Inventory purchases', 4000, 0.4, 6], ['Utilities', 350, 0.3, 12], ['Packaging and store supplies', 120, 0.5, 14],
            ['Insurance', 220, 0, 15], ['Shipping and delivery', 200, 0.5, 17], ['Advertising', 400, 0.5, 20],
            ['Accounting services', 300, 0, 25], ['Credit card processing fees', 380, 0.4, 28], ['Bank fees', 30, 0.4, 28],
        ],
        'restaurant' => [
            ['Office rent', 3500, 0, 1], ['Software subscriptions', 160, 0, 3], ['Food and beverage supplies', 6500, 0.3, 4],
            ['Internet and telecommunications', 90, 0, 5], ['Utilities', 900, 0.3, 12], ['Kitchen supplies and smallwares', 200, 0.6, 13],
            ['Laundry and cleaning', 250, 0.3, 14], ['Insurance', 400, 0, 15], ['Repairs and maintenance', 250, 0.7, 18],
            ['Advertising', 300, 0.5, 20], ['Accounting services', 400, 0, 25], ['Credit card processing fees', 650, 0.3, 28], ['Bank fees', 35, 0.4, 28],
        ],
        'professional' => [
            ['Office rent', 1800, 0, 1], ['Software subscriptions', 300, 0.2, 3], ['Internet and telecommunications', 95, 0, 5],
            ['Utilities', 200, 0.3, 12], ['Insurance', 300, 0, 15], ['Office supplies', 90, 0.6, 18], ['Advertising', 250, 0.5, 20],
            ['Dues and memberships', 60, 0, 22], ['Accounting services', 350, 0, 25], ['Credit card processing fees', 90, 0.4, 28], ['Bank fees', 25, 0.4, 28],
        ],
        'laundry' => [
            ['Office rent', 2000, 0, 1], ['Software subscriptions', 80, 0, 3], ['Internet and telecommunications', 80, 0, 5],
            ['Laundry supplies', 700, 0.4, 7], ['Fuel, oil and vehicle maintenance', 400, 0.35, 9], ['Utilities', 1100, 0.3, 12],
            ['Insurance', 220, 0, 15], ['Machine repairs and maintenance', 250, 0.7, 17], ['Advertising', 200, 0.5, 20],
            ['Accounting services', 250, 0, 25], ['Credit card processing fees', 180, 0.4, 28], ['Bank fees', 25, 0.4, 28],
        ],
        'delivery' => [
            ['Office rent', 1200, 0, 1], ['Software subscriptions', 150, 0, 3], ['Internet and telecommunications', 120, 0, 5],
            ['Vehicle leasing and registration', 1600, 0, 6], ['Fuel, oil and vehicle maintenance', 2200, 0.35, 8], ['Utilities', 150, 0.3, 12],
            ['Insurance', 900, 0, 15], ['Contract drivers and couriers', 3500, 0.4, 18], ['Advertising', 200, 0.5, 20],
            ['Accounting services', 300, 0, 25], ['Credit card processing fees', 160, 0.4, 28], ['Bank fees', 30, 0.4, 28],
        ],
        'call_center' => [
            ['Office rent', 3000, 0, 1], ['Software subscriptions', 600, 0.1, 3], ['Telephony and VoIP services', 900, 0.3, 5],
            ['Utilities', 450, 0.3, 12], ['Insurance', 250, 0, 15], ['Headsets and call equipment', 150, 0.7, 17], ['Office supplies', 120, 0.6, 18],
            ['Advertising', 200, 0.5, 20], ['Accounting services', 400, 0, 25], ['Credit card processing fees', 60, 0.4, 28], ['Bank fees', 30, 0.4, 28],
        ],
        'real_estate' => [
            ['Office rent', 2200, 0, 1], ['Software subscriptions', 250, 0.1, 3], ['Internet and telecommunications', 110, 0, 5],
            ['MLS and board dues', 95, 0, 7], ['Property listing and marketing', 1200, 0.5, 10], ['Utilities', 220, 0.3, 12],
            ['Insurance', 350, 0, 15], ['Co-broker and referral fees', 2500, 0.8, 18], ['Office supplies', 90, 0.6, 20],
            ['Accounting services', 350, 0, 25], ['Credit card processing fees', 60, 0.4, 28], ['Bank fees', 30, 0.4, 28],
        ],
        'legal' => [
            ['Office rent', 2800, 0, 1], ['Software subscriptions', 300, 0.1, 3], ['Internet and telecommunications', 110, 0, 5],
            ['Legal research and library', 250, 0, 7], ['Court filing fees and case costs', 600, 0.7, 10], ['Utilities', 220, 0.3, 12],
            ['Insurance', 200, 0, 15], ['Bar dues and malpractice insurance', 450, 0, 16], ['Office supplies', 120, 0.6, 18],
            ['Advertising', 300, 0.5, 20], ['Accounting services', 350, 0, 25], ['Credit card processing fees', 80, 0.4, 28], ['Bank fees', 30, 0.4, 28],
        ],
        'hospital' => [
            ['Office rent', 9000, 0, 1], ['Software subscriptions', 900, 0.1, 3], ['Medical supplies and pharmaceuticals', 14000, 0.3, 4],
            ['Internet and telecommunications', 300, 0, 5], ['Medical waste and sanitation', 1200, 0.2, 8], ['Utilities', 4500, 0.3, 12],
            ['Insurance', 3500, 0, 15], ['Medical equipment maintenance', 1800, 0.6, 17], ['Advertising', 600, 0.5, 20],
            ['Accounting services', 900, 0, 25], ['Credit card processing fees', 1200, 0.3, 28], ['Bank fees', 60, 0.4, 28],
        ],
        'factory' => [
            ['Office rent', 6500, 0, 1], ['Software subscriptions', 400, 0.1, 3], ['Raw materials', 18000, 0.35, 4],
            ['Internet and telecommunications', 200, 0, 5], ['Factory supplies and consumables', 1500, 0.4, 8], ['Freight and shipping', 2400, 0.4, 10],
            ['Utilities', 5200, 0.3, 12], ['Insurance', 1600, 0, 15], ['Machine repairs and maintenance', 1400, 0.7, 17],
            ['Advertising', 400, 0.5, 20], ['Accounting services', 700, 0, 25], ['Credit card processing fees', 300, 0.4, 28], ['Bank fees', 50, 0.4, 28],
        ],
        'school' => [
            ['Office rent', 5500, 0, 1], ['Software subscriptions', 450, 0.1, 3], ['Internet and telecommunications', 250, 0, 5],
            ['Educational materials and textbooks', 1800, 0.5, 7], ['Utilities', 2200, 0.3, 12], ['Insurance', 900, 0, 15],
            ['Classroom and lab equipment', 600, 0.7, 17], ['Student activities and field trips', 700, 0.8, 19], ['Advertising', 350, 0.5, 20],
            ['Accounting services', 500, 0, 25], ['Credit card processing fees', 250, 0.4, 28], ['Bank fees', 40, 0.4, 28],
        ],
    ];

    /** Accounting demo wording: [quarterly invoice, quarterly supplies purchase, supplies account code]. */
    private const JOURNAL = [
        'general' => ['Invoice to corporate customer (on credit)', 'Printer paper and toner', null],
        'it_services' => ['Managed IT services invoice (on credit)', 'Printer paper and toner', null],
        'landscaping' => ['Landscape maintenance contract invoice (on credit)', 'Mulch, soil and plants for client jobs', '6040'],
        'salon' => ['Corporate / event styling invoice (on credit)', 'Hair color and salon products', '6060'],
        'retail' => ['Wholesale order to business customer (on credit)', 'Bags, tags and store supplies', '8595'],
        'restaurant' => ['Catering invoice (on credit)', 'Weekly produce and dry goods order', '6070'],
        'professional' => ['Consulting engagement invoice (on credit)', 'Printer paper and toner', null],
        'laundry' => ['Hotel / restaurant linen service invoice (on credit)', 'Detergent, softener and garment bags', '6080'],
        'delivery' => ['Monthly delivery contract invoice (on credit)', 'Fuel for the delivery routes', '7570'],
        'call_center' => ['Monthly call-handling contract invoice (on credit)', 'Headsets and call-floor supplies', '7598'],
        'real_estate' => ['Seller commission invoice at closing (on credit)', 'Open-house signs and listing flyers', '8592'],
        'legal' => ['Legal services invoice — monthly retainer (on credit)', 'Court filing fees for a client case', '6095'],
        'hospital' => ['Insurance claims billed for patient services (on credit)', 'Weekly medical supplies order', '6062'],
        'factory' => ['Wholesale order of finished goods to a distributor (on credit)', 'Raw materials for the production run', '6031'],
        'school' => ['Term tuition invoice (on credit)', 'Textbooks and class materials for the term', '6064'],
    ];

    /** Types that share another type's accounts / categories / bills / journal wording. */
    private const SAME_AS = ['barber' => 'salon', 'catering' => 'restaurant'];

    private static function base(string $type): string
    {
        return self::SAME_AS[$type] ?? $type;
    }

    public static function valid(?string $type): string
    {
        return isset(self::LABELS[$type ?? '']) ? $type : self::DEFAULT;
    }

    /** The company's business type (companies.business_type), else general. */
    public static function of(int $companyId): string
    {
        $type = Schema::hasColumn('companies', 'business_type')
            ? DB::table('companies')->where('id', $companyId)->value('business_type')
            : null;

        return self::valid($type);
    }

    public static function set(int $companyId, string $type): void
    {
        if (Schema::hasColumn('companies', 'business_type')) {
            DB::table('companies')->where('id', $companyId)->update(['business_type' => self::valid($type)]);
        }
    }

    /** Every type's expense category: English name => Spanish (translations migration). */
    public static function categoryTranslations(): array
    {
        $out = [];
        foreach (self::CATEGORIES as $cats) {
            foreach ($cats as [$name, $nameEs]) {
                $out[$name] = $nameEs;
            }
        }

        return $out;
    }

    public static function bills(string $type): array
    {
        return self::BILLS[self::base($type)] ?? self::BILLS[self::DEFAULT];
    }

    /** @return array{0: string, 1: string, 2: ?string} */
    public static function journal(string $type): array
    {
        return self::JOURNAL[self::base($type)] ?? self::JOURNAL[self::DEFAULT];
    }

    /**
     * The type's accounts + expense categories for the company (on top of the
     * default set). Idempotent: accounts by code, categories by name.
     *
     * @return array{accounts: int, categories: int} rows created
     */
    public static function ensure(int $companyId, string $type): array
    {
        $created = ['accounts' => 0, 'categories' => 0];
        if (! $companyId || ! Schema::hasColumn('acc_chart_of_accounts', 'company_id')) {
            return $created;
        }
        $type = self::base($type);
        $now = now();
        $ids = DB::table('acc_chart_of_accounts')->where('company_id', $companyId)->pluck('id', 'code')->all();
        foreach (self::ACCOUNTS[$type] ?? [] as [$code, $parent, $name, $nameEs]) {
            if (isset($ids[$code])) {
                continue;
            }
            $ids[$code] = DB::table('acc_chart_of_accounts')->insertGetId([
                'company_id' => $companyId, 'account_id' => null, 'code' => $code, 'name' => $name, 'name_es' => $nameEs,
                'type' => DefaultAccounting::typeOf($code), 'parent_id' => $ids[$parent] ?? null, 'level' => 1, 'is_active' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $created['accounts']++;
        }

        $existing = DB::table('expense_categories')->where('company_id', $companyId)->whereNull('deleted_at')
            ->pluck('name')->map(fn ($n) => mb_strtolower(trim($n)))->all();
        $userId = DB::table('users')->where('company_id', $companyId)->whereNull('deleted_at')
            ->orderByRaw("type = 'admin' desc")->orderBy('id')->value('id')
            ?? DB::table('users')->orderBy('id')->value('id');
        $sort = (int) DB::table('expense_categories')->where('company_id', $companyId)->max('sort_order');
        foreach (self::CATEGORIES[$type] ?? [] as [$name, $nameEs, $acType, $group, $typical, $period, $common, $code, $reference]) {
            if (in_array(mb_strtolower($name), $existing, true)) {
                continue;
            }
            TranslationKeys::ensure($name, ['es' => $nameEs]);
            DB::table('expense_categories')->insert([
                'company_id' => $companyId, 'user_id' => $userId, 'name' => $name, 'reference' => $reference, 'description' => null,
                'account_type' => $acType, 'expense_group' => $group, 'expense_group_es' => DefaultAccounting::GROUPS[$group] ?? null,
                'typical_account' => $typical, 'period' => $period, 'period_es' => DefaultAccounting::PERIODS[$period] ?? null,
                'approximate_amount' => null, 'is_common' => $common ? 1 : 0, 'coa_code' => $code,
                'sort_order' => ++$sort, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $existing[] = mb_strtolower($name);
            $created['categories']++;
        }

        return $created;
    }
}
