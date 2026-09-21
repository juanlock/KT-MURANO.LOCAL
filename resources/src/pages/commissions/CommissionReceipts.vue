<template>
  <div class="page">
    <PageHeader :title="$t('Commission_Receipts')" :breadcrumb="[$t('Commissions'), $t('Commission_Receipts')]">
      <template #extra>
        <a-button v-if="auth.can('commissions_add')" type="primary" @click="openCreateModal">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'agent'">
          {{ record.sales_agent ? record.sales_agent.name : '—' }}
        </template>
        <template v-else-if="column.key === 'amount'">
          {{ formatMoney(record.amount) }}
        </template>
        <template v-else-if="column.key === 'paid_at'">
          {{ formatDate(record.paid_at) }}
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-button size="small" @click="viewReceipt(record)">
            <template #icon><EyeOutlined /></template>
          </a-button>
        </template>
      </template>
    </DataTable>

    <!-- View receipt -->
    <a-modal v-model:open="viewOpen" :title="$t('Commission_Receipt')" :footer="null">
      <a-descriptions v-if="viewReceiptData" :column="1" size="small" bordered style="margin-top: 12px">
        <a-descriptions-item :label="$t('Ref')">{{ viewReceiptData.Ref }}</a-descriptions-item>
        <a-descriptions-item :label="$t('Sales_Agent')">
          {{ viewReceiptData.sales_agent ? viewReceiptData.sales_agent.name : '—' }}
        </a-descriptions-item>
        <a-descriptions-item :label="$t('Amount')">{{ formatMoney(viewReceiptData.amount) }}</a-descriptions-item>
        <a-descriptions-item :label="$t('Paid_At')">{{ formatDate(viewReceiptData.paid_at) }}</a-descriptions-item>
      </a-descriptions>
    </a-modal>

    <!-- Create receipt -->
    <a-modal
      v-model:open="createOpen" :title="$t('Add') + ' ' + $t('Commission_Receipt')"
      :confirm-loading="creating"
      :ok-button-props="{ disabled: !createForm.sales_agent_id || !createForm.commission_ids.length || !createForm.amount }"
      @ok="submitCreateReceipt"
    >
      <a-form layout="vertical" style="margin-top: 12px">
        <a-form-item :label="$t('Sales_Agent')">
          <a-select
            v-model:value="createForm.sales_agent_id" :placeholder="$t('PleaseSelect')"
            :options="agentsList.map(a => ({ label: a.name, value: a.id }))"
            show-search option-filter-prop="label" allow-clear
            @change="onCreateAgentSelect"
          />
        </a-form-item>
        <a-form-item v-if="createForm.sales_agent_id" :label="$t('Approved_Commissions')">
          <div class="commission-picker">
            <a-checkbox-group v-model:value="createForm.commission_ids" style="display: block">
              <div v-for="c in approvedCommissions" :key="c.id" style="margin-bottom: 4px">
                <a-checkbox :value="c.id">
                  {{ c.sale ? c.sale.Ref : '' }} — {{ formatMoney(c.commission_amount) }}
                </a-checkbox>
              </div>
            </a-checkbox-group>
            <span v-if="!approvedCommissions.length" style="color: #999">{{ $t('NodataAvailable') }}</span>
          </div>
          <small style="color: #999">{{ $t('Total') }}: {{ formatMoney(createForm.amount) }}</small>
        </a-form-item>
        <a-form-item :label="$t('Ref')">
          <a-input v-model:value="createForm.Ref" :maxlength="192" />
        </a-form-item>
        <a-form-item :label="$t('Amount') + ' *'">
          <a-input-number v-model:value="createForm.amount" :min="0" :step="0.01" style="width: 100%" />
        </a-form-item>
        <a-form-item :label="$t('Paid_At') + ' *'">
          <a-input v-model:value="createForm.paid_at" type="date" />
        </a-form-item>
        <a-form-item :label="$t('Payment_Method')">
          <a-select
            v-model:value="createForm.payment_method_id" :placeholder="$t('PleaseSelect')"
            :options="paymentMethodsList.map(m => ({ label: m.name, value: m.id }))"
            show-search option-filter-prop="label" allow-clear
          />
        </a-form-item>
        <a-form-item :label="$t('Notes')">
          <a-textarea v-model:value="createForm.notes" :rows="2" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Commission receipts — GET commission_receipts → {receipts, totalRows}
 * (data.data || data unwrap), sort paid_at desc; view GET
 * commission_receipts/{id}. Create: Ref pre-filled from
 * commission_receipts/new_ref, agent's approved commissions from
 * commission_report?status=approved&limit=-1, amount auto-summed from ticked
 * ids (legacy watcher), POST commission_receipts {sales_agent_id,
 * commission_ids, Ref?, amount, paid_at, payment_method_id?, notes?}.
 */
import { ref, computed, watch, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EyeOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';

const { t } = useI18n();
const auth = useAuthStore();

const crud = useCrudTable('commission_receipts', {
  sortField: 'paid_at',
  sortType: 'desc',
  select: p => {
    const d = p.data || p;
    return { rows: d.receipts || [], total: d.totalRows || 0 };
  },
});

const viewOpen = ref(false);
const viewReceiptData = ref(null);
const createOpen = ref(false);
const creating = ref(false);
const agentsList = ref([]);
const paymentMethodsList = ref([]);
const approvedCommissions = ref([]);
const createForm = ref({
  sales_agent_id: null,
  commission_ids: [],
  Ref: '',
  amount: 0,
  paid_at: new Date().toISOString().slice(0, 10),
  payment_method_id: null,
  notes: '',
});

const columns = computed(() => [
  { title: t('Ref'), dataIndex: 'Ref', key: 'Ref', sorter: true },
  { title: t('Sales_Agent'), key: 'agent' },
  { title: t('Amount'), key: 'amount', align: 'right' },
  { title: t('Paid_At'), key: 'paid_at', sorter: true },
  { title: t('Action'), key: 'actions', width: 70 },
]);

// Legacy watcher: ticking commissions recomputes the amount.
watch(() => createForm.value.commission_ids, ids => {
  let sum = 0;
  ids.forEach(id => {
    const c = approvedCommissions.value.find(x => x.id === id);
    if (c && c.commission_amount != null) sum += Number(c.commission_amount);
  });
  createForm.value.amount = Math.round(sum * 100) / 100;
});

function formatDate(v) { return v ? new Date(v).toLocaleDateString() : '—'; }
function formatMoney(v) {
  return v != null ? Number(v).toLocaleString(undefined, { minimumFractionDigits: 2 }) : '0.00';
}

async function viewReceipt(row) {
  try {
    const data = await http.get(`commission_receipts/${row.id}`);
    viewReceiptData.value = data.data || data;
    viewOpen.value = true;
  } catch (e) {
    message.error(t('Error'));
  }
}

function resetCreateForm() {
  createForm.value = {
    sales_agent_id: null,
    commission_ids: [],
    Ref: '',
    amount: 0,
    paid_at: new Date().toISOString().slice(0, 10),
    payment_method_id: null,
    notes: '',
  };
  approvedCommissions.value = [];
}

async function openCreateModal() {
  resetCreateForm();
  createOpen.value = true;
  http.get('commission_receipts/new_ref').then(data => {
    const d = data.data || data;
    if (d && d.Ref) createForm.value.Ref = d.Ref;
  }).catch(() => {});
  http.get('sales_agents_list_for_select').then(data => {
    const d = data.data || data;
    agentsList.value = Array.isArray(d) ? d : (d.agents || []);
  }).catch(() => { agentsList.value = []; });
  http.get('payment_methods', { limit: '-1' }).then(data => {
    paymentMethodsList.value = data.methods || data.data?.methods || [];
  }).catch(() => { paymentMethodsList.value = []; });
}

async function onCreateAgentSelect() {
  createForm.value.commission_ids = [];
  approvedCommissions.value = [];
  if (!createForm.value.sales_agent_id) return;
  try {
    const data = await http.get('commission_report', {
      sales_agent_id: createForm.value.sales_agent_id, status: 'approved', limit: '-1',
    });
    const d = data.data || data;
    approvedCommissions.value = d.commissions || [];
  } catch (e) {
    approvedCommissions.value = [];
  }
}

async function submitCreateReceipt() {
  if (!createForm.value.sales_agent_id || !createForm.value.commission_ids.length || createForm.value.amount == null) return;
  creating.value = true;
  try {
    await http.post('commission_receipts', {
      sales_agent_id: createForm.value.sales_agent_id,
      commission_ids: createForm.value.commission_ids,
      Ref: createForm.value.Ref || undefined,
      amount: createForm.value.amount,
      paid_at: createForm.value.paid_at,
      payment_method_id: createForm.value.payment_method_id || undefined,
      notes: createForm.value.notes || undefined,
    });
    createOpen.value = false;
    message.success(t('Created_successfully'));
    crud.reload();
  } catch (e) {
    message.error(e?.data?.message || t('Error'));
  } finally {
    creating.value = false;
  }
}

onMounted(crud.fetchRows);
</script>

<style scoped>
.commission-picker {
  border: 1px solid rgba(5, 5, 5, 0.12);
  border-radius: 8px;
  padding: 8px 12px;
  max-height: 200px;
  overflow-y: auto;
}
</style>
