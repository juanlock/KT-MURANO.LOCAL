<?php

namespace App\Services;

use App\Http\Controllers\SalesController;
use App\Models\Sale;
use App\Models\SaleDetail;
use Illuminate\Support\Facades\DB;

/**
 * A last-minute passenger sold at a stop ("Mi parada", 2026-11-18): one line of the stretch's ticket product
 * (PAS-{route}, PAS-{route}-VIP for a VIP seat) on the agent's open cash register, paid at once, as a POS sale so the
 * register's close counts it. Called inside the ticket's transaction (TransportStopController::sell).
 */
class TransportTicketSale
{
    public static function create(int $companyId, int $warehouseId, int $userId, object $product, float $amount, ?int $methodId, string $note): Sale
    {
        $register = DB::table('cash_registers')->where('user_id', $userId)->where('warehouse_id', $warehouseId)->where('status', 'open')->orderByDesc('id')->value('id');
        // No sale without the agent's open cash register (user's rule, 2026-11-18): every sol must reach the register close.
        if (! $register) {
            throw \Illuminate\Validation\ValidationException::withMessages(['register' => __('Open your cash register on the POS before selling.')]);
        }
        $sale = new Sale;
        $sale->forceFill([
            'Ref' => app(SalesController::class)->getNumberOrder($warehouseId, $companyId), 'is_pos' => 1, 'company_id' => $companyId, 'warehouse_id' => $warehouseId, 'user_id' => $userId,
            'tax_method' => '1', 'discount_Method' => '2', 'shipping' => 0, 'paid_amount' => 0, 'payment_statut' => 'unpaid', 'date' => now()->toDateString(), 'time' => now()->toTimeString(),
            'client_id' => (int) DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->value('client_id'), 'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0,
            'GrandTotal' => $amount, 'statut' => 'completed', 'notes' => $note, 'cash_register_id' => $register,
        ])->save();
        SaleDetail::create([
            'date' => $sale->date, 'sale_id' => $sale->id, 'product_id' => $product->id, 'product_variant_id' => null, 'sale_unit_id' => $product->unit_sale_id ?? null,
            'quantity' => 1, 'price' => $amount, 'TaxNet' => 0, 'tax_method' => '1', 'discount' => 0, 'discount_method' => '2', 'total' => $amount,
            'price_type' => 'retail', 'pack_multiplier' => 1, 'note' => $note,
        ]);
        if ($amount > 0) {
            TransportParcelSale::pay($sale, $userId, $methodId);
        }

        return $sale;
    }
}
