<template>
  <div class="page">
    <PageHeader :title="tx('Login security', 'Seguridad de acceso')" :breadcrumb="[$t('Settings'), tx('Login security', 'Seguridad de acceso')]" />

    <a-spin :spinning="loading">
      <a-alert type="info" show-icon style="margin-bottom: 16px">
        <template #message>{{ tx('Rules for every login of the company', 'Reglas para todos los logins de la empresa') }}</template>
        <template #description>
          <ul class="rules">
            <li>{{ tx('5 wrong passwords in an hour lock the login for 1 hour, from any IP. The person and the admins get an email.', '5 contraseñas incorrectas en una hora bloquean el login por 1 hora, desde cualquier IP. La persona y los admins reciben un email.') }}</li>
            <li>{{ tx('Whoever signs in with a standard password must choose their own before entering (demo logins excepted).', 'Quien entra con una contraseña estándar debe elegir una propia antes de entrar (salvo los logins de demos).') }}</li>
            <li>{{ tx('Passwords: at least 8 characters with letters and numbers, not standard, not the email, not one of the last 3.', 'Contraseñas: al menos 8 caracteres con letras y números, ni estándar, ni el email, ni una de las 3 últimas.') }}</li>
          </ul>
        </template>
      </a-alert>

      <a-row :gutter="16">
        <a-col :xs="24" :lg="12">
          <a-card :title="tx('Two-step verification', 'Verificación en dos pasos')" style="margin-bottom: 16px">
            <p class="help">{{ tx('Who must enter a code after the password: a 6-digit code by email, or the authenticator app when the person set one up in Profile. "Remember this computer" skips it for 30 days.', 'Quién debe escribir un código después de la contraseña: un código de 6 dígitos por email, o la app autenticadora si la persona la configuró en su Perfil. "Recordar este equipo" lo omite por 30 días.') }}</p>
            <a-checkbox-group v-model:value="form.types" class="types">
              <a-checkbox v-for="tp in data.type_options || []" :key="tp" :value="tp">
                {{ typeLabel(tp) }} <span class="muted">({{ (data.type_counts || {})[tp] || 0 }})</span>
              </a-checkbox>
            </a-checkbox-group>
            <a-divider style="margin: 14px 0" />
            <div class="row-switch">
              <a-switch v-model:checked="form.alerts" />
              <div>
                <b>{{ tx('Sign-in alerts', 'Alertas de ingreso') }}</b>
                <div class="muted">{{ tx('Email the person when their login is used from a computer or browser it never used.', 'Avisa por email a la persona cuando su login se usa desde un equipo o navegador nuevo.') }}</div>
              </div>
            </div>
            <a-button type="primary" style="margin-top: 16px" :loading="saving" :disabled="!changed" @click="save">{{ $t('Save') }}</a-button>
          </a-card>
        </a-col>

        <a-col :xs="24" :lg="12">
          <a-card :title="tx('Locked logins', 'Logins bloqueados')" style="margin-bottom: 16px">
            <a-empty v-if="!(data.locked || []).length" :description="tx('No login is locked.', 'Ningún login está bloqueado.')" />
            <div v-for="u in data.locked || []" :key="u.id" class="person">
              <div>
                <b>{{ u.name }}</b> <a-tag>{{ typeLabel(u.type) }}</a-tag>
                <div class="muted">{{ u.email }} · {{ tx('until', 'hasta') }} {{ dateTime(u.locked_until) }}</div>
              </div>
              <a-button size="small" :loading="busy === 'u' + u.id" @click="unlock(u)">{{ tx('Unlock', 'Desbloquear') }}</a-button>
            </div>
          </a-card>

          <a-card :title="tx('Authenticator app', 'App autenticadora')" style="margin-bottom: 16px">
            <a-empty v-if="!(data.with_app || []).length" :description="tx('Nobody uses an authenticator app yet.', 'Nadie usa una app autenticadora todavía.')" />
            <div v-for="u in data.with_app || []" :key="u.id" class="person">
              <div>
                <b>{{ u.name }}</b> <a-tag>{{ typeLabel(u.type) }}</a-tag>
                <div class="muted">{{ u.email }} · {{ tx('since', 'desde') }} {{ date(u.since) }}</div>
              </div>
              <a-popconfirm :title="tx('Remove their app? (lost phone) They will get email codes when required.', '¿Quitar su app? (teléfono perdido) Recibirá códigos por email cuando se exijan.')" @confirm="resetApp(u)">
                <a-button size="small" danger :loading="busy === 'a' + u.id">{{ tx('Reset', 'Resetear') }}</a-button>
              </a-popconfirm>
            </div>
          </a-card>
        </a-col>
      </a-row>

      <a-card :title="tx('Recent activity', 'Actividad reciente')" :body-style="{ padding: 0 }">
        <a-table :columns="eventColumns" :data-source="data.events || []" :pagination="{ pageSize: 20, size: 'small' }" row-key="id" size="small" :scroll="{ x: 'max-content' }">
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'created_at'">{{ dateTime(record.created_at) }}</template>
            <template v-else-if="column.key === 'who'">
              {{ record.user_name || '—' }}
              <div class="muted small">{{ record.email }}</div>
            </template>
            <template v-else-if="column.key === 'event'">
              <a-tag :color="EVENT_COLORS[record.event] || 'default'">{{ eventLabel(record.event) }}</a-tag>
              <span v-if="record.note" class="muted small">{{ record.note }}</span>
            </template>
            <template v-else-if="column.key === 'channel'">{{ channelLabel(record.channel) }}</template>
          </template>
        </a-table>
      </a-card>
    </a-spin>
  </div>
</template>

<script setup>
/**
 * Settings -> Login security (2026-10-27), permission login_security, acting company
 * (LoginSecurityController; rules in App\Support\LoginSecurity): which user types need
 * the two-step code, sign-in alerts, locked logins (unlock), authenticator apps (reset
 * when a phone is lost), the last 100 sign-in events. On AdminSystem: the SuperAdmin's.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import { useUiStore } from '../../stores/ui';
import http from '../../lib/http';

const ui = useUiStore();
const tx = (en, es) => (ui.locale === 'es' ? es : en);
const { date, dateTime } = useFormat();

const loading = ref(true);
const saving = ref(false);
const busy = ref(null);
const data = ref({});
const form = ref({ types: [], alerts: true });
const changed = computed(() => JSON.stringify([...form.value.types].sort()) !== JSON.stringify([...(data.value.types || [])].sort())
  || form.value.alerts !== data.value.alerts);

const TYPES = {
  SuperAdmin: ['SuperAdmin', 'SuperAdmin'], admin: ['Admins', 'Admins'], employee: ['Employees', 'Empleados'],
  customer: ['Customers (portal)', 'Clientes (portal)'], supplier: ['Suppliers', 'Proveedores'], sales_agent: ['Sales agents', 'Agentes de venta'],
};
const typeLabel = tp => (TYPES[tp] ? tx(...TYPES[tp]) : tp);
const EVENTS = {
  success: ['Signed in', 'Ingresó'], failed: ['Wrong password', 'Contraseña incorrecta'], locked: ['Locked', 'Bloqueado'],
  blocked: ['Refused (locked)', 'Rechazado (bloqueado)'], unlocked: ['Unlocked', 'Desbloqueado'], code_sent: ['Code sent', 'Código enviado'],
  code_ok: ['Code OK', 'Código correcto'], code_failed: ['Wrong code', 'Código incorrecto'], password_changed: ['Password changed', 'Cambió su contraseña'],
  new_device: ['New computer', 'Equipo nuevo'], '2fa_on': ['2-step on', '2 pasos activado'], '2fa_off': ['2-step off', '2 pasos desactivado'],
};
const EVENT_COLORS = { success: 'green', failed: 'orange', locked: 'red', blocked: 'red', unlocked: 'blue', code_failed: 'orange', new_device: 'gold', password_changed: 'blue', '2fa_on': 'purple', '2fa_off': 'default' };
const eventLabel = e => (EVENTS[e] ? tx(...EVENTS[e]) : e);
const channelLabel = c => ({ web: tx('Web', 'Web'), portal: tx('Portal', 'Portal'), mobile: tx('App', 'App'), api: 'API' }[c] || c);

const eventColumns = computed(() => [
  { title: tx('When', 'Cuándo'), key: 'created_at', width: 150 },
  { title: tx('Who', 'Quién'), key: 'who' },
  { title: tx('Event', 'Evento'), key: 'event' },
  { title: tx('Where', 'Dónde'), key: 'channel', width: 80 },
  { title: 'IP', dataIndex: 'ip', key: 'ip' },
  { title: tx('Device', 'Equipo'), dataIndex: 'device', key: 'device' },
]);

function apply(res) {
  data.value = res || {};
  form.value = { types: [...(data.value.types || [])], alerts: !!data.value.alerts };
}

async function load() {
  loading.value = true;
  try {
    apply(await http.get('login_security'));
  } catch (e) {
    message.error(e?.data?.message || tx('Could not load.', 'No se pudo cargar.'));
  } finally {
    loading.value = false;
  }
}

async function save() {
  saving.value = true;
  try {
    apply(await http.put('login_security', form.value));
    message.success(tx('Saved', 'Guardado'));
  } catch (e) {
    message.error(e?.data?.message || tx('Could not save.', 'No se pudo guardar.'));
  } finally {
    saving.value = false;
  }
}

async function unlock(u) {
  busy.value = 'u' + u.id;
  try {
    apply(await http.post(`login_security/unlock/${u.id}`));
    message.success(tx('Unlocked', 'Desbloqueado'));
  } catch (e) {
    message.error(e?.data?.message || tx('Could not unlock.', 'No se pudo desbloquear.'));
  } finally {
    busy.value = null;
  }
}

async function resetApp(u) {
  busy.value = 'a' + u.id;
  try {
    apply(await http.post(`login_security/reset-app/${u.id}`));
    message.success(tx('Done', 'Listo'));
  } catch (e) {
    message.error(e?.data?.message || tx('Could not reset.', 'No se pudo resetear.'));
  } finally {
    busy.value = null;
  }
}

onMounted(load);
</script>

<style scoped>
.rules { margin: 4px 0 0; padding-left: 18px; }
.help { opacity: 0.75; margin-top: 0; }
.types { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 8px; }
.types :deep(.ant-checkbox-wrapper) { margin-inline-start: 0; }
.row-switch { display: flex; gap: 12px; align-items: flex-start; }
.muted { opacity: 0.6; font-size: 12.5px; }
.small { font-size: 12px; }
.person { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 8px 0; border-top: 1px solid rgba(5, 5, 5, 0.06); }
.person:first-child { border-top: 0; }
</style>
