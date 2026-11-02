<template>
  <div class="page">
    <PageHeader :title="es ? 'Rutas y locales' : 'Routes and locations'" :breadcrumb="[es ? 'Transporte' : 'Transport', es ? 'Rutas y locales' : 'Routes and locations']">
      <template #actions>
        <a-button v-if="canManage" type="primary" @click="tab === 'routes' ? openRoute(null) : openLocation(null)">
          <PlusOutlined /> {{ tab === 'routes' ? (es ? 'Nueva ruta' : 'New route') : (es ? 'Nuevo local' : 'New location') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small">
      <a-tabs v-model:active-key="tab">
        <a-tab-pane key="routes" :tab="(es ? 'Rutas' : 'Routes') + ` (${routes.length})`">
          <a-input-search v-model:value="q" allow-clear style="max-width: 260px; margin-bottom: 10px" :placeholder="es ? 'Buscar' : 'Search'" />
          <a-table :columns="routeColumns" :data-source="filteredRoutes" row-key="id" size="middle" :loading="loading" :pagination="{ pageSize: 20, hideOnSinglePage: true }">
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'route'"><b>{{ record.origin }}</b> → <b>{{ record.destination }}</b></template>
              <template v-else-if="column.key === 'duration'">{{ record.duration_min ? hm(record.duration_min) : '—' }}</template>
              <template v-else-if="column.key === 'active'"><a-tag :color="record.active ? 'green' : 'default'">{{ record.active ? (es ? 'Activa' : 'Active') : (es ? 'Inactiva' : 'Inactive') }}</a-tag></template>
              <template v-else-if="column.key === 'actions'">
                <a-space v-if="canManage" :size="0">
                  <a-button type="text" size="small" @click="openRoute(record)"><EditOutlined /></a-button>
                  <a-popconfirm :title="es ? '¿Eliminar esta ruta?' : 'Delete this route?'" @confirm="remove('routes', record)"><a-button type="text" size="small" danger><DeleteOutlined /></a-button></a-popconfirm>
                </a-space>
              </template>
            </template>
          </a-table>
        </a-tab-pane>
        <a-tab-pane key="locations" :tab="(es ? 'Locales' : 'Locations') + ` (${locations.length})`">
          <a-input-search v-model:value="q" allow-clear style="max-width: 260px; margin-bottom: 10px" :placeholder="es ? 'Buscar' : 'Search'" />
          <a-table :columns="locationColumns" :data-source="filteredLocations" row-key="id" size="middle" :loading="loading" :pagination="{ pageSize: 20, hideOnSinglePage: true }">
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'photo'">
                <img v-if="record.image_url" :src="record.image_url" class="tr-thumb" alt="">
                <a-upload v-else-if="canManage" :show-upload-list="false" accept=".jpg,.jpeg,.png,.webp" :before-upload="f => upload(record, f)">
                  <a-button size="small" type="dashed">+ {{ es ? 'Foto' : 'Photo' }}</a-button>
                </a-upload>
              </template>
              <template v-else-if="column.key === 'kind'"><a-tag :color="KIND_COLOR[record.kind]">{{ kindLabel(record.kind) }}</a-tag></template>
              <template v-else-if="column.key === 'active'"><a-tag :color="record.active ? 'green' : 'default'">{{ record.active ? (es ? 'Activo' : 'Active') : (es ? 'Inactivo' : 'Inactive') }}</a-tag></template>
              <template v-else-if="column.key === 'actions'">
                <a-space v-if="canManage" :size="0">
                  <a-button type="text" size="small" @click="openLocation(record)"><EditOutlined /></a-button>
                  <a-popconfirm :title="es ? '¿Eliminar este local?' : 'Delete this location?'" @confirm="remove('locations', record)"><a-button type="text" size="small" danger><DeleteOutlined /></a-button></a-popconfirm>
                </a-space>
              </template>
            </template>
          </a-table>
        </a-tab-pane>
      </a-tabs>
    </a-card>

    <a-modal v-model:open="routeOpen" :title="routeForm.id ? (es ? 'Editar ruta' : 'Edit route') : (es ? 'Nueva ruta' : 'New route')" :confirm-loading="saving" :ok-text="es ? 'Guardar' : 'Save'" @ok="saveRoute">
      <a-form layout="vertical">
        <a-row :gutter="12">
          <a-col :span="12"><a-form-item :label="es ? 'Origen *' : 'Origin *'"><a-select v-model:value="routeForm.origin_id" :options="locationOptions" show-search option-filter-prop="label" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="es ? 'Destino *' : 'Destination *'"><a-select v-model:value="routeForm.destination_id" :options="locationOptions" show-search option-filter-prop="label" /></a-form-item></a-col>
          <a-col :span="8"><a-form-item :label="es ? 'Distancia (km)' : 'Distance (km)'"><a-input-number v-model:value="routeForm.distance_km" :min="0" style="width: 100%" /></a-form-item></a-col>
          <a-col :span="8"><a-form-item :label="es ? 'Duración (min)' : 'Duration (min)'"><a-input-number v-model:value="routeForm.duration_min" :min="0" style="width: 100%" /></a-form-item></a-col>
          <a-col :span="8"><a-form-item :label="es ? 'Activa' : 'Active'"><a-switch v-model:checked="routeForm.active" /></a-form-item></a-col>
        </a-row>
      </a-form>
    </a-modal>

    <a-modal v-model:open="locationOpen" :title="locationForm.id ? (es ? 'Editar local' : 'Edit location') : (es ? 'Nuevo local' : 'New location')" :confirm-loading="saving" :ok-text="es ? 'Guardar' : 'Save'" @ok="saveLocation">
      <a-form layout="vertical">
        <a-form-item :label="es ? 'Nombre *' : 'Name *'"><a-input v-model:value="locationForm.name" :maxlength="120" /></a-form-item>
        <a-row :gutter="12">
          <a-col :span="12"><a-form-item :label="es ? 'Ciudad' : 'City'"><a-input v-model:value="locationForm.city" :maxlength="120" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="es ? 'Tipo' : 'Type'"><a-select v-model:value="locationForm.kind" :options="kindOptions" /></a-form-item></a-col>
        </a-row>
        <a-row :gutter="12">
          <a-col :span="14"><a-form-item :label="es ? 'Dirección' : 'Address'"><a-input v-model:value="locationForm.address" :maxlength="190" /></a-form-item></a-col>
          <a-col :span="10"><a-form-item :label="es ? 'Teléfono' : 'Phone'"><a-input v-model:value="locationForm.phone" :maxlength="60" /></a-form-item></a-col>
        </a-row>
        <a-form-item :label="es ? 'Descripción' : 'Description'"><a-textarea v-model:value="locationForm.description" :rows="2" /></a-form-item>
        <!-- Position on the dashboard map: empty = the city's, when known; an agency
             without a terminal picked = the nearest one. -->
        <a-row v-if="locationForm.kind !== 'online'" :gutter="12">
          <a-col v-if="locationForm.kind === 'agency'" :span="10">
            <a-form-item :label="es ? 'Terminal a la que pertenece' : 'Terminal it belongs to'">
              <a-select v-model:value="locationForm.hub_id" :options="terminalOptions" allow-clear show-search option-filter-prop="label" />
            </a-form-item>
          </a-col>
          <a-col :span="locationForm.kind === 'agency' ? 7 : 12"><a-form-item :label="es ? 'Latitud' : 'Latitude'"><a-input-number v-model:value="locationForm.lat" :min="-90" :max="90" :step="0.0001" style="width: 100%" /></a-form-item></a-col>
          <a-col :span="locationForm.kind === 'agency' ? 7 : 12"><a-form-item :label="es ? 'Longitud' : 'Longitude'"><a-input-number v-model:value="locationForm.lng" :min="-180" :max="180" :step="0.0001" style="width: 100%" /></a-form-item></a-col>
        </a-row>
        <a-form-item :label="es ? 'Activo' : 'Active'" style="margin-bottom: 0"><a-switch v-model:checked="locationForm.active" /></a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Transport -> Routes and locations (2026-11-01): the company's terminals, agencies and
 * online channels, and the routes (origin -> destination, distance, duration) its trips use.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { useAuthStore } from '../../stores/auth';
import { useUiStore } from '../../stores/ui';
import { sortByLabel } from '../../lib/filterOptions';

const auth = useAuthStore();
const es = computed(() => useUiStore().locale === 'es');
const canManage = computed(() => auth.can('transport_manage'));

const KINDS = [['terminal', 'Terminal', 'Terminal'], ['agency', 'Agency', 'Agencia'], ['online', 'Online', 'En línea']];
const KIND_COLOR = { terminal: 'purple', agency: 'blue', online: 'cyan' };
const kindLabel = k => { const x = KINDS.find(r => r[0] === k); return x ? (es.value ? x[2] : x[1]) : k; };
const kindOptions = computed(() => KINDS.map(k => ({ value: k[0], label: es.value ? k[2] : k[1] })));
const hm = m => (m >= 60 ? `${Math.floor(m / 60)} h ${m % 60 ? (m % 60) + ' min' : ''}` : `${m} min`);

const tab = ref('routes');
const q = ref('');
const loading = ref(false);
const routes = ref([]);
const locations = ref([]);
const has = (s, t) => String(s || '').toLowerCase().includes(t);
const filteredRoutes = computed(() => { const t = q.value.trim().toLowerCase(); return t ? routes.value.filter(r => has(r.label, t)) : routes.value; });
const filteredLocations = computed(() => { const t = q.value.trim().toLowerCase(); return t ? locations.value.filter(l => has(l.name, t) || has(l.city, t)) : locations.value; });
const locationOptions = computed(() => sortByLabel(locations.value.filter(l => l.active).map(l => ({ value: l.id, label: l.name }))));
// The terminal an agency belongs to (dashboard map and counts).
const terminalOptions = computed(() => sortByLabel(locations.value.filter(l => l.kind === 'terminal' && l.id !== locationForm.value.id).map(l => ({ value: l.id, label: l.name }))));

const routeColumns = computed(() => [
  { title: es.value ? 'Ruta' : 'Route', key: 'route' },
  { title: es.value ? 'Distancia (km)' : 'Distance (km)', dataIndex: 'distance_km', key: 'distance' },
  { title: es.value ? 'Duración' : 'Duration', key: 'duration' },
  { title: es.value ? 'Estado' : 'Status', key: 'active', width: 110 },
  { title: '', key: 'actions', width: 90 },
]);
const locationColumns = computed(() => [
  { title: '', key: 'photo', width: 90 },
  { title: es.value ? 'Nombre' : 'Name', dataIndex: 'name', key: 'name' },
  { title: es.value ? 'Ciudad' : 'City', dataIndex: 'city', key: 'city' },
  { title: es.value ? 'Dirección' : 'Address', dataIndex: 'address', key: 'address' },
  { title: es.value ? 'Teléfono' : 'Phone', dataIndex: 'phone', key: 'phone' },
  { title: es.value ? 'Tipo' : 'Type', key: 'kind', width: 120 },
  { title: es.value ? 'Estado' : 'Status', key: 'active', width: 110 },
  { title: '', key: 'actions', width: 90 },
]);

async function load() {
  loading.value = true;
  try {
    const [r, l] = await Promise.all([http.get('transport/routes'), http.get('transport/locations')]);
    routes.value = r.routes;
    locations.value = l.locations;
  } catch (e) {
    message.error(e?.data?.message || e.message);
  } finally {
    loading.value = false;
  }
}

const saving = ref(false);
const routeOpen = ref(false);
const routeForm = ref({});
const locationOpen = ref(false);
const locationForm = ref({});
function openRoute(r) {
  routeForm.value = r ? { id: r.id, origin_id: r.origin_id, destination_id: r.destination_id, distance_km: r.distance_km, duration_min: r.duration_min, active: !!r.active }
    : { origin_id: undefined, destination_id: undefined, distance_km: null, duration_min: null, active: true };
  routeOpen.value = true;
}
function openLocation(l) {
  locationForm.value = l
    ? { id: l.id, name: l.name, city: l.city, address: l.address, phone: l.phone, description: l.description, kind: l.kind, active: !!l.active,
      lat: l.lat != null ? Number(l.lat) : null, lng: l.lng != null ? Number(l.lng) : null, hub_id: l.hub_id || undefined }
    : { name: '', city: '', address: '', phone: '', description: '', kind: 'agency', active: true, lat: null, lng: null, hub_id: undefined };
  locationOpen.value = true;
}
async function send(url, body, id, close) {
  saving.value = true;
  try {
    if (id) await http.put(`${url}/${id}`, body); else await http.post(url, body);
    message.success(es.value ? 'Guardado.' : 'Saved.');
    close();
    load();
  } catch (e) {
    const errs = e?.data?.errors;
    message.error((errs && Object.values(errs)[0]?.[0]) || e?.data?.message || e.message);
  } finally {
    saving.value = false;
  }
}
const saveRoute = () => send('transport/routes', routeForm.value, routeForm.value.id, () => { routeOpen.value = false; });
const saveLocation = () => send('transport/locations', locationForm.value, locationForm.value.id, () => { locationOpen.value = false; });
async function upload(r, file) {
  const fd = new FormData();
  fd.append('image', file);
  try {
    const res = await http.postForm(`transport/locations/${r.id}/image`, fd);
    r.image_url = res.image_url;
  } catch (e) {
    message.error(e?.data?.message || e.message);
  }
  return false;
}

async function remove(kind, r) {
  try { await http.delete(`transport/${kind}/${r.id}`); load(); } catch (e) { message.error(e?.data?.message || e.message); }
}

onMounted(load);
</script>

<style scoped>
.tr-thumb { width: 72px; height: 44px; object-fit: cover; border-radius: 6px; display: block; }
</style>
