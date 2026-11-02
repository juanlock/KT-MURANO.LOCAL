/**
 * HRM shared vocabulary. The values are the backend's; the labels follow the
 * screen's language (2026-10-26: they were fixed English, a Spanish company saw
 * "Full-time", "Married"...). Each list is a computed -- templates read it
 * directly (:options="EMPLOYMENT_TYPES"); script code uses .value -- sorted by
 * label like every dropdown (sortByLabel).
 */
import { computed } from 'vue';
import { useUiStore } from '../../stores/ui';
import { sortByLabel } from '../../lib/filterOptions';

const isEs = () => useUiStore().locale === 'es';
// [value, English, Spanish] -> a computed option list in the current language.
const options = rows => computed(() => sortByLabel(rows.map(([value, en, es]) => ({ value, label: isEs() ? es : en }))));
const labelIn = list => value => (list.value.find(o => o.value === value)?.label || value || '—');

export const EMPLOYMENT_TYPES = options([
    ['full_time', 'Full-time', 'Tiempo completo'],
    ['part_time', 'Part-time', 'Medio tiempo'],
    ['self_employed', 'Self-employed', 'Independiente'],
    ['freelance', 'Freelance', 'Freelance'],
    ['contract', 'Contract', 'Por contrato'],
    ['internship', 'Internship', 'Prácticas'],
    ['apprenticeship', 'Apprenticeship', 'Aprendiz'],
    ['seasonal', 'Seasonal', 'Temporal'],
]);
export const employmentTypeLabel = labelIn(EMPLOYMENT_TYPES);

export const GENDERS = options([
    ['male', 'Male', 'Masculino'],
    ['female', 'Female', 'Femenino'],
]);
export const genderLabel = labelIn(GENDERS);

export const MARITAL_STATUSES = options([
    ['married', 'Married', 'Casado(a)'],
    ['single', 'Single', 'Soltero(a)'],
    ['divorced', 'Divorced', 'Divorciado(a)'],
]);
export const maritalStatusLabel = labelIn(MARITAL_STATUSES);

export const PAY_TYPES = options([
    ['commission', 'Commission (% of sales)', 'Comisión (% de ventas)'],
    ['hourly', 'Hours (on service jobs)', 'Por horas (trabajos de servicio)'],
    // Transport crew (2026-11-01): each completed trip x their trip rate.
    ['per_trip', 'Per trip (transport crew)', 'Por viaje (tripulación)'],
]);

// Transport (2026-11-01): which staff an employee is -- normal schedule (administrative,
// maintenance) or crew paid per trip (pilot, hostess).
export const STAFF_TYPES = options([
    ['administrative', 'Administrative', 'Administrativo'],
    ['hostess', 'Hostess', 'Azafata'],
    ['maintenance', 'Maintenance', 'Mantenimiento'],
    ['pilot', 'Pilot', 'Piloto'],
]);

export const LEAVE_STATUSES = options([
    ['approved', 'Approved', 'Aprobado'],
    ['pending', 'Pending', 'Pendiente'],
    ['rejected', 'Rejected', 'Rechazado'],
]);

const LEAVE_STATUS_COLORS = { approved: 'green', pending: 'orange', rejected: 'red' };

export function leaveStatusColor(status) {
    return LEAVE_STATUS_COLORS[status] || 'default';
}

export const leaveStatusLabel = labelIn(LEAVE_STATUSES);

// Leave types are install-wide rows (no company), created in English by the demo / the
// legacy seed: the known ones are shown in the screen's language; a type a company typed
// itself keeps its own name.
const LEAVE_TYPE_ES = { 'Annual Leave': 'Vacaciones', 'Sick Leave': 'Descanso médico', 'Personal Leave': 'Permiso personal',
    'Maternity Leave': 'Licencia de maternidad', 'Paternity Leave': 'Licencia de paternidad', 'Unpaid Leave': 'Licencia sin goce' };
export function leaveTypeLabel(title) {
    return (isEs() && LEAVE_TYPE_ES[title]) || title || '—';
}
