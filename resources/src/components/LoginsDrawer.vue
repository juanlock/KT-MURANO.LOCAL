<template>
  <!--
    Company logins (user's rule, 2026-10-25): in the profile menu, for the
    company's admin only (admin login + permission company_logins_view). Every login of the company with its
    role, what the role can do (its permissions by group) and the password
    when it is still the standard one of its type; a changed password can't
    be read back (stored hashed) and shows as "custom".
  -->
  <a-drawer :open="open" width="min(1320px, 96vw)" :title="tx('Company logins', 'Logins de la empresa')" @close="$emit('close')">
    <div class="bar">
      <a-segmented v-model:value="type" :options="typeOptions" />
      <a-select v-model:value="role" :options="roleOptions" allow-clear show-search option-filter-prop="label"
        :placeholder="tx('All roles', 'Todos los roles')" style="width: 190px" />
      <a-input-search v-model:value="search" allow-clear :placeholder="tx('Search name, user or email', 'Buscar nombre, usuario o correo')" style="width: 210px" />
      <a-button @click="load"><template #icon><ReloadOutlined /></template></a-button>
    </div>
    <a-alert type="info" show-icon class="note"
      :message="tx('Passwords are stored encrypted: only the standard password of each type can be shown. One changed by the user shows as Custom.', 'Las contraseñas se guardan cifradas: solo se puede mostrar la contraseña estándar de cada tipo. Si el usuario la cambió, aparece como Personalizada.')" />
    <a-table :data-source="rows" :columns="columns" row-key="id" size="small" :loading="loading" :pagination="{ pageSize: 25, hideOnSinglePage: true }">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'name'">
          <div class="nm">{{ record.name || record.username }}</div>
          <div class="muted">{{ record.email }}</div>
        </template>
        <template v-else-if="column.key === 'type'">{{ typeLabel(record.type) }}</template>
        <template v-else-if="column.key === 'function'">
          <div v-if="record.job" class="nm small-b">{{ record.job }}</div>
          <a-tooltip :title="functionOf(record)"><div class="fn">{{ functionOf(record) }}</div></a-tooltip>
        </template>
        <template v-else-if="column.key === 'role'">
          <a-popover v-if="record.role" :title="roleName(record)" placement="left" trigger="click" overlay-class-name="logins-pop">
            <template #content>
              <div v-if="record.role_description" class="muted" style="margin-bottom: 6px">{{ record.role_description }}</div>
              <div v-for="g in accesses(record.role_id)" :key="g.group" class="acc">
                <b>{{ g.group }}:</b> {{ g.items.join(', ') }}
              </div>
              <div v-if="!accesses(record.role_id).length" class="muted">{{ tx('No permissions.', 'Sin permisos.') }}</div>
            </template>
            <a-tag color="blue" class="role-tag">{{ roleName(record) }} <InfoCircleOutlined /></a-tag>
          </a-popover>
          <span v-else class="muted">—</span>
          <div class="muted small">{{ tx('{n} accesses', '{n} accesos').replace('{n}', (perms[record.role_id] || []).length) }}</div>
        </template>
        <template v-else-if="column.key === 'patients'">
          <a-tooltip v-if="record.patients && record.patients.length" :title="record.patients.join(', ')">
            <a-tag color="purple" style="cursor: help">{{ record.patients.length }}</a-tag>
          </a-tooltip>
          <span v-else-if="record.patients" class="muted">0</span>
        </template>
        <template v-else-if="column.key === 'active'">
          <a-tag :color="record.active ? 'green' : 'default'">{{ record.active ? tx('Active', 'Activo') : tx('Disabled', 'Desactivado') }}</a-tag>
          <div v-if="record.portal !== null" class="muted small">{{ record.portal ? tx('Portal on', 'Portal activo') : tx('No portal', 'Sin portal') }}</div>
        </template>
        <template v-else-if="column.key === 'password'">
          <span v-if="record.password" class="pw">
            <code>{{ record.password }}</code>
            <a-button type="text" size="small" @click="copy(record.password)"><template #icon><CopyOutlined /></template></a-button>
          </span>
          <span v-else class="muted">{{ tx('Custom (not visible)', 'Personalizada (no visible)') }}</span>
          <a-button v-if="canSetPw(record)" type="link" size="small" @click="pwOpen(record)"><template #icon><KeyOutlined /></template>{{ tx('Change', 'Cambiar') }}</a-button>
        </template>
        <template v-else-if="column.key === 'open'">
          <a-tooltip v-if="canOpen(record)" :title="tx('Work as this login (Back to admin in the header)', 'Entrar como este login (Volver a admin en el encabezado)')">
            <a-button size="small" :loading="opening === record.id" @click="openAs(record)"><template #icon><LoginOutlined /></template>{{ tx('Enter', 'Entrar') }}</a-button>
          </a-tooltip>
        </template>
      </template>
    </a-table>
    <!-- Change a login's password from the table (2026-11-03): a custom one can't be shown. -->
    <a-modal v-model:open="pw.open" :title="tx('Change password', 'Cambiar contraseña') + (pw.row ? ' · ' + (pw.row.name || pw.row.email) : '')"
             :ok-text="tx('Save', 'Guardar')" :cancel-text="tx('Cancel', 'Cancelar')" :confirm-loading="pw.busy" @ok="pwSave">
      <div class="muted" style="margin-bottom: 6px">{{ pw.row && pw.row.email }}</div>
      <a-input-password v-model:value="pw.value" :placeholder="tx('New password (6+ characters)', 'Nueva contraseña (6+ caracteres)')" autocomplete="new-password" />
      <a-space wrap style="margin-top: 8px">
        <a-button v-if="pw.row && pw.row.standard_password" size="small" @click="pw.value = pw.row.standard_password">
          {{ tx('Use the standard one', 'Usar la estándar') }} ({{ pw.row.standard_password }})
        </a-button>
        <a-button size="small" @click="pw.value = randomPw()">{{ tx('Generate', 'Generar') }}</a-button>
        <a-button v-if="pw.value" size="small" @click="copy(pw.value)"><template #icon><CopyOutlined /></template>{{ tx('Copy', 'Copiar') }}</a-button>
      </a-space>
      <div style="margin-top: 10px">
        <a-checkbox v-model:checked="pw.mustChange">{{ tx('Ask them to choose their own at the next sign-in', 'Pedirle que elija la suya al entrar') }}</a-checkbox>
      </div>
    </a-modal>
  </a-drawer>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { ReloadOutlined, CopyOutlined, InfoCircleOutlined, LoginOutlined, KeyOutlined } from '@ant-design/icons-vue';
import http from '../lib/http';
import { useAuthStore } from '../stores/auth';
import { sortByLabel } from '../lib/filterOptions';
import { t } from '../i18n';
import { useUiStore } from '../stores/ui';
import { PERMISSION_GROUPS } from '../config/permissions';

const props = defineProps({ open: Boolean });
defineEmits(['close']);
const ui = useUiStore();
const tx = (en, es) => (ui.locale === 'es' ? es : en);

const loading = ref(false);
const logins = ref([]);
const perms = ref({});
const type = ref('all');
const search = ref('');

const TYPES = {
  admin: ['Access (admin)', 'Acceso (admin)'], employee: ['Employees', 'Empleados'], customer: ['Customers', 'Clientes'],
  supplier: ['Suppliers', 'Proveedores'], sales_agent: ['Sales agents', 'Vendedores'], Online_store: ['Online store', 'Tienda en línea'],
};
const typeLabel = v => (TYPES[v] ? tx(...TYPES[v]) : v);
// Fixed order (access first, then staff, then outside people), not alphabetical.
const typeOptions = computed(() => [{ value: 'all', label: `${tx('All', 'Todos')} (${logins.value.length})` }]
  .concat(Object.keys(TYPES).filter(k => logins.value.some(l => l.type === k))
    .map(k => ({ value: k, label: `${typeLabel(k)} (${logins.value.filter(l => l.type === k).length})` }))));
// The role as the screen shows it: in Spanish its label (roles.label: Recepción,
// Médico especialista...), else its name.
const roleName = r => (ui.locale === 'es' && r.role_label ? r.role_label : r.role);
// Roles of the logins of the chosen type, with how many each has.
const role = ref(undefined);
const roleOptions = computed(() => {
  const counts = {};
  const names = {};
  logins.value.filter(l => l.role && (type.value === 'all' || l.type === type.value))
    .forEach(l => { counts[l.role] = (counts[l.role] || 0) + 1; names[l.role] = roleName(l); });
  return sortByLabel(Object.entries(counts).map(([name, n]) => ({ value: name, label: `${names[name]} (${n})` })));
});
watch(type, () => { if (role.value && !roleOptions.value.some(o => o.value === role.value)) role.value = undefined; });
const rows = computed(() => {
  const q = search.value.trim().toLowerCase();
  return logins.value.filter(l => (type.value === 'all' || l.type === type.value) && (!role.value || l.role === role.value)
    && (!q || [l.name, l.username, l.email, l.role, l.role_label].some(v => String(v || '').toLowerCase().includes(q))));
});
const columns = computed(() => [
  { title: tx('Login', 'Login'), key: 'name' },
  { title: tx('Type', 'Tipo'), key: 'type', width: 130 },
  { title: tx('Function', 'Función'), key: 'function', width: 240 },
  { title: tx('Role and accesses', 'Rol y accesos'), key: 'role', width: 220 },
  // Hospital staff only: patients assigned today (roster + attending doctor).
  ...(logins.value.some(l => l.patients) ? [{ title: tx('Patients today', 'Pacientes hoy'), key: 'patients', width: 110 }] : []),
  { title: tx('Status', 'Estado'), key: 'active', width: 110 },
  { title: tx('Password', 'Contraseña'), key: 'password', width: 210 },
  { title: '', key: 'open', width: 100 },
]);

// "Enter" (switch + back): any active login of the company but my own.
const auth = useAuthStore();
const opening = ref(null);
const canOpen = r => r.active && r.id !== auth.user?.id && r.type !== 'Online_store';
// Same tab (user's rule, 2026-10-25): every tab shares the session, so the
// admin simply becomes that login here and returns with "Back to admin".
async function openAs(r, enablePortal = false) {
  opening.value = r.id;
  try {
    const res = await http.webPost(`login/as/${r.id}`, enablePortal ? { enable_portal: 1 } : {});
    if (r.type !== 'customer') {
      try { window.localStorage.removeItem('murano_auth_cache_v1'); } catch (e) { /* storage unavailable */ }
    }
    window.location.href = res?.redirect || '/';
  } catch (e) {
    opening.value = null;
    // A customer whose portal was turned off: ask, then turn it on and enter.
    if (e?.status === 409 && e?.data?.needs_portal) {
      Modal.confirm({
        title: tx('This customer has no portal access', 'Este cliente no tiene acceso al portal'),
        content: tx('Turn on the customer portal for them and enter?', '¿Activarle el portal del cliente y entrar?'),
        okText: tx('Turn on and enter', 'Activar y entrar'), cancelText: tx('Cancel', 'Cancelar'),
        onOk: () => openAs(r, true),
      });
      return;
    }
    message.error(e?.data?.message || tx('Could not open that login', 'No se pudo abrir ese login'));
  }
}

// What the login is for: its role's description, else a sentence by role / type.
const FUNCTION_BY_ROLE = {
  // Standard roles of every company (App\Support\CompanyRoles, 2026-11-01).
  'IT Admin': ['Runs the whole company: every form and module, users and settings.', 'Administra toda la empresa: todos los formularios y módulos, usuarios y configuración.'],
  Manager: ['Runs the business: every main module, without the configuration.', 'Dirige el negocio: todos los módulos principales, sin la configuración.'],
  Info: ['The company forms and their submissions, read only.', 'Los formularios de la empresa y sus envíos, solo lectura.'],
  Helpdesk: ['Support: works the helpdesk tickets and reads the company mail.', 'Soporte: atiende los tickets de helpdesk y lee el correo de la empresa.'],
  Website: ['Website management and the portfolio.', 'Administración del sitio web y el portafolio.'],
  Formularios: ['The company forms and their submissions, read only.', 'Los formularios de la empresa y sus envíos, solo lectura.'],
  Employee: ['Staff without a role yet: intranet and own employee file.', 'Personal sin rol todavía: intranet y su ficha de empleado.'],
  // Hospital roles (App\Support\HospitalRoles)
  Emergency: ['Emergency room: arrivals, triage, the ER board, its patients and their history.', 'Emergencias: llegadas, triaje, el tablero de emergencia, sus pacientes y su historia.'],
  Admission: ['Admission window: registers the patient, the insurance or card and why they come; visitors and appointments.', 'Ventanilla de admisión: registra al paciente, su seguro o tarjeta y el motivo; visitas y citas.'],
  Triage: ['Triage nurse: calls the patient, takes vital signs, weight and height, sets the level and sends them to an ER bed.', 'Enfermera de triaje: llama al paciente, toma signos vitales, peso y estatura, fija el nivel y lo envía a una cama de emergencia.'],
  Doctor: ['Doctor: the patients of the shift, progress notes, prescriptions, lab, surgeries.', 'Médico: los pacientes del turno, evolución, prescripciones, laboratorio, cirugías.'],
  Nurse: ['Nurse: the patients of the shift, medication doses, vital signs and care.', 'Enfermero(a): los pacientes del turno, dosis de medicación, signos vitales y cuidados.'],
  'Head Nurse': ['Head nurse: the shifts and who looks after each patient; every medication sheet.', 'Jefe de enfermería: los turnos y quién atiende a cada paciente; todas las hojas de medicación.'],
  Pharmacy: ['Pharmacy: medicine stock, batches and dispatching the treatments.', 'Farmacia: stock de medicinas, lotes y despacho de los tratamientos.'],
  Billing: ['Cashier: hospital bills and payments.', 'Caja: facturas del hospital y pagos.'],
  Laboratory: ['Laboratory: lab orders, results and images.', 'Laboratorio: órdenes, resultados e imágenes.'],
  'Operating Room': ['Operating room: surgeries, rooms and moving the patients.', 'Quirófano: cirugías, salas y traslado de los pacientes.'],
  Customer: ['Customer: signs in to the customer portal (invoices, payments, appointments).', 'Cliente: entra al portal del cliente (facturas, pagos, citas).'],
  Supplier: ['Supplier: sees their purchases and payments.', 'Proveedor: ve sus compras y pagos.'],
};
const FUNCTION_BY_TYPE = {
  admin: ['Administrator of the company.', 'Administrador de la empresa.'],
  employee: ['Staff of the company.', 'Personal de la empresa.'],
  customer: ['Customer: signs in to the customer portal (invoices, payments, appointments).', 'Cliente: entra al portal del cliente (facturas, pagos, citas).'],
  supplier: ['Supplier: sees their purchases and payments.', 'Proveedor: ve sus compras y pagos.'],
  sales_agent: ['Sales agent: sales and commissions.', 'Vendedor: ventas y comisiones.'],
  Online_store: ['Online store shopper.', 'Comprador de la tienda en línea.'],
};
function functionOf(r) {
  // Known roles in the screen's language; a company's own role keeps its description.
  if (FUNCTION_BY_ROLE[r.role]) return tx(...FUNCTION_BY_ROLE[r.role]);
  if (r.role_description) return r.role_description;
  const pair = FUNCTION_BY_TYPE[r.type];
  return pair ? tx(pair[0], pair[1]) : '—';
}

// permission name -> [group label, item label]
const LABEL = {};
for (const g of PERMISSION_GROUPS) {
  for (const i of g.items) LABEL[i.v] = [t(g.label, g.label), t(i.l, i.f || i.l)];
}
function accesses(roleId) {
  const groups = {};
  for (const p of perms.value[roleId] || []) {
    const [g, i] = LABEL[p] || [tx('Other', 'Otros'), p];
    (groups[g] ||= []).push(i);
  }
  return Object.entries(groups).map(([group, items]) => ({ group, items }));
}

async function load() {
  loading.value = true;
  try {
    const res = await http.get('company-logins');
    logins.value = res.logins || [];
    perms.value = res.role_permissions || {};
  } catch (e) {
    message.error(e?.data?.message || tx('Could not load', 'No se pudo cargar'));
  } finally {
    loading.value = false;
  }
}
// Change a login's password (2026-11-03): any login of the company but my own.
const pw = ref({ open: false, row: null, value: '', mustChange: true, busy: false });
const canSetPw = r => r.id !== auth.user?.id;
function pwOpen(r) {
  pw.value = { open: true, row: r, value: '', mustChange: true, busy: false };
}
function randomPw() {
  const a = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
  const n = new Uint32Array(10);
  crypto.getRandomValues(n);
  return Array.from(n, x => a[x % a.length]).join('') + '7';
}
async function pwSave() {
  if ((pw.value.value || '').length < 6) return message.warning(tx('At least 6 characters.', 'Mínimo 6 caracteres.'));
  pw.value.busy = true;
  try {
    await http.post(`company-logins/${pw.value.row.id}/password`, { password: pw.value.value, must_change: pw.value.mustChange });
    message.success(tx('Password changed', 'Contraseña cambiada'));
    pw.value.open = false;
    load();
  } catch (e) {
    message.error(e?.data?.message || tx('Could not change it', 'No se pudo cambiar'));
  } finally {
    pw.value.busy = false;
  }
}
async function copy(v) {
  try { await navigator.clipboard.writeText(v); message.success(tx('Copied', 'Copiado')); } catch (e) { /* clipboard blocked */ }
}
watch(() => props.open, o => { if (o) load(); });
</script>

<style scoped>
.bar { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 10px; }
.note { margin-bottom: 10px; }
.nm { font-weight: 600; }
.muted { opacity: 0.6; font-size: 12px; }
.small { font-size: 11px; }
.pw { display: inline-flex; align-items: center; gap: 2px; }
.pw code { background: rgba(22, 119, 255, 0.08); padding: 1px 6px; border-radius: 4px; }
.role-tag { cursor: pointer; }
.acc { font-size: 12px; margin-bottom: 3px; max-width: 460px; }
.small-b { font-size: 12.5px; }
.fn { font-size: 12px; opacity: 0.75; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
</style>
