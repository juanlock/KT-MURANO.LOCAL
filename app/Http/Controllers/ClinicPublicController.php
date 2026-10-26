<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Public pages of the specialist clinic (2026-10-26), no sign-in:
 *  - the reminder's "Confirm my appointment" / "I can't come" (signed link);
 *  - the satisfaction survey (NPS 0-10 + comment) by its token.
 * In the company's language.
 */
class ClinicPublicController extends Controller
{
    public function answer(Request $request, $appointment, $action)
    {
        // Signature checked by the route (signed:relative).
        $a =DB::table('appointments')->whereNull('deleted_at')->where('id', $appointment)->first();
        abort_unless($a, 404);
        [$es, $company] = $this->company((int) $a->company_id);
        $open = in_array($a->status, ['scheduled', 'confirmed'], true) && ! $a->flow_status;
        if ($open && $action === 'confirm') {
            DB::table('appointments')->where('id', $a->id)->update(['status' => 'confirmed', 'confirmed_at' => now(), 'updated_at' => now()]);
            $msg = $es ? '¡Gracias! Su cita quedó confirmada.' : 'Thank you! Your appointment is confirmed.';
        } elseif ($open && $action === 'cancel') {
            DB::table('appointments')->where('id', $a->id)->update(['status' => 'cancelled', 'updated_at' => now()]);
            $msg = $es ? 'Su cita fue cancelada. Si desea otra fecha, comuníquese con nosotros.' : 'Your appointment was cancelled. Contact us for another date.';
        } else {
            $msg = $es ? 'Esta cita ya no se puede cambiar desde aquí.' : 'This appointment can no longer be changed from here.';
        }
        $when = \Carbon\Carbon::parse($a->scheduled_at)->locale($es ? 'es' : 'en')->isoFormat($es ? 'dddd D [de] MMMM, HH:mm' : 'dddd, MMMM D, HH:mm');

        return response()->view('clinic.public', ['company' => $company, 'title' => $company, 'message' => $msg, 'detail' => $a->reference.' · '.$when, 'survey' => null, 'es' => $es]);
    }

    public function survey(Request $request, $token)
    {
        $s = DB::table('hms_surveys')->where('token', $token)->first();
        abort_unless($s, 404);
        [$es, $company] = $this->company((int) $s->company_id);
        $doctor = DB::table('doctors')->where('id', $s->doctor_id)->value('name');

        return response()->view('clinic.public', ['company' => $company, 'title' => $company, 'message' => null, 'detail' => $doctor,
            'survey' => $s, 'es' => $es, 'score' => $request->get('score')]);
    }

    public function answerSurvey(Request $request, $token)
    {
        $request->validate(['score' => 'required|integer|between:0,10', 'comment' => 'nullable|string|max:1000']);
        $s = DB::table('hms_surveys')->where('token', $token)->first();
        abort_unless($s, 404);
        [$es, $company] = $this->company((int) $s->company_id);
        if (! $s->answered_at) {
            DB::table('hms_surveys')->where('id', $s->id)->update(['score' => (int) $request->score, 'comment' => $request->comment,
                'answered_at' => now(), 'updated_at' => now()]);
        }

        return response()->view('clinic.public', ['company' => $company, 'title' => $company, 'survey' => null, 'es' => $es, 'detail' => null,
            'message' => $es ? '¡Gracias por su opinión!' : 'Thank you for your feedback!']);
    }

    private function company(int $companyId): array
    {
        $lang = DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->value('default_language');

        return [str_starts_with((string) $lang, 'es'), (string) (DB::table('companies')->where('id', $companyId)->value('name') ?: 'Clinic')];
    }
}
