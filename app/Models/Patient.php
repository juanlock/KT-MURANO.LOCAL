<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Patient extends Model
{
    use SoftDeletes;

    protected $table = 'patients';

    protected $fillable = [
        'mrn', 'name', 'gender', 'date_of_birth', 'phone', 'email', 'address', 'city',
        'national_id', 'blood_group', 'allergies', 'chronic_conditions',
        'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relation',
        'insurance_provider', 'insurance_number', 'insurance_expiry',
        'client_id', 'image', 'notes', 'status',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'insurance_expiry' => 'date',
        'client_id' => 'integer',
    ];

    public function visits()
    {
        return $this->hasMany(PatientVisit::class, 'patient_id', 'id');
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class, 'patient_id', 'id');
    }

    public function admissions()
    {
        return $this->hasMany(Admission::class, 'patient_id', 'id');
    }

    public function labOrders()
    {
        return $this->hasMany(LabOrder::class, 'patient_id', 'id');
    }

    public function invoices()
    {
        return $this->hasMany(HospitalInvoice::class, 'patient_id', 'id');
    }

    public function prescriptions()
    {
        return $this->hasMany(Prescription::class, 'patient_id', 'id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id', 'id');
    }

    /** Null when no date of birth is on file, rather than a misleading 0. */
    public function getAgeAttribute()
    {
        return $this->date_of_birth ? Carbon::parse($this->date_of_birth)->age : null;
    }

    /**
     * Sequential medical record number, MRN-000001 upward. Not date-based: an
     * MRN follows a person for life, so it must not encode a registration day.
     */
    public static function generateMrn()
    {
        $last = static::withTrashed()->where('mrn', 'like', 'MRN-%')->orderBy('id', 'desc')->first();
        $seq = $last ? ((int) substr($last->mrn, 4)) + 1 : 1;

        return 'MRN-' . str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }
}
