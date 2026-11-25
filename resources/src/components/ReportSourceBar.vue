<template>
  <!-- One line on top of a board (user's request, 2026-11-25: "ponle títulos acorde a de dónde lo saca y si usa filtros"):
       the board's name, where its figures come from (with AuraSoft's latest file) and the filters it follows. The whole
       detail in the (i) popup, so the board still fits one screen. -->
  <div class="rsb">
    <span class="rsb-title">{{ info.title }}</span>
    <span class="rsb-sep">·</span>
    <span class="rsb-lbl">{{ es ? 'Fuente:' : 'Source:' }}</span>
    <span class="rsb-val">{{ info.source }}</span>
    <span class="rsb-sep">·</span>
    <span class="rsb-lbl">{{ es ? 'Filtro:' : 'Filter:' }}</span>
    <span class="rsb-val">{{ filterText }}</span>
    <a-tooltip placement="bottomLeft" overlay-class-name="rsb-tip">
      <template #title>
        <div class="rsb-tip-body">
          <div><b>{{ info.title }}</b></div>
          <div>{{ info.source }}</div>
          <div v-for="(f, i) in files" :key="i" class="rsb-file">
            📄 {{ f.label }}: <template v-if="f.name">{{ f.name }} — {{ es ? 'subido' : 'uploaded' }} {{ fmt.dateTime(f.at) }}<template v-if="f.by"> ({{ f.by }})</template>.
              {{ es ? 'Datos del' : 'Data from' }} {{ fmt.date(f.from) }} {{ es ? 'al' : 'to' }} {{ fmt.date(f.to) }}</template>
            <template v-else>{{ es ? 'todavía no se subió ningún archivo' : 'no file uploaded yet' }}</template>
            <div v-if="f.periods.length > 1" class="rsb-per">
              {{ es ? 'Fechas con archivo:' : 'Dates with a file:' }}
              <span v-for="(p, j) in f.periods" :key="j">{{ j ? '; ' : ' ' }}{{ fmt.date(p.from) }} – {{ fmt.date(p.to) }}</span>
            </div>
          </div>
          <div v-if="info.files.includes('production')" class="rsb-per">{{ es
            ? 'Las fechas sin «Reporte producción por bus» vienen del reporte del día del cliente: solo pasajeros y monto, sin encomiendas ni exceso.'
            : 'Dates without a «Production per bus» file come from the client\'s daily report: passengers and amount only, no parcels or excess.' }}</div>
          <div style="margin-top: 4px">{{ es ? 'Filtro:' : 'Filter:' }} {{ filterText }}</div>
          <div v-if="info.note">{{ info.note }}</div>
        </div>
      </template>
      <InfoCircleOutlined class="rsb-i" />
    </a-tooltip>
  </div>
</template>

<script>
const cache = { p: null, at: 0 };
</script>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { InfoCircleOutlined } from '@ant-design/icons-vue';
import http from '../lib/http';
import { useFormat } from '../composables/useFormat';
import { useUiStore } from '../stores/ui';

const props = defineProps({
  board: { type: String, required: true }, // the dashboard tab key
  range: { type: Array, default: null }, // [from, to] YYYY-MM-DD; null = all dates
});

const fmt = useFormat();
const es = computed(() => useUiStore().locale === 'es');
const L = (e, s) => (es.value ? s : e);

// Shared by the bars opened within a minute (each tab mounts its own): one request.
const sources = ref(null);
onMounted(async () => {
  if (!cache.p || Date.now() - cache.at > 60000) { cache.at = Date.now(); cache.p = http.get('transport/report-sources').catch(() => ({})); }
  const r = await cache.p;
  sources.value = r?.sources || {};
});

const AURA = {
  production: () => L('AuraSoft "Production per bus" report', 'AuraSoft «Reporte producción por bus»'),
  liquidation: () => L('AuraSoft "General settlement"', 'AuraSoft «Liquidación General»'),
  detail: () => L('AuraSoft "Detailed settlement"', 'AuraSoft «Liquidación Detallada»'),
  parcels: () => L('AuraSoft "Parcels to deliver" kardex', 'AuraSoft «Kardex de encomiendas por entregar»'),
};
const MURANO_TRIPS = () => L('Murano departures (loaded from AuraSoft\'s production report)', 'salidas de Murano (cargadas del «Reporte producción por bus» de AuraSoft)');

// What each board reads and which date it follows: range = the period, day = the period's last day, none = the current state.
const BOARDS = {
  transport_daysales: { t: ['Day\'s sales', 'Ventas del día'], s: () => `${AURA.production()} + ${L('fuel and tolls typed in Murano', 'combustible y peajes registrados en Murano')}`, files: ['production'], date: 'day' },
  transport_production: { t: ['Production per bus', 'Producción por bus'], s: () => `${AURA.production()} + ${L('fuel and tolls typed in Murano', 'combustible y peajes registrados en Murano')}`, files: ['production'], date: 'range' },
  transport_pointsales: { t: ['Sales by point of sale', 'Ventas por punto de venta'], s: () => AURA.liquidation(), files: ['liquidation'], date: 'range' },
  transport_cashbook: { t: ['Cash and customers', 'Caja y clientes'], s: () => AURA.detail(), files: ['detail'], date: 'range' },
  transport_customers: { t: ['Customers', 'Clientes'], s: () => `${AURA.detail()} (${L('DNI / RUC of each sale', 'DNI / RUC de cada venta')})`, files: ['detail'], date: 'range' },
  transport_aura_parcels: { t: ['AuraSoft parcels', 'Encomiendas AuraSoft'], s: () => AURA.parcels(), files: ['parcels'], date: 'none',
    n: () => L('It shows the latest kardex uploaded per branch; the date filter does not apply.', 'Muestra el último kardex subido de cada sucursal; el filtro de fechas no se aplica.') },
  transport_departures: { t: ['Departures report', 'Reporte de salidas'], s: () => MURANO_TRIPS(), files: ['production'], date: 'day',
    n: () => L('Its own agency filter is inside the board.', 'El filtro de agencia está dentro del tablero.') },
  transport_outlook: { t: ['Operations outlook', 'Mando operativo'], s: () => L('Murano upcoming departures and the tickets sold ahead', 'próximas salidas de Murano y los pasajes vendidos por adelantado'), files: [], date: 'none',
    n: () => L('Always from today on; the date filter does not apply.', 'Siempre de hoy en adelante; el filtro de fechas no se aplica.') },
  transport_owner: { t: ['Business summary', 'Resumen del negocio'], s: () => `${MURANO_TRIPS()} + ${L('the buses\' costs in Murano', 'los costos de los buses en Murano')}`, files: ['production'], date: 'range' },
  transport_profit: { t: ['Profit by route', 'Rentabilidad por ruta'], s: () => `${MURANO_TRIPS()} + ${L('costs in Murano (payroll, fuel, tolls, maintenance)', 'costos en Murano (planilla, combustible, peajes, mantenimiento)')}`, files: ['production'], date: 'range' },
  transport_expenses: { t: ['Expenses', 'Gastos'], s: () => L('Murano: the departures\' costs + Accounting -> Expenses of the agencies', 'Murano: costos de las salidas + Contabilidad -> Gastos de las agencias'), files: [], date: 'range' },
  transport_indicators: { t: ['Indicators', 'Indicadores'], s: () => `${MURANO_TRIPS()} + ${L('costs in Murano', 'costos en Murano')}`, files: ['production'], date: 'range' },
  transport_risks: { t: ['Risks', 'Riesgos'], s: () => `${MURANO_TRIPS()} + ${L('costs in Murano', 'costos en Murano')}`, files: ['production'], date: 'range',
    n: () => L('The risk thresholds are ours, not yet confirmed by the owner.', 'Los umbrales de riesgo son nuestros, aún sin confirmar por el dueño.') },
  transport_parcels: { t: ['Parcels', 'Encomiendas'], s: () => L('Murano parcels (registered in Murano or loaded with the departures)', 'encomiendas de Murano (registradas en Murano o cargadas con las salidas)'), files: ['production'], date: 'range',
    n: () => L('Its own agency filter is inside the board.', 'El filtro de agencia está dentro del tablero.') },
  transport_claims: { t: ['Complaints', 'Reclamos'], s: () => L('Murano Forms: the complaints book (the website\'s form)', 'Formularios de Murano: el libro de reclamaciones (formulario del website)'), files: [], date: 'range' },
  transport_monitor: { t: ['Control board', 'Tablero de control'], s: () => L('the pilots\' reports in My trip and the phones\' GPS', 'los reportes de los pilotos en «Mi viaje» y el GPS de sus teléfonos'), files: [], date: 'none',
    n: () => L('Its own day picker is inside the board.', 'El día se elige dentro del tablero.') },
  transport: { t: ['Transport board', 'Tablero de transporte'], s: () => L('Murano departures, buses and crews', 'salidas, buses y tripulaciones de Murano'), files: [], date: 'range' },
};

const info = computed(() => {
  const b = BOARDS[props.board] || { t: [props.board, props.board], s: () => 'Murano', files: [], date: 'range' };
  return { title: L(...b.t), source: b.s(), note: b.n ? b.n() : '', date: b.date, files: b.files };
});

const files = computed(() => info.value.files.map(k => {
  const s = sources.value?.[k] || {};
  return { label: AURA[k](), name: s.last_name, at: s.last_at, by: s.last_by, from: s.from, to: s.to, periods: s.periods || [] };
}));

const filterText = computed(() => {
  const r = props.range;
  const agencies = L('agencies of your login', 'agencias de tu usuario');
  if (info.value.date === 'none') return `${L('no date filter', 'sin filtro de fechas')} · ${agencies}`;
  if (!r || !r[0] || !r[1]) return `${L('all dates', 'todas las fechas')} · ${agencies}`;
  if (info.value.date === 'day') return `${L('day', 'día')} ${fmt.date(r[1])} (${L('last day of the period', 'último día del período')}) · ${agencies}`;
  if (r[0] === r[1]) return `${fmt.date(r[0])} · ${agencies}`;
  return `${fmt.date(r[0])} ${L('to', 'al')} ${fmt.date(r[1])} · ${agencies}`;
});
</script>

<style scoped>
.rsb { display: flex; align-items: center; gap: 6px; font-size: 12px; line-height: 20px; margin: -4px 0 6px; white-space: nowrap; overflow: hidden; color: var(--ant-color-text-secondary, rgba(0, 0, 0, 0.55)); }
.rsb-title { font-weight: 600; color: var(--ant-color-text, inherit); }
.rsb-lbl { opacity: 0.8; }
.rsb-val { overflow: hidden; text-overflow: ellipsis; min-width: 0; }
.rsb-sep { opacity: 0.5; }
.rsb-i { cursor: help; flex: none; }
.rsb-tip-body { font-size: 12px; line-height: 18px; }
.rsb-file { margin-top: 2px; }
.rsb-per { margin-top: 2px; opacity: 0.85; }
</style>
