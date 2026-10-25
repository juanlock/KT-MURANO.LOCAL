<?php

namespace App\Http\Controllers;

use App\Models\ErCase;
use App\Models\HospitalBed;
use App\Support\HospitalShifts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Triage (user's rules, 2026-10-25): after the admission window registered the
 * patient and the money, the triage nurse calls them to the office, takes the
 * vital signs, weight and height, describes why they came (plus allergies,
 * chronic illnesses and usual medication), sets the level 1-5 and sends them
 * to an ER bed of the shift. Critical patients already in an ER bed are
 * triaged there, after being seen. Permission hms_triage.
 *
 *  GET  hospital/triage                 the queue (waiting / called) + in a bed without triage + done today + free ER beds
 *  POST hospital/triage/{id}/call       "come to the triage office"
 *  POST hospital/triage/{id}            the triage (vitals, weight, height, level, notes) [+ bed_id]
 */
class HospitalTriageController extends Controller
{
    private function can(Request $r): void
    {
        abort_unless(HospitalShifts::can($r->user('api'), 'hms_triage'), 403, 'Only triage can do this.');
    }

    public function index(Request $request)
    {
        $this->can($request);
        $open = ErCase::with('patient', 'bed')->whereNull('deleted_at')->where('status', '!=', 'closed')
            ->whereIn('pathway', ['emergency', 'critical'])->whereIn('triage_status', ['pending', 'called'])->orderBy('arrived_at')->get();
        $done = ErCase::with('patient', 'bed')->whereNull('deleted_at')->where('triage_status', 'done')->where('triaged_at', '>=', now()->startOfDay())
            ->orderByDesc('triaged_at')->limit(30)->get();
        $beds = HospitalBed::with('ward')->whereNull('deleted_at')->where('status', 'available')
            ->whereHas('ward', fn ($w) => $w->whereNull('deleted_at')->where('is_active', 1)->where('type', 'emergency'))->orderBy('bed_number')->get();

        return response()->json([
            'queue' => $open->map(fn ($c) => $this->present($c))->values(),
            'done_today' => $done->map(fn ($c) => $this->present($c))->values(),
            'er_beds' => $beds->map(fn ($b) => ['id' => $b->id, 'label' => $b->bed_number.' · '.optional($b->ward)->name])->values(),
        ]);
    }

    public function call(Request $request, $id)
    {
        $this->can($request);
        $c = ErCase::whereNull('deleted_at')->where('status', '!=', 'closed')->findOrFail($id);
        $c->update(['triage_status' => 'called', 'triage_called_at' => now()]);

        return response()->json(['success' => true]);
    }

    public function save(Request $request, $id)
    {
        $this->can($request);
        $v = $request->validate([
            'triage_level' => 'required|integer|between:1,5',
            'complaint' => 'required|string|max:2000',
            'temperature' => 'nullable|numeric|between:25,45', 'pulse' => 'nullable|integer|between:0,300',
            'bp_systolic' => 'nullable|integer|between:0,300', 'bp_diastolic' => 'nullable|integer|between:0,250',
            'respiratory_rate' => 'nullable|integer|between:0,80', 'spo2' => 'nullable|integer|between:0,100',
            'pain_score' => 'nullable|integer|between:0,10',
            'weight_kg' => 'nullable|numeric|between:0.3,400', 'height_cm' => 'nullable|numeric|between:20,250',
            'triage_notes' => 'nullable|string|max:4000', 'usual_medication' => 'nullable|string|max:500',
            'allergies' => 'nullable|string|max:500', 'chronic_conditions' => 'nullable|string|max:500',
            'bed_id' => ['nullable', \App\Support\CompanyExists::rule('hospital_beds')],
        ]);
        $c = ErCase::whereNull('deleted_at')->where('status', '!=', 'closed')->findOrFail($id);
        try {
            DB::transaction(function () use ($v, $c, $request) {
                $c->update(collect($v)->except(['allergies', 'chronic_conditions', 'bed_id'])->all() + [
                    'triage_status' => 'done', 'triaged_at' => now(), 'triaged_by' => $request->user('api')->id,
                ]);
                // What the patient told the nurse stays on the patient.
                $p = array_filter(['allergies' => $v['allergies'] ?? null, 'chronic_conditions' => $v['chronic_conditions'] ?? null], fn ($x) => $x !== null && $x !== '');
                if ($p) {
                    DB::table('patients')->where('id', $c->patient_id)->update($p + ['updated_at' => now()]);
                }
                // To an ER bed of the shift (a patient already in a bed stays there).
                if (! empty($v['bed_id']) && ! $c->bed_id) {
                    $bed = HospitalBed::with('ward')->whereNull('deleted_at')->lockForUpdate()->find($v['bed_id']);
                    if (! $bed || $bed->status !== 'available' || optional($bed->ward)->type !== 'emergency') {
                        throw new \RuntimeException('That bed has just been taken. Pick another one.');
                    }
                    $c->update(['bed_id' => $bed->id, 'location' => 'er_bed']);
                    $bed->update(['status' => 'occupied']);
                }
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true]);
    }

    private function present(ErCase $c): array
    {
        $p = $c->patient;

        return [
            'id' => $c->id, 'reference' => $c->reference, 'patient_id' => $c->patient_id,
            'name' => optional($p)->name, 'mrn' => optional($p)->mrn, 'gender' => optional($p)->gender,
            'age' => optional($p)->date_of_birth ? \Carbon\Carbon::parse($p->date_of_birth)->age : null,
            'allergies' => optional($p)->allergies, 'chronic_conditions' => optional($p)->chronic_conditions,
            'pathway' => $c->pathway, 'triage_status' => $c->triage_status, 'triage_level' => $c->triage_level,
            'complaint' => $c->complaint, 'arrived_at' => optional($c->arrived_at)->toIso8601String(),
            'wait_minutes' => $c->arrived_at ? (int) $c->arrived_at->diffInMinutes(now()) : null,
            'called_at' => optional($c->triage_called_at)->toIso8601String(), 'triaged_at' => optional($c->triaged_at)->toIso8601String(),
            'bed' => $c->bed_id ? optional($c->bed)->bed_number : null,
            'payer_type' => $c->payer_type, 'insurer' => $c->insurer, 'card_last4' => $c->card_last4,
            'registration_pending' => $c->registration_status === 'pending',
            'vitals' => $c->only(['temperature', 'pulse', 'bp_systolic', 'bp_diastolic', 'respiratory_rate', 'spo2', 'pain_score', 'weight_kg', 'height_cm']),
            'triage_notes' => $c->triage_notes, 'usual_medication' => $c->usual_medication,
        ];
    }
}
