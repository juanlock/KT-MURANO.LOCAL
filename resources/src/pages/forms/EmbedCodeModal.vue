<template>
  <!-- Forms module: how to put a form on a website / the store / any page. -->
  <a-modal :open="open" :title="es ? 'Publicar el formulario' : 'Publish the form'" :footer="null" :width="720" @cancel="emit('update:open', false)">
    <a-spin :spinning="loading">
      <template v-if="form">
        <a-alert v-if="!form.status" type="warning" show-icon style="margin-bottom: 12px"
          :message="es ? 'El formulario está en borrador: no recibe envíos hasta publicarlo (Editar → Publicado).' : 'The form is a draft: it takes no submissions until it is published (Edit → Published).'" />
        <div class="ec-block">
          <div class="ec-title">1. {{ es ? 'Script (recomendado)' : 'Script (recommended)' }}</div>
          <div class="ec-help">{{ es ? 'Pégalo donde debe aparecer el formulario. Se ajusta solo al alto del formulario.' : 'Paste it where the form should appear. It adjusts itself to the form\'s height.' }}</div>
          <pre class="ec-code">{{ script }}</pre>
          <a-button size="small" @click="copy(script)"><template #icon><CopyOutlined /></template>{{ es ? 'Copiar' : 'Copy' }}</a-button>
        </div>
        <div class="ec-block">
          <div class="ec-title">2. iframe</div>
          <div class="ec-help">{{ es ? 'Para sitios que no permiten scripts. El alto es fijo: ajústalo si hace falta.' : 'For sites that do not allow scripts. The height is fixed: change it if needed.' }}</div>
          <pre class="ec-code">{{ iframe }}</pre>
          <a-button size="small" @click="copy(iframe)"><template #icon><CopyOutlined /></template>{{ es ? 'Copiar' : 'Copy' }}</a-button>
        </div>
        <div class="ec-block">
          <div class="ec-title">3. {{ es ? 'Enlace' : 'Link' }}</div>
          <div class="ec-help">{{ es ? 'Su propia página: para compartir por correo, WhatsApp o un código QR.' : 'Its own page: to share by email, WhatsApp or a QR code.' }}</div>
          <a-input-group compact class="ec-link">
            <a-input :value="form.links.page" readonly style="width: calc(100% - 180px)" />
            <a-button @click="copy(form.links.page)"><CopyOutlined /></a-button>
            <a-button :href="form.links.page" target="_blank"><ExportOutlined /> {{ es ? 'Abrir' : 'Open' }}</a-button>
          </a-input-group>
        </div>
        <div class="ec-muted">
          {{ es ? 'Sitios permitidos:' : 'Allowed sites:' }}
          {{ form.settings.allowed_domains.length ? form.settings.allowed_domains.join(', ') : (es ? 'cualquiera (Editar → Ajustes → Seguridad)' : 'any (Edit → Settings → Security)') }}
        </div>
      </template>
    </a-spin>
  </a-modal>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { message } from 'ant-design-vue';
import { CopyOutlined, ExportOutlined } from '@ant-design/icons-vue';
import http from '../../lib/http';
import { useUiStore } from '../../stores/ui';

const props = defineProps({ open: Boolean, formId: { type: Number, default: null } });
const emit = defineEmits(['update:open']);
const ui = useUiStore();
const es = computed(() => ui.locale === 'es');
const form = ref(null);
const loading = ref(false);

const script = computed(() => form.value
  ? `<div data-murano-form="${form.value.public_key}"></div>\n<script src="${form.value.links.script}" async><\/script>`
  : '');
const iframe = computed(() => form.value
  ? `<iframe src="${form.value.links.embed}" title="${(form.value.name || '').replace(/"/g, '&quot;')}" style="width:100%;height:900px;border:0" loading="lazy"></iframe>`
  : '');

watch(() => [props.open, props.formId], async ([open, id]) => {
  if (!open || !id) return;
  loading.value = true;
  form.value = null;
  try {
    form.value = (await http.get(`forms/${id}`)).form;
  } catch (e) {
    message.error(e?.data?.message || e.message);
  } finally {
    loading.value = false;
  }
}, { immediate: true });

async function copy(text) {
  try {
    await navigator.clipboard.writeText(text);
    message.success(es.value ? 'Copiado.' : 'Copied.');
  } catch (e) {
    message.warning(es.value ? 'Selecciónalo y cópialo a mano.' : 'Select it and copy it by hand.');
  }
}
</script>

<style scoped>
.ec-block { margin-bottom: 18px; }
.ec-title { font-weight: 600; margin-bottom: 2px; }
.ec-help { opacity: 0.65; font-size: 13px; margin-bottom: 6px; }
.ec-code { background: rgba(128, 128, 128, 0.1); border-radius: 6px; padding: 10px 12px; font-size: 12.5px; white-space: pre-wrap; word-break: break-all; margin-bottom: 6px; }
.ec-link { display: flex; }
.ec-muted { opacity: 0.6; font-size: 12.5px; }
</style>
