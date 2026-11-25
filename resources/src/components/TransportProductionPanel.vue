<template>
  <!-- "Producción por bus" (2026-11-23): Mayte's report as a board -- per bus, Lima and Chiclayo, income, fuel, toll and
       what is left; a bus opens its round trips (App\Services\TransportProduction). -->
  <div>
    <a-card v-if="data.enabled" :bordered="false" :body-style="{ padding: '8px' }">
      <a-spin :spinning="loading">
        <TransportStrip :items="tiles" :toggle="false" />
        <!-- By service type (2026-11-24, user: "confort o premium u otro nos da una diferencia"): per departure, best first. -->
        <div v-if="services.length > 1" class="pb-svc">
          <div v-for="s in services" :key="s.service" class="pb-svc-c">
            <div class="pb-svc-t">{{ s.service }} <span>{{ s.buses }} {{ s.buses === 1 ? 'bus' : 'buses' }} · {{ s.trips }} {{ tr('dep.', 'sal.') }}</span></div>
            <div class="pb-svc-v">
              <span><b>{{ s.occupancy != null ? s.occupancy + ' %' : '—' }}</b> {{ tr('occ.', 'ocup.') }}</span>
              <a-tooltip :title="tr('Income per departure', 'Ingreso por salida')"><span><b>{{ money(s.income_trip) }}</b> /{{ tr('dep.', 'sal.') }}</span></a-tooltip>
              <a-tooltip :title="tr('Income - fuel - tolls per departure', 'Ingresos - combustible - peaje por salida')"><span :class="s.net_trip < 0 ? 'pb-neg' : 'pb-pos'"><b>{{ money(s.net_trip) }}</b> {{ tr('net', 'neto') }}</span></a-tooltip>
              <a-tooltip :title="tr('Average ticket', 'Pasaje promedio')"><span>{{ s.ticket_avg != null ? money(s.ticket_avg) : '—' }} {{ tr('ticket', 'pasaje') }}</span></a-tooltip>
            </div>
          </div>
        </div>
        <!-- Her own file, in her own layout (2026-11-24): the summary and one sheet per bus. -->
        <div class="pb-tools">
          <span class="pb-hint">{{ tr('Click a bus to see its round trips and type the fuel.', 'Clic en un bus para ver sus vueltas y escribir el combustible.') }}</span>
          <a-button size="small" :loading="exporting === 'xlsx'" @click="exportFile('xlsx')"><FileExcelOutlined /> Excel</a-button>
          <a-button size="small" :loading="exporting === 'pdf'" @click="exportFile('pdf')"><FilePdfOutlined /> PDF</a-button>
        </div>
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
            <!-- Where the departure's figures come from (2026-11-25): only AuraSoft's production report brings parcels and excess. -->
            <template v-if="column.key === 'out_at' || column.key === 'back_at'">
              <template v-if="record[column.key === 'out_at' ? 'out' : 'back']">
                {{ dt(record[column.key === 'out_at' ? 'out' : 'back'].at) }}
                <a-tooltip :title="srcHelp(record[column.key === 'out_at' ? 'out' : 'back'].source)">
                  <span :class="['pb-src', 'pb-src-' + srcKey(record[column.key === 'out_at' ? 'out' : 'back'].source)]">{{ srcShort(record[column.key === 'out_at' ? 'out' : 'back'].source) }}</span>
                </a-tooltip>
              </template>
              <template v-else>—</template>
            </template>
            <template v-else-if="column.key === 'net'"><b :class="record.net < 0 ? 'pb-neg' : 'pb-pos'">{{ plain(record.net) }}</b></template>
            <!-- The round trip's fuel, typed by whoever runs the departures (2026-11-24: the one figure AuraSoft lacks). -->
            <template v-else-if="column.key === 'fuel'">
              <a-input-number v-if="canFuel" :value="record.fuel || null" size="small" :min="0" :step="10" :precision="2" :controls="false"
                :class="['pb-fuel', { 'pb-fuel-miss': record.fuel_missing }]" :placeholder="tr('fuel', 'comb.')"
                @blur="e => saveFuel(record, e.target.value)" @press-enter="e => e.target.blur()" />
              <template v-else>{{ plain(record.fuel) }}<span v-if="record.fuel_missing" class="pb-warn"> *</span></template>
            </template>
            <template v-else-if="column.key === 'toll'">
              <a-tooltip v-if="record.toll_est" :title="tr('No toll recorded: the route\'s toll.', 'Sin peaje registrado: el peaje de la ruta.')"><span class="pb-est">{{ plain(record.toll) }}</span></a-tooltip>
              <template v-else>{{ plain(record.toll) }}</template>
            </template>
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
        <div class="pb-note">{{ canFuel
          ? tr('Type the round trip\'s fuel and press Enter; it is shared half and half between the departure from Lima and the return. Orange = fuel missing; grey toll = the route\'s.', 'Escriba el combustible de la vuelta y presione Enter; se reparte mitad y mitad entre la salida de Lima y el retorno. Naranja = falta combustible; peaje en gris = el de la ruta.')
          : tr('* fuel not recorded for one of the trips. Fuel and toll of a round trip are shared half and half between the departure from Lima and the return.', '* combustible no registrado en uno de los viajes. El combustible y el peaje de una vuelta se reparten mitad y mitad entre la salida de Lima y el retorno.') }}</div>
      </a-spin>
    </a-modal>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import dayjs from 'dayjs';
import { message } from 'ant-design-vue';
import { FileExcelOutlined, FilePdfOutlined } from '@ant-design/icons-vue';
import http from '../lib/http';
import { useTransportCharts } from '../lib/transportCharts';
import { useAuthStore } from '../stores/auth';
import { getActingCompanyId } from '../lib/actingCompany';
import TransportStrip from './TransportStrip.vue';

const props = defineProps({ range: { type: Array, default: null } });
const { money, tr } = useTransportCharts();
const auth = useAuthStore();
const canFuel = computed(() => auth.can('transport_departures_manage'));

// Excel / PDF of the period (2026-11-24): her "PRODUCCION POR BUS" file.
const exporting = ref('');
async function exportFile(format) {
  exporting.value = format;
  try {
    const p = period();
    const cid = getActingCompanyId();
    const q = new URLSearchParams({ kind: 'production', format, from: p.from, to: p.to, ...(cid ? { company_id: cid } : {}) });
    const stamp = `${dayjs(p.from).format('DD-MM-YYYY')}_al_${dayjs(p.to).format('DD-MM-YYYY')}`;
    await http.download(`transport/production/export?${q}`, `PRODUCCION POR BUS ${stamp}.${format}`);
  } catch (e) { message.error(tr('The file could not be made.', 'No se pudo generar el archivo.')); } finally { exporting.value = ''; }
}

// A round trip's fuel: saved when the field is left (Enter blurs it), then the bus and the board reload.
async function saveFuel(row, raw) {
  const value = raw === '' || raw == null ? null : Number(String(raw).replace(/,/g, ''));
  if ((value ?? 0) === (Number(row.fuel) || 0) && !(value === null && row.fuel)) return;
  const ids = [row.out?.id, row.back?.id].filter(Boolean);
  try {
    await http.post('transport/production/fuel', { trip_ids: ids, amount: value });
    message.success(tr('Fuel saved', 'Combustible guardado'), 1.5);
    const keep = bus.value?.vehicle_id;
    load();
    if (keep) bus.value = await http.get('transport/production', { ...period(), vehicle_id: keep });
  } catch (e) { message.error(e?.data?.message || tr('It could not be saved.', 'No se pudo guardar.')); }
}

const loading = ref(false);
const data = ref({ enabled: false, buses: [], totals: {} });
const buses = computed(() => data.value.buses || []);
const services = computed(() => data.value.by_service || []);
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
const srcKey = s => (s === 'aurasoft' ? 'a' : s === 'report' ? 'r' : 'm');
const srcShort = s => ({ a: 'AS', r: 'RD', m: 'M' })[srcKey(s)];
const srcHelp = s => ({
  a: tr('From AuraSoft\'s "Production per bus" report: passengers, tickets, parcels and excess.', 'Del «Reporte producción por bus» de AuraSoft: pasajeros, pasajes, encomiendas y exceso.'),
  r: tr('From the client\'s daily report: passengers and amount only, no parcels or excess. Upload AuraSoft\'s production report for these dates to complete it.', 'Del reporte del día del cliente: solo pasajeros y monto, sin encomiendas ni exceso. Suba el «Reporte producción por bus» de AuraSoft de esas fechas para completarlo.'),
  m: tr('Registered in Murano (POS / daily departures).', 'Registrada en Murano (POS / salidas del día).'),
})[srcKey(s)];
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
.pb-src { display: inline-block; margin-left: 4px; padding: 0 4px; border-radius: 4px; font-size: 10px; line-height: 15px; font-weight: 600; cursor: help; }
.pb-src-a { background: #e6f4ff; color: #0958d9; }
.pb-src-r { background: #fff7e6; color: #d46b08; }
.pb-src-m { background: #f0f0f0; color: #595959; }
.pb-warn { color: #d97706; font-weight: 700; cursor: help; }
.pb-note { font-size: 11.5px; opacity: 0.7; margin-top: 6px; }
.pb-svc { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 6px; margin: 6px 0; }
.pb-svc-c { border: 1px solid rgba(128, 128, 128, 0.2); border-radius: 6px; padding: 4px 8px; min-width: 0; }
.pb-svc-t { font-weight: 600; font-size: 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pb-svc-t span { font-weight: 400; opacity: 0.6; font-size: 11px; margin-left: 4px; }
.pb-svc-v { display: flex; flex-wrap: wrap; gap: 0 10px; font-size: 11.5px; font-variant-numeric: tabular-nums; }
.pb-tools { display: flex; align-items: center; justify-content: flex-end; gap: 6px; margin: 6px 0; }
.pb-hint { font-size: 11.5px; opacity: 0.65; margin-right: auto; }
.pb-est { opacity: 0.5; cursor: help; }
.pb-fuel { width: 92px; }
.pb-fuel :deep(input) { text-align: right; padding: 0 6px; height: 22px; font-variant-numeric: tabular-nums; }
.pb-fuel-miss { border-color: #d97706; background: rgba(217, 119, 6, 0.08); }
</style>
