<template>
  <div class="page">
    <PageHeader :title="$t('Article_Groups')" :breadcrumb="[$t('Knowledge_Base'), $t('Article_Groups')]">
      <template #actions>
        <a-button @click="$router.push('/kb')">{{ $t('Knowledge_Base') }}</a-button>
        <a-button type="primary" @click="openCreate">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card :body-style="{ padding: 0 }">
      <a-table
        :columns="columns"
        :data-source="groups"
        :loading="loading"
        row-key="id"
        size="middle"
        :pagination="false"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'actions'">
            <a-space>
              <a-tooltip :title="$t('Edit')">
                <a-button type="text" size="small" @click="openEdit(record)">
                  <template #icon><EditOutlined style="color: #52c41a" /></template>
                </a-button>
              </a-tooltip>
              <a-tooltip :title="$t('Del')">
                <a-button type="text" size="small" danger @click="confirmDelete(record)">
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
        <a-form-item label="Slug" name="slug">
          <a-input v-model:value="form.slug" />
        </a-form-item>
        <a-form-item :label="$t('Description')" name="description">
          <a-textarea v-model:value="form.description" :rows="3" />
        </a-form-item>
        <a-form-item :label="$t('SortOrder')" name="sort_order">
          <a-input-number v-model:value="form.sort_order" style="width: 100%" :min="0" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * KB groups: GET knowledge-base/groups → array | .data (no pagination);
 * JSON {name, slug, description, sort_order}; DELETE groups/{id}.
 */
import { ref, computed, createVNode, onMounted } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  PlusOutlined, EditOutlined, DeleteOutlined, ExclamationCircleOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

const loading = ref(true);
const groups = ref([]);

const columns = computed(() => [
  { title: t('Name'), dataIndex: 'name', key: 'name' },
  { title: 'Slug', dataIndex: 'slug', key: 'slug' },
  { title: t('Description'), dataIndex: 'description', key: 'description', ellipsis: true },
  { title: t('SortOrder'), dataIndex: 'sort_order', key: 'sort_order', width: 110, align: 'right' },
  { title: t('Action'), key: 'actions', width: 110, align: 'center' },
]);

async function load() {
  loading.value = true;
  try {
    const data = await http.get('knowledge-base/groups');
    groups.value = Array.isArray(data) ? data : data.data || [];
  } catch (e) {
    groups.value = [];
  } finally {
    loading.value = false;
  }
}

/* ---------------------------------------------------------- create/edit */
const modalOpen = ref(false);
const editMode = ref(false);
const submitting = ref(false);
const formRef = ref();
const emptyForm = () => ({ id: null, name: '', slug: '', description: '', sort_order: 0 });
const form = ref(emptyForm());

const rules = computed(() => ({
  name: [{ required: true, message: t('Field_is_required') }],
}));

function openCreate() {
  editMode.value = false;
  form.value = emptyForm();
  modalOpen.value = true;
}

function openEdit(record) {
  editMode.value = true;
  form.value = {
    id: record.id, name: record.name || '', slug: record.slug || '',
    description: record.description || '', sort_order: record.sort_order ?? 0,
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
  const body = {
    name: form.value.name, slug: form.value.slug,
    description: form.value.description, sort_order: form.value.sort_order,
  };
  try {
    if (editMode.value) {
      await http.put(`knowledge-base/groups/${form.value.id}`, body);
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('knowledge-base/groups', body);
      message.success(t('Successfully_Created'));
    }
    modalOpen.value = false;
    load();
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    submitting.value = false;
  }
}

function confirmDelete(record) {
  Modal.confirm({
    title: t('Delete_Title'),
    icon: createVNode(ExclamationCircleOutlined),
    content: record.name,
    okText: t('Delete_confirmButtonText'),
    okType: 'danger',
    cancelText: t('Delete_cancelButtonText'),
    async onOk() {
      try {
        await http.delete(`knowledge-base/groups/${record.id}`);
        message.success(t('Deleted_in_successfully'));
        load();
      } catch (e) {
        message.error(t('Delete_Therewassomethingwronge'));
      }
    },
  });
}

onMounted(load);
</script>
