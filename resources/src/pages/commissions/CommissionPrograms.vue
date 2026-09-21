<template>
  <div class="page">
    <PageHeader :title="$t('Commission_Programs')" :breadcrumb="[$t('Commissions'), $t('Commission_Programs')]">
      <template #extra>
        <a-button v-if="auth.can('commissions_add')" type="primary" @click="openModal()">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'is_active'">
          <a-tag :color="record.is_active ? 'success' : 'default'">
            {{ record.is_active ? $t('Active') : $t('Inactive') }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'valid_from'">
          {{ record.valid_from ? formatDate(record.valid_from) : '—' }}
        </template>
        <template v-else-if="column.key === 'valid_to'">
          {{ record.valid_to ? formatDate(record.valid_to) : '—' }}
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space>
            <a-button v-if="auth.can('commissions_edit')" size="small" @click="openModal(record)">
              <template #icon><EditOutlined /></template>
            </a-button>
            <a-button v-if="auth.can('commissions_delete')" size="small" danger @click="crud.remove(record)">
              <template #icon><DeleteOutlined /></template>
            </a-button>
          </a-space>
        </template>
      </template>
    </DataTable>

    <a-modal
      v-model:open="modalOpen" :title="editMode ? $t('Edit') : $t('Add')"
      :confirm-loading="saving" @ok="submit"
    >
      <a-form ref="modalFormRef" :model="form" :rules="rules" layout="vertical" style="margin-top: 12px">
        <a-form-item :label="$t('Name') + ' *'" name="name">
          <a-input v-model:value="form.name" :maxlength="192" />
        </a-form-item>
        <a-form-item :label="$t('Description')">
          <a-textarea v-model:value="form.description" :rows="2" :maxlength="500" />
        </a-form-item>
        <a-form-item>
          <a-checkbox v-model:checked="form.is_active">{{ $t('Active') }}</a-checkbox>
        </a-form-item>
        <a-row :gutter="16">
          <a-col :span="12">
            <a-form-item :label="$t('Valid_From')">
              <a-input v-model:value="form.valid_from" type="date" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Valid_To')">
              <a-input v-model:value="form.valid_to" type="date" />
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Commission programs — GET commission_programs (payload may be wrapped in
 * {data}, legacy unwraps with data.data || data) → {programs, totalRows};
 * save POST/PUT commission_programs[/{id}] {name, description, is_active,
 * valid_from|null, valid_to|null}. Buttons gated commissions_add/edit/delete.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';

const { t } = useI18n();
const auth = useAuthStore();

const crud = useCrudTable('commission_programs', {
  select: p => {
    const d = p.data || p;
    return { rows: d.programs || [], total: d.totalRows || 0 };
  },
});

const modalOpen = ref(false);
const editMode = ref(false);
const saving = ref(false);
const modalFormRef = ref();
const form = ref({ id: null, name: '', description: '', is_active: true, valid_from: '', valid_to: '' });

const rules = computed(() => ({
  name: [{ required: true, message: t('Field_is_required') }],
}));

const columns = computed(() => [
  { title: t('Name'), dataIndex: 'name', key: 'name', sorter: true },
  { title: t('Description'), dataIndex: 'description', key: 'description' },
  { title: t('Active'), key: 'is_active', width: 100 },
  { title: t('Valid_From'), key: 'valid_from' },
  { title: t('Valid_To'), key: 'valid_to' },
  { title: t('Rules'), dataIndex: 'commission_rules_count', key: 'commission_rules_count', align: 'center' },
  { title: t('Action'), key: 'actions', width: 100 },
]);

function formatDate(v) {
  return v ? new Date(v).toLocaleDateString() : '—';
}
function openModal(row = null) {
  editMode.value = !!row;
  form.value = row
    ? {
        id: row.id,
        name: row.name,
        description: row.description || '',
        is_active: !!row.is_active,
        valid_from: row.valid_from ? row.valid_from.slice(0, 10) : '',
        valid_to: row.valid_to ? row.valid_to.slice(0, 10) : '',
      }
    : { id: null, name: '', description: '', is_active: true, valid_from: '', valid_to: '' };
  modalFormRef.value?.clearValidate();
  modalOpen.value = true;
}
async function submit() {
  try {
    await modalFormRef.value.validate();
  } catch (e) {
    return;
  }
  saving.value = true;
  const payload = {
    name: form.value.name,
    description: form.value.description,
    is_active: form.value.is_active,
    valid_from: form.value.valid_from || null,
    valid_to: form.value.valid_to || null,
  };
  try {
    if (editMode.value) await http.put(`commission_programs/${form.value.id}`, payload);
    else await http.post('commission_programs', payload);
    message.success(t('Success'));
    modalOpen.value = false;
    await crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('Error'));
  } finally {
    saving.value = false;
  }
}

onMounted(crud.fetchRows);
</script>
