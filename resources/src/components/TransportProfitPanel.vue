<template>
  <!-- Was each route worth it? (2026-11-04): income minus costs by route and, opening a route,
       by trip. A trip still to leave is "pending", not a loss, and stays out of the totals. -->
  <div ref="root">
  <a-card v-if="data.enabled" class="pp" :bordered="false" :body-style="{ padding: '8px' }">
    <a-spin :spinning="loading">
      <TransportStrip :items="tiles" :toggle="false" />
      <div class="pp-grid">
      <div class="pp-main">
      <a-table :columns="columns" :data-source="data.routes" row-key="route_id" size="small" :pagination="false" :scroll="tableScroll">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'route'"><b>{{ record.route }}</b></template>
          <template v-else-if="column.key === 'trips'">
            <span class="pos">{{ record.gain_trips }}</span> / <span class="neg">{{ record.loss_trips }}</span>
            <span v-if="record.trips_pending" class="pp-muted"> · {{ record.trips_pending }} {{ es ? 'por salir' : 'to leave' }}</span>
          </template>
          <template v-else-if="column.key === 'occupancy'">{{ record.occupancy != null ? record.occupancy + ' %' : '—' }}</template>
          <template v-else-if="column.key === 'income'">{{ money(record.income) }}</template>
          <template v-else-if="column.key === 'costs'">{{ money(record.costs) }}</template>
          <template v-else-if="column.key === 'profit'"><b :class="record.profit >= 0 ? 'pos' : 'neg'">{{ record.trips_run ? money(record.profit) : '—' }}</b></template>
        </template>
        <template #expandedRowRender="{ record }">
          <a-table :columns="tripColumns" :data-source="record.trips" row-key="id" size="small" :pagination="false" :scroll="{ x: 'max-content' }">
            <template #bodyCell="{ column, record: t }">
              <template v-if="column.key === 'departure'">{{ fmt(t.departure_at) }}<div class="pp-muted">{{ t.vehicle }}</div></template>
              <template v-else-if="column.key === 'seats'">
                {{ t.sold }} {{ es ? 'boletos' : 'tickets' }} · {{ t.occupancy != null ? t.occupancy + ' %' : '—' }}
                <div v-if="t.vip" class="pp-muted">{{ t.vip }} VIP</div>
                <div v-if="t.break_even_seats != null" class="pp-muted">{{ es ? 'se paga con' : 'covered at' }} {{ t.break_even_seats }} {{ es ? 'boletos' : 'tickets' }}</div>
                <div v-for="l in t.legs" :key="l.leg" class="pp-muted">{{ l.leg }}: {{ l.tickets }}</div>
              </template>
              <template v-else-if="column.key === 'income'">{{ money(t.income.total) }}<div class="pp-muted">{{ es ? 'Enc.' : 'Parcels' }} {{ money(t.income.parcels) }} ({{ t.parcels }})</div></template>
              <template v-else-if="column.key === 'staff'">{{ money(t.costs.staff) }}</template>
              <template v-else-if="column.key === 'payroll'">{{ money(t.costs.payroll) }}</template>
              <template v-else-if="column.key === 'fuel'">{{ money(t.costs.fuel) }}<div v-if="t.estimated.includes('fuel')" class="pp-muted">{{ es ? 'estimado' : 'estimated' }}</div></template>
              <template v-else-if="column.key === 'maintenance'">{{ money(t.costs.maintenance) }}</template>
              <template v-else-if="column.key === 'depreciation'">{{ money(t.costs.depreciation) }}</template>
              <template v-else-if="column.key === 'tolls'">{{ money(t.costs.tolls) }}<div v-if="t.estimated.includes('tolls')" class="pp-muted">{{ es ? 'esperado' : 'expected' }}</div></template>
              <template v-else-if="column.key === 'agency'">{{ money(t.costs.agency) }}</template>
              <template v-else-if="column.key === 'meals'">{{ money(t.costs.meals) }}</template>
              <template v-else-if="column.key === 'profit'"><b :class="t.result === 'pending' ? '' : (t.profit >= 0 ? 'pos' : 'neg')">{{ money(t.profit) }}</b></template>
              <template v-else-if="column.key === 'result'"><a-tag :color="RESULT[t.result].color">{{ es ? RESULT[t.result].es : RESULT[t.result].en }}</a-tag></template>
            </template>
          </a-table>
        </template>
      </a-table>

      </div>
      <!-- What each stretch brings in: the short ones ride on the long trips. -->
      <div v-if="data.legs?.length" class="pp-side" :style="sideHeight ? { maxHeight: sideHeight + 'px' } : null">
        <div class="pp-sub-title">{{ es ? 'Ingresos por tramo' : 'Income by stretch' }}</div>
        <div class="pp-legs">
          <div v-for="l in data.legs" :key="l.leg" class="pp-leg" :title="`${l.tickets} ${es ? 'boletos' : 'tickets'}`"><span class="pp-leg-name">{{ l.leg }}</span><b>{{ money(l.income) }}</b></div>
        </div>
      </div>
      </div>
    </a-spin>
  </a-card>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import dayjs from 'dayjs';
import http from '../lib/http';
import { useFormat } from '../composables/useFormat';
import { useUiStore } from '../stores/ui';
import { useFitHeight } from '../lib/transportCharts';
import TransportStrip from './TransportStrip.vue';

// The dashboard's own date filter (2026-11-04): [from, to], null = its "All" before it has dates.
const props = defineProps({ range: { type: Array, default: null } });
const root = ref(null);
const { fit, measure } = useFitHeight(root);
// One screen: what doesn't fit (a route opened to its trips) scrolls inside the table.
const sideHeight = computed(() => (fit.value ? fit.value - 16 - 58 : null));
const tableScroll = computed(() => (fit.value ? { x: 'max-content', y: fit.value - 16 - 58 - 40 } : { x: 'max-content' }));

const { money } = useFormat();
const es = computed(() => useUiStore().locale === 'es');
const RESULT = {
  gain: { color: 'green', en: 'Profit', es: 'Ganancia' },
  loss: { color: 'red', en: 'Loss', es: 'Pérdida' },
  pending: { color: 'blue', en: 'To leave', es: 'Por salir' },
};
const fmt = d => (d ? dayjs(d).format('DD/MM HH:mm') : '');

const loading = ref(false);
const data = ref({ enabled: false, routes: [], totals: {}, missing: {} });
const totals = computed(() => data.value.totals || {});

async function load() {
  loading.value = true;
  try {
    const res = await http.get('transport/profit', { from: props.range?.[0], to: props.range?.[1] });
    data.value = { routes: [], totals: {}, missing: {}, ...res };
  } catch (e) {
    data.value = { enabled: false, routes: [], totals: {}, missing: {} };
  } finally {
    loading.value = false;
    nextTick(measure);
  }
}
onMounted(load);

const missingLines = computed(() => {
  const m = data.value.missing || {};
  const line = (list, en, sp) => (list?.length ? `${es.value ? sp : en}: ${list.join(', ')}` : null);
  return [
    line(m.seats, 'Buses without seats (Fleet › Vehicles)', 'Buses sin asientos (Flota › Vehículos)'),
    line(m.depreciation, 'Buses without price or useful life: no depreciation counted', 'Buses sin precio o vida útil: no se cuenta depreciación'),
    line(m.fuel, 'Buses without fuel records: no fuel counted', 'Buses sin registros de combustible: no se cuenta combustible'),
    line(m.tolls, 'Routes without tolls: none counted', 'Rutas sin peajes: no se cuentan'),
    line(m.distance, 'Routes without distance', 'Rutas sin distancia'),
  ].filter(Boolean);
});

const tiles = computed(() => {
  const t = totals.value;
  const out = [
    { label: es.value ? 'Ganancia' : 'Profit', value: money(t.profit), level: t.profit < 0 ? 'bad' : 'ok', help: `${t.trips_run || 0} ${es.value ? 'viajes realizados' : 'trips run'}${t.trips_pending ? ` · ${t.trips_pending} ${es.value ? 'por salir' : 'to leave'}` : ''}` },
    { label: es.value ? 'Ingresos' : 'Income', value: money(t.income), help: `${es.value ? 'Pasajes' : 'Tickets'} ${money(t.tickets_income)} · ${es.value ? 'Encomiendas' : 'Parcels'} ${money(t.parcels_income)}` },
    { label: es.value ? 'Gastos' : 'Costs', value: money(t.costs), help: `${es.value ? 'Por viaje' : 'Per trip'} ${money(t.staff)} · ${es.value ? 'Planilla' : 'Payroll'} ${money(t.payroll)} · ${es.value ? 'Combustible' : 'Fuel'} ${money(t.fuel)} · ${es.value ? 'Mant.' : 'Maint.'} ${money(t.maintenance)} · Deprec. ${money(t.depreciation)} · ${es.value ? 'Peajes' : 'Tolls'} ${money(t.tolls)} · ${es.value ? 'Agencias' : 'Agencies'} ${money(t.agency)} · ${es.value ? 'Snack y bebida' : 'Snack and drink'} ${money(t.meals)}` },
    { label: es.value ? 'Viajes +/-' : 'Trips +/-', value: `${t.gain_trips || 0} / ${t.loss_trips || 0}`, help: es.value ? 'Con ganancia / con pérdida.' : 'With profit / with loss.' },
    { label: es.value ? 'Ocupación' : 'Occupancy', value: t.occupancy != null ? `${t.occupancy} %` : '—', help: `${t.tickets || 0} ${es.value ? 'boletos' : 'tickets'} · ${t.vip || 0} VIP` },
  ];
  if (missingLines.value.length) {
    out.push({ label: es.value ? 'Faltan datos' : 'Data missing', value: missingLines.value.length, level: 'warn', warn: true, help: missingLines.value.join(' · ') });
  }
  return out;
});

const columns = computed(() => [
  { title: es.value ? 'Ruta' : 'Route', key: 'route' },
  { title: es.value ? 'Viajes +/-' : 'Trips +/-', key: 'trips' },
  { title: es.value ? 'Ocupación' : 'Occupancy', key: 'occupancy', align: 'right' },
  { title: es.value ? 'Ingresos' : 'Income', key: 'income', align: 'right' },
  { title: es.value ? 'Gastos' : 'Costs', key: 'costs', align: 'right' },
  { title: es.value ? 'Ganancia' : 'Profit', key: 'profit', align: 'right' },
]);
const tripColumns = computed(() => [
  { title: es.value ? 'Salida' : 'Departure', key: 'departure' },
  { title: es.value ? 'Boletos' : 'Tickets', key: 'seats' },
  { title: es.value ? 'Ingresos' : 'Income', key: 'income', align: 'right' },
  { title: es.value ? 'Pago por viaje' : 'Pay per trip', key: 'staff', align: 'right' },
  { title: es.value ? 'Planilla' : 'Payroll', key: 'payroll', align: 'right' },
  { title: es.value ? 'Combustible' : 'Fuel', key: 'fuel', align: 'right' },
  { title: es.value ? 'Mantenimiento' : 'Maintenance', key: 'maintenance', align: 'right' },
  { title: es.value ? 'Depreciación' : 'Depreciation', key: 'depreciation', align: 'right' },
  { title: es.value ? 'Peajes' : 'Tolls', key: 'tolls', align: 'right' },
  { title: es.value ? 'Agencia' : 'Agency', key: 'agency', align: 'right' },
  { title: es.value ? 'Snack y bebida' : 'Snack and drink', key: 'meals', align: 'right' },
  { title: es.value ? 'Ganancia' : 'Profit', key: 'profit', align: 'right' },
  { title: '', key: 'result' },
]);
</script>

<style scoped>
.pp { margin-bottom: 0; }
.pp-grid { display: grid; gap: 8px; grid-template-columns: 1fr; }
.pp-main, .pp-side { min-width: 0; }
.pp-main :deep(.ant-table-thead > tr > th) { padding: 3px 6px !important; white-space: nowrap; font-size: 11.5px; }
.pp-main :deep(.ant-table-tbody > tr > td) { padding: 2px 6px !important; font-size: 12px; }
.pp-side { border: 1px solid rgba(128, 128, 128, 0.2); border-radius: 8px; padding: 6px 8px; overflow-y: auto; }
.pp-sub-title { font-weight: 600; font-size: 13px; margin-bottom: 2px; }
.pp-legs { font-size: 11.5px; }
.pp-leg { display: flex; align-items: baseline; gap: 6px; border-top: 1px solid rgba(128, 128, 128, 0.15); padding: 2px 0; }
.pp-leg-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pp-muted { font-size: 11.5px; opacity: 0.68; font-weight: 400; }
.pos { color: #15803d; }
.neg { color: #b91c1c; }
@media (min-width: 900px) {
  .pp-grid { grid-template-columns: minmax(0, 3fr) minmax(0, 1fr); }
}
</style>
