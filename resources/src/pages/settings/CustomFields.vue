<template>
  <div class="page">
    <PageHeader :title="$t('CustomFields')" :breadcrumb="[$t('Settings'), $t('CustomFields')]" />

    <!-- The Add button sits next to the tabs (NOT in the PageHeader actions):
         when this page is embedded in System Settings the header is hidden,
         which would hide the only way to create a field. -->
    <a-tabs v-model:active-key="entityTab" @change="loadAll">
      <a-tab-pane key="client" :tab="$t('Customers')" />
      <a-tab-pane key="provider" :tab="$t('Suppliers')" />
      <template #rightExtra>
        <a-button type="primary" @click="openCreate">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </a-tabs>

    <a-card size="small" :body-style="{ padding: 0 }">
      <a-table
        :columns="columns" :data-source="fields" :loading="loading"
        :pagination="false" row-key="id" size="middle" :scroll="{ x: 'max-content' }"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'field_type'">
            <a-tag>{{ record.field_type }}</a-tag>
          </template>
          <template v-else-if="column.key === 'is_required'">
            <a-tag :color="record.is_required ? 'error' : 'default'">
              {{ record.is_required ? $t('Required') : '—' }}
            </a-tag>
          </template>
          <template v-else-if="column.key === 'actions'">
            <a-space>
              <a-tooltip :title="$t('Edit')">
                <a-button type="text" size="small" @click="openEdit(record)">
                  <template #icon><EditOutlined style="color: #52c41a" /></template>
                </a-button>
              </a-tooltip>
              <a-tooltip :title="$t('Del')">
                <a-button type="text" size="small" danger @click="remove(record)">
                  <template #icon><DeleteOutlined /></template>
                </a-button>
              </a-tooltip>
            </a-space>
          </template>
        </template>
        <template #emptyText>
          <a-empty :description="$t('NodataAvailable')" style="padding: 32px 0" />
        </template>
      </a-table>
    </a-card>

    <a-modal
      v-model:open="modalOpen"
      :title="editing ? $t('Edit') : $t('Add')"
      :confirm-loading="saving"
      :ok-text="$t('submit')"
      @ok="save"
    >
      <a-form ref="formRef" :model="form" :rules="formRules" layout="vertical">
        <a-form-item :label="$t('FieldName')" name="name">
          <a-input v-model:value="form.name" />
        </a-form-item>
        <a-row :gutter="12">
          <a-col :span="12">
            <a-form-item :label="$t('FieldType')" name="field_type">
              <a-select
                v-model:value="form.field_type"
                :options="['text', 'textarea', 'number', 'date', 'select', 'checkbox'].map(v => ({ value: v, label: v }))"
              />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('SortOrder')">
              <a-input-number v-model:value="form.sort_order" style="width: 100%" :min="0" />
            </a-form-item>
          </a-col>
        </a-row>
        <a-form-item v-if="form.field_type === 'select'" :label="$t('EnterOptionsOnePerLine')">
          <a-textarea v-model:value="optionsText" :rows="3" />
        </a-form-item>
        <a-form-item :label="$t('DefaultValue')">
          <a-input v-model:value="form.default_value" />
        </a-form-item>
        <a-form-item>
          <a-checkbox v-model:checked="form.is_required">{{ $t('Required') }}</a-checkbox>
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * GET custom-fields?entity_type=client|provider → {custom_fields};
 * POST custom-fields / PUT custom-fields/{id} {name, field_type, entity_type,
 * is_required, default_value, sort_order, options[] when select};
 * DELETE custom-fields/{id}. Values render on the customer/supplier forms.
 */
import { ref, computed, createVNode, onMounted } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined, ExclamationCircleOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

const entityTab = ref('client');
const loading = ref(true);
const fields = ref([]);

const columns = computed(() => [
  { title: t('FieldName'), dataIndex: 'name', key: 'name' },
  { title: t('FieldType'), dataIndex: 'field_type', key: 'field_type' },
  { title: t('Required'), dataIndex: 'is_required', key: 'is_required' },
  { title: 'Sort', dataIndex: 'sort_order', key: 'sort_order', align: 'right' },
  { title: t('Action'), key: 'actions', width: 110, align: 'center' },
]);

async function loadAll() {
  loading.value = true;
  try {
    const data = await http.get('custom-fields', { entity_type: entityTab.value });
    fields.value = data.custom_fields || [];
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    loading.value = false;
  }
}

const modalOpen = ref(false);
const saving = ref(false);
const editing = ref(null);
const formRef = ref();
const optionsText = ref('');
const form = ref({});

const formRules = computed(() => ({
  name: [{ required: true, message: t('Field_is_required') }],
  field_type: [{ required: true, message: t('Field_is_required') }],
}));

function emptyForm() {
  return {
    name: '', field_type: 'text', is_required: false,
    default_value: '', sort_order: 0,
  };
}

function openCreate() {
  editing.value = null;
  form.value = emptyForm();
  optionsText.value = '';
  modalOpen.value = true;
}

function openEdit(record) {
  editing.value = record;
  form.value = {
    name: record.name,
    field_type: record.field_type,
    is_required: !!record.is_required,
    default_value: record.default_value || '',
    sort_order: record.sort_order || 0,
  };
  optionsText.value = Array.isArray(record.options) ? record.options.join('\n') : '';
  modalOpen.value = true;
}

async function save() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }
  saving.value = true;
  const payload = {
    name: form.value.name,
    field_type: form.value.field_type,
    entity_type: entityTab.value,
    is_required: form.value.is_required,
    default_value: form.value.default_value || null,
    sort_order: form.value.sort_order || 0,
  };
  if (form.value.field_type === 'select') {
    payload.options = optionsText.value.split('\n').map(s => s.trim()).filter(Boolean);
  }
  try {
    if (editing.value) {
      await http.put(`custom-fields/${editing.value.id}`, payload);
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('custom-fields', payload);
      message.success(t('Successfully_Created'));
    }
    modalOpen.value = false;
    loadAll();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

function remove(record) {
  Modal.confirm({
    title: t('Delete_Title'),
    icon: createVNode(ExclamationCircleOutlined),
    content: record.name,
    okType: 'danger',
    okText: t('Delete_confirmButtonText'),
    cancelText: t('Delete_cancelButtonText'),
    async onOk() {
      try {
        await http.delete(`custom-fields/${record.id}`);
        message.success(t('Deleted_in_successfully'));
        loadAll();
      } catch (e) {
        message.error(t('InvalidData'));
      }
    },
  });
}

onMounted(loadAll);
</script>
