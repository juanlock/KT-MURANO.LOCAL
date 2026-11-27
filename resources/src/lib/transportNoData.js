// Turismo works only with real data (the user's rule, 2026-11-27): a cost or figure we have no data for shows 0 with this
// helper -- what is missing and where it would come from. One place so every board says the same.
const NO_DATA = {
  payroll: ['No data yet: the payroll of pilots and staff (salaries) has not been given. Asked to Mayte in the project.',
    'Sin datos todavía: falta la planilla de pilotos y personal (sueldos). Pedida a Mayte en el proyecto.'],
  staff: ['No data yet: what each trip pays its crew.', 'Sin datos todavía: lo que se paga a la tripulación por viaje.'],
  depreciation: ['No data yet: each bus\'s purchase price and useful life (km).', 'Sin datos todavía: falta el precio de compra y la vida útil (km) de cada bus.'],
  maintenance: ['No data yet: the workshop jobs and their cost (where is maintenance kept today? asked in the project).',
    'Sin datos todavía: faltan los trabajos de taller y su costo (¿dónde llevan hoy el mantenimiento? preguntado en el proyecto).'],
  agency: ['No data yet: each agency\'s daily cost (rent, power, staff).', 'Sin datos todavía: falta el costo diario de cada agencia (alquiler, luz, personal).'],
  meals: ['Not used: Turismo gives no meals on board.', 'No aplica: Turismo no da refrigerios a bordo.'],
  other: ['Maintenance + agencies: no data yet (workshop jobs, agencies\' daily cost).', 'Mantenimiento + agencias: sin datos todavía (trabajos de taller, costo diario de las agencias).'],
  due: ['No data yet: the buses\' SOAT, technical inspection and registration dates and their maintenance plan.',
    'Sin datos todavía: faltan las fechas de SOAT, revisión técnica y tarjeta de cada bus, y su plan de mantenimiento.'],
};

/** The "no data" helper of a cost kind (or null when we know nothing special about it). */
export function noDataHelp(key, es) {
  const m = NO_DATA[key];
  return m ? m[es ? 1 : 0] : null;
}

/** Kinds the boards always list, with 0 and the helper when there is no data. */
export const COST_KINDS_ALWAYS = ['payroll', 'depreciation', 'maintenance', 'agency'];
