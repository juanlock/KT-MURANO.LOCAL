<template>
  <div class="page">
    <PageHeader :title="$t('Company')" :breadcrumb="[$t('HRM'), $t('Company')]">
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
      @ok="submit"
    >
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical" style="margin-top: 8px">
        <a-form-item :label="$t('Name')" name="name">
          <a-input v-model:value="form.name" />
        </a-form-item>
        <a-form-item :label="$t('Phone')" name="phone">
          <a-input v-model:value="form.phone" />
        </a-form-item>
        <a-form-item :label="$t('Email')" name="email">
          <a-input v-model:value="form.email" type="email" />
        </a-form-item>
        <a-form-item :label="$t('Country')" name="country">
          <a-input v-model:value="form.country" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import http from '../../lib/http';

const { t } = useI18n();
const crud = useCrudTable('company', { rowsKey: 'companies' });

const columns = computed(() => [
  { title: t('Name'), dataIndex: 'name', key: 'name', sorter: true },
  { title: t('Phone'), dataIndex: 'phone', key: 'phone' },
  { title: t('Country'), dataIndex: 'country', key: 'country' },
  { title: t('Email'), dataIndex: 'email', key: 'email' },
  { title: t('Action'), key: 'actions', width: 110, align: 'center' },
]);

const modalOpen = ref(false);
const editMode = ref(false);
const submitting = ref(false);
const formRef = ref();
const emptyForm = () => ({ id: null, name: '', phone: '', email: '', country: '' });
const form = ref(emptyForm());

const rules = computed(() => ({
  name: [{ required: true, message: t('Field_is_required') }],
  email: [{ type: 'email', message: t('InvalidData') }],
}));

function openCreate() {
  editMode.value = false;
  form.value = emptyForm();
  modalOpen.value = true;
}

function openEdit(record) {
  editMode.value = true;
  form.value = { id: record.id, name: record.name || '', phone: record.phone || '', email: record.email || '', country: record.country || '' };
  modalOpen.value = true;
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }
  submitting.value = true;
  const body = { name: form.value.name, email: form.value.email, country: form.value.country, phone: form.value.phone };
  try {
    if (editMode.value) {
      await http.put(`company/${form.value.id}`, body);
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('company', body);
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

onMounted(crud.fetchRows);
</script>
