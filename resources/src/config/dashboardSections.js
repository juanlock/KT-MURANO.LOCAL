/**
 * The dashboard, as a company sets it up in ONE place (System Settings ->
 * Dashboard, user's request 2026-10-06): the blocks top to bottom
 * (settings.dashboard_section_order, block ids) and, inside each block, the
 * cards / charts / tables shown (settings.dashboard_hidden_sections, section
 * ids). Dashboard.vue renders the blocks in that order and gates each section.
 */
export const DASHBOARD_SECTIONS = [
  // Summary cards, one switch each.
  { id: 'card_sales', label: 'Card: Sales' },
  { id: 'card_purchases', label: 'Card: Purchases' },
  { id: 'card_sales_returns', label: 'Card: Sales returns' },
  { id: 'card_purchase_returns', label: 'Card: Purchase returns' },
  { id: 'card_sales_due', label: 'Card: Sales due' },
  { id: 'card_purchase_due', label: 'Card: Purchase due' },
  { id: 'card_invoices', label: 'Card: Invoices' },
  { id: 'card_profit', label: 'Card: Profit' },
  { id: 'card_unpaid_invoices', label: 'Card: Unpaid invoices (no payment yet)' },
  // Unpaid invoices by age (days since the invoice date, every open one).
  { id: 'card_unpaid_0_30', label: 'Unpaid 0-30 days' },
  { id: 'card_unpaid_31_60', label: 'Unpaid 31-60 days' },
  { id: 'card_unpaid_61_90', label: 'Unpaid 61-90 days' },
  { id: 'card_unpaid_90_plus', label: 'Unpaid over 90 days' },
  { id: 'chart_sales_purchases', label: 'Chart: Sales and purchases' },
  { id: 'chart_top_selling', label: 'Chart: Top selling products' },
  { id: 'chart_payment_sent_received', label: 'Chart: Payments sent / received' },
  { id: 'chart_top_customers', label: 'Chart: Top customers' },
  { id: 'sales_by_payment', label: 'Sales by payment method' },
  { id: 'stock_value', label: 'Stock value' },
  { id: 'quick_actions', label: 'Quick actions' },
  { id: 'table_stock_alert', label: 'Table: Stock alert' },
  { id: 'table_top_selling_products', label: 'Table: Top selling products' },
  { id: 'table_recent_sales', label: 'Table: Recent sales' },
  // Transport module (2026-11-01): routes map, terminals, fleet, staff, pilots' hours.
  { id: 'transport_departures', label: 'Departures report, per agency' },
  { id: 'transport_profit', label: 'Profit of each route and trip' },
  { id: 'transport_claims', label: 'Complaints: indicators from the complaints book' },
  { id: 'transport_owner', label: 'Business summary (income, tickets, trips, fleet costs)' },
  { id: 'transport_board', label: 'Transport board (map, terminals, pilot hours)' },
];

/** The dashboard's blocks (rows), default order; `sections` = what each one holds. */
export const DASHBOARD_BLOCKS = [
  // First: shown only to companies with the Transport module (TransportBoard hides itself otherwise).
  { id: 'transport_departures', label: 'Departures report', sections: ['transport_departures'] },
  { id: 'transport_profit', label: 'Profit by route', sections: ['transport_profit'] },
  { id: 'transport_claims', label: 'Complaints', sections: ['transport_claims'] },
  { id: 'transport_owner', label: 'Business summary', sections: ['transport_owner'] },
  { id: 'transport', label: 'Transport board', sections: ['transport_board'] },
  { id: 'cards', label: 'Summary cards', sections: ['card_sales', 'card_purchases', 'card_sales_returns', 'card_purchase_returns', 'card_sales_due', 'card_purchase_due', 'card_invoices', 'card_profit', 'card_unpaid_invoices'] },
  { id: 'aging', label: 'Unpaid invoices by age', sections: ['card_unpaid_0_30', 'card_unpaid_31_60', 'card_unpaid_61_90', 'card_unpaid_90_plus'] },
  { id: 'sales_charts', label: 'Sales charts', sections: ['chart_sales_purchases', 'chart_top_selling'] },
  { id: 'payment_charts', label: 'Payment charts', sections: ['chart_payment_sent_received', 'chart_top_customers'] },
  { id: 'payments_stock', label: 'Payments, stock and quick actions', sections: ['sales_by_payment', 'stock_value', 'quick_actions'] },
  { id: 'tables', label: 'Stock alert and top products', sections: ['table_stock_alert', 'table_top_selling_products'] },
  { id: 'recent_sales', label: 'Recent sales', sections: ['table_recent_sales'] },
];

/**
 * The saved block order (JSON list / array of block ids), completed with any
 * block it doesn't name, in default order. Old saved values (the retired
 * widget ids) simply fall back to the default order.
 */
export function blockOrder(saved) {
  let ids = [];
  try {
    ids = Array.isArray(saved) ? saved : (saved ? JSON.parse(saved) : []);
  } catch (e) { ids = []; }
  const known = new Set(DASHBOARD_BLOCKS.map(b => b.id));
  const picked = (Array.isArray(ids) ? ids : []).filter((id, i, a) => known.has(id) && a.indexOf(id) === i);

  return [...picked, ...DASHBOARD_BLOCKS.map(b => b.id).filter(id => !picked.includes(id))];
}
