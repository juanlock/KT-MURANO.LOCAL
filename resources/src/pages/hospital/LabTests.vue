<template>
  <div class="page">
    <PageHeader :title="s('Lab Test Catalogue')" :breadcrumb="[s('Hospital'), s('Laboratory'), s('Tests')]">
      <template #actions>
        <a-button @click="$router.push('/hospital/lab-orders')">
          <template #icon><ExperimentOutlined /></template>
          {{ s('Lab orders') }}
        </a-button>
        <a-button type="primary" @click="openForm(null)">
          <template #icon><PlusOutlined /></template>
          {{ s('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <div class="toolbar">
        <a-input-search
          v-model:value="crud.search.value" :placeholder="s('Search test name, code or category…')"
          allow-clear class="tb-search" @search="crud.reload"
        />
        <a-select
          v-model:value="filters.category" class="tb-item" allow-clear show-search
          :placeholder="s('All categories')" :options="categoryOptions" @change="crud.reload"
        />
        <a-select
          v-model:value="filters.active" class="tb-item-sm" allow-clear :placeholder="s('All')"
          :options="[{ value: '1', label: s('Active') }, { value: '0', label: s('Inactive') }]"
          @change="crud.reload"
        />
      </div>
    </a-card>

    <DataTable :crud="crud" :columns="columns" :searchable="false">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'name'">
          <div class="cell-name">{{ record.name }}</div>
          <div v-if="record.code" class="cell-code">{{ record.code }}</div>
        </template>
        <template v-else-if="column.key === 'price'">{{ money(record.price) }}</template>
        <template v-else-if="column.key === 'turnaround_hours'">
          <span v-if="record.turnaround_hours">{{ record.turnaround_hours }}h</span>
          <span v-else class="muted">—</span>
        </template>
        <template v-else-if="column.key === 'is_active'">
          <a-tag :color="record.is_active ? 'success' : 'default'">
            {{ record.is_active ? s('Active') : s('Inactive') }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space :size="0">
            <a-tooltip :title="$t('Edit')">
              <a-button type="text" size="small" @click="openForm(record)">
                <template #icon><EditOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Delete')">
              <a-button type="text" size="small" danger @click="crud.remove(record, { label: record.name })">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>

    <a-modal
      :open="formOpen" :title="editing ? s('Edit test') : s('New test')" :width="620"
      :confirm-loading="saving" :ok-text="$t('submit')" :cancel-text="$t('Cancel')"
      @ok="submit" @cancel="formOpen = false"
    >
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
        <a-alert
          type="info" show-icon banner
          :message="s('Price and reference range are copied onto each order, so changing them here never rewrites past results.')"
          style="margin-bottom: 16px"
        />
        <a-row :gutter="16">
          <a-col :xs="24" :md="14">
            <a-form-item :label="s('Test name *')" name="name">
              <a-input v-model:value="form.name" :placeholder="s('e.g. Complete blood count')" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="10">
            <a-form-item :label="s('Code')">
              <a-input v-model:value="form.code" :placeholder="s('e.g. CBC')" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item :label="s('Category')">
              <a-auto-complete
                v-model:value="form.category" :options="categoryOptions"
                :placeholder="s('e.g. Haematology')" allow-clear
              />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item :label="s('Sample type')">
              <a-input v-model:value="form.sample_type" :placeholder="s('e.g. Blood')" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item :label="s('Unit')">
              <a-input v-model:value="form.unit" :placeholder="s('e.g. g/dL')" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="s('Normal range')">
              <a-input v-model:value="form.normal_range" :placeholder="s('e.g. 13.5–17.5')" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item :label="s('Price')">
              <a-input-number v-model:value="form.price" :min="0" :step="1" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item :label="s('Turnaround (hours)')">
              <a-input-number v-model:value="form.turnaround_hours" :min="0" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item style="margin-bottom: 0">
              <a-checkbox v-model:checked="form.is_active">{{ s('Available to order') }}</a-checkbox>
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Laboratory catalogue — the tests that can be ordered, their price and their
 * reference range.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { PlusOutlined, EditOutlined, DeleteOutlined, ExperimentOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';
import { t } from '../../i18n';
import { useHospitalText } from './lang/useHospitalText';
import ES from './lang/LabTests.es.js';

const { s } = useHospitalText(ES);

const { money } = useFormat();
const filters = reactive({ category: undefined, active: undefined });

const crud = useCrudTable('hospital/lab-tests', {
  rowsKey: 'lab_tests',
  sortField: 'name',
  sortType: 'asc',
  params: () => ({ category: filters.category || '', active: filters.active || '' }),
});

const categoryOptions = computed(() =>
  (crud.payload.value?.categories || []).map(c => ({ value: c, label: c })));

const columns = computed(() => [
  { title: s('Test'), key: 'name', dataIndex: 'name', sorter: true },
  { title: s('Category'), dataIndex: 'category', key: 'category', sorter: true, width: 160 },
  { title: s('Sample'), dataIndex: 'sample_type', key: 'sample_type', width: 130 },
  { title: s('Unit'), dataIndex: 'unit', key: 'unit', width: 100 },
  { title: s('Normal range'), dataIndex: 'normal_range', key: 'normal_range', width: 150 },
  { title: s('Turnaround'), key: 'turnaround_hours', dataIndex: 'turnaround_hours', width: 120 },
  { title: s('Price'), key: 'price', dataIndex: 'price', sorter: true, width: 120 },
  { title: s('Status'), key: 'is_active', dataIndex: 'is_active', sorter: true, width: 110 },
  { title: '', key: 'actions', width: 90 },
]);

const formRef = ref();
const formOpen = ref(false);
const saving = ref(false);
const editing = ref(null);
const form = ref(empty());

function empty() {
  return {
    name: '', code: '', category: undefined, sample_type: '', unit: '',
    normal_range: '', price: null, turnaround_hours: null, is_active: true,
  };
}

const rules = computed(() => ({
  name: [{ required: true, message: t('Field_is_required', s('This field is required')) }],
}));

function openForm(record) {
  editing.value = record;
  form.value = record ? { ...empty(), ...record, is_active: !!record.is_active } : empty();
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
    if (editing.value) await http.put(`hospital/lab-tests/${editing.value.id}`, form.value);
    else await http.post('hospital/lab-tests', form.value);
    message.success(t('Created_in_successfully', s('Saved successfully')));
    formOpen.value = false;
    editing.value = null;
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData', s('Could not save this test')));
  } finally {
    saving.value = false;
  }
}

onMounted(crud.fetchRows);
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
  flex: 1 1 240px;
  min-width: 200px;
}
.tb-item {
  width: 170px;
}
.tb-item-sm {
  width: 120px;
}
.cell-name {
  font-weight: 500;
}
.cell-code {
  font-size: 11.5px;
  opacity: 0.55;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}
</style>
