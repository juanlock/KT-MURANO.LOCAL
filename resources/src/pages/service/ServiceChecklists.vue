<template>
  <div class="page">
    <PageHeader :title="$t('Checklist_Items')" :breadcrumb="[$t('Service_Maintenance'), $t('Checklist_Items')]">
      <template #extra>
        <a-button type="primary" @click="openModal">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" :body-style="{ padding: 0 }">
      <div style="padding: 16px; border-bottom: 1px solid rgba(5, 5, 5, 0.06)">
        <a-input-search
          v-model:value="search" :placeholder="$t('Search_this_table')"
          allow-clear style="max-width: 280px"
        />
      </div>
      <a-table
        :columns="columns" :data-source="filteredItems"
        :loading="isLoading" size="middle" row-key="id"
        :locale="{ emptyText: $t('NodataAvailable') }"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'actions'">
            <a-space>
              <a-button size="small" @click="editItem(record)">
                <template #icon><EditOutlined /></template>
              </a-button>
              <a-button size="small" danger @click="removeItem(record)">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-space>
          </template>
        </template>
      </a-table>
    </a-card>

    <a-modal
      v-model:open="modalOpen"
      :title="editmode ? $t('Edit') : $t('Add')"
      :confirm-loading="SubmitProcessing"
      @ok="saveItem"
    >
      <a-form ref="modalFormRef" :model="itemForm" :rules="rules" layout="vertical" style="margin-top: 12px">
        <a-form-item :label="$t('Categorie') + ' *'" name="category_id">
          <a-select
            v-model:value="itemForm.category_id" :placeholder="$t('PleaseSelect')"
            :options="categories.map(c => ({ label: c.name, value: c.id }))"
            :disabled="categories.length === 0"
          />
        </a-form-item>
        <a-form-item :label="$t('Item_Name') + ' *'" name="name">
          <a-input v-model:value="itemForm.name" :placeholder="$t('Item_Name')" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Checklist items — client-side list + modal CRUD. GET service_checklist/items
 * (+ categories for names); save POST / PUT service_checklist/items[/{id}]
 * with {category_id, name, sort_order: 0}; legacy only closes the modal when
 * response.success is truthy.
 */
import { ref, computed, onMounted } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

const isLoading = ref(true);
const SubmitProcessing = ref(false);
const editmode = ref(false);
const modalOpen = ref(false);
const categories = ref([]);
const items = ref([]);
const search = ref('');
const modalFormRef = ref();
const itemForm = ref({ id: null, category_id: null, name: '' });

const rules = computed(() => ({
  category_id: [{ required: true, message: t('Field_is_required') }],
  name: [{ required: true, message: t('Field_is_required') }],
}));

const columns = computed(() => [
  { title: t('Categorie'), dataIndex: 'category_name', key: 'category_name' },
  { title: t('Name'), dataIndex: 'name', key: 'name' },
  { title: t('Actions'), key: 'actions', width: 100 },
]);

const filteredItems = computed(() => {
  const q = search.value.trim().toLowerCase();
  if (!q) return items.value;
  return items.value.filter(i =>
    (i.name || '').toLowerCase().includes(q) || (i.category_name || '').toLowerCase().includes(q));
});

async function loadCategories() {
  const data = await http.get('service_checklist/categories');
  categories.value = data.categories || [];
}
async function loadItems() {
  const data = await http.get('service_checklist/items');
  items.value = (data.items || []).map(item => {
    const category = categories.value.find(cat => cat.id === item.category_id);
    return { ...item, category_name: category ? category.name : '-' };
  });
}

function openModal() {
  editmode.value = false;
  itemForm.value = { id: null, category_id: null, name: '' };
  modalFormRef.value?.clearValidate();
  modalOpen.value = true;
}
function editItem(row) {
  editmode.value = true;
  itemForm.value = { id: row.id, category_id: row.category_id, name: row.name };
  modalOpen.value = true;
}
async function saveItem() {
  try {
    await modalFormRef.value.validate();
  } catch (e) {
    return;
  }
  SubmitProcessing.value = true;
  const payload = {
    category_id: itemForm.value.category_id,
    name: itemForm.value.name.trim(),
    sort_order: 0,
  };
  try {
    let response;
    if (editmode.value) {
      response = await http.put(`service_checklist/items/${itemForm.value.id}`, payload);
      message.success(t('Successfully_Updated'));
    } else {
      response = await http.post('service_checklist/items', payload);
      message.success(t('Successfully_Created'));
    }
    if (response && response.success) {
      modalOpen.value = false;
      await loadItems();
      await loadCategories();
    } else {
      message.error(t('InvalidData'));
    }
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    SubmitProcessing.value = false;
  }
}
function removeItem(row) {
  Modal.confirm({
    title: t('Delete_Title'),
    content: row.name,
    okText: t('Delete_confirmButtonText'),
    okType: 'danger',
    cancelText: t('Delete_cancelButtonText'),
    async onOk() {
      try {
        await http.delete(`service_checklist/items/${row.id}`);
        message.success(t('Deleted_in_successfully'));
        await loadItems();
        await loadCategories();
      } catch (e) {
        message.error(e?.data?.message || t('InvalidData'));
      }
    },
  });
}

onMounted(async () => {
  try {
    await loadCategories();
    await loadItems();
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    isLoading.value = false;
  }
});
</script>
