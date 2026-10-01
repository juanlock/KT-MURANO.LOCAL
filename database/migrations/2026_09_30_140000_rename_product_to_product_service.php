<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Singular follow-up to 2026_09_30_130000 ("Products" -> "Products &
 * Services"): "Product" -> "Product/Service" wherever the word means a
 * catalog item (create/edit/save/search/select, name, code, price, cost,
 * tax, image, details, invoice column, messages, sales reports).
 * English + Spanish ("Producto/Servicio").
 *
 * Deliberately NOT renamed (product types or physical-goods / storefront
 * features): StandardProduct, VariableProduct, ComboProduct, ServiceProduct,
 * Subscription_Product, ProductVariant, ProductHasMultiVariants,
 * Product_Has_Imei_Serial_number, Product_Serial_Inventory,
 * Choose_Unit_Product, UnitProduct, Product_Quantity_Alerts,
 * ProductQuantityAlerts, Product_purchases_report, POS_Return,
 * Featured_Product, Product_Reviews, Product*Gallery, Resize_Product_Images,
 * Product_Image_Max_Size, Type_Product, Income_Account_Name_Hint.
 *
 * down() restores the previous values (saved by up() to
 * storage/app/migration_backups/ on the machine that ran it).
 */
return new class extends Migration
{
    // key => [new en, new es]
    public const RENAME = [
        'AddProduct' => ['Create Product/Service', 'Crear Producto/Servicio'],
        'Add_Product' => ['Add Product/Service', 'Añadir Producto/Servicio'],
        'EditProduct' => ['Edit Product/Service', 'Editar Producto/Servicio'],
        'UpdateProduct' => ['Update Product/Service', 'Actualizar Producto/Servicio'],
        'Save_Product' => ['Save Product/Service', 'Guardar Producto/Servicio'],
        'Quick_Add_Product' => ['Quick Add Product/Service', 'Añadir Producto/Servicio rápido'],
        'ProductDetails' => ['Product/Service Details', 'Detalles del Producto/Servicio'],
        'ProductSummary' => ['Product/Service Summary', 'Resumen del Producto/Servicio'],
        'ProductManagement' => ['Product/Service Management', 'Gestión de Productos/Servicios'],
        'import_products' => ['Import Products & Services', 'Importar productos y servicios'],
        'Product' => ['Product/Service', 'Producto/Servicio'],
        'pdf.product' => ['Product/Service', 'Producto/Servicio'],
        'ProductName' => ['Product/Service Name', 'Nombre del Producto/Servicio'],
        'product_name' => ['Product/Service Name', 'Nombre del Producto/Servicio'],
        'Enter_Name_Product' => ['Enter Product/Service Name', 'Ingrese el nombre del Producto/Servicio'],
        'Product_Code' => ['Product/Service Code', 'Código del Producto/Servicio'],
        'CodeProduct' => ['Product/Service Code', 'Código del Producto/Servicio'],
        'Enter_Product_Code' => ['Enter Product/Service Code', 'Ingrese el código del Producto/Servicio'],
        'ProductCodeName' => ['Product/Service (Code - Name)', 'Producto/Servicio (Código - Nombre)'],
        'Product_ID' => ['Product/Service ID', 'ID del Producto/Servicio'],
        'ProductPrice' => ['Product/Service Price', 'Precio del Producto/Servicio'],
        'Enter_Product_Price' => ['Enter Product/Service Price', 'Ingrese el precio del Producto/Servicio'],
        'ProductCost' => ['Product/Service Cost', 'Costo del Producto/Servicio'],
        'Product_Cost' => ['Product/Service Cost', 'Costo del Producto/Servicio'],
        'Enter_Product_Cost' => ['Enter Product/Service Cost', 'Ingrese el costo del Producto/Servicio'],
        'ProductTax' => ['Product/Service Tax', 'Impuesto del Producto/Servicio'],
        'TotalProductTax' => ['Total Product/Service Tax', 'Impuesto total del Producto/Servicio'],
        'ProductImage' => ['Product/Service Image', 'Imagen del Producto/Servicio'],
        'Product_Description' => ['Product/Service Description', 'Descripción del Producto/Servicio'],
        'SearchProduct' => ['Search Product/Service', 'Buscar Producto/Servicio'],
        'Search_product' => ['Search Product/Service', 'Buscar Producto/Servicio'],
        'Search_Product_by_Code_Name' => ['Search Product/Service by code or name', 'Buscar Producto/Servicio por código o nombre'],
        'Scan_Search_Product_by_Code_Name' => ['Scan/Search Product/Service by Code Or Name', 'Escanear / Buscar Producto/Servicio por código o nombre'],
        'SelectProduct' => ['Select Product/Service', 'Seleccionar Producto/Servicio'],
        'Choose_Product' => ['Choose Product/Service', 'Elegir Producto/Servicio'],
        'ChooseProduct' => ['Choose Product/Service', 'Elegir Producto/Servicio'],
        'AddProductToList' => ['Please Add Product/Service To List !!', 'Agregue un Producto/Servicio a la lista'],
        'AlreadyAdd' => ['This Product/Service Already Added !!', 'Este Producto/Servicio ya ha sido agregado'],
        'Product_Not_Found' => ['Product/Service Not Found', 'Producto/Servicio no encontrado'],
        'Product_not_available' => ['Product/Service not available', 'Producto/Servicio no disponible'],
        'Product_name_minimum_3_characters' => ['Product/Service name minimum 3 characters', 'El nombre del Producto/Servicio debe tener al menos 3 caracteres'],
        'ProductHasPromotion' => ['Product/Service Has Promotion', 'El Producto/Servicio tiene promoción'],
        'This_Product_Not_For_Selling' => ['This Product/Service Not For Selling', 'Este Producto/Servicio no es para la venta'],
        'By Product' => ['By Product/Service', 'Por Producto/Servicio'],
        'By_Product' => ['By Product/Service', 'Por Producto/Servicio'],
        'Profit by Product' => ['Profit by Product/Service', 'Beneficio por Producto/Servicio'],
        'product_sales_report' => ['Product/Service sales report', 'Informe de ventas de productos y servicios'],
        'Product_Performance_Table' => ['Product/Service Performance Table', 'Tabla de rendimiento de productos y servicios'],
        'Show_Product_Discount' => ['Show Product/Service Discount', 'Mostrar descuento del Producto/Servicio'],
        'Show_Product_Tax' => ['Show Product/Service Tax', 'Mostrar impuesto del Producto/Servicio'],
        'Show_Product_Images_in_POS' => ['Show Product/Service Images in POS', 'Mostrar imágenes de productos y servicios en el POS'],
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

        @mkdir(dirname(self::backupPath()), 0775, true);
        file_put_contents(self::backupPath(), json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        if (function_exists('forget_db_translations')) {
            forget_db_translations();
        }
    }

    private static function backupPath(): string
    {
        return storage_path('app/migration_backups/2026_09_30_140000_product_rename.json');
    }

    public function down(): void
    {
        $backup = is_file(self::backupPath()) ? (json_decode((string) file_get_contents(self::backupPath()), true) ?: []) : [];
        foreach ($backup as $key => $vals) {
            DB::table('translations')->where('key', $key)->update(['en' => $vals['en'], 'es' => $vals['es'], 'updated_at' => now()]);
        }

        if (function_exists('forget_db_translations')) {
            forget_db_translations();
        }
    }
};
