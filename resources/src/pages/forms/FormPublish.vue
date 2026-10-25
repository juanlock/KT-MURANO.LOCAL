<template>
  <!-- Forms module: every way to put a form in front of people (2026-10-24). -->
  <div class="fp">
    <a-alert v-if="!form.status" type="warning" show-icon class="fp-alert"
      :message="es ? 'El formulario está en borrador: no recibe envíos hasta publicarlo (switch Publicado).' : 'The form is a draft: it takes no submissions until it is published (Published switch).'" />

    <a-tabs v-model:activeKey="tab" size="small">
      <!-- 1. Inside a page of a website / the store -->
      <a-tab-pane key="script" :tab="es ? 'En una página web' : 'In a web page'">
        <p class="fp-help">{{ es ? 'Pega este código en la página (sitio web, tienda, WordPress, Wix…) donde debe aparecer el formulario. Se ajusta solo a su alto.' : 'Paste this code in the page (website, store, WordPress, Wix…) where the form should appear. It adjusts itself to the form\'s height.' }}</p>
        <pre class="fp-code">{{ script }}</pre>
        <a-button size="small" @click="copy(script)"><template #icon><CopyOutlined /></template>{{ es ? 'Copiar código' : 'Copy code' }}</a-button>
      </a-tab-pane>

      <!-- 2. Popup -->
      <a-tab-pane key="popup" :tab="es ? 'Ventana emergente' : 'Popup'">
        <p class="fp-help">{{ es ? 'Un botón en tu página que abre el formulario en una ventana encima de la página.' : 'A button on your page that opens the form in a window over the page.' }}</p>
        <a-space wrap class="fp-row">
          <a-input v-model:value="popupText" :maxlength="60" style="width: 220px" :addon-before="es ? 'Texto' : 'Text'" />
          <span>{{ es ? 'Color' : 'Color' }} <input v-model="popupColor" type="color" class="fp-color"></span>
        </a-space>
        <div class="fp-preview-btn"><button type="button" :style="btnStyle">{{ popupText }}</button></div>
        <pre class="fp-code">{{ popup }}</pre>
        <a-button size="small" @click="copy(popup)"><template #icon><CopyOutlined /></template>{{ es ? 'Copiar código' : 'Copy code' }}</a-button>
      </a-tab-pane>

      <!-- 3. iframe -->
      <a-tab-pane key="iframe" tab="iframe">
        <p class="fp-help">{{ es ? 'Para sitios que no permiten scripts. El alto es fijo: cámbialo si hace falta.' : 'For sites that do not allow scripts. The height is fixed: change it if needed.' }}</p>
        <a-input-number v-model:value="iframeHeight" :min="200" :max="4000" :step="50" addon-after="px" class="fp-row" />
        <pre class="fp-code">{{ iframe }}</pre>
        <a-button size="small" @click="copy(iframe)"><template #icon><CopyOutlined /></template>{{ es ? 'Copiar código' : 'Copy code' }}</a-button>
      </a-tab-pane>

      <!-- 4. Link + QR + social -->
      <a-tab-pane key="link" :tab="es ? 'Enlace y QR' : 'Link & QR'">
        <p class="fp-help">{{ es ? 'Su propia página, para compartir por mensaje, redes sociales o impresa con el código QR.' : 'Its own page, to share by message, social networks or printed with the QR code.' }}</p>
        <div class="fp-link">
          <a-input :value="form.links.page" readonly />
          <a-button @click="copy(form.links.page)"><CopyOutlined /></a-button>
          <a-button :href="form.links.page" target="_blank"><ExportOutlined /></a-button>
        </div>
        <a-space wrap class="fp-row">
          <a-button :href="whatsapp" target="_blank"><WhatsAppOutlined /> WhatsApp</a-button>
          <a-button :href="facebook" target="_blank"><FacebookOutlined /> Facebook</a-button>
          <a-button :href="xLink" target="_blank"><TwitterOutlined /> X</a-button>
          <a-button :href="linkedin" target="_blank"><LinkedinOutlined /> LinkedIn</a-button>
        </a-space>
        <div ref="qrBox" class="fp-qr">
          <a-qrcode :value="form.links.page" :size="200" :color="'#111111'" :bg-color="'#ffffff'" />
          <a-button size="small" @click="downloadQr"><template #icon><DownloadOutlined /></template>{{ es ? 'Descargar QR (PNG)' : 'Download QR (PNG)' }}</a-button>
        </div>
      </a-tab-pane>

      <!-- 5. Send by email -->
      <a-tab-pane key="email" :tab="es ? 'Enviar por correo' : 'Send by email'">
        <p class="fp-help">{{ es ? 'Envía el enlace del formulario desde el correo de la empresa.' : 'Sends the form\'s link from the company\'s email.' }}</p>
        <a-form layout="vertical">
          <a-form-item :label="es ? 'Para' : 'To'" :extra="es ? 'Uno o varios correos (hasta 20).' : 'One or more emails (up to 20).'">
            <a-select v-model:value="mail.to" mode="tags" :token-separators="[',', ' ', ';']" :placeholder="es ? 'cliente@correo.com' : 'customer@email.com'" />
          </a-form-item>
          <a-form-item :label="es ? 'Asunto' : 'Subject'"><a-input v-model:value="mail.subject" :maxlength="190" :placeholder="form.name" /></a-form-item>
          <a-form-item :label="es ? 'Mensaje' : 'Message'"><a-textarea v-model:value="mail.message" :auto-size="{ minRows: 3, maxRows: 8 }" /></a-form-item>
          <a-button type="primary" :loading="sending" :disabled="!canManage || !mail.to.length" @click="sendMail"><template #icon><SendOutlined /></template>{{ es ? 'Enviar' : 'Send' }}</a-button>
        </a-form>
      </a-tab-pane>

      <!-- 6. Standalone HTML page -->
      <a-tab-pane key="html" :tab="es ? 'Página HTML' : 'HTML page'">
        <p class="fp-help">{{ es ? 'Una página .html lista con el formulario, para subirla a cualquier hosting o abrirla en una computadora. Los envíos llegan aquí igual.' : 'A ready .html page with the form, to upload to any hosting or open on a computer. Submissions arrive here just the same.' }}</p>
        <a-button @click="downloadHtml"><template #icon><DownloadOutlined /></template>{{ es ? 'Descargar página HTML' : 'Download HTML page' }}</a-button>
      </a-tab-pane>
    </a-tabs>

    <div class="fp-muted">
      {{ es ? 'Sitios permitidos:' : 'Allowed sites:' }}
      {{ form.settings.allowed_domains.length ? form.settings.allowed_domains.join(', ') : (es ? 'cualquiera (Ajustes → Seguridad)' : 'any (Settings → Security)') }}
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { message } from 'ant-design-vue';
import {
  CopyOutlined, ExportOutlined, DownloadOutlined, SendOutlined, WhatsAppOutlined, FacebookOutlined, TwitterOutlined, LinkedinOutlined,
} from '@ant-design/icons-vue';
import http from '../../lib/http';
import { useAuthStore } from '../../stores/auth';
import { useUiStore } from '../../stores/ui';

const props = defineProps({ form: { type: Object, required: true } });
const auth = useAuthStore();
const ui = useUiStore();
const es = computed(() => ui.locale === 'es');
const canManage = computed(() => auth.can('forms_manage'));

const tab = ref('script');
const q = s => String(s ?? '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
const scriptTag = computed(() => `<script src="${props.form.links.script}" async><\/script>`);

const script = computed(() => `<div data-murano-form="${props.form.public_key}"></div>\n${scriptTag.value}`);

const popupText = ref(es.value ? 'Contáctenos' : 'Contact us');
const popupColor = ref(props.form.settings.primary_color || '#1677ff');
const btnStyle = computed(() => `background:${popupColor.value};color:#fff;border:0;border-radius:6px;padding:10px 20px;font-size:15px;cursor:pointer`);
const popup = computed(() => `<button type="button" data-murano-popup="${props.form.public_key}" style="${btnStyle.value}">${q(popupText.value)}</button>\n${scriptTag.value}`);

const iframeHeight = ref(900);
const iframe = computed(() => `<iframe src="${props.form.links.embed}" title="${q(props.form.name)}" style="width:100%;height:${iframeHeight.value}px;border:0" loading="lazy"></iframe>`);

const enc = encodeURIComponent;
const whatsapp = computed(() => `https://wa.me/?text=${enc(`${props.form.name}: ${props.form.links.page}`)}`);
const facebook = computed(() => `https://www.facebook.com/sharer/sharer.php?u=${enc(props.form.links.page)}`);
const xLink = computed(() => `https://twitter.com/intent/tweet?url=${enc(props.form.links.page)}&text=${enc(props.form.name)}`);
const linkedin = computed(() => `https://www.linkedin.com/sharing/share-offsite/?url=${enc(props.form.links.page)}`);

async function copy(text) {
  try {
    await navigator.clipboard.writeText(text);
    message.success(es.value ? 'Copiado.' : 'Copied.');
  } catch (e) {
    message.warning(es.value ? 'Selecciónalo y cópialo a mano.' : 'Select it and copy it by hand.');
  }
}

function save(blob, name) {
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = name;
  a.click();
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}
const fileName = computed(() => (props.form.name || 'form').replace(/[^\w\- ]+/g, '').trim().replace(/\s+/g, '-').toLowerCase() || 'form');

const qrBox = ref(null);
function downloadQr() {
  const canvas = qrBox.value?.querySelector('canvas');
  if (!canvas) return;
  canvas.toBlob(b => b && save(b, `${fileName.value}-qr.png`));
}

function downloadHtml() {
  const title = q(props.form.settings.title || props.form.name);
  const html = `<!DOCTYPE html>\n<html lang="${props.form.settings.language}">\n<head>\n<meta charset="utf-8">\n<meta name="viewport" content="width=device-width, initial-scale=1">\n<title>${title}</title>\n<style>body{margin:0;background:#f4f6f8;font-family:system-ui,Arial,sans-serif}.wrap{max-width:780px;margin:0 auto;padding:24px 12px}</style>\n</head>\n<body>\n<div class="wrap">\n${script.value}\n</div>\n</body>\n</html>\n`;
  save(new Blob([html], { type: 'text/html;charset=utf-8' }), `${fileName.value}.html`);
}

const mail = ref({ to: [], subject: '', message: '' });
const sending = ref(false);
async function sendMail() {
  sending.value = true;
  try {
    const res = await http.post(`forms/${props.form.id}/share-email`, mail.value);
    message.success((es.value ? 'Enviado a ' : 'Sent to ') + res.sent.join(', '));
    if (res.failed?.length) message.warning((es.value ? 'No se pudo enviar a ' : 'Could not send to ') + res.failed.join(', '));
    mail.value.to = [];
  } catch (e) {
    const errs = e?.data?.errors;
    message.error((errs && Object.values(errs)[0]?.[0]) || e?.data?.message || (es.value ? 'No se pudo enviar.' : 'Could not send.'));
  } finally {
    sending.value = false;
  }
}
</script>

<style scoped>
.fp-alert { margin-bottom: 12px; }
.fp-help { opacity: 0.7; font-size: 13px; margin-bottom: 8px; }
.fp-code { background: rgba(128, 128, 128, 0.1); border-radius: 6px; padding: 10px 12px; font-size: 12.5px; white-space: pre-wrap; word-break: break-all; margin-bottom: 8px; }
.fp-row { margin-bottom: 10px; }
.fp-color { width: 40px; height: 28px; vertical-align: middle; border: 1px solid rgba(128, 128, 128, 0.35); border-radius: 4px; padding: 1px; background: transparent; }
.fp-preview-btn { margin-bottom: 10px; }
.fp-link { display: flex; gap: 6px; margin-bottom: 12px; }
.fp-qr { display: flex; flex-direction: column; align-items: flex-start; gap: 8px; }
.fp-muted { opacity: 0.6; font-size: 12.5px; margin-top: 12px; }
</style>
