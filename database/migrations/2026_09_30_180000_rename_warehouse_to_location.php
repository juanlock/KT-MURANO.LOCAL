<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * UI wording: "Warehouse" -> "Location", "Warehouses" -> "Locations", and
 * the rack/shelf "Warehouse Locations" -> "Sub-Locations". Not every company
 * runs a warehouse (a salon's main office is a location, not a warehouse).
 * Labels only -- tables, columns, routes and permission names stay
 * `warehouse*`. Spanish: Ubicación / Ubicaciones / Sub-ubicaciones (feminine,
 * so articles and adjectives are rewritten, not just the noun).
 */
return new class extends Migration
{
    // key => [new en, new es]
    public const RENAME = [
        'Access_Warehouses' => ['Access Locations', 'Acceso a ubicaciones'],
        'All_Warehouses' => ['All Locations', 'Todas las Ubicaciones'],
        'AllWarehouses' => ['All Locations', 'Todas las Ubicaciones'],
        'Assigned_warehouses' => ['Access locations', 'Ubicaciones asignadas'],
        'Batch_Qty_Edit_Hint' => ['Changing quantity will adjust location on-hand stock by the difference.', 'Cambiar la cantidad ajustará el stock disponible de la ubicación según la diferencia.'],
        'By_Warehouse' => ['By location', 'Por ubicación'],
        'ChangeWarehouse' => ['Change location', 'Cambiar ubicación'],
        'Choose_Warehouse' => ['Choose Location', 'Elija Ubicación'],
        'ChooseWarehouse' => ['Choose Location', 'Elija Ubicación'],
        'DefaultWarehouse' => ['Default Location', 'Ubicación predeterminada'],
        'Enter_City_Warehouse' => ['Enter Location City', 'Ingrese la ciudad de la ubicación'],
        'Enter_Country_Warehouse' => ['Enter Location Country', 'Ingrese el país de la ubicación'],
        'Enter_Email_Warehouse' => ['Enter Location Email', 'Ingrese el correo electrónico de la ubicación'],
        'Enter_Name_Warehouse' => ['Enter Location Name', 'Ingrese el nombre de la ubicación'],
        'Enter_Phone_Warehouse' => ['Enter Location Phone', 'Ingrese el teléfono de la ubicación'],
        'Enter_ZipCode_Warehouse' => ['Enter Location Zip Code', 'Ingrese el código postal de la ubicación'],
        'Filter_by_warehouse' => ['Filter by location', 'Filtrar por ubicación'],
        'FromWarehouse' => ['From Location', 'Desde la ubicación'],
        'ManagementWarehouse' => ['Location Management', 'Gestión de ubicaciones'],
        'No_Batches_Available' => ['No available batches for this product in the selected location', 'No hay lotes disponibles para este producto en la ubicación seleccionada'],
        'No_Serials_Available' => ['No serial numbers available in stock for this product / location.', 'No hay números de serie disponibles en stock para este producto / ubicación.'],
        'No_Warehouse' => ['No location', 'Sin ubicación'],
        'No_Warehouses' => ['No locations available', 'No hay ubicaciones disponibles'],
        'NoStoreWarehouses' => ['No location is enabled for the online store yet.', 'Aún no hay ninguna ubicación activada para la tienda en línea.'],
        'Opening_Batch_Help' => ['This product has stock that is not assigned to any batch (from purchases made before batch tracking was enabled). Assign it to opening batches with expiry dates so it can be sold with batch tracking. Location stock is not changed.', 'Este producto tiene stock que no está asignado a ningún lote (de compras realizadas antes de habilitar el seguimiento por lotes). Asígnalo a lotes iniciales con fechas de caducidad para que pueda venderse con seguimiento por lotes. El stock de la ubicación no se modifica.'],
        'Ord_Guide_Lifecycle_Confirmed' => ['You accepted the order. KcolTech - Murano creates a Sale in the location you picked and deducts the stock — that sale is the record your stock, payment and profit reports read from.', 'Has aceptado el pedido. KcolTech - Murano crea una venta en la ubicación que elegiste y descuenta el stock; esa venta es el registro del que se alimentan tus informes de stock, pagos y beneficios.'],
        'Ord_Guide_Note_Warehouse' => ['The location you pick when confirming decides where the stock is taken from. Locations that cannot cover the whole order are marked Out of Stock in that list.', 'La ubicación que eliges al confirmar decide de dónde se toma el stock. Las ubicaciones que no pueden cubrir todo el pedido aparecen marcadas como Sin stock en esa lista.'],
        'Please_select_warehouse' => ['Please select a location', 'Selecciona una ubicación'],
        'PleaseSelectWarehouse' => ['Please select a location before choosing any product', 'Seleccione una ubicación antes de elegir cualquier producto'],
        'Profit by Warehouse' => ['Profit by Location', 'Beneficio por ubicación'],
        'Sales_by_Month_and_Warehouse' => ['Sales by Month and Location', 'Ventas por Mes y Ubicación'],
        'Search_quotations_ph' => ['Search quotations (Ref, status, location, customer)', 'Buscar cotizaciones (Ref., estado, ubicación, cliente)'],
        'Search_returns_ph' => ['Search returns (Ref, status, payment status, sale ref, location)', 'Buscar devoluciones (Ref., estado, estado de pago, ref. de venta, ubicación)'],
        'Search_sales_ph' => ['Search sales (Ref, status, payment status, location, customer)', 'Buscar ventas (Ref., estado, estado de pago, ubicación, cliente)'],
        'Search_Warehouses' => ['Search locations…', 'Buscar ubicaciones…'],
        'Select_Warehouse' => ['Select Location', 'Seleccionar Ubicación'],
        'SelectWarehouse' => ['Please select a location', 'Por favor seleccione una ubicación'],
        'SendToWarehouse' => ['Send to location', 'Enviar a la ubicación'],
        'Show_Warehouse' => ['Show location', 'Ver ubicación'],
        'Some_warehouses' => ['Some Locations', 'Algunas ubicaciones'],
        'Stock_Valuation_Hint' => ['Valuations are computed for the selected location.', 'Las valoraciones se calculan para la ubicación seleccionada.'],
        'ToWarehouse' => ['To Location', 'A la ubicación'],
        'Value_By_Warehouse' => ['Asset value by location', 'Valor de los activos por ubicación'],
        'warehouse' => ['Location', 'Ubicación'],
        'Warehouse_Access' => ['Location Access', 'Acceso a ubicaciones'],
        // Rack / shelf / bin positions inside a location.
        'Warehouse_Location' => ['Sub-Location', 'Sub-ubicación'],
        'Warehouse_Location_Optional_Hint' => ['Sub-Location (Optional): This field helps you identify the physical position of the product inside the location (Rack / Shelf / Zone / Bin). It’s for reference and faster picking during inventory checks. Stock is still tracked by location, not by sub-location.', 'Sub-ubicación (opcional): este campo ayuda a identificar la posición física del producto dentro de la ubicación (estante / repisa / zona / contenedor). Es solo de referencia y para localizarlo más rápido durante los inventarios. El stock se sigue controlando por ubicación, no por sub-ubicación.'],
        'Warehouse_Locations' => ['Sub-Locations', 'Sub-ubicaciones'],
        'Location_Name' => ['Sub-Location Name', 'Nombre de la sub-ubicación'],
        'Enter_Location_Name' => ['Enter Sub-Location Name', 'Ingrese el nombre de la sub-ubicación'],
        'Rack_Location_Code' => ['Sub-Location Code (Rack/Shelf)', 'Código de sub-ubicación (estante/repisa)'],
        'Enter_Rack_Location_Code' => ['Enter Sub-Location Code', 'Ingrese el código de la sub-ubicación'],
        'Internal_Location_Rack_Shelf' => ['Sub-Location (Rack/Shelf)', 'Sub-ubicación (estante/repisa)'],
        'Internal_Location_Report' => ['Sub-Locations Report', 'Informe de sub-ubicaciones'],
        'Warehouse_Locations_Tab_Help' => ['Define rack / shelf sub-locations inside each location (e.g. Aisle 2 – Rack B). Assign them to products to show where items are physically stored.', 'Defina sub-ubicaciones (estanterías) dentro de cada ubicación (p. ej. Pasillo 2 – Estante B). Asígnelas a los productos para indicar dónde se guardan físicamente los artículos.'],
        'Warehouse_report' => ['Locations report', 'Informe de ubicaciones'],
        'Warehouse_Stock' => ['Location Stock', 'Stock de la Ubicación'],
        'Warehouse_Variants_Stock' => ['Location Variants Stock', 'Stock de Variantes de la Ubicación'],
        'WarehouseIdentical' => ['The two locations cannot be identical !!', 'Las ubicaciones no pueden ser iguales'],
        'Warehouses' => ['Locations', 'Ubicaciones'],
        'Warehouses_Affected' => ['locations affected', 'ubicaciones afectadas'],
        'Warehouses_By_Profit' => ['Locations by profit', 'Ubicaciones por beneficio'],
        'Warehouses_Tab_Help' => ['Manage your locations (main office, stores, branches, warehouses). Each location holds its own stock, and every document — sales, purchases, transfers, adjustments — is recorded against one of them.', 'Gestione sus ubicaciones (oficina principal, tiendas, sucursales, almacenes). Cada ubicación tiene su propio stock, y cada documento — ventas, compras, transferencias, ajustes — se registra en una de ellas.'],
        'WarehouseStockChart' => ['Location Stock Chart', 'Gráfico de stock por ubicación'],
        'Write_Off_Confirm' => ['This will zero-out batch {batch} ({product}) and decrement location stock by {qty}. Continue?', 'Esto pondrá a cero el lote {batch} ({product}) y reducirá el stock de la ubicación en {qty}. ¿Continuar?'],
    ];

    public function up(): void
    {
        $backup = [];
        foreach (self::RENAME as $key => [$en, $es]) {
            // Exact (binary) key match: the table's collation is case-insensitive
            // and 'warehouse' / 'Warehouses' are different keys.
            $row = DB::table('translations')->whereRaw('BINARY `key` = ?', [$key])->first(['en', 'es']);
            if (! $row) {
                continue;
            }
            $backup[$key] = ['en' => $row->en, 'es' => $row->es];
            DB::table('translations')->whereRaw('BINARY `key` = ?', [$key])->update(['en' => $en, 'es' => $es, 'updated_at' => now()]);
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
        return storage_path('app/migration_backups/2026_09_30_180000_warehouse_rename.json');
    }

    public function down(): void
    {
        $backup = is_file(self::backupPath()) ? (json_decode((string) file_get_contents(self::backupPath()), true) ?: []) : [];
        foreach ($backup as $key => $vals) {
            DB::table('translations')->whereRaw('BINARY `key` = ?', [$key])->update(['en' => $vals['en'], 'es' => $vals['es'], 'updated_at' => now()]);
        }

        if (function_exists('forget_db_translations')) {
            forget_db_translations();
        }
    }
};
