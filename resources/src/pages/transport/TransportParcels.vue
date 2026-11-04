<template>
  <div class="page">
    <PageHeader :title="es ? 'Encomiendas' : 'Parcels'" :breadcrumb="[es ? 'Transporte' : 'Transport', es ? 'Encomiendas' : 'Parcels']">
      <template #actions>
        <a-button type="primary" @click="openForm(null)"><PlusOutlined /> {{ es ? 'Registrar encomienda' : 'Register a parcel' }}</a-button>
      </template>
    </PageHeader>

    <a-card size="small" :body-style="{ padding: '12px' }">
      <div class="tp-filters">
        <PresetRangeFilter v-model:value="range" value-format="YYYY-MM-DD" allow-all compact @change="reload" />
        <a-select v-model:value="filters.status" :options="statusOptions" allow-clear style="width: 170px" :placeholder="es ? 'Todos los estados' : 'All statuses'" @change="reload" />
        <a-checkbox v-model:checked="filters.waiting" @change="reload">{{ es ? 'Solo sin bus' : 'Without a bus only' }}</a-checkbox>
        <a-input-search v-model:value="filters.search" allow-clear style="width: 230px" :placeholder="es ? 'Guía, remitente o destinatario' : 'Number, sender or receiver'" @search="reload" />
        <span class="tp-muted">{{ total }} {{ es ? 'encomiendas' : 'parcels' }}</span>
      </div>
      <a-table :columns="columns" :data-source="rows" :loading="loading" row-key="id" size="middle" :scroll="{ x: 'max-content' }"
        :pagination="{ current: page, pageSize: perPage, total, showSizeChanger: true }" @change="onTable">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'number'"><b>{{ record.number }}</b> <a-tag v-if="record.cargo_type === 'fragile'" color="orange">{{ es ? 'Frágil' : 'Fragile' }}</a-tag><div class="tp-muted">{{ record.description }}</div></template>
          <template v-else-if="column.key === 'route'">{{ record.from_name }} → {{ record.to_name }}<div class="tp-muted">{{ es ? 'Llega' : 'Arrives' }} {{ day(record.eta_from) }} – {{ day(record.eta_to) }}</div></template>
          <template v-else-if="column.key === 'bus'">
            <template v-if="record.trip_id">{{ record.vehicle }}<div class="tp-muted">{{ fmt(record.departure_at) }}</div></template>
            <a-tag v-else color="red">{{ es ? 'Sin bus todavía' : 'No bus yet' }}</a-tag>
          </template>
          <template v-else-if="column.key === 'people'">{{ record.sender_name }}<div class="tp-muted">→ {{ record.receiver_name }}</div></template>
          <template v-else-if="column.key === 'box'">{{ num(record.length_cm) }} × {{ num(record.width_cm) }} × {{ num(record.height_cm) }} cm<span v-if="record.pieces > 1"> × {{ record.pieces }}</span>
            <div class="tp-muted">{{ num(record.volume_m3) }} m³ · {{ num(record.weight_kg) }} kg</div></template>
          <template v-else-if="column.key === 'price'">{{ money(record.price) }}<div v-if="record.payment === 'collect'" class="tp-muted">{{ es ? 'Paga quien recibe' : 'Receiver pays' }}</div></template>
          <template v-else-if="column.key === 'status'">
            <a-tag :color="STATUS_COLOR[record.status]">{{ statusLabel(record.status) }}</a-tag>
            <div v-if="record.delivered_to" class="tp-muted">{{ record.delivered_to }}</div>
          </template>
          <template v-else-if="column.key === 'actions'">
            <a-space :size="4" wrap>
              <a-button size="small" type="text" :title="es ? 'Imprimir guía' : 'Print guide'" @click="printGuide(record)"><PrinterOutlined /></a-button>
              <a-button v-if="record.status === 'received'" size="small" type="text" @click="openForm(record)"><EditOutlined /></a-button>
              <a-button v-if="record.status === 'received' && record.trip_id" size="small" @click="setStatus(record, 'on_board')">{{ es ? 'A bordo' : 'On board' }}</a-button>
              <a-button v-if="record.status === 'on_board' || record.status === 'received'" size="small" @click="openDeliver(record)">{{ es ? 'Entregar' : 'Deliver' }}</a-button>
              <a-popconfirm v-if="record.status === 'received'" :title="es ? '¿Anular esta encomienda?' : 'Cancel this parcel?'" @confirm="setStatus(record, 'cancelled')">
                <a-button size="small" type="text" danger><StopOutlined /></a-button>
              </a-popconfirm>
            </a-space>
          </template>
        </template>
      </a-table>
    </a-card>

    <a-modal v-model:open="formOpen" :title="form.id ? (es ? 'Editar encomienda' : 'Edit parcel') : (es ? 'Registrar encomienda' : 'Register a parcel')" :width="680"
      :confirm-loading="saving" :ok-text="es ? 'Guardar' : 'Save'" @ok="save">
      <a-form layout="vertical">
        <a-row :gutter="12">
          <a-col :xs="24" :md="8"><a-form-item :label="es ? 'Fecha *' : 'Date *'"><a-date-picker v-model:value="form.received_on" value-format="YYYY-MM-DD" :allow-clear="false" style="width: 100%" /></a-form-item></a-col>
          <a-col :xs="24" :md="8"><a-form-item :label="es ? 'Origen *' : 'Origin *'"><a-select v-model:value="form.from_location_id" :options="originOptions" :disabled="originOptions.length < 2" /></a-form-item></a-col>
          <a-col :xs="24" :md="8"><a-form-item :label="es ? 'Destino *' : 'Destination *'"><a-select v-model:value="form.to_location_id" :options="destinationOptions" /></a-form-item></a-col>
          <a-col :xs="24" :md="12"><a-form-item :label="es ? 'Remitente *' : 'Sender *'"><a-input v-model:value="form.sender_name" :maxlength="120" /></a-form-item></a-col>
          <a-col :xs="24" :md="12"><a-form-item :label="es ? 'Teléfono del remitente' : 'Sender\'s phone'"><a-input v-model:value="form.sender_phone" :maxlength="40" /></a-form-item></a-col>
          <a-col :xs="24" :md="12"><a-form-item :label="es ? 'Destinatario *' : 'Receiver *'"><a-input v-model:value="form.receiver_name" :maxlength="120" /></a-form-item></a-col>
          <a-col :xs="24" :md="12"><a-form-item :label="es ? 'Teléfono del destinatario' : 'Receiver\'s phone'"><a-input v-model:value="form.receiver_phone" :maxlength="40" /></a-form-item></a-col>
          <a-col :xs="24" :md="12"><a-form-item :label="es ? 'Contenido' : 'Contents'"><a-input v-model:value="form.description" :maxlength="190" /></a-form-item></a-col>
          <a-col :xs="24" :md="12"><a-form-item :label="es ? 'Tipo de carga' : 'Cargo type'" :extra="es ? 'Frágil o de valor (un televisor) cuesta más por m³.' : 'Fragile or valuable costs more per m³.'"><a-select v-model:value="form.cargo_type" :options="cargoOptions" /></a-form-item></a-col>
          <a-col :xs="12" :md="6"><a-form-item :label="es ? 'Largo (cm) *' : 'Length (cm) *'"><a-input-number v-model:value="form.length_cm" :min="0.1" style="width: 100%" /></a-form-item></a-col>
          <a-col :xs="12" :md="6"><a-form-item :label="es ? 'Ancho (cm) *' : 'Width (cm) *'"><a-input-number v-model:value="form.width_cm" :min="0.1" style="width: 100%" /></a-form-item></a-col>
          <a-col :xs="12" :md="6"><a-form-item :label="es ? 'Alto (cm) *' : 'Height (cm) *'"><a-input-number v-model:value="form.height_cm" :min="0.1" style="width: 100%" /></a-form-item></a-col>
          <a-col :xs="12" :md="6"><a-form-item :label="es ? 'Bultos *' : 'Pieces *'"><a-input-number v-model:value="form.pieces" :min="1" :precision="0" style="width: 100%" /></a-form-item></a-col>
          <a-col :xs="12" :md="6"><a-form-item :label="es ? 'Peso total (kg)' : 'Total weight (kg)'"><a-input-number v-model:value="form.weight_kg" :min="0" style="width: 100%" /></a-form-item></a-col>
          <a-col :xs="12" :md="6"><a-form-item :label="es ? 'Precio *' : 'Price *'" :extra="suggested != null ? `${es ? 'Sugerido' : 'Suggested'}: ${money(suggested)}` : ''"><a-input-number v-model:value="form.price" :min="0" style="width: 100%" @change="priceTouched = true" /></a-form-item></a-col>
          <a-col :xs="24" :md="12"><a-form-item :label="es ? 'Pago' : 'Payment'"><a-radio-group v-model:value="form.payment" button-style="solid" size="small"><a-radio-button value="paid">{{ es ? 'Paga ahora' : 'Pays now' }}</a-radio-button><a-radio-button value="collect">{{ es ? 'Paga quien recibe' : 'Receiver pays' }}</a-radio-button></a-radio-group></a-form-item></a-col>
          <a-col v-if="form.payment === 'paid' && !form.id" :xs="24" :md="12"><a-form-item :label="es ? 'Medio de pago' : 'Payment method'"><a-select v-model:value="form.payment_method_id" :options="methodOptions" /></a-form-item></a-col>
        </a-row>
        <a-alert type="info" show-icon :message="etaText" />
      </a-form>
    </a-modal>

    <a-modal v-model:open="deliverOpen" :title="es ? 'Entregar encomienda' : 'Deliver the parcel'" :ok-text="es ? 'Entregar' : 'Deliver'" @ok="deliver">
      <a-form layout="vertical">
        <a-form-item :label="es ? 'Recibido por *' : 'Received by *'"><a-input v-model:value="deliverForm.delivered_to" :maxlength="120" /></a-form-item>
        <a-form-item v-if="deliverForm.record?.payment === 'collect'" :label="es ? 'Cobra ahora: medio de pago' : 'Collect now: payment method'" :extra="`${es ? 'A cobrar' : 'To collect'}: ${money(deliverForm.record.price)}`" style="margin-bottom: 0"><a-select v-model:value="deliverForm.payment_method_id" :options="methodOptions" /></a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Transport -> Parcels (2026-11-04). Who registers a parcel doesn't know the departures: date, destination and the box's
 * measures; the system puts it on the first bus with room (TransportParcelAssign) and the customer gets an estimate of 1 to 3 days.
 */
import { computed, onMounted, ref, watch } from 'vue';
import { message } from 'ant-design-vue';
import { EditOutlined, PlusOutlined, PrinterOutlined, StopOutlined } from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import PageHeader from '../../components/PageHeader.vue';
import PresetRangeFilter from '../../components/PresetRangeFilter.vue';
import http from '../../lib/http';
import { printParcelGuide } from '../../lib/transportPrint';
import { sortByLabel } from '../../lib/filterOptions';
import { useFormat } from '../../composables/useFormat';
import { useListState } from '../../composables/useListState';
import { useUiStore } from '../../stores/ui';

const { money } = useFormat();
const es = computed(() => useUiStore().locale === 'es');
const STATUS = [['received', 'At the agency', 'En agencia'], ['on_board', 'On board', 'A bordo'], ['delivered', 'Delivered', 'Entregada'], ['cancelled', 'Cancelled', 'Anulada']];
const STATUS_COLOR = { received: 'gold', on_board: 'blue', delivered: 'green', cancelled: 'default' };
const statusLabel = s => { const x = STATUS.find(r => r[0] === s); return x ? (es.value ? x[2] : x[1]) : s; };
const statusOptions = computed(() => STATUS.map(s => ({ value: s[0], label: es.value ? s[2] : s[1] })));
const fmt = d => (d ? dayjs(d).format('YYYY-MM-DD HH:mm') : '');
const day = d => (d ? dayjs(d).format('DD/MM') : '');
const num = v => Number(v ?? 0).toLocaleString(undefined, { maximumFractionDigits: 3 });
const errorOf = e => { const errs = e?.data?.errors; return (errs && Object.values(errs)[0]?.[0]) || e?.data?.message || e.message; };

const rows = ref([]);
const total = ref(0);
const loading = ref(false);
const page = ref(1);
const perPage = ref(20);
const range = ref(null);
const filters = ref({ status: undefined, search: '', waiting: false });
useListState('transport-parcels', { page, perPage, range, filters });

const columns = computed(() => [
  { title: es.value ? 'Guía' : 'Number', key: 'number' },
  { title: es.value ? 'Ruta' : 'Route', key: 'route' },
  { title: 'Bus', key: 'bus' },
  { title: es.value ? 'Remite → recibe' : 'From → to', key: 'people' },
  { title: es.value ? 'Caja' : 'Box', key: 'box' },
  { title: es.value ? 'Precio' : 'Price', key: 'price', align: 'right' },
  { title: es.value ? 'Estado' : 'Status', key: 'status' },
  { title: '', key: 'actions' },
]);

async function load() {
  loading.value = true;
  try {
    const res = await http.get('transport/parcels', { ...filters.value, waiting: filters.value.waiting ? 1 : 0, from: range.value?.[0], to: range.value?.[1], page: page.value, limit: perPage.value });
    rows.value = res.parcels;
    total.value = res.totalRows;
  } catch (e) {
    message.error(errorOf(e));
  } finally {
    loading.value = false;
  }
}
function reload() { page.value = 1; load(); }
function onTable(p) { page.value = p.current; perPage.value = p.pageSize; load(); }

// ---- form
const meta = ref({ origins: [], destinations: [], rates: {}, payment_methods: [], eta_days: [1, 3], cargo_factors: {} });
const methodOptions = computed(() => sortByLabel((meta.value.payment_methods || []).map(m => ({ value: m.id, label: m.name }))));
const originOptions = computed(() => sortByLabel(meta.value.origins.map(l => ({ value: l.id, label: l.name }))));
const destinationOptions = computed(() => sortByLabel(meta.value.destinations.filter(l => l.id !== form.value.from_location_id).map(l => ({ value: l.id, label: l.name }))));
const cargoOptions = computed(() => [{ value: 'general', label: 'General' }, { value: 'fragile', label: es.value ? 'Frágil o de valor' : 'Fragile or valuable' }]);
const formOpen = ref(false);
const saving = ref(false);
const priceTouched = ref(false);
const blank = () => ({ id: null, received_on: dayjs().format('YYYY-MM-DD'), from_location_id: meta.value.origins[0]?.id, to_location_id: undefined, cargo_type: 'general', payment: 'paid', payment_method_id: meta.value.payment_methods?.[0]?.id,
  sender_name: '', sender_phone: '', receiver_name: '', receiver_phone: '', description: '', length_cm: null, width_cm: null, height_cm: null, pieces: 1, weight_kg: null, price: null });
const form = ref(blank());
async function loadMeta() {
  meta.value = await http.get('transport/parcels/meta');
}
async function openForm(r) {
  try {
    await loadMeta();
  } catch (e) {
    message.error(errorOf(e));
    return;
  }
  form.value = r
    ? { id: r.id, received_on: r.received_on, from_location_id: r.from_location_id, to_location_id: r.to_location_id, cargo_type: r.cargo_type || 'general', payment: r.payment || 'paid', sender_name: r.sender_name, sender_phone: r.sender_phone,
        receiver_name: r.receiver_name, receiver_phone: r.receiver_phone, description: r.description, length_cm: Number(r.length_cm), width_cm: Number(r.width_cm), height_cm: Number(r.height_cm), pieces: r.pieces, weight_kg: Number(r.weight_kg), price: Number(r.price) }
    : blank();
  priceTouched.value = !!r; // a saved parcel keeps its price
  formOpen.value = true;
}
const volume = computed(() => {
  const f = form.value;
  return ((f.length_cm || 0) * (f.width_cm || 0) * (f.height_cm || 0) * (f.pieces || 1)) / 1000000;
});
// The price per m³ of that origin -> destination, and more for fragile cargo.
const suggested = computed(() => {
  const rate = meta.value.rates?.[`${form.value.from_location_id}-${form.value.to_location_id}`];
  if (rate == null || !(volume.value > 0)) return null;
  return Math.round(rate * volume.value * (meta.value.cargo_factors?.[form.value.cargo_type] || 1) * 100) / 100;
});
watch(suggested, v => { if (v != null && !priceTouched.value) form.value.price = v; });
const etaText = computed(() => {
  const [a, b] = meta.value.eta_days || [1, 3];
  const from = dayjs(form.value.received_on).add(a, 'day').format('DD/MM');
  const to = dayjs(form.value.received_on).add(b, 'day').format('DD/MM');
  return `${es.value ? 'Dígale al cliente: llega entre' : 'Tell the customer: arrives between'} ${from} ${es.value ? 'y' : 'and'} ${to}. ${es.value ? 'El sistema elige el bus.' : 'The system picks the bus.'}`;
});
async function save() {
  saving.value = true;
  try {
    const wasNew = !form.value.id;
    const res = form.value.id ? await http.put(`transport/parcels/${form.value.id}`, form.value) : await http.post('transport/parcels', form.value);
    message.success(`${es.value ? 'Guardada' : 'Saved'}: ${res.number}${res.parcel?.trip_id ? '' : (es.value ? ' · espera un bus con espacio' : ' · waiting for a bus with room')}`);
    formOpen.value = false;
    load();
    if (wasNew && res.parcel) printParcelGuide(res.parcel, es.value);
  } catch (e) {
    message.error(errorOf(e));
  } finally {
    saving.value = false;
  }
}

async function setStatus(r, status, extra = {}) {
  try {
    await http.post(`transport/parcels/${r.id}/status`, { status, ...extra });
    load();
    return true;
  } catch (e) {
    message.error(errorOf(e));
    return false;
  }
}
const deliverOpen = ref(false);
const deliverForm = ref({ record: null, delivered_to: '' });
async function printGuide(r) {
  try {
    const { parcel } = await http.get(`transport/parcels/${r.id}/print`);
    if (!(await printParcelGuide(parcel, es.value))) message.warning(es.value ? 'Permita las ventanas emergentes para imprimir.' : 'Allow pop-ups to print.');
  } catch (e) {
    message.error(errorOf(e));
  }
}
async function openDeliver(r) {
  if (!meta.value.payment_methods?.length) {
    try { await loadMeta(); } catch (e) { /* the server picks one */ }
  }
  deliverForm.value = { record: r, delivered_to: r.receiver_name, payment_method_id: meta.value.payment_methods?.[0]?.id };
  deliverOpen.value = true;
}
async function deliver() {
  if (await setStatus(deliverForm.value.record, 'delivered', { delivered_to: deliverForm.value.delivered_to, payment_method_id: deliverForm.value.payment_method_id })) deliverOpen.value = false;
}

onMounted(load);
</script>

<style scoped>
.tp-filters { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 12px; }
.tp-muted { font-size: 12px; color: #64748b; }
</style>
