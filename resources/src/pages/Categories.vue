<template>
  <div class="page">
    <PageHeader :title="$t('Categories')" :breadcrumb="[$t('Products'), $t('Categories')]">
      <template #actions>
        <a-button type="primary" @click="openCreate">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <DataTable :crud="crud" :columns="columns" selectable>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'icon'">
          <i v-if="record.icon" :class="record.icon" style="font-size: 18px"></i>
          <span v-else class="text-muted">—</span>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space>
            <a-tooltip :title="$t('Edit')">
              <a-button type="text" size="small" :loading="loadingId === record.id" @click="openEdit(record)">
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
        <a-form-item :label="$t('Codecategorie')" name="code">
          <a-input v-model:value="form.code" />
        </a-form-item>
        <a-form-item :label="$t('Namecategorie')" name="name">
          <a-input v-model:value="form.name" />
        </a-form-item>
        <a-form-item :label="$t('Icon')" name="icon">
          <a-select v-model:value="form.icon" show-search allow-clear :options="iconOptions" :filter-option="filterIcon">
            <template #option="{ value, label }">
              <span><i v-if="value" :class="value" style="margin-right: 8px"></i>{{ label }}</span>
            </template>
          </a-select>
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
import { BOOTSTRAP_ICONS } from '../config/bootstrapIcons';
import http from '../lib/http';

const { t } = useI18n();
const crud = useCrudTable('categories', { rowsKey: 'categories' });

const columns = computed(() => [
  { title: t('Codecategorie'), dataIndex: 'code', key: 'code', sorter: true },
  { title: t('Namecategorie'), dataIndex: 'name', key: 'name', sorter: true },
  { title: t('Icon'), key: 'icon', width: 90 },
  { title: t('Action'), key: 'actions', width: 110, align: 'center' },
]);

// Same curated Bootstrap Icons list as the legacy page; the stored value is the
// full class name (e.g. "bi bi-bag"), rendered by bootstrap-icons CSS.
const iconOptions = computed(() => [
  { value: '', label: 'None' },
  ...BOOTSTRAP_ICONS.map(n => ({ value: `bi bi-${n}`, label: n.replace(/-/g, ' ') })),
]);

function filterIcon(input, option) {
  return String(option.label).toLowerCase().includes(String(input).toLowerCase());
}

/* ---------------------------------------------------------- create / edit */
const modalOpen = ref(false);
const editMode = ref(false);
const submitting = ref(false);
const loadingId = ref(null);
const formRef = ref();
const form = ref({ id: null, name: '', code: '', icon: '' });

const rules = computed(() => ({
  name: [{ required: true, message: t('Field_is_required') }],
  code: [{ required: true, message: t('Field_is_required') }],
}));

function openCreate() {
  editMode.value = false;
  form.value = { id: null, name: '', code: '', icon: '' };
  modalOpen.value = true;
}

// The list response omits `code`, so the legacy page refetches the record.
async function openEdit(record) {
  loadingId.value = record.id;
  try {
    const data = await crud.getRecord(record.id);
    form.value = {
      id: data.id,
      name: data.name || '',
      code: data.code || '',
      icon: data.icon || '',
    };
    editMode.value = true;
    modalOpen.value = true;
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    loadingId.value = null;
  }
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }
  submitting.value = true;
  const body = {
    name: form.value.name,
    code: form.value.code,
    icon: form.value.icon || '',
  };
  try {
    if (editMode.value) {
      await http.put(`categories/${form.value.id}`, body);
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('categories', body);
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

onMounted(crud.fetchRows);
</script>
