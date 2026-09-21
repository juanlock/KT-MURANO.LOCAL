<template>
  <div class="page">
    <PageHeader :title="$t('Property_Categories')" :breadcrumb="[$t('Real_Estate'), $t('Property_Categories')]">
      <template #extra>
        <a-button type="primary" @click="openModal()">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add_Category') }}
        </a-button>
      </template>
    </PageHeader>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'image'">
          <img
            v-if="record.image" :src="'/' + record.image"
            style="width: 48px; height: 38px; object-fit: cover; border-radius: 6px"
          />
          <span v-else style="color: #999">—</span>
        </template>
        <template v-else-if="column.key === 'properties_count'">
          <a-tag color="cyan">{{ record.properties_count }}</a-tag>
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
      v-model:open="modalOpen" :title="editMode ? $t('Edit_Category') : $t('Add_Category')"
      :confirm-loading="saving" @ok="save"
    >
      <a-form layout="vertical" style="margin-top: 12px">
        <a-form-item :label="$t('Name') + ' *'">
          <a-input v-model:value="form.name" />
        </a-form-item>
        <a-form-item :label="$t('Description')">
          <a-textarea v-model:value="form.description" :rows="3" />
        </a-form-item>
        <a-form-item :label="$t('Image')">
          <img v-if="imagePreview" :src="imagePreview" style="max-height: 120px; max-width: 100%; border-radius: 8px; margin-bottom: 10px; display: block" />
          <a-upload :file-list="[]" :before-upload="() => false" :max-count="1" accept="image/*" @change="onImageChange">
            <a-button>
              <template #icon><UploadOutlined /></template>
              {{ $t('Choose_a_file') }}
            </a-button>
          </a-upload>
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Property categories — GET realestate/categories → {categories, totalRows}.
 * Save is MULTIPART POST realestate/categories[/{id}] (edit is POST, no
 * _method) with name (+description, image_file when set). Name required.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined, UploadOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { uploadForm } from '../../lib/upload';

const { t } = useI18n();

const crud = useCrudTable('realestate/categories', { rowsKey: 'categories' });

const modalOpen = ref(false);
const editMode = ref(false);
const saving = ref(false);
const imageFile = ref(null);
const imagePreview = ref(null);
const form = ref({ id: null, name: '', description: '' });

const columns = computed(() => [
  { title: t('Image'), key: 'image', width: 80 },
  { title: t('Name'), dataIndex: 'name', key: 'name', sorter: true },
  { title: t('Properties'), key: 'properties_count', width: 110, align: 'center' },
  { title: t('Action'), key: 'actions', width: 100 },
]);

function onImageChange({ file }) {
  const f = file.originFileObj || file;
  if (!f) return;
  imageFile.value = f;
  imagePreview.value = URL.createObjectURL(f);
}
function openModal(row = null) {
  editMode.value = !!row;
  form.value = row
    ? { id: row.id, name: row.name, description: row.description || '' }
    : { id: null, name: '', description: '' };
  imageFile.value = null;
  imagePreview.value = row && row.image ? `/${row.image}` : null;
  modalOpen.value = true;
}
async function save() {
  if (!form.value.name) {
    message.error(t('InvalidData'));
    return;
  }
  saving.value = true;
  try {
    const fd = new FormData();
    fd.append('name', form.value.name);
    if (form.value.description) fd.append('description', form.value.description);
    if (imageFile.value) fd.append('image_file', imageFile.value);
    const url = editMode.value ? `realestate/categories/${form.value.id}` : 'realestate/categories';
    const { status } = await uploadForm(url, fd);
    if (status >= 200 && status < 300) {
      modalOpen.value = false;
      message.success(t('Created_in_successfully'));
      await crud.fetchRows();
    } else {
      message.error(t('InvalidData'));
    }
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

onMounted(crud.fetchRows);
</script>
