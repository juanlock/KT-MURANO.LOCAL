<template>
  <!-- How the system works, for presenting it (user's request, 2026-11-18): who takes part and the steps of a trip, a parcel
       and a maintenance job. Pictures, almost no text; each role has its colour on every diagram. -->
  <div ref="root" class="hw" :class="{ dark: ui.dark, full: isFull }">
    <div class="hw-bar">
      <!-- Bigger, each in its colour (2026-11-18). -->
      <button v-for="t in tabs" :key="t.value" type="button" class="hw-tab" :class="{ on: tab === t.value }"
        :style="{ '--tone': t.color }" @click="tab = t.value">{{ t.label }}</button>
      <span class="hw-sp" />
      <a-button v-if="tabIdx > 0" @click="go(-1)"><LeftOutlined /></a-button>
      <a-button v-if="tabIdx < tabs.length - 1" @click="go(1)"><RightOutlined /></a-button>
      <a-button @click="toggleFull">
        <component :is="isFull ? FullscreenExitOutlined : FullscreenOutlined" /> {{ isFull ? L('Exit', 'Salir') : L('Present', 'Presentar') }}
      </a-button>
    </div>

    <!-- ===== Slide 1: who takes part ===== -->
    <!-- Concentric (user's requests, 2026-11-18): everything ends in the central panel (DATA EN VIVO: the company's bus and a
         dashboard). Across it, three vertical groups -- SALES (local, online / external sellers), OPERATION (trips above the
         panel, parcels below), MAINTENANCE (staff care, customer complaints, fleet and equipment) --, the people on the outer
         ring linked to what they feed. Text only; the message goes in help lines under the picture. -->
    <svg v-if="tab === 'people'" class="hw-svg" viewBox="0 0 1200 760" role="img" :aria-label="L('Who takes part', 'Quiénes participan')">
      <defs>
        <clipPath id="hwShot"><rect :x="CL + 10" :y="CT + 156" :width="CW - 20" height="150" rx="6" /></clipPath>
        <clipPath id="hwR3"><ellipse :cx="CX" :cy="CY" rx="580" ry="330" /></clipPath>
        <clipPath id="hwR2"><ellipse :cx="CX" :cy="CY" rx="440" ry="280" /></clipPath>
        <filter id="hwShadow" x="-10%" y="-10%" width="120%" height="130%"><feDropShadow dx="0" dy="4" stdDeviation="6" flood-opacity="0.18" /></filter>
      </defs>
      <ellipse :cx="CX" :cy="CY" rx="580" ry="330" :fill="c.ring3" />
      <g clip-path="url(#hwR3)">
        <rect v-for="g in groups" :key="'t3' + g.key" :x="g.x0" y="0" :width="g.x1 - g.x0" height="760" :fill="g.color" opacity="0.06" />
      </g>
      <ellipse :cx="CX" :cy="CY" rx="580" ry="330" fill="none" :stroke="c.line" stroke-width="1.5" />
      <ellipse :cx="CX" :cy="CY" rx="440" ry="280" :fill="c.ring2" />
      <g clip-path="url(#hwR2)">
        <rect v-for="g in groups" :key="'t2' + g.key" :x="g.x0" y="0" :width="g.x1 - g.x0" height="760" :fill="g.color" opacity="0.1" />
      </g>
      <ellipse :cx="CX" :cy="CY" rx="440" ry="280" fill="none" :stroke="c.line" stroke-width="1.5" />
      <g clip-path="url(#hwR3)">
        <line v-for="x in [420, 780]" :key="'d' + x" :x1="x" y1="0" :x2="x" y2="760" :stroke="c.line" stroke-width="1.5" stroke-dasharray="7 6" />
      </g>
      <g v-for="g in groups" :key="'h' + g.key">
        <text :x="g.hx" y="30" text-anchor="middle" :fill="g.color" font-size="25" font-weight="800" letter-spacing="2">{{ L(...g.name) }}</text>
        <text :x="g.hx" y="53" text-anchor="middle" :fill="c.text" font-size="14.5">{{ L(...g.goal) }}</text>
      </g>
      <line v-for="p in outer" :key="'ln' + p.key" :x1="p.x" :y1="p.y" :x2="p.to[0]" :y2="p.to[1]" :stroke="ROLE[p.key].color" stroke-width="2.5" stroke-opacity="0.5" stroke-dasharray="5 5" />
      <!-- centre: live data = the bus + a dashboard; blue, not red (red means an alert) -->
      <g filter="url(#hwShadow)">
        <rect :x="CL" :y="CT" :width="CW" height="316" rx="14" :fill="c.card" :stroke="LIVE" stroke-width="3" />
      </g>
      <rect :x="CL" :y="CT" :width="CW" height="36" rx="14" :fill="LIVE" />
      <rect :x="CL" :y="CT + 22" :width="CW" height="14" :fill="LIVE" />
      <text :x="CX" :y="CT + 26" text-anchor="middle" fill="#fff" font-size="22" font-weight="800" letter-spacing="2">{{ L('LIVE DATA', 'DATA EN VIVO') }}</text>
      <image :href="busImg" :x="CL + 10" :y="CT + 44" :width="CW - 20" height="104" preserveAspectRatio="xMidYMid meet" />
      <image :href="shotImg" :x="CL + 10" :y="CT + 156" :width="CW - 20" height="150" preserveAspectRatio="xMidYMin slice" clip-path="url(#hwShot)" />
      <rect :x="CL + 10" :y="CT + 156" :width="CW - 20" height="150" rx="6" fill="none" :stroke="c.line" />
      <g v-for="m in modules" :key="m.key" :transform="`translate(${m.x},${m.y})`">
        <rect :x="-m.w / 2" :y="-m.h / 2" :width="m.w" :height="m.h" rx="12" :fill="c.card" :stroke="m.color" stroke-width="2.5" />
        <text x="0" :y="m.big ? -5 : -4" text-anchor="middle" :fill="m.color" :font-size="m.big ? 23 : 17" font-weight="800">{{ L(...m.name) }}</text>
        <text x="0" :y="m.big ? 20 : 17" text-anchor="middle" :fill="c.text" :font-size="m.big ? 15.5 : 13.5">{{ L(...m.sub) }}</text>
      </g>
      <g v-for="p in outer" :key="p.key" :transform="`translate(${p.x},${p.y})`">
        <rect :x="-pillW(p.key) / 2" y="-24" :width="pillW(p.key)" height="48" rx="24" :fill="ROLE[p.key].color" stroke="#fff" stroke-width="2" />
        <g :transform="`translate(${-pillW(p.key) / 2 + 26},0)`" fill="none" stroke="#fff" stroke-width="2.2" stroke-linejoin="round">
          <template v-if="ROLE[p.key].device === 'phone'">
            <rect x="-8" y="-14" width="16" height="28" rx="3.5" />
            <line x1="-3" y1="9" x2="3" y2="9" stroke-linecap="round" />
          </template>
          <template v-else>
            <rect x="-13" y="-12" width="26" height="18" rx="2" />
            <line x1="0" y1="6" x2="0" y2="11" />
            <line x1="-7" y1="12" x2="7" y2="12" stroke-linecap="round" />
          </template>
        </g>
        <text :x="-pillW(p.key) / 2 + 48" y="-3" fill="#fff" font-size="16" font-weight="700">{{ L(...ROLE[p.key].name) }}</text>
        <text :x="-pillW(p.key) / 2 + 48" y="16" fill="#fff" font-size="13.5" opacity="0.95">{{ L(...ROLE[p.key].where) }}</text>
      </g>
    </svg>

    <!-- ===== Slide 2: Services -- three equal columns, each row a circuit (user, 2026-11-18) ===== -->
    <!-- SERVICES (income) -> OPERATIONS -> MAINTENANCE (Q&A), a row for tickets and one for parcels; each line says what is
         done and, in a chip, who does it ("Murano" = the system by itself). -->
    <svg v-else-if="tab === 'services'" class="hw-svg" viewBox="0 0 1200 600" role="img" :aria-label="L('Services', 'Servicios')">
      <defs>
        <marker id="hwArrowM" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
          <path d="M0,0 L10,5 L0,10 z" :fill="c.line" />
        </marker>
      </defs>
      <g v-for="g in MCOLS" :key="'col' + g.key">
        <rect :x="g.x0 + 6" y="6" width="388" height="588" rx="18" :fill="g.color" opacity="0.07" />
        <text :x="g.x0 + 200" y="38" text-anchor="middle" :fill="g.color" font-size="26" font-weight="800" letter-spacing="2">{{ L(...g.name) }}</text>
        <text v-if="g.sub" :x="g.x0 + 200" y="62" text-anchor="middle" :fill="g.color" font-size="16" font-weight="700">{{ L(...g.sub) }}</text>
      </g>
      <line v-for="(a, i) in mArrows" :key="'ma' + i" :x1="a[0]" :y1="a[1]" :x2="a[2]" :y2="a[1]" :stroke="c.line" stroke-width="3" marker-end="url(#hwArrowM)" />
      <g v-for="b in mboxes" :key="b.key">
        <rect :x="b.x" :y="b.y" :width="b.w" :height="b.h" rx="14" :fill="c.card" :stroke="b.color" stroke-width="2.5" />
        <text :x="b.x + 16" :y="b.y + 32" :fill="b.color" font-size="20" font-weight="800">{{ L(...b.title) }}</text>
        <g v-for="(it, k) in b.items" :key="k" :transform="`translate(0,${b.y + 48 + k * M_STEP})`">
          <line :x1="b.x + 12" :x2="b.x + b.w - 12" y1="0" y2="0" :stroke="c.line" stroke-opacity="0.35" />
          <text :x="b.x + 16" :y="M_STEP / 2 + 6" :fill="c.text" :font-size="itemFs(it, b.w)" font-weight="600">{{ L(...it.t) }}</text>
          <rect :x="b.x + b.w - 14 - chipW(it)" :y="M_STEP / 2 - 12" :width="chipW(it)" height="24" rx="12" :fill="WHO[it.who].color" />
          <text :x="b.x + b.w - 14 - chipW(it) / 2" :y="M_STEP / 2 + 5" text-anchor="middle" fill="#fff" font-size="13" font-weight="700">{{ L(...WHO[it.who].name) }}</text>
        </g>
      </g>
    </svg>

    <!-- The message, as a help line under the picture (2026-11-18: captions on the rings were not clear). -->
    <div v-if="tab === 'people'" class="hw-help">
      <span>{{ L('Everything reaches the central panel live: the owner decides with real figures.', 'Toda la información llega en vivo al panel central: el dueño decide con cifras reales.') }}</span>
      <span>{{ L('Each person uses only their own screen, on the phone or the PC.', 'Cada persona usa solo su pantalla, en el celular o en la PC.') }}</span>
    </div>
    <div v-if="tab === 'services'" class="hw-help">
      <span>{{ L('Each row is a circuit: it is sold, it is run and its quality is checked.', 'Cada fila es un circuito: se vende, se opera y se controla la calidad.') }}</span>
      <span>{{ L('Murano = the system does it by itself.', 'Murano = lo hace el sistema solo.') }}</span>
    </div>

    <!-- ===== A group: one lane per sub-group, numbered steps left to right (2026-11-18: Services / Operation / Maintenance) ===== -->
    <svg v-if="tab === 'operation' || tab === 'maint'" class="hw-svg" viewBox="0 0 1200 640" role="img" :aria-label="current.title">
      <defs>
        <marker id="hwArrow2" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
          <path d="M0,0 L10,5 L0,10 z" :fill="c.line" />
        </marker>
      </defs>
      <g v-for="(ln, li) in lanes" :key="'lane' + li">
        <rect x="8" :y="ln.top" width="1184" :height="ln.h" rx="16" :fill="current.color" :opacity="li % 2 ? 0.05 : 0.09" />
        <rect x="8" :y="ln.top" width="8" :height="ln.h" rx="4" :fill="current.color" />
        <text v-for="(w, k) in L(...ln.name)" :key="k" x="110" :y="ln.mid - (L(...ln.name).length - 1) * 12 + k * 24 + 8" text-anchor="middle"
          :fill="current.color" font-size="21" font-weight="800">{{ w }}</text>
        <path v-for="(a, i) in ln.arrows" :key="'a' + i" :d="a" fill="none" :stroke="c.line" stroke-width="3" marker-end="url(#hwArrow2)" />
        <g v-for="(s, i) in ln.cards" :key="'s' + i" :transform="`translate(${s.x},${ln.mid})`">
          <rect :x="-ln.cw / 2" :y="-ln.ch / 2" :width="ln.cw" :height="ln.ch" rx="16" :fill="c.card" :stroke="ROLE[s.who].color" stroke-width="2.5" />
          <circle :cx="-ln.cw / 2 + 20" :cy="-ln.ch / 2 + 20" r="14" :fill="ROLE[s.who].color" />
          <text :x="-ln.cw / 2 + 20" :y="-ln.ch / 2 + 26" text-anchor="middle" fill="#fff" font-size="15" font-weight="700">{{ i + 1 }}</text>
          <text x="0" :y="-ln.ch / 2 + (ln.ch > 180 ? 66 : 50)" text-anchor="middle" :font-size="ln.ch > 180 ? 44 : 32">{{ s.icon }}</text>
          <text v-for="(line, k) in L(...s.title)" :key="k" x="0" :y="(ln.ch > 180 ? 12 : 4) + k * 20" text-anchor="middle" :fill="c.text" :font-size="ln.cw < 170 ? 13.5 : 16.5" font-weight="700">{{ line }}</text>
          <rect :x="-Math.min(80, ln.cw / 2 - 8)" :y="ln.ch / 2 - 32" :width="Math.min(160, ln.cw - 16)" height="24" rx="12" :fill="ROLE[s.who].color" />
          <text x="0" :y="ln.ch / 2 - 15" text-anchor="middle" fill="#fff" font-size="13" font-weight="600">{{ L(...ROLE[s.who].name) }}</text>
        </g>
      </g>
      <!-- Where it ends: the owner sees it -->
      <text v-if="current.end" x="600" y="628" text-anchor="middle" :fill="c.muted" font-size="16">{{ L(...current.end) }}</text>
    </svg>

    <!-- The colours: who is who -->
    <div class="hw-legend">
      <span v-for="k in legend" :key="k" class="hw-chip" :style="{ background: ROLE[k].color }">{{ ROLE[k].icon }} {{ L(...ROLE[k].name) }}</span>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { FullscreenOutlined, FullscreenExitOutlined, LeftOutlined, RightOutlined } from '@ant-design/icons-vue';
import { useUiStore } from '../../stores/ui';
import busImg from '../../assets/how/bus.png';
import shotImg from '../../assets/how/dashboard.jpg';

const ui = useUiStore();
const es = computed(() => String(ui.locale || 'es').startsWith('es'));
// L('English', 'Español'), or L(['a', 'b'], ['c', 'd']) for the lines of a title.
const L = (en, sp) => (es.value ? sp : en);

const c = computed(() => (ui.dark
  ? { card: '#1f2937', soft: '#273244', text: '#f1f5f9', muted: '#94a3b8', line: '#64748b', ring2: '#1b2638', ring3: '#151d2b' }
  : { card: '#ffffff', soft: '#eef2f7', text: '#0f172a', muted: '#64748b', line: '#94a3b8', ring2: '#e6edf7', ring3: '#f1f5fa' }));

// Each role keeps its colour on every diagram.
const ROLE = {
  owner: { icon: '👔', color: '#7c3aed', name: ['Owner (Manager)', 'Dueño (Gerente)'], where: ['Dashboard', 'Tablero'] },
  it: { icon: '🛠️', color: '#64748b', device: 'pc', name: ['IT Admin', 'Admin TI'], where: ['Users', 'Usuarios'] },
  agency: { icon: '🏢', color: '#6d28d9', device: 'pc', name: ['Agency sup.', 'Sup. de agencia'], where: ['My agency', 'Mi agencia'] },
  fleet: { icon: '🧭', color: '#ea580c', device: 'pc', name: ['Fleet sup.', 'Sup. de flota'], where: ['Fleet board', 'Tablero de flota'] },
  // Coordinates the pilots (shifts, fatigue) and the person who answers complaints (user, 2026-11-18).
  staffsup: { icon: '👥', color: '#ea580c', device: 'pc', name: ['Staff sup.', 'Sup. de personal'], where: ['Shifts and fatigue', 'Turnos y fatiga'] },
  claims: { icon: '💬', color: '#b45309', device: 'pc', name: ['Quality ctrl.', 'Control calidad'], where: ['Complaints', 'Reclamos'] },
  cashier: { icon: '💳', color: '#0891b2', device: 'pc', name: ['Ticket office', 'Boletería'], where: ['Point of sale', 'Punto de venta'] },
  pilot: { icon: '🧑‍✈️', color: '#0369a1', device: 'phone', name: ['Pilot', 'Piloto'], where: ['Departure, arrival', 'Salida y llegada'] },
  hostess: { icon: '🙋‍♀️', color: '#db2777', device: 'phone', name: ['Hostess', 'Azafata'], where: ['Boarding and GPS', 'Abordaje y GPS'] },
  mechanic: { icon: '🔧', color: '#65a30d', device: 'pc', name: ['Mechanic', 'Mecánico'], where: ['Workshop', 'Taller'] },
  customer: { icon: '🧳', color: '#0f766e', device: 'phone', name: ['Passenger', 'Pasajero'], where: ['Ticket and tracking', 'Pasaje y rastreo'] },
  system: { icon: '⚙️', color: '#475569', name: ['Murano (automatic)', 'Murano (automático)'], where: ['', ''] },
};

const tabs = computed(() => [
  { value: 'people', color: '#475569', label: L('Who takes part', 'Quiénes participan') },
  { value: 'services', color: '#7c3aed', label: L('Services', 'Servicios') },
  { value: 'operation', color: '#1d4ed8', label: L('Operation', 'Operación') },
  { value: 'maint', color: '#65a30d', label: L('Maintenance', 'Mantenimiento') },
]);
const tab = ref('people');
const tabIdx = computed(() => tabs.value.findIndex(t => t.value === tab.value));
const go = d => { tab.value = tabs.value[Math.max(0, Math.min(tabs.value.length - 1, tabIdx.value + d))].value; };

// ---- Slide 1: concentric -- centre = live data (the bus + a dashboard); across it three vertical groups ----
const CX = 600;
const CY = 400;
const CT = 242; // top of the central panel
const CW = 310;
const CL = CX - CW / 2;
const LIVE = '#1d4ed8'; // blue: red means an alert
const SALES = '#7c3aed'; // the company's own purple
const OPS = '#1d4ed8';
const MAINT = '#65a30d';
const groups = [
  { key: 'sales', color: SALES, x0: 0, x1: 420, hx: 215, name: ['SALES', 'VENTAS'], goal: ['Sell every seat and every parcel', 'Vender cada asiento y cada encomienda'] },
  { key: 'ops', color: OPS, x0: 420, x1: 780, hx: 600, name: ['OPERATION', 'OPERACIÓN'], goal: ['Leave and arrive on time, safely', 'Salir y llegar a tiempo, seguros'] },
  { key: 'maint', color: MAINT, x0: 780, x1: 1200, hx: 985, name: ['MAINTENANCE', 'MANTENIMIENTO'], goal: ['People, customers and buses in good shape', 'Personal, clientes y buses en buen estado'] },
];
const modules = [
  { key: 'local', x: 305, y: 340, w: 210, h: 66, color: SALES, name: ['Local sales', 'Venta en local'], sub: ['Ticket office · agencies', 'Boletería · agencias'] },
  { key: 'online', x: 305, y: 460, w: 210, h: 66, color: SALES, name: ['Online and external', 'Online y externa'], sub: ['Web · Perubus · Jelaf', 'Web · Perubus · Jelaf'] },
  { key: 'trips', x: CX, y: 175, w: 310, h: 70, big: true, color: OPS, name: ['Trips', 'Viajes'], sub: ['Departures, crew and GPS', 'Salidas, tripulación y GPS'] },
  { key: 'parcels', x: CX, y: 625, w: 310, h: 70, big: true, color: OPS, name: ['Parcels', 'Encomiendas'], sub: ['Reception, bus and delivery', 'Recepción, bus y entrega'] },
  { key: 'staff', x: 900, y: 320, w: 200, h: 58, color: MAINT, name: ['Staff', 'Personal'], sub: ['Care of the staff', 'Cuidado del personal'] },
  { key: 'claims', x: 900, y: 400, w: 200, h: 58, color: MAINT, name: ['Complaints', 'Reclamos'], sub: ['Customer complaints', 'Reclamos de clientes'] },
  { key: 'fleet', x: 900, y: 480, w: 200, h: 58, color: MAINT, name: ['Fleet and equipment', 'Flota y equipos'], sub: ['Plan, workshop, parts', 'Plan, taller y repuestos'] },
];
const mod = k => { const m = modules.find(x => x.key === k); return [m.x, m.y]; };
// On the outer ellipse (rx 580, ry 330) at an angle, or at a fixed point, in the column of what they feed.
const onRing = (deg, rx = 580, ry = 330) => [CX + rx * Math.cos((deg * Math.PI) / 180), CY - ry * Math.sin((deg * Math.PI) / 180)];
const outer = [
  // OPERATION, in the centre column (user, 2026-11-18): pilot and hostess on top, agency supervisor and IT Admin below.
  ['cashier', 150, 'local'], ['customer', 210, 'online'], ['agency', { x: 527, y: 712 }, 'parcels'],
  ['pilot', { x: 512, y: 95 }, 'trips'], ['hostess', { x: 695, y: 95 }, 'trips'],
  ['staffsup', 35, 'staff'], ['claims', { x: 1097, y: 430 }, 'claims'], ['mechanic', -30, 'fleet'], ['it', { x: 709, y: 712 }, null],
].map(([key, deg, to]) => { const [x, y] = typeof deg === 'object' ? [deg.x, deg.y] : onRing(deg); return { key, x, y, to: to ? mod(to) : [CX, CY] }; });
// Room for the device, the name and the screen, the longer of the two lines decides.
const pillW = k => Math.round(Math.max(L(...ROLE[k].name).length * 9.2, L(...ROLE[k].where).length * 7.4) + 66);

// ---- Slide 2, Services: three equal columns, a row per circuit (user, 2026-11-18) ----
const MCOLS = [
  { key: 'sales', color: SALES, x0: 0, name: ['SERVICES', 'SERVICIOS'], sub: ['(Income)', '(Ingresos)'] },
  { key: 'ops', color: OPS, x0: 400, name: ['OPERATIONS', 'OPERACIONES'], sub: null },
  { key: 'maint', color: MAINT, x0: 800, name: ['MAINTENANCE', 'MANTENIMIENTO'], sub: ['(Q&A)', '(Q&A)'] },
];
// Who does it: the chip on each line ("Murano" = the system does it by itself).
const WHO = {
  murano: { color: '#0f172a', name: ['Murano', 'Murano'] },
  web: { color: '#7c3aed', name: ['Website', 'Página web'] },
  phone: { color: '#0e7490', name: ['Call center', 'Central telefónica'] },
  office: { color: '#0891b2', name: ['Ticket office staff', 'Personal de boletería'] },
  super: { color: '#ea580c', name: ['Supervisor', 'Supervisor'] },
  crew: { color: '#0369a1', name: ['Hostess / pilot(s)', 'Azafata / piloto(s)'] },
  crew1: { color: '#0369a1', name: ['Hostess or pilot', 'Azafata o piloto'] },
  claims: { color: '#b45309', name: ['Complaints', 'Reclamos'] },
  care: { color: '#b45309', name: ['Customer care', 'Atención al cliente'] },
};
const chipW = it => Math.round(L(...WHO[it.who].name).length * 7.3 + 22);
// The line's text shrinks a little when it and its chip don't fit the box.
const itemFs = (it, w) => Math.min(16.5, (w - 44 - chipW(it)) / (L(...it.t).length * 0.55));
const SALE_CHANNELS = [
  { t: ['Online', 'Online'], who: 'web' },
  { t: ['By phone', 'Por teléfono'], who: 'phone' },
  { t: ['At the agency', 'En agencia'], who: 'office' },
];
const CELLS = [
  { key: 's1', col: 0, row: 0, title: ['Ticket sales', 'Venta de pasajes'], items: SALE_CHANNELS },
  { key: 's2', col: 0, row: 1, title: ['Parcel sales', 'Venta de encomiendas'], items: SALE_CHANNELS },
  { key: 'o1', col: 1, row: 0, title: ['Tickets', 'Pasajes'], items: [
    { t: ['Seat on the vehicle', 'Asiento en el vehículo'], who: 'murano' },
    { t: ['Vehicle ready', 'Preparación del vehículo'], who: 'super' },
    { t: ['Transport', 'Transporte'], who: 'crew' },
  ] },
  { key: 'o2', col: 1, row: 1, title: ['Parcels', 'Encomiendas'], items: [
    { t: ['Parcel received', 'Recepción del paquete'], who: 'office' },
    { t: ['By vehicle and room', 'Distribución por vehículo y espacio'], who: 'murano' },
  ] },
  { key: 'm1', col: 2, row: 0, title: ['Tickets', 'Pasajes'], items: [
    { t: ['Incident report', 'Reporte de incidencias'], who: 'crew1' },
    { t: ['Travel time', 'Tiempo de viaje'], who: 'murano' },
    { t: ['Service quality', 'Calidad de servicio'], who: 'claims' },
  ] },
  { key: 'm2', col: 2, row: 1, title: ['Parcels', 'Encomiendas'], items: [
    { t: ['Delivery confirmed', 'Confirmación de entrega'], who: 'murano' },
    { t: ['Complaints answered', 'Atención de reclamos'], who: 'care' },
  ] },
];
const M_STEP = 52;
const M_ROW_Y = [90, 345]; // each row the same height in every column: a circuit reads left to right
const M_H = 48 + 3 * M_STEP + 10;
const mboxes = CELLS.map(cell => ({ ...cell, color: MCOLS[cell.col].color, x: cell.col * 400 + 16, y: M_ROW_Y[cell.row], w: 368, h: M_H }));
const mArrows = [0, 1].flatMap(r => [0, 1].map(col => [col * 400 + 16 + 368 + 2, M_ROW_Y[r] + M_H / 2, (col + 1) * 400 + 16 - 4]));

// ---- The groups, one slide each (2026-11-18): Services, Operation, Maintenance -- one lane per sub-group ----
const PROCESSES = {
  // The circuits as the business runs them (user, 2026-11-18): the customer asks (phone, web, agency) and buys at the ticket
  // office, the service is given, it is reported. A parcel can go on the next bus with room or be scheduled for another day.
  operation: {
    color: OPS,
    end: ['The day is closed with its PDF: punctuality, occupancy and income on the central panel', 'El día se cierra con su PDF: puntualidad, ocupación e ingresos en el panel central'],
    lanes: [
      { name: [['Trips'], ['Viajes']], steps: [
        { who: 'agency', icon: '📅', title: [['Plans the', 'departures'], ['Programa', 'las salidas']] },
        { who: 'staffsup', icon: '👥', title: [['Assigns pilot', 'and hostess'], ['Asigna piloto', 'y azafata']] },
        { who: 'hostess', icon: '📲', title: [['Boarding', 'with QR'], ['Abordaje', 'con QR']] },
        { who: 'pilot', icon: '🛣️', title: [['Trip', 'with GPS'], ['Viaje', 'con GPS']] },
        { who: 'pilot', icon: '📸', title: [['Arrival', 'with photo'], ['Llegada', 'con foto']] },
      ] },
      { name: [['Parcels'], ['Encomiendas']], steps: [
        { who: 'cashier', icon: '📏', title: [['Received and', 'registered'], ['Se recibe', 'y registra']] },
        { who: 'system', icon: '🚌', title: [['Next bus with room', 'or another day'], ['Siguiente bus con', 'espacio u otro día']] },
        { who: 'hostess', icon: '📦', title: [['Loaded', 'on the bus'], ['Sube', 'al bus']] },
        { who: 'agency', icon: '🤝', title: [['Delivered at the', 'destination agency'], ['Se entrega en la', 'agencia destino']] },
      ] },
    ],
  },
  maint: {
    color: MAINT,
    end: ['Alerts and costs reach the central panel: fatigue, complaints, what falls due', 'Alertas y costos llegan al panel central: fatiga, reclamos, vencimientos'],
    lanes: [
      { name: [['Staff'], ['Personal']], steps: [
        { who: 'staffsup', icon: '🗓️', title: [['Shifts', 'and rest'], ['Turnos', 'y descansos']] },
        { who: 'system', icon: '🔔', title: [['Fatigue', 'alert'], ['Alerta', 'de fatiga']] },
        { who: 'staffsup', icon: '🔄', title: [['Coordinates', 'with pilots'], ['Coordina', 'con pilotos']] },
      ] },
      { name: [['Complaints'], ['Reclamos']], steps: [
        { who: 'customer', icon: '📝', title: [['The customer', 'complains'], ['El cliente', 'reclama']] },
        { who: 'claims', icon: '💬', title: [['Recorded', 'and answered'], ['Se registra', 'y responde']] },
        { who: 'claims', icon: '📊', title: [['Complaints', 'board'], ['Tablero', 'de reclamos']] },
      ] },
      { name: [['Fleet and', 'equipment'], ['Flota y', 'equipos']], steps: [
        { who: 'mechanic', icon: '🗓️', title: [['Plan by km', 'and date'], ['Plan por km', 'y fecha']] },
        { who: 'system', icon: '🔔', title: [['Alert:', 'falls due'], ['Alerta:', 'vence']] },
        { who: 'mechanic', icon: '🔧', title: [['Workshop', 'and parts'], ['Taller', 'y repuestos']] },
        { who: 'mechanic', icon: '✅', title: [['Bus back', 'in service'], ['Bus vuelve', 'a operar']] },
      ] },
    ],
  },
};
const current = computed(() => {
  const p = PROCESSES[tab.value];
  return p ? { ...p, title: tabs.value[tabIdx.value]?.label } : { lanes: [] };
});
// Each lane: its band, its cards spread over the width, an arrow between cards.
const lanes = computed(() => {
  const ls = current.value.lanes || [];
  const gap = 10;
  // Compact (2026-11-18: tall cards tire the eye): a lane is at most 190 high, the lanes centred on the slide.
  const h = Math.min(190, (597 - (ls.length - 1) * gap) / Math.max(1, ls.length));
  const start = 8 + (597 - (ls.length * h + (ls.length - 1) * gap)) / 2;
  return ls.map((ln, i) => {
    const top = start + i * (h + gap);
    const n = ln.steps.length;
    const step = 960 / Math.max(4, n);
    const cw = step - 44;
    const ch = h - 24;
    const cards = ln.steps.map((s, k) => ({ ...s, x: 220 + step / 2 + k * step }));
    const arrows = cards.slice(1).map((b, k) => `M${cards[k].x + cw / 2 + 4},${top + h / 2} L${b.x - cw / 2 - 6},${top + h / 2}`);
    return { ...ln, top, h, mid: top + h / 2, cw, ch, cards, arrows };
  });
});
const legend = computed(() => {
  if (tab.value === 'people') return [];
  return [...new Set((current.value.lanes || []).flatMap(l => l.steps.map(s => s.who)))];
});

// Full screen for the presentation.
const root = ref(null);
const isFull = ref(false);
const onFull = () => { isFull.value = !!document.fullscreenElement; };
function toggleFull() {
  if (document.fullscreenElement) document.exitFullscreen?.();
  else root.value?.requestFullscreen?.();
}
const onKey = e => { if (e.key === 'ArrowRight') go(1); if (e.key === 'ArrowLeft') go(-1); };
onMounted(() => { document.addEventListener('fullscreenchange', onFull); window.addEventListener('keydown', onKey); });
onBeforeUnmount(() => { document.removeEventListener('fullscreenchange', onFull); window.removeEventListener('keydown', onKey); });
</script>

<style scoped>
.hw { background: #f8fafc; border-radius: 12px; padding: 12px 16px; display: flex; flex-direction: column; min-height: calc(100vh - 140px); }
.hw.dark { background: #111827; }
.hw.full { border-radius: 0; min-height: 100vh; padding: 16px 28px; }
.hw-bar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.hw-sp { flex: 1; }
.hw-svg { flex: 1; width: 100%; min-height: 0; max-height: calc(100vh - 210px); font-family: inherit; }
.hw.full .hw-svg { max-height: calc(100vh - 110px); }
.hw-legend { display: flex; gap: 8px; flex-wrap: wrap; justify-content: center; min-height: 28px; }
.hw-help { display: flex; flex-wrap: wrap; justify-content: center; gap: 4px 28px; font-size: 15px; opacity: 0.8; margin-top: 4px; }
.hw.dark .hw-help { color: #e2e8f0; }
.hw-tab {
  font: inherit; font-size: 17px; font-weight: 700; padding: 9px 22px; border-radius: 10px; cursor: pointer;
  color: var(--tone); background: transparent; border: 2px solid var(--tone); transition: background 0.15s, color 0.15s;
}
.hw-tab:hover { background: color-mix(in srgb, var(--tone) 12%, transparent); }
.hw-tab.on { background: var(--tone); color: #fff; }
.hw-chip { color: #fff; border-radius: 14px; padding: 3px 12px; font-size: 13px; font-weight: 600; }
</style>
