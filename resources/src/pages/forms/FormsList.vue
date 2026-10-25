<template>
  <div class="page">
    <PageHeader :title="$t('Forms_Module')" :breadcrumb="[$t('Forms_Module'), $t('Forms_All')]">
      <template #actions>
        <a-button v-if="canManage" type="primary" @click="openCreate">
          <template #icon><PlusOutlined /></template>
          {{ es ? 'Nuevo formulario' : 'New form' }}
        </a-button>
      </template>
    </PageHeader>

    <a-card :body-style="{ padding: '12px' }">
      <div class="fl-toolbar">
        <a-input-search v-model:value="search" :placeholder="es ? 'Buscar formulario' : 'Search form'" allow-clear style="max-width: 300px" />
        <span class="fl-muted">{{ rows.length }} {{ es ? 'formularios' : 'forms' }}</span>
      </div>
      <a-table :columns="columns" :data-source="rows" :loading="loading" row-key="id" size="middle" :pagination="{ pageSize: 20, hideOnSinglePage: true }">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'name'">
            <router-link :to="`/forms/${record.id}/edit`" class="fl-name">{{ record.name }}</router-link>
            <a-tag v-if="record.legacy" color="default" class="fl-tag">{{ es ? 'Importado' : 'Imported' }}</a-tag>
          </template>
          <template v-else-if="column.key === 'status'">
            <a-tag :color="record.status ? 'green' : 'default'">{{ record.status ? (es ? 'Publicado' : 'Published') : (es ? 'Borrador' : 'Draft') }}</a-tag>
          </template>
          <template v-else-if="column.key === 'submissions'">
            <router-link :to="`/forms/${record.id}/submissions`">{{ record.submissions }}</router-link>
            <a-badge v-if="record.unread" :count="record.unread" :number-style="{ backgroundColor: '#1677ff' }" class="fl-badge" :title="es ? 'Sin leer' : 'Unread'" />
          </template>
          <template v-else-if="column.key === 'last'">
            {{ record.last_submission_at ? fmt(record.last_submission_at) : '—' }}
          </template>
          <template v-else-if="column.key === 'actions'">
            <a-space>
              <a-tooltip :title="es ? 'Envíos' : 'Submissions'">
                <a-button type="text" size="small" @click="$router.push(`/forms/${record.id}/submissions`)"><template #icon><InboxOutlined /></template></a-button>
              </a-tooltip>
              <a-tooltip :title="es ? 'Código para insertar' : 'Embed code'">
                <a-button type="text" size="small" @click="openEmbed(record)"><template #icon><CodeOutlined /></template></a-button>
              </a-tooltip>
              <a-tooltip v-if="canManage" :title="es ? 'Editar' : 'Edit'">
                <a-button type="text" size="small" @click="$router.push(`/forms/${record.id}/edit`)"><template #icon><EditOutlined style="color: #52c41a" /></template></a-button>
              </a-tooltip>
              <a-tooltip v-if="canManage" :title="es ? 'Duplicar' : 'Duplicate'">
                <a-button type="text" size="small" @click="duplicate(record)"><template #icon><CopyOutlined /></template></a-button>
              </a-tooltip>
              <a-tooltip v-if="canManage" :title="es ? 'Eliminar' : 'Delete'">
                <a-button type="text" size="small" danger @click="remove(record)"><template #icon><DeleteOutlined /></template></a-button>
              </a-tooltip>
            </a-space>
          </template>
        </template>
      </a-table>
    </a-card>

    <a-modal v-model:open="createOpen" :title="es ? 'Nuevo formulario' : 'New form'" :confirm-loading="saving" @ok="create">
      <a-form layout="vertical">
        <a-form-item :label="es ? 'Nombre' : 'Name'" required :extra="es ? 'Empieza con Nombre, Correo y Mensaje; luego agrega o quita campos.' : 'Starts with Name, Email and Message; then add or remove fields.'">
          <a-input v-model:value="newName" :maxlength="150" @press-enter="create" />
        </a-form-item>
      </a-form>
    </a-modal>

    <EmbedCodeModal v-model:open="embedOpen" :form-id="embedId" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, createVNode } from 'vue';
import { useRouter } from 'vue-router';
import { message, Modal } from 'ant-design-vue';
import { PlusOutlined, EditOutlined, DeleteOutlined, CopyOutlined, InboxOutlined, CodeOutlined, ExclamationCircleOutlined } from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import PageHeader from '../../components/PageHeader.vue';
import EmbedCodeModal from './EmbedCodeModal.vue';
import http from '../../lib/http';
import { useAuthStore } from '../../stores/auth';
import { useUiStore } from '../../stores/ui';
import { useSystemContext } from '../../lib/systemContext';

const router = useRouter();
const auth = useAuthStore();
const ui = useUiStore();
const { isSystem } = useSystemContext();
const es = computed(() => ui.locale === 'es');
const canManage = computed(() => auth.can('forms_manage'));

const all = ref([]);
const loading = ref(false);
const search = ref('');
const rows = computed(() => {
  const q = search.value.trim().toLowerCase();
  return q ? all.value.filter(r => r.name.toLowerCase().includes(q) || (r.company_name || '').toLowerCase().includes(q)) : all.value;
});
const fmt = d => dayjs(d).format('YYYY-MM-DD HH:mm');

const columns = computed(() => [
  ...(isSystem.value ? [{ title: es.value ? 'Empresa' : 'Company', dataIndex: 'company_name', key: 'company', sorter: (a, b) => (a.company_name || '').localeCompare(b.company_name || '') }] : []),
  { title: es.value ? 'Formulario' : 'Form', key: 'name', sorter: (a, b) => a.name.localeCompare(b.name) },
  { title: es.value ? 'Estado' : 'Status', key: 'status', width: 120 },
  { title: es.value ? 'Envíos' : 'Submissions', key: 'submissions', width: 130, sorter: (a, b) => a.submissions - b.submissions },
  { title: es.value ? 'Último envío' : 'Last submission', key: 'last', width: 170, sorter: (a, b) => String(a.last_submission_at || '').localeCompare(String(b.last_submission_at || '')) },
  { title: '', key: 'actions', width: 210, align: 'right' },
]);

async function load() {
  loading.value = true;
  try {
    all.value = (await http.get('forms')).forms || [];
  } catch (e) {
    message.error(e?.data?.message || e.message);
  } finally {
    loading.value = false;
  }
}

const createOpen = ref(false);
const newName = ref('');
const saving = ref(false);
function openCreate() {
  newName.value = '';
  createOpen.value = true;
}
async function create() {
  if (!newName.value.trim()) return;
  saving.value = true;
  try {
    const res = await http.post('forms', { name: newName.value.trim() });
    createOpen.value = false;
    router.push(`/forms/${res.form.id}/edit`);
  } catch (e) {
    message.error(e?.data?.message || e.message);
  } finally {
    saving.value = false;
  }
}

async function duplicate(r) {
  try {
    const res = await http.post(`forms/${r.id}/duplicate`);
    message.success(es.value ? 'Copia creada (borrador).' : 'Copy created (draft).');
    router.push(`/forms/${res.form.id}/edit`);
  } catch (e) {
    message.error(e?.data?.message || e.message);
  }
}

function remove(r) {
  Modal.confirm({
    title: (es.value ? 'Eliminar ' : 'Delete ') + r.name + '?',
    icon: createVNode(ExclamationCircleOutlined),
    content: es.value
      ? `Se eliminan el formulario y sus ${r.submissions} envíos. Los sitios que lo muestran dejarán de mostrarlo.`
      : `The form and its ${r.submissions} submissions are deleted. Websites showing it will stop showing it.`,
    okType: 'danger',
    okText: es.value ? 'Eliminar' : 'Delete',
    async onOk() {
      await http.delete(`forms/${r.id}`);
      message.success(es.value ? 'Eliminado.' : 'Deleted.');
      load();
    },
  });
}

const embedOpen = ref(false);
const embedId = ref(null);
function openEmbed(r) {
  embedId.value = r.id;
  embedOpen.value = true;
}

onMounted(load);
</script>

<style scoped>
.fl-toolbar { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 12px; flex-wrap: wrap; }
.fl-muted { opacity: 0.6; }
.fl-name { font-weight: 600; }
.fl-tag { margin-inline-start: 8px; }
.fl-badge { margin-inline-start: 8px; }
</style>
