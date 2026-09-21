<template>
  <div class="page">
    <PageHeader :title="$t('Brand')" :breadcrumb="[$t('Products'), $t('Brand')]">
      <template #actions>
        <a-button type="primary" @click="openCreate">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <DataTable :crud="crud" :columns="columns" selectable>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'image'">
          <a-image
            :src="'/images/brands/' + (record.image || 'no_image.png')"
            :width="44"
            :height="44"
            :preview="!!record.image"
            style="object-fit: cover; border-radius: 8px"
            :fallback="IMAGE_FALLBACK"
          />
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

    <!-- Create / edit -->
    <a-modal
      v-model:open="modalOpen"
      :title="editMode ? $t('Edit') : $t('Add')"
      :confirm-loading="submitting"
      :ok-text="$t('Submit')"
      :cancel-text="$t('Delete_cancelButtonText')"
      @ok="submit"
    >
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical" style="margin-top: 8px">
        <a-form-item :label="$t('BrandName')" name="name">
          <a-input v-model:value="form.name" :placeholder="$t('Enter_Name_Brand')" :maxlength="20" show-count />
        </a-form-item>
        <a-form-item :label="$t('BrandDescription')" name="description">
          <a-textarea
            v-model:value="form.description"
            :placeholder="$t('Enter_Description_Brand')"
            :rows="3"
            :maxlength="30"
            show-count
          />
        </a-form-item>
        <a-form-item :label="$t('BrandImage')">
          <a-upload
            :file-list="fileList"
            :before-upload="beforeUpload"
            :max-count="1"
            list-type="picture"
            accept="image/*"
            @remove="() => (fileList = [])"
          >
            <a-button>
              <template #icon><UploadOutlined /></template>
              {{ $t('BrandImage') }} (max {{ MAX_IMAGE_MB }} MB)
            </a-button>
          </a-upload>
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * REFERENCE CRUD PAGE — copy this when migrating any list+form page.
 * Everything server-side (pagination, sort, search, delete) lives in
 * useCrudTable; this file only declares columns, form fields and endpoints.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined, UploadOutlined } from '@ant-design/icons-vue';
import PageHeader from '../components/PageHeader.vue';
import DataTable from '../components/DataTable.vue';
import { useCrudTable } from '../composables/useCrudTable';
import http from '../lib/http';

const { t } = useI18n();

/** Kept in step with the `image` rule in BrandsController (max:2048). */
const MAX_IMAGE_MB = 2;

const IMAGE_FALLBACK =
  'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSI0NCIgaGVpZ2h0PSI0NCI+PHJlY3Qgd2lkdGg9IjQ0IiBoZWlnaHQ9IjQ0IiByeD0iOCIgZmlsbD0iI2YwZjBmMCIvPjwvc3ZnPg==';

const crud = useCrudTable('brands', { rowsKey: 'brands' });

// computed so the headers re-render when the language changes
const columns = computed(() => [
  { title: t('BrandImage'), key: 'image', width: 80 },
  { title: t('BrandName'), dataIndex: 'name', key: 'name', sorter: true },
  { title: t('BrandDescription'), dataIndex: 'description', key: 'description', ellipsis: true },
  { title: t('Action'), key: 'actions', width: 110, align: 'center' },
]);

/* ---------------------------------------------------------- create / edit */
const modalOpen = ref(false);
const editMode = ref(false);
const submitting = ref(false);
const formRef = ref();
const form = ref({ id: null, name: '', description: '' });
const fileList = ref([]);

const rules = computed(() => ({
  name: [
    { required: true, message: t('Field_is_required') },
    { min: 3, max: 20, message: '3 – 20' },
  ],
  description: [{ max: 30, message: '≤ 30' }],
}));

function beforeUpload(file) {
  if (file.size / 1024 / 1024 > MAX_IMAGE_MB) {
    message.error(t('Image_Max_2MB'));
    return false;
  }
  fileList.value = [file];
  return false; // upload happens with the form submit, not immediately
}

function openCreate() {
  editMode.value = false;
  form.value = { id: null, name: '', description: '' };
  fileList.value = [];
  modalOpen.value = true;
}

function openEdit(record) {
  editMode.value = true;
  form.value = { id: record.id, name: record.name, description: record.description || '' };
  fileList.value = [];
  modalOpen.value = true;
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return; // errors render inline
  }
  submitting.value = true;
  try {
    const fd = new FormData();
    fd.append('name', form.value.name);
    fd.append('description', form.value.description || '');
    if (fileList.value.length) fd.append('image', fileList.value[0]);

    if (editMode.value) {
      fd.append('_method', 'put'); // legacy API expects POST + _method for updates
      await http.postForm(`brands/${form.value.id}`, fd);
      message.success(t('Successfully_Updated'));
    } else {
      await http.postForm('brands', fd);
      message.success(t('Successfully_Created'));
    }
    modalOpen.value = false;
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    submitting.value = false;
  }
}

onMounted(crud.fetchRows);
</script>
