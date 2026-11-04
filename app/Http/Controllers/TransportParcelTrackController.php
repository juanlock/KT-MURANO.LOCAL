<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

/**
 * The customer's view of a parcel (2026-11-04), from the QR on its guide: where it is, without names, phones or prices.
 * The random token is the only access.
 */
class TransportParcelTrackController extends Controller
{
    public function show(string $token)
    {
        $parcel = DB::table('transport_parcels')->where('tracking_token', $token)->whereNull('deleted_at')->first();
        abort_unless($parcel, 404);
        $places = DB::table('transport_locations')->whereIn('id', [(int) $parcel->from_location_id, (int) $parcel->to_location_id])->pluck('name', 'id');
        $when = DB::table('transport_parcel_events')->where('parcel_id', $parcel->id)->orderBy('id')->pluck('created_at', 'status');
        $format = fn ($at) => $at ? substr((string) $at, 0, 16) : null;
        $order = ['received', 'on_board', 'delivered'];
        $reached = array_search($parcel->status, $order, true);
        $at = fn (string $status) => $format($when[$status] ?? null) ?: (($reached !== false && array_search($status, $order, true) <= $reached) ? '✓' : null);

        return view('transport.parcel_track', [
            'parcel' => $parcel,
            'company' => DB::table('companies')->where('id', $parcel->company_id)->value('name'),
            'from' => $places[$parcel->from_location_id] ?? '',
            'to' => $places[$parcel->to_location_id] ?? '',
            'eta' => $parcel->eta_from ? substr((string) $parcel->eta_from, 8, 2).'/'.substr((string) $parcel->eta_from, 5, 2).' al '.substr((string) $parcel->eta_to, 8, 2).'/'.substr((string) $parcel->eta_to, 5, 2) : null,
            'steps' => [
                ['label' => 'Recibida en la agencia', 'at' => $at('received')],
                ['label' => 'A bordo del bus', 'at' => $at('on_board')],
                ['label' => 'Entregada', 'at' => $parcel->delivered_at ? $format($parcel->delivered_at) : null],
            ],
        ]);
    }
}
