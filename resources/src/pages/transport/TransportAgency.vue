<template>
  <div class="page">
    <PageHeader :title="L('My agency', 'Mi agencia') + (agencyNames ? ' · ' + agencyNames : '')" :breadcrumb="[L('Transport', 'Transporte'), L('My agency', 'Mi agencia')]" />

    <a-card size="small" :body-style="{ padding: '12px' }">
      <div class="ag-bar">
        <PresetRangeFilter v-model:value="range" value-format="YYYY-MM-DD" @change="reload" />
        <div class="ag-grow" />
        <a-button v-if="tab === 'incidents'" type="primary" @click="openNew"><PlusOutlined /> {{ L('New incident', 'Nueva incidencia') }}</a-button>
      </div>

      <a-tabs v-model:active-key="tab" size="middle">
        <!-- Summary: today and the period, no profit or costs -->
        <a-tab-pane key="summary">
          <template #tab><DashboardOutlined /> {{ L('Summary', 'Resumen') }}</template>
          <a-spin :spinning="busy === 'summary'">
            <template v-if="sum">
              <div class="ag-sub">{{ L('Today', 'Hoy') }}</div>
              <div class="ag-kpis">
                <div class="ag-kpi"><b>{{ sum.today.departures }}</b><span>{{ L('Departures', 'Salidas') }}</span></div>
                <div class="ag-kpi"><b>{{ sum.today.passengers }}<small v-if="sum.today.seats"> / {{ sum.today.seats }}</small></b><span>{{ L('Passengers', 'Pasajeros') }}</span></div>
                <div class="ag-kpi"><b>{{ sum.today.occupancy ?? '—' }}<small v-if="sum.today.occupancy !== null"> %</small></b><span>{{ L('Occupancy', 'Ocupación') }}</span></div>
                <div class="ag-kpi"><b>{{ money(sum.today.tickets_amount) }}</b><span>{{ L('Tickets', 'Pasajes') }}</span></div>
                <div class="ag-kpi"><b>{{ sum.today.parcels }}</b><span>{{ L('Parcels received', 'Encomiendas recibidas') }} · {{ money(sum.today.parcels_amount) }}</span></div>
                <div class="ag-kpi" :class="{ 'ag-warn': sum.pending_parcels }"><b>{{ sum.pending_parcels }}</b><span>{{ L('Parcels pending', 'Encomiendas pendientes') }}</span></div>
                <div class="ag-kpi" :class="{ 'ag-warn': sum.today.incidents }"><b>{{ sum.today.incidents }}</b><span>{{ L('Incidents', 'Incidencias') }}</span></div>
              </div>
              <div class="ag-sub">{{ L('Period', 'Periodo') }} · {{ shortDate(sum.from) }} – {{ shortDate(sum.to) }}</div>
              <div class="ag-kpis">
                <div class="ag-kpi"><b>{{ sum.period.departures }}</b><span>{{ L('Departures', 'Salidas') }}</span></div>
                <div class="ag-kpi"><b>{{ sum.period.passengers }}</b><span>{{ L('Passengers', 'Pasajeros') }}</span></div>
                <div class="ag-kpi"><b>{{ sum.period.occupancy ?? '—' }}<small v-if="sum.period.occupancy !== null"> %</small></b><span>{{ L('Occupancy', 'Ocupación') }}</span></div>
                <div class="ag-kpi"><b>{{ money(sum.period.tickets_amount) }}</b><span>{{ L('Tickets', 'Pasajes') }}</span></div>
                <div class="ag-kpi"><b>{{ sum.period.parcels }}</b><span>{{ L('Parcels', 'Encomiendas') }} · {{ money(sum.period.parcels_amount) }}</span></div>
                <div class="ag-kpi"><b>{{ sum.period.claims ?? '—' }}</b><span>{{ L('Complaints', 'Reclamos') }}</span></div>
                <div class="ag-kpi"><b>{{ sum.period.incidents }}</b><span>{{ L('Incidents', 'Incidencias') }}</span></div>
              </div>
              <div class="ag-sub">
                {{ L('Seats used per trip, by unit · last 14 days', 'Asientos ocupados por viaje, por unidad · últimos 14 días') }}
                <a-tooltip :title="L('Each bus leaving this agency: passengers who boarded per trip (average, boarding pass read). Minimum required = the seats a trip needs to cover its costs. Green: 10 % or more above it; orange: just above it; red: below it.', 'Cada bus que sale de esta agencia: pasajeros que abordaron por viaje (promedio, pase leído). Mínimo requerido = los asientos que un viaje necesita para cubrir sus costos. Verde: 10 % o más por encima; naranja: apenas por encima; rojo: por debajo.')"><InfoCircleOutlined class="ag-info" /></a-tooltip>
              </div>
              <apexchart v-if="sum.units?.length" type="line" height="250" :options="unitOptions" :series="unitSeries" />
              <div v-if="sum.units?.length" class="ag-levels"><b>{{ L('Bars:', 'Barras:') }}</b><span><i style="background:#16a34a" />{{ L('Good', 'Bien') }}</span><span><i style="background:#f59e0b" />{{ L('Just enough', 'Justo') }}</span><span><i style="background:#dc2626" />{{ L('Below the minimum', 'Por debajo del mínimo') }}</span></div><div v-if="sum.units?.length" class="ag-levels"><b>{{ L('Lines:', 'Líneas:') }}</b><span><i class="ag-line red" />{{ L('Minimum required (break-even)', 'Mínimo requerido (cubre costos)') }}</span><span><i class="ag-line grey" />{{ L('Bus seats', 'Asientos del bus') }}</span></div>
              <a-empty v-else :description="L('No trips in the last 14 days.', 'Sin viajes en los últimos 14 días.')" />
            </template>
          </a-spin>
        </a-tab-pane>

        <a-tab-pane key="parcels">
          <template #tab><InboxOutlined /> {{ L('Parcels', 'Encomiendas') }}</template>
          <TransportParcelsPanel v-if="seen.parcels" :key="'p' + stamp" :range="range" />
        </a-tab-pane>

        <a-tab-pane key="claims">
          <template #tab><MessageOutlined /> {{ L('Complaints', 'Reclamos') }}</template>
          <TransportClaimsPanel v-if="seen.claims" :key="'c' + stamp" :range="range" endpoint="transport/agency/claims" />
        </a-tab-pane>

        <!-- Incidents: the crew's from My trip and the supervisor's own -->
        <a-tab-pane key="incidents">
          <template #tab><WarningOutlined /> {{ L('Incidents', 'Incidencias') }}</template>
          <a-table :columns="incColumns" :data-source="incidents" row-key="id" size="small" :loading="busy === 'incidents'"
            :pagination="{ pageSize: 25, hideOnSinglePage: true }" :scroll="{ x: 'max-content' }">
            <template #bodyCell="{ column, record: r }">
              <template v-if="column.key === 'at'">{{ stampOf(r.at) }}</template>
              <template v-else-if="column.key === 'kind'"><a-tag :color="KIND[r.kind]?.color || 'default'">{{ kindLabel(r.kind) }}</a-tag></template>
              <template v-else-if="column.key === 'trip'">
                <div>{{ r.route }}</div>
                <div class="ag-muted">{{ stampOf(r.departure_at) }}<template v-if="r.bus"> · {{ r.bus }}</template></div>
              </template>
              <template v-else-if="column.key === 'note'"><span class="ag-note">{{ r.note || '—' }}</span></template>
              <template v-else-if="column.key === 'photo'">
                <a-button v-if="r.has_photo" size="small" @click="showPhoto(r)"><PictureOutlined /></a-button>
              </template>
            </template>
          </a-table>
        </a-tab-pane>
      </a-tabs>
    </a-card>

    <!-- New incident -->
    <a-modal v-model:open="newOpen" :title="L('New incident', 'Nueva incidencia')" :ok-text="L('Save', 'Guardar')" :cancel-text="L('Cancel', 'Cancelar')"
      :confirm-loading="busy === 'save'" :ok-button-props="{ disabled: !form.trip || !form.kind }" @ok="saveIncident">
      <a-form layout="vertical">
        <a-form-item :label="L('Trip *', 'Viaje *')">
          <a-select v-model:value="form.trip" :options="tripOptions" show-search option-filter-prop="label" :not-found-content="L('No trip on the road or within a day', 'No hay viajes en ruta ni dentro de un día')" />
        </a-form-item>
        <a-form-item :label="L('What happened *', 'Qué pasó *')"><a-select v-model:value="form.kind" :options="kindOptions" /></a-form-item>
        <a-form-item :label="L('Detail', 'Detalle')"><a-textarea v-model:value="form.note" :rows="3" :maxlength="1000" /></a-form-item>
        <a-form-item :label="L('Photo', 'Foto')">
          <input type="file" accept="image/*" capture="environment" @change="pickPhoto" />
        </a-form-item>
      </a-form>
    </a-modal>

    <a-modal v-model:open="photoOpen" :footer="null" :width="720" :title="L('Photo', 'Foto')">
      <img v-if="photoUrl" :src="photoUrl" alt="" style="width: 100%; border-radius: 6px" />
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Transport -> My agency (Turismo Cautivo, user's request 2026-11-11): the agency supervisor's summary (today and the
 * period, no profit or costs), its parcels, its complaints and its incidents (see / create). Only the agencies of the
 * user's locations. TransportAgencyController; the parcels tab reads transport/parcels/board.
 */
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { message } from 'ant-design-vue';
import { DashboardOutlined, InboxOutlined, InfoCircleOutlined, MessageOutlined, PictureOutlined, PlusOutlined, WarningOutlined } from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import PageHeader from '../../components/PageHeader.vue';
import PresetRangeFilter from '../../components/PresetRangeFilter.vue';
import { presetDates } from '../../lib/datePresets';
import TransportParcelsPanel from '../../components/TransportParcelsPanel.vue';
import TransportClaimsPanel from '../../components/TransportClaimsPanel.vue';
import http from '../../lib/http';
import { useUiStore } from '../../stores/ui';
import { sortByLabel } from '../../lib/filterOptions';
import { getActingCompanyId } from '../../lib/actingCompany';
import { useFormat } from '../../composables/useFormat';

const ui = useUiStore();
const L = (en, es) => (String(ui.locale || 'en').startsWith('es') ? es : en);
const money = v => (v === null || v === undefined ? '—' : 'S/ ' + Number(v).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
// Dates in the company's format (user's rule, 2026-11-11).
const { date: shortDate, dayMonthTime: stampOf, dayMonth } = useFormat();
const errorOf = e => { const errs = e?.data?.errors; return (errs && Object.values(errs)[0]?.[0]) || e?.data?.message || e.message; };

// The standard date filter (preset buttons), on This month by default.
const month = presetDates('month');
const range = ref([month.from, month.to]);
const tab = ref('summary');
const busy = ref('');
const stamp = ref(0); // re-mounts the parcel / complaint panels when the period changes
const seen = reactive({ parcels: false, claims: false });
const sum = ref(null);
const agencyNames = computed(() => (sum.value?.agencies || []).join(', '));

const KIND = {
  traffic: { color: 'gold', en: 'Traffic', es: 'Tráfico' },
  breakdown: { color: 'red', en: 'Breakdown', es: 'Avería' },
  accident: { color: 'red', en: 'Accident', es: 'Accidente' },
  road: { color: 'orange', en: 'Road closed or blocked', es: 'Vía cerrada o bloqueada' },
  passenger: { color: 'blue', en: 'Passenger', es: 'Pasajero' },
  other: { color: 'default', en: 'Other', es: 'Otro' },
};
const kindLabel = k => (KIND[k] ? L(KIND[k].en, KIND[k].es) : k);
const kindOptions = computed(() => sortByLabel(Object.keys(KIND).map(k => ({ value: k, label: kindLabel(k) }))));

// Per unit (user's requests, 2026-11-11): the seats used per trip (boarded, average) of each bus against the minimum it
// needs to cover its costs (the owner's break-even): green from 110 % of it, orange from 100 %, red below.
const LEVEL_COLOR = { ok: '#16a34a', warn: '#f59e0b', bad: '#dc2626' };
const units = computed(() => sum.value?.units || []);
const unitSeries = computed(() => [
  { name: L('Seats used per trip', 'Asientos ocupados por viaje'), type: 'column',
    data: units.value.map(u => ({ x: u.bus, y: u.used, fillColor: LEVEL_COLOR[u.level] || '#94a3b8' })) },
  { name: L('Minimum required', 'Mínimo requerido'), type: 'line', data: units.value.map(u => ({ x: u.bus, y: u.needed })) },
  { name: L('Seats', 'Asientos'), type: 'line', data: units.value.map(u => ({ x: u.bus, y: u.seats })) },
]);
const unitOptions = computed(() => ({
  chart: { toolbar: { show: false }, fontFamily: 'inherit' },
  colors: ['#16a34a', '#dc2626', '#94a3b8'],
  stroke: { width: [0, 2, 2], dashArray: [0, 5, 3], curve: 'straight' },
  markers: { size: [0, 6, 0], strokeWidth: 0 },
  plotOptions: { bar: { borderRadius: 3, columnWidth: '60%', dataLabels: { position: 'top' } } },
  dataLabels: { enabled: true, enabledOnSeries: [0], style: { fontSize: '12px', fontWeight: 600, colors: ['#334155'] }, background: { enabled: false }, offsetY: -20, formatter: v => Math.round(v) },
  yaxis: { min: 0, forceNiceScale: true, decimalsInFloat: 0, labels: { formatter: v => Math.round(v) },
    title: { text: L('Seats', 'Asientos'), style: { fontWeight: 400, color: '#64748b' } } },
  tooltip: { shared: true, intersect: false, y: { formatter: (v, { dataPointIndex: i, seriesIndex: s }) => {
    if (v === null || v === undefined) return '—';
    const u = units.value[i];
    return s === 0 && u ? `${Math.round(v)} · ${L('lowest', 'el menor')} ${u.lowest} · ${u.trips} ${L('trips', 'viajes')}` : Math.round(v);
  } } },
  legend: { show: false },
  grid: { borderColor: '#eef2f7' },
}));
async function loadSummary() {
  busy.value = 'summary';
  try {
    sum.value = await http.get('transport/agency/summary', { from: range.value?.[0], to: range.value?.[1] });
  } catch (e) {
    message.error(errorOf(e));
  } finally {
    busy.value = '';
  }
}

// ---------------- incidents
const incidents = ref([]);
const incColumns = computed(() => [
  { title: L('When', 'Cuándo'), key: 'at', width: 110 },
  { title: L('What', 'Qué'), key: 'kind', width: 150 },
  { title: L('Trip', 'Viaje'), key: 'trip' },
  { title: L('Detail', 'Detalle'), key: 'note' },
  { title: L('Reported by', 'Reportó'), dataIndex: 'by', key: 'by' },
  { title: '', key: 'photo', width: 50 },
]);
async function loadIncidents() {
  busy.value = 'incidents';
  try {
    incidents.value = (await http.get('transport/agency/incidents', { from: range.value?.[0], to: range.value?.[1] })).rows || [];
  } catch (e) {
    message.error(errorOf(e));
  } finally {
    busy.value = '';
  }
}
const newOpen = ref(false);
const form = ref({ trip: undefined, kind: undefined, note: '', photo: null });
const trips = ref([]);
const tripOptions = computed(() => trips.value.map(t => ({ value: t.id, label: t.label }))); // by departure time
async function openNew() {
  form.value = { trip: undefined, kind: undefined, note: '', photo: null };
  newOpen.value = true;
  try {
    trips.value = (await http.get('transport/agency/incident-trips')).trips || [];
  } catch (e) {
    message.error(errorOf(e));
  }
}
function pickPhoto(e) {
  form.value.photo = e.target.files?.[0] || null;
}
async function saveIncident() {
  busy.value = 'save';
  try {
    const fd = new FormData();
    fd.append('kind', form.value.kind);
    if (form.value.note) fd.append('note', form.value.note);
    if (form.value.photo) fd.append('photo', form.value.photo, form.value.photo.name || 'photo.jpg');
    await http.postForm(`transport/control/trips/${form.value.trip}/report`, fd);
    message.success(L('Incident saved.', 'Incidencia guardada.'));
    newOpen.value = false;
    loadIncidents();
    loadSummary();
  } catch (e) {
    message.error(errorOf(e));
  } finally {
    busy.value = '';
  }
}
const photoOpen = ref(false);
const photoUrl = ref('');
async function showPhoto(r) {
  try {
    const cid = getActingCompanyId();
    const b = await http.blob(`transport/control/photo/report/${r.id}${cid ? `?company_id=${cid}` : ''}`);
    if (photoUrl.value) URL.revokeObjectURL(photoUrl.value);
    photoUrl.value = URL.createObjectURL(b);
    photoOpen.value = true;
  } catch (e) {
    message.error(L('The photo could not be opened.', 'No se pudo abrir la foto.'));
  }
}

// A tab loads the first time it opens; the period reloads what is open.
watch(tab, t => {
  if (t === 'parcels' || t === 'claims') seen[t] = true;
  if (t === 'incidents' && !incidents.value.length) loadIncidents();
});
function reload() {
  stamp.value++;
  loadSummary();
  if (tab.value === 'incidents') loadIncidents();
  else incidents.value = [];
}
onMounted(loadSummary);
</script>

<style scoped>
.ag-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 4px; }
.ag-grow { flex: 1; }
.ag-sub { font-weight: 700; margin: 8px 0 6px; }
.ag-kpis { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 6px; }
.ag-kpi { flex: 1 1 130px; background: #f5f3ff; border-radius: 8px; padding: 8px 10px; display: flex; flex-direction: column; }
.ag-kpi b { font-size: 18px; line-height: 1.2; white-space: nowrap; }
.ag-kpi small { font-size: 12px; color: #64748b; font-weight: 400; }
.ag-kpi span { font-size: 12px; color: #64748b; }
.ag-warn { background: #fff7ed; }
.ag-muted { color: #94a3b8; font-size: 12px; }
.ag-note { display: inline-block; max-width: 420px; white-space: normal; }
.ag-info { color: #94a3b8; margin-left: 4px; cursor: help; }
.ag-levels { display: flex; flex-wrap: wrap; justify-content: center; gap: 14px; font-size: 12px; color: #64748b; margin-top: 2px; }
.ag-line { display: inline-block; width: 26px; height: 0; border-top: 3px dashed; margin-right: 6px; vertical-align: 3px; }
.ag-line.red { border-color: #dc2626; }
.ag-line.grey { border-color: #94a3b8; border-top-style: dotted; }
.ag-levels i { display: inline-block; width: 10px; height: 10px; border-radius: 2px; margin-right: 5px; vertical-align: -1px; }
</style>
