<template>
  <a-card style="margin-top: 16px">
    <div class="section-title"><SafetyCertificateOutlined /> {{ tx('Two-step verification', 'Verificación en dos pasos') }}</div>
    <a-spin :spinning="loading">
      <template v-if="st">
        <a-alert v-if="st.demo" type="info" show-icon style="margin-bottom: 12px"
          :message="tx('Demo login: two-step verification does not apply to it.', 'Login de demo: la verificación en dos pasos no se le aplica.')" />
        <p class="status">
          <a-tag :color="st.active ? 'green' : 'default'">{{ st.active ? tx('On', 'Activa') : tx('Off', 'Inactiva') }}</a-tag>
          <span v-if="st.required">{{ tx('Your company requires it for your type of login.', 'Su empresa la exige para su tipo de login.') }}</span>
          <span v-else-if="st.active">{{ tx('You turned it on.', 'Usted la activó.') }}</span>
          <span v-else>{{ tx('After your password you can also ask for a code: someone who learns your password still can\'t enter.', 'Después de la contraseña puede pedir también un código: quien sepa su contraseña igual no podrá entrar.') }}</span>
        </p>

        <!-- Email codes -->
        <div class="opt">
          <div>
            <b>{{ tx('Code by email', 'Código por email') }}</b>
            <div class="muted">{{ tx('A 6-digit code to your email each time you sign in from a computer not remembered.', 'Un código de 6 dígitos a su email cada vez que entra desde un equipo no recordado.') }}</div>
          </div>
          <a-switch :checked="st.email_on || (st.required && !st.app_on)" :disabled="st.required || st.app_on" :loading="busy === 'email'" @change="setEmail" />
        </div>

        <!-- Authenticator app -->
        <div class="opt">
          <div>
            <b>{{ tx('Authenticator app', 'App autenticadora') }}</b>
            <div class="muted">{{ tx('Google Authenticator, Microsoft Authenticator or similar: the code comes from your phone, even offline.', 'Google Authenticator, Microsoft Authenticator u otra: el código sale de su teléfono, incluso sin internet.') }}</div>
            <div v-if="st.app_on" class="muted">{{ tx('Recovery codes left', 'Códigos de recuperación disponibles') }}: <b>{{ st.recovery_left }}</b></div>
          </div>
          <a-space v-if="st.app_on" wrap>
            <a-button size="small" @click="askPassword('recovery')">{{ tx('New recovery codes', 'Nuevos códigos de recuperación') }}</a-button>
            <a-button size="small" danger @click="askPassword('disable')">{{ tx('Turn off', 'Desactivar') }}</a-button>
          </a-space>
          <a-button v-else type="primary" size="small" :loading="busy === 'start'" @click="startApp">{{ tx('Set up', 'Configurar') }}</a-button>
        </div>

        <div v-if="st.remembered" class="opt">
          <div class="muted">{{ tx('Computers remembered for 30 days', 'Equipos recordados por 30 días') }}: <b>{{ st.remembered }}</b></div>
          <a-button size="small" :loading="busy === 'forget'" @click="forget">{{ tx('Forget them', 'Olvidarlos') }}</a-button>
        </div>
      </template>
    </a-spin>

    <!-- Set up the app: QR + first code -->
    <a-modal v-model:open="setupOpen" :title="tx('Set up the authenticator app', 'Configurar la app autenticadora')" :footer="null" :width="440" destroy-on-close>
      <template v-if="!recovery.length">
        <ol class="steps">
          <li>{{ tx('Open the app on your phone and add an account by scanning this code.', 'Abra la app en su teléfono y agregue una cuenta escaneando este código.') }}</li>
          <li>{{ tx('Type below the 6-digit code it shows.', 'Escriba abajo el código de 6 dígitos que muestra.') }}</li>
        </ol>
        <div class="qr"><img v-if="qr" :src="qr" alt="QR" /></div>
        <div class="muted center">{{ tx('Can\'t scan? Enter this key:', '¿No puede escanear? Escriba esta clave:') }}</div>
        <div class="secret">{{ setup.secret }}</div>
        <a-input v-model:value="setupCode" class="code" :maxlength="6" inputmode="numeric" placeholder="000000" @press-enter="confirmApp" />
        <a-button type="primary" block style="margin-top: 12px" :loading="busy === 'confirm'" @click="confirmApp">{{ tx('Turn on', 'Activar') }}</a-button>
      </template>
      <RecoveryCodes v-else :codes="recovery" :tx="tx" @done="setupOpen = false; recovery = []" />
    </a-modal>

    <!-- Password check (turn off / new recovery codes) -->
    <a-modal v-model:open="pwOpen" :title="tx('Confirm with your password', 'Confirme con su contraseña')" :footer="null" :width="400" destroy-on-close>
      <template v-if="!recovery.length">
        <a-input-password v-model:value="pw" :placeholder="tx('Password', 'Contraseña')" @press-enter="runPassword" />
        <a-button type="primary" block style="margin-top: 12px" :danger="pwAction === 'disable'" :loading="busy === 'pw'" @click="runPassword">
          {{ pwAction === 'disable' ? tx('Turn off the app', 'Desactivar la app') : tx('Create new codes', 'Crear códigos nuevos') }}
        </a-button>
      </template>
      <RecoveryCodes v-else :codes="recovery" :tx="tx" @done="pwOpen = false; recovery = []" />
    </a-modal>
  </a-card>
</template>

<script setup>
/**
 * Profile -> Two-step verification (2026-10-27), LoginSecurityController my_2fa*:
 * email codes on / off (locked on when the company requires it), the authenticator
 * app (QR from the otpauth URI, first code to confirm, recovery codes shown once,
 * turn off / new codes with the password), forget the computers remembered 30 days.
 */
import { ref, onMounted, h } from 'vue';
import { message } from 'ant-design-vue';
import { SafetyCertificateOutlined } from '@ant-design/icons-vue';
import QRCode from 'qrcode';
import { useUiStore } from '../../stores/ui';
import http from '../../lib/http';

const ui = useUiStore();
const tx = (en, es) => (ui.locale === 'es' ? es : en);

const loading = ref(true);
const busy = ref(null);
const st = ref(null);

async function load() {
  loading.value = true;
  try { st.value = await http.get('my_2fa'); } catch (e) { /* card stays empty */ } finally { loading.value = false; }
}

async function call(key, fn, ok) {
  busy.value = key;
  try {
    const r = await fn();
    if (ok) message.success(ok);
    return r;
  } catch (e) {
    message.error(e?.data?.message || tx('Something went wrong.', 'Algo salió mal.'));
    return null;
  } finally {
    busy.value = null;
  }
}

async function setEmail(on) {
  const r = await call('email', () => http.post('my_2fa/email', { on }), tx('Saved', 'Guardado'));
  if (r) st.value = r;
}

const setupOpen = ref(false);
const setup = ref({ secret: '', uri: '' });
const setupCode = ref('');
const qr = ref('');
const recovery = ref([]);
async function startApp() {
  const r = await call('start', () => http.post('my_2fa/app/start'));
  if (!r) return;
  setup.value = r;
  setupCode.value = '';
  recovery.value = [];
  qr.value = await QRCode.toDataURL(r.uri, { width: 220, margin: 1 });
  setupOpen.value = true;
}
async function confirmApp() {
  const r = await call('confirm', () => http.post('my_2fa/app/confirm', { code: setupCode.value }), tx('The app is on', 'La app quedó activa'));
  if (!r) return;
  recovery.value = r.recovery_codes || [];
  st.value = r;
}

const pwOpen = ref(false);
const pwAction = ref('disable');
const pw = ref('');
function askPassword(action) {
  pwAction.value = action;
  pw.value = '';
  recovery.value = [];
  pwOpen.value = true;
}
async function runPassword() {
  if (pwAction.value === 'disable') {
    const r = await call('pw', () => http.post('my_2fa/app/disable', { password: pw.value }), tx('The app is off', 'La app quedó desactivada'));
    if (r) { st.value = r; pwOpen.value = false; }
  } else {
    const r = await call('pw', () => http.post('my_2fa/recovery', { password: pw.value }));
    if (r) { recovery.value = r.recovery_codes || []; load(); }
  }
}

async function forget() {
  const r = await call('forget', () => http.post('my_2fa/forget-devices'), tx('Done', 'Listo'));
  if (r) st.value = r;
}

/** The recovery codes, shown once, with copy. */
const RecoveryCodes = (props, { emit }) => h('div', [
  h('p', props.tx('Save these recovery codes somewhere safe. Each one lets you in once if you lose your phone. They are not shown again.',
    'Guarde estos códigos de recuperación en un lugar seguro. Cada uno sirve una vez para entrar si pierde su teléfono. No se vuelven a mostrar.')),
  h('div', { class: 'codes' }, props.codes.map(c => h('code', { key: c }, c))),
  h('div', { style: 'display:flex;gap:8px;margin-top:12px' }, [
    h('button', { class: 'ant-btn ant-btn-default', type: 'button', onClick: () => navigator.clipboard?.writeText(props.codes.join('\n')).then(() => message.success(props.tx('Copied', 'Copiado'))) }, props.tx('Copy', 'Copiar')),
    h('button', { class: 'ant-btn ant-btn-primary', type: 'button', onClick: () => emit('done') }, props.tx('I saved them', 'Ya los guardé')),
  ]),
]);
RecoveryCodes.props = ['codes', 'tx'];
RecoveryCodes.emits = ['done'];

onMounted(load);
</script>

<style scoped>
.section-title { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; opacity: 0.65; margin-bottom: 16px; }
.status { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.opt { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 10px 0; border-top: 1px solid rgba(5, 5, 5, 0.06); flex-wrap: wrap; }
.opt > div:first-child { flex: 1 1 260px; }
.muted { opacity: 0.65; font-size: 13px; }
.steps { padding-left: 18px; }
.qr { display: flex; justify-content: center; margin: 8px 0; }
.qr img { width: 220px; height: 220px; }
.center { text-align: center; }
.secret { text-align: center; font-family: monospace; font-size: 14px; letter-spacing: 2px; word-break: break-all; margin: 4px 0 12px; }
.code { text-align: center; font-size: 22px; letter-spacing: 6px; }
:deep(.codes) { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
:deep(.codes code) { padding: 6px 8px; border-radius: 6px; background: rgba(128, 128, 128, 0.12); text-align: center; font-size: 14px; }
</style>
