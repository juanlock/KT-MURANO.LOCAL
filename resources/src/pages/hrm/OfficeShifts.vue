<template>
  <div class="page">
    <PageHeader :title="$t('Office_Shift')" :breadcrumb="[$t('HRM'), $t('Office_Shift')]">
      <template #actions>
        <a-button type="primary" @click="openCreate">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <DataTable :crud="crud" :columns="columns" selectable>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'actions'">
          <a-space>
            <a-tooltip :title="$t('Edit')">
              <a-button type="text" size="small" @click="openEdit(record)">
                <template #icon><EditOutlined style="color: #52c41a" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Del')">
              <a-button type="text" size="small" danger @click="crud.remove(record)">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>

    <a-modal
      v-model:open="modalOpen"
      :title="editMode ? $t('Edit') : $t('Add')"
      :confirm-loading="submitting"
      :ok-text="$t('Submit')"
      :cancel-text="$t('Delete_cancelButtonText')"
      width="720px"
      @ok="submit"
    >
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical" style="margin-top: 8px">
        <a-row :gutter="16">
          <a-col :span="12">
            <a-form-item :label="$t('Name')" name="name">
              <a-input v-model:value="form.name" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Company')" name="company_id">
              <a-select v-model:value="form.company_id" show-search option-filter-prop="label" :options="companyOptions" />
            </a-form-item>
          </a-col>
        </a-row>

        <a-divider style="margin: 4px 0 16px">{{ $t('Office_Shift') }}</a-divider>
        <a-row v-for="day in DAYS" :key="day.key" :gutter="16" align="middle" style="margin-bottom: 8px">
          <a-col :span="6"><strong>{{ day.label }}</strong></a-col>
          <a-col :span="9">
            <a-time-picker
              v-model:value="form[day.key + '_in']"
              value-format="HH:mm"
              format="HH:mm"
              style="width: 100%"
              :placeholder="$t('Time_In')"
            />
          </a-col>
          <a-col :span="9">
            <a-time-picker
              v-model:value="form[day.key + '_out']"
              value-format="HH:mm"
              format="HH:mm"
              style="width: 100%"
              :placeholder="$t('Time_Out')"
            />
          </a-col>
        </a-row>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Office shifts: GET office_shift → {office_shifts, totalRows}; JSON payload
 * name + company_id + {day}_in/{day}_out strings ("HH:mm" or empty) for all
 * seven days. Companies from GET office_shift/create. Edit fills from the row.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import http from '../../lib/http';

const { t } = useI18n();
const crud = useCrudTable('office_shift', { rowsKey: 'office_shifts' });

const DAY_KEYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
const DAYS = computed(() =>
  DAY_KEYS.map(key => ({ key, label: key.charAt(0).toUpperCase() + key.slice(1) }))
);

const columns = computed(() => [
  { title: t('Name'), dataIndex: 'name', key: 'name', sorter: true },
  { title: t('Company'), dataIndex: 'company_name', key: 'company_name' },
  { title: t('Action'), key: 'actions', width: 110, align: 'center' },
]);

const companies = ref([]);
const companyOptions = computed(() => companies.value.map(c => ({ value: c.id, label: c.name })));

const modalOpen = ref(false);
const editMode = ref(false);
const submitting = ref(false);
const formRef = ref();

const emptyForm = () => {
  const f = { id: null, name: '', company_id: undefined };
  for (const d of DAY_KEYS) {
    f[`${d}_in`] = null;
    f[`${d}_out`] = null;
  }
  return f;
};
const form = ref(emptyForm());

const rules = computed(() => ({
  name: [{ required: true, message: t('Field_is_required') }],
  company_id: [{ required: true, message: t('Field_is_required') }],
}));

async function loadCompanies() {
  try {
    const data = await http.get('office_shift/create');
    companies.value = data.companies || [];
  } catch (e) { /* select stays empty */ }
}

function openCreate() {
  editMode.value = false;
  form.value = emptyForm();
  modalOpen.value = true;
}

function openEdit(record) {
  editMode.value = true;
  const f = emptyForm();
  f.id = record.id;
  f.name = record.name || '';
  f.company_id = record.company_id;
  for (const d of DAY_KEYS) {
    f[`${d}_in`] = record[`${d}_in`] || null;
    f[`${d}_out`] = record[`${d}_out`] || null;
  }
  form.value = f;
  modalOpen.value = true;
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }
  submitting.value = true;
  const body = { name: form.value.name, company_id: form.value.company_id };
  for (const d of DAY_KEYS) {
    body[`${d}_in`] = form.value[`${d}_in`] || '';
    body[`${d}_out`] = form.value[`${d}_out`] || '';
  }
  try {
    if (editMode.value) {
      await http.put(`office_shift/${form.value.id}`, body);
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('office_shift', body);
      message.success(t('Successfully_Created'));
    }
    modalOpen.value = false;
    crud.fetchRows();
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    submitting.value = false;
  }
}

onMounted(() => {
  crud.fetchRows();
  loadCompanies();
});
</script>
