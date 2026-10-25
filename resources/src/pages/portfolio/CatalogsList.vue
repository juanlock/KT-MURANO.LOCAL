<template>
  <div class="page">
    <PageHeader :title="$t('Portfolio_Catalogs')" :breadcrumb="['Portfolio', $t('Portfolio_Catalogs')]">
      <template #actions>
        <a-button type="primary" @click="openCreate"><template #icon><PlusOutlined /></template>{{ es ? 'Nuevo catálogo' : 'New catalog' }}</a-button>
      </template>
    </PageHeader>

    <a-alert type="info" show-icon class="cl-hint"
      :message="es ? 'Catálogos a pantalla completa para una tablet o iPad que se le entrega al cliente: Menu (restaurantes y comida) o Models (salones y barberías, con pasos para filtrar). Se abren con el botón Catálogo de la barra superior, junto a POS.' : 'Full-screen catalogs for a tablet or iPad handed to the customer: Menu (restaurants and food) or Models (salons and barbers, with steps to filter). Open them with the Catalog button in the top bar, next to POS.'" />

    <a-card :body-style="{ padding: '12px' }">
      <a-table :columns="columns" :data-source="rows" :loading="loading" row-key="id" size="middle" :pagination="{ pageSize: 20, hideOnSinglePage: true }">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'name'">
            <router-link :to="`/portfolio/catalogs/${record.id}`" class="cl-name">{{ record.name }}</router-link>
          </template>
          <template v-else-if="column.key === 'type'">
            <a-tag :color="record.type === 'menu' ? 'orange' : 'purple'">{{ record.type === 'menu' ? 'Menu' : 'Models' }}</a-tag>
          </template>
          <template v-else-if="column.key === 'status'">
            <a-tag :color="record.status ? 'green' : 'default'">{{ record.status ? (es ? 'Activo' : 'Active') : (es ? 'Apagado' : 'Off') }}</a-tag>
          </template>
          <template v-else-if="column.key === 'actions'">
            <a-space>
              <a-tooltip :title="es ? 'Abrir en la tablet' : 'Open on the tablet'">
                <a-button type="text" size="small" :href="record.links.relative" target="_blank"><template #icon><TabletOutlined /></template></a-button>
              </a-tooltip>
              <a-tooltip :title="es ? 'Editar' : 'Edit'">
                <a-button type="text" size="small" @click="$router.push(`/portfolio/catalogs/${record.id}`)"><template #icon><EditOutlined style="color: #52c41a" /></template></a-button>
              </a-tooltip>
              <a-tooltip :title="es ? 'Eliminar' : 'Delete'">
                <a-button type="text" size="small" danger @click="remove(record)"><template #icon><DeleteOutlined /></template></a-button>
              </a-tooltip>
            </a-space>
          </template>
        </template>
      </a-table>
    </a-card>

    <a-modal v-model:open="createOpen" :title="es ? 'Nuevo catálogo' : 'New catalog'" :confirm-loading="saving" @ok="create">
      <a-form layout="vertical">
        <a-form-item v-if="isSystem" :label="es ? 'Empresa' : 'Company'" required>
          <a-select v-model:value="form.company_id" :options="companyOptions" show-search option-filter-prop="label" />
        </a-form-item>
        <a-form-item :label="es ? 'Tipo' : 'Type'" required>
          <a-radio-group v-model:value="form.type" button-style="solid">
            <a-radio-button value="menu">Menu</a-radio-button>
            <a-radio-button value="models">Models</a-radio-button>
          </a-radio-group>
          <div class="cl-muted">{{ form.type === 'menu' ? (es ? 'Restaurantes y comida: grupos (Arroces, Sopas…) con platos y pedido a Cocina.' : 'Restaurants and food: groups (Rice, Soups…) with dishes and orders to the Kitchen.') : (es ? 'Salones y barberías: el cliente elige por pasos (Mujer/Hombre, Adulto/Niño) y ve sus servicios.' : 'Salons and barbers: the customer answers steps (Woman/Man, Adult/Child) and sees the services that fit.') }}</div>
        </a-form-item>
        <a-form-item :label="es ? 'Nombre' : 'Name'" required>
          <a-input v-model:value="form.name" :maxlength="150" @press-enter="create" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, createVNode } from 'vue';
import { useRouter } from 'vue-router';
import { message, Modal } from 'ant-design-vue';
import { PlusOutlined, EditOutlined, DeleteOutlined, TabletOutlined, ExclamationCircleOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { useUiStore } from '../../stores/ui';
import { useCompanyStore } from '../../stores/company';
import { useSystemContext } from '../../lib/systemContext';
import { sortByLabel } from '../../lib/filterOptions';

const router = useRouter();
const ui = useUiStore();
const es = computed(() => ui.locale === 'es');
const { isSystem } = useSystemContext();
const companyStore = useCompanyStore();
const companyOptions = computed(() => sortByLabel((companyStore.companies || []).filter(c => Number(c.id) !== 999).map(c => ({ value: c.id, label: c.name }))));

const rows = ref([]);
const loading = ref(false);
const columns = computed(() => [
  ...(isSystem.value ? [{ title: es.value ? 'Empresa' : 'Company', dataIndex: 'company_name', key: 'company', sorter: (a, b) => (a.company_name || '').localeCompare(b.company_name || '') }] : []),
  { title: es.value ? 'Catálogo' : 'Catalog', key: 'name', sorter: (a, b) => a.name.localeCompare(b.name) },
  { title: es.value ? 'Tipo' : 'Type', key: 'type', width: 110 },
  { title: es.value ? 'Grupos' : 'Groups', dataIndex: 'groups', key: 'groups', width: 90, align: 'right' },
  { title: es.value ? 'Productos' : 'Products', dataIndex: 'items', key: 'items', width: 100, align: 'right' },
  { title: es.value ? 'Pedidos hoy' : 'Orders today', dataIndex: 'orders_today', key: 'orders', width: 120, align: 'right' },
  { title: es.value ? 'Estado' : 'Status', key: 'status', width: 100 },
  { title: '', key: 'actions', width: 130, align: 'right' },
]);

async function load() {
  loading.value = true;
  try {
    rows.value = (await http.get('portfolio/catalogs')).catalogs || [];
  } catch (e) {
    message.error(e?.data?.message || e.message);
  } finally {
    loading.value = false;
  }
}

const createOpen = ref(false);
const saving = ref(false);
const form = ref({ name: '', type: 'menu', company_id: undefined });
function openCreate() {
  form.value = { name: '', type: 'menu', company_id: undefined };
  createOpen.value = true;
}
async function create() {
  if (!form.value.name.trim() || (isSystem.value && !form.value.company_id)) return;
  saving.value = true;
  try {
    const body = { name: form.value.name.trim(), type: form.value.type, ...(isSystem.value ? { company_id: form.value.company_id } : {}) };
    const res = await http.post('portfolio/catalogs', body);
    createOpen.value = false;
    router.push(`/portfolio/catalogs/${res.catalog.id}`);
  } catch (e) {
    message.error(e?.data?.message || e.message);
  } finally {
    saving.value = false;
  }
}

function remove(r) {
  Modal.confirm({
    title: (es.value ? 'Eliminar ' : 'Delete ') + r.name + '?',
    icon: createVNode(ExclamationCircleOutlined),
    content: es.value ? 'La tablet que lo usa dejará de mostrarlo. Los productos no se tocan.' : 'The tablet using it will stop showing it. Products are not touched.',
    okType: 'danger',
    okText: es.value ? 'Eliminar' : 'Delete',
    async onOk() {
      await http.delete(`portfolio/catalogs/${r.id}`);
      load();
    },
  });
}

onMounted(load);
</script>

<style scoped>
.cl-hint { margin-bottom: 12px; }
.cl-name { font-weight: 600; }
.cl-muted { opacity: 0.6; font-size: 12.5px; margin-top: 6px; }
</style>
