<template>
  <!--
    Company logins (user's rule, 2026-10-25): in the profile menu, for the
    company's admin only (admin login + permission company_logins_view). Every login of the company with its
    role, what the role can do (its permissions by group) and the password
    when it is still the standard one of its type; a changed password can't
    be read back (stored hashed) and shows as "custom".
  -->
  <a-drawer :open="open" :width="1140" :title="tx('Company logins', 'Logins de la empresa')" @close="$emit('close')">
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
        <template v-else-if="column.key === 'role'">
          <a-popover v-if="record.role" :title="record.role" placement="left" trigger="click" overlay-class-name="logins-pop">
            <template #content>
              <div v-if="record.role_description" class="muted" style="margin-bottom: 6px">{{ record.role_description }}</div>
              <div v-for="g in accesses(record.role_id)" :key="g.group" class="acc">
                <b>{{ g.group }}:</b> {{ g.items.join(', ') }}
              </div>
              <div v-if="!accesses(record.role_id).length" class="muted">{{ tx('No permissions.', 'Sin permisos.') }}</div>
            </template>
            <a-tag color="blue" class="role-tag">{{ record.role }} <InfoCircleOutlined /></a-tag>
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
        </template>
        <template v-else-if="column.key === 'open'">
          <a-tooltip v-if="canOpen(record)" :title="tx('Work as this login (Back to admin in the header)', 'Entrar como este login (Volver a admin en el encabezado)')">
            <a-button size="small" :loading="opening === record.id" @click="openAs(record)"><template #icon><LoginOutlined /></template>{{ tx('Enter', 'Entrar') }}</a-button>
          </a-tooltip>
        </template>
      </template>
    </a-table>
  </a-drawer>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { ReloadOutlined, CopyOutlined, InfoCircleOutlined, LoginOutlined } from '@ant-design/icons-vue';
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
// Roles of the logins of the chosen type, with how many each has.
const role = ref(undefined);
const roleOptions = computed(() => {
  const counts = {};
  logins.value.filter(l => l.role && (type.value === 'all' || l.type === type.value))
    .forEach(l => { counts[l.role] = (counts[l.role] || 0) + 1; });
  return sortByLabel(Object.entries(counts).map(([name, n]) => ({ value: name, label: `${name} (${n})` })));
});
watch(type, () => { if (role.value && !roleOptions.value.some(o => o.value === role.value)) role.value = undefined; });
const rows = computed(() => {
  const q = search.value.trim().toLowerCase();
  return logins.value.filter(l => (type.value === 'all' || l.type === type.value) && (!role.value || l.role === role.value)
    && (!q || [l.name, l.username, l.email, l.role].some(v => String(v || '').toLowerCase().includes(q))));
});
const columns = computed(() => [
  { title: tx('Login', 'Login'), key: 'name' },
  { title: tx('Type', 'Tipo'), key: 'type', width: 130 },
  { title: tx('Role and accesses', 'Rol y accesos'), key: 'role', width: 220 },
  // Hospital staff only: patients assigned today (roster + attending doctor).
  ...(logins.value.some(l => l.patients) ? [{ title: tx('Patients today', 'Pacientes hoy'), key: 'patients', width: 110 }] : []),
  { title: tx('Status', 'Estado'), key: 'active', width: 110 },
  { title: tx('Password', 'Contraseña'), key: 'password', width: 210 },
  { title: '', key: 'open', width: 100 },
]);

// "Enter" (switch + back): a login with the standard password, not my own.
const auth = useAuthStore();
const opening = ref(null);
const canOpen = r => r.password && r.active && r.id !== auth.user?.id && r.type !== 'Online_store';
// Same tab (user's rule, 2026-10-25): every tab shares the session, so the
// admin simply becomes that login here and returns with "Back to admin".
async function openAs(r, enablePortal = false) {
  opening.value = r.id;
  try {
    const res = await http.webPost(`login/as/${r.id}`, enablePortal ? { enable_portal: 1 } : {});
    if (r.type !== 'customer') {
      try { window.localStorage.removeItem('stocky_auth_cache_v1'); } catch (e) { /* storage unavailable */ }
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
</style>
