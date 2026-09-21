<template>
  <div class="page">
    <PageHeader :title="$t('Service_Technicians')" :breadcrumb="[$t('Service_Maintenance'), $t('Service_Technicians')]" />

    <a-row :gutter="16">
      <a-col :xs="24" :md="8">
        <a-card size="small" :title="$t('Technician_Details')">
          <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
            <a-form-item :label="$t('Name') + ' *'" name="name">
              <a-input v-model:value="form.name" />
            </a-form-item>
            <a-form-item :label="$t('Phone')">
              <a-input v-model:value="form.phone" />
            </a-form-item>
            <a-form-item :label="$t('Email')">
              <a-input v-model:value="form.email" type="email" />
            </a-form-item>
            <a-form-item :label="$t('Note')">
              <a-textarea v-model:value="form.notes" :rows="2" />
            </a-form-item>
            <a-form-item :label="$t('Status')">
              <a-switch v-model:checked="form.is_active" />
              <span style="margin-left: 8px">{{ form.is_active ? $t('Actif') : $t('Inactif') }}</span>
            </a-form-item>
            <a-space>
              <a-button type="primary" :loading="saving" @click="saveTechnician">{{ $t('Save') }}</a-button>
              <a-button @click="resetForm">{{ $t('Reset') }}</a-button>
            </a-space>
          </a-form>
        </a-card>
      </a-col>

      <a-col :xs="24" :md="16">
        <DataTable :crud="crud" :columns="columns">
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'is_active'">
              <a-tag :color="record.is_active ? 'success' : 'error'">
                {{ record.is_active ? $t('Actif') : $t('Inactif') }}
              </a-tag>
            </template>
            <template v-else-if="column.key === 'actions'">
              <a-space>
                <a-button size="small" @click="editTechnician(record)">
                  <template #icon><EditOutlined /></template>
                </a-button>
                <a-button size="small" danger @click="crud.remove(record)">
                  <template #icon><DeleteOutlined /></template>
                </a-button>
              </a-space>
            </template>
          </template>
        </DataTable>
      </a-col>
    </a-row>
  </div>
</template>

<script setup>
/**
 * Service technicians — side form + list, like legacy. GET service_technicians
 * (server-side) → {technicians, totalRows}; save POST service_technicians or
 * PUT service_technicians/{id} with {name, phone, email, notes, is_active}.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { EditOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import http from '../../lib/http';

const { t } = useI18n();

const crud = useCrudTable('service_technicians', { rowsKey: 'technicians' });

const formRef = ref();
const saving = ref(false);
const form = ref({ id: null, name: '', phone: '', email: '', notes: '', is_active: true });

const rules = computed(() => ({
  name: [{ required: true, message: t('Field_is_required') }],
}));

const columns = computed(() => [
  { title: t('Name'), dataIndex: 'name', key: 'name', sorter: true },
  { title: t('Phone'), dataIndex: 'phone', key: 'phone' },
  { title: t('Email'), dataIndex: 'email', key: 'email' },
  { title: t('Status'), key: 'is_active', width: 100 },
  { title: t('Actions'), key: 'actions', width: 100 },
]);

function resetForm() {
  form.value = { id: null, name: '', phone: '', email: '', notes: '', is_active: true };
  formRef.value?.clearValidate();
}
function editTechnician(row) {
  form.value = {
    id: row.id,
    name: row.name,
    phone: row.phone,
    email: row.email,
    notes: row.notes,
    is_active: !!row.is_active,
  };
}
async function saveTechnician() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }
  saving.value = true;
  const payload = {
    name: form.value.name,
    phone: form.value.phone,
    email: form.value.email,
    notes: form.value.notes,
    is_active: form.value.is_active,
  };
  try {
    if (form.value.id) {
      await http.put(`service_technicians/${form.value.id}`, payload);
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('service_technicians', payload);
      message.success(t('Successfully_Created'));
    }
    resetForm();
    await crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

onMounted(crud.fetchRows);
</script>
