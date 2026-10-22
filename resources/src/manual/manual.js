/**
 * The in-app user manual (header book icon -> ManualDrawer.vue), English and
 * Spanish side by side so both always change together.
 *
 * Each entry is one module of the menu:
 *   key      unique id
 *   module   company module switch (config/modules.js key) -- hidden when the
 *            company has it turned off; null = core, always on
 *   perms    the user sees the module if their role has ANY of these
 *            (empty = everyone)
 *   paths    SPA screens it explains (first URL segments, or deeper prefixes
 *            like 'service/daily'): "This page" picks the entry and sections
 *            whose path matches the screen the user is on
 *   sections the same three filters (module / perms / paths) per section,
 *            plus title, steps and an optional note -- { en, es } each
 *
 * Text: plain sentences; **bold** for buttons / menu names, "A › B" for menu
 * paths. RULE (CLAUDE.md, Pa Llevar): a change to a module, form or role
 * updates its entry here, in English AND Spanish, in the same session.
 */

export const MANUAL_UPDATED = '2026-10-21';

export const MANUAL = [
  // ------------------------------------------------------------------ start
  {
    key: 'start', module: null, perms: [], paths: ['dashboard', 'profile', 'home-buttons'],
    title: { en: 'Getting started', es: 'Primeros pasos' },
    intro: {
      en: 'Your login belongs to one company and your role decides what you see: menus, buttons and even single tabs. If you need something that is not there, ask your company administrator.',
      es: 'Su usuario pertenece a una empresa y su rol decide lo que ve: menús, botones e incluso pestañas. Si necesita algo que no aparece, pídaselo al administrador de su empresa.',
    },
    sections: [
      {
        id: 'signin', paths: ['dashboard'],
        title: { en: 'Sign in and your home page', es: 'Ingresar y su página de inicio' },
        steps: {
          en: ['Sign in with your email and password.', 'Murano opens your role\'s home page: the Dashboard, **Daily Tasks** (field crews) or the **Bookings calendar** (salon staff).', 'Use the left menu (☰ on a phone) to move around.', 'On a shared computer, always sign out from your name in the top bar.'],
          es: ['Ingrese con su correo y contraseña.', 'Murano abre la página de inicio de su rol: el Panel, **Tareas diarias** (cuadrillas) o el **Calendario de reservas** (personal del salón).', 'Use el menú de la izquierda (☰ en el celular) para moverse.', 'En una computadora compartida, cierre siempre la sesión desde su nombre en la barra superior.'],
        },
        note: {
          en: 'Forgot your password, or your login says it is disabled? Ask your company administrator.',
          es: '¿Olvidó su contraseña o su usuario aparece deshabilitado? Consulte al administrador de su empresa.',
        },
      },
      {
        id: 'topbar', paths: ['dashboard', 'profile'],
        title: { en: 'Top bar icons', es: 'Íconos de la barra superior' },
        intro: {
          en: 'The buttons at the top right of every page. You only see the ones your company and your role allow; you can hide or reorder yours in **Profile › Header icons**.',
          es: 'Los botones arriba a la derecha de cada página. Solo ve los que su empresa y su rol permiten; puede ocultar o reordenar los suyos en **Perfil › Íconos del encabezado**.',
        },
        // Each key is drawn with its real icon and color (ManualDrawer ICON_LOOK).
        icons: [
          { key: 'company', title: { en: 'Company selector (SuperAdmin only)', es: 'Selector de empresa (solo SuperAdmin)' }, text: { en: 'Which company you are working on. Every page and form follows the company chosen here.', es: 'En qué empresa está trabajando. Todas las páginas y formularios siguen la empresa elegida aquí.' } },
          { key: 'pos', title: { en: 'POS', es: 'POS' }, text: { en: 'Opens the point of sale (if your role can sell at the register).', es: 'Abre el punto de venta (si su rol puede vender en caja).' } },
          { key: 'customize', title: { en: 'Settings (gear)', es: 'Configuración (engranaje)' }, text: { en: 'Appearance of the app: theme, colors, menu layout.', es: 'Apariencia de la aplicación: tema, colores, distribución del menú.' } },
          { key: 'install', title: { en: 'Install app', es: 'Instalar app' }, text: { en: 'Installs Murano like an app on this computer or phone. Shown only while the browser offers it.', es: 'Instala Murano como una aplicación en esta computadora o celular. Aparece solo mientras el navegador lo ofrece.' } },
          { key: 'summary', title: { en: 'Como Vamos! (today\'s summary)', es: '¡Cómo Vamos! (resumen de hoy)' }, text: { en: 'Today at a glance: sales, payments, bookings and what is pending.', es: 'El día de un vistazo: ventas, pagos, reservas y lo pendiente.' } },
          { key: 'fullscreen', title: { en: 'Full screen', es: 'Pantalla completa' }, text: { en: 'Uses the whole screen; click again (or Esc) to go back. Not on phones.', es: 'Usa toda la pantalla; haga clic otra vez (o Esc) para volver. No en celulares.' } },
          { key: 'manual', title: { en: 'User manual (open book)', es: 'Manual de usuario (libro abierto)' }, text: { en: 'This manual: **This page** shows help for the screen you are on, **Whole manual** has everything with a search. English or Spanish.', es: 'Este manual: **Esta página** muestra la ayuda de la pantalla en la que está, **Todo el manual** tiene todo con búsqueda. Inglés o español.' } },
          { key: 'language', title: { en: 'Language (globe)', es: 'Idioma (globo)' }, text: { en: 'Changes the language of the app for you. Until you pick one, Murano (sign-in page included) follows your computer\'s language when it is English or Spanish, otherwise the company\'s default language.', es: 'Cambia el idioma de la aplicación para usted. Mientras no elija uno, Murano (incluida la página de ingreso) usa el idioma de su computadora si es español o inglés; si no, el idioma predeterminado de la empresa.' } },
          { key: 'notifications', title: { en: 'Notifications (bell)', es: 'Notificaciones (campana)' }, text: { en: 'Work waiting for you (e.g. bookings to confirm, computers to approve). The number is how many; click an item to go there.', es: 'Trabajo pendiente (p. ej. reservas por confirmar, computadoras por aprobar). El número indica cuántos; haga clic en uno para ir.' } },
          { key: 'clock', title: { en: 'Clock in / out', es: 'Marcar entrada / salida' }, text: { en: 'Clocks you in or out for today (if your role has it). Turns green with the time while you are clocked in.', es: 'Marca su entrada o salida de hoy (si su rol lo tiene). Se pone verde con la hora mientras está marcado.' } },
          { key: 'logout', title: { en: 'Log out', es: 'Cerrar sesión' }, text: { en: 'Signs you out in one click. Always do it on a shared computer.', es: 'Cierra su sesión con un clic. Hágalo siempre en una computadora compartida.' } },
          { key: 'user', title: { en: 'Your name', es: 'Su nombre' }, text: { en: '**Profile**, Settings, **Appearance** (light / dark / auto) and sign out.', es: '**Perfil**, Configuración, **Apariencia** (claro / oscuro / automático) y cerrar sesión.' } },
        ],
        note: {
          en: 'Company administrators choose which icons the whole company sees in **System Settings › Defaults › Users › Header icons**; each person can still show / hide and reorder their own in **Profile**.',
          es: 'Los administradores eligen qué íconos ve toda la empresa en **Configuración del sistema › Predeterminados › Usuarios › Íconos del encabezado**; cada persona puede mostrar / ocultar y reordenar los suyos en **Perfil**.',
        },
      },
      {
        id: 'profile', paths: ['profile'],
        title: { en: 'Profile, password and header icons', es: 'Perfil, contraseña e íconos del encabezado' },
        steps: {
          en: ['Click your name › **Profile**.', 'Change your password, photo and contact details, then **Save**.', 'Under **Header icons**, choose for each icon: Company default, Show (green) or Hide (red).', 'Change their order with the **‹ ›** arrows: the list goes from left to right in the top bar (**Reset order** puts it back). Then **Save**.'],
          es: ['Haga clic en su nombre › **Perfil**.', 'Cambie su contraseña, foto y datos de contacto, luego **Guardar**.', 'En **Íconos del encabezado**, elija para cada ícono: Predeterminado de la empresa, Mostrar (verde) u Ocultar (rojo).', 'Cambie su orden con las flechas **‹ ›**: la lista va de izquierda a derecha en la barra superior (**Restablecer orden** la devuelve). Luego **Guardar**.'],
        },
        note: {
          en: 'Your PIN (4 to 8 digits, unique in your company) is set by your administrator in Users. It identifies you at the shared register: never share it.',
          es: 'Su PIN (de 4 a 8 dígitos, único en su empresa) lo asigna el administrador en Usuarios. Lo identifica en la caja compartida: nunca lo comparta.',
        },
      },
      {
        id: 'clock', perms: ['attendance_self'],
        title: { en: 'Clock in and clock out', es: 'Marcar entrada y salida' },
        steps: {
          en: ['When your shift starts, click the **clock** in the top bar. It turns green and shows your clock-in time.', 'When you leave, click it again: Murano shows the hours worked today.', 'Forgot to clock? Tell your manager; they correct it in HRM › Attendance.'],
          es: ['Al empezar su turno, haga clic en el **reloj** de la barra superior. Se pone verde y muestra la hora de entrada.', 'Al salir, haga clic otra vez: Murano muestra las horas trabajadas hoy.', '¿Olvidó marcar? Avise a su encargado; lo corrige en RR. HH. › Asistencia.'],
        },
      },
      {
        id: 'filters',
        title: { en: 'Date filters and exports', es: 'Filtros de fecha y exportación' },
        steps: {
          en: ['Every list and report has the same date filter: All, Today, This Week … Last Year, plus Start date and End date you can type.', '**Double-click** a preset to make it your default (★).', 'Export a list or report to PDF or Excel with the buttons above the table.', 'Open a record from a filtered list (Sales, Purchases, Quotations, Customers, Suppliers), then press **Back**, **Cancel** or **Save**: the list comes back exactly as you left it (filters, dates, page, search). Opening the list from the menu starts fresh.'],
          es: ['Todas las listas y reportes tienen el mismo filtro de fechas: Todo, Hoy, Esta semana … Año pasado, más Fecha inicial y Fecha final que puede escribir.', 'Haga **doble clic** en una opción para dejarla como predeterminada (★).', 'Exporte una lista o reporte a PDF o Excel con los botones sobre la tabla.', 'Abra un registro desde una lista filtrada (Ventas, Compras, Cotizaciones, Clientes, Proveedores) y pulse **Volver**, **Cancelar** o **Guardar**: la lista vuelve tal como la dejó (filtros, fechas, página, búsqueda). Al abrir la lista desde el menú empieza de cero.'],
        },
      },
    ],
  },

  // ------------------------------------------------------------------ sales
  {
    key: 'sales', module: 'sales', perms: ['Sales_view', 'Sales_add'], paths: ['sales', 'sale-returns', 'shipments'],
    title: { en: 'Sales and invoices', es: 'Ventas y facturas' },
    intro: {
      en: 'Invoices are numbered automatically with your company\'s own series (prefix + the company\'s next number).',
      es: 'Las facturas se numeran solas con la serie de su empresa (prefijo + el siguiente número de la empresa).',
    },
    sections: [
      {
        id: 'sales-list', perms: ['Sales_view'], paths: ['sales'],
        title: { en: 'Find a sale', es: 'Buscar una venta' },
        steps: {
          en: ['Open **Sales › List Sales**.', 'Filter by date, customer, location or payment status.', 'The **Other due** column shows the customer\'s other unpaid invoices; open it to see each invoice\'s date and the reminder emails sent (how many and when).', 'On a phone the list shows the sale information first; swipe the table sideways for more columns.'],
          es: ['Abra **Ventas › Lista de ventas**.', 'Filtre por fecha, cliente, local o estado de pago.', 'La columna **Otras deudas** muestra las demás facturas impagas del cliente; ábrala para ver la fecha de cada factura y los recordatorios enviados (cuántos y cuándo).', 'En el celular la lista muestra primero los datos de la venta; deslice la tabla para ver más columnas.'],
        },
      },
      {
        id: 'sales-add', perms: ['Sales_add'], paths: ['sales/create', 'sales/from-quotation'],
        title: { en: 'Create a sale', es: 'Crear una venta' },
        steps: {
          en: ['Open **Sales › Add Sale**.', 'Choose the date, the customer and the location.', 'Add products or services; adjust quantity, price, discount or tax per line if your role allows it.', 'Save. The invoice number is given automatically.', 'To repeat an invoice, use **Duplicate** in its row menu in the list: a new sale opens with today\'s date and the same customer, location and lines (paid invoices too); the top says which invoice it copies. Check it and save.', 'Editing an invoice: the top of the page shows its number (**Edit Sale · INV_…**), customer, date, total, paid and due, its status and payment status, and **View invoice**.'],
          es: ['Abra **Ventas › Agregar venta**.', 'Elija la fecha, el cliente y el local.', 'Agregue productos o servicios; ajuste cantidad, precio, descuento o impuesto por línea si su rol lo permite.', 'Guarde. El número de factura se asigna solo.', 'Para repetir una factura, use **Duplicar** en el menú de su fila en la lista: se abre una venta nueva con la fecha de hoy y el mismo cliente, local y líneas (también facturas pagadas); arriba indica qué factura copia. Revísela y guarde.', 'Al editar una factura, arriba se ve su número (**Editar venta · INV_…**), cliente, fecha, total, pagado y saldo, su estado y estado de pago, y **Ver factura**.'],
        },
      },
      {
        id: 'sales-job', module: 'service', perms: ['service_jobs'], paths: ['sales/create', 'sales'],
        title: { en: 'Add a service job from the sale', es: 'Agregar un trabajo de servicio desde la venta' },
        steps: {
          en: ['In the sale form press **Add Service Job**.', 'Fill in the work, date and technician: the job is linked to this invoice.', 'Pending and completed jobs show different icons.'],
          es: ['En el formulario de venta pulse **Agregar trabajo de servicio**.', 'Complete el trabajo, la fecha y el técnico: el trabajo queda vinculado a esta factura.', 'Los trabajos pendientes y completados se muestran con íconos distintos.'],
        },
      },
      {
        id: 'sales-pay', perms: ['payment_sales_add', 'payment_sales_view'], paths: ['sales'],
        title: { en: 'Payments, printing and email', es: 'Pagos, impresión y correo' },
        steps: {
          en: ['Open the sale (or its row menu).', '**Add payment**: amount, method and date.', 'Print it, or email it to the customer; each email is kept in the sale\'s history.'],
          es: ['Abra la venta (o el menú de su fila).', '**Agregar pago**: monto, método y fecha.', 'Imprímala o envíela por correo al cliente; cada correo queda en el historial de la venta.'],
        },
      },
    ],
  },

  // ------------------------------------------------------------------ POS
  {
    key: 'pos', module: 'pos', perms: ['Pos_view'], paths: ['pos', 'pos-sales'],
    title: { en: 'POS and cash register', es: 'Punto de venta y caja' },
    intro: {
      en: 'The point of sale for the counter. On a shared register each person sells with their own PIN.',
      es: 'El punto de venta del mostrador. En una caja compartida cada persona vende con su propio PIN.',
    },
    sections: [
      {
        id: 'pos-open', perms: ['register_open_close'], paths: ['pos'],
        title: { en: 'Open the register', es: 'Abrir la caja' },
        steps: {
          en: ['At the start of the day open **POS**.', 'Open the register with your PIN and the opening cash.'],
          es: ['Al empezar el día abra el **POS**.', 'Abra la caja con su PIN y el efectivo inicial.'],
        },
      },
      {
        id: 'pos-sell', paths: ['pos'],
        title: { en: 'Sell', es: 'Vender' },
        steps: {
          en: ['Enter your **PIN** so the sale is recorded for you.', 'Add the services and products; choose the customer (or Walk-in).', 'Take the payment; a sale can be split between cash and card.', 'Print or email the receipt.'],
          es: ['Ingrese su **PIN** para que la venta quede a su nombre.', 'Agregue los servicios y productos; elija el cliente (o Cliente de paso).', 'Cobre; una venta se puede dividir entre efectivo y tarjeta.', 'Imprima o envíe el recibo por correo.'],
        },
        note: {
          en: 'Commission pay counts the sales recorded under your PIN, or the bookings where you are the stylist.',
          es: 'La comisión cuenta las ventas registradas con su PIN, o las reservas donde usted es el estilista.',
        },
      },
      {
        id: 'pos-close', perms: ['register_open_close'], paths: ['pos'],
        title: { en: 'Close the register', es: 'Cerrar la caja' },
        steps: {
          en: ['At closing, close the register with your PIN and count the cash.', 'Compare with **Reports › Cash Register Report**.'],
          es: ['Al cierre, cierre la caja con su PIN y cuente el efectivo.', 'Compare con **Reportes › Reporte de caja**.'],
        },
      },
    ],
  },

  // ------------------------------------------------------------------ quotations
  {
    key: 'quotations', module: 'sales', perms: ['Quotations_view', 'Quotations_add'], paths: ['quotations'],
    title: { en: 'Quotations', es: 'Cotizaciones' },
    intro: { en: 'Prices you offer before the sale.', es: 'Precios que ofrece antes de la venta.' },
    sections: [
      {
        id: 'quote-add', perms: ['Quotations_add'], paths: ['quotations'],
        title: { en: 'Create and convert a quotation', es: 'Crear y convertir una cotización' },
        steps: {
          en: ['**Quotations › Add Quotation**: customer, items and prices.', 'Send it to the customer by email.', 'When accepted, open it and **Create sale**: the invoice is made from the quotation.'],
          es: ['**Cotizaciones › Agregar cotización**: cliente, ítems y precios.', 'Envíela por correo al cliente.', 'Cuando la acepten, ábrala y pulse **Crear venta**: la factura se hace desde la cotización.'],
        },
      },
    ],
  },

  // ------------------------------------------------------------------ sales contracts
  {
    key: 'contracts', module: 'sales', perms: ['sales_contracts_view'], paths: ['sales-contracts'],
    title: { en: 'Sales contracts (recurring billing)', es: 'Contratos de venta (facturación recurrente)' },
    intro: {
      en: 'Weekly or monthly services that bill themselves and schedule their visits.',
      es: 'Servicios semanales o mensuales que se facturan solos y programan sus visitas.',
    },
    sections: [
      {
        id: 'contract-add', perms: ['sales_contracts_add'], paths: ['sales-contracts'],
        title: { en: 'Create a contract', es: 'Crear un contrato' },
        steps: {
          en: ['**Sales › Sales Contracts › New**.', 'Pick the customer, the services, the frequency and the start date.', 'Send it for signature.', 'Murano creates each invoice and each scheduled visit on its date; you find them in the Sales list and in Daily Tasks.'],
          es: ['**Ventas › Contratos de venta › Nuevo**.', 'Elija el cliente, los servicios, la frecuencia y la fecha de inicio.', 'Envíelo para firma.', 'Murano crea cada factura y cada visita programada en su fecha; las encuentra en la lista de Ventas y en Tareas diarias.'],
        },
      },
    ],
  },

  // ------------------------------------------------------------------ customers
  {
    key: 'customers', module: 'customers', perms: ['Customers_view'], paths: ['customers', 'customers-with-login', 'customers-without-login'],
    title: { en: 'Customers', es: 'Clientes' },
    intro: {
      en: 'One customer = one email in your company. Matching never uses the phone and never crosses companies.',
      es: 'Un cliente = un correo en su empresa. Nunca se busca por teléfono ni entre empresas.',
    },
    sections: [
      {
        id: 'cust-add', perms: ['Customers_add'], paths: ['customers/create', 'customers'],
        title: { en: 'Add a customer', es: 'Agregar un cliente' },
        steps: {
          en: ['Search by email first to avoid duplicates.', '**People › Customers › Add**: name, email, phone, address.', 'With an email the customer gets a login to the customer portal (invoices, payments, bookings).', 'Customers can\'t delete their portal account themselves: the company admin turns portal access off from **People › Customers** (portal option in the list or in the customer\'s form).'],
          es: ['Busque primero por correo para no duplicar.', '**Personas › Clientes › Agregar**: nombre, correo, teléfono, dirección.', 'Con correo, el cliente recibe acceso al portal de clientes (facturas, pagos, reservas).', 'El cliente no puede eliminar su cuenta del portal: el administrador de la empresa desactiva el acceso desde **Personas › Clientes** (opción de portal en la lista o en el formulario del cliente).'],
        },
      },
      {
        id: 'cust-details', paths: ['customers'],
        title: { en: 'Customer details', es: 'Ficha del cliente' },
        steps: {
          en: ['Open a customer to see sales, payments, balance and, when your company uses them, bookings, service jobs and emails.'],
          es: ['Abra un cliente para ver ventas, pagos, saldo y, si su empresa los usa, reservas, trabajos de servicio y correos.'],
        },
      },
      {
        id: 'cust-import', perms: ['customers_import'], paths: ['customers/import'],
        title: { en: 'Import customers', es: 'Importar clientes' },
        steps: {
          en: ['**People › Import Customers**: download the sample file, fill it in and upload it.', 'Rows with an email that already exists in your company update that customer instead of creating a new one.'],
          es: ['**Personas › Importar clientes**: descargue el archivo de ejemplo, complételo y súbalo.', 'Las filas con un correo que ya existe en su empresa actualizan ese cliente en vez de crear uno nuevo.'],
        },
      },
    ],
  },

  // ------------------------------------------------------------------ purchases & suppliers
  {
    key: 'purchases', module: 'purchases', perms: ['Purchases_view', 'Suppliers_view'], paths: ['purchases', 'purchase-returns', 'suppliers'],
    title: { en: 'Purchases and suppliers', es: 'Compras y proveedores' },
    intro: { en: 'What you buy and who you buy it from.', es: 'Lo que compra y a quién se lo compra.' },
    sections: [
      {
        id: 'sup-add', perms: ['Suppliers_add'], paths: ['suppliers'],
        title: { en: 'Add a supplier', es: 'Agregar un proveedor' },
        steps: {
          en: ['**People › Suppliers › Add**: name, email and phone.', 'With an email the supplier gets a portal login to see their purchase orders and payments.'],
          es: ['**Personas › Proveedores › Agregar**: nombre, correo y teléfono.', 'Con correo, el proveedor recibe acceso al portal para ver sus órdenes de compra y pagos.'],
        },
      },
      {
        id: 'pur-add', perms: ['Purchases_add'], paths: ['purchases'],
        title: { en: 'Record a purchase', es: 'Registrar una compra' },
        steps: {
          en: ['**Purchases › Add Purchase**: supplier, location and items.', 'When the goods arrive mark it received: stock goes up.', 'Add the payment to the supplier from the purchase.'],
          es: ['**Compras › Agregar compra**: proveedor, local e ítems.', 'Cuando llegue la mercadería márquela como recibida: el inventario sube.', 'Registre el pago al proveedor desde la compra.'],
        },
      },
    ],
  },

  // ------------------------------------------------------------------ products
  {
    key: 'products', module: 'products', perms: ['products_view'], paths: ['products', 'categories', 'subcategories', 'brands', 'units', 'adjustments', 'transfers', 'damages'],
    title: { en: 'Products and services', es: 'Productos y servicios' },
    intro: { en: 'What you sell, its prices and its stock in each location.', es: 'Lo que vende, sus precios y su inventario en cada local.' },
    sections: [
      {
        id: 'prod-add', perms: ['products_add'], paths: ['products/create', 'products'],
        title: { en: 'Add a product or service', es: 'Agregar un producto o servicio' },
        steps: {
          en: ['**Products › Add Product** (or **+** next to the product search in a sale, to add it right there).', 'Type or pick the **main service / group** — a new one is kept as you type. Then choose **what you are adding**: the main item only, or a **new sub-item under it** (also under an existing one). **"Will be saved as"** shows the final name and the items already in that group; a duplicate is warned.', 'Choose the type (product with stock, or service), category and price.', 'For products set the cost, unit and alert quantity.', 'Leave the product **Tax** at 0% when your invoices use the company\'s Order Tax (System Settings › Defaults › Default Tax): otherwise that product is taxed twice. New products start at 0%.', 'Save; print barcodes from **Products › Print Barcode**.'],
          es: ['**Productos › Agregar producto** (o **+** junto a la búsqueda de productos en una venta, para agregarlo ahí mismo).', 'Escriba o elija el **servicio principal / grupo** — uno nuevo se conserva mientras escribe. Luego elija **qué está agregando**: solo el principal, o un **nuevo sub-servicio debajo** (también bajo uno existente). **"Will be saved as"** muestra el nombre final y lo que ya hay en ese grupo; avisa si se repite.', 'Elija el tipo (producto con inventario o servicio), categoría y precio.', 'Para productos indique costo, unidad y cantidad de alerta.', 'Deje el **Impuesto** del producto en 0% cuando sus facturas usan el Impuesto de la orden de la empresa (Configuración › Predeterminados › Impuesto predeterminado): si no, ese producto paga impuesto dos veces. Los productos nuevos empiezan en 0%.', 'Guarde; imprima códigos de barras en **Productos › Imprimir código de barras**.'],
        },
      },
      {
        id: 'prod-stock', perms: ['count_stock', 'adjustment_add', 'transfer_add'], paths: ['products/count-stock', 'adjustments', 'transfers'],
        title: { en: 'Stock count, adjustments and transfers', es: 'Conteo, ajustes y transferencias' },
        steps: {
          en: ['**Count Stock**: count a location and let Murano show the differences.', '**Stock Adjustment**: correct the quantity with a reason.', '**Stock Transfers**: move stock between locations.'],
          es: ['**Conteo de inventario**: cuente un local y Murano muestra las diferencias.', '**Ajuste de inventario**: corrija la cantidad con un motivo.', '**Transferencias**: mueva inventario entre locales.'],
        },
      },
    ],
  },

  // ------------------------------------------------------------------ bookings
  {
    key: 'bookings', module: 'bookings', perms: ['bookings'], paths: ['bookings'],
    title: { en: 'Bookings', es: 'Reservas' },
    intro: {
      en: 'Appointments by staff member and time. Staff see their own bookings; roles with "other staff" see everyone\'s.',
      es: 'Citas por persona y horario. El personal ve sus propias reservas; los roles con "otro personal" ven las de todos.',
    },
    sections: [
      {
        id: 'book-cal', paths: ['bookings/calendar', 'bookings'],
        title: { en: 'Use the calendar', es: 'Usar el calendario' },
        steps: {
          en: ['**Booking Management › Calendar View**: switch between day, week and month.', 'Click an empty slot to add a booking: customer, service, staff member and time.', 'Drag a booking to move it, or open it to edit or cancel.', 'When the customer is served, charge the booking at the POS.'],
          es: ['**Reservas › Calendario**: cambie entre día, semana y mes.', 'Haga clic en un espacio libre para agregar una reserva: cliente, servicio, persona y hora.', 'Arrastre una reserva para moverla, o ábrala para editarla o cancelarla.', 'Cuando atienda al cliente, cobre la reserva en el POS.'],
        },
      },
      {
        id: 'book-confirm', paths: ['bookings'],
        title: { en: 'Confirm online bookings', es: 'Confirmar reservas en línea' },
        steps: {
          en: ['Bookings made from your website arrive as **Pending** and the admins get an email.', 'Open the booking and **Confirm** it.', 'Only then the customer receives the confirmation email (if they gave an email).'],
          es: ['Las reservas hechas desde su sitio web llegan como **Pendiente** y los administradores reciben un correo.', 'Abra la reserva y pulse **Confirmar**.', 'Recién entonces el cliente recibe el correo de confirmación (si dejó su correo).'],
        },
      },
      {
        id: 'book-online', perms: ['bookings'], paths: ['bookings/online'],
        title: { en: 'Online booking on your websites', es: 'Reservas en línea en sus sitios web' },
        steps: {
          en: ['**Booking Management › Online Booking**.', 'Set the look: show or hide the logo, background color.', 'In **Websites & codes** add each website that shows the form; copy its code: embedded form, link or button.', '"Exit" and "Close" send the customer back to that website.'],
          es: ['**Reservas › Reservas en línea**.', 'Ajuste el aspecto: mostrar u ocultar el logo, color de fondo.', 'En **Sitios web y códigos** agregue cada sitio que muestra el formulario; copie su código: formulario insertado, enlace o botón.', '"Salir" y "Cerrar" devuelven al cliente a ese sitio.'],
        },
        note: {
          en: 'Customers book in 3 steps and only the name is required. Limit: 5 bookings every 10 minutes per connection; from the 4th booking in 24 hours an email and the "I agree" link are required.',
          es: 'El cliente reserva en 3 pasos y solo el nombre es obligatorio. Límite: 5 reservas cada 10 minutos por conexión; desde la 4.ª reserva en 24 horas se exige correo y el enlace "Acepto".',
        },
      },
      {
        id: 'book-trays', perms: ['trays'], paths: ['bookings/trays'],
        title: { en: 'Trays', es: 'Bandejas' },
        steps: {
          en: ['**Booking Management › Trays** groups the bookings waiting for an action (to confirm, to charge).'],
          es: ['**Reservas › Bandejas** agrupa las reservas que esperan una acción (por confirmar, por cobrar).'],
        },
      },
    ],
  },

  // ------------------------------------------------------------------ service
  {
    key: 'service', module: 'service', perms: ['daily_tasks', 'service_jobs'], paths: ['service'],
    title: { en: 'Service jobs and Daily Tasks', es: 'Trabajos de servicio y Tareas diarias' },
    intro: {
      en: 'Field work: jobs for crews and technicians, linked to the customer and the invoice.',
      es: 'Trabajo de campo: trabajos para cuadrillas y técnicos, vinculados al cliente y a la factura.',
    },
    sections: [
      {
        id: 'daily', perms: ['daily_tasks'], paths: ['service/daily'],
        title: { en: 'Daily Tasks: do your jobs', es: 'Tareas diarias: haga sus trabajos' },
        steps: {
          en: ['Daily Tasks lists your jobs for today; switch to the week to plan ahead.', 'On arrival tap **Start**.', 'When finished tap **Complete**, write a short note and enter the **hours worked**.'],
          es: ['Tareas diarias muestra sus trabajos de hoy; cambie a la semana para planificar.', 'Al llegar pulse **Iniciar**.', 'Al terminar pulse **Completar**, escriba una nota breve e ingrese las **horas trabajadas**.'],
        },
        note: {
          en: 'Weekly pay uses the hours of the jobs you COMPLETE that week: complete every job the same day.',
          es: 'La planilla semanal usa las horas de los trabajos que COMPLETA esa semana: complete cada trabajo el mismo día.',
        },
      },
      {
        id: 'daily-assign', perms: ['daily_tasks_all', 'daily_tasks_assign'], paths: ['service/daily'],
        title: { en: 'Daily Tasks: every crew and assigning', es: 'Tareas diarias: todas las cuadrillas y asignación' },
        steps: {
          en: ['With "all tasks" you see every crew\'s jobs.', 'With "assign" choose the technician on each job from the same screen.'],
          es: ['Con "todas las tareas" ve los trabajos de todas las cuadrillas.', 'Con "asignar" elija el técnico de cada trabajo desde la misma pantalla.'],
        },
      },
      {
        id: 'jobs', perms: ['service_jobs'], paths: ['service/jobs', 'service/history'],
        title: { en: 'Create and follow service jobs', es: 'Crear y seguir trabajos de servicio' },
        steps: {
          en: ['**Service & Maintenance › All Jobs › Create**: customer, date, work to do, technician or crew.', 'Record the equipment used; the job cost adds labor hours and equipment.', 'Follow unpaid work in **Awaiting Payment** and the history in **Maintenance History**.'],
          es: ['**Servicio y mantenimiento › Todos los trabajos › Crear**: cliente, fecha, trabajo, técnico o cuadrilla.', 'Registre el equipo usado; el costo del trabajo suma horas de mano de obra y equipo.', 'Siga lo impago en **Pendiente de pago** y el historial en **Historial de mantenimiento**.'],
        },
      },
      {
        id: 'techs', perms: ['service_jobs'], paths: ['service/technicians', 'service/checklists', 'service/checklist-categories'],
        title: { en: 'Operators and checklists', es: 'Operarios y listas de control' },
        steps: {
          en: ['**Service Setup › Operators**: the people who can receive jobs (linked to their employee).', '**Checklist Items**: the steps a job must tick before it is complete.'],
          es: ['**Configuración de servicio › Operarios**: las personas que pueden recibir trabajos (vinculadas a su ficha de empleado).', '**Ítems de control**: los pasos que un trabajo debe marcar antes de completarse.'],
        },
      },
    ],
  },

  // ------------------------------------------------------------------ HRM
  {
    key: 'hrm', module: 'hrm', perms: ['view_employee', 'attendance', 'leave', 'payroll', 'holiday', 'contracts'], paths: ['hrm', 'contracts'],
    title: { en: 'HR: employees, attendance and payroll', es: 'RR. HH.: empleados, asistencia y planillas' },
    intro: {
      en: 'Every employee has a login, created automatically. Each tab of the employee page has its own view / edit permission.',
      es: 'Cada empleado tiene un usuario, creado automáticamente. Cada pestaña de la ficha del empleado tiene su propio permiso de ver / editar.',
    },
    sections: [
      {
        id: 'emp-add', perms: ['add_employee'], paths: ['hrm/employees/create', 'hrm/employees'],
        title: { en: 'Add an employee', es: 'Agregar un empleado' },
        steps: {
          en: ['**HRM › Employees › Add**: personal data, department, designation, shift.', 'Set the pay type: **hourly** (rate) or **commission** (%), and the date it starts.', 'The login is created automatically; it is active when the company has a default employee password (System Settings › Defaults › Users).'],
          es: ['**RR. HH. › Empleados › Agregar**: datos personales, área, cargo, turno.', 'Indique el tipo de pago: **por hora** (tarifa) o **comisión** (%), y desde qué fecha.', 'El usuario se crea solo; queda activo si la empresa tiene una contraseña predeterminada para empleados (Configuración › Predeterminados › Usuarios).'],
        },
      },
      {
        id: 'emp-details', perms: ['view_employee'], paths: ['hrm/employees'],
        title: { en: 'The employee page', es: 'La ficha del empleado' },
        steps: {
          en: ['Tabs: basic data, company, social, experience, accounts, user (login), production, payments and **Assets assigned**.', 'You see only the tabs your role allows; without "all employees" you see only your own record.', '**Print profile** prints the whole record with the photo.'],
          es: ['Pestañas: datos básicos, empresa, redes, experiencia, cuentas, usuario (acceso), producción, pagos y **Activos asignados**.', 'Solo ve las pestañas que su rol permite; sin "todos los empleados" solo ve su propia ficha.', '**Imprimir perfil** imprime toda la ficha con la foto.'],
        },
      },
      {
        id: 'emp-remove', perms: ['delete_employee'], paths: ['hrm/employees'],
        title: { en: 'Remove an employee', es: 'Dar de baja a un empleado' },
        steps: {
          en: ['Open the employee › **Remove** and set the leaving date.', 'In one step Murano disables the login and signs it out everywhere, unassigns open jobs, upcoming bookings and tickets, and lists the assets to collect.', 'The history (sales, jobs, attendance) is kept.'],
          es: ['Abra el empleado › **Dar de baja** e indique la fecha de salida.', 'En un solo paso Murano deshabilita el usuario y cierra sus sesiones, libera trabajos abiertos, reservas próximas y tickets, y lista los activos por recoger.', 'El historial (ventas, trabajos, asistencia) se conserva.'],
        },
      },
      {
        id: 'attendance', perms: ['attendance'], paths: ['hrm/attendance'],
        title: { en: 'Attendance', es: 'Asistencia' },
        steps: {
          en: ['**HRM › Attendance** lists every clock-in and clock-out.', 'Add or correct a record when someone forgot to clock.', 'Totals by person: **Reports › Attendance Summary**.'],
          es: ['**RR. HH. › Asistencia** muestra cada entrada y salida.', 'Agregue o corrija un registro cuando alguien olvidó marcar.', 'Totales por persona: **Reportes › Resumen de asistencia**.'],
        },
      },
      {
        id: 'leaves', perms: ['leave'], paths: ['hrm/leaves', 'hrm/leave-types'],
        title: { en: 'Leave requests', es: 'Solicitudes de permiso' },
        steps: {
          en: ['**HRM › Leave request › Add**: employee, type, dates and reason.', 'Approve or reject it from the list.'],
          es: ['**RR. HH. › Permisos › Agregar**: empleado, tipo, fechas y motivo.', 'Apruébelo o recházelo desde la lista.'],
        },
      },
      {
        id: 'payroll', perms: ['payroll'], paths: ['hrm/payrolls'],
        title: { en: 'Weekly payroll', es: 'Planilla semanal' },
        steps: {
          en: ['**HRM › Payroll › Generate weekly payroll** and choose the week.', 'Hourly: hours of the jobs completed that week × rate. Commission: that week\'s sales × %.', 'Check each line and mark it paid: it is posted to accounting automatically.'],
          es: ['**RR. HH. › Planillas › Generar planilla semanal** y elija la semana.', 'Por hora: horas de los trabajos completados esa semana × tarifa. Comisión: ventas de la semana × %.', 'Revise cada línea y márquela pagada: se registra en la contabilidad automáticamente.'],
        },
      },
    ],
  },

  // ------------------------------------------------------------------ email office
  {
    key: 'email_office', module: 'email_office', perms: ['email_office_view', 'email_office_manage'], paths: ['email-office'],
    title: { en: 'Email Office', es: 'Email Office' },
    intro: {
      en: 'The company mailbox inside Murano, linked to customers, suppliers and payments.',
      es: 'El correo de la empresa dentro de Murano, vinculado a clientes, proveedores y pagos.',
    },
    sections: [
      {
        id: 'mail-read', perms: ['email_office_view'], paths: ['email-office/received', 'email-office/sent'],
        title: { en: 'Read the mailbox', es: 'Leer el correo' },
        steps: {
          en: ['**Email Office › Received** or **Sent**.', 'Open a message to read it and its attachments; it is linked to the customer or supplier with that address.', 'Payment emails (for example Zelle) are detected and can be matched to the customer.'],
          es: ['**Email Office › Recibidos** o **Enviados**.', 'Abra un mensaje para leerlo con sus adjuntos; queda vinculado al cliente o proveedor con esa dirección.', 'Los correos de pago (por ejemplo Zelle) se detectan y se pueden asociar al cliente.'],
        },
      },
      {
        id: 'mail-assign', perms: ['email_office_view'], paths: ['email-office/received', 'email-office/sent'],
        title: { en: 'Emails assigned to customers by themselves', es: 'Correos asignados solos a los clientes' },
        steps: {
          en: [
            '**Received**: assigned to the customer whose email (or additional email) is the sender.',
            '**Sent**: assigned to the first recipient that is a customer — the To addresses, then CC. Invoices and reminders sent from a sale go to that sale\'s customer even when sent to another address.',
            'When you add or change a customer\'s email (People › Customers), their emails not assigned yet are assigned at once.',
            'Assigning one email assigns **every email with the same address** — received from it and sent to it (To or CC) — to that customer. With "Save this address on the customer" ticked (the default) the address is also saved on the customer, so future emails go to them by themselves.',
          ],
          es: [
            '**Recibidos**: se asignan al cliente cuyo email (o email adicional) es el remitente.',
            '**Enviados**: se asignan al primer destinatario que sea cliente — las direcciones de Para y luego las de CC. Las facturas y recordatorios enviados desde una venta van al cliente de esa venta aunque se envíen a otra dirección.',
            'Al agregar o cambiar el email de un cliente (Personas › Clientes), sus correos sin asignar se le asignan al momento.',
            'Al asignar un correo se asignan **todos los correos con la misma dirección** — recibidos de ella y enviados a ella (Para o CC) — a ese cliente. Con "Save this address on the customer" marcado (viene así) la dirección también se guarda en el cliente, y los correos futuros le llegan solos.',
          ],
        },
        note: {
          en: 'Matching is by email only and within your company; a contact email counts only when a single customer has it.',
          es: 'La asignación es solo por email y dentro de su empresa; un email de contacto cuenta solo si lo tiene un único cliente.',
        },
      },
      {
        id: 'mail-settings', perms: ['email_office_manage'], paths: ['email-office/settings'],
        title: { en: 'Mailbox settings and rules', es: 'Configuración y reglas del correo' },
        steps: {
          en: ['**Email Office › Settings**: the mailbox account, folders and import.', 'Rules sort incoming mail into bills, claims or general.'],
          es: ['**Email Office › Configuración**: la cuenta de correo, carpetas e importación.', 'Las reglas clasifican el correo entrante en facturas, reclamos o general.'],
        },
      },
    ],
  },

  // ------------------------------------------------------------------ website
  {
    key: 'website', module: 'website', perms: ['Store_settings_view'], paths: ['website'],
    title: { en: 'Website', es: 'Sitio web' },
    intro: {
      en: 'The company website, with its own address. It can use our website theme, a one-page template or an imported website.',
      es: 'El sitio web de la empresa, con su propia dirección. Puede usar nuestro tema, una plantilla de una página o un sitio web importado.',
    },
    sections: [
      {
        id: 'web-onepage', paths: ['website/front-page'],
        title: { en: 'One-page template', es: 'Plantilla de una página' },
        steps: {
          en: [
            '**Website › Front page › One-page template**: a single page with sections (header, services, about, gallery, portfolio, reviews, booking, contact) and a menu that scrolls to each one.',
            '**Design**: five designs; the one for your business type is marked *Recommended*. Switching design keeps your texts and pictures; **Start over** puts the starting texts back. Pick the **Main colour** and whether to **Show prices**.',
            '**Sections**: turn each one on or off, change the order with the arrows, and write the menu label, title, text and button in English and Spanish (switch at the top). An empty menu label keeps the section out of the menu.',
            'Services start from your products and services; edit, add or remove them, with a price and a picture. Gallery and Portfolio show the pictures of those modules; Contact shows the company\'s phone, email, address and a map.',
            'Add only real reviews from real customers. **Extra menu links** add your store, a page or another site to the menu.',
            '**Save**, then **Preview** to see it before applying. **Use the one-page template** makes it your front page; visitors switch EN / ES in the menu.',
          ],
          es: [
            '**Sitio web › Portada › Plantilla de una página**: una sola página con secciones (portada, servicios, nosotros, galería, portafolio, opiniones, reservas, contacto) y un menú que se desplaza a cada una.',
            '**Diseño**: cinco diseños; el de su tipo de negocio aparece como *Recomendada*. Al cambiar de diseño se conservan sus textos e imágenes; **Empezar de nuevo** vuelve a los textos iniciales. Elija el **Color principal** y si quiere **Mostrar precios**.',
            '**Secciones**: active o desactive cada una, cambie el orden con las flechas y escriba la etiqueta del menú, el título, el texto y el botón en inglés y español (cambie arriba). Sin etiqueta de menú la sección no aparece en el menú.',
            'Los servicios empiezan con sus productos y servicios; edítelos, agréguelos o quítelos, con precio e imagen. Galería y Portafolio muestran las imágenes de esos módulos; Contacto muestra el teléfono, correo, dirección y un mapa de la empresa.',
            'Agregue solo opiniones reales de clientes reales. **Enlaces adicionales del menú** agregan su tienda, una página u otro sitio al menú.',
            '**Guardar** y luego **Vista previa** para verla antes de aplicarla. **Usar la plantilla de una página** la convierte en su portada; los visitantes cambian EN / ES en el menú.',
          ],
        },
      },
      {
        id: 'web-settings', paths: ['website/settings', 'website/front-page'],
        title: { en: 'Website settings', es: 'Configuración del sitio' },
        steps: {
          en: ['**Website Management › Website Settings**: Front page, Homepage blocks and Branding (website address, name, logo).', 'With our theme you also get Hero, SEO, Top bar & footer and Social links; an imported website brings its own.'],
          es: ['**Sitio web › Configuración**: Portada, Bloques de inicio y Marca (dirección del sitio, nombre, logo).', 'Con nuestro tema también tiene Portada principal, SEO, Barra superior y pie, y Redes; un sitio importado trae los suyos.'],
        },
      },
      {
        id: 'web-pages', paths: ['website/pages'],
        title: { en: 'Edit pages and sections', es: 'Editar páginas y secciones' },
        steps: {
          en: ['**Website › Pages** lists each page of the imported website.', 'Edit texts, pictures and links, or open **Sections** to edit a whole block.', 'In a section, **Use our booking calendar** puts Murano\'s booking form in place of an old one.'],
          es: ['**Sitio web › Páginas** lista cada página del sitio importado.', 'Edite textos, imágenes y enlaces, o abra **Secciones** para editar un bloque completo.', 'En una sección, **Usar nuestro calendario de reservas** pone el formulario de reservas de Murano en lugar del anterior.'],
        },
      },
      {
        id: 'web-import', paths: ['website/front-page'],
        title: { en: 'Import a website', es: 'Importar un sitio web' },
        steps: {
          en: ['**Front page › Import**: from the site\'s address or its files.', 'Or **start from a ready-made website**: complete designs made by KcolTech — **Preview** one, then **Use this**; change its texts, pictures and links in **Website › Pages**.', 'Before replacing the current site, Murano saves it in **Saved websites** (Preview, Use this, Delete).'],
          es: ['**Portada › Importar**: desde la dirección del sitio o sus archivos.', 'O **empiece con un sitio web listo**: diseños completos hechos por KcolTech — **Ver** uno y luego **Usar este**; cambie sus textos, imágenes y enlaces en **Sitio web › Páginas**.', 'Antes de reemplazar el sitio actual, Murano lo guarda en **Sitios guardados** (Ver, Usar este, Eliminar).'],
        },
      },
    ],
  },

  // ------------------------------------------------------------------ gallery
  {
    key: 'gallery', module: 'gallery', perms: ['Store_settings_view'], paths: ['gallery'],
    title: { en: 'Gallery', es: 'Galería' },
    intro: { en: 'Pictures for the website\'s gallery page and block.', es: 'Imágenes para la página y el bloque de galería del sitio web.' },
    sections: [
      {
        id: 'gal', paths: ['gallery'],
        title: { en: 'Manage pictures', es: 'Administrar imágenes' },
        steps: {
          en: ['Upload pictures, drag them into order and show or hide each one.', 'Or import them from the imported website.'],
          es: ['Suba imágenes, ordénelas arrastrando y muestre u oculte cada una.', 'O impórtelas desde el sitio web importado.'],
        },
      },
    ],
  },

  // ------------------------------------------------------------------ portfolio
  {
    key: 'portfolio', module: 'portfolio', perms: ['portfolio_manage'], paths: ['portfolio'],
    title: { en: 'Portfolio', es: 'Portafolio' },
    intro: { en: 'Your finished work, shown on the website.', es: 'Sus trabajos terminados, mostrados en el sitio web.' },
    sections: [
      {
        id: 'port', paths: ['portfolio'],
        title: { en: 'Projects, photos and messages', es: 'Proyectos, fotos y mensajes' },
        steps: {
          en: ['**Portfolio › Photos**: add works with photos, sliders or videos and a category.', 'A work can have a **Website link**: its page then shows a **Visit website** button.', '**Home sections** and **Settings**: what the portfolio page shows.', '**Messages**: what visitors send from it.'],
          es: ['**Portafolio › Fotos**: agregue trabajos con fotos, carruseles o videos y una categoría.', 'Un trabajo puede tener un **Enlace del sitio web**: su página muestra entonces un botón **Visitar el sitio web**.', '**Secciones de inicio** y **Configuración**: lo que muestra la página del portafolio.', '**Mensajes**: lo que envían los visitantes.'],
        },
      },
    ],
  },

  // ------------------------------------------------------------------ school
  {
    key: 'school', module: 'school',
    perms: ['school_dashboard', 'school_students_view', 'school_teachers', 'school_academics', 'school_enrollment', 'school_attendance', 'school_exams', 'school_timetable', 'school_fees', 'school_reports'],
    paths: ['school'],
    title: { en: 'School Management', es: 'Gestión escolar' },
    intro: {
      en: 'A school\'s year: grades and sections, subjects, teachers, students and their enrolment, the timetable, the daily register, exams with marks and report cards, fees and payments — each company with its own school.',
      es: 'El año de una escuela: grados y secciones, materias, profesores, estudiantes y su matrícula, el horario, la asistencia diaria, exámenes con notas y libretas, pensiones y cobros — cada empresa con su propia escuela.',
    },
    sections: [
      {
        id: 'school-setup', perms: ['school_academics', 'school_timetable'], paths: ['school/academics', 'school/timetable'],
        title: { en: 'Year, classes and timetable', es: 'Año, clases y horario' },
        steps: {
          en: ['**Academic Setup**: create the academic year (mark it current), the classes (grades), their **sections** (classroom and homeroom teacher) and the subjects.', '**Timetable**: pick a class and section and add each period (subject, teacher, day, time, room); it refuses a section or teacher booked twice at the same time.'],
          es: ['**Configuración académica**: cree el año académico (márquelo como actual), las clases (grados), sus **secciones** (aula y tutor) y las materias.', '**Horario**: elija una clase y sección y agregue cada hora (materia, profesor, día, hora, aula); no permite una sección o un profesor dos veces a la misma hora.'],
        },
      },
      {
        id: 'school-daily', perms: ['school_students_view', 'school_enrollment', 'school_attendance'], paths: ['school/students', 'school/enrollments', 'school/attendance'],
        title: { en: 'Students, enrolment and attendance', es: 'Estudiantes, matrícula y asistencia' },
        steps: {
          en: ['**Students → Admit student** with the guardian\'s data; **Enrolment** puts each student in a class and section for the year (and promotes them at year end).', '**Attendance** opens on the **whole school** for a date: every class and section with its roll, whether the register was taken and the counts (and the last 30 days); when today\'s isn\'t taken yet, **See <last day>** shows the last one. Click a class (or pick it above) to **take the register** (present, absent, late, excused, half day); **Whole school** goes back. The dashboard shows today\'s rate and the last 14 days.'],
          es: ['**Estudiantes → Matricular estudiante** con los datos del apoderado; **Matrícula** ubica a cada estudiante en una clase y sección del año (y lo promueve al terminar el año).', '**Asistencia** abre con **todo el colegio** en una fecha: cada clase y sección con sus matriculados, si ya se pasó lista y los conteos (y los últimos 30 días); si hoy aún no se pasó lista, **Ver el <último día>** muestra el último. Haga clic en una clase (o elíjala arriba) para **pasar lista** (presente, ausente, tarde, justificado, medio día); **Todo el colegio** vuelve al resumen. El panel muestra la asistencia de hoy y de los últimos 14 días.'],
        },
      },
      {
        id: 'school-exams-fees', perms: ['school_exams', 'school_fees', 'school_reports'], paths: ['school/exams', 'school/fees', 'school/reports'],
        title: { en: 'Exams, fees and reports', es: 'Exámenes, pensiones y reportes' },
        steps: {
          en: ['**Exams**: create the exam (Term 1–4 for the four bimestres), add its papers (subject, date, max and pass marks), enter the marks and publish. Marks out of 20 get a letter: 20 A+, 19 A, 18 B+, 17 B, 16 C+, 15 C, 14 D+, 13 D, 12 E+, 11 E, 10 or less F.', '**Students › a student › Report card** (Libreta): one column per term, each subject\'s final average and letter, the term averages, absences / late per term, the **weighted average** (Σ subject average × weight ÷ Σ weights) and the final situation. Marks below the pass mark in **red**, from the pass mark up in **blue**. **Print** gives an A4 page with the school\'s name, address and logo, signatures and the issue date; **Download PDF** saves the same page as a file.', '**Students** list: cards on top with the best student (and the next two), best grade, best section, the school average, how many students are below the pass mark, the best attendance and the most absences; the **Performance** column (weighted average of the year + each term\'s average, red / blue) sorts the list from the best student down; **Report card › View** opens the Libreta in a popup with Download PDF and Print.', '**Fees**: the fee structure (tuition, transport…), **Bill a class** or a single invoice, and **Take payment**; overdue invoices show on the dashboard.', '**Reports**: enrolment, attendance, low attendance, exam performance, fee collection, fee defaulters and teacher workload, with Excel / PDF export.', 'Every company has its own school: an Admin only sees their company\'s years, classes, teachers, students, exams and fees. **Demo data** (System Settings → Demo data) creates a complete school in the company\'s language, between the school year\'s start and end dates you give it (default: this year, 1 March – 15 December): 4 grades × 2 sections, 7 subjects, 14 teachers, 48 students (some often absent, some with low marks), timetable, daily attendance, four term exams (the ones already over with marks) and fees.'],
          es: ['**Exámenes**: cree el examen (Periodo 1–4 para los cuatro bimestres), agregue sus pruebas (materia, fecha, nota máxima y mínima), registre las notas y publíquelo. Las notas sobre 20 llevan letra: 20 A+, 19 A, 18 B+, 17 B, 16 C+, 15 C, 14 D+, 13 D, 12 E+, 11 E, 10 o menos F.', '**Estudiantes › un estudiante › Libreta**: una columna por bimestre, el promedio final y la letra de cada área, el promedio de cada bimestre, faltas / tardanzas por bimestre, el **promedio ponderado** (Σ promedio del área × peso ÷ Σ pesos) y la situación final. Notas debajo de la aprobatoria en **rojo**, desde la aprobatoria en **azul**. **Imprimir** da una hoja A4 con el nombre, dirección y logo del colegio, firmas y fecha de emisión; **Descargar PDF** guarda la misma hoja como archivo.', 'Lista de **Estudiantes**: tarjetas arriba con el mejor alumno (y los dos siguientes), el mejor grado, la mejor sección, el promedio del colegio, cuántos alumnos están debajo de la aprobatoria, la mejor asistencia y quien tiene más faltas; la columna **Rendimiento** (promedio ponderado del año + promedio de cada bimestre, en rojo / azul) ordena la lista del mejor alumno hacia abajo; **Libreta › Ver** abre la libreta en una ventana con Descargar PDF e Imprimir.', '**Pensiones**: la estructura (enseñanza, movilidad…), **Facturar una clase** o una factura suelta, y **Registrar pago**; las vencidas se ven en el panel.', '**Reportes**: matrícula, asistencia, baja asistencia, rendimiento en exámenes, cobro de pensiones, morosos y carga horaria de profesores, con exportación a Excel / PDF.', 'Cada empresa tiene su propia escuela: un Admin solo ve los años, clases, profesores, estudiantes, exámenes y pensiones de su empresa. **Datos de ejemplo** (Configuración del sistema → Datos de ejemplo) crea una escuela completa en el idioma de la empresa, entre las fechas de inicio y fin del año escolar que usted indique (por defecto: este año, 1 de marzo – 15 de diciembre): 4 grados × 2 secciones, 7 materias, 14 profesores, 48 estudiantes (algunos con muchas faltas y otros con notas bajas), horario, asistencia diaria, cuatro evaluaciones bimestrales (con notas las que ya pasaron) y pensiones.'],
        },
      },
    ],
  },
  // ------------------------------------------------------------------ hospital
  {
    key: 'hospital', module: 'hospital',
    perms: ['hms_dashboard', 'hms_patients_view', 'hms_doctors', 'hms_departments', 'hms_appointments', 'hms_visits', 'hms_wards', 'hms_admissions', 'hms_lab', 'hms_billing', 'hms_reports', 'hms_er', 'hms_pharmacy'],
    paths: ['hospital'],
    title: { en: 'Hospital Management', es: 'Gestión hospitalaria' },
    intro: {
      en: 'A clinic\'s whole day: patients (each one a customer), appointments, consultations, emergency room, pharmacy, lab, admissions, billing (each bill a sale) and payments — each company with its own doctors, beds and patients.',
      es: 'El día completo de una clínica: pacientes (cada uno es un cliente), citas, consultas, emergencias, farmacia, laboratorio, internaciones, facturación (cada factura es una venta) y cobros — cada empresa con sus propios médicos, camas y pacientes.',
    },
    sections: [
      {
        id: 'hms-setup', perms: ['hms_departments', 'hms_doctors', 'hms_wards', 'hms_lab'], paths: ['hospital/departments', 'hospital/doctors', 'hospital/wards', 'hospital/lab-tests'],
        title: { en: 'Set up the clinic', es: 'Configurar la clínica' },
        steps: {
          en: ['**Departments**, then **Doctors** (department, specialty, consultation fee; link the employee when they are staff).', '**Wards & Beds**: each ward with its type and daily rate, and its beds (a ward of type **Emergency room** holds the ER\'s beds).', '**Lab › Test catalogue**: each test with its price and normal range.'],
          es: ['**Departamentos** y luego **Médicos** (departamento, especialidad, honorario de consulta; vincule el empleado si es parte del personal).', '**Salas y camas**: cada sala con su tipo y tarifa diaria, y sus camas (una sala de tipo **Emergencias** tiene las camas de emergencia).', '**Laboratorio › Catálogo de pruebas**: cada prueba con su precio y rango normal.'],
        },
      },
      {
        id: 'hms-patients', perms: ['hms_patients_view', 'hms_appointments', 'hms_visits'], paths: ['hospital/patients', 'hospital/appointments', 'hospital/visits'],
        title: { en: 'Patients, appointments and consultations', es: 'Pacientes, citas y consultas' },
        steps: {
          en: ['**Patients › Register**: each patient gets a record number (MRN); **Patient record** shows the whole history. The list opens on **In the hospital** (an open admission or an open Emergency Room case, with their count); **No longer here** shows the rest; clicking the active button shows everyone.', '**Appointment or consultation?** The **appointment** is the booking (before): patient, doctor, date and time. The **consultation** is the care itself (during): what the doctor records when seeing the patient. A patient who books has an appointment; when the doctor sees them, the consultation is recorded. If they don\'t come, the appointment stays as No-show and there is no consultation.', '**Appointments**: patient, doctor, date and time, length, type (consultation, follow-up, procedure, emergency, teleconsult), reason and fee; status Scheduled → Confirmed → Arrived → Completed, or No-show / Cancelled. A doctor can\'t be booked twice at the same time. This is the schedule (the appointments board).', '**Consultations**: the clinical record of the visit — vital signs, complaint, examination, diagnosis, treatment plan, prescription and follow-up date. Open it from the appointment (completing it completes the appointment), or without one for a walk-in, an emergency or a teleconsult. The bill (fee + medicines, a sale) is made from it, and it stays in the patient\'s record.'],
          es: ['**Pacientes › Registrar**: cada paciente recibe un número de historia (MRN); **Historia del paciente** muestra todo su historial. La lista abre en **En el hospital** (internación abierta o caso abierto en Emergencias, con su cantidad); **Ya no están** muestra el resto; un clic en el botón activo muestra a todos.', '**¿Cita o consulta?** La **cita** es la reserva (antes): paciente, médico, fecha y hora. La **consulta** es la atención médica (durante): lo que el médico registra al atender al paciente. Un paciente que pide turno tiene una cita; cuando el médico lo atiende, se registra la consulta. Si no se presenta, la cita queda como "No asistió" y no hay consulta.', '**Citas**: paciente, médico, fecha y hora, duración, tipo (consulta, control, procedimiento, emergencia, teleconsulta), motivo y honorario; estado Programada → Confirmada → Llegó → Completada, o No asistió / Cancelada. Un médico no puede tener dos citas a la misma hora. Es la agenda (el tablero de citas).', '**Consultas**: el registro clínico de la atención — signos vitales, motivo, examen físico, diagnóstico, plan de tratamiento, receta y fecha de control. Se abre desde la cita (al completarla se completa la cita), o sin cita para quien llega sin turno, una emergencia o una teleconsulta. De ella sale la factura (honorario + medicinas, que es una venta) y queda en la historia del paciente.'],
        },
      },
      {
        id: 'hms-lab-adm', perms: ['hms_lab', 'hms_admissions'], paths: ['hospital/lab-orders', 'hospital/admissions'],
        title: { en: 'Lab and admissions', es: 'Laboratorio e internaciones' },
        steps: {
          en: ['**Lab orders**: order tests, then enter results; price and normal range are kept as they were when ordered.', '**Admissions**: **Admit** to a free bed, **Transfer** to another bed, **Discharge** (the bed is free again).'],
          es: ['**Pedidos de laboratorio**: pida pruebas y luego cargue los resultados; el precio y el rango normal quedan como estaban al pedirlas.', '**Internaciones**: **Internar** en una cama libre, **Trasladar** a otra cama, **Dar de alta** (la cama queda libre).'],
        },
      },
      {
        id: 'hms-er', perms: ['hms_er'], paths: ['hospital/er'],
        title: { en: 'Emergency Room', es: 'Emergencias' },
        steps: {
          en: ['The whole ER on one screen. **New arrival**: pick a registered patient or type a **new patient** (name, gender, birth date, phone — they get their record number), choose the **triage level** 1–5 (1 Resuscitation red … 5 Not urgent blue), the arrival (walk-in, ambulance, referral…), the chief complaint, pain 0–10 and the **vital signs**, and assign a **doctor** and an **ER bed** now or later.', 'The **board** has three columns — **Waiting**, **In treatment**, **Observation** — sorted by triage. Each card shows the time since arrival (in red when the patient waits longer than their triage level allows), allergies, vitals, doctor and bed. **Start treatment** stamps the waiting time; ✎ edits triage, vitals, doctor, bed and the working diagnosis.', '**Disposition** closes the case: **Discharged home**, **Admitted to hospital** (pick a free ward bed: the admission is created and that bed is taken), **Transferred**, **Left without being seen** or **Deceased**. The ER bed is freed and the visit (vitals, diagnosis) goes to the patient\'s record.', 'Top cards: waiting, in treatment, observation, arrivals today, average wait today and free ER beds. **History** lists the closed cases with dates, outcome and time in the ER. ER beds are the beds of a ward of type **Emergency room** (Wards & Beds). Permission: **Emergency Room**.'],
          es: ['Toda la emergencia en una sola pantalla. **Nuevo ingreso**: elija un paciente registrado o escriba un **paciente nuevo** (nombre, género, fecha de nacimiento, teléfono — recibe su número de historia), elija el **nivel de triaje** 1–5 (1 Reanimación rojo … 5 No urgente azul), la llegada (por sus medios, ambulancia, referido…), el motivo de consulta, el dolor 0–10 y los **signos vitales**, y asigne un **médico** y una **cama de emergencia** ahora o después.', 'El **tablero** tiene tres columnas — **En espera**, **En atención**, **En observación** — ordenadas por triaje. Cada tarjeta muestra el tiempo desde la llegada (en rojo cuando el paciente espera más de lo que permite su triaje), alergias, signos vitales, médico y cama. **Iniciar atención** registra el tiempo de espera; ✎ edita triaje, signos vitales, médico, cama y el diagnóstico presuntivo.', '**Destino / alta** cierra el caso: **Alta a domicilio**, **Internado en el hospital** (elija una cama libre de sala: se crea la internación y esa cama queda ocupada), **Transferido**, **Se retiró sin ser atendido** o **Fallecido**. La cama de emergencia queda libre y la atención (signos vitales, diagnóstico) pasa a la historia del paciente.', 'Tarjetas de arriba: en espera, en atención, en observación, ingresos de hoy, espera promedio de hoy y camas de emergencia libres. **Historial** lista los casos cerrados con fechas, destino y tiempo en emergencias. Las camas de emergencia son las de una sala de tipo **Emergencias** (Salas y camas). Permiso: **Emergency Room**.'],
        },
      },
      {
        id: 'hms-pharmacy', perms: ['hms_pharmacy'], paths: ['hospital/pharmacy'],
        title: { en: 'Pharmacy', es: 'Farmacia' },
        steps: {
          en: ['The medicines and supplies on hand: each product with its generic name, strength, form and pack, **Rx** when it needs a prescription, the **stock** in the warehouses you can see, the **next expiry** of its batches and the price.', 'Cards on top filter the list: in stock, **low stock** (at or below the product\'s alert quantity), **out of stock**, **expiring / expired** (batches expiring within 90 days or already expired) and the stock value at cost. Search by name, generic, code or manufacturer; filter by category or prescription only.', '**+** on a row shows its stock per warehouse and its batches. The list is read only: stock comes in with **Purchases** / **Adjustments** and goes out with **Sales**; the pharmacy fields are edited in **Products**. Permission: **Pharmacy**.'],
          es: ['Los medicamentos e insumos disponibles: cada producto con su nombre genérico, concentración, forma y presentación, **Rx** cuando necesita receta, el **stock** en los almacenes que usted puede ver, el **próximo vencimiento** de sus lotes y el precio.', 'Las tarjetas de arriba filtran la lista: con stock, **stock bajo** (igual o menor a la cantidad de alerta del producto), **sin stock**, **por vencer / vencidos** (lotes que vencen en 90 días o ya vencidos) y el valor del stock al costo. Busque por nombre, genérico, código o laboratorio; filtre por categoría o solo con receta.', '**+** en una fila muestra su stock por almacén y sus lotes. La lista es de solo lectura: el stock entra con **Compras** / **Ajustes** y sale con **Ventas**; los datos de farmacia se editan en **Productos**. Permiso: **Pharmacy**.'],
        },
      },
      {
        id: 'hms-billing', perms: ['hms_billing'], paths: ['hospital/invoices'],
        title: { en: 'Billing and payments', es: 'Facturación y cobros' },
        steps: {
          en: ['**Billing**: build the bill from a consultation (fee + medicines), an admission (nights × daily rate) or a lab order — or type the lines.', '**Every bill is a sale**: saving it creates its sale in **Sales** (the company\'s sale number, shown on the bill) for the patient\'s customer — every patient is a customer in **People › Customers**. Medicines on the bill leave the pharmacy stock; consultations, bed nights and lab tests go on the company\'s hospital service items. Changing or cancelling the bill changes or removes its sale.', '**Payment**: record what the patient pays; the invoice turns partial / paid by itself and the payment is the sale\'s payment. A payment larger than the balance is refused.', 'With **Automatic journal entries** on, Accounting gets the sale and its payments like any sale (one entry per bill).', 'Medicines are bought from **Suppliers** in **Purchases** (they raise the stock and the batches).'],
          es: ['**Facturación**: arme la factura desde una consulta (honorario + medicamentos), una internación (noches × tarifa diaria) o un pedido de laboratorio — o escriba las líneas.', '**Cada factura es una venta**: al guardarla se crea su venta en **Ventas** (con el número de venta de la empresa, que se ve en la factura) a nombre del cliente del paciente — todo paciente es un cliente en **Personas › Clientes**. Los medicamentos de la factura salen del stock de la farmacia; consultas, días de internación y exámenes van a los servicios hospitalarios de la empresa. Si cambia o anula la factura, su venta cambia o se elimina.', '**Cobro**: registre lo que paga el paciente; la factura pasa sola a parcial / pagada y el cobro es el cobro de la venta. No se acepta un cobro mayor al saldo.', 'Con los **Asientos automáticos** activos, Contabilidad recibe la venta y sus cobros como cualquier venta (un asiento por factura).', 'Los medicamentos se compran a los **Proveedores** en **Compras** (suben el stock y los lotes).'],
        },
      },
      {
        id: 'hms-dash', perms: ['hms_dashboard', 'hms_reports'], paths: ['hospital/dashboard', 'hospital/reports'],
        title: { en: 'Dashboard and reports', es: 'Panel e informes' },
        steps: {
          en: ['A hospital company opens on the **Hospital dashboard**, with Hospital Management first in the side menu: patients, today\'s appointments, bed occupancy, pending lab and revenue.', '**Reports**: doctors\' workload, revenue by department and ward occupancy.'],
          es: ['Una empresa de tipo hospital abre en el **Panel del hospital**, con Gestión hospitalaria primero en el menú lateral: pacientes, citas de hoy, ocupación de camas, laboratorio pendiente e ingresos.', '**Informes**: carga de trabajo de los médicos, ingresos por departamento y ocupación de salas.'],
        },
      },
    ],
  },

  // ------------------------------------------------------------------ store
  {
    key: 'store', module: 'Store', perms: ['Store_settings_view', 'Orders_view'], paths: ['store'],
    title: { en: 'Online store', es: 'Tienda en línea' },
    intro: { en: 'Sell online with the same products, prices and stock.', es: 'Venda en línea con los mismos productos, precios e inventario.' },
    sections: [
      {
        id: 'store-orders', perms: ['Orders_view'], paths: ['store/orders', 'store/quote-requests', 'store/returns'],
        title: { en: 'Online orders', es: 'Pedidos en línea' },
        steps: {
          en: ['**Store › Online Orders**: new orders show a badge in the menu.', 'Open an order to confirm, ship or cancel it.'],
          es: ['**Tienda › Pedidos en línea**: los pedidos nuevos muestran un indicador en el menú.', 'Abra un pedido para confirmarlo, enviarlo o cancelarlo.'],
        },
      },
      {
        id: 'store-setup', perms: ['Store_settings_view'], paths: ['store'],
        title: { en: 'Store setup', es: 'Configuración de la tienda' },
        steps: {
          en: ['**Store › Settings**, **Shipping Methods / Zones**, **Tax Rates**, **Coupons** and **Flash Sales**.'],
          es: ['**Tienda › Configuración**, **Métodos / Zonas de envío**, **Impuestos**, **Cupones** y **Ofertas relámpago**.'],
        },
      },
    ],
  },

  // ------------------------------------------------------------------ helpdesk
  {
    key: 'helpdesk', module: 'helpdesk', perms: [], paths: ['helpdesk'],
    title: { en: 'Help desk', es: 'Mesa de ayuda' },
    intro: { en: 'Support tickets from staff, customers and suppliers.', es: 'Tickets de soporte del personal, clientes y proveedores.' },
    sections: [
      {
        id: 'hd-my', paths: ['helpdesk/my'],
        title: { en: 'My tickets', es: 'Mis tickets' },
        steps: {
          en: ['**Help desk › My tickets › New**: describe the problem and attach a screenshot.', 'Follow the answers in the same ticket.'],
          es: ['**Mesa de ayuda › Mis tickets › Nuevo**: describa el problema y adjunte una captura.', 'Siga las respuestas en el mismo ticket.'],
        },
      },
      {
        id: 'hd-work', perms: ['helpdesk_view'], paths: ['helpdesk/tickets', 'helpdesk/dashboard'],
        title: { en: 'Answer tickets', es: 'Responder tickets' },
        steps: {
          en: ['**Help desk › Tickets**: filter by status, assign, answer and close.', 'Settings (categories, agents): **Help desk › Settings**.'],
          es: ['**Mesa de ayuda › Tickets**: filtre por estado, asigne, responda y cierre.', 'Configuración (categorías, agentes): **Mesa de ayuda › Configuración**.'],
        },
      },
    ],
  },

  // ------------------------------------------------------------------ accounting
  {
    key: 'accounting', module: 'accounting', perms: ['accounting_dashboard', 'expense_view', 'deposit_view', 'account'], paths: ['accounting-v2', 'expenses', 'deposits', 'accounts', 'transfer-money'],
    title: { en: 'Accounting, expenses and deposits', es: 'Contabilidad, gastos y depósitos' },
    intro: {
      en: 'Sales, purchases, payroll and expenses post to the books automatically.',
      es: 'Ventas, compras, planillas y gastos se registran solos en la contabilidad.',
    },
    sections: [
      {
        id: 'acc-exp', perms: ['expense_add', 'expense_view'], paths: ['expenses'],
        title: { en: 'Record an expense', es: 'Registrar un gasto' },
        steps: {
          en: ['**Accounting › Create Expense**: date, category, account, amount and the bill.', 'Deposits work the same way in **Create deposit**.'],
          es: ['**Contabilidad › Crear gasto**: fecha, categoría, cuenta, monto y la factura.', 'Los depósitos funcionan igual en **Crear depósito**.'],
        },
      },
      {
        id: 'acc-reports', perms: ['accounting_dashboard', 'accounting_profit_loss', 'balance_sheet'], paths: ['accounting-v2'],
        title: { en: 'Financial reports', es: 'Reportes financieros' },
        steps: {
          en: ['Profit and loss, balance sheet, trial balance and tax summary under **Accounting**.', 'Journal entries show every automatic and manual posting.'],
          es: ['Estado de resultados, balance general, balance de comprobación y resumen de impuestos en **Contabilidad**.', 'Los asientos contables muestran cada registro automático y manual.'],
        },
      },
    ],
  },

  // ------------------------------------------------------------------ assets
  {
    key: 'assets', module: 'assets', perms: ['assets'], paths: ['assets'],
    title: { en: 'Assets and equipment', es: 'Activos y equipos' },
    intro: { en: 'Equipment and tools: who has them, maintenance and value.', es: 'Equipos y herramientas: quién los tiene, mantenimiento y valor.' },
    sections: [
      {
        id: 'asset-use', paths: ['assets'],
        title: { en: 'Register and assign', es: 'Registrar y asignar' },
        steps: {
          en: ['**Asset Management › Add Asset**: name, category, cost and hourly cost (used in job costs).', '**Assignments**: give it to an employee; it shows on their Assets tab.', '**Due Assets** lists what must come back.'],
          es: ['**Activos › Agregar activo**: nombre, categoría, costo y costo por hora (usado en el costo de trabajos).', '**Asignaciones**: entréguelo a un empleado; aparece en su pestaña Activos.', '**Activos por devolver** lista lo que debe regresar.'],
        },
      },
    ],
  },

  // ------------------------------------------------------------------ commissions
  {
    key: 'commissions', module: 'commissions', perms: ['commissions_view'], paths: ['commissions'],
    title: { en: 'Commissions', es: 'Comisiones' },
    intro: { en: 'Sales agents and what they earn on their sales.', es: 'Vendedores y lo que ganan por sus ventas.' },
    sections: [
      {
        id: 'com', paths: ['commissions'],
        title: { en: 'Agents, rules and receipts', es: 'Vendedores, reglas y recibos' },
        steps: {
          en: ['**Commissions › Sales Agents**: add an agent; their login is created here.', '**Programs** and **Rules**: the % per product or category.', '**Receipts** and **Report**: what is owed and paid.'],
          es: ['**Comisiones › Vendedores**: agregue un vendedor; su usuario se crea aquí.', '**Programas** y **Reglas**: el % por producto o categoría.', '**Recibos** y **Reporte**: lo que se debe y lo pagado.'],
        },
      },
    ],
  },

  // ------------------------------------------------------------------ marketing
  {
    key: 'marketing', module: 'marketing', perms: ['marketing_dashboard', 'marketing_campaigns'], paths: ['marketing'],
    title: { en: 'Marketing', es: 'Marketing' },
    intro: { en: 'Campaigns to the right customers by email, SMS or WhatsApp.', es: 'Campañas a los clientes correctos por correo, SMS o WhatsApp.' },
    sections: [
      {
        id: 'mkt', paths: ['marketing'],
        title: { en: 'Send a campaign', es: 'Enviar una campaña' },
        steps: {
          en: ['**Customer Segments**: choose who receives it.', '**Templates**: the message for email, SMS or WhatsApp.', '**Campaigns › Create**: segment + template + date; results in **Reports**.'],
          es: ['**Segmentos de clientes**: elija quién la recibe.', '**Plantillas**: el mensaje para correo, SMS o WhatsApp.', '**Campañas › Crear**: segmento + plantilla + fecha; resultados en **Reportes**.'],
        },
      },
    ],
  },

  // ------------------------------------------------------------------ projects
  {
    key: 'projects', module: 'projects', perms: ['projects', 'tasks'], paths: ['projects', 'tasks'],
    title: { en: 'Projects and tasks', es: 'Proyectos y tareas' },
    intro: { en: 'Internal projects, their tasks, milestones and time.', es: 'Proyectos internos, sus tareas, hitos y tiempo.' },
    sections: [
      {
        id: 'proj', paths: ['projects', 'tasks'],
        title: { en: 'Plan and follow', es: 'Planificar y seguir' },
        steps: {
          en: ['**Projects › Add**: customer, dates and team.', '**Tasks** and the **Task Board**: drag tasks between columns.', '**Timesheets**: hours per person and project.'],
          es: ['**Proyectos › Agregar**: cliente, fechas y equipo.', '**Tareas** y el **Tablero**: arrastre las tareas entre columnas.', '**Hojas de tiempo**: horas por persona y proyecto.'],
        },
      },
    ],
  },

  // ------------------------------------------------------------------ intranet
  {
    key: 'intranet', module: 'intranet', perms: ['intranet_view', 'intranet_manage'], paths: ['intranet', 'intranet-admin', 'kb'],
    title: { en: 'Intranet', es: 'Intranet' },
    intro: { en: 'News, events, files and a knowledge base for the team.', es: 'Noticias, eventos, archivos y base de conocimiento para el equipo.' },
    sections: [
      {
        id: 'intra', perms: ['intranet_view'], paths: ['intranet', 'kb'],
        title: { en: 'Read the intranet', es: 'Leer la intranet' },
        steps: {
          en: ['**Intranet › Home**: latest news and events; **Calendar** with birthdays; **Files** and **Gallery**.'],
          es: ['**Intranet › Inicio**: últimas noticias y eventos; **Calendario** con cumpleaños; **Archivos** y **Galería**.'],
        },
      },
      {
        id: 'intra-admin', perms: ['intranet_manage'], paths: ['intranet-admin'],
        title: { en: 'Publish on the intranet', es: 'Publicar en la intranet' },
        steps: {
          en: ['**Intranet Management**: news, files, gallery albums, events and pages.'],
          es: ['**Administración de intranet**: noticias, archivos, álbumes, eventos y páginas.'],
        },
      },
    ],
  },

  // ------------------------------------------------------------------ reports
  {
    key: 'reports', module: null,
    perms: ['Reports_sales', 'Reports_customers', 'Reports_profit', 'cash_register_report', 'report_attendance_summary', 'service_jobs_report', 'Reports_payments_Sales', 'Top_products'],
    paths: ['reports'],
    title: { en: 'Reports', es: 'Reportes' },
    intro: { en: 'Each report appears only if your role has it.', es: 'Cada reporte aparece solo si su rol lo tiene.' },
    sections: [
      {
        id: 'rep', paths: ['reports'],
        title: { en: 'Run a report', es: 'Ver un reporte' },
        steps: {
          en: ['Open **Reports** and pick one (sales, payments, profit, customers, stock, cash register, attendance …).', 'Set the dates with the date filter and the location if needed.', 'Export to PDF or Excel.'],
          es: ['Abra **Reportes** y elija uno (ventas, pagos, utilidad, clientes, inventario, caja, asistencia …).', 'Indique las fechas con el filtro y el local si hace falta.', 'Exporte a PDF o Excel.'],
        },
      },
    ],
  },

  // ------------------------------------------------------------------ users & roles
  {
    key: 'users', module: null, perms: ['users_view', 'permissions_view', 'permissions_edit'], paths: ['users', 'permissions'],
    title: { en: 'Users and roles', es: 'Usuarios y roles' },
    intro: {
      en: 'A user can do only what their role allows. Nothing is given by job title.',
      es: 'Un usuario solo puede hacer lo que su rol permite. Nada se otorga por el cargo.',
    },
    sections: [
      {
        id: 'users-list', perms: ['users_view'], paths: ['users'],
        title: { en: 'Users', es: 'Usuarios' },
        steps: {
          en: ['**Users › Add** creates administrator logins only. Employees, customers, suppliers and sales agents get theirs from their own module.', 'Edit a login here: role, locations, password and PIN.', 'The **Active / Disabled** switch signs the person out everywhere. You can\'t disable yourself.', 'Filter the list by **Type**, **Role** (or "No role") and **Status**; **Clear filters** shows everyone again.', 'When the login belongs to an employee, **Edit** also shows that employee (department, position, shift, pay) with a link to HRM › Employees, where it is changed.'],
          es: ['**Usuarios › Agregar** crea solo accesos de administrador. Empleados, clientes, proveedores y vendedores reciben el suyo desde su propio módulo.', 'Edite un acceso aquí: rol, locales, contraseña y PIN.', 'El interruptor **Activo / Deshabilitado** cierra la sesión de la persona en todos lados. No puede deshabilitarse a sí mismo.', 'Filtre la lista por **Tipo**, **Rol** (o "Sin rol") y **Estado**; **Clear filters** vuelve a mostrar a todos.', 'Cuando el acceso pertenece a un empleado, **Editar** también muestra ese empleado (departamento, puesto, turno, pago) con un enlace a RR. HH. › Empleados, donde se cambia.'],
        },
      },
      {
        id: 'roles', perms: ['permissions_view', 'permissions_edit'], paths: ['permissions'],
        title: { en: 'Roles', es: 'Roles' },
        steps: {
          en: ['**User Management › Group Permissions**: create or edit a role.', 'Tick what the role can do, module by module and, for employees, tab by tab (view / edit).', 'Choose the role\'s **Home page** (for example Daily Tasks or the Bookings calendar).', 'The **Users** column shows how many users have each role; click the number to open Users filtered by that role.'],
          es: ['**Usuarios › Permisos de grupo**: cree o edite un rol.', 'Marque lo que el rol puede hacer, módulo por módulo y, para empleados, pestaña por pestaña (ver / editar).', 'Elija la **Página de inicio** del rol (por ejemplo Tareas diarias o el Calendario de reservas).', 'La columna **Usuarios** muestra cuántos usuarios tienen cada rol; haga clic en el número para abrir Usuarios filtrado por ese rol.'],
        },
      },
    ],
  },

  // ------------------------------------------------------------------ settings
  {
    key: 'settings', module: null, perms: ['setting_system', 'pos_settings', 'mail_settings', 'payment_methods', 'company', 'network_security', 'paypal_manage'], paths: ['settings', 'hrm/companies'],
    title: { en: 'Settings', es: 'Configuración' },
    intro: { en: 'Company-wide options.', es: 'Opciones para toda la empresa.' },
    sections: [
      {
        id: 'set-companies', perms: ['company'], paths: ['hrm/companies'],
        title: { en: 'Companies', es: 'Empresas' },
        steps: {
          en: ['**SuperAdmin with AdminSystem picked** in the company switcher: every company, **Add** and **Delete**, plus the global menus (yellow, tag "Global": Login Page Settings, Update Settings, Currencies, Backup, System Health, Login Device Management). **With a company picked** the SuperAdmin works only on that company: this list shows just it and the global menus are hidden.', 'As **SuperAdmin with AdminSystem picked**, every list (users, roles, sales, customers, suppliers, products, purchases, employees, expenses, students, patients, properties…) and every report shows **all companies**, with a **Company** column. With a company picked, only that company\'s records.', '**Add** (SuperAdmin) or **Edit** a company. The form goes in steps (Company, Helpdesk, Defaults; when adding: Company, Modules, Helpdesk, Defaults, Admin user, Website); use **Next / Back** or click a step. A step marked in red has something missing.', 'Required: name, business type, owner, company email, phone, address and helpdesk email.', '**Logo** (step Company; required when adding, optional on Edit): **Upload logo**, then in **Crop / resize** drag the box or its corners to cut it (shape Free, Square 1:1 or Wide 3:1, or **Whole image** for no cut) and pick the size (longest side, 200 to 1200 px); **Use this logo**. It is saved as the company\'s logo (invoices, menu, customer portal) and shown in the list\'s **Logo** column.', '**Business type** (General, IT, Landscaping, Hair salon, Barber, Retail, Restaurant, Catering / buffet, Laundry, Delivery, Call center, Real estate (selling houses), Law services, Hospital / clinic, Factory / manufacturing, School / academy, Office / professional): sets the company\'s extra accounts, expense categories and demo data, and which modules it starts with.', '**Modules** (when adding): every company gets Accounting, Assets, Document Archive (each company sees only its own files), Email Office, Gallery, Helpdesk, HRM and Website Management, plus its type\'s own (e.g. Kitchen for a restaurant or catering, Bookings for a salon or barber, Fleet for delivery, Real Estate listings for a real estate agency, Projects and Documents for a law firm, Hospital Management for a hospital / clinic, Manufacturing (MRP) and Fleet for a factory, School Management and Intranet for a school), already ticked (tag "type"). Tick any other module it needs, or untick one; changing the business type ticks its modules again. **Sales**, **POS**, **Purchases** and **Suppliers** are ticked for every type except **Hospital / clinic**, which bills through Hospital Management. A **hospital** opens on the **Hospital dashboard**, a **school** on the **School dashboard** and a **real estate** company on the **Real Estate dashboard**, with that module first in the side menu. A **school** starts with only School, Portfolio, Intranet, Documents and Website (no sales, products, customers, accounting or HR). Products, customers, reports and settings are always on.', 'The company\'s **language** is what its users see after signing in (a user who picks another language in the app keeps theirs); the sign-in page follows the computer\'s language or its EN / ES switch.', '**Defaults**: currency, language (when adding, it starts in the language you are using — check it before creating), sale prefix (invoice numbers), public address and the default employee / customer passwords (blank keeps the current one).', 'The **Setup** column shows "Complete" or how many required fields a company still lacks; click it to fill them in.', 'A new company starts with the SuperAdmin\'s current menu (the AdminSystem layout), already in order.'],
          es: ['**SuperAdmin con AdminSystem escogido** en el selector de empresa: todas las empresas, **Añadir** y **Eliminar**, más los menús globales (en amarillo, etiqueta "Global": Configuración de la página de inicio de sesión, Ajustes de actualización, Monedas, Respaldo, Estado del sistema, Gestión de dispositivos de inicio de sesión). **Con una empresa escogida** el SuperAdmin trabaja solo en esa empresa: esta lista muestra solo esa y los menús globales se ocultan.', 'Como **SuperAdmin con AdminSystem escogido**, cada lista (usuarios, roles, ventas, clientes, proveedores, productos, compras, empleados, gastos, estudiantes, pacientes, propiedades…) y cada reporte muestra **todas las empresas**, con una columna **Compañía**. Con una empresa escogida, solo los registros de esa empresa.', '**Agregar** (SuperAdmin) o **Editar** una empresa. El formulario va por pasos (Empresa, Helpdesk, Predeterminados; al agregar: Empresa, Módulos, Helpdesk, Predeterminados, Usuario administrador, Sitio web); use **Next / Back** o haga clic en un paso. Un paso marcado en rojo tiene algo pendiente.', 'Obligatorios: nombre, tipo de negocio, dueño, correo de la empresa, teléfono, dirección y correo de soporte (helpdesk).', '**Logo** (paso Empresa; obligatorio al agregar, opcional al editar): **Upload logo** y luego en **Crop / resize** arrastre el recuadro o sus esquinas para recortarlo (forma libre, cuadrada 1:1 o ancha 3:1, o **Whole image** para no recortar) y elija el tamaño (lado mayor, de 200 a 1200 px); **Use this logo**. Se guarda como el logo de la empresa (facturas, menú, portal del cliente) y se ve en la columna **Logo** de la lista.', '**Tipo de negocio** (General, TI, Jardinería, Salón de belleza, Barbería, Tienda, Restaurante, Catering / buffet, Lavandería, Servicio de entregas, Call center, Bienes raíces (venta de casas), Servicios legales, Hospital / clínica, Fábrica / manufactura, Escuela / academia, Oficina / servicios profesionales): define las cuentas y categorías de gastos adicionales, los datos de ejemplo y con qué módulos empieza la empresa.', '**Módulos** (al agregar): toda empresa tiene Contabilidad, Activos, Archivo de documentos (cada empresa ve solo sus archivos), Email Office, Galería, Helpdesk, RR. HH. y Administración del sitio web, más los propios de su tipo (p. ej. Cocina para restaurante o catering, Reservas para salón o barbería, Flota para entregas, Bienes raíces (propiedades) para una inmobiliaria, Proyectos y Documentos para un bufete de abogados, Gestión hospitalaria para un hospital / clínica, Manufactura (MRP) y Flota para una fábrica, Gestión escolar e Intranet para una escuela), ya marcados (etiqueta "type"). Marque cualquier otro módulo que necesite, o desmarque uno; al cambiar el tipo de negocio se vuelven a marcar sus módulos. **Ventas**, **POS**, **Compras** y **Proveedores** vienen marcados en todos los tipos excepto **Hospital / clínica**, que factura desde Gestión hospitalaria. Un **hospital** abre en el **Panel del hospital**, una **escuela** en el **Panel de la escuela** y una inmobiliaria en el **Panel de bienes raíces**, con ese módulo primero en el menú lateral. Una **escuela** empieza solo con Escuela, Portafolio, Intranet, Documentos y Sitio web (sin ventas, productos, clientes, contabilidad ni RR. HH.). Productos, clientes, reportes y configuración siempre están activos.', 'El **idioma** de la empresa es el que ven sus usuarios al entrar (un usuario que elige otro idioma en la app conserva el suyo); la página de ingreso sigue el idioma de la computadora o su selector EN / ES.', '**Predeterminados**: moneda, idioma (al agregar, viene marcado el idioma que usted está usando — revíselo antes de crear), prefijo de ventas (número de factura), dirección pública y las contraseñas predeterminadas de empleados / clientes (en blanco mantiene la actual).', 'La columna **Setup** muestra "Complete" o cuántos datos obligatorios le faltan a una empresa; haga clic para completarlos.', 'Una empresa nueva empieza con el menú actual del SuperAdmin (el de AdminSystem), ya ordenado.'],
        },
      },
      {
        id: 'set-superadmin-enter', perms: ['setting_system'], paths: ['settings', 'hrm/companies'],
        title: { en: 'SuperAdmin: enter as a company\'s admin', es: 'SuperAdmin: entrar como admin de una compañía' },
        steps: {
          en: [
            'After signing in, a **SuperAdmin** first sees **How do you want to enter?**: **Go to the dashboard** (as SuperAdmin, every company) or one card per company with its admins and **Enter**.',
            '**Enter** opens Murano as that admin — their menu, modules, language and permissions. That company\'s Network security does not block it.',
            'An amber **SuperAdmin** button in the header goes back to the SuperAdmin (and to this choice). Signing out ends it too.',
          ],
          es: [
            'Al ingresar, un **SuperAdmin** ve primero **¿Cómo quiere entrar?**: **Ir al dashboard** (como SuperAdmin, todas las compañías) o una tarjeta por compañía con sus admins y **Entrar**.',
            '**Entrar** abre Murano como ese admin — su menú, módulos, idioma y permisos. La seguridad de red de esa compañía no lo bloquea.',
            'Un botón ámbar **SuperAdmin** en la cabecera vuelve al SuperAdmin (y a esta elección). Cerrar sesión también lo termina.',
          ],
        },
      },
      {
        id: 'set-login-page', perms: ['setting_system'], paths: ['settings/login-page', 'settings'],
        title: { en: 'Login page colours by language', es: 'Colores de la página de ingreso por idioma' },
        steps: {
          en: ['**Settings › Login Page › Background › Solid color**: one colour for **English** and one for **Español**. The login page uses the Spanish one when it opens in Spanish; empty = same as English. An uploaded image is used for every language.'],
          es: ['**Configuración › Página de ingreso › Fondo › Color sólido**: un color para **English** y otro para **Español**. La página de ingreso usa el de español cuando se abre en español; vacío = el mismo que inglés. Una imagen subida se usa en todos los idiomas.'],
        },
      },
      {
        id: 'set-access', perms: ['setting_system'], paths: ['settings', 'permissions'],
        title: { en: 'Which settings a company manages', es: 'Qué configuración maneja una empresa' },
        steps: {
          en: [
            'A company\'s roles can have: **System Settings** (company info, logo, defaults), Appearance, Customize columns, Email templates, Locations, Network security and Login devices.',
            'The rest — Mail settings (every company uses AdminSystem\'s mail server), Payment methods, SMS templates, PayPal, ZATCA, Currencies, Multi-currency, Backup, System health, Translations, Update, Payment gateway, SMS, POS settings, integrations, Loyalty — is for the **SuperAdmin**, unless that module is on for the company (e.g. PayPal, POS or an integration): then its own settings stay available.',
            'A company Admin editing a role can\'t give those settings back; a SuperAdmin can, on purpose (Roles).',
            'A **hospital**, **school** or **real estate** company opens on its own dashboard and its users don\'t see the general Dashboard. They start with **POS off**.',
            'Only a **SuperAdmin** can create, edit, disable or delete a SuperAdmin user; a company Admin can never make anyone a SuperAdmin.',
            '**Integrations** (the Integrations page, Webhooks, WooCommerce, Shopify, QuickBooks, Xero, Slack, Telegram…) are **off for every company** by default: their settings are shared by the whole system. A SuperAdmin turns one on in Business Modules for a company that really uses it (turn on "Integrations hub & Webhooks" too).',
            'A **school** has the roles **Rector** (admin), **Secretaria**, **Profesor**, **Padre de familia** and **Alumno**; parents and students see the Intranet and Documents.',
          ],
          es: [
            'Los roles de una empresa pueden tener: **Configuración del sistema** (datos de la empresa, logo, predeterminados), Apariencia, Columnas, Plantillas de correo, Ubicaciones, Seguridad de red y Dispositivos de inicio de sesión.',
            'El resto — Configuración de correo (todas las empresas usan el servidor de correo de AdminSystem), Métodos de pago, Plantillas SMS, PayPal, ZATCA, Monedas, Multimoneda, Respaldos, Estado del sistema, Traducciones, Actualización, Pasarela de pago, SMS, Configuración del POS, integraciones, Fidelidad — es del **SuperAdmin**, salvo que ese módulo esté activo para la empresa (p. ej. PayPal, POS o una integración): entonces su configuración sigue disponible.',
            'Un Admin de empresa que edita un rol no puede volver a dar esa configuración; un SuperAdmin sí puede, a propósito (Roles).',
            'Una empresa de tipo **hospital**, **escuela** o **bienes raíces** abre en su propio panel y sus usuarios no ven el Panel general. Empiezan con el **POS apagado**.',
            'Solo un **SuperAdmin** puede crear, editar, desactivar o borrar un usuario SuperAdmin; un Admin de empresa nunca puede convertir a nadie en SuperAdmin.',
            'Las **integraciones** (página de Integraciones, Webhooks, WooCommerce, Shopify, QuickBooks, Xero, Slack, Telegram…) vienen **apagadas para todas las empresas**: su configuración es compartida por todo el sistema. Un SuperAdmin enciende una en Módulos para la empresa que de verdad la usa (encienda también "Integrations hub & Webhooks").',
            'Una **escuela** tiene los roles **Rector** (admin), **Secretaria**, **Profesor**, **Padre de familia** y **Alumno**; padres y alumnos ven la Intranet y los Documentos.',
          ],
        },
      },
      {
        id: 'set-modules', perms: ['business_modules', 'setting_system'], paths: ['settings/business-modules'],
        title: { en: 'Business modules (turn modules on / off per company)', es: 'Módulos (activar / desactivar módulos por empresa)' },
        steps: {
          en: [
            '**SuperAdmin** only: **Settings › Modules** (Business Modules), with the company chosen in the company switcher.',
            'The list is alphabetical: Accounting, Real Estate, Recruits & Jobs, Meetings, Fleet, Projects, Kitchen, each integration (QuickBooks, Xero, Google Sheets, Mailchimp, Slack, Telegram, PrestaShop, Salla, Jumia…) and more. Turn the switch on / off and **Submit**.',
            'A module turned off disappears from that company\'s menu, its pages can\'t be opened and the server refuses its requests — for every user and role. Nothing is deleted: turning it back on restores everything as it was.',
            '**Sales**, **POS**, **Purchases** and **Suppliers** can be switched off too (their pages, menu items and reports disappear) — a hospital, for example, bills through Hospital Management. Products, customers, reports and settings are the core of the system and always stay on.',
            'The switches never hide anything from the **SuperAdmin**: with any company chosen, the SuperAdmin sees and opens every module (to see what the company sees, sign in as one of its users).',
            '**Modules of <business type>** turns on the modules a company of that type starts with and turns the others off (the type is the one in Companies › Edit); review and **Submit**.',
            '**ZATCA E-Invoicing (Saudi Arabia)** is OFF by default: turn it on only for a company in Saudi Arabia. While it is off there is no ZATCA settings page, no ZATCA QR on invoices / receipts and nothing is sent to ZATCA when a sale or return is saved.',
          ],
          es: [
            'Solo el **SuperAdmin**: **Configuración › Módulos** (Business Modules), con la empresa elegida en el selector de empresa.',
            'La lista va en orden alfabético: Contabilidad, Bienes raíces (Real Estate), Reclutamiento, Reuniones, Flota, Proyectos, Cocina, cada integración (QuickBooks, Xero, Google Sheets, Mailchimp, Slack, Telegram, PrestaShop, Salla, Jumia…) y más. Active / desactive el interruptor y **Submit**.',
            'Un módulo desactivado desaparece del menú de esa empresa, sus páginas no se pueden abrir y el servidor rechaza sus pedidos — para todos los usuarios y roles. No se borra nada: al activarlo de nuevo vuelve todo como estaba.',
            '**Ventas**, **POS**, **Compras** y **Proveedores** también se pueden desactivar (desaparecen sus páginas, opciones del menú y reportes) — un hospital, por ejemplo, factura desde Gestión hospitalaria. Productos, clientes, reportes y configuración son la base del sistema y siempre quedan activos.',
            'Los interruptores nunca le ocultan nada al **SuperAdmin**: con cualquier empresa elegida, el SuperAdmin ve y abre todos los módulos (para ver lo que ve la empresa, entre con uno de sus usuarios).',
            '**Modules of <tipo de negocio>** activa los módulos con los que empieza una empresa de ese tipo y desactiva los demás (el tipo es el de Empresas › Editar); revise y **Submit**.',
            '**ZATCA E-Invoicing (Arabia Saudita)** viene DESACTIVADO: actívelo solo para una empresa en Arabia Saudita. Mientras esté desactivado no hay página de configuración de ZATCA, ni código QR de ZATCA en facturas / recibos, y no se envía nada a ZATCA al guardar una venta o devolución.',
          ],
        },
      },
      {
        id: 'set-menu', perms: ['setting_system'], paths: ['settings/sidebar-menu'],
        title: { en: 'Manage Menu (order of the side menu)', es: 'Manage Menu (orden del menú lateral)' },
        steps: {
          en: [
            '**Settings › Manage Menu**: each row has **↑ / ↓** (move up / down) and **Move to…** (pick another module, group or the **top level** from a list — a single page can sit in the main menu too); dragging still works. Hide / show items with the eye, then **Save**. Names are the same as in your side menu; an item tagged **not in your menu** is there for other roles of the company.',
            '**SuperAdmin working as AdminSystem** (company switcher): Submit and Reset apply to **every company** — their own order is replaced. New companies also start with this layout.',
            '**SuperAdmin working as one company**, or a company **Admin**: the change stays in that company only.',
            '**Default order** (SuperAdmin working as one company): that company\'s menu becomes a copy of AdminSystem\'s — same order, groups, names and hidden items.',
            'An Admin can reorder and hide / show; adding, removing or renaming items is for the SuperAdmin.',
            'Modules turned off for the company (Settings › Modules) are not listed here nor in the side menu — also their items kept elsewhere (e.g. Hospital Reports under Reports). They keep their place and come back when the module is turned on.',
          ],
          es: [
            '**Configuración › Administrar menú**: cada fila tiene **↑ / ↓** (subir / bajar) y **Mover a…** (escoja otro módulo, grupo o el **nivel principal** de una lista — una página suelta también puede ir en el menú principal); arrastrar sigue funcionando. Oculte / muestre con el ojo y luego **Guardar**. Los nombres son los mismos de su menú lateral; un elemento con la etiqueta **no está en su menú** es para otros roles de la empresa.',
            '**SuperAdmin trabajando como AdminSystem** (selector de empresa): Submit y Reset se aplican a **todas las empresas** — se reemplaza su propio orden. Las empresas nuevas también empiezan con este menú.',
            '**SuperAdmin trabajando como una empresa**, o el **Admin** de una empresa: el cambio queda solo en esa empresa.',
            '**Orden predeterminado** (Default order; SuperAdmin trabajando como una empresa): el menú de esa empresa pasa a ser una copia del de AdminSystem — mismo orden, grupos, nombres y opciones ocultas.',
            'Un Admin puede reordenar y ocultar / mostrar; agregar, quitar o renombrar opciones es del SuperAdmin.',
            'Los módulos desactivados para la empresa (Configuración › Módulos) no aparecen aquí ni en el menú lateral — tampoco sus opciones puestas en otro lugar (p. ej. Hospital Reports dentro de Reportes). Conservan su lugar y vuelven al activar el módulo.',
          ],
        },
      },
      {
        id: 'set-defaults', perms: ['setting_system'], paths: ['settings/system'],
        title: { en: 'System Settings › Defaults', es: 'Configuración del sistema › Predeterminados' },
        steps: {
          en: ['**General**: the company\'s public address used in every link sent outside (booking codes, emails).', '**Users**: the default employee password and the **Header icons** shown to everyone in the company.', '**Invoice PDF › Default**: the **Discount column** and **Tax column** switches hide those columns on the printed invoice AND on the sale form; each shows again by itself on an invoice where a line has a discount or its own tax (a product with a tax %).', '**Customers** and the other tabs: what each form shows by default.'],
          es: ['**General**: la dirección pública de la empresa usada en cada enlace enviado afuera (códigos de reserva, correos).', '**Usuarios**: la contraseña predeterminada de empleados y los **Íconos del encabezado** para toda la empresa.', '**PDF de factura › Predeterminado**: los interruptores **Columna de descuento** y **Columna de impuesto** ocultan esas columnas en la factura impresa Y en el formulario de venta; cada una vuelve a aparecer sola en una factura donde una línea tiene descuento o su propio impuesto (un producto con % de impuesto).', '**Clientes** y las demás pestañas: lo que cada formulario muestra por defecto.'],
        },
      },
      {
        id: 'set-pos', perms: ['pos_settings'], paths: ['settings/pos'],
        title: { en: 'POS settings', es: 'Configuración del POS' },
        steps: {
          en: ['Receipt, payment methods at the counter and whether the POS button shows in the header.'],
          es: ['Recibo, medios de pago en el mostrador y si el botón POS aparece en el encabezado.'],
        },
      },
      {
        id: 'net-sec', perms: ['network_security'], paths: ['settings/network-security'],
        title: { en: 'Network security', es: 'Seguridad de red' },
        steps: {
          en: ['**Settings › Network security**: turn on **Restrict access by network** and add the office networks (its public IP, e.g. 203.0.113.10, or a range like 203.0.113.0/24). **Add it** puts your current IP in the list.', 'Choose what users follow: **Restricted** (safe default) or **Anywhere**; under **People** set someone on their own (e.g. a field worker on phone data = Can sign in from anywhere).', 'A restricted user signing in from outside sends that computer to **Computers** (and the bell): **Approve** it once and it works from anywhere; **Remove** it when a laptop is lost or someone leaves.', 'Only the admin panel is restricted: store, websites, portfolio, online booking and the portals stay open. The SuperAdmin is never blocked. If saving would block you, Murano asks first.'],
          es: ['**Configuración › Seguridad de red**: active **Restringir el acceso por red** y agregue las redes de la oficina (su IP pública, p. ej. 203.0.113.10, o un rango como 203.0.113.0/24). **Agregarla** pone su IP actual en la lista.', 'Elija qué siguen los usuarios: **Restringido** (lo seguro) o **Desde cualquier lugar**; en **Personas** configure a alguien por separado (p. ej. un trabajador de campo con datos del celular = Desde cualquier lugar).', 'Un usuario restringido que entra desde afuera envía esa computadora a **Computadoras** (y a la campana): **Apruébela** una vez y funciona desde cualquier lugar; **Quítela** si se pierde una laptop o alguien deja la empresa.', 'Solo se restringe el panel de administración: tienda, sitios web, portafolio, reservas en línea y los portales siguen abiertos. El SuperAdmin nunca queda bloqueado. Si al guardar usted quedaría bloqueado, Murano pregunta antes.'],
        },
        note: {
          en: 'Blocked users see: "You can\'t use Murano from this network…" on the sign-in page. Clearing the browser\'s data or using another browser needs a new approval.',
          es: 'Los usuarios bloqueados ven: "You can\'t use Murano from this network…" en la página de ingreso. Borrar los datos del navegador o usar otro navegador requiere una nueva aprobación.',
        },
      },
      {
        id: 'paypal', module: 'paypal', perms: ['paypal_manage', 'Sales_view'], paths: ['settings/paypal', 'sales'],
        title: { en: 'PayPal (customers pay invoices online)', es: 'PayPal (los clientes pagan facturas en línea)' },
        steps: {
          en: ['**Settings › PayPal**: paste the **Client ID** and **Secret** of the company\'s PayPal app (developer.paypal.com › Apps & Credentials), pick **Sandbox** to test first or **Live** for real money, choose the **Deposit account** and press **Submit**. **Test connection** checks the keys with PayPal.', 'Turn on **Accept PayPal payments**. Invoices with a balance now have a "Pay now" link: put **{pay_link}** in the invoice email / SMS templates (Settings › Email templates), or in **Sales** open the invoice\'s **⋮** menu › **PayPal pay link** to copy it and send it yourself.', 'The customer opens the link (in their language: Spanish or English, from their browser, with an EN / ES switch), sees the invoice and the amount due and pays with PayPal or a card. The payment is recorded on the invoice by itself (method PayPal, into the deposit account) and the invoice becomes Paid.', 'Every attempt is listed under **PayPal payments** (Started / Paid / Cancelled / Failed / Refunded). Optional: add the **webhook** shown on the page in your PayPal app so the payment is recorded even if the customer closes the page before coming back.'],
          es: ['**Configuración › PayPal**: pegue el **Client ID** y el **Secret** de la app de PayPal de la empresa (developer.paypal.com › Apps & Credentials), elija **Sandbox** para probar primero o **Live** para dinero real, escoja la **cuenta de depósito** y presione **Enviar**. **Test connection** comprueba las claves con PayPal.', 'Active **Accept PayPal payments**. Las facturas con saldo tienen ahora un enlace "Pagar ahora": ponga **{pay_link}** en las plantillas de correo / SMS de la factura (Configuración › Plantillas de correo), o en **Ventas** abra el menú **⋮** de la factura › **PayPal pay link** para copiarlo y enviarlo usted.', 'El cliente abre el enlace (en su idioma: español o inglés, según su navegador, con un selector EN / ES), ve la factura y el saldo y paga con PayPal o con tarjeta. El pago se registra solo en la factura (método PayPal, en la cuenta de depósito) y la factura queda Pagada.', 'Cada intento aparece en **PayPal payments** (Iniciado / Pagado / Cancelado / Fallido / Reembolsado). Opcional: agregue en su app de PayPal el **webhook** que muestra la página, para que el pago se registre aunque el cliente cierre la página antes de volver.'],
        },
        note: {
          en: 'A module per company (System Settings › Business Modules › PayPal), off by default; each company is paid into its own PayPal account. Refunds are made in PayPal and then recorded in Murano by hand (sale return).',
          es: 'Es un módulo por empresa (Configuración del sistema › Módulos › PayPal), apagado por defecto; cada empresa cobra en su propia cuenta de PayPal. Los reembolsos se hacen en PayPal y luego se registran a mano en Murano (devolución de venta).',
        },
      },
      {
        id: 'set-demo', perms: ['setting_system'], paths: ['settings/system'],
        title: { en: 'Demo data (examples)', es: 'Datos de ejemplo' },
        steps: {
          en: [
            '**System Settings › Demo Data**: one set of examples per module, only for the modules the company has on (a school gets School, Website and Portfolio; a hospital gets Hospital; a real estate company gets its listings and inquiries). **Select available** ticks them; **Generate** creates them in the company\'s language.',
            'A module with **Has real data — skipped** is never mixed with examples. **Remove** takes out only the examples.',
            '**Delete real data** (SuperAdmin only, School, Hospital and Real Estate): after a confirmation, deletes every record of that module in the company — typed by hand too — so a test company can start over with fresh examples. It cannot be undone.',
          ],
          es: [
            '**Configuración del sistema › Datos de ejemplo**: un juego de ejemplos por módulo, solo de los módulos que la empresa tiene encendidos (una escuela: Escuela, Sitio web y Portafolio; un hospital: Hospital; una inmobiliaria: sus propiedades y consultas). **Seleccionar disponibles** los marca; **Generar** los crea en el idioma de la empresa.',
            'Un módulo con **Tiene datos reales — se omite** nunca se mezcla con ejemplos. **Quitar** saca solo los ejemplos.',
            '**Borrar datos reales** (solo SuperAdmin; Escuela, Hospital y Bienes raíces): tras una confirmación, borra cada registro de ese módulo en la empresa — también los ingresados a mano — para que una empresa de prueba empiece de nuevo con ejemplos. No se puede deshacer.',
          ],
        },
      },
      {
        id: 'set-mail', perms: ['mail_settings'], paths: ['settings/mail'],
        title: { en: 'Mail settings', es: 'Configuración de correo' },
        steps: {
          en: ['**Settings › Mail Settings**: the mail server the company sends from; **Test** sends a test email.', '**Admin notices** lists who gets the automatic notices (bookings, POS register); the SuperAdmin can also get a copy.', '**Send a copy to other emails**: type the addresses (Enter after each) and tick which emails they copy — **Sales** (invoices and payment receipts), **Payment reminders**, **Purchases** (orders and payments). They get a hidden copy; customers and suppliers never see them. Press **Submit** to save.', 'Every company starts with **AdminSystem\'s mail server**, with the sender name **Admin {company}**. The SuperAdmin, with **AdminSystem** chosen, changes it there and presses **Copy to every company** to send the same server to all companies (each keeps "Admin {company}" as sender name).'],
          es: ['**Configuración › Mail Settings**: el servidor de correo desde el que envía la empresa; **Test** envía un correo de prueba.', '**Admin notices** muestra quién recibe los avisos automáticos (reservas, caja del POS); el SuperAdmin también puede recibir copia.', '**Send a copy to other emails**: escriba las direcciones (Enter después de cada una) y marque qué correos copian — **Ventas** (facturas y recibos de pago), **Recordatorios de pago**, **Compras** (órdenes y pagos). Reciben una copia oculta; clientes y proveedores nunca la ven. Presione **Submit** para guardar.', 'Cada compañía empieza con el **servidor de correo de AdminSystem**, con el remitente **Admin {compañía}**. El SuperAdmin, con **AdminSystem** escogido, lo cambia allí y presiona **Copiar a todas las compañías** para enviar el mismo servidor a todas (cada una conserva "Admin {compañía}" como remitente).'],
        },
      },
    ],
  },
];
