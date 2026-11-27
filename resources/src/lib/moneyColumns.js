// The standard way every Turismo table shows money (the user's rule, 2026-11-27): a group "Ingresos" with Pasajes,
// Encomiendas, Excesos and their Total, a group "Egresos" with Combustible, Peajes, Personal, Depreciación, Otros and
// their Total, then Ganancia / Pérdida. Always "Egresos", never "Gastos". Same order and names on every board.
export const INCOME_COLS = [
  { key: 'tickets', en: 'Tickets', es: 'Pasajes' },
  { key: 'parcels', en: 'Parcels', es: 'Encomiendas', short: 'Encom.' },
  { key: 'excess', en: 'Excess', es: 'Excesos', short: 'Exces.' },
];
export const EXPENSE_COLS = [
  { key: 'fuel', en: 'Fuel', es: 'Combustible', short: 'Comb.', parts: ['fuel'] },
  { key: 'tolls', en: 'Tolls', es: 'Peajes', parts: ['tolls'] },
  { key: 'staff', en: 'Staff', es: 'Personal', parts: ['payroll', 'staff'] },
  { key: 'depreciation', en: 'Depreciation', es: 'Depreciación', short: 'Deprec.', parts: ['depreciation'] },
  { key: 'other', en: 'Other', es: 'Otros', parts: ['maintenance', 'agency', 'meals'] },
];

/** A trip's (or a sum's) income of one standard column. */
export const incomeOf = (income, key) => Number(income?.[key] || 0);
/** A trip's (or a sum's) expense of one standard column (Personal = payroll + crew pay; Otros = maintenance + agency + meals). */
export const expenseOf = (costs, col) => col.parts.reduce((n, k) => n + Number(costs?.[k] || 0), 0);

/** Adds up the income and costs of several trips into one {income, costs} with the standard keys. */
export function sumMoney(trips) {
  const income = { tickets: 0, parcels: 0, excess: 0, total: 0 };
  const costs = {};
  for (const t of trips) {
    for (const k of Object.keys(income)) income[k] += Number(t.income?.[k] || 0);
    for (const [k, v] of Object.entries(t.costs || {})) costs[k] = (costs[k] || 0) + Number(v || 0);
  }
  return { income, costs };
}
