<template>
  <div class="page">
    <PageHeader :title="es ? 'Abordaje' : 'Boarding'" :breadcrumb="[es ? 'Transporte' : 'Transport', es ? 'Abordaje' : 'Boarding']" />

    <a-card size="small" :body-style="{ padding: '12px' }">
      <div class="tb-filters">
        <a-date-picker v-model:value="date" value-format="YYYY-MM-DD" :allow-clear="false" @change="loadTrips" />
        <a-select v-model:value="tripId" :options="trips" show-search option-filter-prop="label" style="min-width: 300px; flex: 1" :placeholder="es ? 'Elija el viaje' : 'Choose the trip'" @change="loadPassengers" />
        <a-button :disabled="!tripId" @click="printAll"><PrinterOutlined /> {{ es ? 'Imprimir boletos' : 'Print passes' }}</a-button>
      </div>

      <div v-if="tripId" class="tb-scan">
        <a-input ref="scanInput" v-model:value="code" size="large" :placeholder="es ? 'Lea el QR del boleto o escriba el código' : 'Read the pass QR or type the code'" allow-clear @press-enter="scan" />
        <a-button size="large" type="primary" :loading="saving" @click="scan">{{ es ? 'Abordar' : 'Board' }}</a-button>
      </div>
      <a-alert v-if="last" :type="last.ok ? 'success' : 'error'" show-icon class="tb-last" :message="last.text" />

      <div v-if="tripId" class="tb-count">
        <b>{{ boarded }}</b> {{ es ? 'a bordo de' : 'on board of' }} <b>{{ rows.length }}</b>
        <a-progress :percent="rows.length ? Math.round((boarded / rows.length) * 100) : 0" size="small" style="max-width: 280px" />
      </div>
      <a-table v-if="tripId" :columns="columns" :data-source="rows" :loading="loading" row-key="id" size="small" :pagination="false" :scroll="{ x: 'max-content' }">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'seat'"><b>{{ record.seat_number ?? '—' }}</b> <a-tag v-if="record.is_vip" color="gold">VIP</a-tag></template>
          <template v-else-if="column.key === 'name'">{{ record.last_name }}, {{ record.first_name }}<div class="tb-muted">{{ record.dni }}</div></template>
          <template v-else-if="column.key === 'leg'">{{ record.from_name }} → {{ record.to_name }}</template>
          <template v-else-if="column.key === 'state'">
            <a-tag v-if="record.boarded_at" color="green">{{ es ? 'A bordo' : 'On board' }} {{ record.boarded_at.slice(11, 16) }}</a-tag>
            <a-tag v-else>{{ es ? 'Falta' : 'Missing' }}</a-tag>
          </template>
          <template v-else-if="column.key === 'actions'">
            <a-space :size="4">
              <a-button v-if="!record.boarded_at" size="small" @click="boardRow(record)">{{ es ? 'Abordar' : 'Board' }}</a-button>
              <a-button size="small" type="text" @click="printOne(record)"><PrinterOutlined /></a-button>
            </a-space>
          </template>
        </template>
      </a-table>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Transport -> Boarding (2026-11-04), as on a plane: the hostess or supervisor reads the QR of each boarding pass at
 * the door. A wrong trip, a pass used twice or an unknown code is refused. A USB / Bluetooth reader types into
 * the box; without one, the code can be typed.
 */
import { computed, nextTick, onMounted, ref } from 'vue';
import { message } from 'ant-design-vue';
import { PrinterOutlined } from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { printBoardingPasses } from '../../lib/transportPrint';
import { useUiStore } from '../../stores/ui';

const es = computed(() => useUiStore().locale === 'es');
const errorOf = e => { const errs = e?.data?.errors; return (errs && Object.values(errs)[0]?.[0]) || e?.data?.message || e.message; };

const date = ref(dayjs().format('YYYY-MM-DD'));
const trips = ref([]);
const tripId = ref(undefined);
const rows = ref([]);
const loading = ref(false);
const saving = ref(false);
const code = ref('');
const last = ref(null);
const scanInput = ref();
const boarded = computed(() => rows.value.filter(r => r.boarded_at).length);

const columns = computed(() => [
  { title: es.value ? 'Asiento' : 'Seat', key: 'seat', width: 110 },
  { title: es.value ? 'Pasajero' : 'Passenger', key: 'name' },
  { title: es.value ? 'Tramo' : 'Stretch', key: 'leg' },
  { title: es.value ? 'Estado' : 'Status', key: 'state' },
  { title: '', key: 'actions', width: 130 },
]);

async function loadTrips() {
  try {
    const res = await http.get('transport/boarding', { date: date.value });
    trips.value = res.trips.map(t => ({ value: t.id, label: t.label }));
    tripId.value = undefined;
    rows.value = [];
    last.value = null;
  } catch (e) {
    message.error(errorOf(e));
  }
}
async function loadPassengers() {
  if (!tripId.value) return;
  loading.value = true;
  try {
    rows.value = (await http.get('transport/boarding', { trip_id: tripId.value })).passengers;
    await nextTick();
    scanInput.value?.focus?.();
  } catch (e) {
    message.error(errorOf(e));
  } finally {
    loading.value = false;
  }
}
async function send(value) {
  saving.value = true;
  try {
    const res = await http.post('transport/boarding', { trip_id: tripId.value, code: value });
    last.value = { ok: true, text: `${res.passenger} · ${es.value ? 'asiento' : 'seat'} ${res.seat ?? '—'}${res.vip ? ' · VIP' : ''}` };
    await loadPassengers();
  } catch (e) {
    last.value = { ok: false, text: errorOf(e) };
  } finally {
    saving.value = false;
  }
}
async function scan() {
  const value = code.value.trim();
  if (!value) return;
  code.value = '';
  await send(value);
}
const boardRow = r => send(r.code);
async function printTickets(params) {
  try {
    const { tickets } = await http.get('transport/tickets', params);
    if (!(await printBoardingPasses(tickets, es.value))) message.warning(es.value ? 'Permita las ventanas emergentes para imprimir.' : 'Allow pop-ups to print.');
  } catch (e) {
    message.error(errorOf(e));
  }
}
const printAll = () => printTickets({ trip_id: tripId.value });
const printOne = r => printTickets({ id: r.id });

onMounted(loadTrips);
</script>

<style scoped>
.tb-filters { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 12px; }
.tb-scan { display: flex; gap: 8px; margin-bottom: 10px; }
.tb-last { margin-bottom: 10px; }
.tb-count { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 10px; }
.tb-muted { font-size: 12px; color: #64748b; }
</style>
