<template>
  <div class="page">
    <PageHeader :title="$t('E_Wallet_Dashboard')" :breadcrumb="[$t('E_Wallet'), $t('E_Wallet_Dashboard')]" />

    <a-alert
      v-if="!isLoading && !walletMethodExists"
      type="warning" show-icon style="margin-bottom: 16px"
      :message="$t('Wallet_Payment_Method_Missing')"
    >
      <template #description>
        {{ $t('Wallet_Payment_Method_Missing_Help') }}
        <router-link to="/settings/payment-methods">{{ $t('Payment_Methods') }}</router-link>
      </template>
    </a-alert>

    <div v-if="isLoading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-tabs v-else v-model:activeKey="activeTab" @change="onTabChange">
      <!-- ===================== OVERVIEW ===================== -->
      <a-tab-pane key="overview" :tab="$t('Overview')">
        <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
          <a-col :xs="12" :md="6">
            <a-card size="small"><a-statistic :title="$t('Total_Wallets')" :value="stats.total_wallets" :value-style="{ color: '#8b5cf6' }" /></a-card>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-card size="small"><a-statistic :title="$t('Active_Wallets')" :value="stats.active_wallets" :value-style="{ color: '#10b981' }" /></a-card>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-card size="small"><a-statistic :title="$t('Total_Credits')" :value="money(stats.total_credits)" :value-style="{ color: '#0d9488' }" /></a-card>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-card size="small"><a-statistic :title="$t('Total_Debits')" :value="money(stats.total_debits)" :value-style="{ color: '#ef4444' }" /></a-card>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-card size="small"><a-statistic :title="$t('Pending_Withdrawals')" :value="stats.pending_withdrawals" :value-style="{ color: '#f59e0b' }" /></a-card>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-card size="small"><a-statistic :title="$t('Pending_Amount')" :value="money(stats.pending_amount)" :value-style="{ color: '#f97316' }" /></a-card>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-card size="small"><a-statistic :title="$t('Total_Withdrawn')" :value="money(stats.total_withdrawn)" :value-style="{ color: '#10b981' }" /></a-card>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-card size="small"><a-statistic :title="$t('Total_Balance_In_Circulation')" :value="money(stats.in_circulation)" /></a-card>
          </a-col>
        </a-row>

        <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
          <a-col :xs="24" :lg="12">
            <a-card size="small" :title="$t('Top_Wallets_By_Balance')" :body-style="{ padding: 0 }" style="height: 100%">
              <a-table
                :columns="topWalletColumns" :data-source="topWallets"
                :pagination="false" size="small" row-key="id"
                :locale="{ emptyText: $t('No_data_available') }"
              >
                <template #bodyCell="{ column, record }">
                  <template v-if="column.key === 'balance'"><strong>{{ money(record.balance) }}</strong></template>
                </template>
              </a-table>
            </a-card>
          </a-col>
          <a-col :xs="24" :lg="12">
            <a-card size="small" :title="$t('Recent_Transactions')" style="height: 100%">
              <a-list :data-source="recent" size="small" :locale="{ emptyText: $t('No_data_available') }">
                <template #renderItem="{ item }">
                  <a-list-item>
                    <a-list-item-meta>
                      <template #title>{{ item.client_name || '—' }}</template>
                      <template #description>{{ sourceLabel(item.source) }} · {{ item.created_at }}</template>
                    </a-list-item-meta>
                    <span :style="{ color: item.type === 'credit' ? '#3f8600' : '#cf1322', fontWeight: 600 }">
                      {{ item.type === 'credit' ? '+' : '-' }}{{ money(item.amount) }}
                    </span>
                  </a-list-item>
                </template>
              </a-list>
            </a-card>
          </a-col>
        </a-row>

        <a-card size="small" :title="$t('Pending_Withdrawal_Requests')" :body-style="{ padding: 0 }">
          <a-table
            :columns="pendingColumns" :data-source="pendingRequests"
            :pagination="false" size="small" row-key="id" :scroll="{ x: 'max-content' }"
            :locale="{ emptyText: $t('No_data_available') }"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'amount'"><strong>{{ money(record.amount) }}</strong></template>
              <template v-else-if="column.key === 'method'">
                {{ record.method || '—' }}
                <div v-if="record.destination" style="font-size: 12px; color: #999">{{ record.destination }}</div>
              </template>
              <template v-else-if="column.key === 'actions'">
                <a-space>
                  <a-button size="small" type="primary" @click="approveWithdrawal(record, false)">{{ $t('Approved') }}</a-button>
                  <a-button size="small" danger @click="rejectWithdrawal(record, false)">{{ $t('Rejected') }}</a-button>
                </a-space>
              </template>
            </template>
          </a-table>
        </a-card>
      </a-tab-pane>

      <!-- ===================== WALLETS ===================== -->
      <a-tab-pane key="wallets" :tab="$t('Wallets')">
        <a-card size="small" :body-style="{ padding: 0 }">
          <div style="padding: 16px; border-bottom: 1px solid rgba(5, 5, 5, 0.06)">
            <a-input-search
              v-model:value="walletSearch" :placeholder="$t('Search_Customer')"
              allow-clear style="max-width: 260px" @search="loadWallets" @change="loadWallets"
            />
          </div>
          <a-table
            :columns="walletColumns" :data-source="wallets"
            :pagination="false" size="middle" row-key="id" :scroll="{ x: 'max-content' }"
            :locale="{ emptyText: $t('No_data_available') }"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'client'">
                {{ record.client_name }}
                <div v-if="record.client_email" style="font-size: 12px; color: #999">{{ record.client_email }}</div>
              </template>
              <template v-else-if="column.key === 'balance'"><strong>{{ money(record.balance) }}</strong></template>
              <template v-else-if="column.key === 'status'">
                <a-switch :checked="record.status === 'active'" @change="toggleWalletStatus(record)" />
                <span :style="{ marginLeft: '8px', color: record.status === 'active' ? '#3f8600' : '#999' }">
                  {{ record.status === 'active' ? $t('Active') : $t('Frozen') }}
                </span>
              </template>
              <template v-else-if="column.key === 'actions'">
                <a-button size="small" @click="openAdjust(record)">{{ $t('Adjust_Balance') }}</a-button>
              </template>
            </template>
          </a-table>
        </a-card>
      </a-tab-pane>

      <!-- ===================== WITHDRAWALS ===================== -->
      <a-tab-pane key="withdrawals" :tab="$t('Withdrawals')">
        <a-card size="small" :body-style="{ padding: 0 }">
          <div style="padding: 16px; border-bottom: 1px solid rgba(5, 5, 5, 0.06)">
            <a-select v-model:value="wdStatus" style="width: 200px" @change="loadWithdrawals">
              <a-select-option value="">{{ $t('All') }}</a-select-option>
              <a-select-option value="pending">{{ $t('Pending') }}</a-select-option>
              <a-select-option value="approved">{{ $t('Approved') }}</a-select-option>
              <a-select-option value="paid">{{ $t('Paid') }}</a-select-option>
              <a-select-option value="rejected">{{ $t('Rejected') }}</a-select-option>
            </a-select>
          </div>
          <a-table
            :columns="withdrawalColumns" :data-source="withdrawals"
            :pagination="false" size="middle" row-key="id" :scroll="{ x: 'max-content' }"
            :locale="{ emptyText: $t('No_data_available') }"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'client'">
                {{ record.client_name || '—' }}
                <div v-if="record.client_email" style="font-size: 12px; color: #999">{{ record.client_email }}</div>
              </template>
              <template v-else-if="column.key === 'amount'"><strong>{{ money(record.amount) }}</strong></template>
              <template v-else-if="column.key === 'method'">
                {{ record.method || '—' }}
                <div v-if="record.destination" style="font-size: 12px; color: #999">{{ record.destination }}</div>
              </template>
              <template v-else-if="column.key === 'status'">
                <a-tag :color="wdColor(record.status)">{{ wdLabel(record.status) }}</a-tag>
              </template>
              <template v-else-if="column.key === 'actions'">
                <a-space v-if="record.status === 'pending'">
                  <a-button size="small" type="primary" @click="approveWithdrawal(record, true)">{{ $t('Approved') }}</a-button>
                  <a-button size="small" danger @click="rejectWithdrawal(record, true)">{{ $t('Rejected') }}</a-button>
                </a-space>
                <a-button v-else-if="record.status === 'approved'" size="small" @click="markPaid(record)">{{ $t('Mark_Paid') }}</a-button>
                <span v-else style="color: #999">—</span>
              </template>
            </template>
          </a-table>
        </a-card>
      </a-tab-pane>
    </a-tabs>

    <!-- Adjust balance modal -->
    <a-modal v-model:open="adjustOpen" :title="$t('Adjust_Balance')" :confirm-loading="adjustSaving" @ok="submitAdjust">
      <div v-if="adjust.wallet" style="margin: 12px 0">
        <strong>{{ adjust.wallet.client_name }}</strong>
        <div style="font-size: 12px; color: #999">{{ $t('Balance') }}: {{ money(adjust.wallet.balance) }}</div>
      </div>
      <a-form layout="vertical">
        <a-form-item :label="$t('Type')">
          <a-select v-model:value="adjust.type">
            <a-select-option value="credit">{{ $t('Credit_Add') }}</a-select-option>
            <a-select-option value="debit">{{ $t('Debit_Deduct') }}</a-select-option>
          </a-select>
        </a-form-item>
        <a-form-item :label="$t('Amount') + ' *'">
          <a-input-number v-model:value="adjust.amount" :min="0.01" :step="0.01" style="width: 100%" />
        </a-form-item>
        <a-form-item :label="$t('Note')">
          <a-input v-model:value="adjust.note" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * E-Wallet dashboard — 3 tabs mirroring legacy store/Wallet.vue:
 *  - overview: GET store/wallet/dashboard → {currency, stats, top_wallets,
 *    recent_transactions, pending_requests}
 *  - wallets: GET store/wallets?search&per_page=15 → {data}; adjust POST
 *    store/wallets/{id}/adjust {type, amount, note|null}; freeze/unfreeze
 *    POST store/wallets/{id}/status {status: active|frozen}
 *  - withdrawals: GET store/wallet-withdrawals?status&per_page=20 → {data};
 *    POST store/wallet-withdrawals/{id}/approve|reject|paid
 */
import { ref, computed, onMounted } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

const isLoading = ref(true);
const activeTab = ref('overview');
const currency = ref('');
// POS spends wallets through the payment method named "Wallet" — warn when
// it is missing (e.g. renamed back after the id-8 collision).
const walletMethodExists = ref(true);
const stats = ref({
  total_wallets: 0, active_wallets: 0, in_circulation: 0, total_credits: 0,
  total_debits: 0, pending_withdrawals: 0, pending_amount: 0, total_withdrawn: 0,
});
const topWallets = ref([]);
const recent = ref([]);
const pendingRequests = ref([]);
const wallets = ref([]);
const walletSearch = ref('');
const withdrawals = ref([]);
const wdStatus = ref('');
const adjustOpen = ref(false);
const adjustSaving = ref(false);
const adjust = ref({ wallet: null, type: 'credit', amount: null, note: '' });

const topWalletColumns = computed(() => [
  { title: t('Customer'), dataIndex: 'client_name', key: 'client_name' },
  { title: t('Balance'), key: 'balance', align: 'right' },
]);
const pendingColumns = computed(() => [
  { title: t('Customer'), dataIndex: 'client_name', key: 'client_name' },
  { title: t('Amount'), key: 'amount', align: 'right' },
  { title: t('Method'), key: 'method' },
  { title: t('date'), dataIndex: 'created_at', key: 'created_at' },
  { title: t('Actions'), key: 'actions', width: 190 },
]);
const walletColumns = computed(() => [
  { title: t('Customer'), key: 'client' },
  { title: t('Balance'), key: 'balance', align: 'right' },
  { title: t('Status'), key: 'status', width: 160 },
  { title: t('Actions'), key: 'actions', width: 140 },
]);
const withdrawalColumns = computed(() => [
  { title: t('Customer'), key: 'client' },
  { title: t('Amount'), key: 'amount', align: 'right' },
  { title: t('Method'), key: 'method' },
  { title: t('Status'), key: 'status', width: 100 },
  { title: t('date'), dataIndex: 'created_at', key: 'created_at' },
  { title: t('Actions'), key: 'actions', width: 190 },
]);

function money(v) {
  return (currency.value ? currency.value + ' ' : '') + Number(v || 0).toFixed(2);
}
function sourceLabel(s) {
  return {
    checkout: t('Checkout'), pos_sale: t('POS_Sale'), refund: t('Refund'),
    withdrawal: t('Withdrawal'), adjustment: t('Adjustment'), gift_card: t('Gift_Card'),
  }[s] || s;
}
function wdLabel(s) {
  return { pending: t('Pending'), approved: t('Approved'), paid: t('Paid'), rejected: t('Rejected') }[s] || s;
}
function wdColor(s) {
  return { pending: 'warning', approved: 'processing', paid: 'success', rejected: 'error' }[s] || 'default';
}

async function loadDashboard() {
  try {
    const r = await http.get('store/wallet/dashboard');
    walletMethodExists.value = r.wallet_payment_method_exists !== false;
    currency.value = r.currency || '';
    stats.value = r.stats || stats.value;
    topWallets.value = r.top_wallets || [];
    recent.value = r.recent_transactions || [];
    pendingRequests.value = r.pending_requests || [];
  } catch (e) {
    message.error(t('Failed'));
  } finally {
    isLoading.value = false;
  }
}
async function loadWallets() {
  try {
    const r = await http.get('store/wallets', { search: walletSearch.value, per_page: 15 });
    wallets.value = r.data || [];
  } catch (e) {
    message.error(t('Failed'));
  }
}
async function loadWithdrawals() {
  try {
    const r = await http.get('store/wallet-withdrawals', { status: wdStatus.value, per_page: 20 });
    withdrawals.value = r.data || [];
  } catch (e) {
    message.error(t('Failed'));
  }
}
function onTabChange(key) {
  if (key === 'wallets') loadWallets();
  else if (key === 'withdrawals') loadWithdrawals();
}

function openAdjust(w) {
  adjust.value = { wallet: w, type: 'credit', amount: null, note: '' };
  adjustOpen.value = true;
}
async function submitAdjust() {
  if (!adjust.value.wallet || !adjust.value.amount) return;
  adjustSaving.value = true;
  try {
    await http.post(`store/wallets/${adjust.value.wallet.id}/adjust`, {
      type: adjust.value.type,
      amount: adjust.value.amount,
      note: adjust.value.note || null,
    });
    message.success(t('Successfully_Updated'));
    adjustOpen.value = false;
    loadWallets();
    loadDashboard();
  } catch (e) {
    message.error(e?.data?.error || t('Failed'));
  } finally {
    adjustSaving.value = false;
  }
}
async function toggleWalletStatus(w) {
  const next = w.status === 'active' ? 'frozen' : 'active';
  try {
    await http.post(`store/wallets/${w.id}/status`, { status: next });
    w.status = next;
  } catch (e) {
    message.error(t('Failed'));
  }
}

function approveWithdrawal(w, fromTab) {
  Modal.confirm({
    title: t('Confirm_Approve_Withdrawal'),
    okText: t('Yes'),
    cancelText: t('No'),
    async onOk() {
      try {
        await http.post(`store/wallet-withdrawals/${w.id}/approve`);
        message.success(t('Successfully_Updated'));
        loadDashboard();
        if (fromTab) loadWithdrawals();
      } catch (e) {
        message.error(e?.data?.error || t('Failed'));
      }
    },
  });
}
function rejectWithdrawal(w, fromTab) {
  Modal.confirm({
    title: t('AreYouSure'),
    okText: t('Yes'),
    okType: 'danger',
    cancelText: t('No'),
    async onOk() {
      try {
        await http.post(`store/wallet-withdrawals/${w.id}/reject`);
        message.success(t('Successfully_Updated'));
        loadDashboard();
        if (fromTab) loadWithdrawals();
      } catch (e) {
        message.error(t('Failed'));
      }
    },
  });
}
async function markPaid(w) {
  try {
    await http.post(`store/wallet-withdrawals/${w.id}/paid`);
    message.success(t('Successfully_Updated'));
    loadWithdrawals();
    loadDashboard();
  } catch (e) {
    message.error(t('Failed'));
  }
}

onMounted(loadDashboard);
</script>
