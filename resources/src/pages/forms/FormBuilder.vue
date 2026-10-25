<template>
  <div class="page">
    <PageHeader :title="form ? form.name : (es ? 'Formulario' : 'Form')" :breadcrumb="[$t('Forms_Module'), es ? 'Editar' : 'Edit']">
      <template #actions>
        <a-space wrap>
          <a-button @click="back">{{ es ? 'Volver' : 'Back' }}</a-button>
          <a-button v-if="form" @click="tab = 'publish'"><template #icon><CodeOutlined /></template>{{ es ? 'Publicar' : 'Publish' }}</a-button>
          <a-button v-if="form" @click="$router.push(`/forms/${form.id}/submissions`)"><template #icon><InboxOutlined /></template>{{ es ? 'Envíos' : 'Submissions' }}</a-button>
          <a-button type="primary" :loading="saving" :disabled="!canManage" @click="save"><template #icon><SaveOutlined /></template>{{ es ? 'Guardar' : 'Save' }}</a-button>
        </a-space>
      </template>
    </PageHeader>

    <a-spin :spinning="loading">
      <a-card v-if="form" :body-style="{ padding: '12px 16px' }">
        <div class="fb-top">
          <a-input v-model:value="form.name" :maxlength="150" class="fb-name" :placeholder="es ? 'Nombre del formulario' : 'Form name'" @change="dirty = true" />
          <span class="fb-switch">
            <a-switch v-model:checked="form.status" @change="dirty = true" />
            <span>{{ form.status ? (es ? 'Publicado' : 'Published') : (es ? 'Borrador' : 'Draft') }}</span>
          </span>
          <a-tag v-if="dirty" color="orange">{{ es ? 'Cambios sin guardar' : 'Unsaved changes' }}</a-tag>
        </div>

        <a-tabs v-model:activeKey="tab">
          <!-- ============ FIELDS ============ -->
          <a-tab-pane key="fields" :tab="es ? 'Campos' : 'Fields'">
            <div class="fb-grid">
              <div class="fb-palette">
                <div class="fb-h">{{ es ? 'Agregar' : 'Add' }}</div>
                <a-button v-for="t in TYPES" :key="t.type" block class="fb-add" @click="add(t.type)">
                  <span>{{ es ? t.es : t.en }}</span>
                </a-button>
              </div>

              <div class="fb-list">
                <div class="fb-h">{{ es ? 'Formulario' : 'Form' }} <span class="fb-muted">({{ inputCount }} {{ es ? 'campos' : 'fields' }})</span></div>
                <a-empty v-if="!form.fields.length" :description="es ? 'Agrega campos desde la izquierda.' : 'Add fields from the left.'" />
                <div class="fb-rows">
                  <div
                    v-for="(f, i) in form.fields" :key="f._uid"
                    class="fb-item" :class="[`w-${f.width || 'full'}`, { active: selected === f._uid, over: overIndex === i }]"
                    draggable="true"
                    @click="selected = f._uid"
                    @dragstart="dragIndex = i"
                    @dragover.prevent="overIndex = i"
                    @dragleave="overIndex = null"
                    @drop.prevent="drop(i)"
                    @dragend="dragIndex = null; overIndex = null"
                  >
                    <div class="fb-item-head">
                      <HolderOutlined class="fb-grip" />
                      <span class="fb-type">{{ typeLabel(f.type) }}</span>
                      <span class="fb-actions" @click.stop>
                        <a-button type="text" size="small" :disabled="i === 0" @click="move(i, -1)"><ArrowUpOutlined /></a-button>
                        <a-button type="text" size="small" :disabled="i === form.fields.length - 1" @click="move(i, 1)"><ArrowDownOutlined /></a-button>
                        <a-button type="text" size="small" @click="copyField(i)"><CopyOutlined /></a-button>
                        <a-button type="text" size="small" danger @click="removeField(i)"><DeleteOutlined /></a-button>
                      </span>
                    </div>
                    <div class="fb-item-body">
                      <template v-if="f.type === 'heading'"><b>{{ f.text || f.label || '—' }}</b></template>
                      <template v-else-if="f.type === 'paragraph'"><span class="fb-muted">{{ (f.text || '').slice(0, 140) || '—' }}</span></template>
                      <template v-else-if="f.type === 'divider'"><hr class="fb-hr"></template>
                      <template v-else>
                        <span>{{ f.label || f.key }}</span><span v-if="f.required" class="fb-req">*</span>
                        <span v-if="f.options && f.options.length" class="fb-muted"> · {{ f.options.length }} {{ es ? 'opciones' : 'options' }}</span>
                      </template>
                    </div>
                  </div>
                </div>
              </div>

              <div class="fb-props">
                <div class="fb-h">{{ es ? 'Propiedades' : 'Properties' }}</div>
                <a-empty v-if="!current" :description="es ? 'Elige un campo para editarlo.' : 'Pick a field to edit it.'" />
                <a-form v-else layout="vertical" class="fb-form" @change="dirty = true">
                  <template v-if="['heading', 'paragraph'].includes(current.type)">
                    <a-form-item :label="es ? 'Texto' : 'Text'">
                      <a-textarea v-model:value="current.text" :auto-size="{ minRows: current.type === 'heading' ? 1 : 3, maxRows: 10 }" />
                    </a-form-item>
                    <a-form-item v-if="current.type === 'heading'" :label="es ? 'Tamaño' : 'Size'">
                      <a-radio-group v-model:value="current.level" button-style="solid" size="small" @change="dirty = true">
                        <a-radio-button :value="2">{{ es ? 'Grande' : 'Large' }}</a-radio-button>
                        <a-radio-button :value="3">{{ es ? 'Mediano' : 'Medium' }}</a-radio-button>
                        <a-radio-button :value="4">{{ es ? 'Pequeño' : 'Small' }}</a-radio-button>
                      </a-radio-group>
                    </a-form-item>
                  </template>
                  <template v-else-if="current.type !== 'divider'">
                    <a-form-item :label="es ? 'Etiqueta' : 'Label'">
                      <a-input v-model:value="current.label" :maxlength="255" />
                    </a-form-item>
                    <a-form-item v-if="current.type !== 'hidden'">
                      <a-checkbox v-model:checked="current.required" @change="dirty = true">{{ es ? 'Obligatorio' : 'Required' }}</a-checkbox>
                    </a-form-item>
                    <a-form-item v-if="hasPlaceholder" :label="es ? 'Texto de ayuda dentro del campo' : 'Placeholder'">
                      <a-input v-model:value="current.placeholder" :maxlength="190" />
                    </a-form-item>
                    <a-form-item v-if="['select', 'radio', 'checkbox'].includes(current.type)" :label="es ? 'Opciones (una por línea)' : 'Options (one per line)'">
                      <a-textarea :value="(current.options || []).join('\n')" :auto-size="{ minRows: 3, maxRows: 12 }" @update:value="v => { current.options = v.split('\n').map(x => x.trim()).filter(Boolean); dirty = true }" />
                    </a-form-item>
                    <a-form-item v-if="current.type === 'select'">
                      <a-checkbox v-model:checked="current.multiple" @change="dirty = true">{{ es ? 'Permitir varias' : 'Allow several' }}</a-checkbox>
                    </a-form-item>
                    <a-form-item v-if="['text', 'select', 'radio', 'hidden'].includes(current.type)" :label="current.type === 'hidden' ? (es ? 'Valor' : 'Value') : (es ? 'Valor inicial' : 'Default value')">
                      <a-input v-model:value="current.default" :maxlength="500" />
                    </a-form-item>
                    <a-row v-if="current.type === 'number'" :gutter="8">
                      <a-col :span="12"><a-form-item label="Min"><a-input-number v-model:value="current.min" style="width: 100%" @change="dirty = true" /></a-form-item></a-col>
                      <a-col :span="12"><a-form-item label="Max"><a-input-number v-model:value="current.max" style="width: 100%" @change="dirty = true" /></a-form-item></a-col>
                    </a-row>
                    <a-form-item v-if="['text', 'textarea'].includes(current.type)" :label="es ? 'Máximo de caracteres' : 'Max characters'">
                      <a-input-number v-model:value="current.max" :min="1" :max="20000" style="width: 100%" @change="dirty = true" />
                    </a-form-item>
                    <a-form-item v-if="current.type === 'textarea'" :label="es ? 'Alto (líneas)' : 'Height (lines)'">
                      <a-input-number v-model:value="current.rows" :min="2" :max="20" style="width: 100%" @change="dirty = true" />
                    </a-form-item>
                    <template v-if="current.type === 'file'">
                      <a-form-item :label="es ? 'Tipos permitidos' : 'Allowed types'" :extra="es ? 'Separados por coma, ej. .pdf,.jpg,.png' : 'Comma separated, e.g. .pdf,.jpg,.png'">
                        <a-input v-model:value="current.accept" :maxlength="100" />
                      </a-form-item>
                      <a-form-item :label="es ? 'Tamaño máximo (MB)' : 'Max size (MB)'">
                        <a-input-number v-model:value="current.max_mb" :min="1" :max="20" style="width: 100%" @change="dirty = true" />
                      </a-form-item>
                    </template>
                    <a-form-item v-if="current.type !== 'hidden'" :label="es ? 'Ayuda (debajo del campo)' : 'Help (below the field)'">
                      <a-input v-model:value="current.help" :maxlength="500" />
                    </a-form-item>
                  </template>
                  <a-form-item v-if="current.type !== 'hidden'" :label="es ? 'Ancho' : 'Width'">
                    <a-radio-group v-model:value="current.width" button-style="solid" size="small" @change="dirty = true">
                      <a-radio-button value="full">{{ es ? 'Completo' : 'Full' }}</a-radio-button>
                      <a-radio-button value="half">1/2</a-radio-button>
                      <a-radio-button value="third">1/3</a-radio-button>
                    </a-radio-group>
                  </a-form-item>
                  <a-form-item :label="es ? 'Clave interna' : 'Internal key'" :extra="es ? 'Nombre del dato en los envíos y exportaciones. Cambiarla en un formulario con envíos los deja en otra columna.' : 'The data\'s name in submissions and exports. Changing it on a form with submissions moves them to another column.'">
                    <a-input v-model:value="current.key" :maxlength="40" />
                  </a-form-item>
                </a-form>
              </div>
            </div>
          </a-tab-pane>

          <!-- ============ SETTINGS ============ -->
          <a-tab-pane key="settings" :tab="es ? 'Ajustes' : 'Settings'">
            <a-form layout="vertical" class="fb-settings" @change="dirty = true">
              <a-row :gutter="24">
                <a-col :xs="24" :lg="12">
                  <div class="fb-sec">{{ es ? 'General' : 'General' }}</div>
                  <a-form-item :label="es ? 'Título (arriba del formulario)' : 'Title (above the form)'"><a-input v-model:value="s.title" :maxlength="190" /></a-form-item>
                  <a-form-item :label="es ? 'Descripción' : 'Description'"><a-textarea v-model:value="s.description" :auto-size="{ minRows: 2, maxRows: 6 }" /></a-form-item>
                  <a-row :gutter="12">
                    <a-col :span="12"><a-form-item :label="es ? 'Texto del botón' : 'Button text'"><a-input v-model:value="s.submit_text" :maxlength="60" /></a-form-item></a-col>
                    <a-col :span="12">
                      <a-form-item :label="es ? 'Idioma de los mensajes' : 'Messages language'">
                        <a-select v-model:value="s.language" :options="languageOptions" @change="dirty = true" />
                      </a-form-item>
                    </a-col>
                  </a-row>

                  <div class="fb-sec">{{ es ? 'Después de enviar' : 'After sending' }}</div>
                  <a-form-item>
                    <a-radio-group v-model:value="s.confirmation" @change="dirty = true">
                      <a-radio value="message">{{ es ? 'Mostrar un mensaje' : 'Show a message' }}</a-radio>
                      <a-radio value="redirect">{{ es ? 'Ir a otra página' : 'Go to another page' }}</a-radio>
                    </a-radio-group>
                  </a-form-item>
                  <a-form-item v-if="s.confirmation === 'message'" :label="es ? 'Mensaje' : 'Message'" :extra="es ? '{number} = número del envío' : '{number} = the submission number'">
                    <a-textarea v-model:value="s.success_message" :auto-size="{ minRows: 2, maxRows: 6 }" />
                  </a-form-item>
                  <a-form-item v-else :label="es ? 'Dirección' : 'Address'"><a-input v-model:value="s.redirect_url" placeholder="https://" /></a-form-item>
                  <a-form-item><a-checkbox v-model:checked="s.hide_after_submit" @change="dirty = true">{{ es ? 'Ocultar el formulario después de enviar' : 'Hide the form after sending' }}</a-checkbox></a-form-item>

                  <div class="fb-sec">{{ es ? 'Numeración' : 'Numbering' }}</div>
                  <a-row :gutter="12">
                    <a-col :span="12"><a-form-item :label="es ? 'Prefijo' : 'Prefix'" :extra="es ? 'ej. REC- → REC-2040' : 'e.g. REC- → REC-2040'"><a-input v-model:value="s.number_prefix" :maxlength="20" /></a-form-item></a-col>
                    <a-col :span="12"><a-form-item :label="es ? 'Próximo número' : 'Next number'"><a-input-number v-model:value="form.next_number" :min="1" style="width: 100%" @change="dirty = true" /></a-form-item></a-col>
                  </a-row>
                </a-col>

                <a-col :xs="24" :lg="12">
                  <div class="fb-sec">{{ es ? 'Avisos por correo' : 'Email notifications' }}</div>
                  <a-form-item><a-checkbox v-model:checked="s.notify" @change="dirty = true">{{ es ? 'Avisar por correo de cada envío' : 'Email me every submission' }}</a-checkbox></a-form-item>
                  <template v-if="s.notify">
                    <a-form-item :label="es ? 'Enviar a' : 'Send to'" :extra="es ? 'Vacío = los administradores de la empresa.' : 'Empty = the company\'s admins.'">
                      <a-select v-model:value="s.notify_emails" mode="tags" :token-separators="[',', ' ', ';']" :placeholder="es ? 'correo@empresa.com' : 'email@company.com'" @change="dirty = true" />
                    </a-form-item>
                    <a-form-item :label="es ? 'Asunto' : 'Subject'" :extra="es ? '{form} = nombre del formulario, {number} = número' : '{form} = form name, {number} = number'">
                      <a-input v-model:value="s.notify_subject" :maxlength="190" />
                    </a-form-item>
                    <a-form-item :label="es ? 'Responder a' : 'Reply to'" :extra="es ? 'Al responder el aviso, la respuesta va al correo que escribió el visitante.' : 'Replying to the notification answers the visitor\'s email.'">
                      <a-select v-model:value="s.reply_to_field" :options="emailFieldOptions" allow-clear @change="dirty = true" />
                    </a-form-item>
                  </template>

                  <div class="fb-sec">{{ es ? 'Seguridad' : 'Security' }}</div>
                  <a-form-item><a-checkbox v-model:checked="s.honeypot" @change="dirty = true">{{ es ? 'Campo trampa contra robots' : 'Hidden trap field against bots' }}</a-checkbox></a-form-item>
                  <a-form-item :label="es ? 'Tiempo mínimo para llenarlo (segundos)' : 'Minimum time to fill it (seconds)'">
                    <a-input-number v-model:value="s.min_seconds" :min="0" :max="60" style="width: 120px" @change="dirty = true" />
                  </a-form-item>
                  <a-form-item :label="es ? 'Sitios donde se puede insertar' : 'Sites that may embed it'" :extra="es ? 'Vacío = cualquier sitio. ej. turismocautivo.com' : 'Empty = any site. e.g. kcoltech.com'">
                    <a-select v-model:value="s.allowed_domains" mode="tags" :token-separators="[',', ' ', ';']" @change="dirty = true" />
                  </a-form-item>

                  <div class="fb-sec">{{ es ? 'Apariencia' : 'Look' }}</div>
                  <a-row :gutter="12">
                    <a-col :span="8"><a-form-item :label="es ? 'Color' : 'Color'"><input v-model="s.primary_color" type="color" class="fb-color" @input="dirty = true"></a-form-item></a-col>
                    <a-col :span="8"><a-form-item :label="es ? 'Fondo' : 'Background'"><input v-model="s.background" type="color" class="fb-color" @input="dirty = true"></a-form-item></a-col>
                    <a-col :span="8">
                      <a-form-item :label="es ? 'Letra' : 'Font'">
                        <a-select v-model:value="s.font" :options="fontOptions" @change="dirty = true" />
                      </a-form-item>
                    </a-col>
                  </a-row>
                  <a-form-item :label="es ? 'Bordes redondeados' : 'Rounded corners'">
                    <a-slider v-model:value="s.radius" :min="0" :max="24" @change="dirty = true" />
                  </a-form-item>
                </a-col>
              </a-row>
            </a-form>
          </a-tab-pane>

          <!-- ============ PUBLISH ============ -->
          <a-tab-pane key="publish" :tab="es ? 'Publicar' : 'Publish'">
            <a-alert v-if="dirty" type="info" show-icon style="margin-bottom: 12px" :message="es ? 'Guarda los cambios antes de publicar.' : 'Save your changes before publishing.'" />
            <FormPublish :form="form" />
          </a-tab-pane>

          <!-- ============ PREVIEW ============ -->
          <a-tab-pane key="preview" :tab="es ? 'Vista previa' : 'Preview'">
            <a-alert v-if="dirty" type="info" show-icon style="margin-bottom: 12px" :message="es ? 'La vista previa muestra lo guardado: guarda para ver los cambios.' : 'The preview shows what is saved: save to see your changes.'" />
            <div class="fb-preview">
              <iframe v-if="tab === 'preview'" :key="previewKey" :src="form.links.embed + '?preview=' + previewKey" title="Preview" />
            </div>
          </a-tab-pane>
        </a-tabs>
      </a-card>
    </a-spin>

    <EmbedCodeModal v-model:open="embedOpen" :form-id="form ? form.id : null" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, createVNode } from 'vue';
import { useRoute, useRouter, onBeforeRouteLeave } from 'vue-router';
import { message, Modal } from 'ant-design-vue';
import {
  SaveOutlined, CodeOutlined, InboxOutlined, DeleteOutlined, CopyOutlined, ArrowUpOutlined, ArrowDownOutlined,
  HolderOutlined, ExclamationCircleOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import EmbedCodeModal from './EmbedCodeModal.vue';
import FormPublish from './FormPublish.vue';
import http from '../../lib/http';
import { goBack } from '../../lib/goBack';
import { useAuthStore } from '../../stores/auth';
import { useUiStore } from '../../stores/ui';
import { sortByLabel } from '../../lib/filterOptions';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const ui = useUiStore();
const es = computed(() => ui.locale === 'es');
const canManage = computed(() => auth.can('forms_manage'));

// The palette: Murano's field types (App\Support\FormSchema), in the order a form is usually built.
const TYPES = [
  { type: 'text', en: 'Short text', es: 'Texto corto' },
  { type: 'textarea', en: 'Long text', es: 'Texto largo' },
  { type: 'email', en: 'Email', es: 'Correo' },
  { type: 'phone', en: 'Phone', es: 'Teléfono' },
  { type: 'number', en: 'Number', es: 'Número' },
  { type: 'date', en: 'Date', es: 'Fecha' },
  { type: 'time', en: 'Time', es: 'Hora' },
  { type: 'select', en: 'Dropdown', es: 'Lista desplegable' },
  { type: 'radio', en: 'One choice', es: 'Una opción' },
  { type: 'checkbox', en: 'Several choices', es: 'Varias opciones' },
  { type: 'file', en: 'File upload', es: 'Archivo' },
  { type: 'url', en: 'Web address', es: 'Dirección web' },
  { type: 'hidden', en: 'Hidden value', es: 'Valor oculto' },
  { type: 'heading', en: 'Heading', es: 'Título' },
  { type: 'paragraph', en: 'Paragraph', es: 'Párrafo' },
  { type: 'divider', en: 'Divider', es: 'Separador' },
];
const typeLabel = t => { const x = TYPES.find(y => y.type === t); return x ? (es.value ? x.es : x.en) : t; };

const form = ref(null);
const loading = ref(false);
const saving = ref(false);
const dirty = ref(false);
const tab = ref('fields');
const selected = ref(null);
const previewKey = ref(Date.now());
const embedOpen = ref(false);
let uid = 0;

const s = computed(() => form.value.settings);
const current = computed(() => form.value?.fields.find(f => f._uid === selected.value) || null);
const inputCount = computed(() => (form.value?.fields || []).filter(f => !['heading', 'paragraph', 'divider'].includes(f.type)).length);
const hasPlaceholder = computed(() => current.value && ['text', 'textarea', 'email', 'phone', 'number', 'url', 'select'].includes(current.value.type));
const emailFieldOptions = computed(() => sortByLabel((form.value?.fields || []).filter(f => f.type === 'email').map(f => ({ value: f.key, label: f.label || f.key }))));
const languageOptions = sortByLabel([{ value: 'en', label: 'English' }, { value: 'es', label: 'Español' }]);
const fontOptions = computed(() => sortByLabel([{ value: 'system', label: es.value ? 'Moderna' : 'Modern' }, { value: 'serif', label: es.value ? 'Clásica' : 'Classic' }]));

function withUid(f) {
  return { ...f, _uid: ++uid };
}

async function load() {
  loading.value = true;
  try {
    const res = await http.get(`forms/${route.params.id}`);
    form.value = { ...res.form, fields: (res.form.fields || []).map(withUid) };
    dirty.value = false;
  } catch (e) {
    message.error(e?.data?.message || e.message);
  } finally {
    loading.value = false;
  }
}

function newKey(type) {
  const used = new Set(form.value.fields.map(f => f.key));
  let n = 1;
  while (used.has(`${type}_${n}`)) n++;
  return `${type}_${n}`;
}

function add(type) {
  const f = {
    key: newKey(type), type, label: ['heading', 'paragraph', 'divider'].includes(type) ? '' : typeLabel(type),
    required: false, placeholder: '', help: '', width: 'full', default: '',
  };
  if (['select', 'radio', 'checkbox'].includes(type)) f.options = es.value ? ['Opción 1', 'Opción 2'] : ['Option 1', 'Option 2'];
  if (type === 'heading') { f.text = es.value ? 'Título' : 'Heading'; f.level = 3; }
  if (type === 'paragraph') f.text = '';
  if (type === 'textarea') { f.rows = 4; f.max = 5000; }
  if (type === 'text') f.max = 255;
  if (type === 'file') { f.accept = '.pdf,.jpg,.jpeg,.png'; f.max_mb = 5; }
  const item = withUid(f);
  // After the selected field, else at the end.
  const at = form.value.fields.findIndex(x => x._uid === selected.value);
  if (at >= 0) form.value.fields.splice(at + 1, 0, item); else form.value.fields.push(item);
  selected.value = item._uid;
  dirty.value = true;
}

function move(i, d) {
  const list = form.value.fields;
  const [x] = list.splice(i, 1);
  list.splice(i + d, 0, x);
  dirty.value = true;
}

const dragIndex = ref(null);
const overIndex = ref(null);
function drop(i) {
  if (dragIndex.value === null || dragIndex.value === i) return;
  const list = form.value.fields;
  const [x] = list.splice(dragIndex.value, 1);
  list.splice(i, 0, x);
  dragIndex.value = null;
  overIndex.value = null;
  dirty.value = true;
}

function copyField(i) {
  const src = form.value.fields[i];
  const item = withUid({ ...JSON.parse(JSON.stringify(src)), key: newKey(src.type) });
  form.value.fields.splice(i + 1, 0, item);
  selected.value = item._uid;
  dirty.value = true;
}

function removeField(i) {
  const f = form.value.fields[i];
  if (selected.value === f._uid) selected.value = null;
  form.value.fields.splice(i, 1);
  dirty.value = true;
}

async function save() {
  saving.value = true;
  try {
    const fields = form.value.fields.map(({ _uid, ...f }) => f);
    const res = await http.put(`forms/${form.value.id}`, {
      name: form.value.name, status: form.value.status, fields, settings: form.value.settings, next_number: form.value.next_number,
    });
    const keep = selected.value !== null ? form.value.fields.findIndex(f => f._uid === selected.value) : -1;
    form.value = { ...res.form, fields: (res.form.fields || []).map(withUid) };
    selected.value = keep >= 0 && form.value.fields[keep] ? form.value.fields[keep]._uid : null;
    dirty.value = false;
    previewKey.value = Date.now();
    message.success(es.value ? 'Guardado.' : 'Saved.');
  } catch (e) {
    const errs = e?.data?.errors;
    message.error((errs && Object.values(errs)[0]?.[0]) || e?.data?.message || e.message);
  } finally {
    saving.value = false;
  }
}

function back() {
  goBack(router, '/forms');
}

onBeforeRouteLeave((to, from, next) => {
  if (!dirty.value) return next();
  Modal.confirm({
    title: es.value ? '¿Salir sin guardar?' : 'Leave without saving?',
    icon: createVNode(ExclamationCircleOutlined),
    content: es.value ? 'Los cambios de este formulario se perderán.' : 'The changes to this form will be lost.',
    okText: es.value ? 'Salir' : 'Leave',
    okType: 'danger',
    onOk: () => next(),
    onCancel: () => next(false),
  });
});

onMounted(load);
</script>

<style scoped>
.fb-top { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; margin-bottom: 4px; }
.fb-name { max-width: 420px; font-weight: 600; }
.fb-switch { display: inline-flex; align-items: center; gap: 8px; }
.fb-grid { display: grid; grid-template-columns: 180px minmax(0, 1fr) 320px; gap: 16px; align-items: start; }
@media (max-width: 1100px) { .fb-grid { grid-template-columns: 150px minmax(0, 1fr); } .fb-props { grid-column: 1 / -1; } }
@media (max-width: 640px) { .fb-grid { grid-template-columns: 1fr; } }
.fb-h { font-weight: 600; margin-bottom: 8px; }
.fb-muted { opacity: 0.6; font-weight: normal; }
.fb-add { margin-bottom: 6px; text-align: left; }
.fb-rows { display: flex; flex-wrap: wrap; margin: 0 -4px; }
.fb-item {
  margin: 0 4px 8px; width: calc(100% - 8px); border: 1px solid rgba(128, 128, 128, 0.25); border-radius: 8px;
  padding: 6px 10px 8px; cursor: pointer; background: rgba(128, 128, 128, 0.03);
}
.fb-item.w-half { width: calc(50% - 8px); }
.fb-item.w-third { width: calc(33.333% - 8px); }
@media (max-width: 640px) { .fb-item.w-half, .fb-item.w-third { width: calc(100% - 8px); } }
.fb-item.active { border-color: #1677ff; box-shadow: 0 0 0 2px rgba(22, 119, 255, 0.15); }
.fb-item.over { border-style: dashed; border-color: #1677ff; }
.fb-item-head { display: flex; align-items: center; gap: 6px; font-size: 12px; }
.fb-grip { cursor: grab; opacity: 0.5; }
.fb-type { opacity: 0.6; text-transform: uppercase; letter-spacing: 0.3px; }
.fb-actions { margin-left: auto; display: inline-flex; }
.fb-item-body { margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.fb-req { color: #ff4d4f; margin-left: 2px; }
.fb-hr { border: 0; border-top: 1px solid rgba(128, 128, 128, 0.35); margin: 8px 0 2px; }
.fb-props { position: sticky; top: 72px; }
.fb-form :deep(.ant-form-item) { margin-bottom: 12px; }
.fb-settings { max-width: 1100px; }
.fb-sec { font-weight: 600; margin: 6px 0 10px; padding-bottom: 4px; border-bottom: 1px solid rgba(128, 128, 128, 0.2); }
.fb-color { width: 100%; height: 32px; border: 1px solid rgba(128, 128, 128, 0.35); border-radius: 6px; padding: 2px; background: transparent; }
.fb-preview { background: #f4f6f8; border-radius: 8px; padding: 16px; }
.fb-preview iframe { width: 100%; max-width: 760px; height: 760px; border: 0; display: block; margin: 0 auto; background: transparent; }
</style>
