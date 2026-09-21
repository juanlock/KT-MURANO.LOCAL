<template>
  <div class="page">
    <!-- No PageHeader actions: the header is hidden when this page is
         embedded in System Settings — the logout action lives on the card. -->
    <PageHeader :title="$t('Login_Device_Management')" :breadcrumb="[$t('Settings'), $t('Login_Device_Management')]" />

    <a-card size="small" :title="$t('Login_Device_Management')" :body-style="{ padding: 0 }">
      <template #extra>
        <a-button danger :loading="revokingOthers" @click="logoutOthers">
          <template #icon><LogoutOutlined /></template>
          Logout other devices
        </a-button>
      </template>
      <a-table
        :columns="columns" :data-source="sessions" :loading="loading"
        :pagination="false" :row-key="r => r.token_id" size="middle" :scroll="{ x: 'max-content' }"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'device'">
            {{ record.device }}
            <a-tag v-if="record.is_current" color="success" style="margin-left: 6px">Current</a-tag>
          </template>
          <template v-else-if="column.key === 'actions'">
            <a-button
              v-if="!record.is_current"
              type="text" size="small" danger
              @click="revoke(record)"
            >
              <template #icon><DeleteOutlined /></template>
            </a-button>
          </template>
        </template>
        <template #emptyText>
          <a-empty :description="$t('NodataAvailable')" style="padding: 32px 0" />
        </template>
      </a-table>
    </a-card>
  </div>
</template>

<script setup>
/**
 * GET security/sessions → {sessions (token_id, user_name, device, ip_address,
 * login_at, last_activity_at, is_current)}. Revoke one =
 * DELETE security/sessions/{token_id}; revoke all others =
 * POST security/sessions/logout-other → {revoked}. Column headers are literal
 * English in legacy too.
 */
import { ref, computed, createVNode, onMounted } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { DeleteOutlined, LogoutOutlined, ExclamationCircleOutlined } from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

// API sends raw ISO timestamps (2026-07-27T16:02:03.000000Z) — render them in
// the admin-configured date format + time instead.
const DATE_FMT = (() => {
  try { return localStorage.getItem('app_date_format') || 'YYYY-MM-DD'; } catch (e) { return 'YYYY-MM-DD'; }
})();
const fmtDateTime = v => (v ? dayjs(v).format(`${DATE_FMT} HH:mm`) : '');

const loading = ref(true);
const revokingOthers = ref(false);
const sessions = ref([]);

const columns = computed(() => [
  { title: 'User', dataIndex: 'user_name', key: 'user_name' },
  { title: 'Device / Browser', dataIndex: 'device', key: 'device' },
  { title: 'IP Address', dataIndex: 'ip_address', key: 'ip_address' },
  { title: 'Login', dataIndex: 'login_at', key: 'login_at', customRender: ({ text }) => fmtDateTime(text) },
  { title: 'Last activity', dataIndex: 'last_activity_at', key: 'last_activity_at', customRender: ({ text }) => fmtDateTime(text) },
  { title: t('Action'), key: 'actions', width: 70, align: 'center' },
]);

async function load() {
  loading.value = true;
  try {
    const data = await http.get('security/sessions');
    sessions.value = data.sessions || [];
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    loading.value = false;
  }
}

function revoke(record) {
  Modal.confirm({
    title: t('Delete_Title'),
    icon: createVNode(ExclamationCircleOutlined),
    content: `${record.device} — ${record.ip_address || ''}`,
    okType: 'danger',
    okText: t('Delete_confirmButtonText'),
    cancelText: t('Delete_cancelButtonText'),
    async onOk() {
      try {
        await http.delete(`security/sessions/${encodeURIComponent(record.token_id)}`);
        message.success(t('Success'));
        load();
      } catch (e) {
        message.error(e?.data?.message || t('InvalidData'));
      }
    },
  });
}

async function logoutOthers() {
  revokingOthers.value = true;
  try {
    const data = await http.post('security/sessions/logout-other');
    message.success(data?.revoked !== undefined ? `${t('Success')} (${data.revoked})` : t('Success'));
    load();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    revokingOthers.value = false;
  }
}

onMounted(load);
</script>
