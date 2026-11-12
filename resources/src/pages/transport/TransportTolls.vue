<template>
  <div class="page">
    <PageHeader :title="es ? 'Peajes' : 'Tolls'" :breadcrumb="[es ? 'Transporte' : 'Transport', es ? 'Peajes' : 'Tolls']">
      <template #actions>
        <a-button type="primary" @click="openForm"><PlusOutlined /> {{ es ? 'Registrar peaje' : 'Register a toll' }}</a-button>
      </template>
    </PageHeader>

    <a-card size="small" :body-style="{ padding: '12px' }">
      <div class="tl-filters">
        <PresetRangeFilter v-model:value="range" value-format="YYYY-MM-DD" allow-all @change="reload" />
        <span class="tl-muted">{{ total }} {{ es ? 'registros' : 'records' }} · {{ es ? 'Total' : 'Total' }} <b>{{ money(sum) }}</b></span>
      </div>
      <a-table :columns="columns" :data-source="rows" :loading="loading" row-key="id" size="middle" :scroll="{ x: 'max-content' }"
        :pagination="{ current: page, pageSize: perPage, total, showSizeChanger: true }" @change="onTable">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'trip'">{{ record.route }}<div class="tl-muted">{{ fmt(record.departure_at) }} · {{ record.vehicle }}</div></template>
          <template v-else-if="column.key === 'amount'">{{ money(record.amount) }}</template>
          <template v-else-if="column.key === 'actions'">
            <a-popconfirm :title="es ? '¿Eliminar este peaje?' : 'Delete this toll?'" @confirm="remove(record)">
              <a-button type="text" size="small" danger><DeleteOutlined /></a-button>
            </a-popconfirm>
          </template>
        </template>
      </a-table>
    </a-card>

    <a-modal v-model:open="formOpen" :title="es ? 'Registrar peaje' : 'Register a toll'" :confirm-loading="saving" :ok-text="es ? 'Guardar' : 'Save'" @ok="save">
      <a-form layout="vertical">
        <a-form-item :label="es ? 'Viaje *' : 'Trip *'"><a-select v-model:value="form.trip_id" :options="tripOptions" show-search option-filter-prop="label" /></a-form-item>
        <a-row :gutter="12">
          <a-col :xs="24" :md="12"><a-form-item :label="es ? 'Peaje *' : 'Toll station *'"><a-input v-model:value="form.place" :maxlength="120" /></a-form-item></a-col>
          <a-col :xs="12" :md="6"><a-form-item :label="es ? 'Monto *' : 'Amount *'"><a-input-number v-model:value="form.amount" :min="0.01" style="width: 100%" /></a-form-item></a-col>
          <a-col :xs="12" :md="6"><a-form-item :label="es ? 'Fecha *' : 'Date *'"><a-date-picker v-model:value="form.paid_on" value-format="YYYY-MM-DD" style="width: 100%" /></a-form-item></a-col>
        </a-row>
        <a-form-item :label="es ? 'Nota' : 'Note'" style="margin-bottom: 0"><a-input v-model:value="form.note" :maxlength="255" /></a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Transport -> Tolls (2026-11-04): what each trip really paid. A trip with no toll on record
 * counts its route's expected cost (Routes and locations), flagged in the profit panel.
 */
import { computed, onMounted, ref } from 'vue';
import { message } from 'ant-design-vue';
import { DeleteOutlined, PlusOutlined } from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import PageHeader from '../../components/PageHeader.vue';
import PresetRangeFilter from '../../components/PresetRangeFilter.vue';
import http from '../../lib/http';
import { useFormat } from '../../composables/useFormat';
import { useListState } from '../../composables/useListState';
import { useUiStore } from '../../stores/ui';

const { money } = useFormat();
const es = computed(() => useUiStore().locale === 'es');
const fmt = d => (d ? dayjs(d).format('YYYY-MM-DD HH:mm') : '');
const errorOf = e => { const errs = e?.data?.errors; return (errs && Object.values(errs)[0]?.[0]) || e?.data?.message || e.message; };

const rows = ref([]);
const total = ref(0);
const sum = ref(0);
const loading = ref(false);
const page = ref(1);
const perPage = ref(20);
const range = ref(null);
useListState('transport-tolls', { page, perPage, range });

const columns = computed(() => [
  { title: es.value ? 'Fecha' : 'Date', dataIndex: 'paid_on', key: 'paid_on' },
  { title: es.value ? 'Peaje' : 'Toll station', dataIndex: 'place', key: 'place' },
  { title: es.value ? 'Viaje' : 'Trip', key: 'trip' },
  { title: es.value ? 'Monto' : 'Amount', key: 'amount', align: 'right' },
  { title: es.value ? 'Nota' : 'Note', dataIndex: 'note', key: 'note' },
  { title: '', key: 'actions', width: 60 },
]);

async function load() {
  loading.value = true;
  try {
    const res = await http.get('transport/tolls', { from: range.value?.[0], to: range.value?.[1], page: page.value, limit: perPage.value });
    rows.value = res.tolls;
    total.value = res.totalRows;
    sum.value = res.sum;
  } catch (e) {
    message.error(errorOf(e));
  } finally {
    loading.value = false;
  }
}
function reload() { page.value = 1; load(); }
function onTable(p) { page.value = p.current; perPage.value = p.pageSize; load(); }

const trips = ref([]);
const tripOptions = computed(() => trips.value.map(t => ({ value: t.id, label: `${fmt(t.departure_at)} · ${t.route}${t.vehicle ? ' · ' + t.vehicle : ''}` })));
const formOpen = ref(false);
const saving = ref(false);
const form = ref({});
async function openForm() {
  try {
    trips.value = (await http.get('transport/tolls/trips')).trips;
  } catch (e) {
    message.error(errorOf(e));
    return;
  }
  form.value = { trip_id: undefined, place: '', amount: null, paid_on: dayjs().format('YYYY-MM-DD'), note: '' };
  formOpen.value = true;
}
async function save() {
  saving.value = true;
  try {
    await http.post('transport/tolls', form.value);
    message.success(es.value ? 'Guardado.' : 'Saved.');
    formOpen.value = false;
    load();
  } catch (e) {
    message.error(errorOf(e));
  } finally {
    saving.value = false;
  }
}
async function remove(r) {
  try { await http.delete(`transport/tolls/${r.id}`); load(); } catch (e) { message.error(errorOf(e)); }
}

onMounted(load);
</script>

<style scoped>
.tl-filters { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 12px; }
.tl-muted { font-size: 12px; color: #64748b; }
</style>
