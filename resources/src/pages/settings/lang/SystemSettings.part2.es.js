// System Settings -> inner fields of Dashboard, DataTable, Export, Prefixes,
// Pharmacy, Backup, Security, Maintenance and Calendar, in Spanish (2026-10-20).
// Merged with SystemSettings.es.js in SystemSettings.vue (useLocalText).
export default {
    // Dashboard
    'The company\'s dashboard, top to bottom. Move a block with the arrows; tick what each block shows (a block with nothing ticked disappears). Applies to every user of this company after':
        'El tablero de la empresa, de arriba hacia abajo. Mueva un bloque con las flechas; marque lo que muestra cada bloque (un bloque sin nada marcado desaparece). Se aplica a todos los usuarios de esta empresa después de',
    'Save': 'Guardar',
    'Show everything': 'Mostrar todo',
    'Hide everything': 'Ocultar todo',
    'Reset to default order': 'Restablecer el orden predeterminado',
    // Dashboard blocks (config/dashboardSections.js)
    'Summary cards': 'Tarjetas de resumen',
    'Unpaid invoices by age': 'Facturas sin pagar por antigüedad',
    'Sales charts': 'Gráficos de ventas',
    'Payment charts': 'Gráficos de pagos',
    'Payments, stock and quick actions': 'Pagos, inventario y acciones rápidas',
    'Stock alert and top products': 'Alerta de inventario y productos más vendidos',
    'Recent sales': 'Ventas recientes',
    // Dashboard sections
    'Card: Sales': 'Tarjeta: Ventas',
    'Card: Purchases': 'Tarjeta: Compras',
    'Card: Sales returns': 'Tarjeta: Devoluciones de ventas',
    'Card: Purchase returns': 'Tarjeta: Devoluciones de compras',
    'Card: Sales due': 'Tarjeta: Ventas por cobrar',
    'Card: Purchase due': 'Tarjeta: Compras por pagar',
    'Card: Invoices': 'Tarjeta: Facturas',
    'Card: Profit': 'Tarjeta: Ganancia',
    'Card: Unpaid invoices (no payment yet)': 'Tarjeta: Facturas sin pagar (sin ningún pago)',
    'Unpaid 0-30 days': 'Sin pagar 0-30 días',
    'Unpaid 31-60 days': 'Sin pagar 31-60 días',
    'Unpaid 61-90 days': 'Sin pagar 61-90 días',
    'Unpaid over 90 days': 'Sin pagar más de 90 días',
    'Chart: Sales and purchases': 'Gráfico: Ventas y compras',
    'Chart: Top selling products': 'Gráfico: Productos más vendidos',
    'Chart: Payments sent / received': 'Gráfico: Pagos enviados / recibidos',
    'Chart: Top customers': 'Gráfico: Mejores clientes',
    'Sales by payment method': 'Ventas por método de pago',
    'Stock value': 'Valor del inventario',
    'Quick actions': 'Acciones rápidas',
    'Table: Stock alert': 'Tabla: Alerta de inventario',
    'Table: Top selling products': 'Tabla: Productos más vendidos',
    'Table: Recent sales': 'Tabla: Ventas recientes',

    // DataTable
    'Company-wide table format': 'Formato de tablas para toda la empresa',
    'Applies to every table in the system for every user of this company once you Save. Changes preview immediately here. Column names, widths, visibility and order are set per table with the Customize columns button on each list (admins).':
        'Se aplica a todas las tablas del sistema para todos los usuarios de esta empresa al Guardar. Los cambios se ven al instante aquí. Los nombres, anchos, visibilidad y orden de las columnas se configuran en cada tabla con el botón Personalizar columnas de cada lista (administradores).',
    'Table': 'Tabla',
    'Header': 'Encabezado',
    'Toolbar': 'Barra de herramientas',
    'Font family': 'Tipo de letra',
    'Default (Ant Design)': 'Predeterminado (Ant Design)',
    'Monospace': 'Monoespaciado',
    'System UI': 'Interfaz del sistema',
    'Serif': 'Serif',
    'Font size': 'Tamaño de letra',
    'Row density': 'Densidad de filas',
    'Compact': 'Compacta',
    'Medium': 'Media',
    'Large': 'Amplia',
    'Cell borders': 'Bordes de celdas',
    'Striped rows': 'Filas alternadas',
    'Sortable columns': 'Columnas ordenables',
    'Font weight': 'Grosor de letra',
    'Normal': 'Normal',
    'Semibold': 'Seminegrita',
    'Bold': 'Negrita',
    'Uppercase': 'Mayúsculas',
    'Background color': 'Color de fondo',
    'Theme (default)': 'Tema (predeterminado)',
    'Plain': 'Simple',
    'Custom': 'Personalizado',
    'Text color': 'Color del texto',
    'Column visibility': 'Visibilidad de columnas',
    'Preview': 'Vista previa',
    'Reference': 'Referencia',
    'Name': 'Nombre',
    'Amount': 'Monto',

    // Export
    'Export full data': 'Exportar todos los datos',
    'On: exports and print fetch every row matching the current filters.':
        'Activado: las exportaciones y la impresión traen todas las filas que coinciden con los filtros actuales.',
    'Off: only the rows currently displayed on the page are exported.':
        'Desactivado: solo se exportan las filas que se ven en la página.',
    'Include totals row': 'Incluir fila de totales',
    'Append a bold totals row for money/number columns to PDF, Excel and print exports.':
        'Agrega una fila de totales en negrita para las columnas de dinero/números en las exportaciones a PDF, Excel e impresión.',
    'PDF orientation': 'Orientación del PDF',
    'Page orientation for exported PDF files.': 'Orientación de página de los archivos PDF exportados.',
    'Landscape': 'Horizontal',
    'Portrait': 'Vertical',
    'Add date to file names': 'Agregar la fecha a los nombres de archivo',
    'Append today\'s date to exported file names, e.g. Sales_Report_2026-08-04.pdf.':
        'Agrega la fecha de hoy a los nombres de los archivos exportados, p. ej. Sales_Report_2026-08-04.pdf.',
    'Company & date in PDF header': 'Empresa y fecha en el encabezado del PDF',
    'Print the company name and generation date/time under the report title in PDF exports.':
        'Imprime el nombre de la empresa y la fecha/hora de generación debajo del título del reporte en las exportaciones a PDF.',

    // Backup
    'Cloud backup destination': 'Destino del respaldo en la nube',
    'Copy generated backups to cloud storage.': 'Copia los respaldos generados a un almacenamiento en la nube.',
    'Manage backup archives': 'Administrar archivos de respaldo',
    'Provider': 'Proveedor',
    'S3-compatible': 'Compatible con S3',
    'Path': 'Ruta',
    'Bucket': 'Bucket',
    'Region': 'Región',
    'Endpoint': 'Endpoint',
    'Access key': 'Clave de acceso',
    'Secret key': 'Clave secreta',
    'Path-style URLs': 'URLs de estilo ruta',
    'Folder ID': 'ID de carpeta',
    'Client ID': 'ID de cliente',
    'Client secret': 'Secreto de cliente',
    'Access token': 'Token de acceso',
    'Refresh token': 'Token de actualización',

    // Security
    'Inactivity auto-logout': 'Cierre de sesión por inactividad',
    'Sign users out after this long without activity. "Never" disables automatic logout completely; activity on any open page keeps the session alive.':
        'Cierra la sesión de los usuarios después de este tiempo sin actividad. "Nunca" desactiva por completo el cierre automático; la actividad en cualquier página abierta mantiene la sesión activa.',
    'Never': 'Nunca',
    'min': 'min',
    'Login devices': 'Dispositivos de inicio de sesión',
    'Review active sessions and sign out other devices.': 'Revise las sesiones activas y cierre la sesión en otros dispositivos.',

    // Maintenance
    'Debug mode': 'Modo de depuración',
    'Verbose error output. Keep off in production.': 'Muestra errores detallados. Manténgalo apagado en producción.',
    'Offline sync (POS)': 'Sincronización sin conexión (POS)',
    'Queue POS sales while offline and sync when back online.':
        'Guarda en cola las ventas del POS sin conexión y las sincroniza al volver a estar en línea.',
    'Clears compiled views, config and route caches.': 'Borra la caché de vistas compiladas, configuración y rutas.',

    // Calendar
    'Google Calendar connected': 'Google Calendar conectado',
    'Google Calendar not connected': 'Google Calendar no conectado',
    'Connect': 'Conectar',
    'Disconnect': 'Desconectar',
    'Redirect URI': 'URI de redirección',
    'Calendar ID': 'ID del calendario',
};
