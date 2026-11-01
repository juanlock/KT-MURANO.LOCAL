/**
 * "Why Murano for your company" (user's request, 2026-11-01): a summary of at
 * most two pages per business type (App\Support\BusinessTypes keys), shown in
 * the manual drawer (tab "Your company") and at the start of each type's PDF
 * manual (public/manual/murano-{type}-{lang}.pdf, KT-TOOLS/Manual/build_type_pdfs.mjs).
 *
 *   TYPES[type]  label, tagline, intro, points (the type's own advantages)
 *   COMMON       advantages every company gets; `module` = shown only when the
 *                type starts with that module (BusinessTypes::modules)
 * English and Spanish side by side, like manual.js.
 */

export const COMMON = [
  { module: null, t: { en: 'One system, your own space', es: 'Un solo sistema, su propio espacio' }, d: { en: 'Sales, people, money and documents in one place, in the cloud, from any computer or phone. Your company only ever sees its own data.', es: 'Ventas, personas, dinero y documentos en un solo lugar, en la nube, desde cualquier computadora o celular. Su empresa solo ve sus propios datos.' } },
  { module: null, t: { en: 'Everyone sees only their part', es: 'Cada uno ve solo lo suyo' }, d: { en: 'Ready-made roles (Admin, Helpdesk, Website, Forms read-only, Customer, Employee) and your own: each login sees only the menus and buttons its role allows.', es: 'Roles listos (Admin, Helpdesk, Website, Formularios de solo lectura, Cliente, Empleado) y los suyos: cada usuario ve solo los menús y botones que su rol permite.' } },
  { module: null, t: { en: 'Secure sign-in', es: 'Ingreso seguro' }, d: { en: 'Two-step verification, lockout after wrong passwords, alerts on new computers and optional office-network restriction.', es: 'Verificación en dos pasos, bloqueo tras contraseñas incorrectas, avisos de equipos nuevos y restricción opcional a la red de la oficina.' } },
  { module: 'website', t: { en: 'Your website included', es: 'Su sitio web incluido' }, d: { en: 'Edit your pages, menus, gallery and portfolio yourself, or import the site you already have.', es: 'Edite usted mismo sus páginas, menús, galería y portafolio, o importe el sitio que ya tiene.' } },
  { module: 'forms', t: { en: 'Forms that work for you', es: 'Formularios que trabajan por usted' }, d: { en: 'Contact forms, complaints books and surveys on your website; every submission numbered, readable, printable and marked as attended.', es: 'Formularios de contacto, libros de reclamaciones y encuestas en su web; cada envío numerado, legible, imprimible y marcado como atendido.' } },
  { module: 'email_office', t: { en: 'Email Office', es: 'Email Office' }, d: { en: 'The company mailbox inside Murano: emails linked to customers, bills and payments read automatically.', es: 'El buzón de la empresa dentro de Murano: correos vinculados a clientes, facturas y pagos leídos automáticamente.' } },
  { module: 'helpdesk', t: { en: 'Helpdesk', es: 'Helpdesk' }, d: { en: 'Customer requests become tickets with a number, an owner and a history — nothing gets lost.', es: 'Las solicitudes de clientes se vuelven tickets con número, responsable e historial — nada se pierde.' } },
  { module: 'accounting', t: { en: 'Accounting that fills itself', es: 'Contabilidad que se llena sola' }, d: { en: 'Sales, purchases, payments and payroll post to your chart of accounts; profit and loss and balance sheet at any time.', es: 'Ventas, compras, pagos y planillas se registran en su plan de cuentas; estado de resultados y balance en cualquier momento.' } },
  { module: 'hrm', t: { en: 'Your team in order', es: 'Su equipo en orden' }, d: { en: 'Employees with their login, attendance, leaves, contracts and weekly payroll by hours or commission.', es: 'Empleados con su acceso, asistencia, permisos, contratos y planilla semanal por horas o comisión.' } },
  { module: 'customers', t: { en: 'Customer portal', es: 'Portal del cliente' }, d: { en: 'Your customers see their invoices, payments, appointments and tickets, and pay online.', es: 'Sus clientes ven sus facturas, pagos, citas y tickets, y pagan en línea.' } },
];

const P = (en, es, den, des) => ({ t: { en, es }, d: { en: den, es: des } });

export const TYPES = {
  general: {
    label: { en: 'General company', es: 'Empresa general' },
    tagline: { en: 'Start simple, grow with modules.', es: 'Empiece simple, crezca con módulos.' },
    intro: { en: 'Murano starts with the essentials and turns on your sector\'s modules when you are ready.', es: 'Murano empieza con lo esencial y enciende los módulos de su sector cuando usted esté listo.' },
    points: [
      P('Invoices in minutes', 'Facturas en minutos', 'Quotations, sales and receipts with your logo and numbering; send them by email or WhatsApp.', 'Cotizaciones, ventas y recibos con su logo y numeración; envíelos por correo o WhatsApp.'),
      P('Know what you are owed', 'Sepa cuánto le deben', 'Due invoices, automatic reminders and online payment links.', 'Facturas pendientes, recordatorios automáticos y enlaces de pago en línea.'),
      P('Products and stock', 'Productos y stock', 'Prices, stock per location and purchases from suppliers in one list.', 'Precios, stock por local y compras a proveedores en una sola lista.'),
      P('Reports that answer', 'Reportes que responden', 'Sales, profit, payments and taxes for any date range, exportable to Excel or PDF.', 'Ventas, ganancia, pagos e impuestos para cualquier rango de fechas, exportables a Excel o PDF.'),
      P('Grows with you', 'Crece con usted', 'Bookings, projects, fleet, POS and more are one switch away.', 'Reservas, proyectos, flota, POS y más están a un interruptor de distancia.'),
    ],
  },
  it_services: {
    label: { en: 'IT / technology services', es: 'Servicios de TI / tecnología' },
    tagline: { en: 'Projects, support and recurring billing together.', es: 'Proyectos, soporte y facturación recurrente juntos.' },
    intro: { en: 'For companies that sell services, contracts and support hours.', es: 'Para empresas que venden servicios, contratos y horas de soporte.' },
    points: [
      P('Projects and tasks', 'Proyectos y tareas', 'Plan the work, assign people and track hours per project.', 'Planifique el trabajo, asigne personas y controle horas por proyecto.'),
      P('Service jobs', 'Trabajos de servicio', 'Each visit or installation with its technician, hours and cost.', 'Cada visita o instalación con su técnico, horas y costo.'),
      P('Recurring contracts', 'Contratos recurrentes', 'Monthly plans invoice themselves on their date.', 'Los planes mensuales se facturan solos en su fecha.'),
      P('Support tickets', 'Tickets de soporte', 'Customer emails become tickets; customers follow them in their portal.', 'Los correos de clientes se vuelven tickets; el cliente los sigue en su portal.'),
      P('Portfolio and documents', 'Portafolio y documentos', 'Show your work on your website and keep contracts and manuals in the archive.', 'Muestre su trabajo en su web y guarde contratos y manuales en el archivo.'),
    ],
  },
  landscaping: {
    label: { en: 'Landscaping / lawn care', es: 'Jardinería / paisajismo' },
    tagline: { en: 'Crews, routes and invoices without paperwork.', es: 'Cuadrillas, rutas y facturas sin papeleo.' },
    intro: { en: 'For companies with field crews and recurring visits.', es: 'Para empresas con cuadrillas de campo y visitas recurrentes.' },
    points: [
      P('Daily tasks for crews', 'Tareas diarias para cuadrillas', 'Each worker opens their jobs of the day on the phone, starts and completes them with a note.', 'Cada trabajador abre sus trabajos del día en el celular, los inicia y completa con una nota.'),
      P('Real job cost', 'Costo real del trabajo', 'Hours of each operator plus the equipment used, job by job.', 'Horas de cada operario más el equipo usado, trabajo por trabajo.'),
      P('Recurring services', 'Servicios recurrentes', 'Weekly or monthly maintenance invoiced automatically.', 'Mantenimiento semanal o mensual facturado automáticamente.'),
      P('Fleet', 'Flota', 'Trucks and equipment with maintenance and fuel.', 'Camiones y equipos con mantenimiento y combustible.'),
      P('Payroll by hours', 'Planilla por horas', 'Hours of completed jobs become the weekly payroll.', 'Las horas de los trabajos completados se vuelven la planilla semanal.'),
    ],
  },
  salon: {
    label: { en: 'Hair salon / beauty', es: 'Salón de belleza' },
    tagline: { en: 'A full agenda and paid commissions.', es: 'Agenda llena y comisiones pagadas.' },
    intro: { en: 'For salons with stylists, appointments and walk-ins.', es: 'Para salones con estilistas, citas y clientes sin cita.' },
    points: [
      P('Online booking', 'Reservas en línea', 'Customers book from your website; staff confirm and the customer gets the email.', 'Los clientes reservan desde su web; el personal confirma y el cliente recibe el correo.'),
      P('Tablet catalog', 'Catálogo en tablet', 'Services and model photos for the customer to choose; the order reaches the POS.', 'Servicios y fotos de modelos para que el cliente elija; el pedido llega al POS.'),
      P('POS with PIN', 'POS con PIN', 'Each stylist sells with their PIN on the shared register.', 'Cada estilista vende con su PIN en la caja compartida.'),
      P('Commissions', 'Comisiones', 'Weekly pay calculated from each stylist\'s sales.', 'Pago semanal calculado con las ventas de cada estilista.'),
      P('Promotions', 'Promociones', 'Discounts and packages that bring customers back.', 'Descuentos y paquetes que hacen volver a los clientes.'),
    ],
  },
  barber: {
    label: { en: 'Barber shop', es: 'Barbería' },
    tagline: { en: 'Chairs busy, cash under control.', es: 'Sillas ocupadas, caja bajo control.' },
    intro: { en: 'For barber shops with several barbers and quick service.', es: 'Para barberías con varios barberos y servicio rápido.' },
    points: [
      P('Shop agenda', 'Agenda de la barbería', 'Each barber\'s appointments on one calendar, plus online booking.', 'Las citas de cada barbero en un calendario, más reservas en línea.'),
      P('Style catalog', 'Catálogo de estilos', 'The customer picks the cut on a tablet with photos.', 'El cliente elige el corte en una tablet con fotos.'),
      P('Register with PIN', 'Caja con PIN', 'Every sale under the barber who made it; close-out per person.', 'Cada venta a nombre del barbero que la hizo; cierre por persona.'),
      P('Commissions', 'Comisiones', 'Barbers\' pay from their own sales, every week.', 'El pago de los barberos con sus propias ventas, cada semana.'),
      P('Loyalty', 'Fidelidad', 'Promotions for frequent customers.', 'Promociones para clientes frecuentes.'),
    ],
  },
  retail: {
    label: { en: 'Retail store', es: 'Tienda' },
    tagline: { en: 'Sell in store and online with the same stock.', es: 'Venda en tienda y en línea con el mismo stock.' },
    intro: { en: 'For shops with a counter, stock and an online store.', es: 'Para tiendas con mostrador, stock y tienda en línea.' },
    points: [
      P('Fast POS', 'POS rápido', 'Barcode, cash and card, receipts and register close-out.', 'Código de barras, efectivo y tarjeta, recibos y cierre de caja.'),
      P('Online store', 'Tienda en línea', 'Your products online with cart and payments, same stock.', 'Sus productos en línea con carrito y pagos, mismo stock.'),
      P('Stock under control', 'Stock bajo control', 'Stock per location, transfers, counts and low-stock alerts.', 'Stock por local, traslados, conteos y alertas de stock bajo.'),
      P('Marketing', 'Marketing', 'Campaigns by email, SMS or WhatsApp to your own customers.', 'Campañas por correo, SMS o WhatsApp a sus propios clientes.'),
      P('Wallet and promotions', 'Monedero y promociones', 'Customer balance, points and discounts that make them return.', 'Saldo del cliente, puntos y descuentos que lo hacen volver.'),
    ],
  },
  restaurant: {
    label: { en: 'Restaurant / food', es: 'Restaurante / comida' },
    tagline: { en: 'From the table to the kitchen without shouting.', es: 'De la mesa a la cocina sin gritos.' },
    intro: { en: 'For restaurants, cafés and food counters.', es: 'Para restaurantes, cafés y mostradores de comida.' },
    points: [
      P('Tablet menu', 'Menú en tablet', 'Customers order from a photo menu; the order reaches the kitchen and the POS.', 'Los clientes piden desde un menú con fotos; el pedido llega a cocina y al POS.'),
      P('Kitchen screen', 'Pantalla de cocina', 'Orders by status: new, preparing, ready.', 'Pedidos por estado: nuevo, en preparación, listo.'),
      P('POS and register', 'POS y caja', 'Split payments, receipts and daily close-out.', 'Pagos divididos, recibos y cierre diario.'),
      P('Reservations', 'Reservas', 'Tables booked online or by phone on one calendar.', 'Mesas reservadas en línea o por teléfono en un calendario.'),
      P('Ingredients and costs', 'Insumos y costos', 'Purchases and stock of ingredients to know your margin.', 'Compras y stock de insumos para conocer su margen.'),
    ],
  },
  professional: {
    label: { en: 'Office / professional services', es: 'Oficina / servicios profesionales' },
    tagline: { en: 'Your time, billed and organized.', es: 'Su tiempo, facturado y organizado.' },
    intro: { en: 'For consultants, accountants and offices that sell their knowledge.', es: 'Para consultores, contadores y oficinas que venden su conocimiento.' },
    points: [
      P('Appointments', 'Citas', 'Clients book consultations online.', 'Los clientes reservan consultas en línea.'),
      P('Projects and hours', 'Proyectos y horas', 'Every engagement with its tasks and timesheets.', 'Cada encargo con sus tareas y horas.'),
      P('Documents', 'Documentos', 'Client files in a private archive per company.', 'Archivos de clientes en un archivo privado por empresa.'),
      P('Meetings', 'Reuniones', 'Meetings with agenda, attendees and minutes.', 'Reuniones con agenda, asistentes y acta.'),
      P('Recurring fees', 'Honorarios recurrentes', 'Monthly retainers invoiced by themselves.', 'Igualas mensuales que se facturan solas.'),
    ],
  },
  laundry: {
    label: { en: 'Laundry services', es: 'Lavandería' },
    tagline: { en: 'Every order tracked from drop-off to delivery.', es: 'Cada pedido seguido de la entrega a la devolución.' },
    intro: { en: 'For laundries and dry cleaners with pick-up and delivery.', es: 'Para lavanderías y tintorerías con recojo y entrega.' },
    points: [
      P('Orders as service jobs', 'Pedidos como trabajos', 'Each order with its status, date and responsible person.', 'Cada pedido con su estado, fecha y responsable.'),
      P('Pick-up bookings', 'Reservas de recojo', 'Customers schedule pick-ups online.', 'Los clientes programan recojos en línea.'),
      P('POS at the counter', 'POS en mostrador', 'Charge on drop-off or delivery with receipts.', 'Cobre al recibir o entregar, con recibos.'),
      P('Promotions', 'Promociones', 'Packages and discounts for regular customers.', 'Paquetes y descuentos para clientes frecuentes.'),
      P('Daily control', 'Control diario', 'Sales, pending orders and payments of the day.', 'Ventas, pedidos pendientes y pagos del día.'),
    ],
  },
  delivery: {
    label: { en: 'Delivery services', es: 'Servicio de entregas' },
    tagline: { en: 'Vehicles, drivers and deliveries on one screen.', es: 'Vehículos, choferes y entregas en una pantalla.' },
    intro: { en: 'For courier and delivery companies.', es: 'Para empresas de mensajería y reparto.' },
    points: [
      P('Fleet', 'Flota', 'Vehicles with fuel, maintenance and documents due.', 'Vehículos con combustible, mantenimiento y documentos por vencer.'),
      P('Deliveries as jobs', 'Entregas como trabajos', 'Each delivery assigned to a driver, started and completed from the phone.', 'Cada entrega asignada a un chofer, iniciada y completada desde el celular.'),
      P('Cost per delivery', 'Costo por entrega', 'Hours and vehicle use per job.', 'Horas y uso del vehículo por trabajo.'),
      P('Invoicing', 'Facturación', 'Per delivery or monthly per customer.', 'Por entrega o mensual por cliente.'),
      P('Drivers\' payroll', 'Planilla de choferes', 'Weekly pay from completed jobs.', 'Pago semanal con los trabajos completados.'),
    ],
  },
  catering: {
    label: { en: 'Catering / buffet', es: 'Catering / buffet' },
    tagline: { en: 'Events planned, cooked and delivered on time.', es: 'Eventos planificados, cocinados y entregados a tiempo.' },
    intro: { en: 'For caterers and buffets that serve events.', es: 'Para empresas de catering y buffets que atienden eventos.' },
    points: [
      P('Event bookings', 'Reservas de eventos', 'Dates, guests and menus on one calendar.', 'Fechas, invitados y menús en un calendario.'),
      P('Kitchen orders', 'Pedidos de cocina', 'Production by status for every event.', 'Producción por estado para cada evento.'),
      P('Fleet for delivery', 'Flota para entregas', 'Vehicles and drivers for each event.', 'Vehículos y choferes para cada evento.'),
      P('Quotations to invoices', 'De cotización a factura', 'Quote the event and turn it into a sale in one click.', 'Cotice el evento y conviértalo en venta con un clic.'),
      P('Ingredients and margin', 'Insumos y margen', 'Purchases per event to know the real profit.', 'Compras por evento para conocer la ganancia real.'),
    ],
  },
  call_center: {
    label: { en: 'Call center', es: 'Call center' },
    tagline: { en: 'Campaigns, agents and results measured.', es: 'Campañas, agentes y resultados medidos.' },
    intro: { en: 'For contact centers and sales teams.', es: 'Para centros de contacto y equipos de venta.' },
    points: [
      P('Marketing campaigns', 'Campañas de marketing', 'Segments and messages by email, SMS or WhatsApp.', 'Segmentos y mensajes por correo, SMS o WhatsApp.'),
      P('Tickets', 'Tickets', 'Every call or email with an owner and status.', 'Cada llamada o correo con responsable y estado.'),
      P('Projects', 'Proyectos', 'Client accounts organized with tasks and hours.', 'Cuentas de clientes organizadas con tareas y horas.'),
      P('Recruitment', 'Reclutamiento', 'Job posts, candidates and interviews.', 'Avisos, candidatos y entrevistas.'),
      P('Meetings', 'Reuniones', 'Team meetings with minutes and follow-up.', 'Reuniones de equipo con acta y seguimiento.'),
    ],
  },
  real_estate: {
    label: { en: 'Real estate', es: 'Bienes raíces' },
    tagline: { en: 'Listings, inquiries and closings in one flow.', es: 'Propiedades, consultas y cierres en un solo flujo.' },
    intro: { en: 'For agencies that sell and rent properties.', es: 'Para inmobiliarias que venden y alquilan propiedades.' },
    points: [
      P('Listings', 'Propiedades', 'Every property with photos, price and status, published on your website.', 'Cada propiedad con fotos, precio y estado, publicada en su web.'),
      P('Inquiries', 'Consultas', 'Website leads arrive to the right agent.', 'Los interesados de la web llegan al agente correcto.'),
      P('Showings', 'Visitas', 'Appointments to see the property on a calendar.', 'Citas para ver la propiedad en un calendario.'),
      P('Agent commissions', 'Comisiones de agentes', 'Calculated on each closing.', 'Calculadas en cada cierre.'),
      P('Contracts', 'Contratos', 'Deeds and contracts kept in the document archive.', 'Escrituras y contratos guardados en el archivo de documentos.'),
    ],
  },
  legal: {
    label: { en: 'Law firm', es: 'Bufete / servicios legales' },
    tagline: { en: 'Cases, hours and files under control.', es: 'Casos, horas y expedientes bajo control.' },
    intro: { en: 'For lawyers and legal services.', es: 'Para abogados y servicios legales.' },
    points: [
      P('Cases as projects', 'Casos como proyectos', 'Each case with its tasks, deadlines and hours.', 'Cada caso con sus tareas, plazos y horas.'),
      P('Case files', 'Expedientes', 'Private documents per client and case.', 'Documentos privados por cliente y caso.'),
      P('Consultations', 'Consultas', 'Clients book appointments online.', 'Los clientes reservan citas en línea.'),
      P('Billing by hours', 'Cobro por horas', 'Timesheets turned into invoices.', 'Horas registradas convertidas en facturas.'),
      P('Meetings', 'Reuniones', 'Client meetings with agenda and minutes.', 'Reuniones con clientes con agenda y acta.'),
    ],
  },
  hospital: {
    label: { en: 'Hospital / clinic', es: 'Hospital / clínica' },
    tagline: { en: 'From admission to discharge, every step recorded.', es: 'De la admisión al alta, cada paso registrado.' },
    intro: { en: 'For hospitals and clinics with emergency, wards and staff on shifts.', es: 'Para hospitales y clínicas con emergencia, hospitalización y personal por turnos.' },
    points: [
      P('Admission and triage', 'Admisión y triaje', 'The window registers the patient and the money; triage takes vitals and sends to a bed.', 'La ventanilla registra al paciente y el pago; triaje toma signos vitales y envía a una cama.'),
      P('Clinical chart', 'Historia clínica', 'Notes, vitals, doses of the day, labs with critical alerts, epicrisis.', 'Notas, signos vitales, dosis del día, laboratorio con alertas críticas, epicrisis.'),
      P('Beds and floor maps', 'Camas y planos', 'Every floor, room and bed with its status; cleaning after each discharge.', 'Cada piso, sala y cama con su estado; limpieza tras cada alta.'),
      P('Shifts and rounds', 'Turnos y rondas', 'Monthly shift plans and each nurse\'s and doctor\'s route.', 'Planes de turnos mensuales y la ruta de cada enfermera y médico.'),
      P('Billing at discharge', 'Cobro al alta', 'Insurance and card shares settled automatically.', 'La parte del seguro y de la tarjeta se liquidan solas.'),
    ],
  },
  health_specialist: {
    label: { en: 'Specialist clinic', es: 'Clínica de especialistas' },
    tagline: { en: 'Agenda, consultation and payment in one visit.', es: 'Agenda, consulta y cobro en una sola visita.' },
    intro: { en: 'For outpatient clinics with specialists.', es: 'Para clínicas ambulatorias con especialistas.' },
    points: [
      P('Reception agenda', 'Agenda de recepción', 'Drag-and-drop appointments per doctor, check-in and payment.', 'Citas por médico arrastrando y soltando, llegada y cobro.'),
      P('Nursing station', 'Estación de enfermería', 'Vitals before the consultation.', 'Signos vitales antes de la consulta.'),
      P('Specialist record', 'Historia del especialista', 'Problems, notes, prescriptions to print.', 'Problemas, notas y recetas para imprimir.'),
      P('Patient portal', 'Portal del paciente', 'Patients book, pre-register and download their prescriptions.', 'Los pacientes reservan, se preregistran y descargan sus recetas.'),
      P('Scans at arrival', 'Escaneos a la llegada', 'Insurance card and ID saved with the patient.', 'Tarjeta del seguro y documento guardados con el paciente.'),
    ],
  },
  factory: {
    label: { en: 'Factory / manufacturing', es: 'Fábrica / manufactura' },
    tagline: { en: 'Plan, produce and deliver with real costs.', es: 'Planifique, produzca y entregue con costos reales.' },
    intro: { en: 'For workshops and factories that make products.', es: 'Para talleres y fábricas que elaboran productos.' },
    points: [
      P('Bills of materials', 'Listas de materiales', 'Each product with its components and costs.', 'Cada producto con sus componentes y costos.'),
      P('Production orders', 'Órdenes de producción', 'Work centers, steps and quality checks.', 'Centros de trabajo, pasos y controles de calidad.'),
      P('Stock of materials', 'Stock de materiales', 'Purchases and consumption with batches.', 'Compras y consumo con lotes.'),
      P('Maintenance', 'Mantenimiento', 'Machines and trucks with service jobs.', 'Máquinas y camiones con trabajos de servicio.'),
      P('Projects', 'Proyectos', 'Special orders planned as projects.', 'Pedidos especiales planificados como proyectos.'),
    ],
  },
  school: {
    label: { en: 'School / academy', es: 'Escuela / academia' },
    tagline: { en: 'Students, teachers and parents connected.', es: 'Alumnos, profesores y padres conectados.' },
    intro: { en: 'For schools and academies.', es: 'Para colegios y academias.' },
    points: [
      P('School management', 'Gestión escolar', 'Students, classes, teachers, timetable and exams.', 'Alumnos, clases, profesores, horario y exámenes.'),
      P('Fees', 'Pensiones', 'Fees per student with their payments.', 'Pensiones por alumno con sus pagos.'),
      P('Intranet', 'Intranet', 'News and documents for staff, parents and students.', 'Noticias y documentos para personal, padres y alumnos.'),
      P('Roles for everyone', 'Roles para todos', 'Rector, secretary, teacher, parent and student, each with their view.', 'Rector, secretaria, profesor, padre y alumno, cada uno con su vista.'),
      P('Your website', 'Su sitio web', 'Portfolio of activities and admissions forms.', 'Portafolio de actividades y formularios de admisión.'),
    ],
  },
  tourism: {
    label: { en: 'Tourism / travel agency', es: 'Turismo / agencia de viajes' },
    tagline: { en: 'Tours sold, customers served, complaints answered.', es: 'Tours vendidos, clientes atendidos, reclamos respondidos.' },
    intro: { en: 'For tour operators, transport and travel agencies.', es: 'Para operadores de turismo, transporte y agencias de viaje.' },
    points: [
      P('Reservations', 'Reservas', 'Tours and seats booked online or at the agency.', 'Tours y asientos reservados en línea o en la agencia.'),
      P('Complaints book', 'Libro de reclamaciones', 'The legal complaints form on your website, numbered, grouped by type and agency, and marked as attended.', 'El libro de reclamaciones en su web, numerado, agrupado por tipo y agencia, y marcado como atendido.'),
      P('Agents\' commissions', 'Comisiones de agentes', 'Calculated on each sale.', 'Calculadas en cada venta.'),
      P('Campaigns and promotions', 'Campañas y promociones', 'Offers by email or WhatsApp to your own customers.', 'Ofertas por correo o WhatsApp a sus propios clientes.'),
      P('Tours portfolio', 'Portafolio de tours', 'Destinations with photos on your website.', 'Destinos con fotos en su web.'),
    ],
  },
};

/**
 * The flyer's opening graphic per type (2026-11-01): an icon, the type's color and
 * the business flow Murano covers, step by step (public/manual/flyer-{type}-{lang}.pdf).
 */
const F = (icon, en, es) => ({ icon, t: { en, es } });
export const FLYER = {
  general: { icon: '🏢', color: '#4f46e5', flow: [F('🧾', 'Quote', 'Cotiza'), F('🛒', 'Sell', 'Vende'), F('💳', 'Get paid', 'Cobra'), F('📊', 'Measure', 'Mide')] },
  it_services: { icon: '💻', color: '#2563eb', flow: [F('📩', 'Request', 'Solicitud'), F('🗂️', 'Project', 'Proyecto'), F('🛠️', 'Service', 'Servicio'), F('🔁', 'Monthly billing', 'Cobro mensual')] },
  landscaping: { icon: '🌿', color: '#16a34a', flow: [F('📅', 'Schedule', 'Programa'), F('🚚', 'Crew on route', 'Cuadrilla en ruta'), F('✅', 'Job done', 'Trabajo hecho'), F('💵', 'Invoice', 'Factura')] },
  salon: { icon: '💇', color: '#db2777', flow: [F('📱', 'Online booking', 'Reserva en línea'), F('📸', 'Choose the style', 'Elige el estilo'), F('✂️', 'Service', 'Servicio'), F('💳', 'Pay with PIN', 'Cobro con PIN')] },
  barber: { icon: '💈', color: '#0f766e', flow: [F('📱', 'Booking', 'Reserva'), F('📸', 'Pick the cut', 'Elige el corte'), F('✂️', 'Barber', 'Barbero'), F('💵', 'Commission', 'Comisión')] },
  retail: { icon: '🛍️', color: '#ea580c', flow: [F('📦', 'Stock', 'Stock'), F('🏪', 'Store and online', 'Tienda y en línea'), F('💳', 'Sale', 'Venta'), F('📣', 'Campaign', 'Campaña')] },
  restaurant: { icon: '🍽️', color: '#dc2626', flow: [F('📱', 'Tablet menu', 'Menú en tablet'), F('👨‍🍳', 'Kitchen', 'Cocina'), F('🍲', 'Served', 'Servido'), F('💳', 'Charge', 'Cobro')] },
  professional: { icon: '💼', color: '#475569', flow: [F('📅', 'Consultation', 'Consulta'), F('🗂️', 'Engagement', 'Encargo'), F('⏱️', 'Hours', 'Horas'), F('🧾', 'Fees', 'Honorarios')] },
  laundry: { icon: '🧺', color: '#0891b2', flow: [F('📅', 'Pick-up', 'Recojo'), F('🫧', 'Washing', 'Lavado'), F('📦', 'Delivery', 'Entrega'), F('💳', 'Charge', 'Cobro')] },
  delivery: { icon: '🚚', color: '#ca8a04', flow: [F('📦', 'Order', 'Pedido'), F('🧭', 'Driver assigned', 'Chofer asignado'), F('✅', 'Delivered', 'Entregado'), F('🧾', 'Invoice', 'Factura')] },
  catering: { icon: '🥘', color: '#b45309', flow: [F('📝', 'Quote the event', 'Cotiza el evento'), F('👨‍🍳', 'Kitchen', 'Cocina'), F('🚚', 'Delivery', 'Entrega'), F('🎉', 'Event served', 'Evento servido')] },
  call_center: { icon: '🎧', color: '#7c3aed', flow: [F('📣', 'Campaign', 'Campaña'), F('☎️', 'Contact', 'Contacto'), F('🎫', 'Ticket', 'Ticket'), F('📊', 'Results', 'Resultados')] },
  real_estate: { icon: '🏠', color: '#0d9488', flow: [F('🏷️', 'Listing', 'Propiedad'), F('🌐', 'Inquiry', 'Consulta'), F('🔑', 'Showing', 'Visita'), F('🤝', 'Closing', 'Cierre')] },
  legal: { icon: '⚖️', color: '#1e3a8a', flow: [F('📅', 'Consultation', 'Consulta'), F('📁', 'Case', 'Caso'), F('⏱️', 'Hours', 'Horas'), F('🧾', 'Invoice', 'Factura')] },
  hospital: { icon: '🏥', color: '#0284c7', flow: [F('🪪', 'Admission', 'Admisión'), F('🩺', 'Triage', 'Triaje'), F('🛏️', 'Bed and care', 'Cama y cuidados'), F('💳', 'Discharge and billing', 'Alta y cobro')] },
  health_specialist: { icon: '🩺', color: '#0369a1', flow: [F('📅', 'Appointment', 'Cita'), F('🪪', 'Check-in', 'Llegada'), F('💉', 'Nursing', 'Enfermería'), F('📋', 'Consultation', 'Consulta')] },
  factory: { icon: '🏭', color: '#57534e', flow: [F('📐', 'Bill of materials', 'Lista de materiales'), F('⚙️', 'Production', 'Producción'), F('🔍', 'Quality', 'Calidad'), F('🚚', 'Delivery', 'Entrega')] },
  school: { icon: '🏫', color: '#9333ea', flow: [F('📝', 'Enrollment', 'Matrícula'), F('📚', 'Classes', 'Clases'), F('📝', 'Exams', 'Exámenes'), F('💳', 'Fees', 'Pensiones')] },
  tourism: { icon: '🚌', color: '#7e22ce', flow: [F('🗺️', 'Tour', 'Tour'), F('📅', 'Reservation', 'Reserva'), F('🚌', 'Trip', 'Viaje'), F('📖', 'Complaints answered', 'Reclamos atendidos')] },
};

/** The type's own points + the common ones for the modules it starts with. */
export function advantagesFor(type, modules = null) {
  const t = TYPES[type] || TYPES.general;
  const common = COMMON.filter(c => !c.module || !modules || modules.includes(c.module));
  return { ...t, common };
}
