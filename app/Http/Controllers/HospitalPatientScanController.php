<?php

namespace App\Http\Controllers;

use App\Models\Patient;
use App\Support\CompanyFiles;
use App\Support\HospitalShifts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Intervention\Image\Facades\Image;

/**
 * Patient scans (2026-10-26): the insurance card and the ID document, front and back,
 * scanned or photographed at the arrival (clinic check-in, hospital admission window).
 * Never a payment card (only its type and last 4 digits are kept). Private files of the
 * company (files/company_{id}/patient-scans/, no direct URL): shown through a signed
 * link that lasts a few minutes, made only for a user with the hospital permissions.
 */
class HospitalPatientScanController extends Controller
{
    public const KINDS = ['insurance_front', 'insurance_back', 'id_front', 'id_back'];

    private const MODULE = 'patient-scans';

    private const UPLOAD = ['hms_reception', 'hms_admission_desk', 'hms_admissions', 'hms_patients_add', 'hms_patients_edit'];

    private const VIEW = ['hms_reception', 'hms_admission_desk', 'hms_admissions', 'hms_patients_view', 'hms_billing', 'hms_nursing_station', 'hms_specialist'];

    public function index(Request $request, $patientId)
    {
        abort_unless(HospitalShifts::can($request->user('api'), ...self::VIEW), 403);
        $patient = Patient::findOrFail($patientId); // company-scoped model

        return response()->json(['scans' => $this->rows((int) $patient->id)]);
    }

    public function store(Request $request)
    {
        abort_unless(HospitalShifts::can($request->user('api'), ...self::UPLOAD), 403);
        $request->validate([
            'patient_id' => ['required', \App\Support\CompanyExists::rule('patients')],
            'kind' => 'required|in:'.implode(',', self::KINDS),
            'file' => 'required|file|max:15360|mimes:jpg,jpeg,png,webp,heic,heif,pdf',
            'appointment_id' => 'nullable|integer', 'er_case_id' => 'nullable|integer', 'admission_id' => 'nullable|integer',
        ]);
        $patient = Patient::findOrFail($request->patient_id);
        $cid = (int) $patient->company_id;
        $file = $request->file('file');
        $mime = (string) $file->getMimeType();

        // Photos from a phone are big: at most 2000 px, JPEG. A PDF (scanner) stays as it is.
        if (str_starts_with($mime, 'image/') && ! in_array($mime, ['image/heic', 'image/heif'], true)) {
            $img = Image::make($file->getRealPath())->orientate();
            $img->resize(2000, 2000, function ($c) {
                $c->aspectRatio();
                $c->upsize();
            });
            $path = CompanyFiles::putBytes($cid, self::MODULE, pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME).'.jpg', (string) $img->encode('jpg', 85), true);
            $mime = 'image/jpeg';
        } else {
            $path = CompanyFiles::putUpload($cid, self::MODULE, $file, true);
        }

        $id = DB::transaction(function () use ($request, $patient, $cid, $path, $file, $mime) {
            // One current scan per kind: the previous one of that side is replaced (kept as history, soft-deleted).
            DB::table('hms_patient_scans')->where('patient_id', $patient->id)->where('kind', $request->kind)->whereNull('deleted_at')
                ->update(['deleted_at' => now(), 'updated_at' => now()]);

            return DB::table('hms_patient_scans')->insertGetId([
                'company_id' => $cid, 'patient_id' => $patient->id, 'kind' => $request->kind, 'path' => $path,
                'original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 191), 'mime' => $mime,
                'size' => is_file(public_path($path)) ? filesize(public_path($path)) : null,
                'appointment_id' => $request->appointment_id ?: null, 'er_case_id' => $request->er_case_id ?: null,
                'admission_id' => $request->admission_id ?: null, 'uploaded_by' => optional($request->user('api'))->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return response()->json(['success' => true, 'id' => $id, 'scans' => $this->rows((int) $patient->id)]);
    }

    public function destroy(Request $request, $id)
    {
        abort_unless(HospitalShifts::can($request->user('api'), ...self::UPLOAD), 403);
        $row = DB::table('hms_patient_scans')->where('id', $id)->whereNull('deleted_at')->first();
        abort_unless($row, 404);
        Patient::findOrFail($row->patient_id); // same company
        DB::table('hms_patient_scans')->where('id', $id)->update(['deleted_at' => now(), 'updated_at' => now()]);

        return response()->json(['success' => true, 'scans' => $this->rows((int) $row->patient_id)]);
    }

    /** GET /hospital-scan/{id} (web, signed, a few minutes): the file itself. */
    public function file(Request $request, $id)
    {
        $row = DB::table('hms_patient_scans')->where('id', $id)->whereNull('deleted_at')->first();
        abort_unless($row && CompanyFiles::owns($row->path, (int) $row->company_id, self::MODULE), 404);

        return response()->file(public_path($row->path), [
            'Content-Type' => $row->mime ?: 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function rows(int $patientId): array
    {
        return DB::table('hms_patient_scans')->where('patient_id', $patientId)->whereNull('deleted_at')->orderBy('kind')->get()
            ->map(fn ($r) => [
                'id' => $r->id, 'kind' => $r->kind, 'mime' => $r->mime, 'name' => $r->original_name, 'created_at' => $r->created_at,
                // Signed, 10 minutes: <img src> and "open" work without exposing the file.
                'url' => URL::temporarySignedRoute('hospital.scan', now()->addMinutes(10), ['id' => $r->id], false),
            ])->values()->all();
    }
}
