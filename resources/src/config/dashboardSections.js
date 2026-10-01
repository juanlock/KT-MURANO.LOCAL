/**
 * The dashboard's cards / charts / tables a company can switch off
 * (System Settings -> Defaults -> Users -> "Dashboard sections", stored in
 * settings.dashboard_hidden_sections). Dashboard.vue gates each block by id.
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
];
