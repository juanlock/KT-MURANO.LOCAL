<template>
  <div class="page">
    <PageHeader :title="$t('Languages')" :breadcrumb="[$t('Settings'), $t('Languages')]">
      <template #actions>
        <a-button type="primary" @click="openCreate">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" :body-style="{ padding: 0 }">
      <a-table
        :columns="columns" :data-source="languages" :loading="loading"
        :pagination="false" row-key="id" size="middle" :scroll="{ x: 'max-content' }"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'flag'">
            <img v-if="record.flag" :src="'/flags/' + record.flag" alt="" style="height: 18px" />
          </template>
          <template v-else-if="column.key === 'is_default'">
            <a-tag v-if="record.is_default" color="success">{{ $t('Default') }}</a-tag>
            <a-button v-else size="small" @click="setDefault(record)">{{ $t('Default') }}</a-button>
          </template>
          <template v-else-if="column.key === 'is_active'">
            <a-switch :checked="!!record.is_active" size="small" @change="setActive(record)" />
          </template>
          <template v-else-if="column.key === 'actions'">
            <a-space>
              <a-tooltip :title="$t('Translations')">
                <a-button type="text" size="small" @click="$router.push(`/settings/translations/${record.locale}`)">
                  <template #icon><TranslationOutlined style="color: #1677ff" /></template>
                </a-button>
              </a-tooltip>
              <a-tooltip :title="$t('Edit')">
                <a-button type="text" size="small" @click="openEdit(record)">
                  <template #icon><EditOutlined style="color: #52c41a" /></template>
                </a-button>
              </a-tooltip>
              <a-tooltip :title="$t('Del')">
                <a-button type="text" size="small" danger :disabled="!!record.is_default" @click="remove(record)">
                  <template #icon><DeleteOutlined /></template>
                </a-button>
              </a-tooltip>
            </a-space>
          </template>
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
        <a-form-item :label="$t('Name')" name="name">
          <a-input v-model:value="form.name" />
        </a-form-item>
        <a-form-item :label="$t('Locale')" name="locale">
          <a-input v-model:value="form.locale" placeholder="en" />
        </a-form-item>
        <a-form-item :label="$t('Flag')">
          <a-upload
            :file-list="flagList" :max-count="1" accept="image/*" list-type="picture"
            :before-upload="f => { flagList = [f]; return false; }" @remove="flagList = []"
          >
            <a-button><UploadOutlined /> {{ $t('Choose') }}</a-button>
          </a-upload>
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * GET languages_setting → array {id, name, locale, flag, is_default,
 * is_active}. Create POST languages_setting (FormData name/locale/flag);
 * edit POST languages_setting/{id}?_method=PUT; set-default/set-active =
 * POST languages_setting/{id}/set-default|set-active; DELETE
 * languages_setting/{id} (blocked for the default).
 */
import { ref, computed, createVNode, onMounted } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  PlusOutlined, EditOutlined, DeleteOutlined, UploadOutlined,
  TranslationOutlined, ExclamationCircleOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

const loading = ref(true);
const languages = ref([]);

const columns = computed(() => [
  { title: t('Flag'), key: 'flag', width: 70 },
  { title: t('Name'), dataIndex: 'name', key: 'name' },
  { title: t('Locale'), dataIndex: 'locale', key: 'locale' },
  { title: t('Default'), key: 'is_default', width: 110 },
  { title: t('Active'), key: 'is_active', width: 90 },
  { title: t('Action'), key: 'actions', width: 140, align: 'center' },
]);

async function load() {
  loading.value = true;
  try {
    const data = await http.get('languages_setting');
    languages.value = Array.isArray(data) ? data : [];
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    loading.value = false;
  }
}

async function setDefault(record) {
  try {
    await http.post(`languages_setting/${record.id}/set-default`);
    message.success(t('Successfully_Updated'));
    load();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  }
}

async function setActive(record) {
  try {
    await http.post(`languages_setting/${record.id}/set-active`);
    message.success(t('Successfully_Updated'));
    load();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  }
}

const modalOpen = ref(false);
const saving = ref(false);
const editing = ref(null);
const formRef = ref();
const flagList = ref([]);
const form = ref({ name: '', locale: '' });

const formRules = computed(() => ({
  name: [{ required: true, message: t('Field_is_required') }],
  locale: [{ required: true, message: t('Field_is_required') }],
}));

function openCreate() {
  editing.value = null;
  form.value = { name: '', locale: '' };
  flagList.value = [];
  modalOpen.value = true;
}

function openEdit(record) {
  editing.value = record;
  form.value = { name: record.name, locale: record.locale };
  flagList.value = [];
  modalOpen.value = true;
}

async function save() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }
  saving.value = true;
  const fd = new FormData();
  fd.append('name', form.value.name);
  fd.append('locale', form.value.locale);
  if (flagList.value.length) fd.append('flag', flagList.value[0].originFileObj || flagList.value[0]);
  try {
    if (editing.value) {
      await http.postForm(`languages_setting/${editing.value.id}?_method=PUT`, fd);
      message.success(t('Successfully_Updated'));
    } else {
      await http.postForm('languages_setting', fd);
      message.success(t('Successfully_Created'));
    }
    modalOpen.value = false;
    load();
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
        await http.delete(`languages_setting/${record.id}`);
        message.success(t('Deleted_in_successfully'));
        load();
      } catch (e) {
        message.error(e?.data?.message || t('InvalidData'));
      }
    },
  });
}

onMounted(load);
</script>
