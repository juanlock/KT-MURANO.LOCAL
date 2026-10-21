<template>
  <!--
    Website Management -> One-page template (2026-10-20): pick a design (by
    business type), colour, the sections (show / hide, order, texts EN + ES,
    pictures, items) and extra menu links. Saved to admin/website/onepage
    (WebsiteSettingsController, App\Support\OnePageTemplates); the menu of the
    page is built from the sections' menu labels.
  -->
  <a-spin :spinning="loading">
    <div v-if="page" class="op-editor">
      <a-alert
        v-if="!applied" type="info" show-icon style="margin-bottom: 12px"
        :message="L('notApplied')" :description="L('notAppliedHelp')"
      />

      <div class="op-bar">
        <a-space wrap>
          <a-button type="primary" :loading="saving" @click="save">{{ L('save') }}</a-button>
          <a-button @click="openPreview"><EyeOutlined /> {{ L('preview') }}</a-button>
          <a v-if="applied && publicUrl" :href="publicUrl" target="_blank" rel="noopener">{{ L('viewSite') }}</a>
          <span v-if="dirty" class="op-help">{{ L('unsaved') }}</span>
        </a-space>
        <a-radio-group v-model:value="lang" size="small" button-style="solid">
          <a-radio-button value="en">English</a-radio-button>
          <a-radio-button value="es">Español</a-radio-button>
        </a-radio-group>
      </div>

      <!-- Design -->
      <a-divider orientation="left">{{ L('design') }}</a-divider>
      <div class="op-templates">
        <a-popconfirm
          v-for="tp in templates" :key="tp.key"
          :disabled="tp.key === page.template"
          :title="L('switchConfirm')" :ok-text="L('switch')" @confirm="switchTemplate(tp.key, false)"
        >
          <div class="op-tpl" :class="{ active: tp.key === page.template }">
            <div class="op-tpl-swatch" :style="{ background: tp.color }">{{ tp.example }}</div>
            <div class="op-tpl-body">
              <strong>{{ tp.name }}</strong>
              <a-tag v-if="tp.key === recommended" color="green" style="margin-left: 6px">{{ L('recommended') }}</a-tag>
              <div class="op-help">{{ tp.description[uiLang] || tp.description.en }}</div>
            </div>
          </div>
        </a-popconfirm>
      </div>
      <div class="op-help" style="margin-top: 6px">{{ L('recommendedFor') }} <strong>{{ businessType }}</strong>.</div>

      <a-space wrap style="margin-top: 12px" align="center">
        <span>{{ L('color') }}</span>
        <input v-model="page.color" type="color" class="op-color" @input="touch">
        <a-button size="small" @click="page.color = currentTemplate.color; touch()">{{ L('defaultColor') }}</a-button>
        <a-divider type="vertical" />
        <a-switch v-model:checked="page.show_prices" @change="touch" /> <span>{{ L('showPrices') }}</span>
        <a-divider type="vertical" />
        <a-popconfirm :title="L('resetConfirm')" :ok-text="L('reset')" @confirm="switchTemplate(page.template, true)">
          <a-button size="small" danger>{{ L('reset') }}</a-button>
        </a-popconfirm>
      </a-space>

      <!-- Sections -->
      <a-divider orientation="left">{{ L('sections') }}</a-divider>
      <div class="op-help" style="margin-bottom: 8px">{{ L('sectionsHelp') }}</div>
      <a-collapse v-model:activeKey="openKeys">
        <a-collapse-panel v-for="(s, i) in page.sections" :key="s.id">
          <template #header>
            <span class="op-sec-head" @click.stop>
              <a-switch v-model:checked="s.on" size="small" @change="touch" />
            </span>
            <strong :style="{ opacity: s.on ? 1 : .5 }">{{ typeLabel(s.type) }}</strong>
            <span class="op-help" style="margin-left: 8px">#{{ s.id }} · {{ s.menu[lang] || L('notInMenu') }}</span>
          </template>
          <template #extra>
            <span @click.stop>
              <a-button size="small" type="text" :disabled="i === 0" @click="move(page.sections, i, -1)"><ArrowUpOutlined /></a-button>
              <a-button size="small" type="text" :disabled="i === page.sections.length - 1" @click="move(page.sections, i, 1)"><ArrowDownOutlined /></a-button>
              <a-popconfirm :title="L('removeSection')" @confirm="page.sections.splice(i, 1); touch()">
                <a-button size="small" type="text" danger><DeleteOutlined /></a-button>
              </a-popconfirm>
            </span>
          </template>

          <a-form layout="vertical" class="op-form">
            <a-row :gutter="12">
              <a-col :xs="24" :md="8">
                <a-form-item :label="L('menuLabel')" :extra="L('menuLabelHelp')">
                  <a-input v-model:value="s.menu[lang]" :maxlength="40" @change="touch" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="16">
                <a-form-item :label="L('title')">
                  <a-input v-model:value="s.title[lang]" :maxlength="160" @change="touch" />
                </a-form-item>
              </a-col>
            </a-row>
            <a-form-item :label="L('text')">
              <a-textarea v-model:value="s.text[lang]" :auto-size="{ minRows: 2, maxRows: 8 }" :maxlength="3000" @change="touch" />
            </a-form-item>

            <a-row v-if="hasButton(s.type)" :gutter="12">
              <a-col :xs="24" :md="8">
                <a-form-item :label="L('buttonText')">
                  <a-input v-model:value="s.button[lang]" :maxlength="60" :placeholder="L('noButton')" @change="touch" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="16">
                <a-form-item :label="L('buttonLink')" :extra="L('linkHelp')">
                  <a-auto-complete v-model:value="s.link" :options="linkOptions" :placeholder="'#contact'" @change="touch" />
                </a-form-item>
              </a-col>
            </a-row>

            <a-form-item v-if="hasImage(s.type)" :label="L('picture')">
              <div class="op-img-row">
                <img v-if="s.image" :src="imagesBase + s.image" alt="" class="op-thumb">
                <a-upload :show-upload-list="false" accept="image/*" :before-upload="f => upload(f, url => { s.image = url; })">
                  <a-button :loading="uploading"><UploadOutlined /> {{ s.image ? L('change') : L('upload') }}</a-button>
                </a-upload>
                <a-button v-if="s.image" danger type="text" @click="s.image = null; touch()">{{ L('remove') }}</a-button>
              </div>
            </a-form-item>

            <a-alert v-if="s.type === 'gallery'" type="info" show-icon :message="L('galleryNote')">
              <template #description><PermLink to="/gallery" permission="Store_settings_view">{{ L('openGallery') }}</PermLink></template>
            </a-alert>
            <a-alert v-if="s.type === 'portfolio'" type="info" show-icon :message="L('portfolioNote')">
              <template #description><PermLink to="/portfolio/photos" permission="portfolio_manage">{{ L('openPortfolio') }}</PermLink></template>
            </a-alert>
            <a-alert v-if="s.type === 'contact'" type="info" show-icon :message="L('contactNote')" />
            <a-alert v-if="s.type === 'cta' && !modules.bookings" type="warning" show-icon :message="L('noBookings')" />

            <!-- Items: services (with price / picture) and testimonials (name + quote) -->
            <template v-if="s.type === 'services' || s.type === 'testimonials'">
              <a-divider orientation="left" style="margin: 8px 0">{{ s.type === 'services' ? L('items') : L('reviews') }}</a-divider>
              <a-alert v-if="s.type === 'testimonials'" type="warning" show-icon style="margin-bottom: 8px" :message="L('realReviews')" />
              <div v-for="(it, j) in s.items" :key="j" class="op-item">
                <a-row :gutter="8" align="middle">
                  <a-col :xs="24" :md="s.type === 'services' ? 7 : 8">
                    <a-input v-model:value="it.title[lang]" :placeholder="s.type === 'services' ? L('itemName') : L('personName')" @change="touch" />
                  </a-col>
                  <a-col :xs="24" :md="s.type === 'services' ? 9 : 13">
                    <a-input v-model:value="it.text[lang]" :placeholder="s.type === 'services' ? L('itemText') : L('quote')" @change="touch" />
                  </a-col>
                  <a-col v-if="s.type === 'services'" :xs="12" :md="3">
                    <a-input v-model:value="it.price" :placeholder="L('price')" @change="touch" />
                  </a-col>
                  <a-col :xs="12" :md="s.type === 'services' ? 5 : 3" class="op-item-actions">
                    <a-upload v-if="s.type === 'services'" :show-upload-list="false" accept="image/*" :before-upload="f => upload(f, url => { it.image = url; })">
                      <a-tooltip :title="L('picture')">
                        <img v-if="it.image" :src="imagesBase + it.image" alt="" class="op-thumb-sm">
                        <a-button v-else size="small"><PictureOutlined /></a-button>
                      </a-tooltip>
                    </a-upload>
                    <a-button v-if="it.image" size="small" type="text" @click="it.image = null; touch()">✕</a-button>
                    <a-button size="small" type="text" :disabled="j === 0" @click="move(s.items, j, -1)"><ArrowUpOutlined /></a-button>
                    <a-button size="small" type="text" danger @click="s.items.splice(j, 1); touch()"><DeleteOutlined /></a-button>
                  </a-col>
                </a-row>
              </div>
              <a-button size="small" @click="addItem(s)"><PlusOutlined /> {{ s.type === 'services' ? L('addItem') : L('addReview') }}</a-button>
            </template>
          </a-form>
        </a-collapse-panel>
      </a-collapse>

      <a-space style="margin-top: 10px" wrap>
        <a-select v-model:value="newType" :options="addableTypes" :placeholder="L('addSection')" style="min-width: 220px" />
        <a-button :disabled="!newType" @click="addSection"><PlusOutlined /> {{ L('add') }}</a-button>
      </a-space>

      <!-- Extra menu links -->
      <a-divider orientation="left">{{ L('extraLinks') }}</a-divider>
      <div class="op-help" style="margin-bottom: 8px">{{ L('extraLinksHelp') }}</div>
      <div v-for="(l, i) in page.links" :key="i" class="op-item">
        <a-row :gutter="8">
          <a-col :xs="24" :md="8"><a-input v-model:value="l.label[lang]" :placeholder="L('linkLabel')" @change="touch" /></a-col>
          <a-col :xs="20" :md="14"><a-input v-model:value="l.url" placeholder="https://… /book?company=…" @change="touch" /></a-col>
          <a-col :xs="4" :md="2"><a-button type="text" danger @click="page.links.splice(i, 1); touch()"><DeleteOutlined /></a-button></a-col>
        </a-row>
      </div>
      <a-button size="small" :disabled="page.links.length >= 8" @click="page.links.push({ label: { en: '', es: '' }, url: '' }); touch()"><PlusOutlined /> {{ L('addLink') }}</a-button>

      <div style="margin-top: 20px">
        <a-button type="primary" :loading="saving" @click="save">{{ L('save') }}</a-button>
      </div>
    </div>
  </a-spin>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  EyeOutlined, ArrowUpOutlined, ArrowDownOutlined, DeleteOutlined, UploadOutlined, PlusOutlined, PictureOutlined,
} from '@ant-design/icons-vue';
import http from '../../lib/http';
import { uploadForm } from '../../lib/upload';
import { sortByLabel } from '../../lib/filterOptions';

const props = defineProps({ applied: { type: Boolean, default: false } });

const { locale } = useI18n();
const uiLang = computed(() => (String(locale.value || 'en').startsWith('es') ? 'es' : 'en'));

// Screen texts, English + Spanish (the screen follows the app language).
const TX = {
  save: ['Save', 'Guardar'], preview: ['Preview', 'Vista previa'], viewSite: ['View the website', 'Ver el sitio web'],
  unsaved: ['Unsaved changes', 'Cambios sin guardar'],
  notApplied: ['The one-page template is not your front page yet.', 'La plantilla de una página aún no es tu página principal.'],
  notAppliedHelp: ['Prepare it here and use Preview; when it is ready, choose "One-page template" in the Front page tab and apply it.', 'Prepárala aquí y usa Vista previa; cuando esté lista, elige "Plantilla de una página" en la pestaña Página principal y aplícala.'],
  design: ['Design', 'Diseño'], recommended: ['Recommended', 'Recomendada'],
  recommendedFor: ['Recommended design for the business type', 'Diseño recomendado para el tipo de negocio'],
  switchConfirm: ['Switch to this design? Your texts and pictures are kept.', '¿Cambiar a este diseño? Se conservan tus textos e imágenes.'],
  switch: ['Switch', 'Cambiar'], color: ['Main colour', 'Color principal'], defaultColor: ['Design colour', 'Color del diseño'],
  showPrices: ['Show prices', 'Mostrar precios'],
  reset: ['Start over', 'Empezar de nuevo'],
  resetConfirm: ['Start over with this design? Every text, picture and item of the one-page website is replaced by the starting ones.', '¿Empezar de nuevo con este diseño? Todos los textos, imágenes y elementos se reemplazan por los iniciales.'],
  sections: ['Sections', 'Secciones'],
  sectionsHelp: ['Each section is a block of the page. Turn it on or off, change its order with the arrows, and write its texts in English and Spanish (switch at the top). A section with a menu label appears in the menu and the menu scrolls to it.', 'Cada sección es un bloque de la página. Actívala o desactívala, cambia el orden con las flechas y escribe sus textos en inglés y español (cambia arriba). Una sección con etiqueta de menú aparece en el menú y el menú se desplaza hasta ella.'],
  notInMenu: ['not in the menu', 'no está en el menú'], removeSection: ['Remove this section?', '¿Quitar esta sección?'],
  menuLabel: ['Menu label', 'Etiqueta del menú'], menuLabelHelp: ['Empty = not in the menu.', 'Vacía = no aparece en el menú.'],
  title: ['Title', 'Título'], text: ['Text', 'Texto'], buttonText: ['Button text', 'Texto del botón'], noButton: ['(no button)', '(sin botón)'],
  buttonLink: ['Button link', 'Enlace del botón'],
  linkHelp: ['#section of this page, /book?company=… or a full https:// address.', '#sección de esta página, /book?company=… o una dirección https:// completa.'],
  picture: ['Picture', 'Imagen'], upload: ['Upload', 'Subir'], change: ['Change', 'Cambiar'], remove: ['Remove', 'Quitar'],
  galleryNote: ['The pictures come from the Gallery module (active pictures).', 'Las imágenes vienen del módulo Galería (imágenes activas).'],
  openGallery: ['Open Gallery', 'Abrir Galería'],
  portfolioNote: ['The projects come from the Portfolio module (published works).', 'Los proyectos vienen del módulo Portafolio (trabajos publicados).'],
  openPortfolio: ['Open Portfolio', 'Abrir Portafolio'],
  contactNote: ['Phone, email and address come from System settings (the company\'s details); a map is shown for the address.', 'El teléfono, correo y dirección vienen de Configuración del sistema (datos de la empresa); se muestra un mapa de la dirección.'],
  noBookings: ['Bookings is off for this company: point the button somewhere else or turn this section off.', 'Reservas está desactivado para esta empresa: cambia el enlace del botón o desactiva esta sección.'],
  items: ['Services', 'Servicios'], reviews: ['Reviews', 'Opiniones'],
  realReviews: ['Only add real reviews from real customers (with their permission).', 'Agrega solo opiniones reales de clientes reales (con su permiso).'],
  itemName: ['Name', 'Nombre'], itemText: ['Short description', 'Descripción corta'], price: ['Price', 'Precio'],
  personName: ['Customer name', 'Nombre del cliente'], quote: ['What they said', 'Lo que dijo'],
  addItem: ['Add a service', 'Agregar servicio'], addReview: ['Add a review', 'Agregar opinión'],
  addSection: ['Add a section…', 'Agregar una sección…'], add: ['Add', 'Agregar'],
  extraLinks: ['Extra menu links', 'Enlaces adicionales del menú'],
  extraLinksHelp: ['Links shown in the menu after the sections: your online store, a page, booking, another website.', 'Enlaces que aparecen en el menú después de las secciones: tu tienda en línea, una página, reservas, otro sitio.'],
  linkLabel: ['Label', 'Etiqueta'], addLink: ['Add a link', 'Agregar enlace'],
  saved: ['One-page website saved.', 'Sitio de una página guardado.'], saveFailed: ['Could not save.', 'No se pudo guardar.'],
  switched: ['Design changed.', 'Diseño cambiado.'], uploadFailed: ['Could not upload the picture.', 'No se pudo subir la imagen.'],
};
const L = k => (TX[k] ? TX[k][uiLang.value === 'es' ? 1 : 0] : k);

const TYPE_LABELS = {
  hero: ['Header (hero)', 'Portada'], about: ['About', 'Nosotros'], services: ['Services', 'Servicios'],
  gallery: ['Gallery', 'Galería'], portfolio: ['Portfolio', 'Portafolio'], testimonials: ['Reviews', 'Opiniones'],
  cta: ['Call to action (booking)', 'Llamado a la acción (reservas)'], contact: ['Contact', 'Contacto'],
};
const typeLabel = t => (TYPE_LABELS[t] || [t, t])[uiLang.value === 'es' ? 1 : 0];
const hasButton = t => ['hero', 'about', 'cta', 'contact'].includes(t);
const hasImage = t => ['hero', 'about', 'contact'].includes(t);

const loading = ref(false);
const saving = ref(false);
const uploading = ref(false);
const dirty = ref(false);
const page = ref(null);
const templates = ref([]);
const recommended = ref('');
const businessType = ref('');
const applied = ref(props.applied);
const previewUrl = ref('');
const publicUrl = ref('');
const imagesBase = ref('/');
const modules = ref({});
const lang = ref(uiLang.value);
const openKeys = ref([]);
const newType = ref(undefined);

const currentTemplate = computed(() => templates.value.find(t => t.key === page.value?.template) || { color: '#0d6efd' });
const touch = () => { dirty.value = true; };

const blank = () => ({ en: '', es: '' });
const norm = v => (v && typeof v === 'object' ? { en: v.en || '', es: v.es || '' } : { en: v || '', es: '' });
function normalize(p) {
  p.links = (p.links || []).map(l => ({ label: norm(l.label), url: l.url || '' }));
  p.sections = (p.sections || []).map(s => ({
    ...s, menu: norm(s.menu), title: norm(s.title), text: norm(s.text), button: norm(s.button), link: s.link || '',
    items: (s.items || []).map(it => ({ ...it, title: norm(it.title), text: norm(it.text), price: it.price || '' })),
  }));
  return p;
}

function apply(res) {
  page.value = normalize(res.onepage);
  templates.value = res.templates || [];
  recommended.value = res.recommended;
  businessType.value = res.business_type;
  applied.value = !!res.applied;
  previewUrl.value = res.preview_url;
  publicUrl.value = res.public_url;
  imagesBase.value = res.images_base || '/';
  modules.value = res.modules || {};
  dirty.value = false;
}

async function load() {
  loading.value = true;
  try { apply(await http.get('admin/website/onepage')); } finally { loading.value = false; }
}

async function save() {
  saving.value = true;
  try {
    apply(await http.post('admin/website/onepage', { onepage: page.value }));
    message.success(L('saved'));
  } catch (e) {
    message.error(e?.data?.message || L('saveFailed'));
  } finally {
    saving.value = false;
  }
}

async function switchTemplate(key, reset) {
  if (dirty.value) await save();
  loading.value = true;
  try {
    apply(await http.post('admin/website/onepage/template', { template: key, reset }));
    message.success(L('switched'));
  } catch (e) {
    message.error(e?.data?.message || L('saveFailed'));
  } finally {
    loading.value = false;
  }
}

async function openPreview() {
  if (dirty.value) await save();
  if (previewUrl.value) window.open(previewUrl.value, '_blank', 'noopener');
}

async function upload(file, done) {
  uploading.value = true;
  try {
    const fd = new FormData();
    fd.append('image', file);
    const { status, data } = await uploadForm('admin/website/onepage/image', fd);
    if (status >= 200 && status < 300 && data?.path) {
      done(data.path);
      touch();
    } else {
      message.error(data?.message || L('uploadFailed'));
    }
  } catch (e) {
    message.error(L('uploadFailed'));
  } finally {
    uploading.value = false;
  }
  return false; // never let a-upload post by itself
}

function move(list, i, d) {
  const j = i + d;
  if (j < 0 || j >= list.length) return;
  [list[i], list[j]] = [list[j], list[i]];
  touch();
}

function addItem(s) {
  s.items.push({ title: blank(), text: blank(), price: '', image: null });
  touch();
}

const addableTypes = computed(() => sortByLabel(Object.keys(TYPE_LABELS)
  .filter(t => !(page.value?.sections || []).some(s => s.type === t))
  .map(t => ({ value: t, label: typeLabel(t) }))));

function addSection() {
  const t = newType.value;
  if (!t) return;
  const menu = { en: TYPE_LABELS[t][0].split(' (')[0], es: TYPE_LABELS[t][1].split(' (')[0] };
  page.value.sections.push({ id: t, type: t, on: true, menu, title: { ...menu }, text: blank(), button: blank(), link: '', image: null, items: [] });
  openKeys.value = [t];
  newType.value = undefined;
  touch();
}

const linkOptions = computed(() => (page.value?.sections || [])
  .filter(s => s.on)
  .map(s => ({ value: '#' + s.id, label: '#' + s.id + ' — ' + typeLabel(s.type) })));

onMounted(load);
</script>

<style scoped>
.op-bar { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 10px; }
.op-help { color: #8c8c8c; font-size: 12.5px; }
.op-templates { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 12px; }
.op-tpl { border: 2px solid #f0f0f0; border-radius: 10px; overflow: hidden; cursor: pointer; transition: border-color .15s, box-shadow .15s; height: 100%; }
.op-tpl:hover { box-shadow: 0 4px 14px rgba(0, 0, 0, .08); }
.op-tpl.active { border-color: #1677ff; }
.op-tpl-swatch { height: 64px; color: #fff; font-weight: 600; display: flex; align-items: flex-end; padding: 8px 10px; font-size: 12px; letter-spacing: .04em; text-transform: uppercase; }
.op-tpl-body { padding: 8px 10px 10px; }
.op-color { width: 44px; height: 30px; padding: 0; border: 1px solid #d9d9d9; border-radius: 6px; cursor: pointer; background: none; }
.op-sec-head { margin-right: 10px; display: inline-block; }
.op-form { max-width: 980px; }
.op-img-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.op-thumb { height: 64px; border-radius: 6px; object-fit: cover; }
.op-thumb-sm { height: 28px; width: 40px; border-radius: 4px; object-fit: cover; cursor: pointer; vertical-align: middle; }
.op-item { padding: 6px 0; border-bottom: 1px dashed #f0f0f0; }
.op-item-actions { display: flex; align-items: center; gap: 2px; justify-content: flex-end; }
</style>
