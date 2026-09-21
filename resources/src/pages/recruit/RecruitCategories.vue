<template>
  <div class="page">
    <PageHeader :title="$t('Job_Categories')" :breadcrumb="[$t('Recruit'), $t('Job_Categories')]">
      <template #extra>
        <a-button type="primary" @click="openModal()">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <DataTable :crud="crud" :columns="columns" selectable>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'is_active'">
          <a-tag :color="record.is_active ? 'success' : 'default'">
            {{ record.is_active ? $t('Active') : $t('Inactive') }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space>
            <a-button size="small" @click="openModal(record)">
              <template #icon><EditOutlined /></template>
            </a-button>
            <a-button size="small" danger @click="crud.remove(record)">
              <template #icon><DeleteOutlined /></template>
            </a-button>
          </a-space>
        </template>
      </template>
    </DataTable>

    <a-modal
      v-model:open="modalOpen" :title="editmode ? $t('Edit') : $t('Add')"
      :confirm-loading="saving" @ok="submit"
    >
      <a-form ref="modalFormRef" :model="category" :rules="rules" layout="vertical" style="margin-top: 12px">
        <a-form-item :label="$t('Category_Name') + ' *'" name="name">
          <a-input v-model:value="category.name" :placeholder="$t('Category_Name')" />
        </a-form-item>
        <a-form-item :label="$t('Description')">
          <a-textarea v-model:value="category.description" :rows="3" />
        </a-form-item>
        <a-form-item>
          <a-checkbox v-model:checked="category.is_active">{{ $t('Active') }}</a-checkbox>
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Recruit job categories — GET recruit/categories → {categories, totalRows};
 * save POST/PUT recruit/categories[/{id}] {name, description, is_active};
 * bulk delete recruit/categories/delete/by_selection.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import http from '../../lib/http';

const { t } = useI18n();

const crud = useCrudTable('recruit/categories', { rowsKey: 'categories' });

const modalOpen = ref(false);
const editmode = ref(false);
const saving = ref(false);
const modalFormRef = ref();
const category = ref({ id: '', name: '', description: '', is_active: true });

const rules = computed(() => ({
  name: [{ required: true, message: t('Field_is_required') }],
}));

const columns = computed(() => [
  { title: t('Category_Name'), dataIndex: 'name', key: 'name', sorter: true },
  { title: t('Jobs'), dataIndex: 'jobs_count', key: 'jobs_count', align: 'center' },
  { title: t('Status'), key: 'is_active', width: 100 },
  { title: t('Action'), key: 'actions', width: 100 },
]);

function openModal(row = null) {
  editmode.value = !!row;
  category.value = row
    ? { id: row.id, name: row.name, description: row.description || '', is_active: !!row.is_active }
    : { id: '', name: '', description: '', is_active: true };
  modalFormRef.value?.clearValidate();
  modalOpen.value = true;
}
async function submit() {
  try {
    await modalFormRef.value.validate();
  } catch (e) {
    message.error(t('Please_fill_the_form_correctly'));
    return;
  }
  saving.value = true;
  const payload = {
    name: category.value.name,
    description: category.value.description,
    is_active: category.value.is_active,
  };
  try {
    if (editmode.value) {
      await http.put(`recruit/categories/${category.value.id}`, payload);
      message.success(t('Updated_in_successfully'));
    } else {
      await http.post('recruit/categories', payload);
      message.success(t('Created_in_successfully'));
    }
    modalOpen.value = false;
    await crud.fetchRows();
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

onMounted(crud.fetchRows);
</script>
