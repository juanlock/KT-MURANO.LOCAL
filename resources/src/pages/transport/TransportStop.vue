<template>
  <div class="page ts-page">
    <PageHeader :title="es ? 'Mi parada' : 'My stop'" :breadcrumb="[es ? 'Transporte' : 'Transport', es ? 'Mi parada' : 'My stop']">
      <template #actions>
        <a-select v-if="agencies.length > 1" v-model:value="agencyId" :options="agencyOptions" style="min-width: 150px" @change="loadBuses(true)" />
        <a-button :loading="loading" @click="refresh"><ReloadOutlined /></a-button>
      </template>
    </PageHeader>

    <div class="ts-agency">
      <EnvironmentOutlined /> <b>{{ agency?.name || '—' }}</b>
      <span class="ts-muted">· {{ dayLabel }}</span>
      <a-switch v-model:checked="showDone" size="small" class="ts-done-switch" />
      <span class="ts-muted">{{ es ? 'Ver despachados' : 'Show dispatched' }} ({{ doneCount }})</span>
    </div>

    <a-empty v-if="loaded && !visibleBuses.length" :description="es ? 'No hay más buses que pasen hoy por esta agencia.' : 'No more buses pass through this agency today.'" />

    <div v-else class="ts-layout">
      <!-- The day's buses, in time order: a strip on the phone, a column on a computer. -->
      <div class="ts-buses">
        <div v-for="b in visibleBuses" :key="b.id" class="ts-bus" :class="{ 'is-on': b.id === tripId, 'is-done': !b.pending }" @click="pick(b.id)">
          <div class="ts-bus-top">
            <span class="ts-bus-time">{{ hhmm(b.departed_at || b.arrived_at || b.plan_arrive || b.plan_depart) }}</span>
            <a-tag :color="stateColor(b)" class="ts-tag">{{ stateText(b) }}</a-tag>
          </div>
          <div class="ts-bus-route">{{ b.route }}</div>
          <div class="ts-muted">{{ b.vehicle || '—' }}<span v-if="b.plate"> · {{ b.plate }}</span></div>
          <div class="ts-bus-counts">
            <span :title="es ? 'Bajan aquí' : 'Get off here'"><ArrowDownOutlined /> {{ b.down.done }}/{{ b.down.n }}</span>
            <span :title="es ? 'Suben aquí' : 'Get on here'"><ArrowUpOutlined /> {{ b.up.done }}/{{ b.up.n }}</span>
            <span :title="es ? 'Encomiendas' : 'Parcels'"><InboxOutlined /> {{ b.parcels_down.n + b.parcels_up.n }}</span>
          </div>
        </div>
      </div>

      <!-- The bus picked. -->
      <a-card v-if="detail" size="small" class="ts-detail" :body-style="{ padding: '12px' }">
        <div class="ts-head">
          <div>
            <div class="ts-head-route">{{ detail.trip.route }}</div>
            <div class="ts-muted">{{ detail.trip.vehicle || '—' }}<span v-if="detail.trip.plate"> · {{ detail.trip.plate }}</span>
              <span v-for="c in detail.crew" :key="c.name"> · {{ c.name }}</span></div>
          </div>
          <a-tag :color="stateColor(stopState)">{{ stateText(stopState) }}</a-tag>
        </div>

        <div class="ts-times">
          <div v-if="stop.kind !== 'origin'">
            <div class="ts-label">{{ es ? 'Llegada programada' : 'Planned arrival' }}</div>
            <div class="ts-value">{{ hhmm(stop.plan_arrive) }}</div>
          </div>
          <div v-if="stop.kind !== 'origin'">
            <div class="ts-label">{{ stop.arrived_at ? (es ? 'Llegó' : 'Arrived') : (es ? 'Llegada estimada' : 'Expected arrival') }}</div>
            <div class="ts-value" :class="{ 'is-late': stop.late && !stop.arrived_at }">{{ hhmm(stop.arrived_at || stop.eta) }}</div>
          </div>
          <div v-if="stop.kind !== 'destination'">
            <div class="ts-label">{{ stop.departed_at ? (es ? 'Salió' : 'Left') : (es ? 'Salida programada' : 'Planned departure') }}</div>
            <div class="ts-value">{{ hhmm(stop.departed_at || stop.plan_depart) }}</div>
          </div>
          <div>
            <div class="ts-label">{{ es ? 'Retraso' : 'Delay' }}</div>
            <div class="ts-value" :class="{ 'is-late': stop.late }">{{ delayText(stop) }}</div>
          </div>
        </div>
        <div v-if="stop.position && !stop.arrived_at && !stop.departed_at" class="ts-muted ts-gps">
          <AimOutlined /> GPS
          <span v-if="stop.position.km !== null"> · {{ es ? 'a' : '' }} {{ stop.position.km }} km{{ es ? '' : ' away' }}</span>
          <span v-if="stop.position.speed !== null"> · {{ Math.round(stop.position.speed) }} km/h</span>
          · {{ es ? 'hace' : '' }} {{ stop.position.age_min }} min{{ es ? '' : ' ago' }}
        </div>

        <div class="ts-actions">
          <a-button v-if="stop.kind !== 'origin' && !stop.arrived_at && !stop.departed_at && running" size="large" :loading="busy === 'arrive'" @click="markBus('arrive')">
            <FlagOutlined /> {{ es ? 'Bus llegó' : 'Bus arrived' }}
          </a-button>
          <a-button v-if="stop.kind !== 'destination' && !stop.departed_at && running" size="large" type="primary" :loading="busy === 'depart'" @click="confirmDepart">
            <SendOutlined /> {{ es ? 'Bus despachado' : 'Bus dispatched' }}
          </a-button>
        </div>

        <a-tabs v-model:activeKey="tab" size="small" class="ts-tabs">
          <a-tab-pane v-if="stop.kind !== 'origin'" key="down" :tab="`${es ? 'Bajan aquí' : 'Get off'} (${detail.down.length})`">
            <a-empty v-if="!detail.down.length" :description="es ? 'Nadie baja aquí.' : 'Nobody gets off here.'" />
            <div v-for="p in detail.down" :key="p.id" class="ts-row">
              <div class="ts-seat">{{ p.seat_number ?? '—' }}<a-tag v-if="p.is_vip" color="gold" class="ts-vip">VIP</a-tag></div>
              <div class="ts-who">
                <div class="ts-name">{{ p.last_name }}, {{ p.first_name }}</div>
                <div class="ts-muted">{{ p.dni }} · {{ es ? 'desde' : 'from' }} {{ p.from_name }}</div>
              </div>
              <div class="ts-btns">
                <template v-if="p.alighted_at">
                  <a-tag color="green">{{ es ? 'Bajó' : 'Off' }} {{ hhmm(p.alighted_at) }}</a-tag>
                  <a-button size="small" type="link" @click="ticketAction(p, 'undo')">{{ es ? 'Deshacer' : 'Undo' }}</a-button>
                </template>
                <a-button v-else type="primary" ghost @click="ticketAction(p, 'alight')">{{ es ? 'Bajó' : 'Got off' }}</a-button>
              </div>
            </div>
          </a-tab-pane>

          <a-tab-pane v-if="stop.kind !== 'destination'" key="up" :tab="`${es ? 'Suben aquí' : 'Get on'} (${detail.up.length})`">
            <a-empty v-if="!detail.up.length" :description="es ? 'Nadie sube aquí.' : 'Nobody gets on here.'" />
            <div v-for="p in detail.up" :key="p.id" class="ts-row" :class="{ 'is-noshow': p.no_show_at }">
              <div class="ts-seat">{{ p.seat_number ?? '—' }}<a-tag v-if="p.is_vip" color="gold" class="ts-vip">VIP</a-tag></div>
              <div class="ts-who">
                <div class="ts-name">{{ p.last_name }}, {{ p.first_name }}</div>
                <div class="ts-muted">{{ p.dni }} · {{ es ? 'a' : 'to' }} {{ p.to_name }}<span v-if="p.channel"> · {{ channelText(p.channel) }}</span></div>
              </div>
              <div class="ts-btns">
                <template v-if="p.boarded_at">
                  <a-tag color="green">{{ es ? 'Subió' : 'On board' }} {{ hhmm(p.boarded_at) }}</a-tag>
                  <a-button size="small" type="link" @click="ticketAction(p, 'undo')">{{ es ? 'Deshacer' : 'Undo' }}</a-button>
                </template>
                <template v-else-if="p.no_show_at">
                  <a-tag color="red">{{ es ? 'No se presentó' : 'No-show' }}</a-tag>
                  <a-button size="small" type="link" @click="ticketAction(p, 'undo')">{{ es ? 'Deshacer' : 'Undo' }}</a-button>
                </template>
                <template v-else>
                  <a-button type="primary" @click="ticketAction(p, 'board')">{{ es ? 'Subió' : 'Boarded' }}</a-button>
                  <a-popconfirm :title="es ? '¿No se presentó? Su asiento queda libre para la venta.' : 'No-show? The seat becomes free to sell.'" :ok-text="es ? 'Sí' : 'Yes'" :cancel-text="es ? 'No' : 'No'" @confirm="ticketAction(p, 'no_show')">
                    <a-button danger>{{ es ? 'No vino' : 'No-show' }}</a-button>
                  </a-popconfirm>
                </template>
              </div>
            </div>
          </a-tab-pane>

          <a-tab-pane v-if="stop.kind !== 'destination'" key="sell" :tab="es ? 'Última hora' : 'Last minute'">
            <a-alert v-if="!detail.can_sell" type="info" show-icon :message="detail.sell_block" />
            <template v-else>
              <a-alert v-if="!detail.register_open" type="warning" show-icon class="ts-gap"
                :message="es ? 'Su caja no está abierta: abra la caja en el POS para poder vender.' : 'Your cash register is not open: open it on the POS to sell.'" />
              <a-form layout="vertical" class="ts-sell">
                <a-form-item :label="es ? 'Destino' : 'Destination'">
                  <a-select v-model:value="sale.to" :options="destOptions" :placeholder="es ? 'Elija el destino' : 'Choose the destination'" @change="onDest" />
                </a-form-item>
                <a-form-item v-if="destStop && detail.trip.seats" :label="`${es ? 'Asiento' : 'Seat'} · ${destStop.free.length} ${es ? 'libres (dorado = VIP)' : 'free (gold = VIP)'}`">
                  <div v-if="destStop.free.length" class="ts-seats">
                    <a-button v-for="s in destStop.free" :key="s.number" :type="sale.seat === s.number ? 'primary' : 'default'" class="ts-seat-btn" :class="{ 'is-vip': s.vip }" @click="pickSeat(s)">
                      {{ s.number }}
                    </a-button>
                  </div>
                  <a-alert v-else type="error" :message="es ? 'No quedan asientos libres en este tramo.' : 'No free seats left on this stretch.'" />
                </a-form-item>
                <div class="ts-grid2">
                  <a-form-item :label="es ? 'Documento' : 'Document'">
                    <a-input-group compact class="ts-doc">
                      <a-select v-model:value="sale.doc_type" :options="docOptions" style="width: 40%" />
                      <a-input v-model:value="sale.dni" style="width: 60%" :inputmode="sale.doc_type === 'dni' ? 'numeric' : 'text'" :maxlength="20" />
                    </a-input-group>
                  </a-form-item>
                  <a-form-item :label="es ? 'Monto cobrado (S/)' : 'Amount charged'">
                    <a-input-number v-model:value="sale.price" :min="0" :step="1" style="width: 100%" inputmode="decimal" />
                  </a-form-item>
                  <a-form-item :label="es ? 'Nombres' : 'First names'">
                    <a-input v-model:value="sale.first_name" :maxlength="100" />
                  </a-form-item>
                  <a-form-item :label="es ? 'Apellidos' : 'Last names'">
                    <a-input v-model:value="sale.last_name" :maxlength="100" />
                  </a-form-item>
                  <a-form-item :label="es ? 'Forma de pago' : 'Payment method'">
                    <a-select v-model:value="sale.method" :options="methodOptions" />
                  </a-form-item>
                  <a-form-item :label="es ? 'Equipaje' : 'Luggage'">
                    <a-switch v-model:checked="sale.luggage" />
                  </a-form-item>
                </div>
                <a-button type="primary" size="large" block :loading="busy === 'sell'" :disabled="!canSubmitSale" @click="sell">
                  <ShoppingCartOutlined /> {{ es ? 'Vender y embarcar' : 'Sell and board' }}
                </a-button>
              </a-form>
            </template>
          </a-tab-pane>

          <a-tab-pane key="parcels" :tab="`${es ? 'Encomiendas' : 'Parcels'} (${detail.parcels_down.length + detail.parcels_up.length})`">
            <template v-if="stop.kind !== 'origin'">
              <div class="ts-sub">{{ es ? 'Descargar aquí' : 'Unload here' }} ({{ detail.parcels_down.length }})</div>
              <div v-if="!detail.parcels_down.length" class="ts-muted ts-gap">{{ es ? 'Ninguna.' : 'None.' }}</div>
              <div v-for="p in detail.parcels_down" :key="p.id" class="ts-row">
                <div class="ts-who">
                  <div class="ts-name">{{ p.number }} · {{ p.receiver_name }}</div>
                  <div class="ts-muted">{{ p.pieces }} {{ es ? 'bulto(s)' : 'piece(s)' }}<span v-if="p.description"> · {{ p.description }}</span> · {{ es ? 'desde' : 'from' }} {{ p.from_name }}
                    <span v-if="p.payment === 'collect'"> · <b>{{ es ? 'paga el destinatario' : 'receiver pays' }}</b></span></div>
                </div>
                <div class="ts-btns">
                  <a-tag v-if="p.unloaded_at || p.status === 'delivered'" color="green">{{ es ? 'Descargado' : 'Unloaded' }} {{ hhmm(p.unloaded_at) }}</a-tag>
                  <a-button v-else type="primary" ghost @click="parcelAction(p, 'unload')">{{ es ? 'Descargado' : 'Unloaded' }}</a-button>
                </div>
              </div>
            </template>
            <template v-if="stop.kind !== 'destination'">
              <div class="ts-sub">{{ es ? 'Cargar aquí' : 'Load here' }} ({{ detail.parcels_up.length }})</div>
              <div v-if="!detail.parcels_up.length" class="ts-muted ts-gap">{{ es ? 'Ninguna.' : 'None.' }}</div>
              <div v-for="p in detail.parcels_up" :key="p.id" class="ts-row">
                <div class="ts-who">
                  <div class="ts-name">{{ p.number }} · {{ es ? 'a' : 'to' }} {{ p.to_name }}</div>
                  <div class="ts-muted">{{ p.pieces }} {{ es ? 'bulto(s)' : 'piece(s)' }}<span v-if="p.description"> · {{ p.description }}</span> · {{ p.receiver_name }}</div>
                </div>
                <div class="ts-btns">
                  <a-tag v-if="p.status !== 'received'" color="green">{{ es ? 'Cargado' : 'Loaded' }}</a-tag>
                  <a-button v-else type="primary" ghost @click="parcelAction(p, 'load')">{{ es ? 'Cargado' : 'Loaded' }}</a-button>
                </div>
              </div>
            </template>
          </a-tab-pane>
        </a-tabs>
      </a-card>
    </div>
  </div>
</template>

<script setup>
/**
 * Transport -> Mi parada (Turismo Cautivo, 2026-11-18): the screen of the one person of a pass-through agency (Chepén,
 * Trujillo), made for the phone. The buses that pass through the agency today, in time order; for the bus picked: when it
 * arrives (plan + the delay it carries, GPS), who gets off and on here (on board / no-show: the seat is free again), the
 * last-minute passengers in the seats free from here on (a ticket + its sale on the agent's register), the parcels to unload
 * and load, and "Bus despachado" (the control point of this agency).
 */
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { AimOutlined, ArrowDownOutlined, ArrowUpOutlined, EnvironmentOutlined, FlagOutlined, InboxOutlined, ReloadOutlined, SendOutlined, ShoppingCartOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { printBoardingPasses } from '../../lib/transportPrint';
import { useUiStore } from '../../stores/ui';
import { sortByLabel } from '../../lib/filterOptions';
import { looseTranslation } from '../../i18n';
import { useFormat } from '../../composables/useFormat';

const es = computed(() => useUiStore().locale === 'es');
const { date: fmtDate } = useFormat();
const errorOf = e => { const errs = e?.data?.errors; return (errs && Object.values(errs)[0]?.[0]) || e?.data?.message || e.message; };

const agencies = ref([]);
const agency = ref(null);
const agencyId = ref(undefined);
const buses = ref([]);
const day = ref(null);
const loaded = ref(false);
const loading = ref(false);
const showDone = ref(false);
const tripId = ref(null);
const detail = ref(null);
const tab = ref('up');
const busy = ref(null);
let timer = null;

const agencyOptions = computed(() => agencies.value.map(a => ({ value: a.id, label: a.name }))); // line order, from the server
const visibleBuses = computed(() => buses.value.filter(b => showDone.value || b.pending || b.id === tripId.value));
const doneCount = computed(() => buses.value.filter(b => !b.pending).length);
const dayLabel = computed(() => (day.value ? fmtDate(day.value) : ''));
const stop = computed(() => detail.value?.stop || {});
const stopState = computed(() => ({ ...stop.value, status: detail.value?.trip?.status }));
const running = computed(() => ['scheduled', 'in_progress'].includes(detail.value?.trip?.status));

const hhmm = v => (v ? String(v).slice(11, 16) : '—');
function delayText(s) {
  if (s.delay_min === null || s.delay_min === undefined) return es.value ? 'Sin reportes' : 'No reports';
  if (s.delay_min <= 0) return es.value ? 'A tiempo' : 'On time';
  return `+${s.delay_min} min`;
}
function stateText(b) {
  if (b.departed_at) return (es.value ? 'Despachado ' : 'Dispatched ') + hhmm(b.departed_at);
  if (b.skipped) return es.value ? 'No paró' : 'Skipped';
  if (b.arrived_at) return (es.value ? 'En la agencia' : 'At the agency');
  if (b.kind === 'origin' && b.status === 'in_progress') return es.value ? 'Salió' : 'Left';
  if (b.kind === 'origin') return es.value ? 'Sale de aquí' : 'Leaves from here';
  if (b.late) return `${es.value ? 'Atrasado' : 'Late'} +${b.delay_min} min`;
  return b.status === 'in_progress' ? (es.value ? 'En camino' : 'On the way') : (es.value ? 'Programado' : 'Scheduled');
}
function stateColor(b) {
  if (b.departed_at || b.skipped) return 'default';
  if (b.arrived_at) return 'green';
  if (b.late) return 'red';
  return b.status === 'in_progress' ? 'blue' : 'default';
}
const CHANNELS = { agency: ['Agency', 'Agencia'], jelaf: ['Jelaf', 'Jelaf'], perubus: ['PeruBus', 'PeruBus'], phone: ['Phone', 'Teléfono'] };
const channelText = c => (CHANNELS[c] ? CHANNELS[c][es.value ? 1 : 0] : c);

const params = () => (agencyId.value ? { agency_id: agencyId.value } : {});

async function loadBuses(reset = false) {
  loading.value = true;
  try {
    const res = await http.get('transport/stop', params());
    agencies.value = res.agencies;
    agency.value = res.agency;
    agencyId.value = res.agency.id;
    day.value = res.date;
    buses.value = res.buses;
    loaded.value = true;
    if (reset || !buses.value.some(b => b.id === tripId.value)) {
      // Opens on the bus at hand: the first one still to dispatch.
      const pick0 = buses.value.find(b => b.pending) || buses.value[buses.value.length - 1];
      tripId.value = pick0?.id ?? null;
      detail.value = null;
      if (tripId.value) await loadDetail(true);
    }
  } catch (e) {
    message.error(errorOf(e));
  } finally {
    loading.value = false;
  }
}
function defaultTab() {
  const s = detail.value?.stop || {};
  if (s.kind === 'origin') return 'up';
  if (s.kind === 'destination') return 'down';
  return detail.value.down.some(p => !p.alighted_at) && !s.arrived_at ? 'down' : 'up';
}
function apply(res, keepTab = true) {
  detail.value = res;
  if (!keepTab) tab.value = defaultTab();
  // The card keeps up with what was done here.
  const b = buses.value.find(x => x.id === res.trip.id);
  if (b && res.stop) {
    Object.assign(b, { arrived_at: res.stop.arrived_at, departed_at: res.stop.departed_at, eta: res.stop.eta, late: res.stop.late, delay_min: res.stop.delay_min, status: res.trip.status });
    b.down = { n: res.down.length, done: res.down.filter(p => p.alighted_at).length };
    b.up = { n: res.up.length, done: res.up.filter(p => p.boarded_at).length };
    b.pending = res.stop.pending;
  }
}
async function loadDetail(resetTab = false) {
  if (!tripId.value) return;
  try {
    apply(await http.get(`transport/stop/trips/${tripId.value}`, params()), !resetTab);
  } catch (e) {
    message.error(errorOf(e));
  }
}
function pick(id) {
  if (id === tripId.value) return;
  tripId.value = id;
  resetSale();
  loadDetail(true);
}
async function refresh() {
  await loadBuses();
  await loadDetail();
}

async function post(url, body, key) {
  busy.value = key;
  try {
    const res = await http.post(url, { ...params(), ...body });
    apply(res);
    return res;
  } catch (e) {
    message.error(errorOf(e));
    return null;
  } finally {
    busy.value = null;
  }
}
const ticketAction = (p, action) => post(`transport/stop/tickets/${p.id}`, { action }, `t${p.id}`);
const parcelAction = (p, action) => post(`transport/stop/parcels/${p.id}`, { action }, `p${p.id}`);
async function markBus(action) {
  if (await post(`transport/stop/trips/${tripId.value}/${action}`, {}, action)) {
    message.success(action === 'arrive' ? (es.value ? 'Llegada registrada.' : 'Arrival recorded.') : (es.value ? 'Bus despachado.' : 'Bus dispatched.'));
  }
}
function confirmDepart() {
  const missing = detail.value.up.filter(p => !p.boarded_at && !p.no_show_at).length;
  const parcels = detail.value.parcels_up.filter(p => p.status === 'received').length;
  const parts = [];
  if (missing) parts.push(es.value ? `${missing} pasajero(s) sin marcar` : `${missing} passenger(s) not marked`);
  if (parcels) parts.push(es.value ? `${parcels} encomienda(s) sin cargar` : `${parcels} parcel(s) not loaded`);
  Modal.confirm({
    title: es.value ? '¿Despachar el bus?' : 'Dispatch the bus?',
    content: parts.length ? `${parts.join(' · ')}.` : (es.value ? 'Todo está marcado.' : 'Everything is marked.'),
    okText: es.value ? 'Despachar' : 'Dispatch',
    cancelText: es.value ? 'Cancelar' : 'Cancel',
    onOk: () => markBus('depart'),
  });
}

// ------------------------------------------------------------------ last-minute passenger
const DOCS = { dni: 'DNI', ce: 'CE', passport: ['Passport', 'Pasaporte'], ptp: 'PTP' };
const docOptions = computed(() => sortByLabel(Object.entries(DOCS).map(([value, l]) => ({ value, label: Array.isArray(l) ? l[es.value ? 1 : 0] : l }))));
const sale = reactive({ to: undefined, seat: null, vip: false, doc_type: 'dni', dni: '', first_name: '', last_name: '', price: null, method: undefined, luggage: false });
function resetSale() {
  Object.assign(sale, { to: undefined, seat: null, vip: false, dni: '', first_name: '', last_name: '', price: null, luggage: false });
}
const destOptions = computed(() => (detail.value?.stops || []).map(s => ({ value: s.location_id, label: `${s.name} · ${s.free.length} ${es.value ? 'libres' : 'free'}` })));
const destStop = computed(() => (detail.value?.stops || []).find(s => s.location_id === sale.to));
const methodOptions = computed(() => sortByLabel((detail.value?.payment_methods || []).map(m => ({ value: m.id, label: looseTranslation(m.name) || m.name }))));
const canSubmitSale = computed(() => !!(sale.to && (sale.seat || !detail.value?.trip?.seats) && sale.dni && sale.first_name && sale.last_name && sale.price !== null));
function suggestedPrice() {
  const s = destStop.value;
  if (!s) return null;
  return sale.vip && s.price_vip !== null ? s.price_vip : s.price;
}
function onDest() {
  sale.seat = null;
  sale.vip = false;
  sale.price = suggestedPrice();
  if (!sale.method) sale.method = (detail.value.payment_methods.find(m => m.name === 'Cash') || detail.value.payment_methods[0])?.id;
}
function pickSeat(s) {
  sale.seat = s.number;
  sale.vip = s.vip;
  sale.price = suggestedPrice();
}
async function sell() {
  const res = await post(`transport/stop/trips/${tripId.value}/sell`, {
    to_location_id: sale.to, seat_number: sale.seat, doc_type: sale.doc_type, dni: sale.dni, first_name: sale.first_name, last_name: sale.last_name,
    price: sale.price, payment_method_id: sale.method, luggage: sale.luggage,
  }, 'sell');
  if (!res) return;
  message.success(es.value ? 'Pasaje vendido: el pasajero ya figura a bordo.' : 'Ticket sold: the passenger is on board.');
  const id = res.ticket_id;
  resetSale();
  tab.value = 'up';
  Modal.confirm({
    title: es.value ? '¿Imprimir el boleto?' : 'Print the boarding pass?',
    okText: es.value ? 'Imprimir' : 'Print',
    cancelText: es.value ? 'No' : 'No',
    onOk: async () => {
      try {
        const { tickets } = await http.get('transport/tickets', { id });
        if (!(await printBoardingPasses(tickets, es.value))) message.warning(es.value ? 'Permita las ventanas emergentes para imprimir.' : 'Allow pop-ups to print.');
      } catch (e) {
        message.error(errorOf(e));
      }
    },
  });
}

onMounted(() => {
  loadBuses(true);
  // The bus moves: the times and the GPS every minute.
  timer = setInterval(() => { if (!busy.value && !document.hidden) refresh(); }, 60000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<style scoped>
.ts-agency { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin: 0 0 10px; font-size: 15px; }
.ts-done-switch { margin-left: auto; }
.ts-muted { font-size: 12px; color: #64748b; }
.ts-layout { display: grid; grid-template-columns: 300px minmax(0, 1fr); gap: 12px; align-items: start; }
.ts-buses { display: flex; flex-direction: column; gap: 8px; max-height: calc(100vh - 170px); overflow-y: auto; }
.ts-bus { border: 1px solid #e2e8f0; border-radius: 10px; padding: 8px 10px; background: #fff; cursor: pointer; }
.ts-bus.is-on { border-color: var(--ant-primary-color, #1677ff); box-shadow: 0 0 0 2px rgba(22, 119, 255, 0.15); }
.ts-bus.is-done { opacity: 0.6; }
.ts-bus-top { display: flex; align-items: center; justify-content: space-between; gap: 6px; }
.ts-bus-time { font-size: 22px; font-weight: 700; line-height: 1.1; }
.ts-tag { margin: 0; }
.ts-bus-route { font-weight: 600; margin-top: 2px; }
.ts-bus-counts { display: flex; gap: 12px; margin-top: 4px; font-size: 13px; }
.ts-head { display: flex; justify-content: space-between; gap: 8px; align-items: flex-start; }
.ts-head-route { font-size: 16px; font-weight: 700; }
.ts-times { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; margin: 10px 0 4px; }
.ts-label { font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 0.02em; }
.ts-value { font-size: 20px; font-weight: 700; }
.ts-value.is-late { color: #dc2626; }
.ts-gps { margin-bottom: 4px; }
.ts-actions { display: flex; flex-wrap: wrap; gap: 8px; margin: 8px 0; }
.ts-actions .ant-btn { flex: 1 1 160px; }
.ts-row { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid #f1f5f9; }
.ts-row.is-noshow .ts-name { text-decoration: line-through; color: #94a3b8; }
.ts-seat { width: 46px; font-size: 18px; font-weight: 700; text-align: center; flex: none; }
.ts-vip { display: block; margin: 2px 0 0; font-size: 10px; line-height: 14px; padding: 0 3px; }
.ts-who { flex: 1; min-width: 0; }
.ts-name { font-weight: 600; overflow: hidden; text-overflow: ellipsis; }
.ts-btns { display: flex; flex-wrap: wrap; gap: 6px; justify-content: flex-end; align-items: center; }
.ts-sub { font-weight: 700; margin: 6px 0 2px; }
.ts-gap { margin-bottom: 10px; }
.ts-seats { display: flex; flex-wrap: wrap; gap: 6px; }
.ts-seat-btn { width: 46px; height: 40px; padding: 0; font-weight: 600; }
.ts-seat-btn.is-vip:not(.ant-btn-primary) { border-color: #d4a72c; background: #fffbeb; }
.ts-grid2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); column-gap: 10px; }
.ts-doc { display: flex !important; }
.ts-sell :deep(.ant-form-item) { margin-bottom: 10px; }

@media (max-width: 991px) {
  .ts-layout { grid-template-columns: minmax(0, 1fr); }
  .ts-buses, .ts-detail { min-width: 0; }
  /* The phone: the buses as a strip to swipe. */
  .ts-buses { flex-direction: row; overflow-x: auto; overflow-y: hidden; max-height: none; padding-bottom: 4px; scroll-snap-type: x mandatory; }
  .ts-bus { flex: 0 0 210px; scroll-snap-align: start; }
}
@media (max-width: 575px) {
  .ts-times { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .ts-grid2 { grid-template-columns: 1fr; }
  .ts-value { font-size: 18px; }
  .ts-row { gap: 6px; }
  .ts-seat { width: 36px; font-size: 16px; }
  .ts-name { white-space: nowrap; }
  .ts-btns { flex-direction: column; align-items: stretch; gap: 4px; }
  .ts-btns .ant-btn:not(.ant-btn-link) { padding: 0 10px; }
}
</style>
