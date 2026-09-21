<template>
  <!-- Pay due -->
  <a-modal v-model:open="payOpen" :title="modalTitle" width="720px" :footer="null">
    <a-alert
      type="info" show-icon style="margin-bottom: 16px"
      :message="`${$t('Payment_Allocation')}: ${$t('Payment_Allocation_description')}`"
    />
    <a-row :gutter="16" style="margin-bottom: 16px">
      <a-col :span="6">
        <a-statistic :title="$t('Opening_Balance')" :value="money(openingBalance)" />
      </a-col>
      <a-col :span="6">
        <a-statistic :title="$t('Sales_Due')" :value="money(salesDue)" />
      </a-col>
      <a-col :span="6">
        <a-statistic :title="$t('Service_Due')" :value="money(serviceDue)" />
      </a-col>
      <a-col :span="6">
        <a-statistic
          :title="$t('Total_Due')" :value="money(totalDue)"
          :value-style="{ color: totalDue > 0 ? '#ff4d4f' : '#52c41a' }"
        />
      </a-col>
    </a-row>

    <a-form ref="payRef" :model="payment" :rules="payRules" layout="vertical">
      <a-form-item :label="$t('Paying_Amount') + ' *'" name="amount">
        <a-input-number v-model:value="payment.amount" :min="0" :max="totalDue" style="width: 100%" />
        <div class="muted">{{ $t('Maximum_payment') }}: <strong>{{ money(totalDue) }}</strong></div>
      </a-form-item>
      <a-form-item :label="$t('Paymentchoice') + ' *'" name="payment_method_id">
        <a-select
          v-model:value="payment.payment_method_id" :placeholder="$t('PleaseSelect')"
          :options="paymentMethods.map(m => ({ value: m.id, label: m.name }))"
        />
      </a-form-item>
      <a-form-item :label="$t('Account')">
        <a-select
          v-model:value="payment.account_id" allow-clear :placeholder="$t('Choose_Account')"
          :options="accounts.map(a => ({ value: a.id, label: a.account_name ?? a.name }))"
        />
      </a-form-item>
      <a-form-item :label="$t('Please_provide_any_details')">
        <a-textarea v-model:value="payment.notes" :rows="3" />
      </a-form-item>
      <a-button type="primary" :loading="paying" @click="submitPayment">{{ $t('submit') }}</a-button>
    </a-form>
  </a-modal>

  <!-- Receipt -->
  <a-modal v-model:open="receiptOpen" :title="$t('Customer_Credit_Note')" :footer="null" width="380px">
    <div ref="receiptRef" class="receipt">
      <div class="receipt-head">
        <strong>{{ company.CompanyName }}</strong>
        <div>{{ company.CompanyAdress }}</div>
        <div>{{ company.CompanyPhone }}</div>
      </div>
      <div><strong>{{ $t('date') }}:</strong> {{ receipt.date }}</div>
      <div><strong>{{ $t('Customer') }}:</strong> {{ client?.name }}</div>
      <table>
        <tr><td>{{ $t('Payment_Method') }}</td><td>{{ receiptMethodName }}</td></tr>
        <tr><td>{{ $t('Amount') }}</td><td>{{ money(receipt.amount) }}</td></tr>
        <tr><td>{{ $t('Due_Before') }}</td><td>{{ money(receipt.dueBefore) }}</td></tr>
        <tr><td>{{ $t('Due_After') }}</td><td>{{ money(receipt.dueBefore - (Number(receipt.amount) || 0)) }}</td></tr>
      </table>
    </div>
    <a-button style="margin-top: 12px" @click="printReceipt">
      <template #icon><PrinterOutlined /></template>
      {{ $t('print') }}
    </a-button>
  </a-modal>
</template>

<script setup>
/**
 * Pay-due modal + printable credit note, extracted from CustomerDetails so the
 * customers list shows the identical flow. Owns the whole cycle: form, POST to
 * clients_pay_due (opening balance is allocated first, then sales, then open
 * service jobs — hence the info alert), then the receipt. Parents call open()
 * and refresh their data on @paid.
 */
import { ref, computed } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PrinterOutlined } from '@ant-design/icons-vue';
import { useFormat } from '../composables/useFormat';
import http from '../lib/http';

const props = defineProps({
  /** { id, name } — the customer being paid. */
  client: { type: Object, default: null },
  openingBalance: { type: Number, default: 0 },
  salesDue: { type: Number, default: 0 },
  /** Outstanding balance of the customer's accepted / in-progress service jobs. */
  serviceDue: { type: Number, default: 0 },
  /** [{ id, name }] */
  paymentMethods: { type: Array, default: () => [] },
  /** [{ id, account_name }] */
  accounts: { type: Array, default: () => [] },
  /** { CompanyName, CompanyAdress, CompanyPhone } — receipt header. */
  company: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['paid']);

const { t } = useI18n();
const { money, roundMoney } = useFormat();

const payOpen = ref(false);
const paying = ref(false);
const payRef = ref();
const payment = ref({ amount: 0, payment_method_id: undefined, account_id: undefined, notes: '' });

const receiptOpen = ref(false);
const receiptRef = ref();
const receipt = ref({ date: '', amount: 0, dueBefore: 0, payment_method_id: null });
const receiptMethodName = computed(() => {
  const m = props.paymentMethods.find(x => Number(x.id) === Number(receipt.value.payment_method_id));
  return m ? m.name : '-';
});

const modalTitle = computed(() =>
  props.client?.name ? `${t('Pay_Due')} — ${props.client.name}` : t('Pay_Due'));

// Rounded: the raw float sum is the :max of the paying-amount input, and the
// clamp would otherwise display something like 121.99999999999994.
const totalDue = computed(() => roundMoney(props.openingBalance + props.salesDue + props.serviceDue));

const payRules = computed(() => ({
  amount: [
    { required: true, message: t('Field_is_required') },
    {
      validator: (_r, v) => (Number(v) > totalDue.value
        ? Promise.reject(t('Paying_amount_is_greater_than_Total_Due'))
        : Promise.resolve()),
    },
  ],
  payment_method_id: [{ required: true, message: t('Field_is_required') }],
}));

function open() {
  payment.value = { amount: 0, payment_method_id: undefined, account_id: undefined, notes: '' };
  payOpen.value = true;
}
defineExpose({ open });

async function submitPayment() {
  try {
    await payRef.value.validate();
  } catch (e) {
    message.error(t('Please_fill_the_form_correctly'));
    return;
  }
  paying.value = true;
  // Captured before the parent reloads so the receipt can show due before/after.
  const snapshot = {
    date: new Date().toISOString().slice(0, 10),
    amount: payment.value.amount,
    dueBefore: totalDue.value,
    payment_method_id: payment.value.payment_method_id,
  };
  try {
    await http.post('clients_pay_due', {
      client_id: props.client?.id,
      amount: payment.value.amount,
      notes: payment.value.notes,
      payment_method_id: payment.value.payment_method_id,
      account_id: payment.value.account_id || null,
    });
    message.success(t('Successfully_Created'));
    payOpen.value = false;
    receipt.value = snapshot;
    receiptOpen.value = true;
    emit('paid');
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    paying.value = false;
  }
}

function printReceipt() {
  const node = receiptRef.value;
  if (!node) return;
  const win = window.open('', '', 'height=600,width=420');
  if (!win) {
    message.error(t('InvalidData'));
    return;
  }
  win.document.write(
    `<html><head><link rel="stylesheet" href="/css/pos_print.css" /></head><body>${node.innerHTML}</body></html>`
  );
  win.document.close();
  setTimeout(() => win.print(), 600);
}
</script>

<style scoped>
.muted {
  color: rgba(0, 0, 0, 0.45);
  font-size: 12px;
}
.receipt {
  font-size: 12px;
  font-family: monospace;
}
.receipt-head {
  text-align: center;
  margin-bottom: 12px;
}
.receipt table {
  width: 100%;
  margin-top: 12px;
  border-top: 1px dashed #999;
}
.receipt td {
  padding: 4px 0;
}
.receipt td:last-child {
  text-align: right;
}
</style>
