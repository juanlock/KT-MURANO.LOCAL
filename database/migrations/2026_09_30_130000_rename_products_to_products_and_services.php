<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Products" -> "Products & Services" wherever the word names the catalog
 * (menu, breadcrumbs, lists, sales/catalog reports, POS) -- the catalog holds
 * services too. English + Spanish (the languages in use).
 *
 * Deliberately NOT renamed (physical goods only, or a storefront feature
 * name): Products_Per_Aisle, ProductsExpired, AddProducts (purchase order),
 * Combined_Products / ComboProducts, Products_in_Collection,
 * Related_Products_Hint.
 *
 * New key Products_Only ("Products" / "Productos") for screens where the word
 * means physical goods as opposed to services: the Type filter
 * (Products vs Services) and the stock reports.
 *
 * down() restores the previous values (saved by up() to
 * storage/app/migration_backups/ on the machine that ran it).
 */
return new class extends Migration
{
    // key => [new en, new es]
    private const RENAME = [
        'Products' => ['Products & Services', 'Productos y Servicios'],
        'productsList' => ['All Products & Services', 'Todos los productos y servicios'],
        'TotalProducts' => ['Total Products & Services', 'Total de productos y servicios'],
        'product_report' => ['Products & Services report', 'Informe de productos y servicios'],
        'Products_Sold' => ['Products & services sold', 'Productos y servicios vendidos'],
        'products_sold_summary' => ['Products & Services Sold Summary', 'Resumen de productos y servicios vendidos'],
        'Zero_Sales_Products_Report' => ['Zero Sales Products & Services Report', 'Informe de productos y servicios sin ventas'],
        'Top_Selling_Products' => ['Top Selling Products & Services', 'Productos y servicios más vendidos'],
        'Top5Products' => ['Top 5 Products & Services', 'Los 5 mejores productos y servicios'],
        'Top_Products_by_Month' => ['Top Products & Services by Month', 'Principales productos y servicios por mes'],
        'Top_Products_Chart' => ['Top Products & Services (Chart)', 'Mejores productos y servicios (gráfico)'],
        'Top_Profit_Products' => ['Top Profit Products & Services', 'Productos y servicios más rentables'],
        'Top_Profit_Products_Top_15_Chart' => ['Top Profit Products & Services (Top 15 Chart)', 'Productos y servicios más rentables (gráfico top 15)'],
        'Top_Revenue_Products' => ['Top Revenue Products & Services', 'Productos y servicios con más ingresos'],
        'Revenue_vs_Profit_Top_Products' => ['Revenue vs Profit (Top Products & Services)', 'Ingresos frente a beneficio (mejores productos y servicios)'],
        'Dashboard_Chart_Top_Selling' => ['Top Selling Products & Services chart', 'Gráfico de productos y servicios más vendidos'],
        'Selected_Products' => ['Selected Products & Services', 'Productos y servicios seleccionados'],
        'pos.Available_Products' => ['Available Products & Services', 'Productos y servicios disponibles'],
    ];

    public function up(): void
    {
        $backup = [];
        foreach (self::RENAME as $key => [$en, $es]) {
            $row = DB::table('translations')->where('key', $key)->first(['en', 'es']);
            if (! $row) {
                continue;
            }
            $backup[$key] = ['en' => $row->en, 'es' => $row->es];
            DB::table('translations')->where('key', $key)->update(['en' => $en, 'es' => $es, 'updated_at' => now()]);
        }

        // The hint text points at the menu section by name.
        $hint = DB::table('translations')->where('key', 'No_Vehicle_Catalog_Hint')->first(['en', 'es']);
        if ($hint) {
            $backup['No_Vehicle_Catalog_Hint'] = ['en' => $hint->en, 'es' => $hint->es];
            DB::table('translations')->where('key', 'No_Vehicle_Catalog_Hint')->update([
                'en' => str_replace('under Products', 'under Products & Services', $hint->en),
                'es' => $hint->es ? preg_replace('/\bProductos\b/u', 'Productos y Servicios', $hint->es, 1) : $hint->es,
                'updated_at' => now(),
            ]);
        }

        if (! DB::table('translations')->where('key', 'Products_Only')->exists()) {
            DB::table('translations')->insert([
                'key' => 'Products_Only', 'is_default' => 1, 'en' => 'Products', 'es' => 'Productos',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Kept for down(): this machine's exact previous values.
        @mkdir(dirname(self::backupPath()), 0775, true);
        file_put_contents(self::backupPath(), json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        if (function_exists('forget_db_translations')) {
            forget_db_translations();
        }
    }

    private static function backupPath(): string
    {
        return storage_path('app/migration_backups/2026_09_30_130000_products_rename.json');
    }

    public function down(): void
    {
        $backup = is_file(self::backupPath()) ? (json_decode((string) file_get_contents(self::backupPath()), true) ?: []) : [];
        foreach ($backup as $key => $vals) {
            DB::table('translations')->where('key', $key)->update(['en' => $vals['en'], 'es' => $vals['es'], 'updated_at' => now()]);
        }
        DB::table('translations')->where('key', 'Products_Only')->delete();

        if (function_exists('forget_db_translations')) {
            forget_db_translations();
        }
    }
};
