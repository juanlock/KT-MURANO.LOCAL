<template>
  <div class="page">
    <PageHeader :title="$t('Users')" :breadcrumb="[$t('User_Management'), $t('Users')]">
      <template #actions>
        <a-button v-if="auth.can('users_add')" type="primary" @click="$router.push('/users/create')">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <!-- Filters: type, role, status (search box is in the table toolbar). -->
    <a-card size="small" style="margin-bottom: 16px">
      <a-space wrap :size="16">
        <span class="uf">
          <span class="uf-label">{{ $t('type') }}</span>
          <a-select v-model:value="typeFilter" style="width: 160px" :options="typeOptions" @change="crud.reload()" />
        </span>
        <span class="uf">
          <span class="uf-label">{{ $t('RoleName') }}</span>
          <a-select
            v-model:value="roleFilter" style="width: 200px" :options="roleOptions"
            show-search option-filter-prop="label" @change="crud.reload()"
          />
        </span>
        <span class="uf">
          <span class="uf-label">{{ $t('Status') }}</span>
          <a-select v-model:value="statusFilter" style="width: 140px" :options="statusOptions" @change="crud.reload()" />
        </span>
        <a-button v-if="typeFilter || roleFilter || statusFilter" @click="clearFilters">{{ $t('Clear_Filters') }}</a-button>
      </a-space>
    </a-card>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'avatar'">
          <a-avatar :size="36" :src="avatarUrl(record)">
            {{ initials(record) }}
          </a-avatar>
        </template>
        <template v-else-if="column.key === 'type'">
          <a-tag :color="typeColor(record.type)">{{ typeLabel(record.type) }}</a-tag>
        </template>
        <template v-else-if="column.key === 'role_name'">
          <a-tag v-if="record.role_name" color="blue">{{ record.role_name }}</a-tag>
          <span v-else style="opacity: 0.45">—</span>
        </template>
        <template v-else-if="column.key === 'statut'">
          <!-- Activate/deactivate toggle, like the legacy switch. The logged-in
               user cannot deactivate their own account. -->
          <a-tooltip :title="isSelf(record) ? $t('tx_You_cannot_deactivate_your_own_account_c1542') : ''">
            <a-switch
              :checked="!!Number(record.statut)"
              :loading="switching === record.id"
              :disabled="isSelf(record)"
              @change="toggleStatus(record)"
            />
          </a-tooltip>
          <!-- Login security (2026-10-27): locked after 5 wrong passwords. -->
          <div v-if="isLocked(record)" class="locked">
            <a-tag color="red">{{ lockedText(record) }}</a-tag>
            <a v-if="auth.can('login_security')" @click="unlock(record)">{{ ui.locale === 'es' ? 'Desbloquear' : $t('tx_Unlock_7e712') }}</a>
          </div>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space>
            <a-tooltip :title="$t('Edit')">
              <a-button type="text" size="small" @click="$router.push(`/users/${record.id}/edit`)">
                <template #icon><EditOutlined style="color: #52c41a" /></template>
              </a-button>
            </a-tooltip>
            <!-- Legacy hides delete for the current user. -->
            <a-tooltip v-if="!isSelf(record)" :title="$t('Del')">
              <a-button type="text" size="small" danger @click="crud.remove(record)">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
import { t as tEs } from '../../i18n/index.js';
/**
 * Users list: GET users → { users, totalRows, roles, warehouses }.
 * Status toggle: PUT users_switch_activated/{id} { statut, id } (legacy
 * contract sends the NEW value). No bulk delete endpoint for users.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import { PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';
import { sortByLabel, withAllOption } from '../../lib/filterOptions';
import { useSystemContext } from '../../lib/systemContext';
import { useUiStore } from '../../stores/ui';
import dayjs from 'dayjs';

const { t } = useI18n();
const auth = useAuthStore();

// Filters ('' = all), sent as filter_type / filter_role / filter_status.
// ?role=ID&role_name=... (Group Permissions -> Users column) opens it filtered by that role.
const route = useRoute();
const linkedRole = route.query.role ? { value: String(route.query.role), label: String(route.query.role_name || `#${route.query.role}`) } : null;
const typeFilter = ref('');
const roleFilter = ref(linkedRole ? linkedRole.value : '');
const statusFilter = ref('');
const crud = useCrudTable('users', {
  rowsKey: 'users',
  params: () => ({ filter_type: typeFilter.value || '', filter_role: roleFilter.value || '', filter_status: statusFilter.value || '' }),
});
const switching = ref(null);
function clearFilters() {
  typeFilter.value = '';
  roleFilter.value = '';
  statusFilter.value = '';
  crud.reload();
}

// The logged-in user may not deactivate or delete their own account (legacy parity).
const isSelf = record => auth.user && Number(record.id) === Number(auth.user.id);

// The backend stores the avatar's full relative path (e.g.
// images/company_2/avatar/xxx.jpg), not a bare filename under a shared
// /images/avatar/ folder -- use it as-is; the antd Avatar falls back to
// initials when the image is missing or fails to load.
const avatarUrl = record => {
  if (!record.avatar) return undefined;
  return record.avatar.startsWith('/') ? record.avatar : `/${record.avatar}`;
};
const initials = record =>
  `${(record.firstname || '')[0] || ''}${(record.lastname || '')[0] || ''}`.toUpperCase()
  || (record.username || '?')[0].toUpperCase();

const TYPE_COLORS = { SuperAdmin: 'gold', admin: 'blue', employee: 'green', customer: 'purple', sales_agent: 'orange', supplier: 'cyan', Online_store: 'magenta' };
const TYPE_LABELS = { SuperAdmin: 'SuperAdmin', admin: 'Admin', employee: 'Employee', customer: 'Customer', sales_agent: 'Sales_Agent', supplier: 'Supplier', Online_store: 'Online_Store' };
const typeColor = type => TYPE_COLORS[type] || 'default';
const typeLabel = type => t(TYPE_LABELS[type] || type);

// Filter options: alphabetical, "All" last (lib/filterOptions).
const typeOptions = computed(() => withAllOption(sortByLabel(Object.keys(TYPE_LABELS).map(k => ({ value: k, label: typeLabel(k) })))));
const roleOptions = computed(() => {
  const list = (crud.payload.value?.roles || []).map(r => ({ value: String(r.id), label: r.name }));
  // a role opened from Group Permissions that isn't in this company's list (SuperAdmin, all companies)
  if (linkedRole && !list.some(o => o.value === linkedRole.value)) list.push(linkedRole);
  return withAllOption([
  ...sortByLabel(list),
  { value: 'none', label: tEs('tx_No_role_f2981', 'No role') },
  ]);
});
const statusOptions = computed(() => withAllOption([
  { value: '1', label: tEs('Actif', 'Active') },
  { value: '0', label: tEs('Disabled', 'Disabled') },
]));

const { isSystem } = useSystemContext();
const columns = computed(() => [
  { title: t('Image'), key: 'avatar', dataIndex: 'avatar', width: 70, align: 'center', exportValue: r => r.avatar || '' },
  { title: t('Firstname'), dataIndex: 'firstname', key: 'firstname', sorter: true },
  { title: t('lastname'), dataIndex: 'lastname', key: 'lastname', sorter: true },
  { title: t('username'), dataIndex: 'username', key: 'username', sorter: true },
  { title: t('Email'), dataIndex: 'email', key: 'email' },
  { title: t('Phone'), dataIndex: 'phone', key: 'phone' },
  { title: t('type'), key: 'type', dataIndex: 'type', exportValue: r => typeLabel(r.type) },
  { title: t('RoleName'), key: 'role_name', dataIndex: 'role_name', exportValue: r => r.role_name || '' },
  // superadmin/AdminSystem: every company's users -- whose is each one.
  ...(isSystem.value ? [{ title: t('Company'), key: 'company_name', dataIndex: 'company_name', exportValue: r => r.company_name || '' }] : []),
  { title: t('Status'), key: 'statut', dataIndex: 'statut', exportValue: r => (Number(r.statut) ? t('ActivateUser') : t('DisActivateUser')) },
  { title: t('Action'), key: 'actions', width: 110, align: 'center' },
]);

async function toggleStatus(record) {
  if (isSelf(record)) return;
  switching.value = record.id;
  const next = Number(record.statut) ? 0 : 1;
  try {
    await http.put(`users_switch_activated/${record.id}`, { statut: next, id: record.id });
    record.statut = next;
    message.success(next ? t('ActivateUser') : t('DisActivateUser'));
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    switching.value = null;
  }
}

// Locked logins (LoginSecurity): tag + Unlock (permission login_security).
const ui = useUiStore();
const isLocked = record => record.locked_until && dayjs(record.locked_until).isAfter(dayjs());
const lockedText = record => `${ui.locale === 'es' ? 'Bloqueado hasta' : 'Locked until'} ${dayjs(record.locked_until).format('HH:mm')}`;
async function unlock(record) {
  try {
    await http.post(`login_security/unlock/${record.id}`);
    record.locked_until = null;
    message.success(ui.locale === 'es' ? 'Desbloqueado' : 'Unlocked');
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  }
}

onMounted(crud.fetchRows);
</script>

<style scoped>
.locked { margin-top: 4px; display: flex; align-items: center; gap: 6px; font-size: 12px; }
.uf { display: inline-flex; align-items: center; gap: 8px; }
.uf-label { font-size: 13px; opacity: 0.75; }
</style>
