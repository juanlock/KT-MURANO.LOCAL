<template>
  <div class="page">
    <PageHeader :title="es ? 'Personal activo' : 'Active staff'" :breadcrumb="[es ? 'Transporte' : 'Transport', es ? 'Personal activo' : 'Active staff']" />

    <div class="ts-cards">
      <div v-for="c in cards" :key="c.key" class="ts-card" :class="{ on: type === c.key }" @click="type = type === c.key ? null : c.key">
        <div class="ts-n">{{ c.n }}</div>
        <div class="ts-l">{{ c.label }}</div>
        <div class="ts-s">{{ c.sub }}</div>
      </div>
    </div>

    <a-card size="small" :body-style="{ padding: '12px' }">
      <div class="ts-filters">
        <PresetRangeFilter v-model:value="range" value-format="YYYY-MM-DD" @change="load" />
        <a-input-search v-model:value="q" allow-clear style="max-width: 240px" :placeholder="es ? 'Buscar' : 'Search'" />
        <span class="ts-muted">{{ es ? 'Viajes y pago: viajes completados en el período.' : 'Trips and pay: trips completed in the period.' }}</span>
      </div>
      <a-table :columns="columns" :data-source="shown" row-key="id" size="middle" :loading="loading" :scroll="{ x: 'max-content' }" :pagination="{ pageSize: 25, hideOnSinglePage: true }">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'name'"><b>{{ record.name }}</b><div class="ts-muted">{{ record.designation }}</div></template>
          <template v-else-if="column.key === 'type'"><a-tag :color="TYPE_COLOR[record.staff_type]">{{ typeLabel(record.staff_type) }}</a-tag></template>
          <template v-else-if="column.key === 'schedule'">
            <span v-if="isCrew(record)">{{ es ? 'Por viaje' : 'Per trip' }}<span v-if="record.trip_rate != null" class="ts-muted"> · {{ money(record.trip_rate) }}</span></span>
            <span v-else>{{ record.shift || (es ? 'Horario normal' : 'Normal schedule') }}</span>
          </template>
          <template v-else-if="column.key === 'now'">
            <a-tag v-if="record.on_trip" color="orange">{{ es ? 'En viaje' : 'On a trip' }}: {{ record.on_trip }}</a-tag>
            <span v-else-if="record.next_trip" class="ts-muted">{{ es ? 'Próximo' : 'Next' }}: {{ fmt(record.next_trip) }}</span>
            <span v-else-if="isCrew(record)" class="ts-muted">{{ es ? 'Disponible' : 'Available' }}</span>
          </template>
          <template v-else-if="column.key === 'trips'">{{ isCrew(record) ? record.trips : '—' }}</template>
          <template v-else-if="column.key === 'pay'">{{ isCrew(record) ? money(record.trip_pay) : '—' }}</template>
        </template>
      </a-table>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Transport -> Active staff (2026-11-01): the company's active employees by type --
 * administrative and maintenance on the normal schedule (their shift), pilots and
 * hostesses paid per trip: on a trip right now, next trip, trips completed and their
 * pay in the period (the weekly payroll pays the same, HRM -> Payroll).
 * Staff type and pay per trip are set in HRM -> Employees.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import dayjs from 'dayjs';
import PageHeader from '../../components/PageHeader.vue';
import PresetRangeFilter from '../../components/PresetRangeFilter.vue';
import http from '../../lib/http';
import { useUiStore } from '../../stores/ui';
import { useFormat } from '../../composables/useFormat';

const es = computed(() => useUiStore().locale === 'es');
const { money } = useFormat();
const TYPES = [['administrative', 'Administrative', 'Administrativo'], ['maintenance', 'Maintenance', 'Mantenimiento'], ['pilot', 'Pilots', 'Pilotos'], ['hostess', 'Hostesses', 'Azafatas']];
const TYPE_COLOR = { administrative: 'default', maintenance: 'gold', pilot: 'blue', hostess: 'magenta' };
const typeLabel = t => { const x = TYPES.find(r => r[0] === t); return x ? (es.value ? x[2] : x[1]) : t; };
const isCrew = r => ['pilot', 'hostess'].includes(r.staff_type);
const fmt = d => (d ? dayjs(d).format('DD-MM HH:mm') : '');

const range = ref([dayjs().startOf('month').format('YYYY-MM-DD'), dayjs().format('YYYY-MM-DD')]);
const staff = ref([]);
const loading = ref(false);
const q = ref('');
const type = ref(null);

const cards = computed(() => TYPES.map(([key, en, sp]) => {
  const rows = staff.value.filter(s => s.staff_type === key);
  const crew = ['pilot', 'hostess'].includes(key);
  return {
    key, n: rows.length, label: es.value ? sp : en,
    sub: crew ? `${rows.filter(r => r.on_trip).length} ${es.value ? 'en viaje' : 'on a trip'} · ${rows.reduce((a, r) => a + r.trips, 0)} ${es.value ? 'viajes' : 'trips'}`
      : (es.value ? 'Horario normal' : 'Normal schedule'),
  };
}));
const shown = computed(() => {
  const t = q.value.trim().toLowerCase();
  return staff.value.filter(s => (!type.value || s.staff_type === type.value) && (!t || s.name.toLowerCase().includes(t)));
});
const columns = computed(() => [
  { title: es.value ? 'Nombre' : 'Name', key: 'name' },
  { title: es.value ? 'Tipo' : 'Type', key: 'type', width: 130 },
  { title: es.value ? 'Horario / pago' : 'Schedule / pay', key: 'schedule' },
  { title: es.value ? 'Ahora' : 'Now', key: 'now' },
  { title: es.value ? 'Viajes' : 'Trips', key: 'trips', align: 'right', width: 80 },
  { title: es.value ? 'Pago por viajes' : 'Trip pay', key: 'pay', align: 'right', width: 120 },
  { title: es.value ? 'Teléfono' : 'Phone', dataIndex: 'phone', key: 'phone' },
]);

async function load() {
  loading.value = true;
  try {
    const res = await http.get('transport/staff', { from: range.value?.[0], to: range.value?.[1] });
    staff.value = res.staff;
  } catch (e) {
    message.error(e?.data?.message || e.message);
  } finally {
    loading.value = false;
  }
}
onMounted(load);
</script>

<style scoped>
.ts-cards { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 12px; }
@media (max-width: 900px) { .ts-cards { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
.ts-card { border: 1px solid rgba(128, 128, 128, 0.2); border-radius: 10px; padding: 12px 14px; cursor: pointer; background: rgba(128, 128, 128, 0.03); }
.ts-card.on { border-color: #7c3aed; background: rgba(124, 58, 237, 0.07); }
.ts-n { font-size: 26px; font-weight: 700; line-height: 1.2; }
.ts-l { font-weight: 600; }
.ts-s, .ts-muted { opacity: 0.6; font-size: 12px; }
.ts-filters { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 12px; }
</style>
