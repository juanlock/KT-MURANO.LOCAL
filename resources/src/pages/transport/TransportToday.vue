<template>
  <div class="page">
    <PageHeader :title="es ? 'Salidas de hoy' : 'Today\'s departures'" :breadcrumb="[es ? 'Transporte' : 'Transport', es ? 'Salidas de hoy' : 'Today\'s departures']" />

    <a-card size="small" :body-style="{ padding: '12px' }">
      <div class="td-filters">
        <a-date-picker v-model:value="date" value-format="YYYY-MM-DD" :allow-clear="false" @change="load" />
        <a-select v-if="agencyOptions.length > 1" v-model:value="warehouseId" :options="agencyOptions" allow-clear style="width: 200px" :placeholder="es ? 'Todas las agencias' : 'All agencies'" @change="load" />
        <a-button @click="load"><ReloadOutlined /> {{ es ? 'Actualizar' : 'Refresh' }}</a-button>
      </div>

      <a-spin :spinning="loading">
        <a-empty v-if="!rows.length && !loading" :description="es ? 'No hay salidas ese día.' : 'No departures that day.'" />
        <div class="td-list">
          <div v-for="t in rows" :key="t.id" class="td-trip" :class="`td-${stateOf(t)}`">
            <div class="td-time">{{ time(t.departure_at) }}</div>
            <div class="td-main">
              <div class="td-route">{{ t.route }}</div>
              <div class="td-muted">{{ t.vehicle }}</div>
              <div class="td-muted">{{ t.crew.map(c => c.name).join(', ') }}</div>
              <div class="td-figures">
                <span>{{ es ? 'Asientos' : 'Seats' }}: <b>{{ t.sold }}{{ t.seats ? ' / ' + t.seats : '' }}</b></span>
                <span>{{ es ? 'Encomiendas' : 'Parcels' }}: <b>{{ t.parcels }}</b><template v-if="t.cargo_limit"> ({{ t.cargo_volume }} / {{ t.cargo_limit }} m³)</template></span>
              </div>
              <div v-if="t.last_decision" class="td-muted">
                {{ actionLabel(t.last_decision.action) }} · {{ t.last_decision.by }}<template v-if="t.last_decision.note"> · {{ t.last_decision.note }}</template>
              </div>
            </div>
            <div class="td-side">
              <a-tag :color="STATE[stateOf(t)].color">{{ es ? STATE[stateOf(t)].es : STATE[stateOf(t)].en }}</a-tag>
              <a-space wrap :size="6" class="td-actions">
                <template v-if="t.status === 'scheduled' && t.can_decide">
                  <a-button v-if="!t.confirmed" size="small" type="primary" @click="confirmTrip(t)">{{ es ? 'Se ejecuta' : 'It runs' }}</a-button>
                  <a-button size="small" @click="openDecision(t, 'reschedule')">{{ es ? 'Reprogramar' : 'Reschedule' }}</a-button>
                  <a-button size="small" danger @click="openDecision(t, 'cancel')">{{ es ? 'Cancelar' : 'Cancel' }}</a-button>
                </template>
                <a-button size="small" @click="openManifest(t)">{{ es ? 'Pasajeros' : 'Passengers' }}</a-button>
              </a-space>
            </div>
          </div>
        </div>
      </a-spin>
    </a-card>

    <a-modal v-model:open="decisionOpen" :title="decision.action === 'cancel' ? (es ? 'Cancelar el viaje' : 'Cancel the trip') : (es ? 'Reprogramar el viaje' : 'Reschedule the trip')"
      :confirm-loading="saving" :ok-text="es ? 'Confirmar' : 'Confirm'" :cancel-text="es ? 'Volver' : 'Back'" :ok-button-props="{ danger: decision.action === 'cancel' }" @ok="sendDecision">
      <p v-if="decision.trip"><b>{{ decision.trip.route }}</b> · {{ time(decision.trip.departure_at) }} · {{ decision.trip.sold }} {{ es ? 'pasajeros' : 'passengers' }}, {{ decision.trip.parcels }} {{ es ? 'encomiendas' : 'parcels' }}</p>
      <a-alert v-if="decision.action === 'reschedule'" type="info" show-icon class="td-note" :message="es ? 'Los pasajeros conservan su boleto en la nueva salida.' : 'Passengers keep their ticket on the new departure.'" />
      <a-alert v-else type="warning" show-icon class="td-note" :message="es ? 'No se hacen devoluciones ni se envían correos: verás la lista de afectados para atenderlos.' : 'No refunds or emails are made: you will see who is affected to attend them.'" />
      <a-form layout="vertical">
        <a-form-item v-if="decision.action === 'reschedule'" :label="es ? 'Nueva salida *' : 'New departure *'">
          <a-date-picker v-model:value="decision.departure_at" show-time format="YYYY-MM-DD HH:mm" value-format="YYYY-MM-DD HH:mm:ss" style="width: 100%" />
        </a-form-item>
        <a-form-item :label="es ? 'Motivo *' : 'Reason *'" style="margin-bottom: 0"><a-textarea v-model:value="decision.note" :rows="2" :maxlength="500" /></a-form-item>
      </a-form>
    </a-modal>

    <a-modal v-model:open="manifestOpen" :title="manifestTitle" :footer="null" :width="720">
      <div class="td-sub">{{ es ? 'Pasajeros' : 'Passengers' }} ({{ manifest.passengers.length }})</div>
      <a-table :columns="passengerColumns" :data-source="manifest.passengers" row-key="id" size="small" :pagination="false" :scroll="{ x: 'max-content', y: 280 }">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'name'">{{ record.last_name }}, {{ record.first_name }}</template>
          <template v-else-if="column.key === 'doc'">{{ String(record.doc_type).toUpperCase() }} {{ record.dni }}</template>
          <template v-else-if="column.key === 'luggage'">{{ record.luggage ? (es ? 'Sí' : 'Yes') : (es ? 'No' : 'No') }}</template>
          <template v-else-if="column.key === 'seat'">{{ record.seat_number ?? '—' }} <a-tag v-if="record.is_vip" color="gold">VIP</a-tag></template>
        </template>
      </a-table>
      <div class="td-sub">{{ es ? 'Encomiendas' : 'Parcels' }} ({{ manifest.parcels.length }})</div>
      <a-table :columns="parcelColumns" :data-source="manifest.parcels" row-key="id" size="small" :pagination="false" :scroll="{ x: 'max-content', y: 220 }" />
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Transport -> Today's departures (2026-11-04): the agency supervisor decides at the start of the
 * day whether each trip runs, is cancelled or is rescheduled. Only the trips leaving from
 * the user's locations; every decision stays on record (TransportOperationsController).
 */
import { computed, onMounted, ref } from 'vue';
import { message } from 'ant-design-vue';
import { ReloadOutlined } from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { useUiStore } from '../../stores/ui';
import { sortByLabel } from '../../lib/filterOptions';

const es = computed(() => useUiStore().locale === 'es');
const STATE = {
  pending: { color: 'gold', en: 'To decide', es: 'Por decidir' },
  confirmed: { color: 'green', en: 'It runs', es: 'Se ejecuta' },
  in_progress: { color: 'orange', en: 'On the road', es: 'En ruta' },
  completed: { color: 'blue', en: 'Completed', es: 'Completado' },
  cancelled: { color: 'default', en: 'Cancelled', es: 'Cancelado' },
};
const stateOf = t => (t.status === 'scheduled' ? (t.confirmed ? 'confirmed' : 'pending') : t.status);
const actionLabel = a => ({ confirm: es.value ? 'Confirmado' : 'Confirmed', cancel: es.value ? 'Cancelado' : 'Cancelled', reschedule: es.value ? 'Reprogramado' : 'Rescheduled' })[a] || a;
const time = d => (d ? dayjs(d).format('HH:mm') : '');
const errorOf = e => { const errs = e?.data?.errors; return (errs && Object.values(errs)[0]?.[0]) || e?.data?.message || e.message; };

const date = ref(dayjs().format('YYYY-MM-DD'));
const warehouseId = ref(undefined);
const rows = ref([]);
const agencies = ref([]);
const loading = ref(false);
const agencyOptions = computed(() => sortByLabel(agencies.value.map(a => ({ value: a.warehouse_id, label: a.name }))));

async function load() {
  loading.value = true;
  try {
    const res = await http.get('transport/today', { date: date.value, warehouse_id: warehouseId.value });
    rows.value = res.trips;
    agencies.value = res.agencies;
  } catch (e) {
    message.error(errorOf(e));
  } finally {
    loading.value = false;
  }
}

async function confirmTrip(t) {
  try {
    await http.post(`transport/trips/${t.id}/decision`, { action: 'confirm' });
    message.success(es.value ? 'Viaje confirmado.' : 'Trip confirmed.');
    load();
  } catch (e) {
    message.error(errorOf(e));
  }
}

const decisionOpen = ref(false);
const saving = ref(false);
const decision = ref({});
function openDecision(t, action) {
  decision.value = { trip: t, action, note: '', departure_at: action === 'reschedule' ? t.departure_at : null };
  decisionOpen.value = true;
}
async function sendDecision() {
  const d = decision.value;
  saving.value = true;
  try {
    const res = await http.post(`transport/trips/${d.trip.id}/decision`, { action: d.action, note: d.note, departure_at: d.departure_at });
    decisionOpen.value = false;
    message.success(es.value ? 'Guardado.' : 'Saved.');
    await load();
    if (d.action === 'cancel' && res.affected) {
      manifest.value = res.affected;
      manifestTitle.value = `${es.value ? 'Afectados por la cancelación' : 'Affected by the cancellation'}: ${d.trip.route}`;
      manifestOpen.value = true;
    }
  } catch (e) {
    message.error(errorOf(e));
  } finally {
    saving.value = false;
  }
}

const manifestOpen = ref(false);
const manifestTitle = ref('');
const manifest = ref({ passengers: [], parcels: [] });
async function openManifest(t) {
  try {
    manifest.value = await http.get(`transport/trips/${t.id}/manifest`);
    manifestTitle.value = `${t.route} · ${time(t.departure_at)}`;
    manifestOpen.value = true;
  } catch (e) {
    message.error(errorOf(e));
  }
}
const passengerColumns = computed(() => [
  { title: es.value ? 'Asiento' : 'Seat', key: 'seat' },
  { title: es.value ? 'Pasajero' : 'Passenger', key: 'name' },
  { title: es.value ? 'Documento' : 'Document', key: 'doc' },
  { title: es.value ? 'Equipaje' : 'Luggage', key: 'luggage' },
]);
const parcelColumns = computed(() => [
  { title: es.value ? 'Guía' : 'Number', dataIndex: 'number', key: 'number' },
  { title: es.value ? 'Remite' : 'Sender', dataIndex: 'sender_name', key: 'sender' },
  { title: es.value ? 'Destinatario' : 'Receiver', dataIndex: 'receiver_name', key: 'receiver' },
  { title: 'm³', dataIndex: 'volume_m3', key: 'volume', align: 'right' },
  { title: 'kg', dataIndex: 'weight_kg', key: 'weight', align: 'right' },
]);

onMounted(load);
</script>

<style scoped>
.td-filters { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 12px; }
.td-list { display: grid; gap: 10px; min-height: 60px; }
.td-trip { display: flex; flex-wrap: wrap; gap: 12px; padding: 12px 14px; border: 1px solid #e2e8f0; border-left: 4px solid #cbd5e1; border-radius: 10px; background: #fff; }
.td-pending { border-left-color: #f59e0b; }
.td-confirmed { border-left-color: #16a34a; }
.td-in_progress { border-left-color: #ea580c; }
.td-completed { border-left-color: #2563eb; }
.td-cancelled { opacity: .65; }
.td-time { font-size: 22px; font-weight: 700; min-width: 70px; }
.td-main { flex: 1 1 240px; min-width: 0; }
.td-route { font-weight: 700; }
.td-muted { font-size: 12px; color: #64748b; }
.td-figures { display: flex; flex-wrap: wrap; gap: 4px 16px; font-size: 13px; margin-top: 4px; }
.td-side { display: flex; flex-direction: column; align-items: flex-end; gap: 8px; }
.td-actions { justify-content: flex-end; }
.td-note { margin-bottom: 12px; }
.td-sub { font-weight: 700; margin: 12px 0 6px; }
@media (max-width: 640px) { .td-side { align-items: flex-start; width: 100%; } }
</style>
