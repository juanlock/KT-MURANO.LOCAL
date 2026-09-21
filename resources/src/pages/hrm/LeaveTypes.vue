<template>
  <div class="page">
    <PageHeader :title="$t('Leave_type')" :breadcrumb="[$t('hrm'), $t('Leave_type')]">
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
              <a-button type="text" size="small" danger @click="crud.remove(record, { label: record.title })">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>

    <a-modal
      v-model:open="modalOpen"
      :title="editing ? $t('Edit') : $t('Add')"
      :confirm-loading="saving"
      :ok-text="$t('submit')"
      @ok="save"
    >
      <a-form ref="formRef" :model="form" :rules="formRules" layout="vertical">
        <a-form-item :label="$t('Leave_Type')" name="title">
          <a-input v-model:value="form.title" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * GET leave_type → {leave_types, totalRows}; POST/PUT with {title} only.
 */
import { ref, computed } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import http from '../../lib/http';

const { t } = useI18n();

const crud = useCrudTable('leave_type', { rowsKey: 'leave_types' });
crud.fetchRows();

const columns = computed(() => [
  { title: t('Leave_Type'), dataIndex: 'title', key: 'title', sorter: true },
  { title: t('Action'), key: 'actions', width: 110, align: 'center' },
]);

const modalOpen = ref(false);
const saving = ref(false);
const editing = ref(null);
const formRef = ref();
const form = ref({ title: '' });

const formRules = computed(() => ({
  title: [{ required: true, message: t('Field_is_required') }],
}));

function openCreate() {
  editing.value = null;
  form.value = { title: '' };
  modalOpen.value = true;
}

function openEdit(record) {
  editing.value = record;
  form.value = { title: record.title };
  modalOpen.value = true;
}

async function save() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }
  saving.value = true;
  try {
    if (editing.value) {
      await http.put(`leave_type/${editing.value.id}`, form.value);
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('leave_type', form.value);
      message.success(t('Successfully_Created'));
    }
    modalOpen.value = false;
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}
</script>
