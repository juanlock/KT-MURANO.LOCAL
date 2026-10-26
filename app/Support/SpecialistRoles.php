<?php

namespace App\Support;

/**
 * The roles of a specialist clinic (Health Specialist, 2026-10-26), applied
 * with HospitalRoles::apply($companyId, SpecialistRoles::ROLES): the old roles
 * removed, Administrador / Paciente (cliente) / Proveedor / Personal sin rol /
 * Helpdesk / Info kept, and the clinic's own:
 *   Recepción          schedule (drag & drop), check-in and payment, follow-ups, cash
 *   Enfermería         the nursing station: vital signs -> "ready to go in"
 *   Médico especialista his day, the consultation (EMR by specialty), orders
 *   Caja / Facturación bills and payments
 */
class SpecialistRoles
{
    /** name => [label, description, home page, permissions] */
    public const ROLES = [
        'Reception' => ['Recepción', 'Reception: the schedule (drag & drop), check-in with payment or insurance, follow-ups and referrals to schedule, patients.', '/hospital/agenda', [
            'hms_reception', 'hms_appointments', 'hms_patients_view', 'hms_patients_add', 'hms_patients_edit', 'hms_billing', 'hms_doctors',
        ]],
        'Clinic Nurse' => ['Enfermería', 'Nurse / medical assistant: the nursing station (vital signs) that tells the doctor the patient is ready.', '/hospital/nursing', [
            'hms_nursing_station', 'hms_patients_view',
        ]],
        'Specialist' => ['Médico especialista', 'Specialist doctor: the day, the consultation (EMR by specialty, prescriptions, lab / imaging orders, referrals, follow-up).', '/hospital/specialist', [
            'hms_specialist', 'hms_patients_view', 'hms_patients_edit', 'hms_visits', 'hms_lab', 'hms_appointments',
        ]],
        'Billing' => ['Caja / Facturación', 'Cashier: the consultation bills (sales) and payments.', '/hospital/invoices', [
            'hms_billing', 'hms_patients_view', 'Sales_view', 'Customers_view',
        ]],
    ];
}
