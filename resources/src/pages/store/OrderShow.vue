<template>
  <div class="page">
    <PageHeader :title="code || $t('Order')" :breadcrumb="[$t('Store'), $t('Online_Orders'), code || '']">
      <template #extra>
        <a-space wrap>
          <a-tooltip v-if="order.is_flagged" :title="order.flag_reason">
            <a-tag color="error"><FlagOutlined /> {{ $t('Flagged') }}</a-tag>
          </a-tooltip>
          <a-tag :color="statusColor(order.status)" style="text-transform: uppercase">{{ order.status }}</a-tag>
          <a-tag v-if="order.has_preorder_items" color="warning">{{ $t('HasPreorderItems') }}</a-tag>
          <a-button v-if="order.status === 'confirmed'" :loading="actionBusy" @click="changeStatus('shipped')">
            {{ $t('Mark_Shipped') }}
          </a-button>
          <a-button v-if="order.status === 'shipped'" :loading="actionBusy" @click="changeStatus('delivered')">
            {{ $t('Mark_Delivered') }}
          </a-button>
          <a-button @click="downloadInvoice">
            <template #icon><FilePdfOutlined /></template>
            {{ $t('Download_Invoice') }}
          </a-button>
        </a-space>
      </template>
    </PageHeader>

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-row v-else :gutter="[16, 16]">
      <a-col :xs="24" :lg="14">
        <a-card size="small" :title="$t('Items')" :body-style="{ padding: 0 }">
          <a-table
            :columns="itemColumns" :data-source="order.items"
            :pagination="false" size="small" row-key="id"
            :scroll="{ x: 'max-content' }"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'name'">
                {{ record.name }}
                <a-tag v-if="record.is_preorder" color="warning">{{ $t('PreOrder') }}</a-tag>
              </template>
              <template v-else-if="column.key === 'price'">{{ currency(record.price) }}</template>
              <template v-else-if="column.key === 'total'">{{ currency(record.price * record.qty) }}</template>
            </template>
          </a-table>
        </a-card>
      </a-col>

      <a-col :xs="24" :lg="10">
        <a-card size="small" :title="$t('Customer')" style="margin-bottom: 16px">
          <div class="muted">{{ order.customer_name }}</div>
          <div class="muted">{{ order.customer_email }}</div>
          <div class="muted">{{ order.customer_phone }}</div>
        </a-card>

        <a-card size="small" :title="$t('Shipping')" style="margin-bottom: 16px">
          <div class="muted">{{ order.customer_phone || '-' }}</div>
          <div class="muted">{{ order.customer_address || '-' }}</div>
        </a-card>

        <a-card size="small" :title="$t('warehouse')" style="margin-bottom: 16px">
          <div class="muted">{{ order.warehouse_name || '-' }}</div>
        </a-card>

        <a-card v-if="isPickup" size="small" :title="$t('CollectAtBranch')" style="margin-bottom: 16px">
          <a-tag color="orange" style="margin-bottom: 6px">{{ $t('Pickup') }}</a-tag>
          <div class="muted">{{ order.pickup_branch_name || order.warehouse_name || '-' }}</div>
        </a-card>

        <a-card size="small" :title="$t('Payment')" style="margin-bottom: 16px">
          <div class="line">
            <span class="muted">{{ $t('Method') }}</span>
            <a-tag :color="paymentMethodColor(order.payment_method)">{{ paymentMethodLabel(order.payment_method) }}</a-tag>
          </div>
          <div class="line">
            <span class="muted">{{ $t('Status') }}</span>
            <a-tag :color="order.payment_status === 'paid' ? 'success' : 'warning'">{{ order.payment_status || 'pending' }}</a-tag>
          </div>
        </a-card>

        <a-card
          v-if="proofs.length"
          size="small"
          :title="$t('ProofOfPayment')"
          style="margin-bottom: 16px"
        >
          <div v-for="p in proofs" :key="p.id" class="proof">
            <div class="line">
              <a-tag :color="proofStatusColor(p.status)">{{ proofStatusLabel(p.status) }}</a-tag>
              <span class="muted">{{ p.submitted_at }}</span>
            </div>

            <a-descriptions size="small" :column="1" bordered style="margin: 8px 0">
              <a-descriptions-item :label="$t('ReferenceNumber')">{{ p.reference_number || '-' }}</a-descriptions-item>
              <a-descriptions-item :label="$t('AmountSent')">
                {{ currency(p.amount) }}
                <a-tooltip v-if="amountMismatch(p)" :title="$t('ProofAmountMismatchHint')">
                  <a-tag color="warning" style="margin-inline-start: 6px">{{ $t('AmountMismatch') }}</a-tag>
                </a-tooltip>
              </a-descriptions-item>
              <a-descriptions-item v-if="p.paid_at" :label="$t('PaymentDate')">{{ p.paid_at }}</a-descriptions-item>
              <a-descriptions-item v-if="p.note" :label="$t('Note')">{{ p.note }}</a-descriptions-item>
              <a-descriptions-item v-if="p.reject_reason" :label="$t('Reason')">{{ p.reject_reason }}</a-descriptions-item>
            </a-descriptions>

            <div v-if="p.file_url" class="proof-file">
              <a-image v-if="isImage(p.file_url)" :src="p.file_url" :width="140" />
              <a v-else :href="p.file_url" target="_blank" rel="noopener">
                <FilePdfOutlined /> {{ $t('ViewFile') }}
              </a>
            </div>

            <a-space v-if="p.status === 'pending'" style="margin-top: 10px">
              <a-button type="primary" size="small" :loading="reviewBusy === p.id" @click="approveProof(p)">
                {{ $t('Approve') }}
              </a-button>
              <a-button danger size="small" :loading="reviewBusy === p.id" @click="promptReject(p)">
                {{ $t('Reject') }}
              </a-button>
            </a-space>
          </div>
        </a-card>

        <a-card size="small" title="Summary">
          <div class="line"><span>{{ $t('Subtotal') }}</span><strong>{{ currency(order.subtotal) }}</strong></div>
          <div v-if="Number(order.shipping || 0) > 0" class="line">
            <span>{{ $t('Shipping') }}</span><strong>{{ currency(order.shipping || 0) }}</strong>
          </div>
          <div v-if="Number(order.discount || 0) > 0" class="line">
            <span>{{ $t('Discount') }}</span><strong>-{{ currency(order.discount || 0) }}</strong>
          </div>
          <a-divider style="margin: 8px 0" />
          <div class="line"><span>{{ $t('Total') }}</span><strong>{{ currency(order.total) }}</strong></div>
        </a-card>
      </a-col>
    </a-row>

    <a-modal
      v-model:open="rejectOpen"
      :title="$t('RejectProof')"
      :confirm-loading="reviewBusy !== null"
      @ok="confirmReject"
    >
      <a-form layout="vertical">
        <a-form-item :label="$t('Reason')">
          <a-textarea v-model:value="rejectReason" :rows="3" :maxlength="255" show-count />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Online order details — GET store/orders/{id} (flat order object with
 * items). Fulfilment transitions PATCH store/orders/{id} {status}; invoice
 * blob GET store/orders/{id}/invoice.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { FlagOutlined, FilePdfOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';

const { t } = useI18n();
const route = useRoute();
const auth = useAuthStore();

const id = route.params.id;
const loading = ref(true);
const actionBusy = ref(false);
const order = ref({ items: [], status: 'pending' });
const code = ref('');

// Proof of payment (GCash / bank transfer) awaiting verification.
const reviewBusy = ref(null);
const rejectOpen = ref(false);
const rejectReason = ref('');
const rejectTarget = ref(null);

const proofs = computed(() => order.value.payment_proofs || []);
const isPickup = computed(() => order.value.delivery_method === 'pickup');

const itemColumns = computed(() => [
  { title: t('ProductName'), key: 'name' },
  { title: t('Qty'), dataIndex: 'qty', key: 'qty', align: 'center' },
  { title: t('Price'), key: 'price', align: 'right' },
  { title: t('Total'), key: 'total', align: 'right' },
]);

function currency(n) {
  const c = auth.currency;
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: c }).format(n || 0);
  } catch (e) {
    return `${c} ${Number(n || 0).toFixed(2)}`;
  }
}
function statusColor(s) {
  return { pending: 'warning', confirmed: 'success', shipped: 'cyan', delivered: 'blue', cancelled: 'error' }[s] || 'default';
}
function paymentMethodLabel(m) {
  return {
    credit_card: t('CreditCard'), paypal: 'PayPal', paystack: 'Paystack', flutterwave: 'Flutterwave',
    razorpay: 'Razorpay', bkash: 'bKash', sslcommerz: 'SSLCommerz', mobile_money: t('MobileMoney'), cod: t('CashOnDelivery'),
    gcash: t('GCash'), bank_transfer: t('BankTransfer'), cash_on_pickup: t('CashOnPickup'),
  }[m] || m || 'N/A';
}
function paymentMethodColor(m) {
  return {
    credit_card: 'blue', paypal: 'geekblue', paystack: 'cyan', flutterwave: 'orange', razorpay: 'blue',
    bkash: 'magenta', sslcommerz: 'geekblue',
    mobile_money: 'cyan', cod: 'default', gcash: 'blue', bank_transfer: 'geekblue', cash_on_pickup: 'orange',
  }[m] || 'default';
}
function proofStatusColor(s) {
  return { pending: 'warning', approved: 'success', rejected: 'error' }[s] || 'default';
}
function proofStatusLabel(s) {
  return { pending: t('AwaitingVerification'), approved: t('Verified'), rejected: t('Rejected') }[s] || s;
}
function isImage(url) {
  return /\.(jpe?g|png|webp|gif)$/i.test(String(url || '').split('?')[0]);
}
/** Flag a shopper who sent a different amount than the order total. */
function amountMismatch(p) {
  return Math.abs(Number(p.amount || 0) - Number(order.value.total || 0)) > 0.009;
}

async function reviewProof(proof, status, reason) {
  reviewBusy.value = proof.id;
  try {
    const res = await http.post(`store/orders/${id}/payment-proofs/${proof.id}/review`, {
      status,
      reject_reason: reason || null,
    });
    proof.status = status;
    proof.reject_reason = status === 'rejected' ? reason || null : null;
    if (res && res.payment_status) order.value.payment_status = res.payment_status;
    message.success(status === 'approved' ? t('PaymentVerified') : t('Status_updated'));
  } catch (e) {
    message.error(e?.data?.error || t('Failed'));
  } finally {
    reviewBusy.value = null;
  }
}
function approveProof(p) {
  return reviewProof(p, 'approved');
}
function promptReject(p) {
  rejectTarget.value = p;
  rejectReason.value = '';
  rejectOpen.value = true;
}
async function confirmReject() {
  if (!rejectTarget.value) return;
  await reviewProof(rejectTarget.value, 'rejected', rejectReason.value.trim());
  rejectOpen.value = false;
  rejectTarget.value = null;
}

async function changeStatus(status) {
  actionBusy.value = true;
  try {
    await http.patch(`store/orders/${id}`, { status });
    order.value.status = status;
    message.success(t('Status_updated'));
  } catch (e) {
    message.error(e?.data?.error || t('Failed'));
  } finally {
    actionBusy.value = false;
  }
}
async function downloadInvoice() {
  try {
    await http.download(`store/orders/${id}/invoice`, `invoice-${code.value || id}.pdf`);
  } catch (e) {
    message.error(t('Failed'));
  }
}

onMounted(async () => {
  try {
    const data = await http.get(`store/orders/${id}`);
    order.value = data || { items: [], status: 'pending' };
    code.value = data && (data.code || `#${data.id}`);
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.muted {
  color: rgba(0, 0, 0, 0.55);
}
.line {
  display: flex;
  justify-content: space-between;
  margin-bottom: 6px;
}
.proof + .proof {
  border-top: 1px dashed rgba(0, 0, 0, 0.08);
  margin-top: 12px;
  padding-top: 12px;
}
.proof-file {
  margin-top: 4px;
}
</style>
