<template>
  <div class="page">
    <PageHeader :title="$t('Wallet_Items')" :breadcrumb="[$t('E_Wallet'), $t('Wallet_Items')]">
      <template #extra>
        <a-button type="primary" @click="openCreate">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" :body-style="{ padding: 0 }">
      <div style="padding: 16px; border-bottom: 1px solid rgba(5, 5, 5, 0.06); display: flex; gap: 12px; flex-wrap: wrap">
        <a-input-search
          v-model:value="search" :placeholder="$t('Search')"
          allow-clear style="max-width: 220px" @search="reload" @change="reload"
        />
        <a-select v-model:value="status" style="width: 170px" @change="reload">
          <a-select-option value="">{{ $t('All') }}</a-select-option>
          <a-select-option value="active">{{ $t('Active') }}</a-select-option>
          <a-select-option value="redeemed">{{ $t('Redeemed') }}</a-select-option>
          <a-select-option value="disabled">{{ $t('Disabled') }}</a-select-option>
          <a-select-option value="expired">{{ $t('Expired') }}</a-select-option>
        </a-select>
      </div>
      <a-table
        :columns="columns" :data-source="cards" :loading="isLoading"
        :pagination="pagination" size="middle" row-key="id" :scroll="{ x: 'max-content' }"
        :locale="{ emptyText: $t('No_Wallet_Items') }"
        @change="onTableChange"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'code'">
            <strong style="font-family: ui-monospace, Menlo, monospace">{{ record.code }}</strong>
            <div v-if="record.name" style="font-size: 12px; color: #999">{{ record.name }}</div>
          </template>
          <template v-else-if="column.key === 'type'">
            <a-tag :color="typeColor(record.type)">{{ typeLabel(record.type) }}</a-tag>
          </template>
          <template v-else-if="column.key === 'amount'">{{ money(record.amount) }}</template>
          <template v-else-if="column.key === 'balance'">{{ money(record.balance) }}</template>
          <template v-else-if="column.key === 'status'">
            <a-tag :color="statusColor(record.status)">{{ statusLabel(record.status) }}</a-tag>
          </template>
          <template v-else-if="column.key === 'expires_at'">
            {{ record.expires_at ? record.expires_at.replace('T', ' ') : '—' }}
          </template>
          <template v-else-if="column.key === 'redeemer'">
            {{ record.redeemer_name || '—' }}
          </template>
          <template v-else-if="column.key === 'actions'">
            <a-space>
              <a-tooltip :title="$t('Copy')">
                <a-button size="small" @click="copyCode(record)">
                  <template #icon><CopyOutlined /></template>
                </a-button>
              </a-tooltip>
              <a-button size="small" @click="openEdit(record)">
                <template #icon><EditOutlined /></template>
              </a-button>
              <a-button size="small" danger @click="confirmDelete(record)">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-space>
          </template>
        </template>
      </a-table>
    </a-card>

    <a-modal
      v-model:open="modalOpen" :title="form.id ? $t('Edit') : $t('Add')"
      :confirm-loading="saving" width="720px" @ok="submit"
    >
      <a-form layout="vertical" style="margin-top: 12px">
        <a-row :gutter="16">
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Type')">
              <a-select v-model:value="form.type">
                <a-select-option value="gift_card">{{ $t('Gift_Card') }}</a-select-option>
                <a-select-option value="voucher">{{ $t('Voucher') }}</a-select-option>
                <a-select-option value="store_credit">{{ $t('Store_Credit') }}</a-select-option>
              </a-select>
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Name')">
              <a-input v-model:value="form.name" :placeholder="$t('Optional')" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Amount') + ' *'">
              <a-input-number v-model:value="form.amount" :min="0.01" :step="0.01" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Expires') + ' (' + $t('Optional') + ')'">
              <a-input v-model:value="form.expires_at" type="datetime-local" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Code')">
              <a-input
                v-model:value="form.code"
                :placeholder="form.id ? '' : $t('Auto_Generate_If_Empty')"
                :disabled="!form.id && form.quantity > 1"
                @input="form.code = (form.code || '').toUpperCase()"
              />
            </a-form-item>
          </a-col>
          <a-col v-if="!form.id" :xs="24" :md="12">
            <a-form-item :label="$t('Quantity')" :extra="$t('Batch_Generate_Hint')">
              <a-input-number v-model:value="form.quantity" :min="1" :max="500" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="$t('Note')">
              <a-input v-model:value="form.note" :placeholder="$t('Optional')" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-switch v-model:checked="form.active" />
            <span style="margin-left: 8px">{{ form.active ? $t('Active') : $t('Disabled') }}</span>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Wallet items (gift cards / vouchers / store credit) — GET store/gift-cards
 * ?search&status&per_page=10&page → {data, meta{total, page, pages}}. Save
 * POST/PUT store/gift-cards[/{id}] with the legacy payload builder: empty →
 * null, status derived from the active switch, quantity only on create
 * (code only sent when quantity <= 1; batch generation auto-codes).
 */
import { ref, computed, onMounted } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined, CopyOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

const isLoading = ref(true);
const saving = ref(false);
const search = ref('');
const status = ref('');
const cards = ref([]);
const meta = ref({ total: 0, page: 1, pages: 1 });
const page = ref(1);
const modalOpen = ref(false);

const emptyForm = () => ({
  id: null, type: 'gift_card', name: '', amount: null, code: '', quantity: 1,
  expires_at: '', note: '', active: true,
});
const form = ref(emptyForm());

const columns = computed(() => [
  { title: t('Code'), key: 'code' },
  { title: t('Type'), key: 'type', width: 120 },
  { title: t('Amount'), key: 'amount', align: 'right' },
  { title: t('Balance'), key: 'balance', align: 'right' },
  { title: t('Status'), key: 'status', width: 100 },
  { title: t('Expires'), key: 'expires_at' },
  { title: t('Redeemed_By'), key: 'redeemer' },
  { title: t('Actions'), key: 'actions', width: 130 },
]);

const pagination = computed(() => ({
  current: meta.value.page,
  pageSize: 10,
  total: meta.value.total,
  showSizeChanger: false,
}));

function money(v) { return Number(v || 0).toFixed(2); }
function typeLabel(x) {
  return { gift_card: t('Gift_Card'), voucher: t('Voucher'), store_credit: t('Store_Credit') }[x] || x;
}
function typeColor(x) {
  return { gift_card: 'blue', voucher: 'cyan', store_credit: 'success' }[x] || 'default';
}
function statusLabel(s) {
  return { active: t('Active'), redeemed: t('Redeemed'), disabled: t('Disabled'), expired: t('Expired') }[s] || s;
}
function statusColor(s) {
  return { active: 'success', redeemed: 'blue', disabled: 'warning', expired: 'error' }[s] || 'default';
}

async function fetchCards() {
  isLoading.value = true;
  try {
    const r = await http.get('store/gift-cards', {
      search: search.value, status: status.value, per_page: 10, page: page.value,
    });
    cards.value = r.data || [];
    meta.value = r.meta || meta.value;
  } catch (e) {
    message.error(t('Failed'));
  } finally {
    isLoading.value = false;
  }
}
function reload() {
  page.value = 1;
  fetchCards();
}
function onTableChange(pag) {
  page.value = pag.current;
  fetchCards();
}

function openCreate() {
  form.value = emptyForm();
  modalOpen.value = true;
}
function openEdit(c) {
  form.value = {
    ...emptyForm(),
    id: c.id, type: c.type, name: c.name || '', amount: c.amount, code: c.code,
    expires_at: c.expires_at || '', note: c.note || '', active: c.status !== 'disabled',
  };
  modalOpen.value = true;
}
function buildPayload() {
  const f = form.value;
  const clean = v => (v === '' || v == null ? null : v);
  const p = {
    type: f.type,
    name: clean(f.name),
    amount: f.amount,
    expires_at: clean(f.expires_at),
    note: clean(f.note),
    status: f.active ? 'active' : 'disabled',
  };
  if (!f.id) {
    p.quantity = f.quantity || 1;
    if (f.quantity <= 1) p.code = clean(f.code);
  } else {
    p.code = f.code;
  }
  return p;
}
async function submit() {
  saving.value = true;
  try {
    if (form.value.id) await http.put(`store/gift-cards/${form.value.id}`, buildPayload());
    else await http.post('store/gift-cards', buildPayload());
    message.success(t('Successfully_Updated'));
    modalOpen.value = false;
    fetchCards();
  } catch (e) {
    const msg = e?.data?.errors ? Object.values(e.data.errors)[0][0] : t('InvalidData');
    message.error(msg);
  } finally {
    saving.value = false;
  }
}
function copyCode(c) {
  try {
    navigator.clipboard.writeText(c.code);
    message.success(t('Copied'));
  } catch (e) {
    message.warning(c.code);
  }
}
function confirmDelete(c) {
  Modal.confirm({
    title: t('AreYouSure'),
    okText: t('Yes'),
    okType: 'danger',
    cancelText: t('No'),
    async onOk() {
      try {
        await http.delete(`store/gift-cards/${c.id}`);
        message.success(t('Deleted_in_successfully'));
        fetchCards();
      } catch (e) {
        message.error(t('Failed'));
      }
    },
  });
}

onMounted(fetchCards);
</script>
