// Spanish texts of InvoicePdfSettings.vue (English text = key), used through useLocalText().
// Only the settings screen: the texts printed on the PDF (labels, default title,
// thank-you line) are the user's data and are not translated here.
export default {
  // Document types
  'Sales Invoice': 'Factura de venta',
  'Quotation': 'Cotización',
  'Purchase': 'Compra',

  // Tabs
  'Default': 'Predeterminado',
  'Design': 'Diseño',

  // Default tab
  'These options apply to Sales invoices.': 'Estas opciones se aplican a las facturas de venta.',
  'Company Note content': 'Contenido de la nota de la empresa',
  'Terms & Conditions content': 'Contenido de los términos y condiciones',
  'e.g. We accept credit card for payment with 3.5% extra fee...':
    'p. ej., Aceptamos pagos con tarjeta de crédito con un cargo adicional del 3.5%...',
  'e.g. Make all checks payable to: {{COMPANY NAME}}...':
    'p. ej., Emite todos los cheques a nombre de: {{COMPANY NAME}}...',
  "{{COMPANY NAME}} and {{ADDRESS COMPANY}} are replaced with this company's own name / address.":
    '{{COMPANY NAME}} y {{ADDRESS COMPANY}} se reemplazan por el nombre / la dirección de esta empresa.',
  'Hide the time on the invoice date': 'Ocultar la hora en la fecha de la factura',
  'Show only the date (2025-12-30), not the time the sale was made (16:39:00).':
    'Mostrar solo la fecha (2025-12-30), no la hora en que se hizo la venta (16:39:00).',
  'Discount column': 'Columna de descuento',
  'Show the DISC column in the line-items table.': 'Mostrar la columna DISC en la tabla de artículos.',
  'Tax column': 'Columna de impuesto',
  'Show the TAX column (only when at least one item has its own tax).':
    'Mostrar la columna TAX (solo cuando al menos un artículo tiene su propio impuesto).',
  'Shipping row': 'Fila de envío',
  'Show Shipping in the totals (only when the invoice has shipping).':
    'Mostrar el envío en los totales (solo cuando la factura tiene envío).',
  'Discount row': 'Fila de descuento',
  'Show Discount in the totals (only when the invoice has a discount).':
    'Mostrar el descuento en los totales (solo cuando la factura tiene descuento).',
  'Previous Dues / Net Balance': 'Saldo anterior / Saldo neto',
  'For a customer with an outstanding balance.': 'Para un cliente con saldo pendiente.',
  'Company Note': 'Nota de la empresa',
  'A note block below the totals.': 'Un bloque de nota debajo de los totales.',
  'Terms & Conditions': 'Términos y condiciones',
  'A terms block below the totals.': 'Un bloque de términos debajo de los totales.',

  // Design tab
  'Colors': 'Colores',
  'Primary color': 'Color principal',
  'Secondary color': 'Color secundario',
  'Text color': 'Color del texto',
  'Background color': 'Color de fondo',
  'Typography': 'Tipografía',
  'Font family': 'Tipo de letra',
  'Font size (pt)': 'Tamaño de letra (pt)',
  'Layout & logo': 'Diseño de página y logo',
  'Top/bottom margin (mm)': 'Margen superior/inferior (mm)',
  'Left/right margin (mm)': 'Margen izquierdo/derecho (mm)',
  'Show logo': 'Mostrar logo',
  'Logo width (px)': 'Ancho del logo (px)',
  'Logo height (px)': 'Alto del logo (px)',
  'Items table': 'Tabla de artículos',
  'Outer border': 'Borde exterior',
  'Striped rows': 'Filas alternadas',
  'Sections': 'Secciones',
  'Status / Payment badges': 'Etiquetas de estado / pago',
  'Supplier details': 'Datos del proveedor',
  'Customer details': 'Datos del cliente',
  'Company details': 'Datos de la empresa',
  'Notes': 'Notas',
  'Footer text': 'Texto del pie de página',
  'Thank-you line': 'Línea de agradecimiento',
  'Text & labels': 'Textos y etiquetas',
  'Document title (blank = default)': 'Título del documento (vacío = predeterminado)',
  'Thank-you line (blank = default)': 'Línea de agradecimiento (vacío = predeterminada)',
  'Footer text (blank = System Settings invoice footer)':
    'Texto del pie de página (vacío = pie de factura de la Configuración del sistema)',

  // Preview
  'Live preview — the real PDF': 'Vista previa en vivo — el PDF real',
  'Refresh': 'Actualizar',
  'Invoice preview': 'Vista previa de la factura',
  'No preview yet': 'Aún no hay vista previa',
  'Preview failed (HTTP {status})': 'No se pudo generar la vista previa (HTTP {status})',
};
