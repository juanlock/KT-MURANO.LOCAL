<template>
  <div class="page">
    <PageHeader title="Departments" :breadcrumb="['Hospital', 'Departments']">
      <template #actions>
        <a-button type="primary" @click="openForm(null)">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <div class="toolbar">
        <a-input-search
          v-model:value="crud.search.value" placeholder="Search name, code or location…"
          allow-clear class="tb-search" @search="crud.reload"
        />
        <a-select
          v-model:value="filters.active" class="tb-item" allow-clear placeholder="All"
          :options="[{ value: '1', label: 'Active' }, { value: '0', label: 'Inactive' }]"
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
            <a-tag>{{ record.doctors_count }} doctors</a-tag>
            <a-tag>{{ record.wards_count }} wards</a-tag>
          </a-space>
        </template>
        <template v-else-if="column.key === 'is_active'">
          <a-tag :color="record.is_active ? 'success' : 'default'">
            {{ record.is_active ? 'Active' : 'Inactive' }}
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
      :open="formOpen" :title="editing ? 'Edit department' : 'New department'" :width="560"
      :confirm-loading="saving" :ok-text="$t('submit')" :cancel-text="$t('Cancel')"
      @ok="submit" @cancel="formOpen = false"
    >
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
        <a-row :gutter="16">
          <a-col :xs="24" :md="16">
            <a-form-item label="Name *" name="name">
              <a-input v-model:value="form.name" placeholder="e.g. Cardiology" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item label="Code">
              <a-input v-model:value="form.code" placeholder="e.g. CARD" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item label="Location">
              <a-input v-model:value="form.location" placeholder="e.g. Block B, 2nd floor" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item label="Extension / phone">
              <a-input v-model:value="form.phone" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="$t('Description')">
              <a-textarea v-model:value="form.description" :rows="2" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item style="margin-bottom: 0">
              <a-checkbox v-model:checked="form.is_active">Active</a-checkbox>
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
import { t } from '../../i18n';

const filters = reactive({ active: undefined });

const crud = useCrudTable('hospital/departments', {
  rowsKey: 'departments',
  sortField: 'name',
  sortType: 'asc',
  params: () => ({ active: filters.active || '' }),
});

const columns = computed(() => [
  { title: 'Department', key: 'name', dataIndex: 'name', sorter: true },
  { title: 'Code', key: 'code', dataIndex: 'code', sorter: true, width: 120 },
  { title: 'Location', dataIndex: 'location', key: 'location', width: 200 },
  { title: 'Phone', dataIndex: 'phone', key: 'phone', width: 140 },
  { title: 'Contains', key: 'counts', width: 200 },
  { title: 'Status', key: 'is_active', dataIndex: 'is_active', sorter: true, width: 110 },
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
  name: [{ required: true, message: t('Field_is_required', 'This field is required') }],
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
    message.success(t('Created_in_successfully', 'Saved successfully'));
    formOpen.value = false;
    editing.value = null;
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData', 'Could not save this department'));
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
