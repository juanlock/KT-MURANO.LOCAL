<template>
  <!-- Was each route worth it? (2026-11-04): income minus costs by route and, opening a route,
       by trip. A trip still to leave is "pending", not a loss, and stays out of the totals. -->
  <a-card v-if="data.enabled" class="pp" :bordered="false">
    <template #title>
      <span class="pp-title"><LineChartOutlined /> {{ es ? 'Rentabilidad por ruta' : 'Profit by route' }}</span>
    </template>
    <a-spin :spinning="loading">
      <a-alert v-if="missingLines.length" type="warning" show-icon class="pp-alert" :message="es ? 'Faltan datos: estas cifras no están completas' : 'Data is missing: these figures are incomplete'">
        <template #description><div v-for="m in missingLines" :key="m">{{ m }}</div></template>
      </a-alert>

      <div class="pp-cards">
        <div class="pp-card" :class="totals.profit >= 0 ? 'good' : 'bad'">
          <div class="pp-label">{{ es ? 'Ganancia' : 'Profit' }}</div>
          <div class="pp-value">{{ money(totals.profit) }}</div>
          <div class="pp-sub">{{ totals.trips_run || 0 }} {{ es ? 'viajes realizados' : 'trips run' }}<template v-if="totals.trips_pending"> · {{ totals.trips_pending }} {{ es ? 'por salir' : 'to leave' }}</template></div>
        </div>
        <div class="pp-card">
          <div class="pp-label">{{ es ? 'Ingresos' : 'Income' }}</div>
          <div class="pp-value">{{ money(totals.income) }}</div>
          <div class="pp-sub">{{ es ? 'Pasajes' : 'Tickets' }} {{ money(totals.tickets_income) }} · {{ es ? 'Encomiendas' : 'Parcels' }} {{ money(totals.parcels_income) }}</div>
        </div>
        <div class="pp-card">
          <div class="pp-label">{{ es ? 'Gastos' : 'Costs' }}</div>
          <div class="pp-value">{{ money(totals.costs) }}</div>
          <div class="pp-sub">{{ es ? 'Por viaje' : 'Per trip' }} {{ money(totals.staff) }} · {{ es ? 'Planilla' : 'Payroll' }} {{ money(totals.payroll) }} ·{{ es ? 'Combustible' : 'Fuel' }} {{ money(totals.fuel) }} · {{ es ? 'Mant.' : 'Maint.' }} {{ money(totals.maintenance) }} · {{ es ? 'Deprec.' : 'Deprec.' }} {{ money(totals.depreciation) }} · {{ es ? 'Peajes' : 'Tolls' }} {{ money(totals.tolls) }} · {{ es ? 'Agencias' : 'Agencies' }} {{ money(totals.agency) }} · {{ es ? 'Snack y bebida' : 'Snack and drink' }} {{ money(totals.meals) }}</div>
        </div>
        <div class="pp-card">
          <div class="pp-label">{{ es ? 'Viajes con ganancia / pérdida' : 'Trips with profit / loss' }}</div>
          <div class="pp-value"><span class="pos">{{ totals.gain_trips || 0 }}</span> / <span class="neg">{{ totals.loss_trips || 0 }}</span></div>
          <div class="pp-sub">{{ es ? 'Ocupación' : 'Occupancy' }}: {{ totals.occupancy != null ? totals.occupancy + ' %' : '—' }} · {{ totals.tickets || 0 }} {{ es ? 'boletos' : 'tickets' }} · {{ totals.vip || 0 }} VIP</div>
        </div>
      </div>

      <a-table :columns="columns" :data-source="data.routes" row-key="route_id" size="small" :pagination="false" :scroll="{ x: 'max-content' }">
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

      <!-- What each stretch brings in: the short ones ride on the long trips. -->
      <template v-if="data.legs?.length">
        <div class="pp-sub-title">{{ es ? 'Ingresos por tramo' : 'Income by stretch' }}</div>
        <div class="pp-legs">
          <div v-for="l in data.legs" :key="l.leg" class="pp-leg"><span>{{ l.leg }}</span><b>{{ money(l.income) }}</b><span class="pp-muted">{{ l.tickets }} {{ es ? 'boletos' : 'tickets' }}</span></div>
        </div>
      </template>
    </a-spin>
  </a-card>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import dayjs from 'dayjs';
import { LineChartOutlined } from '@ant-design/icons-vue';
import http from '../lib/http';
import { useFormat } from '../composables/useFormat';
import { useUiStore } from '../stores/ui';

// The dashboard's own date filter (2026-11-04): [from, to], null = its "All" before it has dates.
const props = defineProps({ range: { type: Array, default: null } });

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

const columns = computed(() => [
  { title: es.value ? 'Ruta' : 'Route', key: 'route' },
  { title: es.value ? 'Viajes con ganancia / pérdida' : 'Trips with profit / loss', key: 'trips' },
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
.pp { margin-bottom: 16px; }
.pp-title { display: inline-flex; gap: 8px; align-items: center; font-weight: 600; }
.pp-alert { margin-bottom: 14px; }
.pp-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 12px; margin-bottom: 16px; }
.pp-card { padding: 12px 14px; border: 1px solid #eef0f3; border-radius: 10px; background: #fff; min-width: 0; }
.pp-card.good { background: #f0fdf4; border-color: #bbf7d0; }
.pp-card.bad { background: #fef2f2; border-color: #fecaca; }
.pp-label { font-size: 12px; color: #64748b; }
.pp-value { font-size: 22px; font-weight: 700; line-height: 1.25; overflow-wrap: anywhere; }
.pp-sub { font-size: 11.5px; color: #64748b; }
.pp-sub-title { font-weight: 700; margin: 16px 0 8px; }
.pp-legs { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 4px 16px; font-size: 13px; }
.pp-leg { display: flex; align-items: baseline; gap: 8px; border-bottom: 1px dashed #eef0f3; padding: 3px 0; }
.pp-leg span:first-child { flex: 1; min-width: 0; }
.pp-muted { font-size: 11.5px; color: #64748b; font-weight: 400; }
.pos { color: #15803d; }
.neg { color: #b91c1c; }
</style>
