<template>
  <div class="page">
    <PageHeader :title="$t('Holidays')" :breadcrumb="[$t('HRM'), $t('Holidays')]">
      <template #actions>
        <a-button type="primary" @click="openCreate">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <DataTable :crud="crud" :columns="columns" selectable>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'start_date'">{{ record.start_date ? date(record.start_date) : '—' }}</template>
        <template v-else-if="column.key === 'end_date'">{{ record.end_date ? date(record.end_date) : '—' }}</template>
        <template v-else-if="column.key === 'actions'">
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
      @ok="submit"
    >
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical" style="margin-top: 8px">
        <a-form-item :label="$t('Title')" name="title">
          <a-input v-model:value="form.title" />
        </a-form-item>
        <a-form-item :label="$t('Company')" name="company_id">
          <a-select v-model:value="form.company_id" show-search option-filter-prop="label" :options="companyOptions" />
        </a-form-item>
        <a-form-item :label="$t('date')" name="range">
          <a-range-picker v-model:value="form.range" style="width: 100%" />
        </a-form-item>
        <a-form-item :label="$t('Description')" name="description">
          <a-textarea v-model:value="form.description" :rows="3" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Holidays: list GET holiday → {holidays, totalRows}; JSON {company_id, title,
 * start_date, end_date, description}; companies from GET holiday/create (or
 * {id}/edit). Edit populates from the row.
 */
import { ref, computed, onMounted } from 'vue';
import dayjs from 'dayjs';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';

const { t } = useI18n();
const { date } = useFormat();
const crud = useCrudTable('holiday', { rowsKey: 'holidays' });

const columns = computed(() => [
  { title: t('Title'), dataIndex: 'title', key: 'title', sorter: true },
  { title: t('Company'), dataIndex: 'company_name', key: 'company_name' },
  { title: t('start_date'), key: 'start_date', dataIndex: 'start_date', sorter: true, exportValue: r => (r.start_date ? date(r.start_date) : '') },
  { title: t('Finish_Date'), key: 'end_date', dataIndex: 'end_date', sorter: true, exportValue: r => (r.end_date ? date(r.end_date) : '') },
  { title: t('Action'), key: 'actions', width: 110, align: 'center' },
]);

const companies = ref([]);
const companyOptions = computed(() => companies.value.map(c => ({ value: c.id, label: c.name })));

const modalOpen = ref(false);
const editMode = ref(false);
const submitting = ref(false);
const formRef = ref();
const emptyForm = () => ({ id: null, title: '', company_id: undefined, range: null, description: '' });
const form = ref(emptyForm());

const rules = computed(() => ({
  title: [{ required: true, message: t('Field_is_required') }],
  company_id: [{ required: true, message: t('Field_is_required') }],
  range: [{ required: true, message: t('Field_is_required') }],
}));

async function loadCompanies() {
  try {
    const data = await http.get('holiday/create');
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
  form.value = {
    id: record.id,
    title: record.title || '',
    company_id: record.company_id,
    range: record.start_date && record.end_date ? [dayjs(record.start_date), dayjs(record.end_date)] : null,
    description: record.description || '',
  };
  modalOpen.value = true;
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }
  submitting.value = true;
  const body = {
    company_id: form.value.company_id,
    title: form.value.title,
    start_date: form.value.range?.[0]?.format?.('YYYY-MM-DD') || '',
    end_date: form.value.range?.[1]?.format?.('YYYY-MM-DD') || '',
    description: form.value.description,
  };
  try {
    if (editMode.value) {
      await http.put(`holiday/${form.value.id}`, body);
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('holiday', body);
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
