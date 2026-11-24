/**
 * Short names for the TOP menu (user's rule, 2026-11-24): with the side menu collapsed its groups are buttons in the top
 * bar (SidebarMenu horizontal), where long names push the rest under "Más". A company's own short name (Manage Menu ->
 * "Nombre corto", menu_positions.short_label) wins; else this proposal by the group's key; else the full name.
 * [English, Spanish]. Keys are config/menu.js group keys plus the companies' own groups (custom_*).
 */
export const MENU_SHORT = {
  // Groups the companies made in Manage Menu
  custom_menul1: ['Modules', 'Módulos'],                 // Main Modules
  custom_company: ['Organization', 'Organización'],
  custom_suppliers: ['Suppliers', 'Proveedores'],
  custom_reports_2: ['Reports', 'Reportes'],
  custom_tc_pasajeros: ['Passengers', 'Pasajeros'],      // Módulo de Pasajeros
  custom_tc_encomiendas: ['Parcels', 'Encomiendas'],     // Módulo de Encomiendas
  custom_tc_flota: ['Fleet', 'Flota'],                   // Flota y Logística (TMS)
  custom_tc_backoffice: ['Back office', 'Back Office'],  // Back Office y Administración

  // The code's groups (config/menu.js)
  home_buttons: ['Home', 'Inicio'],
  intranet: ['Intranet', 'Intranet'],
  intranet_management: ['Intranet admin', 'Admin. intranet'],
  Store: ['Store', 'Tienda'],
  website: ['Website', 'Web'],
  gallery: ['Gallery', 'Galería'],
  helpdesk: ['Helpdesk', 'Soporte'],
  portfolio: ['Portfolio', 'Portafolio'],
  forms: ['Forms', 'Formularios'],
  email_office: ['Email', 'Correo'],
  People: ['Customers', 'Clientes'],
  products: ['Products', 'Productos'],
  assets: ['Assets', 'Activos'],
  sales: ['Sales', 'Ventas'],
  kitchen: ['Kitchen', 'Cocina'],
  sale_return: ['Sales ret.', 'Devol. ventas'],
  purchases: ['Purchases', 'Compras'],
  purchase_return: ['Purch. ret.', 'Devol. compras'],
  quotations: ['Quotes', 'Cotizaciones'],
  adjustments: ['Adjustments', 'Ajustes'],
  transfers: ['Transfers', 'Traslados'],
  damages: ['Damages', 'Daños'],
  hrm: ['HR', 'RR. HH.'],
  recruit: ['Recruiting', 'Selección'],
  meeting: ['Meetings', 'Reuniones'],
  marketing: ['Marketing', 'Marketing'],
  accounting: ['Accounting', 'Contabilidad'],
  EWallet: ['Wallet', 'Billetera'],
  commissions: ['Commissions', 'Comisiones'],
  promotions: ['Promos', 'Promociones'],
  ecommerce_platforms: ['E-commerce', 'E-commerce'],
  integrations: ['Integrations', 'Integraciones'],
  documents: ['Documents', 'Documentos'],
  subscription_product: ['Subscriptions', 'Suscripciones'],
  manufacturing: ['MRP', 'MRP'],
  projects: ['Projects', 'Proyectos'],
  bookings: ['Bookings', 'Reservas'],
  service: ['Services', 'Servicios'],
  fleet: ['Fleet', 'Flota'],
  transport: ['Transport', 'Transporte'],
  transport_integration: ['Integration', 'Integración'],
  b2b: ['B2B', 'B2B'],
  callcenter: ['Call center', 'Call center'],
  hospital: ['Hospital', 'Hospital'],
  school: ['School', 'Colegio'],
  settings: ['Settings', 'Configuración'],
  superadmin: ['SuperAdmin', 'SuperAdmin'],
  manage_menu: ['Menu', 'Menú'],
  reports: ['Reports', 'Reportes'],
};

/** The proposal for a group key in the active language, or null. */
export function proposedShort(key, locale) {
  const p = MENU_SHORT[key];
  if (!p) return null;
  return String(locale || 'en').startsWith('es') ? p[1] : p[0];
}
