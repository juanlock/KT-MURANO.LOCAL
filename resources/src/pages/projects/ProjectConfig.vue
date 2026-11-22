<template>
  <a-spin :spinning="loading">
    <a-row :gutter="16">
      <!-- How to get in -->
      <a-col :xs="24" :lg="10">
        <a-card size="small" class="card">
          <template #title><GlobalOutlined class="ic" /> {{ tr('Access from the web', 'Acceso desde la web') }}</template>
          <div v-for="a in accessRows" :key="a.key" class="acc">
            <div class="acc-l">{{ a.label }}</div>
            <div class="acc-v">
              <a :href="a.url" target="_blank" rel="noopener">{{ a.url }}</a>
              <a-button type="text" size="small" @click="copy(a.url)"><template #icon><CopyOutlined /></template></a-button>
            </div>
          </div>
          <div class="hint"><InfoCircleOutlined /> {{ tr('A member signs in and sees their projects and tasks in "My projects", whatever company they belong to.', 'Un miembro entra con su usuario y ve sus proyectos y tareas en "Mis proyectos", sea de la empresa que sea.') }}</div>
        </a-card>

        <!-- The project's links -->
        <a-card size="small" class="card mt">
          <template #title><LinkOutlined class="ic" /> {{ tr('Project links', 'Enlaces del proyecto') }}</template>
          <template #extra><a-button type="primary" size="small" :loading="saving" :disabled="!dirty" @click="saveLinks">{{ tr('Save', 'Guardar') }}</a-button></template>
          <div v-for="(l, i) in links" :key="i" class="lk">
            <a-input v-model:value="l.label" size="small" class="lk-l" :placeholder="tr('Name (e.g. Shared folder)', 'Nombre (p. ej. Carpeta compartida)')" @change="dirty = true" />
            <a-input v-model:value="l.url" size="small" class="lk-u" placeholder="https://" @change="dirty = true" />
            <a-button v-if="isUrl(l.url)" type="text" size="small" :href="l.url" target="_blank"><template #icon><ExportOutlined /></template></a-button>
            <a-button type="text" size="small" danger @click="links.splice(i, 1); dirty = true"><template #icon><DeleteOutlined /></template></a-button>
          </div>
          <a class="add" @click="links.push({ label: '', url: '' }); dirty = true"><PlusOutlined /> {{ tr('Add a link', 'Agregar enlace') }}</a>
          <div class="hint">{{ tr('E.g. the shared folder, the meeting room, the client\'s current system, the test site.', 'P. ej. la carpeta compartida, la sala de reuniones, el sistema actual del cliente, el sitio de pruebas.') }}</div>
        </a-card>
      </a-col>

      <!-- Credentials of each member -->
      <a-col :xs="24" :lg="14">
        <a-card size="small" class="card">
          <template #title><KeyOutlined class="ic" /> {{ tr('Members\' credentials', 'Credenciales de los miembros') }}</template>
          <div v-for="m in members" :key="m.id" class="mem">
            <a-avatar size="small" :class="['av', m.side]">{{ (m.name || '?').charAt(0).toUpperCase() }}</a-avatar>
            <div class="who">
              <div class="nm">{{ m.name }} <span class="role">{{ m.role }}</span></div>
              <div v-if="!m.can_see_login" class="muted small">{{ tr('Only the SuperAdmin sees this login.', 'Solo el SuperAdmin ve este login.') }}</div>
              <div v-else-if="!m.has_login" class="muted small">{{ tr('No login.', 'Sin login.') }}</div>
              <div v-else class="cred">
                <span><UserOutlined /> <code>{{ m.login }}</code></span>
                <span><KeyOutlined /> <code v-if="m.password">{{ m.password }}</code><span v-else class="muted">{{ tr('custom', 'personalizada') }}</span></span>
                <a-tag v-if="!m.login_active" class="tight">{{ tr('Login disabled', 'Login desactivado') }}</a-tag>
              </div>
            </div>
            <a-space v-if="m.can_see_login && m.has_login" :size="4" class="acts">
              <a-tooltip :title="tr('Copy the access text', 'Copiar el texto de acceso')">
                <a-button size="small" @click="copy(accessText(m))"><template #icon><CopyOutlined /></template></a-button>
              </a-tooltip>
              <a-tooltip :title="tr('Send by WhatsApp', 'Enviar por WhatsApp')">
                <a-button size="small" class="wa" :disabled="!m.login_active" @click="whatsapp(m)"><template #icon><WhatsAppOutlined /></template></a-button>
              </a-tooltip>
              <a-tooltip :title="tr('Send by email', 'Enviar por correo')">
                <a-button size="small" :disabled="!m.login_active" :loading="busy === m.id" @click="sendMail(m)"><template #icon><MailOutlined /></template></a-button>
              </a-tooltip>
            </a-space>
          </div>
          <a-checkbox v-model:checked="withLink" class="mt-s">{{ tr('WhatsApp: include a sign-in link (no password, first phone only)', 'WhatsApp: incluir un link de acceso (sin contraseña, solo el primer celular)') }}</a-checkbox>
        </a-card>
      </a-col>
    </a-row>
  </a-spin>
</template>

<script setup>
/**
 * The project's configuration (user, 2026-11-22): how to get in from the web, the project's links and each member's
 * credentials -- copy, WhatsApp (with an optional one-device sign-in link) or email.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import {
  GlobalOutlined, LinkOutlined, KeyOutlined, CopyOutlined, InfoCircleOutlined, ExportOutlined, DeleteOutlined, PlusOutlined,
  UserOutlined, WhatsAppOutlined, MailOutlined,
} from '@ant-design/icons-vue';
import http from '../../lib/http';
import { useTr } from '../../lib/useTr';

const props = defineProps({ projectId: { type: Number, required: true }, title: { type: String, default: '' } });
const tr = useTr();
const loading = ref(false);
const saving = ref(false);
const dirty = ref(false);
const busy = ref(null);
const withLink = ref(false);
const info = ref({ access: {}, members: [], links: [] });
const links = ref([]);
const members = computed(() => info.value.members || []);
const accessRows = computed(() => [
  { key: 'project', label: tr('This project', 'Este proyecto'), url: info.value.access.project },
  { key: 'mine', label: tr('My projects (all of the member\'s)', 'Mis proyectos (todos los del miembro)'), url: info.value.access.my_projects },
  { key: 'login', label: tr('Sign-in page', 'Página de ingreso'), url: info.value.access.login },
].filter(a => a.url));
const isUrl = u => /^https?:\/\/\S+$/i.test(u || '');

async function load() {
  loading.value = true;
  try {
    info.value = await http.get(`projects/${props.projectId}/config`);
    links.value = (info.value.links || []).map(l => ({ ...l }));
    dirty.value = false;
  } finally { loading.value = false; }
}
async function saveLinks() {
  const clean = links.value.filter(l => (l.url || '').trim());
  if (clean.some(l => !isUrl(l.url.trim()))) { message.warning(tr('Write full addresses (https://...).', 'Escribe direcciones completas (https://...).')); return; }
  saving.value = true;
  try {
    await http.put(`projects/${props.projectId}/config`, { links: clean });
    message.success(tr('Links saved.', 'Enlaces guardados.'));
    await load();
  } catch (e) { message.error(e?.data?.message || tr('Could not save.', 'No se pudo guardar.')); } finally { saving.value = false; }
}
async function copy(text) {
  try { await navigator.clipboard.writeText(text); message.success(tr('Copied', 'Copiado')); } catch (e) { /* clipboard blocked */ }
}
function accessText(m, link = null) {
  const lines = [
    `Hola ${m.name},`,
    `Te damos acceso al proyecto "${props.title}" en Murano.`,
    '',
    `Entra aquí: ${info.value.access.project}`,
    `Usuario: ${m.login}`,
    m.password ? `Contraseña: ${m.password} (te pedirá cambiarla al entrar)` : 'Contraseña: la tuya. Si no la recuerdas, usa "¿Olvidaste tu contraseña?".',
  ];
  if (link) lines.push('', `O entra directo desde este celular (sin contraseña, solo funciona en el primer equipo que lo abra): ${link}`);
  lines.push('', 'Ahí verás tus tareas, podrás registrar tus avances y subir documentos.');
  return lines.join('\n');
}
async function whatsapp(m) {
  let link = null;
  if (withLink.value) {
    try { link = (await http.post(`projects/${props.projectId}/members/${m.id}/access-link`)).url; } catch (e) {
      message.warning(e?.data?.message || tr('No sign-in link for this login.', 'Este login no admite link de acceso.'));
    }
  }
  window.open(`https://wa.me/?text=${encodeURIComponent(accessText(m, link))}`, '_blank', 'noopener');
}
async function sendMail(m) {
  busy.value = m.id;
  try {
    const r = await http.post(`projects/${props.projectId}/members/${m.id}/send-credentials`);
    message.success(tr(`Sent to ${r.to}.`, `Enviado a ${r.to}.`));
  } catch (e) { message.error(e?.data?.message || tr('Could not send it.', 'No se pudo enviar.')); } finally { busy.value = null; }
}

onMounted(load);
</script>

<style scoped>
.card { margin-bottom: 0; }
.mt { margin-top: 16px; }
.mt-s { margin-top: 10px; font-size: 12px; }
.ic { color: #6366f1; }
.acc { padding: 5px 0; border-bottom: 1px dashed rgba(128, 128, 128, 0.2); }
.acc-l { font-size: 11.5px; opacity: 0.6; }
.acc-v { display: flex; align-items: center; gap: 4px; font-size: 13px; word-break: break-all; }
.hint { font-size: 12px; opacity: 0.65; margin-top: 8px; }
.lk { display: flex; gap: 6px; align-items: center; margin-bottom: 6px; }
.lk-l { width: 38%; }
.lk-u { flex: 1; }
.add { font-size: 12.5px; }
.mem { display: flex; align-items: center; gap: 10px; padding: 7px 0; border-bottom: 1px solid rgba(128, 128, 128, 0.12); }
.av { background: #6366f1; flex: none; }
.av.client { background: #0d9488; }
.who { flex: 1; min-width: 0; }
.nm { font-weight: 500; }
.role { font-size: 11.5px; opacity: 0.6; font-weight: 400; margin-inline-start: 6px; }
.cred { display: flex; flex-wrap: wrap; gap: 2px 14px; font-size: 12px; align-items: center; }
.cred code { font-size: 11.5px; background: rgba(128, 128, 128, 0.12); padding: 0 4px; border-radius: 3px; }
.acts { flex: none; }
.wa { color: #16a34a; }
.muted { opacity: 0.55; }
.small { font-size: 12px; }
.tight { margin: 0; }
</style>
