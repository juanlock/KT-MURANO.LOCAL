<template>
  <div class="page">
    <PageHeader :title="s('Departments')" :breadcrumb="[s('Hospital'), s('Departments')]">
      <template #actions>
        <a-button type="primary" @click="openForm(null)">
          <template #icon><PlusOutlined /></template>
          {{ s('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <div class="toolbar">
        <a-input-search
          v-model:value="crud.search.value" :placeholder="s('Search name, code or location…')"
          allow-clear class="tb-search" @search="crud.reload"
        />
        <a-select
          v-model:value="filters.active" class="tb-item" allow-clear :placeholder="s('All')"
          :options="[{ value: '1', label: s('Active') }, { value: '0', label: s('Inactive') }]"
          @change="crud.reload"
        />
      </div>
    </a-card>

    <DataTable :crud="crud" :columns="columns" :searchable="false" selectable>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'name'">
          <div class="cell-name">{{ record.name }}</div>
          <div v-if="record.description" class="cell-sub">{{ record.description }}</div>
        </template>
        <template v-else-if="column.key === 'code'">
          <a-tag v-if="record.code">{{ record.code }}</a-tag>
          <span v-else class="muted">—</span>
        </template>
        <template v-else-if="column.key === 'counts'">
          <a-space :size="4">
            <a-tag>{{ s('{n} doctors', { n: record.doctors_count }) }}</a-tag>
            <a-tag>{{ s('{n} wards', { n: record.wards_count }) }}</a-tag>
          </a-space>
        </template>
        <template v-else-if="column.key === 'is_active'">
          <a-tag :color="record.is_active ? 'success' : 'default'">
            {{ record.is_active ? s('Active') : s('Inactive') }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space :size="0">
            <a-tooltip :title="s('Edit')">
              <a-button type="text" size="small" @click="openForm(record)">
                <template #icon><EditOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="s('Delete')">
              <a-button type="text" size="small" danger @click="crud.remove(record, { label: record.name })">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>

    <a-modal
      :open="formOpen" :title="editing ? s('Edit department') : s('New department')" :width="560"
      :confirm-loading="saving" :ok-text="s('Save')" :cancel-text="s('Cancel')"
      @ok="submit" @cancel="formOpen = false"
    >
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
        <a-row :gutter="16">
          <a-col :xs="24" :md="16">
            <a-form-item :label="s('Name') + ' *'" name="name">
              <a-input v-model:value="form.name" :placeholder="s('e.g. Cardiology')" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="s('Code')">
              <a-input v-model:value="form.code" :placeholder="s('e.g. CARD')" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="s('Location')">
              <a-input v-model:value="form.location" :placeholder="s('e.g. Block B, 2nd floor')" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="s('Extension / phone')">
              <a-input v-model:value="form.phone" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="s('Description')">
              <a-textarea v-model:value="form.description" :rows="2" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item style="margin-bottom: 0">
              <a-checkbox v-model:checked="form.is_active">{{ s('Active') }}</a-checkbox>
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Clinical departments. Deleting one detaches its doctors and wards rather than
 * removing them — the backend enforces that, and the confirmation says so.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import http from '../../lib/http';
import { useHospitalText } from './lang/useHospitalText';
import ES from './lang/HospitalDepartments.es.js';

const { s } = useHospitalText(ES);

const filters = reactive({ active: undefined });

const crud = useCrudTable('hospital/departments', {
  rowsKey: 'departments',
  sortField: 'name',
  sortType: 'asc',
  params: () => ({ active: filters.active || '' }),
});

const columns = computed(() => [
  { title: s('Department'), key: 'name', dataIndex: 'name', sorter: true },
  { title: s('Code'), key: 'code', dataIndex: 'code', sorter: true, width: 120 },
  { title: s('Location'), dataIndex: 'location', key: 'location', width: 200 },
  { title: s('Phone'), dataIndex: 'phone', key: 'phone', width: 140 },
  { title: s('Contains'), key: 'counts', width: 200 },
  { title: s('Status'), key: 'is_active', dataIndex: 'is_active', sorter: true, width: 110 },
  { title: '', key: 'actions', width: 90 },
]);

const formRef = ref();
const formOpen = ref(false);
const saving = ref(false);
const editing = ref(null);
const form = ref(empty());

function empty() {
  return { name: '', code: '', location: '', phone: '', description: '', is_active: true };
}

const rules = computed(() => ({
  name: [{ required: true, message: s('This field is required') }],
}));

function openForm(record) {
  editing.value = record;
  form.value = record
    ? {
        name: record.name,
        code: record.code || '',
        location: record.location || '',
        phone: record.phone || '',
        description: record.description || '',
        is_active: !!record.is_active,
      }
    : empty();
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
    if (editing.value) await http.put(`hospital/departments/${editing.value.id}`, form.value);
    else await http.post('hospital/departments', form.value);
    message.success(s('Saved successfully'));
    formOpen.value = false;
    editing.value = null;
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || s('Could not save this department'));
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
  width: 140px;
}
.cell-name {
  font-weight: 500;
}
.cell-sub {
  font-size: 12px;
  opacity: 0.55;
  max-width: 340px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
