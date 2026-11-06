<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Turismo Cautivo sells services, no physical products for now (user's request, 2026-11-04): its side menu hides what is
 * only about physical stock, for every role of that company. It keeps the list and the form of products (its fares:
 * tickets are service products) and their categories. Only hidden: Manage Menu brings any of them back.
 * Safe to run twice.
 */
return new class extends Migration
{
    private const HIDE = [
        // Products.
        '/app/products/opening_stock_import', '/app/products/barcode', '/app/products/count_stock', '/app/products/SubCategories', '/app/products/Brands',
        '/app/products/SizeGuides', '/app/products/Units', '/app/products/vehicle_fitment', '/app/products/Batches', '/app/serial_numbers/list',
        '/app/products/import', '/app/products/import-update',
        // Stock reports.
        '/app/reports/inventory_valuation_summary', '/app/reports/stock_report', '/app/reports/product_purchases_report', '/app/reports/stock_inventory_valuation',
        '/app/reports/quantity_alerts', '/app/reports/warranty_guarantee_report', '/app/reports/internal_location_report', 'custom_serials',
        '/app/reports/expiry_report', '/app/reports/batch_register_report', '/app/reports/report_sales_by_brand', '/app/reports/zero_sales_products_report',
        // Stock movements.
        'adjustments', 'transfers', 'damages',
        // A sale's shipments: its parcels are in Transport -> Parcels, the group would show two.
        '/app/sales/shipment',
    ];

    public function up(): void
    {
        $cid = (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
        if (! $cid) {
            return;
        }
        echo '  Turismo: '.DB::table('menu_positions')->where('company_id', $cid)->whereIn('item_key', self::HIDE)->update(['hidden' => 1, 'updated_at' => now()])." menu items hidden\n";
    }

    public function down(): void
    {
    }
};
