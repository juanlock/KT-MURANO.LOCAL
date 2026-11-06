<?php

use App\Support\TransportDemoDay;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Turismo Cautivo's fares and distances from redBus (user's rule, 2026-11-04: the highest published
 * value of each route as its fare), with distance and duration. An upper limit, not a real average:
 * replace with the company's own fares. Trujillo -> Lima isn't published: taken as Lima ->
 * Trujillo's. The short stretches have no maximum published and keep the demo's fares. The demo's
 * ticket products and sales follow (rows tagged as demo). Safe to run twice.
 */
return new class extends Migration
{
    /** origin, destination, fare, km, minutes (null = not published, kept). */
    private const ROUTES = [
        ['LIMA', 'CHICLAYO', 110, 771.7, 780],  // premium, schedules page
        ['CHICLAYO', 'LIMA', 120, 768.4, 780],  // the top of the range the user gave
        ['LIMA', 'CHEPEN', 90, 698.2, 720],     // premium, schedules page
        ['CHEPEN', 'LIMA', 120, null, null],    // the top of the range the user gave
        ['LIMA', 'TRUJILLO', 90, 557.4, 540],   // premium, schedules page
        ['TRUJILLO', 'LIMA', 90, null, null],   // not published: as Lima -> Trujillo
    ];

    public function up(): void
    {
        $cid = (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
        if (! $cid) {
            return;
        }
        $demo = fn (string $type) => DB::table('demo_records')->where('company_id', $cid)->where('record_type', $type)->pluck('record_id')->all();
        $ids = DB::table('transport_locations')->where('company_id', $cid)->whereNull('deleted_at')->where('kind', 'agency')->pluck('id', 'name');
        $demoProducts = $demo('products');
        $demoSales = $demo('sales');

        DB::transaction(function () use ($cid, $ids, $demoProducts, $demoSales, $demo) {
            $changed = [];
            foreach (self::ROUTES as [$o, $d, $fare, $km, $minutes]) {
                if (! isset($ids[$o], $ids[$d])) {
                    continue;
                }
                $route = DB::table('transport_routes')->where('company_id', $cid)->where('origin_id', $ids[$o])->where('destination_id', $ids[$d])->whereNull('deleted_at')->value('id');
                if (! $route) {
                    continue;
                }
                DB::table('transport_routes')->where('id', $route)->update(array_filter(['distance_km' => $km, 'duration_min' => $minutes, 'updated_at' => now()], fn ($v) => $v !== null));
                $n = DB::table('products')->whereIn('id', $demoProducts)->where('code', 'PAS-'.$route)->update(['price' => $fare, 'wholesale_price' => $fare, 'min_price' => round($fare * 0.8, 2), 'updated_at' => now()]);
                if ($n) {
                    $changed[] = (int) DB::table('products')->whereIn('id', $demoProducts)->where('code', 'PAS-'.$route)->value('id');
                }
            }
            if (! $changed) {
                return;
            }
            // The demo's sales of those tickets, at the new fare.
            foreach (array_chunk($demoSales, 500) as $chunk) {
                DB::table('sale_details as d')->join('products as p', 'p.id', '=', 'd.product_id')->whereIn('d.sale_id', $chunk)->whereIn('d.product_id', $changed)
                    ->update(['d.price' => DB::raw('p.price'), 'd.total' => DB::raw('ROUND(d.quantity * p.price, 2)')]);
                DB::statement('UPDATE sales s JOIN (SELECT sale_id, SUM(total) t FROM sale_details WHERE sale_id IN ('.implode(',', array_map('intval', $chunk)).') GROUP BY sale_id) x ON x.sale_id = s.id
                    SET s.GrandTotal = x.t, s.paid_amount = x.t');
                DB::statement('UPDATE payment_sales ps JOIN sales s ON s.id = ps.sale_id SET ps.montant = s.GrandTotal WHERE ps.sale_id IN ('.implode(',', array_map('intval', $chunk)).')');
            }
            // Closed demo registers: their totals again.
            foreach (DB::table('cash_registers')->whereIn('id', $demo('cash_registers'))->where('status', 'closed')->get(['id', 'opening_balance']) as $r) {
                $cash = (float) DB::table('payment_sales')->join('sales', 'sales.id', '=', 'payment_sales.sale_id')->where('sales.cash_register_id', $r->id)->where('payment_sales.payment_method_id', 2)->sum('payment_sales.montant');
                DB::table('cash_registers')->where('id', $r->id)->update(['total_sales' => (float) DB::table('sales')->where('cash_register_id', $r->id)->sum('GrandTotal'), 'closing_balance' => (float) $r->opening_balance + $cash]);
            }
            // Fuel again, at the published distances.
            DB::table('transport_trips')->whereIn('id', $demo('transport_trips'))->update(['fuel_cost' => null]);
        });
        echo '  Turismo demo: '.json_encode(TransportDemoDay::profitData($cid, true))."\n";
    }

    public function down(): void
    {
    }
};
