<template>
  <div class="page">
    <PageHeader
      :title="$t('Network_Security')"
      :subtitle="$t('tx_Staff_can_use_the_admin_panel_only_from_the_company_s_networ_78f45')"
      :breadcrumb="[$t('Settings'), $t('Network_Security')]"
    />

    <div v-if="loading" style="display: flex; justify-content: center; padding: 64px 0"><a-spin size="large" /></div>

    <template v-else>
      <!-- ---------------- company switch + networks ---------------- -->
      <a-card size="small" :title="$t('tx_Company_setting_3552e')" style="margin-bottom: 16px">
        <div class="ns-row">
          <div>
            <div class="ns-label">{{ $t('tx_Restrict_access_by_network_e1595') }}</div>
            <div class="ns-help">{{ $t('tx_On_restricted_users_must_be_on_one_of_the_networks_below_or__9184f') }}</div>
          </div>
          <a-switch v-model:checked="form.enabled" />
        </div>
        <div class="ns-row">
          <div>
            <div class="ns-label">{{ $t('tx_Users_follow_unless_set_one_by_one_below_52f45') }}</div>
            <div class="ns-help">{{ $t('tx_Restricted_is_the_safe_default_Anywhere_lets_everyone_in_exc_7e5aa') }}</div>
          </div>
          <a-radio-group v-model:value="form.default_restricted" button-style="solid">
            <a-radio-button :value="true">{{ $t('tx_Restricted_ecdee') }}</a-radio-button>
            <a-radio-button :value="false">{{ $t('tx_Anywhere_2fba3') }}</a-radio-button>
          </a-radio-group>
        </div>

        <div class="ns-label" style="margin: 14px 0 6px">{{ $t('tx_Company_networks_ed4c0') }}</div>
        <div class="ns-help" style="margin-bottom: 8px">{{ $t('tx_An_office_s_public_IP_e_g_203_0_113_10_or_a_range_e_g_203_0__265f2') }} <b>{{ data.current_ip }}</b>
          <a-button type="link" size="small" :disabled="hasCurrentIp" @click="addCurrentIp">{{ hasCurrentIp ? $t('tx_already_in_the_list_14bbf') : $t('tx_Add_it_75904') }}</a-button>
        </div>
        <div v-for="(row, i) in form.allowed" :key="i" class="ns-net">
          <a-input v-model:value="row.ip" :placeholder="$t('tx_IP_or_range_ad8cb')" class="ns-ip" />
          <a-input v-model:value="row.label" :placeholder="$t('tx_Name_e_g_Main_office_2a67b')" class="ns-name" />
          <a-button danger type="text" aria-label="Remove network" @click="form.allowed.splice(i, 1)"><template #icon><DeleteOutlined /></template></a-button>
        </div>
        <a-button size="small" @click="form.allowed.push({ ip: '', label: '' })"><template #icon><PlusOutlined /></template>{{ $t('tx_Add_network_a2df5') }}</a-button>

        <div style="margin-top: 16px">
          <a-button type="primary" :loading="saving" @click="save()">{{ $t('submit') }}</a-button>
        </div>
      </a-card>

      <!-- ---------------- computers ---------------- -->
      <a-card size="small" :title="$t('tx_Computers_45888')" style="margin-bottom: 16px">
        <div class="ns-help" style="margin-bottom: 8px">{{ $t('tx_A_restricted_user_who_signs_in_from_outside_the_company_s_ne_db583') }}</div>
        <a-table :data-source="data.devices" :columns="deviceColumns" row-key="id" size="small" :pagination="false" :scroll="{ x: 720 }">
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'status'">
              <a-tag :color="record.status === 'approved' ? 'green' : 'orange'">{{ record.status === 'approved' ? $t('tx_Approved_6f806') : $t('tx_Waiting_5706d') }}</a-tag>
            </template>
            <template v-else-if="column.key === 'last'">
              {{ record.last_used_at ? dayjs(record.last_used_at).format('YYYY-MM-DD HH:mm') : '-' }} <span class="ns-muted">{{ record.last_ip }}</span>
            </template>
            <template v-else-if="column.key === 'actions'">
              <a-space>
                <a-button v-if="record.status !== 'approved'" type="primary" size="small" @click="approve(record)">{{ $t('Approve') }}</a-button>
                <a-button danger size="small" @click="remove(record)">{{ record.status === 'approved' ? $t('Remove') : $t('tx_Refuse_dc717') }}</a-button>
              </a-space>
            </template>
          </template>
          <template #emptyText>{{ $t('tx_No_computers_yet_93760') }}</template>
        </a-table>
      </a-card>

      <!-- ---------------- people ---------------- -->
      <a-card size="small" :title="$t('People')">
        <div class="ns-help" style="margin-bottom: 8px">{{ $t('tx_Each_staff_login_the_company_setting_or_its_own_choice_Custo_4efd4') }}</div>
        <a-table :data-source="data.users" :columns="userColumns" row-key="id" size="small" :pagination="{ pageSize: 20 }" :scroll="{ x: 640 }">
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'name'">
              {{ record.name }} <a-tag v-if="!record.active">{{ $t('Disabled') }}</a-tag>
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
import { t as tEs } from '../../i18n/index.js';
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
  { value: 'restricted', label: tEs('tx_Restricted_ecdee', 'Restricted') },
  { value: 'anywhere', label: tEs('tx_Can_sign_in_from_anywhere_52685', 'Can sign in from anywhere') },
]);

const deviceColumns = [
  { title: tEs('Person', 'Person'), dataIndex: 'user_name', key: 'user' },
  { title: tEs('tx_Computer_18190', 'Computer'), dataIndex: 'name', key: 'name' },
  { title: tEs('tx_Asked_from_10b2d', 'Asked from'), dataIndex: 'requested_ip', key: 'ip' },
  { title: tEs('tx_Last_used_135da', 'Last used'), key: 'last' },
  { title: tEs('Serial_Status', 'Status'), key: 'status', width: 100 },
  { title: '', key: 'actions', width: 170 },
];
const userColumns = [
  { title: tEs('Person', 'Person'), key: 'name' },
  { title: tEs('type', 'Type'), dataIndex: 'type', key: 'type', width: 110 },
  { title: tEs('tx_Network_access_7a7bc', 'Network access'), key: 'access', width: 260 },
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
  form.value.allowed.push({ ip: data.value.current_ip, label: tEs('tx_Added_from_this_computer_d4613', 'Added from this computer') });
}

async function save(confirmLockout = false) {
  saving.value = true;
  try {
    const allowed = form.value.allowed.filter(r => r.ip.trim());
    apply(await http.put('network_security', { ...form.value, allowed, confirm_lockout: confirmLockout ? 1 : 0 }));
    message.success(tEs('tx_Network_security_saved_7ec5f', 'Network security saved.'));
  } catch (e) {
    if (e?.status === 409 && e?.data?.code === 'self_lockout') {
      Modal.confirm({
        title: tEs('tx_This_would_block_you_dbaae', 'This would block you'),
        icon: createVNode(ExclamationCircleOutlined),
        content: `${e.data.message} Add your current IP, or save anyway (you'll need another admin or the SuperAdmin to let you back in).`,
        okText: tEs('tx_Save_anyway_9c3da', 'Save anyway'),
        okType: 'danger',
        cancelText: tEs('Cancel', 'Cancel'),
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
