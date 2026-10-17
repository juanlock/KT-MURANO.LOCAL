<template>
  <div class="page">
    <PageHeader
      :title="$t('Network_Security')"
      subtitle="Staff can use the admin panel only from the company's networks or from computers you approved. The store, websites, portfolio, online booking and the portals are never affected."
      :breadcrumb="[$t('Settings'), $t('Network_Security')]"
    />

    <div v-if="loading" style="display: flex; justify-content: center; padding: 64px 0"><a-spin size="large" /></div>

    <template v-else>
      <!-- ---------------- company switch + networks ---------------- -->
      <a-card size="small" title="Company setting" style="margin-bottom: 16px">
        <div class="ns-row">
          <div>
            <div class="ns-label">Restrict access by network</div>
            <div class="ns-help">On: restricted users must be on one of the networks below, or on a computer approved here. The SuperAdmin is never blocked.</div>
          </div>
          <a-switch v-model:checked="form.enabled" />
        </div>
        <div class="ns-row">
          <div>
            <div class="ns-label">Users follow, unless set one by one below</div>
            <div class="ns-help">"Restricted" is the safe default; "Anywhere" lets everyone in except the users you mark as restricted.</div>
          </div>
          <a-radio-group v-model:value="form.default_restricted" button-style="solid">
            <a-radio-button :value="true">Restricted</a-radio-button>
            <a-radio-button :value="false">Anywhere</a-radio-button>
          </a-radio-group>
        </div>

        <div class="ns-label" style="margin: 14px 0 6px">Company networks</div>
        <div class="ns-help" style="margin-bottom: 8px">An office's public IP (e.g. 203.0.113.10) or a range (e.g. 203.0.113.0/24). Your current IP: <b>{{ data.current_ip }}</b>
          <a-button type="link" size="small" :disabled="hasCurrentIp" @click="addCurrentIp">{{ hasCurrentIp ? 'already in the list' : 'Add it' }}</a-button>
        </div>
        <div v-for="(row, i) in form.allowed" :key="i" class="ns-net">
          <a-input v-model:value="row.ip" placeholder="IP or range" class="ns-ip" />
          <a-input v-model:value="row.label" placeholder="Name (e.g. Main office)" class="ns-name" />
          <a-button danger type="text" aria-label="Remove network" @click="form.allowed.splice(i, 1)"><template #icon><DeleteOutlined /></template></a-button>
        </div>
        <a-button size="small" @click="form.allowed.push({ ip: '', label: '' })"><template #icon><PlusOutlined /></template>Add network</a-button>

        <div style="margin-top: 16px">
          <a-button type="primary" :loading="saving" @click="save()">{{ $t('submit') }}</a-button>
        </div>
      </a-card>

      <!-- ---------------- computers ---------------- -->
      <a-card size="small" title="Computers" style="margin-bottom: 16px">
        <div class="ns-help" style="margin-bottom: 8px">A restricted user who signs in from outside the company's networks sends that computer here. Approve it once and it works from anywhere until you remove it (lost laptop, someone who left). Clearing the browser's data needs a new approval.</div>
        <a-table :data-source="data.devices" :columns="deviceColumns" row-key="id" size="small" :pagination="false" :scroll="{ x: 720 }">
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'status'">
              <a-tag :color="record.status === 'approved' ? 'green' : 'orange'">{{ record.status === 'approved' ? 'Approved' : 'Waiting' }}</a-tag>
            </template>
            <template v-else-if="column.key === 'last'">
              {{ record.last_used_at ? dayjs(record.last_used_at).format('YYYY-MM-DD HH:mm') : '-' }} <span class="ns-muted">{{ record.last_ip }}</span>
            </template>
            <template v-else-if="column.key === 'actions'">
              <a-space>
                <a-button v-if="record.status !== 'approved'" type="primary" size="small" @click="approve(record)">Approve</a-button>
                <a-button danger size="small" @click="remove(record)">{{ record.status === 'approved' ? 'Remove' : 'Refuse' }}</a-button>
              </a-space>
            </template>
          </template>
          <template #emptyText>No computers yet.</template>
        </a-table>
      </a-card>

      <!-- ---------------- people ---------------- -->
      <a-card size="small" title="People">
        <div class="ns-help" style="margin-bottom: 8px">Each staff login: the company setting, or its own choice. Customers and suppliers never need this.</div>
        <a-table :data-source="data.users" :columns="userColumns" row-key="id" size="small" :pagination="{ pageSize: 20 }" :scroll="{ x: 640 }">
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'name'">
              {{ record.name }} <a-tag v-if="!record.active">Disabled</a-tag>
              <div class="ns-muted">{{ record.email }}</div>
            </template>
            <template v-else-if="column.key === 'access'">
              <!-- Fixed, meaningful order (not alphabetical): default first. -->
              <a-select :value="record.network_access || ''" style="width: 230px" :options="accessOptions" @change="v => setAccess(record, v)" />
            </template>
          </template>
        </a-table>
      </a-card>
    </template>
  </div>
</template>

<script setup>
/**
 * Settings -> Network security (2026-10-16), permission network_security, for
 * the acting company. API: network_security (NetworkSecurityController);
 * enforcement: App\Support\NetworkAccess (sign-in + every staff request).
 */
import { ref, computed, onMounted, createVNode } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { DeleteOutlined, PlusOutlined, ExclamationCircleOutlined } from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const loading = ref(true);
const saving = ref(false);
const data = ref({ users: [], devices: [], allowed: [], current_ip: '' });
const form = ref({ enabled: false, default_restricted: true, allowed: [] });

const hasCurrentIp = computed(() => form.value.allowed.some(r => r.ip.trim() === data.value.current_ip));
const accessOptions = computed(() => [
  { value: '', label: `Company setting (${form.value.default_restricted ? 'restricted' : 'anywhere'})` },
  { value: 'restricted', label: 'Restricted' },
  { value: 'anywhere', label: 'Can sign in from anywhere' },
]);

const deviceColumns = [
  { title: 'Person', dataIndex: 'user_name', key: 'user' },
  { title: 'Computer', dataIndex: 'name', key: 'name' },
  { title: 'Asked from', dataIndex: 'requested_ip', key: 'ip' },
  { title: 'Last used', key: 'last' },
  { title: 'Status', key: 'status', width: 100 },
  { title: '', key: 'actions', width: 170 },
];
const userColumns = [
  { title: 'Person', key: 'name' },
  { title: 'Type', dataIndex: 'type', key: 'type', width: 110 },
  { title: 'Network access', key: 'access', width: 260 },
];

function apply(d) {
  data.value = d;
  form.value = { enabled: !!d.enabled, default_restricted: !!d.default_restricted, allowed: (d.allowed || []).map(r => ({ ...r })) };
}

async function load() {
  loading.value = true;
  try {
    apply(await http.get('network_security'));
  } catch (e) {
    message.error(e?.data?.message || 'Could not load network security.');
  } finally {
    loading.value = false;
  }
}

function addCurrentIp() {
  form.value.allowed.push({ ip: data.value.current_ip, label: 'Added from this computer' });
}

async function save(confirmLockout = false) {
  saving.value = true;
  try {
    const allowed = form.value.allowed.filter(r => r.ip.trim());
    apply(await http.put('network_security', { ...form.value, allowed, confirm_lockout: confirmLockout ? 1 : 0 }));
    message.success('Network security saved.');
  } catch (e) {
    if (e?.status === 409 && e?.data?.code === 'self_lockout') {
      Modal.confirm({
        title: 'This would block you',
        icon: createVNode(ExclamationCircleOutlined),
        content: `${e.data.message} Add your current IP, or save anyway (you'll need another admin or the SuperAdmin to let you back in).`,
        okText: 'Save anyway',
        okType: 'danger',
        cancelText: 'Cancel',
        onOk: () => save(true),
      });
    } else {
      message.error(e?.data?.message || 'Could not save.');
    }
  } finally {
    saving.value = false;
  }
}

async function approve(record) {
  try {
    await http.post(`network_security/devices/${record.id}/approve`);
    message.success(`${record.user_name}'s computer approved.`);
    await load();
  } catch (e) {
    message.error(e?.data?.message || 'Could not approve.');
  }
}

function remove(record) {
  Modal.confirm({
    title: record.status === 'approved' ? 'Remove this computer?' : 'Refuse this computer?',
    icon: createVNode(ExclamationCircleOutlined),
    content: `${record.user_name} -- ${record.name}. Outside the company's networks this computer will be blocked (it can ask again).`,
    okText: record.status === 'approved' ? 'Remove' : 'Refuse',
    okType: 'danger',
    async onOk() {
      await http.delete(`network_security/devices/${record.id}`);
      await load();
    },
  });
}

async function setAccess(record, value) {
  try {
    await http.put(`network_security/users/${record.id}`, { network_access: value || null });
    record.network_access = value || null;
    message.success(`${record.name}: saved.`);
  } catch (e) {
    message.error(e?.data?.message || 'Could not save.');
  }
}

onMounted(load);
</script>

<style scoped>
.ns-row { display: flex; justify-content: space-between; align-items: center; gap: 16px; padding: 8px 0; border-bottom: 1px solid rgba(0, 0, 0, 0.05); flex-wrap: wrap; }
.ns-label { font-weight: 600; }
.ns-help { font-size: 12.5px; color: rgba(0, 0, 0, 0.55); max-width: 720px; }
.ns-muted { font-size: 12px; color: rgba(0, 0, 0, 0.45); }
.ns-net { display: flex; gap: 8px; margin-bottom: 8px; flex-wrap: wrap; }
.ns-ip { width: 220px; }
.ns-name { width: 260px; max-width: 100%; }
:global(.dark) .ns-help, :global(.dark) .ns-muted { color: rgba(255, 255, 255, 0.55); }
</style>
