<template>
  <div class="page">
    <PageHeader :title="$t('Units')" :breadcrumb="[$t('Products'), $t('Units')]">
      <template #actions>
        <a-button type="primary" @click="openCreate">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <a-alert type="info" show-icon closable style="margin-bottom: 16px">
      <template #message>{{ $t('Units_Guide_Title') }}</template>
      <template #description>
        <ul style="margin: 0; padding-left: 18px">
          <li>{{ $t('Units_Guide_Base') }}</li>
          <li>{{ $t('Units_Guide_Sub') }}</li>
          <li>{{ $t('Units_Guide_Example_Multiply') }}</li>
          <li>{{ $t('Units_Guide_Example_Divide') }}</li>
          <li>{{ $t('Units_Guide_Note') }}</li>
        </ul>
      </template>
    </a-alert>

    <DataTable :crud="crud" :columns="columns" selectable>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'base_unit'">
          <span v-if="record.base_unit_name">{{ record.base_unit_name }}</span>
          <a-tag v-else color="blue">{{ $t('BaseUnit') }}</a-tag>
        </template>
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
        <a-form-item :label="$t('Name')" name="name">
          <a-input v-model:value="form.name" />
        </a-form-item>
        <a-form-item :label="$t('ShortName')" name="ShortName">
          <a-input v-model:value="form.ShortName" />
        </a-form-item>
        <a-form-item :label="$t('BaseUnit')" name="base_unit">
          <a-select
            v-model:value="form.base_unit"
            allow-clear
            show-search
            option-filter-prop="label"
            :options="baseUnitOptions"
            :placeholder="$t('BaseUnit')"
          />
        </a-form-item>

        <!-- Operator only applies when this unit derives from a base unit. -->
        <template v-if="form.base_unit">
          <a-form-item :label="$t('Operator')" name="operator">
            <a-select v-model:value="form.operator" :options="operatorOptions" />
          </a-form-item>
          <a-form-item :label="$t('OperationValue')" name="operator_value">
            <a-input-number v-model:value="form.operator_value" style="width: 100%" :min="0" />
          </a-form-item>
        </template>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../components/PageHeader.vue';
import DataTable from '../components/DataTable.vue';
import { useCrudTable } from '../composables/useCrudTable';
import http from '../lib/http';

const { t } = useI18n();

// The payload carries both the rows (Units) and the base-unit list (Units_base),
// so rowsKey is required — auto-detection would be ambiguous.
const crud = useCrudTable('units', { rowsKey: 'Units' });

const baseUnitOptions = computed(() =>
  (crud.payload.value?.Units_base || []).map(u => ({ value: u.id, label: u.name }))
);

const operatorOptions = [
  { value: '*', label: '* (multiply)' },
  { value: '/', label: '/ (divide)' },
];

const columns = computed(() => [
  { title: t('Name'), dataIndex: 'name', key: 'name', sorter: true },
  { title: t('ShortName'), dataIndex: 'ShortName', key: 'ShortName', sorter: true },
  { title: t('BaseUnit'), key: 'base_unit' },
  { title: t('Operator'), dataIndex: 'operator', key: 'operator', width: 100 },
  { title: t('OperationValue'), dataIndex: 'operator_value', key: 'operator_value', width: 140 },
  { title: t('Action'), key: 'actions', width: 110, align: 'center' },
]);

/* ---------------------------------------------------------- create / edit */
const modalOpen = ref(false);
const editMode = ref(false);
const submitting = ref(false);
const formRef = ref();
const emptyForm = () => ({ id: null, name: '', ShortName: '', base_unit: null, operator: '*', operator_value: 1 });
const form = ref(emptyForm());

const rules = computed(() => ({
  name: [{ required: true, message: t('Field_is_required') }],
  ShortName: [{ required: true, message: t('Field_is_required') }],
}));

function openCreate() {
  editMode.value = false;
  form.value = emptyForm();
  modalOpen.value = true;
}

function openEdit(record) {
  editMode.value = true;
  form.value = {
    id: record.id,
    name: record.name || '',
    ShortName: record.ShortName || '',
    base_unit: record.base_unit || null,
    operator: record.operator || '*',
    operator_value: record.operator_value ?? 1,
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
  // Legacy sends '' (not null) when there is no base unit.
  const body = {
    name: form.value.name,
    ShortName: form.value.ShortName,
    base_unit: form.value.base_unit || '',
    operator: form.value.operator,
    operator_value: form.value.operator_value,
  };
  try {
    if (editMode.value) {
      await http.put(`units/${form.value.id}`, body);
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('units', body);
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
