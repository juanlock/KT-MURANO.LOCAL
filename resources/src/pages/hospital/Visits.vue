<template>
  <div class="page">
    <PageHeader title="Consultations" :breadcrumb="['Hospital', 'Consultations']">
      <template #actions>
        <a-button type="primary" @click="$router.push('/hospital/visits/create')">
          <template #icon><PlusOutlined /></template>
          New consultation
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <div class="toolbar">
        <a-input-search
          v-model:value="crud.search.value" placeholder="Search patient, MRN, diagnosis…"
          allow-clear class="tb-search" @search="crud.reload"
        />
        <a-select
          v-model:value="filters.doctor_id" class="tb-item" allow-clear show-search
          option-filter-prop="label" placeholder="All doctors" :options="doctorOptions" @change="crud.reload"
        />
        <a-select
          v-model:value="filters.department_id" class="tb-item" allow-clear show-search
          option-filter-prop="label" placeholder="All departments" :options="departmentOptions" @change="crud.reload"
        />
        <a-select
          v-model:value="filters.type" class="tb-item-sm" allow-clear
          placeholder="Type" :options="VISIT_TYPES" @change="crud.reload"
        />
        <a-range-picker v-model:value="filters.range" class="tb-range" value-format="YYYY-MM-DD" @change="crud.reload" />
      </div>
    </a-card>

    <DataTable :crud="crud" :columns="columns" :searchable="false" selectable>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'visit_date'">
          <div class="when">{{ dateTime(record.visit_date) }}</div>
          <div class="when-sub">{{ record.reference }}</div>
        </template>
        <template v-else-if="column.key === 'patient'">
          <button type="button" class="link-cell" @click="$router.push(`/hospital/patients/${record.patient_id}`)">
            <div class="cell-name">{{ record.patient_name }}</div>
            <div class="cell-mrn">{{ record.patient_mrn }}</div>
          </button>
        </template>
        <template v-else-if="column.key === 'type'">
          <a-tag :color="optionOf(VISIT_TYPES, record.type).color">{{ labelOf(VISIT_TYPES, record.type) }}</a-tag>
        </template>
        <template v-else-if="column.key === 'vitals'">
          <a-space :size="4">
            <a-tag v-if="record.blood_pressure">BP {{ record.blood_pressure }}</a-tag>
            <a-tag v-if="record.bmi">BMI {{ record.bmi }}</a-tag>
            <span v-if="!record.blood_pressure && !record.bmi" class="muted">—</span>
          </a-space>
        </template>
        <template v-else-if="column.key === 'status'">
          <a-tag :color="optionOf(VISIT_STATUSES, record.status).color">
            {{ labelOf(VISIT_STATUSES, record.status) }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'fee'">{{ money(record.fee) }}</template>
        <template v-else-if="column.key === 'actions'">
          <a-space :size="0">
            <a-tooltip title="Bill this consultation">
              <a-button type="text" size="small" @click="bill(record)">
                <template #icon><DollarOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Edit')">
              <a-button type="text" size="small" @click="$router.push(`/hospital/visits/${record.id}/edit`)">
                <template #icon><EditOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Delete')">
              <a-button type="text" size="small" danger @click="crud.remove(record, { label: record.reference })">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
/**
 * Consultation log. "Bill this consultation" hands the visit to the billing
 * page, which drafts the lines (consultation fee + prescribed medicines) from
 * the record rather than making anyone retype them.
 */
import { reactive, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { PlusOutlined, EditOutlined, DeleteOutlined, DollarOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { VISIT_TYPES, VISIT_STATUSES, labelOf, optionOf } from './hospitalOptions';
import http from '../../lib/http';
import { ref } from 'vue';

const router = useRouter();
const { money, dateTime } = useFormat();

const filters = reactive({
  doctor_id: undefined, department_id: undefined, type: undefined, range: null,
});

const crud = useCrudTable('hospital/visits', {
  rowsKey: 'visits',
  sortField: 'visit_date',
  params: () => ({
    doctor_id: filters.doctor_id || '',
    department_id: filters.department_id || '',
    type: filters.type || '',
    start_date: filters.range?.[0] || '',
    end_date: filters.range?.[1] || '',
  }),
});

const doctors = ref([]);
const departments = ref([]);
const doctorOptions = computed(() => doctors.value.map(d => ({ value: d.id, label: d.name })));
const departmentOptions = computed(() => departments.value.map(d => ({ value: d.id, label: d.name })));

const columns = computed(() => [
  { title: 'When', key: 'visit_date', dataIndex: 'visit_date', sorter: true, width: 190 },
  { title: 'Patient', key: 'patient', dataIndex: 'patient_name' },
  { title: 'Doctor', dataIndex: 'doctor_name', key: 'doctor_name', width: 150 },
  { title: 'Type', key: 'type', dataIndex: 'type', sorter: true, width: 120 },
  { title: 'Diagnosis', dataIndex: 'diagnosis', key: 'diagnosis' },
  { title: 'Vitals', key: 'vitals', width: 170 },
  { title: 'Status', key: 'status', dataIndex: 'status', sorter: true, width: 120 },
  { title: 'Fee', key: 'fee', dataIndex: 'fee', sorter: true, width: 110 },
  { title: '', key: 'actions', width: 120 },
]);

function bill(record) {
  router.push(`/hospital/invoices?draft=visit&id=${record.id}`);
}

onMounted(async () => {
  crud.fetchRows();
  try {
    const meta = await http.get('hospital/meta');
    doctors.value = meta?.doctors || [];
    departments.value = meta?.departments || [];
  } catch (e) { /* selects stay empty */ }
});
</script>

<style scoped>
.muted {
  color: rgba(128, 128, 128, 0.7);
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}
.tb-search {
  flex: 1 1 220px;
  min-width: 180px;
}
.tb-item {
  width: 170px;
}
.tb-item-sm {
  width: 120px;
}
.tb-range {
  width: 240px;
}
.when {
  font-weight: 500;
}
.when-sub {
  font-size: 11px;
  opacity: 0.5;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}
.link-cell {
  border: 0;
  background: none;
  padding: 0;
  cursor: pointer;
  color: inherit;
  font: inherit;
  text-align: left;
}
.cell-name {
  font-weight: 500;
}
.link-cell:hover .cell-name {
  color: #6d28d9;
}
.cell-mrn {
  font-size: 11.5px;
  opacity: 0.55;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}
</style>
