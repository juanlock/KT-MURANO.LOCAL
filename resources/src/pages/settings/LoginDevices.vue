<template>
  <div class="page">
    <!-- No PageHeader actions: the header is hidden when this page is
         embedded in System Settings — the logout action lives on the card. -->
    <PageHeader :title="$t('Login_Device_Management')" :breadcrumb="[$t('Settings'), $t('Login_Device_Management')]" />

    <!-- Date range: its own row (login date). -->
    <a-card size="small" style="margin-bottom: 16px">
      <PresetRangeFilter v-model:value="filters.range" value-format="YYYY-MM-DD" @change="crud.reload" />
    </a-card>

    <DataTable :crud="crud" :columns="columns" row-key="token_id">
      <!-- Company / role / type, beside the search box. -->
      <template #filters>
        <a-select v-model:value="filters.company" class="tb-item" allow-clear show-search option-filter-prop="label"
          :placeholder="tx('All companies', 'Todas las empresas')" :options="companyOptions" @change="crud.reload" />
        <a-select v-model:value="filters.role" class="tb-item" allow-clear show-search option-filter-prop="label"
          :placeholder="tx('All roles', 'Todos los roles')" :options="opt('roles')" @change="crud.reload" />
        <a-select v-model:value="filters.type" class="tb-item" allow-clear
          :placeholder="tx('All user types', 'Todos los tipos')" :options="opt('types')" @change="crud.reload" />
      </template>
      <template #toolbar>
        <a-button danger :loading="revokingOthers" @click="logoutOthers">
          <template #icon><LogoutOutlined /></template>
          {{ tx('Logout my other devices', 'Cerrar mis otras sesiones') }}
        </a-button>
      </template>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'user_name'">
          {{ record.user_name || '—' }}
          <div v-if="record.email" class="muted small">{{ record.email }}</div>
        </template>
        <template v-else-if="column.key === 'company_name'">
          {{ record.company_name || '—' }}
        </template>
        <template v-else-if="column.key === 'role'">
          {{ record.role || '—' }}
          <div v-if="record.user_type" class="muted small">{{ record.user_type }}</div>
        </template>
        <template v-else-if="column.key === 'device'">
          {{ record.device }}
          <a-tag v-if="record.is_current" color="success" style="margin-left: 6px">{{ tx('Current', 'Actual') }}</a-tag>
        </template>
        <template v-else-if="column.key === 'login_at' || column.key === 'last_activity_at'">
          {{ record[column.key] ? dateTime(record[column.key]) : '—' }}
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-tooltip v-if="!record.is_current" :title="tx('Sign this device out', 'Cerrar esta sesión')">
            <a-button type="text" size="small" danger @click="revoke(record)">
              <template #icon><DeleteOutlined /></template>
            </a-button>
          </a-tooltip>
        </template>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
/**
 * GET security/sessions -> {sessions (token_id, user_name, email, company_name, role,
 * user_type, device, ip_address, login_at, last_activity_at, is_current), totalRows,
 * options} with our standard list filters (2026-10-27): dates (login), company, role,
 * user type, search, sort, pages -- restored on Back (useListState). Revoke one =
 * DELETE security/sessions/{token_id}; revoke my others = POST security/sessions/logout-other.
 */
import { ref, computed, createVNode, onMounted } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { DeleteOutlined, LogoutOutlined, ExclamationCircleOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import PresetRangeFilter from '../../components/PresetRangeFilter.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useListState } from '../../composables/useListState';
import { useFormat } from '../../composables/useFormat';
import { sortByLabel } from '../../lib/filterOptions';
import { useUiStore } from '../../stores/ui';
import http from '../../lib/http';

const { t } = useI18n();
const ui = useUiStore();
const tx = (en, es) => (ui.locale === 'es' ? es : en);
const { dateTime } = useFormat();

const filters = ref({ range: null, company: undefined, role: undefined, type: undefined });
const crud = useCrudTable('security/sessions', {
  rowsKey: 'sessions',
  limit: 25,
  params: () => ({
    from: filters.value.range?.[0] || '',
    to: filters.value.range?.[1] || '',
    company: filters.value.company || '',
    role: filters.value.role || '',
    type: filters.value.type || '',
  }),
});
// Back from elsewhere = the same filters, page and search (Frontend Conventions).
const listState = useListState('login-devices', { filters }, crud);

const opt = key => sortByLabel((crud.payload.value?.options?.[key] || []).map(v => ({ value: v, label: v })));
const companyOptions = computed(() => sortByLabel(crud.payload.value?.options?.companies || []));

const columns = computed(() => [
  { title: tx('User', 'Usuario'), key: 'user_name', dataIndex: 'user_name', sorter: true },
  { title: tx('Company', 'Empresa'), key: 'company_name', dataIndex: 'company_name', sorter: true },
  { title: tx('Role', 'Rol'), key: 'role', dataIndex: 'role', sorter: true },
  { title: tx('Device / Browser', 'Equipo / navegador'), key: 'device', dataIndex: 'device', sorter: true },
  { title: tx('IP address', 'Dirección IP'), key: 'ip_address', dataIndex: 'ip_address', sorter: true },
  { title: tx('Login', 'Ingreso'), key: 'login_at', dataIndex: 'login_at', sorter: true },
  { title: tx('Last activity', 'Última actividad'), key: 'last_activity_at', dataIndex: 'last_activity_at', sorter: true },
  { title: t('Action'), key: 'actions', width: 70, align: 'center' },
]);

function revoke(record) {
  Modal.confirm({
    title: tx('Sign this device out?', '¿Cerrar esta sesión?'),
    icon: createVNode(ExclamationCircleOutlined),
    content: `${record.user_name || ''} — ${record.device} — ${record.ip_address || ''}`,
    okType: 'danger',
    okText: tx('Sign out', 'Cerrar sesión'),
    cancelText: t('Delete_cancelButtonText'),
    async onOk() {
      try {
        await http.delete(`security/sessions/${encodeURIComponent(record.token_id)}`);
        message.success(t('Success'));
        crud.fetchRows();
      } catch (e) {
        message.error(e?.data?.message || t('InvalidData'));
      }
    },
  });
}

const revokingOthers = ref(false);
async function logoutOthers() {
  revokingOthers.value = true;
  try {
    const data = await http.post('security/sessions/logout-other');
    message.success(data?.revoked !== undefined ? `${t('Success')} (${data.revoked})` : t('Success'));
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    revokingOthers.value = false;
  }
}

onMounted(() => {
  // Restored (Back) = its own filters; otherwise PresetRangeFilter applies the user's
  // default preset and its @change loads. First load in any case:
  if (listState.restored || !filters.value.range) crud.fetchRows();
});
</script>

<style scoped>
.tb-item { width: 180px; }
.muted { opacity: 0.6; }
.small { font-size: 12px; }
</style>
