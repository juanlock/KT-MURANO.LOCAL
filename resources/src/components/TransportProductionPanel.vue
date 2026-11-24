<template>
  <!-- "Producción por bus" (2026-11-23): Mayte's report as a board -- per bus, Lima and Chiclayo, income, fuel, toll and
       what is left; a bus opens its round trips (App\Services\TransportProduction). -->
  <div>
    <a-card v-if="data.enabled" :bordered="false" :body-style="{ padding: '8px' }">
      <a-spin :spinning="loading">
        <TransportStrip :items="tiles" :toggle="false" />
        <a-empty v-if="!buses.length" :description="tr('No departures in this period.', 'Sin salidas en este período.')" />
        <div v-else class="pb-box">
          <a-table class="pb-table" :columns="columns" :data-source="buses" row-key="vehicle_id" size="small" :pagination="false" bordered
            :scroll="{ x: 'max-content' }" :custom-row="r => ({ onClick: () => openBus(r), style: 'cursor: pointer' })" :row-class-name="(_, i) => (i % 2 ? '' : 'pb-tint')">
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'bus'"><b>{{ busName(record.bus) }}</b> <span class="pb-cat">{{ record.category }}</span></template>
              <template v-else-if="column.key === 'occupancy'">{{ record.occupancy != null ? record.occupancy + ' %' : '—' }}</template>
              <template v-else-if="column.key === 'net'"><b :class="record.net < 0 ? 'pb-neg' : 'pb-pos'">{{ plain(record.net) }}</b></template>
              <template v-else-if="column.key === 'fuel'">
                {{ plain(record.fuel) }}<a-tooltip v-if="record.missing_fuel" :title="tr(`${record.missing_fuel} departures without fuel recorded`, `${record.missing_fuel} salidas sin combustible registrado`)"><span class="pb-warn"> *</span></a-tooltip>
              </template>
              <template v-else-if="column.money">{{ plain(get(record, column.dataIndex)) }}</template>
            </template>
            <template #summary>
              <a-table-summary fixed>
                <a-table-summary-row class="pb-total">
                  <a-table-summary-cell :index="0"><b>{{ tr('Total', 'Total') }}</b></a-table-summary-cell>
                  <a-table-summary-cell v-for="(c, i) in leafCols.slice(1)" :key="c.key" :index="i + 1" :align="c.align">
                    <b>{{ totalOf(c) }}</b>
                  </a-table-summary-cell>
                </a-table-summary-row>
              </a-table-summary>
            </template>
          </a-table>
        </div>
      </a-spin>
    </a-card>
    <a-empty v-else-if="!loading" :description="tr('Pick a company with Transport in the top bar to see this.', 'Elige una compañía con Transporte en la barra superior para ver esto.')" />

    <!-- One bus: its round trips, as on the client's sheet per bus. -->
    <a-modal v-model:open="busOpen" :footer="null" width="96vw" :style="{ top: '16px', maxWidth: '1400px' }"
      :title="bus ? `${bus.bus} · ${bus.plate} · ${bus.category}${bus.seats ? ' · ' + bus.seats + ' ' + tr('seats', 'asientos') : ''}` : ''">
      <a-spin :spinning="busLoading">
        <a-table v-if="bus" class="pb-table" :columns="tripColumns" :data-source="bus.rows" :row-key="(r, i) => i" size="small" :pagination="false" bordered :scroll="{ y: 'calc(100vh - 220px)' }">
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'out_at'">{{ record.out ? dt(record.out.at) : '—' }}</template>
            <template v-else-if="column.key === 'back_at'">{{ record.back ? dt(record.back.at) : '—' }}</template>
            <template v-else-if="column.key === 'net'"><b :class="record.net < 0 ? 'pb-neg' : 'pb-pos'">{{ plain(record.net) }}</b></template>
            <template v-else-if="column.key === 'fuel'">{{ plain(record.fuel) }}<span v-if="record.fuel_missing" class="pb-warn"> *</span></template>
            <template v-else-if="column.money">{{ get(record, column.dataIndex) != null ? plain(get(record, column.dataIndex)) : '—' }}</template>
            <template v-else-if="column.num">{{ get(record, column.dataIndex) ?? '—' }}</template>
          </template>
          <template #summary>
            <a-table-summary fixed>
              <a-table-summary-row class="pb-total">
                <a-table-summary-cell v-for="(c, i) in tripLeaf" :key="c.key" :index="i" :align="c.align"><b>{{ i === 0 ? tr('Total', 'Total') : tripTotal(c) }}</b></a-table-summary-cell>
              </a-table-summary-row>
            </a-table-summary>
          </template>
        </a-table>
        <div class="pb-note">{{ tr('* fuel not recorded for one of the trips. Fuel and toll of a round trip are shared half and half between the departure from Lima and the return.', '* combustible no registrado en uno de los viajes. El combustible y el peaje de una vuelta se reparten mitad y mitad entre la salida de Lima y el retorno.') }}</div>
      </a-spin>
    </a-modal>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import dayjs from 'dayjs';
import http from '../lib/http';
import { useTransportCharts } from '../lib/transportCharts';
import TransportStrip from './TransportStrip.vue';

const props = defineProps({ range: { type: Array, default: null } });
const { money, tr } = useTransportCharts();

const loading = ref(false);
const data = ref({ enabled: false, buses: [], totals: {} });
const buses = computed(() => data.value.buses || []);
const period = () => {
  const today = dayjs().format('YYYY-MM-DD');
  return { from: props.range?.[0] || dayjs().startOf('month').format('YYYY-MM-DD'), to: props.range?.[1] && props.range[1] < today ? props.range[1] : today };
};
async function load() {
  loading.value = true;
  try { data.value = await http.get('transport/production', period()); } catch (e) { data.value = { enabled: false, buses: [], totals: {} }; } finally { loading.value = false; }
}
onMounted(load);
watch(() => props.range, load, { deep: true });

const plain = v => money(Number(v || 0)).replace(/^[^\d-]+/, '');
const busName = n => String(n).replace(/^Bus\s+/i, '');
const get = (o, path) => (Array.isArray(path) ? path.reduce((x, k) => (x == null ? x : x[k]), o) : o[path]);
const dt = d => dayjs(d).format('DD/MM hh:mm A');

const tiles = computed(() => {
  const t = data.value.totals || {};
  return [
    { label: tr('Departures', 'Salidas'), value: t.trips ?? 0 },
    { label: tr('Passengers', 'Pasajeros'), value: Number(t.pax || 0).toLocaleString('en-US') },
    { label: tr('Occupancy', 'Ocupación'), value: t.occupancy != null ? `${t.occupancy} %` : '—' },
    { label: tr('Income', 'Ingresos'), value: money(t.income || 0), help: tr('Tickets + parcels + excess luggage.', 'Pasajes + encomiendas + exceso de equipaje.') },
    { label: tr('From Lima', 'Salidas Lima'), value: money(t.lima || 0) },
    { label: tr('From Chiclayo', 'Salidas Chiclayo'), value: money(t.chiclayo || 0) },
    { label: tr('Fuel', 'Combustible'), value: money(t.fuel || 0), help: t.missing_fuel ? tr(`${t.missing_fuel} departures without fuel recorded.`, `${t.missing_fuel} salidas sin combustible registrado.`) : undefined },
    { label: tr('Tolls', 'Peaje'), value: money(t.toll || 0) },
    { label: tr('Income - fuel - tolls', 'Ingresos - combustible - peaje'), value: money(t.net || 0), help: tr("Like the client's sheet; the other costs are in Profit by route.", 'Como la hoja del cliente; los demás costos están en Rentabilidad.') },
  ];
});

const side = (key, title) => ({
  title, children: [
    { title: 'Pax', key: key + 'pax', dataIndex: [key, 'pax'], align: 'right', width: 46, num: true },
    { title: tr('Tickets', 'Pasajes'), key: key + 'tickets', dataIndex: [key, 'tickets'], align: 'right', width: 76, money: true },
    { title: tr('Parcels', 'Encom.'), key: key + 'parcels', dataIndex: [key, 'parcels'], align: 'right', width: 66, money: true },
    { title: tr('Excess', 'Exceso'), key: key + 'excess', dataIndex: [key, 'excess'], align: 'right', width: 56, money: true },
    { title: 'Total', key: key + 'total', dataIndex: [key, 'total'], align: 'right', width: 80, money: true },
  ],
});
const columns = computed(() => [
  { title: tr('Bus / service', 'Bus / servicio'), key: 'bus', dataIndex: 'bus', width: 118, ellipsis: true },
  side('lima', tr('Departures from Lima', 'Salidas de Lima')),
  side('chiclayo', tr('Departures from Chiclayo', 'Salidas de Chiclayo')),
  { title: tr('Total income', 'Total ingresos'), key: 'income', dataIndex: 'income', align: 'right', width: 86, money: true },
  { title: tr('Fuel', 'Combustible'), key: 'fuel', dataIndex: 'fuel', align: 'right', width: 84, money: true },
  { title: tr('Tolls', 'Peaje'), key: 'toll', dataIndex: 'toll', align: 'right', width: 68, money: true },
  { title: tr('Income - fuel - tolls', 'Ingr. - comb. - peaje'), key: 'net', dataIndex: 'net', align: 'right', width: 88, money: true },
  { title: tr('Occ.', 'Ocup.'), key: 'occupancy', dataIndex: 'occupancy', align: 'right', width: 46 },
]);
const leafCols = computed(() => columns.value.flatMap(c => c.children || [c]));
function totalOf(c) {
  if (c.key === 'occupancy') return data.value.totals?.occupancy != null ? `${data.value.totals.occupancy} %` : '';
  const s = buses.value.reduce((a, b) => a + Number(get(b, c.dataIndex) || 0), 0);
  return c.money ? plain(s) : s.toLocaleString('en-US');
}

// ---- one bus
const busOpen = ref(false);
const busLoading = ref(false);
const bus = ref(null);
async function openBus(r) {
  busOpen.value = true;
  busLoading.value = true;
  bus.value = null;
  try { bus.value = await http.get('transport/production', { ...period(), vehicle_id: r.vehicle_id }); } finally { busLoading.value = false; }
}
const leg = (key, title) => ({
  title, children: [
    { title: tr('Date / time', 'Fecha / hora'), key: key + '_at', dataIndex: [key, 'at'], width: 108 },
    { title: 'Pax', key: key + 'pax', dataIndex: [key, 'pax'], align: 'right', width: 40, num: true },
    { title: tr('Tickets', 'Pasajes'), key: key + 'tickets', dataIndex: [key, 'tickets'], align: 'right', width: 74, money: true },
    { title: tr('Parcels', 'Encom.'), key: key + 'parcels', dataIndex: [key, 'parcels'], align: 'right', width: 66, money: true },
    { title: tr('Excess', 'Exceso'), key: key + 'excess', dataIndex: [key, 'excess'], align: 'right', width: 58, money: true },
    { title: tr('Income', 'Ingreso'), key: key + 'total', dataIndex: [key, 'total'], align: 'right', width: 80, money: true },
  ],
});
const tripColumns = computed(() => [
  leg('out', tr('Departure from Lima', 'Salida de Lima')),
  leg('back', tr('Return from Chiclayo', 'Retorno de Chiclayo')),
  { title: tr('Round trip income', 'Ingreso vuelta'), key: 'income', dataIndex: 'income', align: 'right', width: 88, money: true },
  { title: tr('Fuel', 'Combustible'), key: 'fuel', dataIndex: 'fuel', align: 'right', width: 86, money: true },
  { title: tr('Tolls', 'Peaje'), key: 'toll', dataIndex: 'toll', align: 'right', width: 70, money: true },
  { title: tr('Income - fuel - tolls', 'Ingr. - comb. - peaje'), key: 'net', dataIndex: 'net', align: 'right', width: 90, money: true },
]);
const tripLeaf = computed(() => tripColumns.value.flatMap(c => c.children || [c]));
function tripTotal(c) {
  if (String(c.key).endsWith('_at')) return '';
  const s = (bus.value?.rows || []).reduce((a, r) => a + Number(get(r, c.dataIndex) || 0), 0);
  return c.money ? plain(s) : s.toLocaleString('en-US');
}
</script>

<style scoped>
.pb-box { border: 1px solid rgba(128, 128, 128, 0.2); border-radius: 8px; overflow: hidden; }
.pb-table :deep(.ant-table-thead > tr > th) { padding: 3px 5px !important; font-size: 11.5px; line-height: 1.25; text-align: center; }
.pb-table :deep(.ant-table-tbody > tr > td), .pb-table :deep(.ant-table-summary td) { padding: 2px 5px !important; font-size: 12.5px; white-space: nowrap; font-variant-numeric: tabular-nums; }
.pb-table :deep(tr.pb-tint > td) { background: rgba(42, 120, 214, 0.07); }
.pb-table :deep(.pb-total td) { background: rgba(42, 120, 214, 0.14) !important; }
.pb-pos { color: #15803d; }
.pb-cat { font-size: 10.5px; opacity: 0.6; margin-left: 4px; }
.pb-neg { color: #d03b3b; }
.pb-warn { color: #d97706; font-weight: 700; cursor: help; }
.pb-note { font-size: 11.5px; opacity: 0.7; margin-top: 6px; }
</style>
