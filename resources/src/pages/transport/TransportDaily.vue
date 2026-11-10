<template>
  <div class="page">
    <PageHeader :title="L('Daily departures', 'Salidas diarias')" :breadcrumb="[L('Transport', 'Transporte'), L('Daily departures', 'Salidas diarias')]" />

    <a-card size="small" :body-style="{ padding: '12px' }">
      <!-- Day, agency and the day's actions -->
      <div class="dd-bar">
        <a-date-picker v-model:value="date" value-format="YYYY-MM-DD" :allow-clear="false" @change="load" />
        <a-select v-if="agencyOptions.length > 1" v-model:value="agencyId" :options="agencyOptions" style="width: 190px" @change="load" />
        <a-tag v-if="closed" color="green" class="dd-state"><LockOutlined /> {{ L('Closed', 'Cerrado') }} · {{ data.closure.closed_by }} · {{ stamp(data.closure.closed_at) }}</a-tag>
        <a-tag v-else-if="data.closure?.status === 'open'" color="orange" class="dd-state">
          {{ L('Reopened', 'Reabierto') }} · {{ data.closure.reopened_by }}<template v-if="data.closure.reopen_note">: {{ data.closure.reopen_note }}</template>
        </a-tag>
        <div class="dd-grow" />
        <template v-if="data.can_manage && !closed">
          <a-button v-if="data.copy_from" :loading="busy === 'copy'" @click="copyDay">
            <CopyOutlined /> {{ L('Copy from', 'Copiar del') }} {{ shortDate(data.copy_from) }}
          </a-button>
          <a-button @click="openTrip()"><PlusOutlined /> {{ L('Add departure', 'Agregar salida') }}</a-button>
          <a-button type="primary" :disabled="!data.rows?.length || date > today" @click="askClose"><LockOutlined /> {{ L('Close day', 'Cerrar día') }}</a-button>
        </template>
        <a-button v-if="closed && data.closure.has_pdf" @click="openPdf(data.closure.id)"><FilePdfOutlined /> PDF</a-button>
        <a-button v-if="closed && data.can_reopen" @click="reopenOpen = true"><UnlockOutlined /> {{ L('Reopen', 'Reabrir') }}</a-button>
        <a-button @click="openHistory"><HistoryOutlined /> {{ L('History', 'Historial') }}</a-button>
        <a-button v-if="data.can_manage" @click="emailsOpen = true"><MailOutlined /></a-button>
      </div>

      <a-spin :spinning="loading">
        <!-- Key figures of the day -->
        <div v-if="data.rows?.length" class="dd-kpis">
          <div class="dd-kpi"><b>{{ t.departures }}</b><span>{{ L('Departures', 'Salidas') }}</span></div>
          <div class="dd-kpi"><b>{{ t.passengers }}<small v-if="t.seats"> / {{ t.seats }}</small></b><span>{{ L('Passengers', 'Pasajeros') }}</span></div>
          <div class="dd-kpi"><b>{{ t.occupancy ?? '—' }}<small v-if="t.occupancy !== null"> %</small></b><span>{{ L('Occupancy', 'Ocupación') }}</span></div>
          <div class="dd-kpi"><b>{{ money(t.amount) }}</b><span>{{ L('Amount', 'Monto') }}</span></div>
          <div class="dd-kpi"><b>{{ money(t.tolls) }}</b><span>{{ L('Tolls', 'Peajes') }}<a-tag v-if="t.missing_toll" color="orange" class="dd-miss">{{ t.missing_toll }} {{ L('missing', 'sin dato') }}</a-tag></span></div>
          <div class="dd-kpi"><b>{{ money(t.fuel) }}</b><span>{{ L('Fuel', 'Combustible') }}<a-tag v-if="t.missing_fuel" color="orange" class="dd-miss">{{ t.missing_fuel }} {{ L('missing', 'sin dato') }}</a-tag></span></div>
          <div class="dd-kpi dd-net"><b>{{ money(t.net) }}</b><span>{{ L('Amount - tolls - fuel', 'Monto - peajes - comb.') }}</span></div>
          <div v-if="data.can_manage || price.high || price.low" class="dd-kpi dd-price">
            <span>{{ L('Price of the day', 'Precio del día') }}</span>
            <div class="dd-price-in">
              <a-input-number v-model:value="price.high" :min="0" size="small" :disabled="closed || !data.can_manage" :placeholder="L('High', 'Alto')" @blur="savePrice" />
              <a-input-number v-model:value="price.low" :min="0" size="small" :disabled="closed || !data.can_manage" :placeholder="L('Low', 'Bajo')" @blur="savePrice" />
            </div>
          </div>
        </div>

        <a-empty v-if="!loading && !data.rows?.length" :description="emptyText">
          <a-space v-if="data.can_manage && !closed">
            <a-button v-if="data.copy_from" type="primary" :loading="busy === 'copy'" @click="copyDay">{{ L('Copy the departures of', 'Copiar las salidas del') }} {{ shortDate(data.copy_from) }}</a-button>
            <a-button @click="openTrip()">{{ L('Add departure', 'Agregar salida') }}</a-button>
          </a-space>
        </a-empty>

        <a-table v-else :columns="columns" :data-source="data.rows" row-key="id" size="small" :pagination="false" :scroll="{ x: 'max-content' }"
          :row-class-name="r => (r.status === 'cancelled' ? 'dd-cancel' : '')">
          <template #bodyCell="{ column, record: r }">
            <template v-if="column.key === 'time'"><b class="dd-time">{{ r.time }}</b></template>
            <template v-else-if="column.key === 'bus'">{{ r.bus || '—' }}</template>
            <template v-else-if="column.key === 'points'">
              <a-tag v-for="(p, i) in r.points" :key="i" class="dd-pt"><a-tooltip :title="p.name">{{ p.label || p.name }}</a-tooltip> {{ p.time }}</a-tag>
              <span v-if="!r.points.length" class="dd-muted">{{ L('Origin only', 'Solo origen') }}</span>
            </template>
            <template v-else-if="column.key === 'passengers'">
              <a-tooltip :title="r.reported_passengers !== null ? L(`Typed. POS: ${r.pos_passengers}`, `Escrito. POS: ${r.pos_passengers}`) : L('From the POS tickets', 'De los pasajes del POS')">
                <b>{{ r.passengers }}</b><span v-if="r.seats" class="dd-muted"> / {{ r.seats }}</span>
                <EditOutlined v-if="r.reported_passengers !== null" class="dd-typed" />
              </a-tooltip>
              <div v-if="r.occupancy !== null" class="dd-bar-occ"><i :style="{ width: Math.min(100, r.occupancy) + '%' }" :class="{ low: r.occupancy < 50 }" /></div>
            </template>
            <template v-else-if="column.key === 'amount'">
              <span :class="{ 'dd-low': r.status !== 'cancelled' && r.amount < LOW }">{{ money(r.amount) }}</span>
              <EditOutlined v-if="r.reported_income !== null" class="dd-typed" />
            </template>
            <template v-else-if="column.key === 'toll'"><span :class="{ 'dd-muted': r.toll === null }">{{ r.toll === null ? '—' : money(r.toll) }}</span></template>
            <template v-else-if="column.key === 'fuel'"><span :class="{ 'dd-muted': r.fuel === null }">{{ r.fuel === null ? '—' : money(r.fuel) }}</span></template>
            <template v-else-if="column.key === 'status'"><a-tag :color="STATE[stateOf(r)].color">{{ L(STATE[stateOf(r)].en, STATE[stateOf(r)].es) }}</a-tag></template>
            <template v-else-if="column.key === 'actions'">
              <a-space v-if="data.can_manage && !closed" :size="4">
                <a-button size="small" @click="openTrip(r)"><EditOutlined /></a-button>
                <a-popconfirm :title="L('Remove this departure?', '¿Quitar esta salida?')" :ok-text="L('Remove', 'Quitar')" :cancel-text="L('Back', 'Volver')" @confirm="removeTrip(r)">
                  <a-button size="small" danger><DeleteOutlined /></a-button>
                </a-popconfirm>
              </a-space>
            </template>
          </template>
        </a-table>
      </a-spin>
    </a-card>

    <!-- A departure -->
    <a-modal v-model:open="tripOpen" :title="form.id ? L('Departure', 'Salida') + ' ' + form.time : L('New departure', 'Nueva salida')" :width="640"
      :confirm-loading="busy === 'save'" :ok-text="L('Save', 'Guardar')" :cancel-text="L('Cancel', 'Cancelar')" @ok="saveTrip">
      <a-form layout="vertical">
        <div class="dd-grid">
          <a-form-item :label="L('Time *', 'Hora *')"><a-input v-model:value="form.time" placeholder="20:30" :maxlength="5" /></a-form-item>
          <a-form-item :label="L('Destination *', 'Destino *')"><a-select v-model:value="form.route_id" :options="routeOptions" show-search option-filter-prop="label" /></a-form-item>
          <a-form-item :label="L('Bus', 'Bus')"><a-select v-model:value="form.vehicle_id" :options="vehicleOptions" allow-clear show-search option-filter-prop="label" /></a-form-item>
        </div>

        <div class="dd-sub">{{ L('Boarding points', 'Puntos de embarque') }}</div>
        <div v-for="(p, i) in form.points" :key="i" class="dd-point">
          <a-select v-model:value="p.location_id" :options="pointOptions" show-search option-filter-prop="label" style="flex: 2" />
          <a-input v-model:value="p.label" :placeholder="L('Code (P.N.)', 'Código (P.N.)')" :maxlength="40" style="flex: 1" />
          <a-input v-model:value="p.time" placeholder="20:30" :maxlength="5" style="width: 80px" />
          <a-button @click="form.points.splice(i, 1)"><DeleteOutlined /></a-button>
        </div>
        <a-button size="small" @click="addPoint"><PlusOutlined /> {{ L('Add point', 'Agregar punto') }}</a-button>

        <div class="dd-sub">{{ L('Figures of the day', 'Cifras del día') }}</div>
        <div class="dd-grid">
          <a-form-item :label="L('Passengers', 'Pasajeros')" :extra="form.id ? L(`POS: ${form.pos_passengers}. Empty = POS.`, `POS: ${form.pos_passengers}. Vacío = POS.`) : L('Empty = POS tickets.', 'Vacío = pasajes del POS.')">
            <a-input-number v-model:value="form.reported_passengers" :min="0" style="width: 100%" />
          </a-form-item>
          <a-form-item :label="L('Amount (S/)', 'Monto (S/)')" :extra="form.id ? L(`POS: ${money(form.pos_amount)}. Empty = POS.`, `POS: ${money(form.pos_amount)}. Vacío = POS.`) : L('Empty = POS tickets.', 'Vacío = pasajes del POS.')">
            <a-input-number v-model:value="form.reported_income" :min="0" :precision="2" style="width: 100%" />
          </a-form-item>
          <a-form-item :label="L('Toll (S/)', 'Peaje (S/)')"><a-input-number v-model:value="form.toll" :min="0" :precision="2" style="width: 100%" /></a-form-item>
          <a-form-item :label="L('Fuel (S/)', 'Combustible (S/)')"><a-input-number v-model:value="form.fuel" :min="0" :precision="2" style="width: 100%" /></a-form-item>
        </div>
      </a-form>
    </a-modal>

    <!-- Close the day -->
    <a-modal v-model:open="closeOpen" :title="L('Close the day', 'Cerrar el día')" :confirm-loading="busy === 'close'" :ok-text="L('Close day', 'Cerrar día')" :cancel-text="L('Back', 'Volver')" @ok="closeDay">
      <p>{{ data.agency }} · {{ shortDate(date) }}: {{ t.departures }} {{ L('departures', 'salidas') }}, {{ t.passengers }} {{ L('passengers', 'pasajeros') }}, {{ money(t.amount) }}.</p>
      <a-alert v-if="t.missing_toll || t.missing_fuel" type="warning" show-icon class="dd-note"
        :message="L(`Without toll: ${t.missing_toll}. Without fuel: ${t.missing_fuel}.`, `Sin peaje: ${t.missing_toll}. Sin combustible: ${t.missing_fuel}.`)" />
      <p class="dd-muted">{{ L('The day is locked, the PDF is made and sent to:', 'El día queda bloqueado, se genera el PDF y se envía a:') }} <b>{{ data.emails || L('nobody yet (envelope button)', 'nadie todavía (botón del sobre)') }}</b></p>
    </a-modal>

    <a-modal v-model:open="reopenOpen" :title="L('Reopen the day', 'Reabrir el día')" :confirm-loading="busy === 'reopen'" :ok-text="L('Reopen', 'Reabrir')" :cancel-text="L('Back', 'Volver')" @ok="reopenDay">
      <a-form-item :label="L('Reason *', 'Motivo *')" style="margin-bottom: 0"><a-textarea v-model:value="reopenNote" :rows="2" :maxlength="500" /></a-form-item>
    </a-modal>

    <a-modal v-model:open="emailsOpen" :title="L('Who gets the daily PDF', 'Quién recibe el PDF diario')" :ok-text="L('Save', 'Guardar')" :cancel-text="L('Cancel', 'Cancelar')" :confirm-loading="busy === 'emails'" @ok="saveEmails">
      <a-textarea v-model:value="emailsText" :rows="3" :placeholder="L('Emails separated by commas', 'Correos separados por comas')" />
    </a-modal>

    <!-- History -->
    <a-drawer v-model:open="historyOpen" :title="L('Closed days', 'Días cerrados')" :width="drawerWidth">
      <PresetRangeFilter v-model:value="historyRange" allow-all compact @change="loadHistory" />
      <a-table :columns="historyColumns" :data-source="history" row-key="id" size="small" :pagination="{ pageSize: 20 }" :loading="busy === 'history'" style="margin-top: 10px"
        :custom-row="h => ({ onClick: () => goTo(h), style: 'cursor: pointer' })">
        <template #bodyCell="{ column, record: h }">
          <template v-if="column.key === 'day'">{{ shortDate(h.day) }}<a-tag v-if="h.status === 'open'" color="orange" class="dd-miss">{{ L('reopened', 'reabierto') }}</a-tag></template>
          <template v-else-if="column.key === 'pax'">{{ h.totals?.passengers }}<span v-if="h.totals?.occupancy !== null" class="dd-muted"> ({{ h.totals?.occupancy }} %)</span></template>
          <template v-else-if="column.key === 'amount'">{{ money(h.totals?.amount) }}</template>
          <template v-else-if="column.key === 'pdf'"><a-button size="small" @click.stop="openPdf(h.id)"><FilePdfOutlined /></a-button></template>
        </template>
      </a-table>
    </a-drawer>
  </div>
</template>

<script setup>
/**
 * Transport -> Daily departures (Turismo Cautivo, 2026-11-10): the agency's day in place of the client's Excel.
 * Plan (copy an earlier day, add / change departures and their boarding points), the POS fills passengers and
 * amount (or they are typed), toll and fuel, then "Close day": locked, PDF, email, history. TransportDailyController.
 */
import { computed, onMounted, reactive, ref } from 'vue';
import { message } from 'ant-design-vue';
import {
  CopyOutlined, DeleteOutlined, EditOutlined, FilePdfOutlined, HistoryOutlined, LockOutlined, MailOutlined, PlusOutlined, UnlockOutlined,
} from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import PageHeader from '../../components/PageHeader.vue';
import PresetRangeFilter from '../../components/PresetRangeFilter.vue';
import http from '../../lib/http';
import { useUiStore } from '../../stores/ui';
import { sortByLabel } from '../../lib/filterOptions';
import { getActingCompanyId } from '../../lib/actingCompany';

const ui = useUiStore();
const L = (en, es) => (String(ui.locale || 'en').startsWith('es') ? es : en);
const LOW = 2000; // TransportDepartures::LOW_AMOUNT
const STATE = {
  pending: { color: 'gold', en: 'To confirm', es: 'Por confirmar' },
  confirmed: { color: 'green', en: 'Confirmed', es: 'Confirmada' },
  in_progress: { color: 'orange', en: 'On the road', es: 'En ruta' },
  completed: { color: 'blue', en: 'Completed', es: 'Completada' },
  cancelled: { color: 'default', en: 'Cancelled', es: 'Cancelada' },
};
const stateOf = r => (r.status === 'scheduled' ? (r.confirmed ? 'confirmed' : 'pending') : (STATE[r.status] ? r.status : 'pending'));
const money = v => (v === null || v === undefined ? '—' : 'S/ ' + Number(v).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
const shortDate = d => (d ? dayjs(d).format(L('MM-DD-YYYY', 'DD-MM-YYYY')) : '');
const stamp = d => (d ? dayjs(d).format(L('MM-DD HH:mm', 'DD-MM HH:mm')) : '');
const errorOf = e => { const errs = e?.data?.errors; return (errs && Object.values(errs)[0]?.[0]) || e?.data?.message || e.message; };
const today = dayjs().format('YYYY-MM-DD');
const drawerWidth = computed(() => Math.min(640, window.innerWidth));

const date = ref(today);
const agencyId = ref(undefined);
const data = ref({});
const loading = ref(false);
const busy = ref('');
const price = reactive({ high: null, low: null });
const t = computed(() => data.value.totals || {});
const closed = computed(() => data.value.closure?.status === 'closed');
const agencyOptions = computed(() => sortByLabel((data.value.agencies || []).map(a => ({ value: a.id, label: a.name }))));
const routeOptions = computed(() => sortByLabel((data.value.routes || []).map(r => ({ value: r.id, label: r.destination }))));
const vehicleOptions = computed(() => sortByLabel((data.value.vehicles || []).map(v => ({ value: v.id, label: v.name + (v.seats ? ` · ${v.seats}` : '') }))));
// The agency and its terminals first, then the rest (each part alphabetical).
const pointOptions = computed(() => {
  const all = data.value.points || [];
  return [
    { label: L('This agency', 'Esta agencia'), options: sortByLabel(all.filter(p => p.own).map(p => ({ value: p.id, label: p.name }))) },
    { label: L('Other places', 'Otros lugares'), options: sortByLabel(all.filter(p => !p.own).map(p => ({ value: p.id, label: p.name }))) },
  ].filter(g => g.options.length);
});
const emptyText = computed(() => (date.value > today ? L('No departures planned for this day yet.', 'Todavía no hay salidas planificadas para este día.') : L('No departures that day.', 'No hay salidas ese día.')));

const columns = computed(() => [
  { title: L('Time', 'Hora'), key: 'time', width: 70 },
  { title: L('Bus', 'Bus'), key: 'bus' },
  { title: L('Destination', 'Destino'), dataIndex: 'destination', key: 'destination' },
  { title: L('Boarding points', 'Puntos de embarque'), key: 'points' },
  { title: L('Passengers', 'Pasajeros'), key: 'passengers', width: 110 },
  { title: L('Amount', 'Monto'), key: 'amount', align: 'right' },
  { title: L('Toll', 'Peaje'), key: 'toll', align: 'right' },
  { title: L('Fuel', 'Combustible'), key: 'fuel', align: 'right' },
  { title: L('Status', 'Estado'), key: 'status' },
  { title: '', key: 'actions', width: 80 },
]);

async function load() {
  loading.value = true;
  try {
    data.value = await http.get('transport/daily', { date: date.value, agency_id: agencyId.value });
    agencyId.value = data.value.agency_id;
    price.high = data.value.price?.high ?? null;
    price.low = data.value.price?.low ?? null;
    emailsText.value = data.value.emails || '';
  } catch (e) {
    message.error(errorOf(e));
  } finally {
    loading.value = false;
  }
}
const scope = () => ({ date: date.value, agency_id: agencyId.value });

async function run(name, fn, ok) {
  busy.value = name;
  try {
    const res = await fn();
    if (ok) message.success(typeof ok === 'function' ? ok(res) : ok);
    return res;
  } catch (e) {
    message.error(errorOf(e));
    return null;
  } finally {
    busy.value = '';
  }
}

async function copyDay() {
  const res = await run('copy', () => http.post('transport/daily/copy', { ...scope(), from: data.value.copy_from }),
    r => L(`${r.made} departures copied.`, `${r.made} salidas copiadas.`));
  if (res) load();
}

async function savePrice() {
  if (closed.value || !data.value.can_manage) return;
  const old = data.value.price || {};
  if ((old.high ?? null) === price.high && (old.low ?? null) === price.low) return;
  const res = await run('price', () => http.post('transport/daily/price', { ...scope(), high: price.high, low: price.low }), L('Price saved.', 'Precio guardado.'));
  if (res) data.value.price = { high: price.high, low: price.low };
}

// ---------------- a departure
const tripOpen = ref(false);
const form = ref({ points: [] });
function openTrip(r = null) {
  form.value = r
    ? {
      id: r.id, time: r.time, route_id: r.route_id, vehicle_id: r.vehicle_id, points: r.points.map(p => ({ ...p })),
      reported_passengers: r.reported_passengers, reported_income: r.reported_income, toll: r.toll, fuel: r.fuel,
      pos_passengers: r.pos_passengers, pos_amount: r.pos_amount,
    }
    : { id: null, time: '', route_id: routeOptions.value[0]?.value, vehicle_id: undefined, points: [], reported_passengers: null, reported_income: null, toll: null, fuel: null };
  tripOpen.value = true;
}
function addPoint() {
  const last = form.value.points[form.value.points.length - 1];
  form.value.points.push({ location_id: last ? undefined : data.value.agency_id, label: '', time: last?.time || form.value.time || '' });
}
async function saveTrip() {
  const f = form.value;
  const body = { ...scope(), time: f.time, route_id: f.route_id, vehicle_id: f.vehicle_id || null,
    points: f.points.filter(p => p.location_id).map(p => ({ location_id: p.location_id, label: p.label || null, time: p.time })),
    reported_passengers: f.reported_passengers ?? null, reported_income: f.reported_income ?? null, toll: f.toll ?? null, fuel: f.fuel ?? null };
  const res = await run('save', () => (f.id ? http.put(`transport/daily/trips/${f.id}`, body) : http.post('transport/daily/trips', body)), L('Saved.', 'Guardado.'));
  if (res) {
    tripOpen.value = false;
    load();
  }
}
async function removeTrip(r) {
  const res = await run('remove', () => http.delete(`transport/daily/trips/${r.id}`, scope()), L('Removed.', 'Quitada.'));
  if (res) load();
}

// ---------------- close / reopen / emails
const closeOpen = ref(false);
const askClose = () => { closeOpen.value = true; };
async function closeDay() {
  const res = await run('close', () => http.post('transport/daily/close', scope()));
  if (!res) return;
  closeOpen.value = false;
  const to = res.emailed_to?.length ? L(` Sent to ${res.emailed_to.join(', ')}.`, ` Enviado a ${res.emailed_to.join(', ')}.`) : '';
  message.success(L('Day closed.', 'Día cerrado.') + to + (res.pdf ? '' : L(' The PDF could not be made.', ' No se pudo generar el PDF.')));
  load();
}
const reopenOpen = ref(false);
const reopenNote = ref('');
async function reopenDay() {
  const res = await run('reopen', () => http.post('transport/daily/reopen', { ...scope(), note: reopenNote.value }), L('Day reopened.', 'Día reabierto.'));
  if (res) {
    reopenOpen.value = false;
    reopenNote.value = '';
    load();
  }
}
const emailsOpen = ref(false);
const emailsText = ref('');
async function saveEmails() {
  const res = await run('emails', () => http.post('transport/daily/emails', { emails: emailsText.value }), L('Saved.', 'Guardado.'));
  if (res) {
    emailsOpen.value = false;
    data.value.emails = res.emails;
  }
}

async function openPdf(id) {
  try {
    const cid = getActingCompanyId();
    const b = await http.blob(`transport/daily/pdf/${id}${cid ? `?company_id=${cid}` : ''}`);
    window.open(URL.createObjectURL(b), '_blank');
  } catch (e) {
    message.error(L('The PDF could not be opened.', 'No se pudo abrir el PDF.'));
  }
}

// ---------------- history
const historyOpen = ref(false);
const history = ref([]);
const historyRange = ref([dayjs().subtract(60, 'day').format('YYYY-MM-DD'), today]);
const historyColumns = computed(() => [
  { title: L('Day', 'Día'), key: 'day' },
  { title: L('Agency', 'Agencia'), dataIndex: 'agency', key: 'agency' },
  { title: L('Passengers', 'Pasajeros'), key: 'pax' },
  { title: L('Amount', 'Monto'), key: 'amount', align: 'right' },
  { title: L('Closed by', 'Cerrado por'), dataIndex: 'closed_by', key: 'by' },
  { title: '', key: 'pdf', width: 50 },
]);
function openHistory() {
  historyOpen.value = true;
  loadHistory();
}
async function loadHistory() {
  const [from, to] = historyRange.value || [];
  const res = await run('history', () => http.get('transport/daily/history', { from, to }));
  history.value = res?.rows || [];
}
function goTo(h) {
  date.value = h.day;
  agencyId.value = h.agency_id;
  historyOpen.value = false;
  load();
}

onMounted(load);
</script>

<style scoped>
.dd-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 12px; }
.dd-grow { flex: 1; }
.dd-state { margin: 0; }
.dd-kpis { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
.dd-kpi { flex: 1 1 120px; background: #f5f3ff; border-radius: 8px; padding: 8px 10px; display: flex; flex-direction: column; }
.dd-kpi b { font-size: 18px; line-height: 1.2; }
.dd-kpi small { font-size: 12px; color: #64748b; font-weight: 400; }
.dd-kpi span { font-size: 12px; color: #64748b; }
.dd-net { background: #ecfdf5; }
.dd-price-in { display: flex; gap: 4px; margin-top: 2px; }
.dd-miss { margin-left: 6px; font-size: 11px; }
.dd-time { font-size: 15px; }
.dd-pt { margin: 0 4px 4px 0; }
.dd-muted { color: #94a3b8; }
.dd-low { color: #b91c1c; font-weight: 600; }
.dd-typed { color: #7c3aed; margin-left: 4px; font-size: 11px; }
.dd-bar-occ { height: 4px; background: #e2e8f0; border-radius: 2px; margin-top: 3px; width: 80px; }
.dd-bar-occ i { display: block; height: 100%; background: #16a34a; border-radius: 2px; }
.dd-bar-occ i.low { background: #f59e0b; }
.dd-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 0 12px; }
.dd-sub { font-weight: 700; margin: 4px 0 8px; }
.dd-point { display: flex; gap: 6px; margin-bottom: 6px; }
.dd-note { margin-bottom: 10px; }
:deep(.dd-cancel) td { opacity: .5; text-decoration: line-through; }
@media (max-width: 640px) { .dd-grow { display: none; } .dd-point { flex-wrap: wrap; } }
</style>
