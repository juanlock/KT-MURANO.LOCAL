<template>
  <div class="page">
    <PageHeader title="Lab Orders" :breadcrumb="['Hospital', 'Laboratory', 'Orders']">
      <template #actions>
        <a-button @click="$router.push('/hospital/lab-tests')">
          <template #icon><UnorderedListOutlined /></template>
          Catalogue
        </a-button>
        <a-button type="primary" @click="openForm(null)">
          <template #icon><PlusOutlined /></template>
          New order
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <div class="toolbar">
        <a-input-search
          v-model:value="crud.search.value" placeholder="Search patient, MRN or reference…"
          allow-clear class="tb-search" @search="crud.reload"
        />
        <a-select
          v-model:value="filters.status" class="tb-item" allow-clear
          placeholder="All statuses" :options="LAB_STATUSES" @change="crud.reload"
        />
        <a-select
          v-model:value="filters.priority" class="tb-item-sm" allow-clear
          placeholder="Priority" :options="LAB_PRIORITIES" @change="crud.reload"
        />
        <a-range-picker v-model:value="filters.range" class="tb-range" value-format="YYYY-MM-DD" @change="crud.reload" />
        <a-checkbox v-model:checked="filters.pending" class="tb-check" @change="crud.reload">
          Pending only
        </a-checkbox>
      </div>
    </a-card>

    <DataTable :crud="crud" :columns="columns" :searchable="false" selectable>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'patient'">
          <button type="button" class="link-cell" @click="$router.push(`/hospital/patients/${record.patient_id}`)">
            <div class="cell-name">{{ record.patient_name }}</div>
            <div class="cell-mrn">{{ record.patient_mrn }} · {{ record.reference }}</div>
          </button>
        </template>
        <template v-else-if="column.key === 'ordered_at'">{{ dateTime(record.ordered_at) }}</template>
        <template v-else-if="column.key === 'tests'">
          <a-badge :count="record.test_count" :number-style="{ backgroundColor: '#6d28d9' }" />
          <a-tag v-if="record.abnormal_count" color="error" class="abn">
            {{ record.abnormal_count }} abnormal
          </a-tag>
        </template>
        <template v-else-if="column.key === 'priority'">
          <a-tag :color="optionOf(LAB_PRIORITIES, record.priority).color">
            {{ labelOf(LAB_PRIORITIES, record.priority) }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'status'">
          <a-tag :color="optionOf(LAB_STATUSES, record.status).color">
            {{ labelOf(LAB_STATUSES, record.status) }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'total'">{{ money(record.total) }}</template>
        <template v-else-if="column.key === 'actions'">
          <a-space :size="0">
            <a-tooltip title="Results">
              <a-button type="text" size="small" @click="openResults(record)">
                <template #icon><FileTextOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip title="Bill these tests">
              <a-button type="text" size="small" @click="bill(record)">
                <template #icon><DollarOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Edit')">
              <a-button type="text" size="small" @click="openForm(record)">
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

    <!-- Order form -->
    <a-modal
      :open="formOpen" :title="editing ? 'Edit order' : 'New lab order'" :width="620"
      :confirm-loading="saving" :ok-text="$t('submit')" :cancel-text="$t('Cancel')"
      @ok="submit" @cancel="formOpen = false"
    >
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
        <a-form-item label="Patient *" name="patient_id">
          <PatientPicker v-model="form.patient_id" :initial-option="editingPatient" />
        </a-form-item>
        <a-row :gutter="16">
          <a-col :xs="24" :md="12">
            <a-form-item label="Requested by">
              <a-select
                v-model:value="form.doctor_id" allow-clear show-search option-filter-prop="label"
                :options="doctorOptions" placeholder="Select"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-form-item label="Ordered at">
              <a-date-picker
                v-model:value="form.ordered_at" show-time style="width: 100%"
                value-format="YYYY-MM-DD HH:mm:ss"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-form-item label="Priority">
              <a-select v-model:value="form.priority" :options="LAB_PRIORITIES" />
            </a-form-item>
          </a-col>
        </a-row>
        <a-form-item label="Tests *" name="test_ids">
          <a-select
            v-model:value="form.test_ids" mode="multiple" show-search option-filter-prop="label"
            :options="testOptions" placeholder="Pick the tests to run"
          />
        </a-form-item>
        <div class="order-total">
          Estimated total: <b>{{ money(estimate) }}</b>
        </div>
        <a-form-item :label="$t('Note')" style="margin-bottom: 0">
          <a-textarea v-model:value="form.notes" :rows="2" />
        </a-form-item>
      </a-form>
    </a-modal>

    <!-- Results -->
    <a-drawer :open="resultsOpen" :width="720" :title="`Results — ${current?.reference || ''}`" @close="resultsOpen = false">
      <template #extra>
        <a-space>
          <a-select v-model:value="resultStatus" :options="LAB_STATUSES" style="width: 170px" />
          <a-button type="primary" :loading="savingResults" @click="saveResults">{{ $t('submit') }}</a-button>
        </a-space>
      </template>

      <a-descriptions v-if="current" :column="2" size="small" bordered style="margin-bottom: 16px">
        <a-descriptions-item label="Patient">{{ current.patient_name }}</a-descriptions-item>
        <a-descriptions-item label="MRN">{{ current.patient_mrn }}</a-descriptions-item>
        <a-descriptions-item label="Ordered">{{ dateTime(current.ordered_at) }}</a-descriptions-item>
        <a-descriptions-item label="Priority">{{ labelOf(LAB_PRIORITIES, current.priority) }}</a-descriptions-item>
      </a-descriptions>

      <div v-for="item in resultItems" :key="item.id" class="res">
        <div class="res-head">
          <span class="res-name">{{ item.test_name }}</span>
          <span v-if="item.normal_range" class="res-range">Normal: {{ item.normal_range }}{{ item.unit ? ` ${item.unit}` : '' }}</span>
        </div>
        <div class="res-inputs">
          <a-input v-model:value="item.result_value" placeholder="Result" class="res-value" />
          <a-select v-model:value="item.flag" :options="RESULT_FLAGS" placeholder="Flag" allow-clear class="res-flag" />
          <a-input v-model:value="item.remarks" placeholder="Remarks" class="res-remarks" />
        </div>
      </div>
      <a-empty v-if="!resultItems.length" description="No tests on this order" />
    </a-drawer>
  </div>
</template>

<script setup>
/**
 * Lab orders and result entry.
 *
 * Prices and reference ranges are snapshotted onto the order when it is raised,
 * so editing the catalogue later never rewrites a result that has already been
 * reported or a bill that has already been sent.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import {
  PlusOutlined, EditOutlined, DeleteOutlined, DollarOutlined,
  FileTextOutlined, UnorderedListOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import PatientPicker from './PatientPicker.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { LAB_STATUSES, LAB_PRIORITIES, RESULT_FLAGS, labelOf, optionOf } from './hospitalOptions';
import http from '../../lib/http';
import { useRoute } from 'vue-router';
import { t } from '../../i18n';

const route = useRoute();
const router = useRouter();
const { money, dateTime } = useFormat();

const filters = reactive({
  status: undefined, priority: undefined, range: null, pending: route.query.pending === '1',
});

const crud = useCrudTable('hospital/lab-orders', {
  rowsKey: 'lab_orders',
  sortField: 'ordered_at',
  params: () => ({
    status: filters.status || '',
    priority: filters.priority || '',
    pending: filters.pending ? 1 : '',
    start_date: filters.range?.[0] || '',
    end_date: filters.range?.[1] || '',
  }),
});

const columns = computed(() => [
  { title: 'Patient', key: 'patient', dataIndex: 'patient_name' },
  { title: 'Ordered', key: 'ordered_at', dataIndex: 'ordered_at', sorter: true, width: 175 },
  { title: 'Doctor', dataIndex: 'doctor_name', key: 'doctor_name', width: 150 },
  { title: 'Tests', key: 'tests', width: 150 },
  { title: 'Priority', key: 'priority', dataIndex: 'priority', sorter: true, width: 120 },
  { title: 'Status', key: 'status', dataIndex: 'status', sorter: true, width: 160 },
  { title: 'Total', key: 'total', dataIndex: 'total', sorter: true, width: 120 },
  { title: '', key: 'actions', width: 150 },
]);

const doctors = ref([]);
const tests = ref([]);
const doctorOptions = computed(() => doctors.value.map(d => ({ value: d.id, label: d.name })));
const testOptions = computed(() => tests.value.map(x => ({
  value: x.id,
  label: x.category ? `${x.name} · ${x.category}` : x.name,
})));

// ---------------- order form ----------------

const formRef = ref();
const formOpen = ref(false);
const saving = ref(false);
const editing = ref(null);
const editingPatient = ref(null);
const form = ref(empty());

function stamp(d = new Date()) {
  const pad = n => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:00`;
}

function empty() {
  return {
    patient_id: undefined, doctor_id: undefined, ordered_at: stamp(),
    priority: 'routine', test_ids: [], notes: '',
  };
}

const required = () => [{ required: true, message: t('Field_is_required', 'This field is required') }];
const rules = computed(() => ({
  patient_id: required(),
  test_ids: [{ required: true, type: 'array', min: 1, message: 'Pick at least one test' }],
}));

const estimate = computed(() =>
  (form.value.test_ids || []).reduce((sum, id) => {
    const test = tests.value.find(x => x.id === id);
    return sum + (test ? Number(test.price) || 0 : 0);
  }, 0));

async function openForm(record) {
  editing.value = record;
  editingPatient.value = record
    ? { id: record.patient_id, name: record.patient_name, mrn: record.patient_mrn }
    : null;

  if (record) {
    // The list rows omit items; fetch the order to know which tests it holds.
    try {
      const data = await http.get(`hospital/lab-orders/${record.id}`);
      const o = data?.lab_order;
      form.value = {
        patient_id: o.patient_id,
        doctor_id: o.doctor_id || undefined,
        ordered_at: o.ordered_at ? stamp(new Date(o.ordered_at)) : stamp(),
        priority: o.priority,
        test_ids: (o.items || []).map(i => i.lab_test_id),
        notes: o.notes || '',
      };
    } catch (e) {
      message.error(t('InvalidData', 'Could not load this order'));
      return;
    }
  } else {
    form.value = empty();
  }

  formOpen.value = true;
  formRef.value?.clearValidate?.();
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }

  saving.value = true;
  try {
    if (editing.value) await http.put(`hospital/lab-orders/${editing.value.id}`, form.value);
    else await http.post('hospital/lab-orders', form.value);
    message.success(t('Created_in_successfully', 'Saved successfully'));
    formOpen.value = false;
    editing.value = null;
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData', 'Could not save this order'));
  } finally {
    saving.value = false;
  }
}

// ---------------- results ----------------

const resultsOpen = ref(false);
const savingResults = ref(false);
const current = ref(null);
const resultItems = ref([]);
const resultStatus = ref('completed');

async function openResults(record) {
  try {
    const data = await http.get(`hospital/lab-orders/${record.id}`);
    current.value = data?.lab_order || null;
    resultItems.value = (current.value?.items || []).map(i => ({ ...i }));
    resultStatus.value = current.value?.status === 'cancelled' ? 'cancelled' : 'completed';
    resultsOpen.value = true;
  } catch (e) {
    message.error(t('InvalidData', 'Could not load this order'));
  }
}

async function saveResults() {
  savingResults.value = true;
  try {
    await http.post(`hospital/lab-orders/${current.value.id}/results`, {
      status: resultStatus.value,
      results: resultItems.value.map(i => ({
        id: i.id,
        result_value: i.result_value,
        flag: i.flag,
        remarks: i.remarks,
      })),
    });
    message.success('Results saved.');
    resultsOpen.value = false;
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData', 'Could not save the results'));
  } finally {
    savingResults.value = false;
  }
}

function bill(record) {
  router.push(`/hospital/invoices?draft=lab_order&id=${record.id}`);
}

onMounted(async () => {
  crud.fetchRows();
  try {
    const meta = await http.get('hospital/meta');
    doctors.value = meta?.doctors || [];
    tests.value = meta?.lab_tests || [];
  } catch (e) { /* selects stay empty */ }
});
</script>

<style scoped>
.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
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
  width: 130px;
}
.tb-range {
  width: 240px;
}
.tb-check {
  white-space: nowrap;
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
.abn {
  margin-inline-start: 8px;
}
.order-total {
  margin-bottom: 16px;
  padding: 8px 12px;
  border-radius: 9px;
  background: rgba(109, 40, 217, 0.09);
  font-size: 13px;
}
.res {
  padding: 12px 0;
  border-bottom: 1px solid rgba(128, 128, 128, 0.15);
}
.res-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 6px;
}
.res-name {
  font-weight: 500;
}
.res-range {
  font-size: 12px;
  opacity: 0.6;
  white-space: nowrap;
}
.res-inputs {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}
.res-value {
  flex: 1 1 130px;
}
.res-flag {
  width: 130px;
  flex: none;
}
.res-remarks {
  flex: 2 1 180px;
}
</style>
