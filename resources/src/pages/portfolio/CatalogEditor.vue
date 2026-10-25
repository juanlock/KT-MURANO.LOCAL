<template>
  <div class="page">
    <PageHeader :title="c ? c.name : ''" :breadcrumb="['Portfolio', $t('Portfolio_Catalogs')]">
      <template #actions>
        <a-space wrap>
          <a-button @click="goBack($router, '/portfolio/catalogs')">{{ es ? 'Volver' : 'Back' }}</a-button>
          <a-button v-if="c" :href="c.links.relative" target="_blank"><template #icon><TabletOutlined /></template>{{ es ? 'Abrir en la tablet' : 'Open on the tablet' }}</a-button>
          <a-button type="primary" :loading="saving" @click="save"><template #icon><SaveOutlined /></template>{{ es ? 'Guardar' : 'Save' }}</a-button>
        </a-space>
      </template>
    </PageHeader>

    <a-spin :spinning="loading">
      <a-card v-if="c" :body-style="{ padding: '12px 16px' }">
        <div class="ce-top">
          <a-input v-model:value="c.name" :maxlength="150" class="ce-name" @change="dirty = true" />
          <a-tag :color="c.type === 'menu' ? 'orange' : 'purple'">{{ c.type === 'menu' ? 'Menu' : 'Models' }}</a-tag>
          <span class="ce-switch"><a-switch v-model:checked="c.status" @change="dirty = true" /> {{ c.status ? (es ? 'Activo' : 'Active') : (es ? 'Apagado' : 'Off') }}</span>
          <a-tag v-if="dirty" color="orange">{{ es ? 'Cambios sin guardar' : 'Unsaved changes' }}</a-tag>
        </div>

        <a-tabs v-model:activeKey="tab">
          <!-- ===== Groups and products ===== -->
          <a-tab-pane key="groups" :tab="es ? 'Grupos y productos' : 'Groups and products'">
            <div class="ce-grid">
              <div>
                <div class="ce-h">{{ es ? 'Grupos' : 'Groups' }}</div>
                <div v-for="(g, i) in c.groups" :key="g.id" class="ce-group" :class="{ on: g.id === groupId }" @click="groupId = g.id">
                  <img v-if="g.image_url" :src="g.image_url" alt="">
                  <span v-else class="ce-noimg" />
                  <span class="ce-gname">{{ g.name }}</span>
                  <span class="ce-count">{{ itemsOf(g.id).length }}</span>
                  <span class="ce-gact" @click.stop>
                    <a-button type="text" size="small" :disabled="i === 0" @click="moveGroup(i, -1)"><ArrowUpOutlined /></a-button>
                    <a-button type="text" size="small" :disabled="i === c.groups.length - 1" @click="moveGroup(i, 1)"><ArrowDownOutlined /></a-button>
                  </span>
                </div>
                <a-input-search v-model:value="newGroup" :placeholder="es ? 'Nuevo grupo (ej. Arroces)' : 'New group (e.g. Rice)'" class="ce-newg" @search="addGroup">
                  <template #enterButton><a-button><PlusOutlined /></a-button></template>
                </a-input-search>
              </div>

              <div v-if="group">
                <div class="ce-gh">
                  <a-input v-model:value="group.name" :maxlength="120" style="max-width: 260px" @blur="saveGroup(group)" @press-enter="saveGroup(group)" />
                  <a-upload :show-upload-list="false" accept="image/*" :before-upload="f => uploadGroupImage(group, f)">
                    <a-button size="small"><PictureOutlined /> {{ es ? 'Imagen del grupo' : 'Group picture' }}</a-button>
                  </a-upload>
                  <a-button size="small" danger @click="removeGroup(group)"><DeleteOutlined /></a-button>
                </div>
                <a-select
                  v-model:value="toAdd" mode="multiple" :filter-option="false" :options="productOptions" :loading="searching" show-search
                  :placeholder="es ? 'Buscar productos para agregar…' : 'Search products to add…'" style="width: 100%" @search="searchProducts" @focus="searchProducts('')"
                />
                <a-button type="primary" size="small" class="ce-add" :disabled="!toAdd.length" @click="addItems">{{ es ? 'Agregar al grupo' : 'Add to the group' }}</a-button>

                <div class="ce-items">
                  <div v-for="(it, i) in itemsOf(group.id)" :key="it.id" class="ce-item" :class="{ off: !it.available }">
                    <img v-if="it.image_url" :src="it.image_url" alt="">
                    <span v-else class="ce-noimg" />
                    <div class="ce-iinfo">
                      <div class="ce-iname">{{ it.name }} <a-tag v-if="!it.available" color="red">{{ es ? 'no disponible' : 'unavailable' }}</a-tag></div>
                      <div class="ce-muted">{{ c.currency }}{{ Number(it.price).toFixed(2) }}<span v-if="it.code"> · {{ it.code }}</span></div>
                      <div v-if="c.type === 'models' && s.steps.length" class="ce-opts">
                        <span v-for="st in s.steps" :key="st.id" class="ce-opt">
                          <b>{{ st.name }}:</b>
                          <a-checkbox-group :value="(it.options && it.options[st.id]) || []" :options="st.options.map(o => ({ value: o.id, label: o.label }))" @change="v => setOptions(it, st.id, v)" />
                        </span>
                      </div>
                    </div>
                    <span class="ce-iact">
                      <a-button size="small" @click="photosOf = it.id"><PictureOutlined /> {{ es ? 'Modelos' : 'Models' }} ({{ (it.photos || []).length }})</a-button>
                      <a-button type="text" size="small" :disabled="i === 0" @click="moveItem(group.id, i, -1)"><ArrowUpOutlined /></a-button>
                      <a-button type="text" size="small" @click="moveItem(group.id, i, 1)"><ArrowDownOutlined /></a-button>
                      <a-button type="text" size="small" danger @click="removeItem(it)"><DeleteOutlined /></a-button>
                    </span>
                  </div>
                  <a-empty v-if="!itemsOf(group.id).length" :description="es ? 'Agrega productos de Productos (imagen, nombre y precio salen de ahí).' : 'Add products from Products (picture, name and price come from there).'" />
                </div>
                <div v-if="c.type === 'models'" class="ce-muted ce-small">{{ es ? 'Sin opciones marcadas = el servicio se muestra para todas las respuestas.' : 'No option ticked = the service shows for every answer.' }}</div>
              </div>
              <a-empty v-else :description="es ? 'Crea un grupo (ej. Arroces, Sopas, Postres, Bebidas).' : 'Create a group (e.g. Rice, Soups, Desserts, Drinks).'" />
            </div>
          </a-tab-pane>

          <!-- ===== Steps (models) ===== -->
          <a-tab-pane v-if="c.type === 'models'" key="steps" :tab="es ? 'Pasos' : 'Steps'">
            <p class="ce-muted">{{ es ? 'Lo que el cliente elige antes de ver los servicios, en orden (ej. ¿Para quién es? Mujer / Hombre; Edad: Adulto / Niño).' : 'What the customer picks before seeing the services, in order (e.g. Who is it for? Woman / Man; Age: Adult / Child).' }}</p>
            <div v-for="(st, i) in s.steps" :key="st.id" class="ce-step">
              <div class="ce-steph">
                <a-input v-model:value="st.name" :maxlength="80" style="max-width: 320px" @change="dirty = true" />
                <a-button size="small" danger @click="s.steps.splice(i, 1); dirty = true"><DeleteOutlined /></a-button>
              </div>
              <div v-for="(o, j) in st.options" :key="o.id" class="ce-stepo">
                <img v-if="o.image" :src="'/' + o.image" alt="">
                <span v-else class="ce-noimg" />
                <a-input v-model:value="o.label" :maxlength="60" style="max-width: 220px" @change="dirty = true" />
                <a-upload :show-upload-list="false" accept="image/*" :before-upload="f => uploadOptionImage(o, f)"><a-button size="small"><PictureOutlined /></a-button></a-upload>
                <a-button size="small" danger @click="st.options.splice(j, 1); dirty = true"><DeleteOutlined /></a-button>
              </div>
              <a-button size="small" @click="st.options.push({ id: rid('o'), label: es ? 'Opción' : 'Option', image: '' }); dirty = true"><PlusOutlined /> {{ es ? 'Opción' : 'Option' }}</a-button>
            </div>
            <a-button @click="s.steps.push({ id: rid('s'), name: es ? 'Nuevo paso' : 'New step', options: [{ id: rid('o'), label: es ? 'Opción 1' : 'Option 1', image: '' }] }); dirty = true"><PlusOutlined /> {{ es ? 'Agregar paso' : 'Add step' }}</a-button>
            <div class="ce-muted ce-small">{{ es ? 'Guarda para aplicar los pasos.' : 'Save to apply the steps.' }}</div>
          </a-tab-pane>

          <!-- ===== General ===== -->
          <a-tab-pane key="general" :tab="es ? 'Pantalla y pedido' : 'Screen and order'">
            <a-form layout="vertical" class="ce-form" @change="dirty = true">
              <a-row :gutter="24">
                <a-col :xs="24" :lg="12">
                  <a-form-item :label="es ? 'Título' : 'Title'"><a-input v-model:value="s.title" :maxlength="190" :placeholder="c.name" /></a-form-item>
                  <a-form-item :label="es ? 'Subtítulo' : 'Subtitle'"><a-input v-model:value="s.subtitle" :maxlength="190" /></a-form-item>
                  <a-form-item :label="es ? 'Logo' : 'Logo'">
                    <a-space>
                      <img v-if="s.logo" :src="'/' + s.logo" class="ce-logo" alt="">
                      <a-upload :show-upload-list="false" accept="image/*" :before-upload="uploadLogo"><a-button size="small"><PictureOutlined /> {{ es ? 'Subir' : 'Upload' }}</a-button></a-upload>
                      <a-button v-if="s.logo" size="small" @click="s.logo = ''; dirty = true">{{ es ? 'Quitar' : 'Remove' }}</a-button>
                    </a-space>
                  </a-form-item>
                  <a-form-item :label="es ? 'Imágenes de portada (slider, mitad superior)' : 'Cover pictures (slider, top half)'">
                    <div class="ce-slides">
                      <div v-for="(p, i) in s.slides" :key="p" class="ce-slide">
                        <img :src="'/' + p" alt="">
                        <a-button size="small" danger class="ce-slide-x" @click="s.slides.splice(i, 1); dirty = true"><DeleteOutlined /></a-button>
                      </div>
                      <a-upload :show-upload-list="false" accept="image/*" multiple :before-upload="uploadSlide">
                        <div class="ce-slide ce-slide-add"><PlusOutlined /></div>
                      </a-upload>
                    </div>
                  </a-form-item>
                  <a-row :gutter="12">
                    <a-col :span="8"><a-form-item :label="es ? 'Color' : 'Color'"><input v-model="s.accent" type="color" class="ce-color" @input="dirty = true"></a-form-item></a-col>
                    <a-col :span="16">
                      <a-form-item :label="es ? 'Idioma' : 'Language'">
                        <a-radio-group v-model:value="s.language" button-style="solid" @change="dirty = true">
                          <a-radio-button value="es">Español</a-radio-button>
                          <a-radio-button value="en">English</a-radio-button>
                        </a-radio-group>
                      </a-form-item>
                    </a-col>
                  </a-row>
                  <a-form-item><a-checkbox v-model:checked="s.show_prices" @change="dirty = true">{{ es ? 'Mostrar precios' : 'Show prices' }}</a-checkbox></a-form-item>
                </a-col>
                <a-col :xs="24" :lg="12">
                  <div class="ce-h">{{ es ? 'Pedido desde la tablet' : 'Order from the tablet' }}</div>
                  <a-form-item><a-checkbox v-model:checked="s.orders" @change="dirty = true">{{ es ? 'El cliente puede enviar su pedido' : 'The customer can send an order' }}</a-checkbox></a-form-item>
                  <template v-if="s.orders">
                    <a-form-item><a-checkbox v-model:checked="s.ask_table" @change="dirty = true">{{ es ? 'Pedir' : 'Ask for' }}</a-checkbox>
                      <a-input v-model:value="s.table_label" :maxlength="40" style="width: 160px" :placeholder="es ? 'Mesa' : 'Table'" />
                    </a-form-item>
                    <a-form-item><a-checkbox v-model:checked="s.hold_in_pos" @change="dirty = true">{{ es ? 'Queda pendiente en el POS para cobrarlo (borradores recientes)' : 'Waits in the POS to be charged (recent drafts)' }}</a-checkbox></a-form-item>
                    <a-form-item :extra="es ? 'Necesita la pantalla de Cocina encendida en la empresa (Configuración POS).' : 'Needs the company\'s Kitchen display on (POS settings).'">
                      <a-checkbox v-model:checked="s.send_kitchen" @change="dirty = true">{{ es ? 'Enviar a Cocina' : 'Send to the Kitchen' }}</a-checkbox>
                    </a-form-item>
                    <a-form-item :label="es ? 'Mensaje al enviar' : 'Message after sending'"><a-input v-model:value="s.thanks" :maxlength="190" /></a-form-item>
                  </template>
                </a-col>
              </a-row>
            </a-form>
          </a-tab-pane>

          <!-- ===== Orders ===== -->
          <a-tab-pane key="orders" :tab="es ? 'Pedidos' : 'Orders'">
            <a-table :columns="orderColumns" :data-source="orders" row-key="id" size="small" :loading="ordersLoading" :pagination="{ pageSize: 20, hideOnSinglePage: true }">
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'lines'">
                  <div v-for="(l, n) in record.lines" :key="n" class="ce-oline">
                    <a v-if="l.photo" :href="'/' + l.photo" target="_blank"><img :src="'/' + l.photo" alt=""></a>
                    {{ l.qty }} × {{ l.name }}<span v-if="l.note" class="ce-muted"> — {{ l.note }}</span>
                  </div>
                </template>
                <template v-else-if="column.key === 'total'">{{ c.currency }}{{ Number(record.total).toFixed(2) }}</template>
                <template v-else-if="column.key === 'date'">{{ fmt(record.created_at) }}</template>
              </template>
            </a-table>
          </a-tab-pane>

          <!-- ===== Open ===== -->
          <a-tab-pane key="open" :tab="es ? 'Abrir en la tablet' : 'Open on the tablet'">
            <p class="ce-muted">{{ es ? 'En la tablet: abre este enlace (o escanea el QR) y agrégalo a la pantalla de inicio para verlo a pantalla completa. También se abre con el botón Catálogo de la barra superior.' : 'On the tablet: open this link (or scan the QR) and add it to the home screen to see it full screen. It also opens with the Catalog button in the top bar.' }}</p>
            <a-input-group compact class="ce-link">
              <a-input :value="c.links.page" readonly style="width: calc(100% - 100px)" />
              <a-button :href="c.links.relative" target="_blank"><ExportOutlined /></a-button>
              <a-button @click="copy(c.links.page)"><CopyOutlined /></a-button>
            </a-input-group>
            <a-qrcode :value="c.links.page" :size="200" class="ce-qr" />
          </a-tab-pane>
        </a-tabs>
      </a-card>
    </a-spin>

    <!-- Model photos of one item: tapping its picture on the tablet shows them; the customer picks one. -->
    <a-modal :open="!!photoItem" :title="(es ? 'Modelos (fotos) — ' : 'Models (photos) — ') + (photoItem?.name || '')" :footer="null" width="820px" @cancel="photosOf = null">
      <p class="ce-muted">{{ es ? 'En la tablet, al tocar la imagen del servicio se muestran estas fotos y el cliente elige su modelo (sale en el pedido como "Modelo N").' : 'On the tablet, tapping the service picture shows these photos and the customer picks a style (it shows on the order as "Model N").' }}</p>
      <div class="ce-padd">
        <a-upload :before-upload="uploadPhoto" :show-upload-list="false" accept="image/*" multiple>
          <a-button type="primary" :loading="photoBusy > 0"><UploadOutlined /> {{ es ? 'Subir fotos' : 'Upload photos' }}</a-button>
        </a-upload>
        <!-- Tablet / phone: opens the camera; a computer without one gets the file picker. -->
        <a-button :loading="photoBusy > 0" @click="cameraInput?.click()"><CameraOutlined /> {{ es ? 'Tomar foto' : 'Take photo' }}</a-button>
        <input ref="cameraInput" type="file" accept="image/*" capture="environment" class="ce-hidden" @change="onCamera">
        <a-input-search
          v-model:value="photoUrl" :placeholder="es ? 'O pega el enlace de una imagen de la web' : 'Or paste a picture link from the web'"
          :enter-button="es ? 'Agregar' : 'Add'" :loading="urlBusy" class="ce-purl" @search="addFromUrl"
        >
          <template #prefix><LinkOutlined /></template>
        </a-input-search>
      </div>
      <div class="ce-muted ce-small">{{ es ? 'Las fotos grandes se reducen solas (máx. 1600 px). Usa fotos propias o con permiso de uso.' : 'Large photos are reduced automatically (max 1600 px). Use your own photos or ones you may use.' }}</div>
      <div v-if="photoItem" class="ce-photos">
        <div v-for="(p, n) in photoItem.photos" :key="p.path" class="ce-photo">
          <img :src="p.url" alt="">
          <span class="ce-pn">{{ n + 1 }}</span>
          <span class="ce-pact">
            <a-button size="small" :disabled="n === 0" @click="movePhoto(n, -1)"><ArrowUpOutlined style="transform: rotate(-90deg)" /></a-button>
            <a-button size="small" :disabled="n === photoItem.photos.length - 1" @click="movePhoto(n, 1)"><ArrowDownOutlined style="transform: rotate(-90deg)" /></a-button>
            <a-button size="small" danger @click="removePhoto(n)"><DeleteOutlined /></a-button>
          </span>
        </div>
      </div>
      <a-empty v-if="photoItem && !photoItem.photos.length" :description="es ? 'Sin fotos: en la tablet el servicio se muestra sin modelos.' : 'No photos: the service shows without models on the tablet.'" />
    </a-modal>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, createVNode } from 'vue';
import { useRoute, useRouter, onBeforeRouteLeave } from 'vue-router';
import { message, Modal } from 'ant-design-vue';
import {
  SaveOutlined, TabletOutlined, PlusOutlined, DeleteOutlined, ArrowUpOutlined, ArrowDownOutlined, PictureOutlined,
  ExportOutlined, CopyOutlined, ExclamationCircleOutlined, UploadOutlined, CameraOutlined, LinkOutlined,
} from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { goBack } from '../../lib/goBack';
import { useUiStore } from '../../stores/ui';

const route = useRoute();
const router = useRouter();
const ui = useUiStore();
const es = computed(() => ui.locale === 'es');

const c = ref(null);
const loading = ref(false);
const saving = ref(false);
const dirty = ref(false);
const tab = ref('groups');
const groupId = ref(null);
const s = computed(() => c.value.settings);
const group = computed(() => c.value?.groups.find(g => g.id === groupId.value) || null);
const itemsOf = gid => (c.value?.items || []).filter(i => i.group_id === gid);
const fmt = d => (d ? dayjs(d).format('YYYY-MM-DD HH:mm') : '');
const rid = p => p + Math.random().toString(36).slice(2, 8);

function take(catalog) {
  c.value = catalog;
  if (!catalog.groups.find(g => g.id === groupId.value)) groupId.value = catalog.groups[0]?.id ?? null;
}
async function load() {
  loading.value = true;
  try {
    take((await http.get(`portfolio/catalogs/${route.params.id}`)).catalog);
    dirty.value = false;
  } catch (e) {
    message.error(e?.data?.message || e.message);
  } finally {
    loading.value = false;
  }
}
const err = e => message.error(e?.data?.message || (e?.data?.errors && Object.values(e.data.errors)[0]?.[0]) || e.message);

async function save() {
  saving.value = true;
  try {
    take((await http.put(`portfolio/catalogs/${c.value.id}`, { name: c.value.name, status: c.value.status, settings: c.value.settings })).catalog);
    dirty.value = false;
    message.success(es.value ? 'Guardado.' : 'Saved.');
  } catch (e) { err(e); } finally { saving.value = false; }
}

// ---- pictures
async function upload(file) {
  const fd = new FormData();
  fd.append('image', file);
  return http.postForm(`portfolio/catalogs/${c.value.id}/image`, fd);
}
async function uploadLogo(file) { try { s.value.logo = (await upload(file)).path; dirty.value = true; } catch (e) { err(e); } return false; }
async function uploadSlide(file) { try { s.value.slides.push((await upload(file)).path); dirty.value = true; } catch (e) { err(e); } return false; }
async function uploadOptionImage(o, file) { try { o.image = (await upload(file)).path; dirty.value = true; } catch (e) { err(e); } return false; }
async function uploadGroupImage(g, file) {
  try { const r = await upload(file); take((await http.put(`portfolio/catalogs/groups/${g.id}`, { name: g.name, image: r.path })).catalog); } catch (e) { err(e); }
  return false;
}

// ---- groups
const newGroup = ref('');
async function addGroup() {
  if (!newGroup.value.trim()) return;
  try {
    take((await http.post(`portfolio/catalogs/${c.value.id}/groups`, { name: newGroup.value.trim() })).catalog);
    groupId.value = c.value.groups[c.value.groups.length - 1].id;
    newGroup.value = '';
  } catch (e) { err(e); }
}
async function saveGroup(g) {
  try { take((await http.put(`portfolio/catalogs/groups/${g.id}`, { name: g.name, image: g.image })).catalog); } catch (e) { err(e); }
}
async function moveGroup(i, d) {
  const ids = c.value.groups.map(g => g.id);
  [ids[i], ids[i + d]] = [ids[i + d], ids[i]];
  try { take((await http.post(`portfolio/catalogs/${c.value.id}/groups/reorder`, { ids })).catalog); } catch (e) { err(e); }
}
function removeGroup(g) {
  Modal.confirm({
    title: (es.value ? 'Eliminar el grupo ' : 'Delete group ') + g.name + '?',
    icon: createVNode(ExclamationCircleOutlined),
    content: es.value ? 'Sale del catálogo con sus productos; los productos siguen en Productos.' : 'It leaves the catalog with its products; the products stay in Products.',
    okType: 'danger',
    async onOk() { take((await http.delete(`portfolio/catalogs/groups/${g.id}`)).catalog); },
  });
}

// ---- items
const toAdd = ref([]);
const productOptions = ref([]);
const searching = ref(false);
let searchTimer = null;
function searchProducts(q) {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(async () => {
    searching.value = true;
    try {
      const inGroup = new Set(itemsOf(groupId.value).map(i => i.product_id));
      const res = await http.get(`portfolio/catalogs/${c.value.id}/products`, { search: q || undefined });
      productOptions.value = res.products.filter(p => !inGroup.has(p.id)).map(p => ({ value: p.id, label: `${p.name} · ${c.value.currency}${Number(p.price).toFixed(2)}` }));
    } catch (e) { err(e); } finally { searching.value = false; }
  }, 250);
}
async function addItems() {
  try {
    take((await http.post(`portfolio/catalogs/groups/${groupId.value}/items`, { product_ids: toAdd.value })).catalog);
    toAdd.value = [];
    searchProducts('');
  } catch (e) { err(e); }
}
async function moveItem(gid, i, d) {
  const ids = itemsOf(gid).map(x => x.id);
  if (i + d < 0 || i + d >= ids.length) return;
  [ids[i], ids[i + d]] = [ids[i + d], ids[i]];
  try { take((await http.post(`portfolio/catalogs/groups/${gid}/items/reorder`, { ids })).catalog); } catch (e) { err(e); }
}
async function removeItem(it) {
  try { take((await http.delete(`portfolio/catalogs/items/${it.id}`)).catalog); } catch (e) { err(e); }
}
async function setOptions(it, stepId, values) {
  const options = { ...(it.options || {}), [stepId]: values };
  try { take((await http.put(`portfolio/catalogs/items/${it.id}`, { options })).catalog); } catch (e) { err(e); }
}

// ---- model photos of an item
const photosOf = ref(null);
const photoItem = computed(() => (c.value?.items || []).find(i => i.id === photosOf.value) || null);
const photoBusy = ref(0);
async function uploadPhoto(file) {
  const fd = new FormData();
  fd.append('photos[]', file);
  photoBusy.value++;
  try { take((await http.postForm(`portfolio/catalogs/items/${photosOf.value}/photos`, fd)).catalog); } catch (e) { err(e); } finally { photoBusy.value--; }
  return false;
}
const cameraInput = ref(null);
async function onCamera(e) {
  const file = e.target.files?.[0];
  e.target.value = '';
  if (file) await uploadPhoto(file);
}
const photoUrl = ref('');
const urlBusy = ref(false);
async function addFromUrl() {
  const url = photoUrl.value.trim();
  if (!url) return;
  urlBusy.value = true;
  try {
    take((await http.post(`portfolio/catalogs/items/${photosOf.value}/photos/url`, { url })).catalog);
    photoUrl.value = '';
    message.success(es.value ? 'Foto agregada.' : 'Photo added.');
  } catch (e) { err(e); } finally { urlBusy.value = false; }
}
async function savePhotos(paths) {
  try { take((await http.put(`portfolio/catalogs/items/${photosOf.value}/photos`, { photos: paths })).catalog); } catch (e) { err(e); }
}
function movePhoto(n, d) {
  const paths = photoItem.value.photos.map(p => p.path);
  [paths[n], paths[n + d]] = [paths[n + d], paths[n]];
  savePhotos(paths);
}
function removePhoto(n) {
  savePhotos(photoItem.value.photos.map(p => p.path).filter((p, i) => i !== n));
}

// ---- orders
const orders = ref([]);
const ordersLoading = ref(false);
const orderColumns = computed(() => [
  { title: '#', dataIndex: 'number', key: 'number', width: 70 },
  { title: es.value ? 'Fecha' : 'Date', key: 'date', width: 150 },
  { title: c.value?.settings.table_label || (es.value ? 'Mesa' : 'Table'), dataIndex: 'table_label', key: 'table', width: 110 },
  { title: es.value ? 'Pedido' : 'Order', key: 'lines' },
  { title: es.value ? 'Notas' : 'Notes', dataIndex: 'notes', key: 'notes' },
  { title: 'Total', key: 'total', width: 110, align: 'right' },
]);
watch(tab, async t => {
  if (t !== 'orders') return;
  ordersLoading.value = true;
  try { orders.value = (await http.get(`portfolio/catalogs/${c.value.id}/orders`)).orders; } catch (e) { err(e); } finally { ordersLoading.value = false; }
});

async function copy(t) {
  try { await navigator.clipboard.writeText(t); message.success(es.value ? 'Copiado.' : 'Copied.'); } catch (e) { /* select by hand */ }
}

onBeforeRouteLeave((to, from, next) => {
  if (!dirty.value) return next();
  Modal.confirm({
    title: es.value ? '¿Salir sin guardar?' : 'Leave without saving?',
    icon: createVNode(ExclamationCircleOutlined),
    okText: es.value ? 'Salir' : 'Leave',
    okType: 'danger',
    onOk: () => next(),
    onCancel: () => next(false),
  });
});

onMounted(load);
</script>

<style scoped>
.ce-top { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; margin-bottom: 4px; }
.ce-name { max-width: 380px; font-weight: 600; }
.ce-switch { display: inline-flex; align-items: center; gap: 6px; }
.ce-grid { display: grid; grid-template-columns: 280px minmax(0, 1fr); gap: 20px; align-items: start; }
@media (max-width: 900px) { .ce-grid { grid-template-columns: 1fr; } }
.ce-h { font-weight: 600; margin-bottom: 8px; }
.ce-group { display: flex; align-items: center; gap: 8px; padding: 6px 8px; border-radius: 8px; cursor: pointer; border: 1px solid transparent; }
.ce-group:hover { background: rgba(128, 128, 128, 0.07); }
.ce-group.on { border-color: #1677ff; background: rgba(22, 119, 255, 0.06); }
.ce-group img, .ce-noimg { width: 36px; height: 36px; border-radius: 6px; object-fit: cover; flex: none; background: rgba(128, 128, 128, 0.15); display: inline-block; }
.ce-gname { flex: 1; font-weight: 600; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ce-count { opacity: 0.55; font-size: 12px; }
.ce-gact { display: inline-flex; }
.ce-newg { margin-top: 8px; }
.ce-gh { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; flex-wrap: wrap; }
.ce-add { margin: 8px 0 12px; }
.ce-items { display: flex; flex-direction: column; gap: 8px; }
.ce-item { display: flex; gap: 10px; align-items: flex-start; border: 1px solid rgba(128, 128, 128, 0.2); border-radius: 8px; padding: 8px; }
.ce-item.off { opacity: 0.55; }
.ce-item img, .ce-item .ce-noimg { width: 64px; height: 64px; border-radius: 6px; object-fit: cover; flex: none; }
.ce-iinfo { flex: 1; min-width: 0; }
.ce-iname { font-weight: 600; }
.ce-iact { display: inline-flex; align-items: center; gap: 2px; }
.ce-padd { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 6px; }
.ce-purl { flex: 1; min-width: 260px; }
.ce-hidden { display: none; }
.ce-photos { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 10px; margin-top: 14px; }
.ce-photo { position: relative; border-radius: 8px; overflow: hidden; background: rgba(128, 128, 128, 0.12); }
.ce-photo img { width: 100%; aspect-ratio: 3 / 4; object-fit: cover; display: block; }
.ce-pn { position: absolute; left: 6px; top: 6px; background: rgba(0, 0, 0, 0.65); color: #fff; border-radius: 999px; padding: 0 8px; font-size: 12px; font-weight: 600; }
.ce-pact { position: absolute; left: 0; right: 0; bottom: 0; display: flex; justify-content: center; gap: 4px; padding: 4px; background: linear-gradient(transparent, rgba(0, 0, 0, 0.55)); }
.ce-oline { display: flex; align-items: center; gap: 6px; }
.ce-oline img { width: 28px; height: 36px; object-fit: cover; border-radius: 4px; }
.ce-opts { margin-top: 4px; display: flex; flex-direction: column; gap: 2px; }
.ce-opt { font-size: 12.5px; }
.ce-muted { opacity: 0.6; }
.ce-small { font-size: 12px; margin-top: 8px; }
.ce-step { border: 1px solid rgba(128, 128, 128, 0.2); border-radius: 8px; padding: 10px; margin-bottom: 12px; }
.ce-steph { display: flex; gap: 8px; margin-bottom: 8px; }
.ce-stepo { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }
.ce-stepo img, .ce-stepo .ce-noimg { width: 36px; height: 36px; border-radius: 6px; object-fit: cover; }
.ce-form { max-width: 1100px; }
.ce-logo { height: 40px; max-width: 140px; object-fit: contain; background: #111; border-radius: 4px; padding: 2px; }
.ce-slides { display: flex; flex-wrap: wrap; gap: 8px; }
.ce-slide { position: relative; width: 150px; height: 84px; border-radius: 6px; overflow: hidden; background: rgba(128, 128, 128, 0.15); }
.ce-slide img { width: 100%; height: 100%; object-fit: cover; }
.ce-slide-x { position: absolute; top: 4px; right: 4px; }
.ce-slide-add { display: flex; align-items: center; justify-content: center; cursor: pointer; border: 1px dashed rgba(128, 128, 128, 0.5); font-size: 20px; }
.ce-color { width: 100%; height: 32px; border: 1px solid rgba(128, 128, 128, 0.35); border-radius: 6px; padding: 2px; background: transparent; }
.ce-link { display: flex; max-width: 640px; margin-bottom: 14px; }
.ce-qr { margin-top: 8px; }
</style>
