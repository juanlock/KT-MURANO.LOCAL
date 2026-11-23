<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * HORDEX product prices = the last real price of the earlier system (user, 2026-11-23: "el cliente dice que los
 * productos precio del antiguo sistema y el nuevo son diferentes" -> "actualízalo con la fecha del sistema anterior";
 * "la ficha ... trae precios mal tipeados en algunos insumos: arréglalo"). Its invoice screen proposed
 * Producto::ultimoPrecio() -- a final product's unit price on its latest invoice, an input's (insumo) latest purchase
 * price -- not the product sheet, which was years old and had typos (EMPBASCH 988,140 for 0.99; BTCH 150 for 0.15;
 * ENVBMX50 65 for 0.04). Values read from c2111853_hordex on 2026-11-23: [code, sheet price, last price, its date].
 * A final product: price. An input (not sold, not_selling): price and cost. Only where the price is still the old sheet
 * value -- a price already changed in Murano is never overwritten. By company name and code. Safe to run twice.
 */
return new class extends Migration
{
    private const PRICES = [
        ['7071', 11.74, 13.14, '2026-10-29'], // BASURERO CHICO (final)
        ['7076', 21, 25, '2026-10-29'], // BASURERO CHICO CON TAPA (final)
        ['B3CCT', 6.65, 1.75, '2024-09-10'], // BASURERO CHICO CON TAPA (S/T) (insumo)
        ['B3CH', 6.04, 1.59, '2024-09-10'], // BASURERO CHICO S/E (insumo)
        ['7073', 14.56, 16.31, '2026-10-29'], // BASURERO MEDIANO (final)
        ['7077', 23.6, 27.97, '2026-10-29'], // BASURERO MEDIANO CON TAPA (final)
        ['B3MCT', 9.35, 2.46, '2024-09-11'], // BASURERO MEDIANO CON TAPA (S/T) (insumo)
        ['B3M', 7.03, 1.85, '2024-09-10'], // BASURERO MEDIANO S/E (insumo)
        ['BIMPCH', 0.19, 0.12, '2022-10-17'], // BOLSA IMPRESA CHICA (insumo)
        ['BIMPMED', 0.18, 0.2, '2024-08-12'], // BOLSA IMPRESA MEDIANA (insumo)
        ['BTCH', 150, 0.15, '2024-08-12'], // BOLSA TRANSPARENTE CHICA (insumo) -- typo in the sheet
        ['1850814', 23.16, 25.94, '2026-01-25'], // CANASTILLA FP 50X15 HORDEX (TMH) (final)
        ['RA-50', 12.54, 3.3, '2024-09-27'], // CANASTILLA GRANDE (insumo)
        ['CAREMB37X55', 1.6, 1.36, '2024-08-22'], // CARTON DE EMBALAJE P/CANASTILLA RA50 (insumo)
        ['CAREMPBCCT25X41', 0.9, 0.76, '2024-08-22'], // CARTON DE EMPAQUE BAS.CH.C/TAPAX6U DE 25X41CMS. (insumo)
        ['EMPBASCH', 988140, 0.99, '2024-08-22'], // EMPAQUE DE BASURERO CHICO 26.5X27"X5MM (insumo) -- typo in the sheet
        ['ENVBMX50', 65, 0.043378, '2024-08-24'], // ENVASE BOLSA MEDIANA POR 50U (insumo) -- typo in the sheet
        ['EC', 9.5, 2.5, '2024-09-27'], // ESCURRIDOR DE CUBIERTO (insumo)
        ['N200', 0, 7.18, '2024-09-27'], // ESCURRIDOR GRANDE (insumo)
        ['ETBIODEG', 0.05, 0.045, '2024-10-25'], // ETIQUETA BIO DEGRADABLE CON FECHA DE PRODUCCION (insumo)
        ['ETIQ.RA-50', 0.2, 0.15, '2024-08-21'], // ETIQUETA DE CANASTILLA (insumo)
        ['ETIQ.P5', 0.25, 0.24, '2024-08-21'], // ETIQUETA DEL ORGANIZADOR (insumo)
        ['EXH2CERR', 0, 8, '2024-02-26'], // EXHIBIDOR TIPO ISLA 2 CERRITOS (insumo)
        ['138478', 7.3, 9.56, '2025-09-25'], // JUEGO DE BOLSAS CHICAS X 50U (final)
        ['138480', 14.75, 18.63, '2025-09-25'], // JUEGO DE BOLSAS GRANDES X 50U (final)
        ['JBMX10', 0.5, 0.353, '2024-10-25'], // JUEGO DE BOLSAS MEDIANAS POR 10U (insumo)
        ['JBMX50', 2.01, 1.755, '2024-10-25'], // JUEGO DE BOLSAS MEDIANAS POR 50U (insumo)
        ['138479', 9.9, 12.96, '2025-09-25'], // JUEGO DE BOLSAS MEDIANAS X 50U (final)
        ['2544997', 10.3, 11.54, '2026-01-25'], // JUEGO DE BOLSAS MEDIANAS X 50U (TMH) (final)
        ['P5', 12.92, 3.4, '2024-09-27'], // ORGANIZADOR DE DUCHA (insumo)
        ['128198', 23.45, 26.5, '2026-01-08'], // ORGANIZADOR DE DUCHA (TMH) (final)
        ['RA-28', 6.65, 1.75, '2024-11-08'], // REPICERO (insumo)
        ['128309', 12.51, 14.01, '2025-12-29'], // REPICERO FP HORDEX (TMH) (final)
        ['REP40', 0, 2, '2023-06-02'], // REPISA DE 100X40CMS. (insumo)
        ['511075', 8.5, 11.13, '2025-09-25'], // ROLLO DE BOLSAS CHICAS X 200U (final)
        ['SOPDEEXHIBI', 0, 8, '2024-03-25'], // SOPORTE DE PLACA DE EXHIBIDOR (insumo)
        ['SOP40CMS', 0, 4, '2023-06-02'], // SOPORTE DE REPISA DE 40CMS (insumo)
        ['TCH', 1.97, 0.95, '2024-05-16'], // TAPA CHICA (insumo)
        ['TMED', 2.64, 0.8, '2024-05-16'], // TAPA MEDIANA (insumo)
    ];

    public function up(): void
    {
        $cid = DB::table('companies')->where('name', 'like', 'HORDEX%')->value('id');
        if (! $cid) {
            return;
        }
        foreach (self::PRICES as [$code, $old, $last]) {
            $p = DB::table('products')->where('company_id', $cid)->where('code', $code)->first(['id', 'price', 'not_selling']);
            if (! $p || abs((float) $p->price - $old) > 0.0001) {
                continue; // gone, or already changed in Murano
            }
            $set = ['price' => $last, 'updated_at' => now()];
            if ((int) $p->not_selling === 1) {
                $set['cost'] = $last; // an input: what it is bought at
            }
            DB::table('products')->where('id', $p->id)->update($set);
        }
    }

    public function down(): void
    {
    }
};
