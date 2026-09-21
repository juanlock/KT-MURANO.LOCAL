<template>
  <div class="page">
    <PageHeader :title="pageTitle" :breadcrumb="[$t('Marketing'), pageTitle]">
      <template #actions>
        <a-button type="primary" @click="openCreate">
          <template #icon><PlusOutlined /></template>
          {{ $t('New_Template') }}
        </a-button>
      </template>
    </PageHeader>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'actions'">
          <a-space>
            <a-tooltip :title="$t('Duplicate')">
              <a-button type="text" size="small" @click="duplicate(record)">
                <template #icon><CopyOutlined style="color: #1677ff" /></template>
              </a-button>
            </a-tooltip>
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
      :title="editing ? $t('Edit_Template') : $t('New_Template')"
      :confirm-loading="saving"
      :ok-text="$t('submit')"
      width="720px"
      @ok="save"
    >
      <a-form ref="formRef" :model="form" :rules="formRules" layout="vertical">
        <a-row :gutter="12">
          <a-col :xs="24" :md="16">
            <a-form-item :label="$t('Template_Name')" name="name">
              <a-input v-model:value="form.name" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Category')" name="category">
              <a-input v-model:value="form.category" />
            </a-form-item>
          </a-col>
        </a-row>
        <a-form-item v-if="templateType === 'email'" :label="$t('Email_Subject')" name="subject">
          <a-input v-model:value="form.subject" />
        </a-form-item>
        <a-form-item :label="$t('Message_Content')" name="content">
          <a-textarea v-model:value="form.content" :rows="templateType === 'email' ? 8 : 4" />
        </a-form-item>
        <div style="color: rgba(0, 0, 0, 0.45); font-size: 12px">
          {{ $t('Available_Variables') }}: {{ PERSONALIZATION_VARIABLES.join(' ') }}
        </div>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * One page for the three template channels — the route param :type
 * (sms|whatsapp|email) picks the channel, exactly like legacy's
 * meta.templateType. The router keys views by $route.path, so switching
 * channel remounts with the right list (GET marketing/templates&type=X).
 * Duplicate = POST marketing/templates/{id}/duplicate.
 */
import { ref, computed } from 'vue';
import { useRoute } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined, CopyOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { PERSONALIZATION_VARIABLES } from './marketingVocab';
import http from '../../lib/http';

const { t } = useI18n();
const route = useRoute();

// Captured at setup; sibling routes remount via the path-keyed router-view.
const templateType = ['sms', 'whatsapp', 'email'].includes(route.params.type)
  ? route.params.type
  : 'sms';

const pageTitle = computed(() => {
  const map = { sms: t('SMS_Templates'), whatsapp: t('WhatsApp_Templates'), email: t('Email_Templates') };
  return map[templateType];
});

const crud = useCrudTable('marketing/templates', {
  rowsKey: 'templates',
  params: () => ({ type: templateType }),
});
crud.fetchRows();

const columns = computed(() => [
  { title: t('Template_Name'), dataIndex: 'name', key: 'name', sorter: true },
  { title: t('Category'), dataIndex: 'category', key: 'category', sorter: true },
  { title: t('Action'), key: 'actions', width: 140, align: 'center' },
]);

const modalOpen = ref(false);
const saving = ref(false);
const editing = ref(null);
const formRef = ref();
const form = ref({ name: '', category: '', subject: '', content: '' });

const formRules = computed(() => ({
  name: [{ required: true, message: t('Field_is_required') }],
  content: [{ required: true, message: t('Field_is_required') }],
}));

function openCreate() {
  editing.value = null;
  form.value = { name: '', category: '', subject: '', content: '' };
  modalOpen.value = true;
}

function openEdit(record) {
  editing.value = record;
  form.value = {
    name: record.name,
    category: record.category || '',
    subject: record.subject || '',
    content: record.content || '',
  };
  modalOpen.value = true;
}

async function duplicate(record) {
  try {
    await http.post(`marketing/templates/${record.id}/duplicate`);
    message.success(t('Successfully_Created'));
    crud.fetchRows();
  } catch (e) {
    message.error(t('InvalidData'));
  }
}

async function save() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }
  saving.value = true;
  const payload = { ...form.value, type: templateType };
  try {
    if (editing.value) {
      await http.put(`marketing/templates/${editing.value.id}`, payload);
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('marketing/templates', payload);
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
