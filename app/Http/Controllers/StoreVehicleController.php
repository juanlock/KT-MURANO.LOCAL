<?php

namespace App\Http\Controllers;

use App\Models\CustomerVehicle;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\FitmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Storefront vehicle selection + "My Garage".
 *
 * The active vehicle lives in the session (works for guests); logged-in
 * customers can additionally persist vehicles to their garage. All endpoints
 * 404 when the Vehicle Fitment feature is disabled.
 */
class StoreVehicleController extends Controller
{
    public function __construct(private FitmentService $fitment)
    {
    }

    private function assertEnabled(): void
    {
        abort_unless($this->fitment->enabled(), 404);
    }

    private function clientId(): ?int
    {
        $user = Auth::guard('store')->user();

        return $user && $user->client_id ? (int) $user->client_id : null;
    }

    /** GET /vehicle/options — catalog + current selection + garage. */
    public function options(Request $request)
    {
        $this->assertEnabled();

        $garage = [];
        if ($clientId = $this->clientId()) {
            $garage = CustomerVehicle::with(['make:id,name', 'model:id,name'])
                ->where('client_id', $clientId)
                ->orderByDesc('is_default')->orderBy('id')
                ->get()
                ->map(fn ($v) => [
                    'id' => $v->id,
                    'make_id' => $v->vehicle_make_id,
                    'model_id' => $v->vehicle_model_id,
                    'year' => $v->year,
                    'engine' => $v->engine,
                    'nickname' => $v->nickname,
                    'is_default' => $v->is_default,
                    'label' => $v->label(),
                ])->values();
        }

        return response()->json([
            'makes' => VehicleMake::where('active', true)->orderBy('name')->get(['id', 'name']),
            'models' => VehicleModel::where('active', true)->orderBy('name')
                ->get(['id', 'vehicle_make_id', 'name']),
            'current' => $this->fitment->currentVehicle(),
            'garage' => $garage,
            'logged_in' => $this->clientId() !== null,
        ]);
    }

    /** POST /vehicle — select a vehicle (optionally saving it to the garage). */
    public function select(Request $request)
    {
        $this->assertEnabled();

        $vehicle = $this->fitment->normalizeVehicle($request->all());
        if (! $vehicle) {
            return response()->json(['error' => __('Please choose a make and model.')], 422);
        }

        session([FitmentService::SESSION_KEY => $vehicle]);

        // Logged-in customers keep it in their garage (deduped).
        if (($clientId = $this->clientId()) && $request->boolean('save', true)) {
            $existing = CustomerVehicle::where('client_id', $clientId)
                ->where('vehicle_make_id', $vehicle['make_id'])
                ->where('vehicle_model_id', $vehicle['model_id'])
                ->where(fn ($q) => $vehicle['year'] ? $q->where('year', $vehicle['year']) : $q->whereNull('year'))
                ->first();

            if (! $existing) {
                CustomerVehicle::create([
                    'client_id' => $clientId,
                    'vehicle_make_id' => $vehicle['make_id'],
                    'vehicle_model_id' => $vehicle['model_id'],
                    'year' => $vehicle['year'],
                    'engine' => $vehicle['engine'],
                    'is_default' => ! CustomerVehicle::where('client_id', $clientId)->exists(),
                ]);
            }
        }

        return response()->json(['ok' => true, 'vehicle' => $vehicle]);
    }

    /** POST /vehicle/clear — browse without a vehicle again. */
    public function clear(Request $request)
    {
        $this->assertEnabled();
        session()->forget(FitmentService::SESSION_KEY);

        return response()->json(['ok' => true]);
    }

    /** POST /garage/{id}/select — activate a saved garage vehicle. */
    public function selectGarage(Request $request, $id)
    {
        $this->assertEnabled();
        $clientId = $this->clientId();
        abort_unless($clientId, 401);

        $v = CustomerVehicle::where('client_id', $clientId)->findOrFail($id);
        $vehicle = $this->fitment->normalizeVehicle([
            'make_id' => $v->vehicle_make_id,
            'model_id' => $v->vehicle_model_id,
            'year' => $v->year,
            'engine' => $v->engine,
        ]);
        if (! $vehicle) {
            return response()->json(['error' => __('This vehicle is no longer available.')], 422);
        }

        CustomerVehicle::where('client_id', $clientId)->update(['is_default' => false]);
        $v->update(['is_default' => true]);
        session([FitmentService::SESSION_KEY => $vehicle]);

        return response()->json(['ok' => true, 'vehicle' => $vehicle]);
    }

    /** DELETE /garage/{id} — remove a saved vehicle. */
    public function destroyGarage(Request $request, $id)
    {
        $this->assertEnabled();
        $clientId = $this->clientId();
        abort_unless($clientId, 401);

        CustomerVehicle::where('client_id', $clientId)->findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }
}
