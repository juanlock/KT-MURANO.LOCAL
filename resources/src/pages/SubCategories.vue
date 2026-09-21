<template>
  <div class="page">
    <PageHeader :title="$t('SubCategory')" :breadcrumb="[$t('Products'), $t('SubCategory')]">
      <template #actions>
        <a-button type="primary" @click="openCreate">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <DataTable :crud="crud" :columns="columns" selectable>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'category_name'">
          {{ record.category ? record.category.name : '—' }}
        </template>
        <template v-else-if="column.key === 'status'">
          <a-tag :color="record.status ? 'success' : 'default'">
            {{ record.status ? $t('Active') : $t('Inactive') }}
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
        <a-form-item :label="$t('Categorie')" name="category_id">
          <a-select
            v-model:value="form.category_id"
            show-search
            option-filter-prop="label"
            :options="categoryOptions"
            :placeholder="$t('Categorie')"
          />
        </a-form-item>
        <a-form-item :label="$t('SubCategoryName')" name="name">
          <a-input v-model:value="form.name" />
        </a-form-item>
        <a-form-item :label="$t('Description')" name="description">
          <a-textarea v-model:value="form.description" :rows="3" />
        </a-form-item>
        <a-form-item :label="$t('Status')">
          <a-switch v-model:checked="form.status" />
          <span style="margin-left: 8px">{{ form.status ? $t('Active') : $t('Inactive') }}</span>
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
import PageHeader from '../components/PageHeader.vue';
import DataTable from '../components/DataTable.vue';
import { useCrudTable } from '../composables/useCrudTable';
import http from '../lib/http';

const { t } = useI18n();
const crud = useCrudTable('subcategories', { rowsKey: 'subcategories' });

// Parent categories for the select — loaded once, like the legacy page.
const categories = ref([]);
const categoryOptions = computed(() => categories.value.map(c => ({ value: c.id, label: c.name })));

const columns = computed(() => [
  { title: t('Categorie'), key: 'category_name' },
  { title: t('SubCategoryName'), dataIndex: 'name', key: 'name', sorter: true },
  { title: t('Status'), key: 'status', width: 120 },
  { title: t('Action'), key: 'actions', width: 110, align: 'center' },
]);

/* ---------------------------------------------------------- create / edit */
const modalOpen = ref(false);
const editMode = ref(false);
const submitting = ref(false);
const formRef = ref();
const emptyForm = () => ({ id: null, category_id: null, name: '', description: '', status: true });
const form = ref(emptyForm());

const rules = computed(() => ({
  category_id: [{ required: true, message: t('Field_is_required') }],
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
    id: record.id,
    category_id: record.category_id,
    name: record.name || '',
    description: record.description || '',
    status: !!record.status,
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
    category_id: form.value.category_id,
    name: form.value.name,
    description: form.value.description,
    status: form.value.status ? 1 : 0,
  };
  try {
    if (editMode.value) {
      await http.put(`subcategories/${form.value.id}`, body);
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('subcategories', body);
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

async function loadCategories() {
  try {
    // limit: -1 tells the API to return every category (the default page size
    // is 10, which silently truncated this select).
    const data = await http.get('categories', { limit: -1, SortField: 'name', SortType: 'asc' });
    categories.value = data.categories || [];
  } catch (e) {
    // The select stays empty; the table itself still works.
  }
}

onMounted(() => {
  crud.fetchRows();
  loadCategories();
});
</script>
