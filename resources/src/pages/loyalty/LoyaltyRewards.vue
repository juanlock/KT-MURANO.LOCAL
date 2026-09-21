<template>
  <div class="page">
    <PageHeader :title="$t('Loyalty_Rewards')" :breadcrumb="[$t('Loyalty_Points'), $t('Loyalty_Rewards')]" />

    <a-card>
      <a-tabs v-model:activeKey="activeTab" @change="onTabChange">
        <!-- ===================== Rewards catalog ===================== -->
        <a-tab-pane key="rewards" :tab="$t('Loyalty_Items')">
          <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px">
            <a-input
              v-model:value="search" :placeholder="$t('Search')" allow-clear
              style="max-width: 220px" @change="fetchRewards"
            />
            <a-select
              v-model:value="typeFilter" style="width: 170px"
              :options="[
                { value: '', label: $t('All') },
                { value: 'gift_card', label: $t('Gift_Card') },
                { value: 'voucher', label: $t('Voucher') },
                { value: 'product', label: $t('Product') },
              ]"
              @change="fetchRewards"
            />
            <a-button type="primary" style="margin-left: auto" @click="openCreate">
              <template #icon><PlusOutlined /></template>
              {{ $t('Add') }}
            </a-button>
          </div>

          <a-table
            :columns="rewardColumns" :data-source="rewards" :loading="loadingRewards"
            size="middle" :pagination="false" row-key="id"
            :locale="{ emptyText: $t('No_Loyalty_Items') }"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'name'">
                <strong>{{ record.name }}</strong>
                <div v-if="record.description" style="font-size: 12px; color: #8c8c8c">{{ record.description }}</div>
              </template>
              <template v-else-if="column.key === 'type'">
                <a-tag>{{ typeLabel(record.type) }}</a-tag>
              </template>
              <template v-else-if="column.key === 'points_cost'">{{ num(record.points_cost) }}</template>
              <template v-else-if="column.key === 'value'">{{ record.type === 'product' ? '—' : num(record.value) }}</template>
              <template v-else-if="column.key === 'stock'">{{ record.stock === null ? $t('Unlimited') : record.stock }}</template>
              <template v-else-if="column.key === 'active'">
                <a-switch :checked="!!record.active" size="small" @change="toggle(record)" />
                <span :style="{ marginLeft: '6px', color: record.active ? '#52c41a' : '#8c8c8c' }">
                  {{ record.active ? $t('Active') : $t('Disabled') }}
                </span>
              </template>
              <template v-else-if="column.key === 'actions'">
                <a-space>
                  <a-button size="small" @click="openEdit(record)"><EditOutlined /></a-button>
                  <a-popconfirm :title="$t('AreYouSure')" :ok-text="$t('Yes')" :cancel-text="$t('No')" @confirm="remove(record)">
                    <a-button size="small" danger><DeleteOutlined /></a-button>
                  </a-popconfirm>
                </a-space>
              </template>
            </template>
          </a-table>
        </a-tab-pane>

        <!-- ===================== Redemptions ===================== -->
        <a-tab-pane key="redemptions" :tab="$t('Redemptions')">
          <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px">
            <a-input
              v-model:value="redSearch" :placeholder="$t('Search')" allow-clear
              style="max-width: 220px" @change="fetchRedemptions"
            />
            <a-select
              v-model:value="redStatus" style="width: 170px"
              :options="[
                { value: '', label: $t('All') },
                { value: 'issued', label: $t('Issued') },
                { value: 'fulfilled', label: $t('Fulfilled') },
                { value: 'cancelled', label: $t('Cancelled') },
              ]"
              @change="fetchRedemptions"
            />
          </div>

          <a-table
            :columns="redemptionColumns" :data-source="redemptions" :loading="loadingRedemptions"
            size="middle" :pagination="false" row-key="id"
            :locale="{ emptyText: $t('No_data_available') }"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'client_name'">{{ record.client_name || '—' }}</template>
              <template v-else-if="column.key === 'reward_type'">
                <a-tag>{{ typeLabel(record.reward_type) }}</a-tag>
              </template>
              <template v-else-if="column.key === 'points_spent'">{{ num(record.points_spent) }}</template>
              <template v-else-if="column.key === 'code'">
                <a-typography-text v-if="record.code" code>{{ record.code }}</a-typography-text>
                <span v-else>—</span>
              </template>
              <template v-else-if="column.key === 'status'">
                <a-tag :color="statusColor(record.status)">{{ statusLabel(record.status) }}</a-tag>
              </template>
              <template v-else-if="column.key === 'actions'">
                <a-space v-if="record.status === 'issued'">
                  <a-button size="small" type="primary" @click="fulfill(record)">{{ $t('Fulfill') }}</a-button>
                  <a-popconfirm :title="$t('AreYouSure')" :ok-text="$t('Yes')" :cancel-text="$t('No')" @confirm="cancelRedemption(record)">
                    <a-button size="small" danger>{{ $t('Cancel') }}</a-button>
                  </a-popconfirm>
                </a-space>
                <span v-else style="color: #8c8c8c">—</span>
              </template>
            </template>
          </a-table>
        </a-tab-pane>

        <!-- ===================== Adjust points ===================== -->
        <a-tab-pane key="adjust" :tab="$t('Adjust_Points')">
          <div style="max-width: 560px">
            <a-form-item :label="$t('Search_Customer')" layout="vertical">
              <a-input v-model:value="clientSearch" :placeholder="$t('Search_Customer')" @change="searchClients" />
            </a-form-item>
            <a-list
              v-if="clients.length" size="small" bordered
              :data-source="clients" style="margin-bottom: 16px; max-height: 220px; overflow: auto"
            >
              <template #renderItem="{ item }">
                <a-list-item
                  style="cursor: pointer"
                  :style="adjust.client && adjust.client.id === item.id ? { background: 'rgba(109, 40, 217, 0.08)' } : {}"
                  @click="adjust.client = item"
                >
                  <span>{{ item.name }}<span v-if="item.email" style="font-size: 12px; color: #8c8c8c"> · {{ item.email }}</span></span>
                  <span style="font-size: 12px">{{ num(item.points) }} {{ $t('Points') }}</span>
                </a-list-item>
              </template>
            </a-list>

            <template v-if="adjust.client">
              <a-alert
                type="info" style="margin-bottom: 16px"
                :message="adjust.client.name + ' — ' + $t('Points') + ': ' + num(adjust.client.points)"
              />
              <a-form layout="vertical">
                <a-form-item :label="$t('Type')">
                  <a-select
                    v-model:value="adjust.type"
                    :options="[
                      { value: 'credit', label: $t('Credit_Add') },
                      { value: 'debit', label: $t('Debit_Deduct') },
                    ]"
                  />
                </a-form-item>
                <a-form-item :label="$t('Points') + ' *'">
                  <a-input-number v-model:value="adjust.points" :min="0.01" :step="0.01" style="width: 100%" />
                </a-form-item>
                <a-form-item :label="$t('Note')">
                  <a-input v-model:value="adjust.note" :placeholder="$t('Optional')" />
                </a-form-item>
                <a-button type="primary" :loading="adjustSaving" @click="submitAdjust">{{ $t('submit') }}</a-button>
              </a-form>
            </template>
          </div>
        </a-tab-pane>
      </a-tabs>
    </a-card>

    <!-- Reward create/edit -->
    <a-modal
      v-model:open="modalOpen"
      :title="form.id ? $t('Edit') : $t('Add')"
      :confirm-loading="saving"
      :ok-text="$t('submit')"
      :cancel-text="$t('Delete_cancelButtonText')"
      width="720px"
      @ok="submit"
    >
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical" style="margin-top: 8px">
        <a-row :gutter="16">
          <a-col :span="16">
            <a-form-item :label="$t('Name') + ' *'" name="name">
              <a-input v-model:value="form.name" />
            </a-form-item>
          </a-col>
          <a-col :span="8">
            <a-form-item :label="$t('Type')">
              <a-select
                v-model:value="form.type"
                :options="[
                  { value: 'gift_card', label: $t('Gift_Card') },
                  { value: 'voucher', label: $t('Voucher') },
                  { value: 'product', label: $t('Product') },
                ]"
              />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="$t('Description')">
              <a-input v-model:value="form.description" :placeholder="$t('Optional')" />
            </a-form-item>
          </a-col>
          <a-col :span="8">
            <a-form-item :label="$t('Points_Cost') + ' *'" name="points_cost">
              <a-input-number v-model:value="form.points_cost" :min="0.01" :step="0.01" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :span="8" v-if="form.type !== 'product'">
            <a-form-item :label="(form.type === 'gift_card' ? $t('Wallet_Credit_Amount') : $t('Discount_Amount')) + ' *'">
              <a-input-number v-model:value="form.value" :min="0" :step="0.01" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :span="8" v-else>
            <a-form-item :label="$t('Product_ID') + ' *'">
              <a-input-number v-model:value="form.product_id" :min="1" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :span="8">
            <a-form-item :label="$t('Stock')">
              <a-input-number v-model:value="form.stock" :min="0" style="width: 100%" :placeholder="$t('Unlimited')" />
            </a-form-item>
          </a-col>
          <a-col :span="8">
            <a-form-item :label="$t('Per_Customer_Limit')">
              <a-input-number v-model:value="form.per_customer_limit" :min="1" style="width: 100%" :placeholder="$t('Unlimited')" />
            </a-form-item>
          </a-col>
          <a-col :span="8">
            <a-form-item :label="$t('Sort_Order')">
              <a-input-number v-model:value="form.sort_order" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-switch v-model:checked="form.active" />
            {{ form.active ? $t('Active') : $t('Disabled') }}
          </a-col>
        </a-row>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Loyalty rewards — 3 tabs mirroring legacy LoyaltyRewards.vue:
 * - catalog: GET loyalty/rewards?search&type → {rewards}; POST/PUT
 *   loyalty/rewards[/{id}] (value defaults 0, product_id only for type
 *   product, empty→null for description/stock/per_customer_limit, active
 *   1/0); DELETE loyalty/rewards/{id}; toggle = PUT with full payload +
 *   flipped active (legacy behavior)
 * - redemptions: GET loyalty/redemptions?search&status&per_page=20 →
 *   {data}; POST loyalty/redemptions/{id}/fulfill | /cancel (issued only);
 *   server `error` field surfaces verbatim
 * - adjust: GET loyalty/clients?search (300ms debounce) → {clients};
 *   POST loyalty/adjust {client_id, type credit|debit, points, note||null}
 *   → {balance} updates the selected client inline
 */
import { ref, computed } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

const activeTab = ref('rewards');

/* ------------------------------------------------------------ catalog tab */
const search = ref('');
const typeFilter = ref('');
const rewards = ref([]);
const loadingRewards = ref(false);

const rewardColumns = computed(() => [
  { title: t('Name'), key: 'name' },
  { title: t('Type'), key: 'type', width: 110 },
  { title: t('Points_Cost'), key: 'points_cost', align: 'right', width: 110 },
  { title: t('Value'), key: 'value', align: 'right', width: 100 },
  { title: t('Stock'), key: 'stock', align: 'right', width: 100 },
  { title: t('Redeemed'), dataIndex: 'redemptions_count', key: 'redemptions_count', align: 'right', width: 100 },
  { title: t('Status'), key: 'active', width: 140 },
  { title: t('Actions'), key: 'actions', width: 100, align: 'center' },
]);

function num(v) { return Number(v || 0).toLocaleString(); }
function typeLabel(ty) {
  return { gift_card: t('Gift_Card'), voucher: t('Voucher'), product: t('Product') }[ty] || ty;
}
function statusLabel(s) {
  return { issued: t('Issued'), fulfilled: t('Fulfilled'), cancelled: t('Cancelled') }[s] || s;
}
function statusColor(s) {
  return { issued: 'processing', fulfilled: 'success', cancelled: 'default' }[s] || 'default';
}

async function fetchRewards() {
  loadingRewards.value = true;
  try {
    const data = await http.get('loyalty/rewards', { search: search.value, type: typeFilter.value });
    rewards.value = data.rewards || [];
  } catch (e) {
    message.error(t('Failed'));
  } finally {
    loadingRewards.value = false;
  }
}
fetchRewards();

/* ------------------------------------------------------- reward modal */
const modalOpen = ref(false);
const saving = ref(false);
const formRef = ref();

const emptyForm = () => ({
  id: null, name: '', description: '', type: 'gift_card', points_cost: null,
  value: null, product_id: null, stock: null, per_customer_limit: null, sort_order: 0, active: true,
});
const form = ref(emptyForm());

const rules = computed(() => ({
  name: [{ required: true, message: t('Field_is_required') }],
  points_cost: [{ required: true, message: t('Field_is_required') }],
}));

function openCreate() {
  form.value = emptyForm();
  modalOpen.value = true;
}
function openEdit(r) {
  form.value = { ...emptyForm(), ...r, active: !!r.active };
  modalOpen.value = true;
}

function payload(f) {
  const clean = (v) => (v === '' || v == null ? null : v);
  return {
    name: f.name,
    description: clean(f.description),
    type: f.type,
    points_cost: f.points_cost,
    value: clean(f.value) || 0,
    product_id: f.type === 'product' ? clean(f.product_id) : null,
    stock: clean(f.stock),
    per_customer_limit: clean(f.per_customer_limit),
    sort_order: f.sort_order || 0,
    active: f.active ? 1 : 0,
  };
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }
  saving.value = true;
  try {
    if (form.value.id) await http.put(`loyalty/rewards/${form.value.id}`, payload(form.value));
    else await http.post('loyalty/rewards', payload(form.value));
    message.success(t('Successfully_Updated'));
    modalOpen.value = false;
    fetchRewards();
  } catch (e) {
    const errors = e?.data?.errors;
    const msg = errors ? Object.values(errors)[0][0] : t('InvalidData');
    message.error(String(msg));
  } finally {
    saving.value = false;
  }
}

async function toggle(r) {
  try {
    await http.put(`loyalty/rewards/${r.id}`, { ...payload({ ...r, active: r.active }), active: r.active ? 0 : 1 });
    fetchRewards();
  } catch (e) {
    message.error(t('Failed'));
    fetchRewards();
  }
}

async function remove(r) {
  try {
    await http.delete(`loyalty/rewards/${r.id}`);
    message.success(t('Deleted_in_successfully'));
    fetchRewards();
  } catch (e) {
    message.error(t('Failed'));
  }
}

/* -------------------------------------------------------- redemptions tab */
const redSearch = ref('');
const redStatus = ref('');
const redemptions = ref([]);
const loadingRedemptions = ref(false);

const redemptionColumns = computed(() => [
  { title: t('Customer'), key: 'client_name' },
  { title: t('Reward'), dataIndex: 'reward_name', key: 'reward_name' },
  { title: t('Type'), key: 'reward_type', width: 110 },
  { title: t('Points'), key: 'points_spent', align: 'right', width: 90 },
  { title: t('Code'), key: 'code', width: 140 },
  { title: t('Status'), key: 'status', width: 110 },
  { title: t('date'), dataIndex: 'created_at', key: 'created_at', width: 160 },
  { title: t('Actions'), key: 'actions', width: 170, align: 'center' },
]);

async function fetchRedemptions() {
  loadingRedemptions.value = true;
  try {
    const data = await http.get('loyalty/redemptions', {
      search: redSearch.value, status: redStatus.value, per_page: 20,
    });
    redemptions.value = data.data || [];
  } catch (e) {
    message.error(t('Failed'));
  } finally {
    loadingRedemptions.value = false;
  }
}

function onTabChange(key) {
  if (key === 'redemptions') fetchRedemptions();
}

async function fulfill(d) {
  try {
    await http.post(`loyalty/redemptions/${d.id}/fulfill`);
    message.success(t('Successfully_Updated'));
    fetchRedemptions();
  } catch (e) {
    message.error(e?.data?.error || t('Failed'));
  }
}

async function cancelRedemption(d) {
  try {
    await http.post(`loyalty/redemptions/${d.id}/cancel`);
    message.success(t('Successfully_Updated'));
    fetchRedemptions();
  } catch (e) {
    message.error(e?.data?.error || t('Failed'));
  }
}

/* ------------------------------------------------------------- adjust tab */
const clientSearch = ref('');
const clients = ref([]);
const adjust = ref({ client: null, type: 'credit', points: null, note: '' });
const adjustSaving = ref(false);
let searchTimer = null;

function searchClients() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(async () => {
    try {
      const data = await http.get('loyalty/clients', { search: clientSearch.value });
      clients.value = data.clients || [];
    } catch (e) { /* silent, like legacy */ }
  }, 300);
}

async function submitAdjust() {
  if (!adjust.value.client) return;
  adjustSaving.value = true;
  try {
    const data = await http.post('loyalty/adjust', {
      client_id: adjust.value.client.id,
      type: adjust.value.type,
      points: adjust.value.points,
      note: adjust.value.note || null,
    });
    adjust.value.client.points = data.balance;
    adjust.value.points = null;
    adjust.value.note = '';
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.error || t('Failed'));
  } finally {
    adjustSaving.value = false;
  }
}
</script>
