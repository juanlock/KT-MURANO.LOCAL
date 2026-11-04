<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The texts of the dashboard's business summary for transport companies
 * (TransportOwnerPanel.vue, 2026-11-04). Safe to run twice.
 */
return new class extends Migration
{
    private const TEXTS = [
        'Owner_Panel' => ['Business summary', 'Resumen del negocio'],
        'Owner_Panel_Period' => ['Today and the month in progress', 'Hoy y el mes en curso'],
        'Owner_By_Agency' => ['By agency', 'Por agencia'],
        'Transport_Agency' => ['Agency', 'Agencia'],
        'Owner_Income_Today' => ['Income today', 'Ingresos de hoy'],
        'Owner_Income_Month' => ['Income this month', 'Ingresos del mes'],
        'Owner_Tickets_Month' => ['Tickets this month', 'Boletos del mes'],
        'Owner_Tickets' => ['tickets', 'boletos'],
        'Owner_Other_Locations' => ['Other locations', 'Otros locales'],
        'Owner_No_Sales' => ['No sales this month', 'Sin ventas este mes'],
        'Owner_Top_Routes' => ['Best-selling routes', 'Rutas más vendidas'],
        'Owner_Fleet_Alerts' => ['Falling due on the buses', 'Vencimientos de los buses'],
        'Owner_No_Alerts' => ['Nothing due in the next 30 days', 'Nada vence en los próximos 30 días'],
        'Owner_Next_30' => ['overdue or within 30 days', 'vencidos o en 30 días'],
        'Owner_Trips_Today' => ['Trips today', 'Viajes de hoy'],
        'Owner_Done' => ['done', 'terminados'],
        'Owner_On_Road' => ['on the road', 'en ruta'],
        'Owner_To_Leave' => ['to leave', 'por salir'],
        'Owner_Fleet_Costs' => ['Fleet costs this month', 'Costos de flota del mes'],
        'Owner_Fuel' => ['Fuel', 'Combustible'],
        'Owner_Maintenance' => ['Maintenance', 'Mantenimiento'],
        'Owner_Alert_Insurance' => ['Insurance', 'Seguro'],
        'Owner_Alert_Registration' => ['Registration', 'Tarjeta de propiedad'],
        'Owner_Alert_Inspection' => ['Inspection', 'Revisión técnica'],
        'Owner_Alert_Service' => ['Next service', 'Próximo servicio'],
        'Owner_Overdue' => ['overdue', 'vencido hace'],
        'Owner_In' => ['in', 'en'],
    ];

    public function up(): void
    {
        foreach (self::TEXTS as $key => [$en, $es]) {
            TranslationKeys::ensure($key, ['es' => $es]);
            foreach (['en' => $en, 'es' => $es] as $col => $text) {
                DB::table('translations')->whereRaw('`key` = ? COLLATE utf8mb4_bin', [$key])
                    ->where(fn ($q) => $q->whereNull($col)->orWhere($col, '')->orWhere($col, $key))
                    ->update([$col => $text, 'updated_at' => now()]);
            }
        }
        if (function_exists('forget_db_translations')) {
            forget_db_translations();
        }
    }

    public function down(): void
    {
    }
};
