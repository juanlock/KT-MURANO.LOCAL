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
 * Text: plain sentences; **bold** for buttons / menu names, "A â€º B" for menu
 * paths. RULE (CLAUDE.md, Pa Llevar): a change to a module, form or role
 * updates its entry here, in English AND Spanish, in the same session.
 */

export const MANUAL_UPDATED = '2026-10-18';

export const MANUAL = [
  // ------------------------------------------------------------------ start
  {
    key: 'start', module: null, perms: [], paths: ['dashboard', 'profile', 'home-buttons'],
    title: { en: 'Getting started', es: 'Primeros pasos' },
    intro: {
      en: 'Your login belongs to one company and your role decides what you see: menus, buttons and even single tabs. If you need something that is not there, ask your company administrator.',
      es: 'Su usuario pertenece a una empresa y su rol decide lo que ve: menÃºs, botones e incluso pestaÃ±as. Si necesita algo que no aparece, pÃ­daselo al administrador de su empresa.',
    },
    sections: [
      {
        id: 'signin', paths: ['dashboard'],
        title: { en: 'Sign in and your home page', es: 'Ingresar y su pÃ¡gina de inicio' },
        steps: {
          en: ['Sign in with your email and password.', 'Murano opens your role\'s home page: the Dashboard, **Daily Tasks** (field crews) or the **Bookings calendar** (salon staff).', 'Use the left menu (â˜° on a phone) to move around.', 'On a shared computer, always sign out from your name in the top bar.'],
          es: ['Ingrese con su correo y contraseÃ±a.', 'Murano abre la pÃ¡gina de inicio de su rol: el Panel, **Tareas diarias** (cuadrillas) o el **Calendario de reservas** (personal del salÃ³n).', 'Use el menÃº de la izquierda (â˜° en el celular) para moverse.', 'En una computadora compartida, cierre siempre la sesiÃ³n desde su nombre en la barra superior.'],
        },
        note: {
          en: 'Forgot your password, or your login says it is disabled? Ask your company administrator.',
          es: 'Â¿OlvidÃ³ su contraseÃ±a o su usuario aparece deshabilitado? Consulte al administrador de su empresa.',
        },
      },
      {
        id: 'topbar', paths: ['dashboard', 'profile'],
        title: { en: 'Top bar icons', es: 'Ãconos de la barra superior' },
        intro: {
          en: 'The buttons at the top right of every page. You only see the ones your company and your role allow; you can hide or reorder yours in **Profile â€º Header icons**.',
          es: 'Los botones arriba a la derecha de cada pÃ¡gina. Solo ve los que su empresa y su rol permiten; puede ocultar o reordenar los suyos en **Perfil â€º Ãconos del encabezado**.',
        },
        // Each key is drawn with its real icon and color (ManualDrawer ICON_LOOK).
        icons: [
          { key: 'company', title: { en: 'Company selector (SuperAdmin only)', es: 'Selector de empresa (solo SuperAdmin)' }, text: { en: 'Which company you are working on. Every page and form follows the company chosen here.', es: 'En quÃ© empresa estÃ¡ trabajando. Todas las pÃ¡ginas y formularios siguen la empresa elegida aquÃ­.' } },
          { key: 'pos', title: { en: 'POS', es: 'POS' }, text: { en: 'Opens the point of sale (if your role can sell at the register).', es: 'Abre el punto de venta (si su rol puede vender en caja).' } },
          { key: 'customize', title: { en: 'Settings (gear)', es: 'ConfiguraciÃ³n (engranaje)' }, text: { en: 'Appearance of the app: theme, colors, menu layout.', es: 'Apariencia de la aplicaciÃ³n: tema, colores, distribuciÃ³n del menÃº.' } },
          { key: 'install', title: { en: 'Install app', es: 'Instalar app' }, text: { en: 'Installs Murano like an app on this computer or phone. Shown only while the browser offers it.', es: 'Instala Murano como una aplicaciÃ³n en esta computadora o celular. Aparece solo mientras el navegador lo ofrece.' } },
          { key: 'summary', title: { en: 'Como Vamos! (today\'s summary)', es: 'Â¡CÃ³mo Vamos! (resumen de hoy)' }, text: { en: 'Today at a glance: sales, payments, bookings and what is pending.', es: 'El dÃ­a de un vistazo: ventas, pagos, reservas y lo pendiente.' } },
          { key: 'fullscreen', title: { en: 'Full screen', es: 'Pantalla completa' }, text: { en: 'Uses the whole screen; click again (or Esc) to go back. Not on phones.', es: 'Usa toda la pantalla; haga clic otra vez (o Esc) para volver. No en celulares.' } },
          { key: 'manual', title: { en: 'User manual (open book)', es: 'Manual de usuario (libro abierto)' }, text: { en: 'This manual: **This page** shows help for the screen you are on, **Whole manual** has everything with a search. English or Spanish.', es: 'Este manual: **Esta pÃ¡gina** muestra la ayuda de la pantalla en la que estÃ¡, **Todo el manual** tiene todo con bÃºsqueda. InglÃ©s o espaÃ±ol.' } },
          { key: 'language', title: { en: 'Language (globe)', es: 'Idioma (globo)' }, text: { en: 'Changes the language of the app for you.', es: 'Cambia el idioma de la aplicaciÃ³n para usted.' } },
          { key: 'notifications', title: { en: 'Notifications (bell)', es: 'Notificaciones (campana)' }, text: { en: 'Work waiting for you (e.g. bookings to confirm, computers to approve). The number is how many; click an item to go there.', es: 'Trabajo pendiente (p. ej. reservas por confirmar, computadoras por aprobar). El nÃºmero indica cuÃ¡ntos; haga clic en uno para ir.' } },
          { key: 'clock', title: { en: 'Clock in / out', es: 'Marcar entrada / salida' }, text: { en: 'Clocks you in or out for today (if your role has it). Turns green with the time while you are clocked in.', es: 'Marca su entrada o salida de hoy (si su rol lo tiene). Se pone verde con la hora mientras estÃ¡ marcado.' } },
          { key: 'logout', title: { en: 'Log out', es: 'Cerrar sesiÃ³n' }, text: { en: 'Signs you out in one click. Always do it on a shared computer.', es: 'Cierra su sesiÃ³n con un clic. HÃ¡galo siempre en una computadora compartida.' } },
          { key: 'user', title: { en: 'Your name', es: 'Su nombre' }, text: { en: '**Profile**, Settings, **Appearance** (light / dark / auto) and sign out.', es: '**Perfil**, ConfiguraciÃ³n, **Apariencia** (claro / oscuro / automÃ¡tico) y cerrar sesiÃ³n.' } },
        ],
        note: {
          en: 'Company administrators choose which icons the whole company sees in **System Settings â€º Defaults â€º Users â€º Header icons**; each person can still show / hide and reorder their own in **Profile**.',
          es: 'Los administradores eligen quÃ© Ã­conos ve toda la empresa en **ConfiguraciÃ³n del sistema â€º Predeterminados â€º Usuarios â€º Ãconos del encabezado**; cada persona puede mostrar / ocultar y reordenar los suyos en **Perfil**.',
        },
      },
      {
        id: 'profile', paths: ['profile'],
        title: { en: 'Profile, password and header icons', es: 'Perfil, contraseÃ±a e Ã­conos del encabezado' },
        steps: {
          en: ['Click your name â€º **Profile**.', 'Change your password, photo and contact details, then **Save**.', 'Under **Header icons**, choose for each icon: Company default, Show (green) or Hide (red).', 'Change their order with the **â€¹ â€º** arrows: the list goes from left to right in the top bar (**Reset order** puts it back). Then **Save**.'],
          es: ['Haga clic en su nombre â€º **Perfil**.', 'Cambie su contraseÃ±a, foto y datos de contacto, luego **Guardar**.', 'En **Ãconos del encabezado**, elija para cada Ã­cono: Predeterminado de la empresa, Mostrar (verde) u Ocultar (rojo).', 'Cambie su orden con las flechas **â€¹ â€º**: la lista va de izquierda a derecha en la barra superior (**Restablecer orden** la devuelve). Luego **Guardar**.'],
        },
        note: {
          en: 'Your PIN (4 to 8 digits, unique in your company) is set by your administrator in Users. It identifies you at the shared register: never share it.',
          es: 'Su PIN (de 4 a 8 dÃ­gitos, Ãºnico en su empresa) lo asigna el administrador en Usuarios. Lo identifica en la caja compartida: nunca lo comparta.',
        },
      },
      {
        id: 'clock', perms: ['attendance_self'],
        title: { en: 'Clock in and clock out', es: 'Marcar entrada y salida' },
        steps: {
          en: ['When your shift starts, click the **clock** in the top bar. It turns green and shows your clock-in time.', 'When you leave, click it again: Murano shows the hours worked today.', 'Forgot to clock? Tell your manager; they correct it in HRM â€º Attendance.'],
          es: ['Al empezar su turno, haga clic en el **reloj** de la barra superior. Se pone verde y muestra la hora de entrada.', 'Al salir, haga clic otra vez: Murano muestra las horas trabajadas hoy.', 'Â¿OlvidÃ³ marcar? Avise a su encargado; lo corrige en RR. HH. â€º Asistencia.'],
        },
      },
      {
        id: 'filters',
        title: { en: 'Date filters and exports', es: 'Filtros de fecha y exportaciÃ³n' },
        steps: {
          en: ['Every list and report has the same date filter: All, Today, This Week â€¦ Last Year, plus Start date and End date you can type.', '**Double-click** a preset to make it your default (â˜…).', 'Export a list or report to PDF or Excel with the buttons above the table.', 'Open a record from a filtered list (Sales, Purchases, Quotations, Customers, Suppliers), then press **Back**, **Cancel** or **Save**: the list comes back exactly as you left it (filters, dates, page, search). Opening the list from the menu starts fresh.'],
          es: ['Todas las listas y reportes tienen el mismo filtro de fechas: Todo, Hoy, Esta semana â€¦ AÃ±o pasado, mÃ¡s Fecha inicial y Fecha final que puede escribir.', 'Haga **doble clic** en una opciÃ³n para dejarla como predeterminada (â˜…).', 'Exporte una lista o reporte a PDF o Excel con los botones sobre la tabla.', 'Abra un registro desde una lista filtrada (Ventas, Compras, Cotizaciones, Clientes, Proveedores) y pulse **Volver**, **Cancelar** o **Guardar**: la lista vuelve tal como la dejÃ³ (filtros, fechas, pÃ¡gina, bÃºsqueda). Al abrir la lista desde el menÃº empieza de cero.'],
        },
      },
    ],
  },

  // ------------------------------------------------------------------ sales
  {
    key: 'sales', module: null, perms: ['Sales_view', 'Sales_add'], paths: ['sales', 'sale-returns', 'shipments'],
    title: { en: 'Sales and invoices', es: 'Ventas y facturas' },
    intro: {
      en: 'Invoices are numbered automatically with your company\'s own series (prefix + the company\'s next number).',
      es: 'Las facturas se numeran solas con la serie de su empresa (prefijo + el siguiente nÃºmero de la empresa).',
    },
    sections: [
      {
        id: 'sales-list', perms: ['Sales_view'], paths: ['sales'],
        title: { en: 'Find a sale', es: 'Buscar una venta' },
        steps: {
          en: ['Open **Sales â€º List Sales**.', 'Filter by date, customer, location or payment status.', 'The **Other due** column shows the customer\'s other unpaid invoices; open it to see each invoice\'s date and the reminder emails sent (how many and when).', 'On a phone the list shows the sale information first; swipe the table sideways for more columns.'],
          es: ['Abra **Ventas â€º Lista de ventas**.', 'Filtre por fecha, cliente, local o estado de pago.', 'La columna **Otras deudas** muestra las demÃ¡s facturas impagas del cliente; Ã¡brala para ver la fecha de cada factura y los recordatorios enviados (cuÃ¡ntos y cuÃ¡ndo).', 'En el celular la lista muestra primero los datos de la venta; deslice la tabla para ver mÃ¡s columnas.'],
        },
      },
      {
        id: 'sales-add', perms: ['Sales_add'], paths: ['sales/create', 'sales/from-quotation'],
        title: { en: 'Create a sale', es: 'Crear una venta' },
        steps: {
          en: ['Open **Sales â€º Add Sale**.', 'Choose the date, the customer and the location.', 'Add products or services; adjust quantity, price, discount or tax per line if your role allows it.', 'Save. The invoice number is given automatically.', 'To repeat an invoice, use **Duplicate** in its row menu in the list: a new sale opens with today\'s date and the same customer, location and lines (paid invoices too); the top says which invoice it copies. Check it and save.', 'Editing an invoice: the top of the page shows its number (**Edit Sale Â· INV_â€¦**), customer, date, total, paid and due, its status and payment status, and **View invoice**.'],
          es: ['Abra **Ventas â€º Agregar venta**.', 'Elija la fecha, el cliente y el local.', 'Agregue productos o servicios; ajuste cantidad, precio, descuento o impuesto por lÃ­nea si su rol lo permite.', 'Guarde. El nÃºmero de factura se asigna solo.', 'Para repetir una factura, use **Duplicar** en el menÃº de su fila en la lista: se abre una venta nueva con la fecha de hoy y el mismo cliente, local y lÃ­neas (tambiÃ©n facturas pagadas); arriba indica quÃ© factura copia. RevÃ­sela y guarde.', 'Al editar una factura, arriba se ve su nÃºmero (**Editar venta Â· INV_â€¦**), cliente, fecha, total, pagado y saldo, su estado y estado de pago, y **Ver factura**.'],
        },
      },
      {
        id: 'sales-job', module: 'service', perms: ['service_jobs'], paths: ['sales/create', 'sales'],
        title: { en: 'Add a service job from the sale', es: 'Agregar un trabajo de servicio desde la venta' },
        steps: {
          en: ['In the sale form press **Add Service Job**.', 'Fill in the work, date and technician: the job is linked to this invoice.', 'Pending and completed jobs show different icons.'],
          es: ['En el formulario de venta pulse **Agregar trabajo de servicio**.', 'Complete el trabajo, la fecha y el tÃ©cnico: el trabajo queda vinculado a esta factura.', 'Los trabajos pendientes y completados se muestran con Ã­conos distintos.'],
        },
      },
      {
        id: 'sales-pay', perms: ['payment_sales_add', 'payment_sales_view'], paths: ['sales'],
        title: { en: 'Payments, printing and email', es: 'Pagos, impresiÃ³n y correo' },
        steps: {
          en: ['Open the sale (or its row menu).', '**Add payment**: amount, method and date.', 'Print it, or email it to the customer; each email is kept in the sale\'s history.'],
          es: ['Abra la venta (o el menÃº de su fila).', '**Agregar pago**: monto, mÃ©todo y fecha.', 'ImprÃ­mala o envÃ­ela por correo al cliente; cada correo queda en el historial de la venta.'],
        },
      },
    ],
  },

  // ------------------------------------------------------------------ POS
  {
    key: 'pos', module: null, perms: ['Pos_view'], paths: ['pos', 'pos-sales'],
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
          es: ['Al empezar el dÃ­a abra el **POS**.', 'Abra la caja con su PIN y el efectivo inicial.'],
        },
      },
      {
        id: 'pos-sell', paths: ['pos'],
        title: { en: 'Sell', es: 'Vender' },
        steps: {
          en: ['Enter your **PIN** so the sale is recorded for you.', 'Add the services and products; choose the customer (or Walk-in).', 'Take the payment; a sale can be split between cash and card.', 'Print or email the receipt.'],
          es: ['Ingrese su **PIN** para que la venta quede a su nombre.', 'Agregue los servicios y productos; elija el cliente (o Cliente de paso).', 'Cobre; una venta se puede dividir entre efectivo y tarjeta.', 'Imprima o envÃ­e el recibo por correo.'],
        },
        note: {
          en: 'Commission pay counts the sales recorded under your PIN, or the bookings where you are the stylist.',
          es: 'La comisiÃ³n cuenta las ventas registradas con su PIN, o las reservas donde usted es el estilista.',
        },
      },
      {
        id: 'pos-close', perms: ['register_open_close'], paths: ['pos'],
        title: { en: 'Close the register', es: 'Cerrar la caja' },
        steps: {
          en: ['At closing, close the register with your PIN and count the cash.', 'Compare with **Reports â€º Cash Register Report**.'],
          es: ['Al cierre, cierre la caja con su PIN y cuente el efectivo.', 'Compare con **Reportes â€º Reporte de caja**.'],
        },
      },
    ],
  },

  // ------------------------------------------------------------------ quotations
  {
    key: 'quotations', module: null, perms: ['Quotations_view', 'Quotations_add'], paths: ['quotations'],
    title: { en: 'Quotations', es: 'Cotizaciones' },
    intro: { en: 'Prices you offer before the sale.', es: 'Precios que ofrece antes de la venta.' },
    sections: [
      {
        id: 'quote-add', perms: ['Quotations_add'], paths: ['quotations'],
        title: { en: 'Create and convert a quotation', es: 'Crear y convertir una cotizaciÃ³n' },
        steps: {
          en: ['**Quotations â€º Add Quotation**: customer, items and prices.', 'Send it to the customer by email.', 'When accepted, open it and **Create sale**: the invoice is made from the quotation.'],
          es: ['**Cotizaciones â€º Agregar cotizaciÃ³n**: cliente, Ã­tems y precios.', 'EnvÃ­ela por correo al cliente.', 'Cuando la acepten, Ã¡brala y pulse **Crear venta**: la factura se hace desde la cotizaciÃ³n.'],
        },
      },
    ],
  },

  // ------------------------------------------------------------------ sales contracts
  {
    key: 'contracts', module: null, perms: ['sales_contracts_view'], paths: ['sales-contracts'],
    title: { en: 'Sales contracts (recurring billing)', es: 'Contratos de venta (facturaciÃ³n recurrente)' },
    intro: {
      en: 'Weekly or monthly services that bill themselves and schedule their visits.',
      es: 'Servicios semanales o mensuales que se facturan solos y programan sus visitas.',
    },
    sections: [
      {
        id: 'contract-add', perms: ['sales_contracts_add'], paths: ['sales-contracts'],
        title: { en: 'Create a contract', es: 'Crear un contrato' },
        steps: {
          en: ['**Sales â€º Sales Contracts â€º New**.', 'Pick the customer, the services, the frequency and the start date.', 'Send it for signature.', 'Murano creates each invoice and each scheduled visit on its date; you find them in the Sales list and in Daily Tasks.'],
          es: ['**Ventas â€º Contratos de venta â€º Nuevo**.', 'Elija el cliente, los servicios, la frecuencia y la fecha de inicio.', 'EnvÃ­elo para firma.', 'Murano crea cada factura y cada visita programada en su fecha; las encuentra en la lista de Ventas y en Tareas diarias.'],
        },
      },
    ],
  },

  // ------------------------------------------------------------------ customers
  {
    key: 'customers', module: null, perms: ['Customers_view'], paths: ['customers', 'customers-with-login', 'customers-without-login'],
    title: { en: 'Customers', es: 'Clientes' },
    intro: {
      en: 'One customer = one email in your company. Matching never uses the phone and never crosses companies.',
      es: 'Un cliente = un correo en su empresa. Nunca se busca por telÃ©fono ni entre empresas.',
    },
    sections: [
      {
        id: 'cust-add', perms: ['Customers_add'], paths: ['customers/create', 'customers'],
        title: { en: 'Add a customer', es: 'Agregar un cliente' },
        steps: {
          en: ['Search by email first to avoid duplicates.', '**People â€º Customers â€º Add**: name, email, phone, address.', 'With an email the customer gets a login to the customer portal (invoices, payments, bookings).'],
          es: ['Busque primero por correo para no duplicar.', '**Personas â€º Clientes â€º Agregar**: nombre, correo, telÃ©fono, direcciÃ³n.', 'Con correo, el cliente recibe acceso al portal de clientes (facturas, pagos, reservas).'],
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
          en: ['**People â€º Import Customers**: download the sample file, fill it in and upload it.', 'Rows with an email that already exists in your company update that customer instead of creating a new one.'],
          es: ['**Personas â€º Importar clientes**: descargue el archivo de ejemplo, complÃ©telo y sÃºbalo.', 'Las filas con un correo que ya existe en su empresa actualizan ese cliente en vez de crear uno nuevo.'],
        },
      },
    ],
  },

  // ------------------------------------------------------------------ purchases & suppliers
  {
    key: 'purchases', module: null, perms: ['Purchases_view', 'Suppliers_view'], paths: ['purchases', 'purchase-returns', 'suppliers'],
    title: { en: 'Purchases and suppliers', es: 'Compras y proveedores' },
    intro: { en: 'What you buy and who you buy it from.', es: 'Lo que compra y a quiÃ©n se lo compra.' },
    sections: [
      {
        id: 'sup-add', perms: ['Suppliers_add'], paths: ['suppliers'],
        title: { en: 'Add a supplier', es: 'Agregar un proveedor' },
        steps: {
          en: ['**People â€º Suppliers â€º Add**: name, email and phone.', 'With an email the supplier gets a portal login to see their purchase orders and payments.'],
          es: ['**Personas â€º Proveedores â€º Agregar**: nombre, correo y telÃ©fono.', 'Con correo, el proveedor recibe acceso al portal para ver sus Ã³rdenes de compra y pagos.'],
        },
      },
      {
        id: 'pur-add', perms: ['Purchases_add'], paths: ['purchases'],
        title: { en: 'Record a purchase', es: 'Registrar una compra' },
        steps: {
          en: ['**Purchases â€º Add Purchase**: supplier, location and items.', 'When the goods arrive mark it received: stock goes up.', 'Add the payment to the supplier from the purchase.'],
          es: ['**Compras â€º Agregar compra**: proveedor, local e Ã­tems.', 'Cuando llegue la mercaderÃ­a mÃ¡rquela como recibida: el inventario sube.', 'Registre el pago al proveedor desde la compra.'],
        },
      },
    ],
  },

  // ------------------------------------------------------------------ products
  {
    key: 'products', module: null, perms: ['products_view'], paths: ['products', 'categories', 'subcategories', 'brands', 'units', 'adjustments', 'transfers', 'damages'],
    title: { en: 'Products and services', es: 'Productos y servicios' },
    intro: { en: 'What you sell, its prices and its stock in each location.', es: 'Lo que vende, sus precios y su inventario en cada local.' },
    sections: [
      {
        id: 'prod-add', perms: ['products_add'], paths: ['products/create', 'products'],
        title: { en: 'Add a product or service', es: 'Agregar un producto o servicio' },
        steps: {
          en: ['**Products â€º Add Product** (or **+** next to the product search in a sale, to add it right there).', 'Type or pick the **main service / group** â€” a new one is kept as you type. Then choose **what you are adding**: the main item only, or a **new sub-item under it** (also under an existing one). **"Will be saved as"** shows the final name and the items already in that group; a duplicate is warned.', 'Choose the type (product with stock, or service), category and price.', 'For products set the cost, unit and alert quantity.', 'Leave the product **Tax** at 0% when your invoices use the company\'s Order Tax (System Settings â€º Defaults â€º Default Tax): otherwise that product is taxed twice. New products start at 0%.', 'Save; print barcodes from **Products â€º Print Barcode**.'],
          es: ['**Productos â€º Agregar producto** (o **+** junto a la bÃºsqueda de productos en una venta, para agregarlo ahÃ­ mismo).', 'Escriba o elija el **servicio principal / grupo** â€” uno nuevo se conserva mientras escribe. Luego elija **quÃ© estÃ¡ agregando**: solo el principal, o un **nuevo sub-servicio debajo** (tambiÃ©n bajo uno existente). **"Will be saved as"** muestra el nombre final y lo que ya hay en ese grupo; avisa si se repite.', 'Elija el tipo (producto con inventario o servicio), categorÃ­a y precio.', 'Para productos indique costo, unidad y cantidad de alerta.', 'Deje el **Impuesto** del producto en 0% cuando sus facturas usan el Impuesto de la orden de la empresa (ConfiguraciÃ³n â€º Predeterminados â€º Impuesto predeterminado): si no, ese producto paga impuesto dos veces. Los productos nuevos empiezan en 0%.', 'Guarde; imprima cÃ³digos de barras en **Productos â€º Imprimir cÃ³digo de barras**.'],
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
          en: ['**Booking Management â€º Calendar View**: switch between day, week and month.', 'Click an empty slot to add a booking: customer, service, staff member and time.', 'Drag a booking to move it, or open it to edit or cancel.', 'When the customer is served, charge the booking at the POS.'],
          es: ['**Reservas â€º Calendario**: cambie entre dÃ­a, semana y mes.', 'Haga clic en un espacio libre para agregar una reserva: cliente, servicio, persona y hora.', 'Arrastre una reserva para moverla, o Ã¡brala para editarla o cancelarla.', 'Cuando atienda al cliente, cobre la reserva en el POS.'],
        },
      },
      {
        id: 'book-confirm', paths: ['bookings'],
        title: { en: 'Confirm online bookings', es: 'Confirmar reservas en lÃ­nea' },
        steps: {
          en: ['Bookings made from your website arrive as **Pending** and the admins get an email.', 'Open the booking and **Confirm** it.', 'Only then the customer receives the confirmation email (if they gave an email).'],
          es: ['Las reservas hechas desde su sitio web llegan como **Pendiente** y los administradores reciben un correo.', 'Abra la reserva y pulse **Confirmar**.', 'ReciÃ©n entonces el cliente recibe el correo de confirmaciÃ³n (si dejÃ³ su correo).'],
        },
      },
      {
        id: 'book-online', perms: ['bookings'], paths: ['bookings/online'],
        title: { en: 'Online booking on your websites', es: 'Reservas en lÃ­nea en sus sitios web' },
        steps: {
          en: ['**Booking Management â€º Online Booking**.', 'Set the look: show or hide the logo, background color.', 'In **Websites & codes** add each website that shows the form; copy its code: embedded form, link or button.', '"Exit" and "Close" send the customer back to that website.'],
          es: ['**Reservas â€º Reservas en lÃ­nea**.', 'Ajuste el aspecto: mostrar u ocultar el logo, color de fondo.', 'En **Sitios web y cÃ³digos** agregue cada sitio que muestra el formulario; copie su cÃ³digo: formulario insertado, enlace o botÃ³n.', '"Salir" y "Cerrar" devuelven al cliente a ese sitio.'],
        },
        note: {
          en: 'Customers book in 3 steps and only the name is required. Limit: 5 bookings every 10 minutes per connection; from the 4th booking in 24 hours an email and the "I agree" link are required.',
          es: 'El cliente reserva en 3 pasos y solo el nombre es obligatorio. LÃ­mite: 5 reservas cada 10 minutos por conexiÃ³n; desde la 4.Âª reserva en 24 horas se exige correo y el enlace "Acepto".',
        },
      },
      {
        id: 'book-trays', perms: ['trays'], paths: ['bookings/trays'],
        title: { en: 'Trays', es: 'Bandejas' },
        steps: {
          en: ['**Booking Management â€º Trays** groups the bookings waiting for an action (to confirm, to charge).'],
          es: ['**Reservas â€º Bandejas** agrupa las reservas que esperan una acciÃ³n (por confirmar, por cobrar).'],
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
      es: 'Trabajo de campo: trabajos para cuadrillas y tÃ©cnicos, vinculados al cliente y a la factura.',
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
          es: 'La planilla semanal usa las horas de los trabajos que COMPLETA esa semana: complete cada trabajo el mismo dÃ­a.',
        },
      },
      {
        id: 'daily-assign', perms: ['daily_tasks_all', 'daily_tasks_assign'], paths: ['service/daily'],
        title: { en: 'Daily Tasks: every crew and assigning', es: 'Tareas diarias: todas las cuadrillas y asignaciÃ³n' },
        steps: {
          en: ['With "all tasks" you see every crew\'s jobs.', 'With "assign" choose the technician on each job from the same screen.'],
          es: ['Con "todas las tareas" ve los trabajos de todas las cuadrillas.', 'Con "asignar" elija el tÃ©cnico de cada trabajo desde la misma pantalla.'],
        },
      },
      {
        id: 'jobs', perms: ['service_jobs'], paths: ['service/jobs', 'service/history'],
        title: { en: 'Create and follow service jobs', es: 'Crear y seguir trabajos de servicio' },
        steps: {
          en: ['**Service & Maintenance â€º All Jobs â€º Create**: customer, date, work to do, technician or crew.', 'Record the equipment used; the job cost adds labor hours and equipment.', 'Follow unpaid work in **Awaiting Payment** and the history in **Maintenance History**.'],
          es: ['**Servicio y mantenimiento â€º Todos los trabajos â€º Crear**: cliente, fecha, trabajo, tÃ©cnico o cuadrilla.', 'Registre el equipo usado; el costo del trabajo suma horas de mano de obra y equipo.', 'Siga lo impago en **Pendiente de pago** y el historial en **Historial de mantenimiento**.'],
        },
      },
      {
        id: 'techs', perms: ['service_jobs'], paths: ['service/technicians', 'service/checklists', 'service/checklist-categories'],
        title: { en: 'Operators and checklists', es: 'Operarios y listas de control' },
        steps: {
          en: ['**Service Setup â€º Operators**: the people who can receive jobs (linked to their employee).', '**Checklist Items**: the steps a job must tick before it is complete.'],
          es: ['**ConfiguraciÃ³n de servicio â€º Operarios**: las personas que pueden recibir trabajos (vinculadas a su ficha de empleado).', '**Ãtems de control**: los pasos que un trabajo debe marcar antes de completarse.'],
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
      es: 'Cada empleado tiene un usuario, creado automÃ¡ticamente. Cada pestaÃ±a de la ficha del empleado tiene su propio permiso de ver / editar.',
    },
    sections: [
      {
        id: 'emp-add', perms: ['add_employee'], paths: ['hrm/employees/create', 'hrm/employees'],
        title: { en: 'Add an employee', es: 'Agregar un empleado' },
        steps: {
          en: ['**HRM â€º Employees â€º Add**: personal data, department, designation, shift.', 'Set the pay type: **hourly** (rate) or **commission** (%), and the date it starts.', 'The login is created automatically; it is active when the company has a default employee password (System Settings â€º Defaults â€º Users).'],
          es: ['**RR. HH. â€º Empleados â€º Agregar**: datos personales, Ã¡rea, cargo, turno.', 'Indique el tipo de pago: **por hora** (tarifa) o **comisiÃ³n** (%), y desde quÃ© fecha.', 'El usuario se crea solo; queda activo si la empresa tiene una contraseÃ±a predeterminada para empleados (ConfiguraciÃ³n â€º Predeterminados â€º Usuarios).'],
        },
      },
      {
        id: 'emp-details', perms: ['view_employee'], paths: ['hrm/employees'],
        title: { en: 'The employee page', es: 'La ficha del empleado' },
        steps: {
          en: ['Tabs: basic data, company, social, experience, accounts, user (login), production, payments and **Assets assigned**.', 'You see only the tabs your role allows; without "all employees" you see only your own record.', '**Print profile** prints the whole record with the photo.'],
          es: ['PestaÃ±as: datos bÃ¡sicos, empresa, redes, experiencia, cuentas, usuario (acceso), producciÃ³n, pagos y **Activos asignados**.', 'Solo ve las pestaÃ±as que su rol permite; sin "todos los empleados" solo ve su propia ficha.', '**Imprimir perfil** imprime toda la ficha con la foto.'],
        },
      },
      {
        id: 'emp-remove', perms: ['delete_employee'], paths: ['hrm/employees'],
        title: { en: 'Remove an employee', es: 'Dar de baja a un empleado' },
        steps: {
          en: ['Open the employee â€º **Remove** and set the leaving date.', 'In one step Murano disables the login and signs it out everywhere, unassigns open jobs, upcoming bookings and tickets, and lists the assets to collect.', 'The history (sales, jobs, attendance) is kept.'],
          es: ['Abra el empleado â€º **Dar de baja** e indique la fecha de salida.', 'En un solo paso Murano deshabilita el usuario y cierra sus sesiones, libera trabajos abiertos, reservas prÃ³ximas y tickets, y lista los activos por recoger.', 'El historial (ventas, trabajos, asistencia) se conserva.'],
        },
      },
      {
        id: 'attendance', perms: ['attendance'], paths: ['hrm/attendance'],
        title: { en: 'Attendance', es: 'Asistencia' },
        steps: {
          en: ['**HRM â€º Attendance** lists every clock-in and clock-out.', 'Add or correct a record when someone forgot to clock.', 'Totals by person: **Reports â€º Attendance Summary**.'],
          es: ['**RR. HH. â€º Asistencia** muestra cada entrada y salida.', 'Agregue o corrija un registro cuando alguien olvidÃ³ marcar.', 'Totales por persona: **Reportes â€º Resumen de asistencia**.'],
        },
      },
      {
        id: 'leaves', perms: ['leave'], paths: ['hrm/leaves', 'hrm/leave-types'],
        title: { en: 'Leave requests', es: 'Solicitudes de permiso' },
        steps: {
          en: ['**HRM â€º Leave request â€º Add**: employee, type, dates and reason.', 'Approve or reject it from the list.'],
          es: ['**RR. HH. â€º Permisos â€º Agregar**: empleado, tipo, fechas y motivo.', 'ApruÃ©belo o rechÃ¡zelo desde la lista.'],
        },
      },
      {
        id: 'payroll', perms: ['payroll'], paths: ['hrm/payrolls'],
        title: { en: 'Weekly payroll', es: 'Planilla semanal' },
        steps: {
          en: ['**HRM â€º Payroll â€º Generate weekly payroll** and choose the week.', 'Hourly: hours of the jobs completed that week Ã— rate. Commission: that week\'s sales Ã— %.', 'Check each line and mark it paid: it is posted to accounting automatically.'],
          es: ['**RR. HH. â€º Planillas â€º Generar planilla semanal** y elija la semana.', 'Por hora: horas de los trabajos completados esa semana Ã— tarifa. ComisiÃ³n: ventas de la semana Ã— %.', 'Revise cada lÃ­nea y mÃ¡rquela pagada: se registra en la contabilidad automÃ¡ticamente.'],
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
          en: ['**Email Office â€º Received** or **Sent**.', 'Open a message to read it and its attachments; it is linked to the customer or supplier with that address.', 'Payment emails (for example Zelle) are detected and can be matched to the customer.'],
          es: ['**Email Office â€º Recibidos** o **Enviados**.', 'Abra un mensaje para leerlo con sus adjuntos; queda vinculado al cliente o proveedor con esa direcciÃ³n.', 'Los correos de pago (por ejemplo Zelle) se detectan y se pueden asociar al cliente.'],
        },
      },
      {
        id: 'mail-assign', perms: ['email_office_view'], paths: ['email-office/received', 'email-office/sent'],
        title: { en: 'Emails assigned to customers by themselves', es: 'Correos asignados solos a los clientes' },
        steps: {
          en: [
            '**Received**: assigned to the customer whose email (or additional email) is the sender.',
            '**Sent**: assigned to the first recipient that is a customer â€” the To addresses, then CC. Invoices and reminders sent from a sale go to that sale\'s customer even when sent to another address.',
            'When you add or change a customer\'s email (People â€º Customers), their emails not assigned yet are assigned at once.',
            'Assigning one email assigns **every email with the same address** â€” received from it and sent to it (To or CC) â€” to that customer. With "Save this address on the customer" ticked (the default) the address is also saved on the customer, so future emails go to them by themselves.',
          ],
          es: [
            '**Recibidos**: se asignan al cliente cuyo email (o email adicional) es el remitente.',
            '**Enviados**: se asignan al primer destinatario que sea cliente â€” las direcciones de Para y luego las de CC. Las facturas y recordatorios enviados desde una venta van al cliente de esa venta aunque se envÃ­en a otra direcciÃ³n.',
            'Al agregar o cambiar el email de un cliente (Personas â€º Clientes), sus correos sin asignar se le asignan al momento.',
            'Al asignar un correo se asignan **todos los correos con la misma direcciÃ³n** â€” recibidos de ella y enviados a ella (Para o CC) â€” a ese cliente. Con "Save this address on the customer" marcado (viene asÃ­) la direcciÃ³n tambiÃ©n se guarda en el cliente, y los correos futuros le llegan solos.',
          ],
        },
        note: {
          en: 'Matching is by email only and within your company; a contact email counts only when a single customer has it.',
          es: 'La asignaciÃ³n es solo por email y dentro de su empresa; un email de contacto cuenta solo si lo tiene un Ãºnico cliente.',
        },
      },
      {
        id: 'mail-settings', perms: ['email_office_manage'], paths: ['email-office/settings'],
        title: { en: 'Mailbox settings and rules', es: 'ConfiguraciÃ³n y reglas del correo' },
        steps: {
          en: ['**Email Office â€º Settings**: the mailbox account, folders and import.', 'Rules sort incoming mail into bills, claims or general.'],
          es: ['**Email Office â€º ConfiguraciÃ³n**: la cuenta de correo, carpetas e importaciÃ³n.', 'Las reglas clasifican el correo entrante en facturas, reclamos o general.'],
        },
      },
    ],
  },

  // ------------------------------------------------------------------ website
  {
    key: 'website', module: 'website', perms: ['Store_settings_view'], paths: ['website'],
    title: { en: 'Website', es: 'Sitio web' },
    intro: {
      en: 'The company website, with its own address. It can use our website theme or an imported website.',
      es: 'El sitio web de la empresa, con su propia direcciÃ³n. Puede usar nuestro tema o un sitio web importado.',
    },
    sections: [
      {
        id: 'web-settings', paths: ['website/settings', 'website/front-page'],
        title: { en: 'Website settings', es: 'ConfiguraciÃ³n del sitio' },
        steps: {
          en: ['**Website Management â€º Website Settings**: Front page, Homepage blocks and Branding (website address, name, logo).', 'With our theme you also get Hero, SEO, Top bar & footer and Social links; an imported website brings its own.'],
          es: ['**Sitio web â€º ConfiguraciÃ³n**: Portada, Bloques de inicio y Marca (direcciÃ³n del sitio, nombre, logo).', 'Con nuestro tema tambiÃ©n tiene Portada principal, SEO, Barra superior y pie, y Redes; un sitio importado trae los suyos.'],
        },
      },
      {
        id: 'web-pages', paths: ['website/pages'],
        title: { en: 'Edit pages and sections', es: 'Editar pÃ¡ginas y secciones' },
        steps: {
          en: ['**Website â€º Pages** lists each page of the imported website.', 'Edit texts, pictures and links, or open **Sections** to edit a whole block.', 'In a section, **Use our booking calendar** puts Murano\'s booking form in place of an old one.'],
          es: ['**Sitio web â€º PÃ¡ginas** lista cada pÃ¡gina del sitio importado.', 'Edite textos, imÃ¡genes y enlaces, o abra **Secciones** para editar un bloque completo.', 'En una secciÃ³n, **Usar nuestro calendario de reservas** pone el formulario de reservas de Murano en lugar del anterior.'],
        },
      },
      {
        id: 'web-import', paths: ['website/front-page'],
        title: { en: 'Import a website', es: 'Importar un sitio web' },
        steps: {
          en: ['**Front page â€º Import**: from the site\'s address or its files.', 'Before replacing the current site, Murano saves it in **Saved websites** (Preview, Use this, Delete).'],
          es: ['**Portada â€º Importar**: desde la direcciÃ³n del sitio o sus archivos.', 'Antes de reemplazar el sitio actual, Murano lo guarda en **Sitios guardados** (Ver, Usar este, Eliminar).'],
        },
      },
    ],
  },

  // ------------------------------------------------------------------ gallery
  {
    key: 'gallery', module: 'gallery', perms: ['Store_settings_view'], paths: ['gallery'],
    title: { en: 'Gallery', es: 'GalerÃ­a' },
    intro: { en: 'Pictures for the website\'s gallery page and block.', es: 'ImÃ¡genes para la pÃ¡gina y el bloque de galerÃ­a del sitio web.' },
    sections: [
      {
        id: 'gal', paths: ['gallery'],
        title: { en: 'Manage pictures', es: 'Administrar imÃ¡genes' },
        steps: {
          en: ['Upload pictures, drag them into order and show or hide each one.', 'Or import them from the imported website.'],
          es: ['Suba imÃ¡genes, ordÃ©nelas arrastrando y muestre u oculte cada una.', 'O impÃ³rtelas desde el sitio web importado.'],
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
          en: ['**Portfolio â€º Photos**: add works with photos, sliders or videos and a category.', '**Home sections** and **Settings**: what the portfolio page shows.', '**Messages**: what visitors send from it.'],
          es: ['**Portafolio â€º Fotos**: agregue trabajos con fotos, carruseles o videos y una categorÃ­a.', '**Secciones de inicio** y **ConfiguraciÃ³n**: lo que muestra la pÃ¡gina del portafolio.', '**Mensajes**: lo que envÃ­an los visitantes.'],
        },
      },
    ],
  },

  // ------------------------------------------------------------------ store
  {
    key: 'store', module: 'Store', perms: ['Store_settings_view', 'Orders_view'], paths: ['store'],
    title: { en: 'Online store', es: 'Tienda en lÃ­nea' },
    intro: { en: 'Sell online with the same products, prices and stock.', es: 'Venda en lÃ­nea con los mismos productos, precios e inventario.' },
    sections: [
      {
        id: 'store-orders', perms: ['Orders_view'], paths: ['store/orders', 'store/quote-requests', 'store/returns'],
        title: { en: 'Online orders', es: 'Pedidos en lÃ­nea' },
        steps: {
          en: ['**Store â€º Online Orders**: new orders show a badge in the menu.', 'Open an order to confirm, ship or cancel it.'],
          es: ['**Tienda â€º Pedidos en lÃ­nea**: los pedidos nuevos muestran un indicador en el menÃº.', 'Abra un pedido para confirmarlo, enviarlo o cancelarlo.'],
        },
      },
      {
        id: 'store-setup', perms: ['Store_settings_view'], paths: ['store'],
        title: { en: 'Store setup', es: 'ConfiguraciÃ³n de la tienda' },
        steps: {
          en: ['**Store â€º Settings**, **Shipping Methods / Zones**, **Tax Rates**, **Coupons** and **Flash Sales**.'],
          es: ['**Tienda â€º ConfiguraciÃ³n**, **MÃ©todos / Zonas de envÃ­o**, **Impuestos**, **Cupones** y **Ofertas relÃ¡mpago**.'],
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
          en: ['**Help desk â€º My tickets â€º New**: describe the problem and attach a screenshot.', 'Follow the answers in the same ticket.'],
          es: ['**Mesa de ayuda â€º Mis tickets â€º Nuevo**: describa el problema y adjunte una captura.', 'Siga las respuestas en el mismo ticket.'],
        },
      },
      {
        id: 'hd-work', perms: ['helpdesk_view'], paths: ['helpdesk/tickets', 'helpdesk/dashboard'],
        title: { en: 'Answer tickets', es: 'Responder tickets' },
        steps: {
          en: ['**Help desk â€º Tickets**: filter by status, assign, answer and close.', 'Settings (categories, agents): **Help desk â€º Settings**.'],
          es: ['**Mesa de ayuda â€º Tickets**: filtre por estado, asigne, responda y cierre.', 'ConfiguraciÃ³n (categorÃ­as, agentes): **Mesa de ayuda â€º ConfiguraciÃ³n**.'],
        },
      },
    ],
  },

  // ------------------------------------------------------------------ accounting
  {
    key: 'accounting', module: 'accounting', perms: ['accounting_dashboard', 'expense_view', 'deposit_view', 'account'], paths: ['accounting-v2', 'expenses', 'deposits', 'accounts', 'transfer-money'],
    title: { en: 'Accounting, expenses and deposits', es: 'Contabilidad, gastos y depÃ³sitos' },
    intro: {
      en: 'Sales, purchases, payroll and expenses post to the books automatically.',
      es: 'Ventas, compras, planillas y gastos se registran solos en la contabilidad.',
    },
    sections: [
      {
        id: 'acc-exp', perms: ['expense_add', 'expense_view'], paths: ['expenses'],
        title: { en: 'Record an expense', es: 'Registrar un gasto' },
        steps: {
          en: ['**Accounting â€º Create Expense**: date, category, account, amount and the bill.', 'Deposits work the same way in **Create deposit**.'],
          es: ['**Contabilidad â€º Crear gasto**: fecha, categorÃ­a, cuenta, monto y la factura.', 'Los depÃ³sitos funcionan igual en **Crear depÃ³sito**.'],
        },
      },
      {
        id: 'acc-reports', perms: ['accounting_dashboard', 'accounting_profit_loss', 'balance_sheet'], paths: ['accounting-v2'],
        title: { en: 'Financial reports', es: 'Reportes financieros' },
        steps: {
          en: ['Profit and loss, balance sheet, trial balance and tax summary under **Accounting**.', 'Journal entries show every automatic and manual posting.'],
          es: ['Estado de resultados, balance general, balance de comprobaciÃ³n y resumen de impuestos en **Contabilidad**.', 'Los asientos contables muestran cada registro automÃ¡tico y manual.'],
        },
      },
    ],
  },

  // ------------------------------------------------------------------ assets
  {
    key: 'assets', module: 'assets', perms: ['assets'], paths: ['assets'],
    title: { en: 'Assets and equipment', es: 'Activos y equipos' },
    intro: { en: 'Equipment and tools: who has them, maintenance and value.', es: 'Equipos y herramientas: quiÃ©n los tiene, mantenimiento y valor.' },
    sections: [
      {
        id: 'asset-use', paths: ['assets'],
        title: { en: 'Register and assign', es: 'Registrar y asignar' },
        steps: {
          en: ['**Asset Management â€º Add Asset**: name, category, cost and hourly cost (used in job costs).', '**Assignments**: give it to an employee; it shows on their Assets tab.', '**Due Assets** lists what must come back.'],
          es: ['**Activos â€º Agregar activo**: nombre, categorÃ­a, costo y costo por hora (usado en el costo de trabajos).', '**Asignaciones**: entrÃ©guelo a un empleado; aparece en su pestaÃ±a Activos.', '**Activos por devolver** lista lo que debe regresar.'],
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
          en: ['**Commissions â€º Sales Agents**: add an agent; their login is created here.', '**Programs** and **Rules**: the % per product or category.', '**Receipts** and **Report**: what is owed and paid.'],
          es: ['**Comisiones â€º Vendedores**: agregue un vendedor; su usuario se crea aquÃ­.', '**Programas** y **Reglas**: el % por producto o categorÃ­a.', '**Recibos** y **Reporte**: lo que se debe y lo pagado.'],
        },
      },
    ],
  },

  // ------------------------------------------------------------------ marketing
  {
    key: 'marketing', module: 'marketing', perms: ['marketing_dashboard', 'marketing_campaigns'], paths: ['marketing'],
    title: { en: 'Marketing', es: 'Marketing' },
    intro: { en: 'Campaigns to the right customers by email, SMS or WhatsApp.', es: 'CampaÃ±as a los clientes correctos por correo, SMS o WhatsApp.' },
    sections: [
      {
        id: 'mkt', paths: ['marketing'],
        title: { en: 'Send a campaign', es: 'Enviar una campaÃ±a' },
        steps: {
          en: ['**Customer Segments**: choose who receives it.', '**Templates**: the message for email, SMS or WhatsApp.', '**Campaigns â€º Create**: segment + template + date; results in **Reports**.'],
          es: ['**Segmentos de clientes**: elija quiÃ©n la recibe.', '**Plantillas**: el mensaje para correo, SMS o WhatsApp.', '**CampaÃ±as â€º Crear**: segmento + plantilla + fecha; resultados en **Reportes**.'],
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
          en: ['**Projects â€º Add**: customer, dates and team.', '**Tasks** and the **Task Board**: drag tasks between columns.', '**Timesheets**: hours per person and project.'],
          es: ['**Proyectos â€º Agregar**: cliente, fechas y equipo.', '**Tareas** y el **Tablero**: arrastre las tareas entre columnas.', '**Hojas de tiempo**: horas por persona y proyecto.'],
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
          en: ['**Intranet â€º Home**: latest news and events; **Calendar** with birthdays; **Files** and **Gallery**.'],
          es: ['**Intranet â€º Inicio**: Ãºltimas noticias y eventos; **Calendario** con cumpleaÃ±os; **Archivos** y **GalerÃ­a**.'],
        },
      },
      {
        id: 'intra-admin', perms: ['intranet_manage'], paths: ['intranet-admin'],
        title: { en: 'Publish on the intranet', es: 'Publicar en la intranet' },
        steps: {
          en: ['**Intranet Management**: news, files, gallery albums, events and pages.'],
          es: ['**AdministraciÃ³n de intranet**: noticias, archivos, Ã¡lbumes, eventos y pÃ¡ginas.'],
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
          en: ['Open **Reports** and pick one (sales, payments, profit, customers, stock, cash register, attendance â€¦).', 'Set the dates with the date filter and the location if needed.', 'Export to PDF or Excel.'],
          es: ['Abra **Reportes** y elija uno (ventas, pagos, utilidad, clientes, inventario, caja, asistencia â€¦).', 'Indique las fechas con el filtro y el local si hace falta.', 'Exporte a PDF o Excel.'],
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
          en: ['**Users â€º Add** creates administrator logins only. Employees, customers, suppliers and sales agents get theirs from their own module.', 'Edit a login here: role, locations, password and PIN.', 'The **Active / Disabled** switch signs the person out everywhere. You can\'t disable yourself.', 'Filter the list by **Type**, **Role** (or "No role") and **Status**; **Clear filters** shows everyone again.', 'When the login belongs to an employee, **Edit** also shows that employee (department, position, shift, pay) with a link to HRM â€º Employees, where it is changed.'],
          es: ['**Usuarios â€º Agregar** crea solo accesos de administrador. Empleados, clientes, proveedores y vendedores reciben el suyo desde su propio mÃ³dulo.', 'Edite un acceso aquÃ­: rol, locales, contraseÃ±a y PIN.', 'El interruptor **Activo / Deshabilitado** cierra la sesiÃ³n de la persona en todos lados. No puede deshabilitarse a sÃ­ mismo.', 'Filtre la lista por **Tipo**, **Rol** (o "Sin rol") y **Estado**; **Clear filters** vuelve a mostrar a todos.', 'Cuando el acceso pertenece a un empleado, **Editar** tambiÃ©n muestra ese empleado (departamento, puesto, turno, pago) con un enlace a RR. HH. â€º Empleados, donde se cambia.'],
        },
      },
      {
        id: 'roles', perms: ['permissions_view', 'permissions_edit'], paths: ['permissions'],
        title: { en: 'Roles', es: 'Roles' },
        steps: {
          en: ['**User Management â€º Group Permissions**: create or edit a role.', 'Tick what the role can do, module by module and, for employees, tab by tab (view / edit).', 'Choose the role\'s **Home page** (for example Daily Tasks or the Bookings calendar).', 'The **Users** column shows how many users have each role; click the number to open Users filtered by that role.'],
          es: ['**Usuarios â€º Permisos de grupo**: cree o edite un rol.', 'Marque lo que el rol puede hacer, mÃ³dulo por mÃ³dulo y, para empleados, pestaÃ±a por pestaÃ±a (ver / editar).', 'Elija la **PÃ¡gina de inicio** del rol (por ejemplo Tareas diarias o el Calendario de reservas).', 'La columna **Usuarios** muestra cuÃ¡ntos usuarios tienen cada rol; haga clic en el nÃºmero para abrir Usuarios filtrado por ese rol.'],
        },
      },
    ],
  },

  // ------------------------------------------------------------------ settings
  {
    key: 'settings', module: null, perms: ['setting_system', 'pos_settings', 'mail_settings', 'payment_methods', 'company', 'network_security'], paths: ['settings', 'hrm/companies'],
    title: { en: 'Settings', es: 'ConfiguraciÃ³n' },
    intro: { en: 'Company-wide options.', es: 'Opciones para toda la empresa.' },
    sections: [
      {
        id: 'set-companies', perms: ['company'], paths: ['hrm/companies'],
        title: { en: 'Companies', es: 'Empresas' },
        steps: {
          en: ['**Add** (SuperAdmin) or **Edit** a company. The form goes in steps (Company, Helpdesk, Defaults, then Admin user and Website when adding); use **Next / Back** or click a step. A step marked in red has something missing.', 'Required: name, business type, owner, company email, phone, address and helpdesk email.', '**Defaults**: currency, language, sale prefix (invoice numbers), public address and the default employee / customer passwords (blank keeps the current one).', 'The **Setup** column shows "Complete" or how many required fields a company still lacks; click it to fill them in.', 'A new company starts with the SuperAdmin\'s current menu (the AdminSystem layout), already in order.'],
          es: ['**Agregar** (SuperAdmin) o **Editar** una empresa. El formulario va por pasos (Empresa, Helpdesk, Predeterminados y, al agregar, Usuario administrador y Sitio web); use **Next / Back** o haga clic en un paso. Un paso marcado en rojo tiene algo pendiente.', 'Obligatorios: nombre, tipo de negocio, dueÃ±o, correo de la empresa, telÃ©fono, direcciÃ³n y correo de soporte (helpdesk).', '**Predeterminados**: moneda, idioma, prefijo de ventas (nÃºmero de factura), direcciÃ³n pÃºblica y las contraseÃ±as predeterminadas de empleados / clientes (en blanco mantiene la actual).', 'La columna **Setup** muestra "Complete" o cuÃ¡ntos datos obligatorios le faltan a una empresa; haga clic para completarlos.', 'Una empresa nueva empieza con el menÃº actual del SuperAdmin (el de AdminSystem), ya ordenado.'],
        },
      },
      {
        id: 'set-modules', perms: ['business_modules', 'setting_system'], paths: ['settings/business-modules'],
        title: { en: 'Business modules (turn modules on / off per company)', es: 'MÃ³dulos (activar / desactivar mÃ³dulos por empresa)' },
        steps: {
          en: [
            '**SuperAdmin** only: **Settings â€º Modules** (Business Modules), with the company chosen in the company switcher.',
            'The list is alphabetical: Accounting, Real Estate, Recruits & Jobs, Meetings, Fleet, Projects, Kitchen, each integration (QuickBooks, Xero, Google Sheets, Mailchimp, Slack, Telegram, PrestaShop, Salla, Jumiaâ€¦) and more. Turn the switch on / off and **Submit**.',
            'A module turned off disappears from that company\'s menu, its pages can\'t be opened and the server refuses its requests â€” for every user and role. Nothing is deleted: turning it back on restores everything as it was.',
            'Sales, products, purchases, people, reports and settings are the core of the system and always stay on.',
            '**ZATCA E-Invoicing (Saudi Arabia)** is OFF by default: turn it on only for a company in Saudi Arabia. While it is off there is no ZATCA settings page, no ZATCA QR on invoices / receipts and nothing is sent to ZATCA when a sale or return is saved.',
          ],
          es: [
            'Solo el **SuperAdmin**: **ConfiguraciÃ³n â€º MÃ³dulos** (Business Modules), con la empresa elegida en el selector de empresa.',
            'La lista va en orden alfabÃ©tico: Contabilidad, Bienes raÃ­ces (Real Estate), Reclutamiento, Reuniones, Flota, Proyectos, Cocina, cada integraciÃ³n (QuickBooks, Xero, Google Sheets, Mailchimp, Slack, Telegram, PrestaShop, Salla, Jumiaâ€¦) y mÃ¡s. Active / desactive el interruptor y **Submit**.',
            'Un mÃ³dulo desactivado desaparece del menÃº de esa empresa, sus pÃ¡ginas no se pueden abrir y el servidor rechaza sus pedidos â€” para todos los usuarios y roles. No se borra nada: al activarlo de nuevo vuelve todo como estaba.',
            'Ventas, productos, compras, personas, reportes y configuraciÃ³n son la base del sistema y siempre quedan activos.',
            '**ZATCA E-Invoicing (Arabia Saudita)** viene DESACTIVADO: actÃ­velo solo para una empresa en Arabia Saudita. Mientras estÃ© desactivado no hay pÃ¡gina de configuraciÃ³n de ZATCA, ni cÃ³digo QR de ZATCA en facturas / recibos, y no se envÃ­a nada a ZATCA al guardar una venta o devoluciÃ³n.',
          ],
        },
      },
      {
        id: 'set-menu', perms: ['setting_system'], paths: ['settings/sidebar-menu'],
        title: { en: 'Manage Menu (order of the side menu)', es: 'Manage Menu (orden del menÃº lateral)' },
        steps: {
          en: [
            '**Settings â€º Manage Menu**: drag items to reorder them or move them into another group; hide / show items, then **Submit**.',
            '**SuperAdmin working as AdminSystem** (company switcher): Submit and Reset apply to **every company** â€” their own order is replaced. New companies also start with this layout.',
            '**SuperAdmin working as one company**, or a company **Admin**: the change stays in that company only.',
            'An Admin can reorder and hide / show; adding, removing or renaming items is for the SuperAdmin.',
            'Modules turned off for the company (Settings â€º Modules) are not listed here nor in the side menu â€” also their items kept elsewhere (e.g. Hospital Reports under Reports). They keep their place and come back when the module is turned on.',
          ],
          es: [
            '**ConfiguraciÃ³n â€º Manage Menu**: arrastre las opciones para reordenarlas o moverlas a otro grupo; oculte / muestre opciones y luego **Submit**.',
            '**SuperAdmin trabajando como AdminSystem** (selector de empresa): Submit y Reset se aplican a **todas las empresas** â€” se reemplaza su propio orden. Las empresas nuevas tambiÃ©n empiezan con este menÃº.',
            '**SuperAdmin trabajando como una empresa**, o el **Admin** de una empresa: el cambio queda solo en esa empresa.',
            'Un Admin puede reordenar y ocultar / mostrar; agregar, quitar o renombrar opciones es del SuperAdmin.',
            'Los mÃ³dulos desactivados para la empresa (ConfiguraciÃ³n â€º MÃ³dulos) no aparecen aquÃ­ ni en el menÃº lateral â€” tampoco sus opciones puestas en otro lugar (p. ej. Hospital Reports dentro de Reportes). Conservan su lugar y vuelven al activar el mÃ³dulo.',
          ],
        },
      },
      {
        id: 'set-defaults', perms: ['setting_system'], paths: ['settings/system'],
        title: { en: 'System Settings â€º Defaults', es: 'ConfiguraciÃ³n del sistema â€º Predeterminados' },
        steps: {
          en: ['**General**: the company\'s public address used in every link sent outside (booking codes, emails).', '**Users**: the default employee password and the **Header icons** shown to everyone in the company.', '**Invoice PDF â€º Default**: the **Discount column** and **Tax column** switches hide those columns on the printed invoice AND on the sale form; each shows again by itself on an invoice where a line has a discount or its own tax (a product with a tax %).', '**Customers** and the other tabs: what each form shows by default.'],
          es: ['**General**: la direcciÃ³n pÃºblica de la empresa usada en cada enlace enviado afuera (cÃ³digos de reserva, correos).', '**Usuarios**: la contraseÃ±a predeterminada de empleados y los **Ãconos del encabezado** para toda la empresa.', '**PDF de factura â€º Predeterminado**: los interruptores **Columna de descuento** y **Columna de impuesto** ocultan esas columnas en la factura impresa Y en el formulario de venta; cada una vuelve a aparecer sola en una factura donde una lÃ­nea tiene descuento o su propio impuesto (un producto con % de impuesto).', '**Clientes** y las demÃ¡s pestaÃ±as: lo que cada formulario muestra por defecto.'],
        },
      },
      {
        id: 'set-pos', perms: ['pos_settings'], paths: ['settings/pos'],
        title: { en: 'POS settings', es: 'ConfiguraciÃ³n del POS' },
        steps: {
          en: ['Receipt, payment methods at the counter and whether the POS button shows in the header.'],
          es: ['Recibo, medios de pago en el mostrador y si el botÃ³n POS aparece en el encabezado.'],
        },
      },
      {
        id: 'net-sec', perms: ['network_security'], paths: ['settings/network-security'],
        title: { en: 'Network security', es: 'Seguridad de red' },
        steps: {
          en: ['**Settings â€º Network security**: turn on **Restrict access by network** and add the office networks (its public IP, e.g. 203.0.113.10, or a range like 203.0.113.0/24). **Add it** puts your current IP in the list.', 'Choose what users follow: **Restricted** (safe default) or **Anywhere**; under **People** set someone on their own (e.g. a field worker on phone data = Can sign in from anywhere).', 'A restricted user signing in from outside sends that computer to **Computers** (and the bell): **Approve** it once and it works from anywhere; **Remove** it when a laptop is lost or someone leaves.', 'Only the admin panel is restricted: store, websites, portfolio, online booking and the portals stay open. The SuperAdmin is never blocked. If saving would block you, Murano asks first.'],
          es: ['**ConfiguraciÃ³n â€º Seguridad de red**: active **Restringir el acceso por red** y agregue las redes de la oficina (su IP pÃºblica, p. ej. 203.0.113.10, o un rango como 203.0.113.0/24). **Agregarla** pone su IP actual en la lista.', 'Elija quÃ© siguen los usuarios: **Restringido** (lo seguro) o **Desde cualquier lugar**; en **Personas** configure a alguien por separado (p. ej. un trabajador de campo con datos del celular = Desde cualquier lugar).', 'Un usuario restringido que entra desde afuera envÃ­a esa computadora a **Computadoras** (y a la campana): **ApruÃ©bela** una vez y funciona desde cualquier lugar; **QuÃ­tela** si se pierde una laptop o alguien deja la empresa.', 'Solo se restringe el panel de administraciÃ³n: tienda, sitios web, portafolio, reservas en lÃ­nea y los portales siguen abiertos. El SuperAdmin nunca queda bloqueado. Si al guardar usted quedarÃ­a bloqueado, Murano pregunta antes.'],
        },
        note: {
          en: 'Blocked users see: "You can\'t use Murano from this networkâ€¦" on the sign-in page. Clearing the browser\'s data or using another browser needs a new approval.',
          es: 'Los usuarios bloqueados ven: "You can\'t use Murano from this networkâ€¦" en la pÃ¡gina de ingreso. Borrar los datos del navegador o usar otro navegador requiere una nueva aprobaciÃ³n.',
        },
      },
      {
        id: 'set-mail', perms: ['mail_settings'], paths: ['settings/mail'],
        title: { en: 'Mail settings', es: 'ConfiguraciÃ³n de correo' },
        steps: {
          en: ['**Settings â€º Mail Settings**: the mail server the company sends from; **Test** sends a test email.', '**Admin notices** lists who gets the automatic notices (bookings, POS register); the SuperAdmin can also get a copy.', '**Send a copy to other emails**: type the addresses (Enter after each) and tick which emails they copy â€” **Sales** (invoices and payment receipts), **Payment reminders**, **Purchases** (orders and payments). They get a hidden copy; customers and suppliers never see them. Press **Submit** to save.'],
          es: ['**ConfiguraciÃ³n â€º Mail Settings**: el servidor de correo desde el que envÃ­a la empresa; **Test** envÃ­a un correo de prueba.', '**Admin notices** muestra quiÃ©n recibe los avisos automÃ¡ticos (reservas, caja del POS); el SuperAdmin tambiÃ©n puede recibir copia.', '**Send a copy to other emails**: escriba las direcciones (Enter despuÃ©s de cada una) y marque quÃ© correos copian â€” **Ventas** (facturas y recibos de pago), **Recordatorios de pago**, **Compras** (Ã³rdenes y pagos). Reciben una copia oculta; clientes y proveedores nunca la ven. Presione **Submit** para guardar.'],
        },
      },
    ],
  },
];
