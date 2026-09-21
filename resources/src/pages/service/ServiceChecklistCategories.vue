<template>
  <div class="page">
    <PageHeader :title="$t('Checklist_Categories')" :breadcrumb="[$t('Service_Maintenance'), $t('Checklist_Categories')]" />

    <a-row :gutter="16">
      <a-col :xs="24" :md="10">
        <a-card size="small" :title="$t('Checklist_Categories')">
          <a-form ref="formRef" :model="categoryForm" :rules="rules" layout="vertical">
            <a-form-item :label="$t('Name') + ' *'" name="name">
              <a-input v-model:value="categoryForm.name" />
            </a-form-item>
            <a-form-item :label="$t('Description')">
              <a-textarea v-model:value="categoryForm.description" :rows="2" />
            </a-form-item>
            <a-space>
              <a-button type="primary" :loading="saving" @click="saveCategory">{{ $t('Save') }}</a-button>
              <a-button @click="resetCategoryForm">{{ $t('Reset') }}</a-button>
            </a-space>
          </a-form>
        </a-card>
      </a-col>

      <a-col :xs="24" :md="14">
        <a-card size="small" :body-style="{ padding: 0 }">
          <div style="padding: 16px; border-bottom: 1px solid rgba(5, 5, 5, 0.06)">
            <a-input-search
              v-model:value="search" :placeholder="$t('Search_this_table')"
              allow-clear style="max-width: 280px"
            />
          </div>
          <a-table
            :columns="columns" :data-source="filteredCategories"
            :loading="isLoading" size="middle" row-key="id"
            :locale="{ emptyText: $t('NodataAvailable') }"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'actions'">
                <a-space>
                  <a-button size="small" @click="editCategory(record)">
                    <template #icon><EditOutlined /></template>
                  </a-button>
                  <a-button size="small" danger @click="removeCategory(record)">
                    <template #icon><DeleteOutlined /></template>
                  </a-button>
                </a-space>
              </template>
            </template>
          </a-table>
        </a-card>
      </a-col>
    </a-row>
  </div>
</template>

<script setup>
/**
 * Checklist categories — client-side list (legacy loads all at once). GET
 * service_checklist/categories → {categories (with items_count)}; save POST /
 * PUT service_checklist/categories[/{id}] with {name, description}.
 */
import { ref, computed, onMounted } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { EditOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

const isLoading = ref(true);
const saving = ref(false);
const categories = ref([]);
const search = ref('');
const formRef = ref();
const categoryForm = ref({ id: null, name: '', description: '' });

const rules = computed(() => ({
  name: [{ required: true, message: t('Field_is_required') }],
}));

const columns = computed(() => [
  { title: t('Name'), dataIndex: 'name', key: 'name' },
  { title: t('Description'), dataIndex: 'description', key: 'description' },
  { title: t('Items'), dataIndex: 'items_count', key: 'items_count', width: 90, align: 'center' },
  { title: t('Actions'), key: 'actions', width: 100 },
]);

const filteredCategories = computed(() => {
  const q = search.value.trim().toLowerCase();
  if (!q) return categories.value;
  return categories.value.filter(c =>
    (c.name || '').toLowerCase().includes(q) || (c.description || '').toLowerCase().includes(q));
});

async function loadCategories() {
  isLoading.value = true;
  try {
    const data = await http.get('service_checklist/categories');
    categories.value = (data.categories || []).map(cat => ({
      ...cat,
      items_count: cat.items_count || 0,
    }));
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    isLoading.value = false;
  }
}
function editCategory(cat) {
  categoryForm.value = { id: cat.id, name: cat.name, description: cat.description || '' };
}
function resetCategoryForm() {
  categoryForm.value = { id: null, name: '', description: '' };
  formRef.value?.clearValidate();
}
async function saveCategory() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }
  saving.value = true;
  const payload = {
    name: categoryForm.value.name.trim(),
    description: categoryForm.value.description || '',
  };
  try {
    if (categoryForm.value.id) {
      await http.put(`service_checklist/categories/${categoryForm.value.id}`, payload);
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('service_checklist/categories', payload);
      message.success(t('Successfully_Created'));
    }
    await loadCategories();
    resetCategoryForm();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}
function removeCategory(row) {
  Modal.confirm({
    title: t('Delete_Title'),
    content: row.name,
    okText: t('Delete_confirmButtonText'),
    okType: 'danger',
    cancelText: t('Delete_cancelButtonText'),
    async onOk() {
      try {
        await http.delete(`service_checklist/categories/${row.id}`);
        message.success(t('Deleted_in_successfully'));
        await loadCategories();
        resetCategoryForm();
      } catch (e) {
        message.error(e?.data?.message || t('InvalidData'));
      }
    },
  });
}

onMounted(loadCategories);
</script>
