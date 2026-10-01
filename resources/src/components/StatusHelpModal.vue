<template>
  <a-tooltip :title="$t('Status_Help')">
    <a-button type="text" @click="open = true">
      <template #icon><QuestionCircleOutlined /></template>
    </a-button>
  </a-tooltip>

  <a-modal v-model:open="open" :title="copy.title" :footer="null" width="640px">
    <div class="sh-section">
      <div class="sh-heading">{{ $t('Status') }}</div>
      <p class="sh-intro">{{ copy.statusIntro }}</p>
      <div v-for="row in copy.statusRows" :key="row.value" class="sh-row">
        <a-tag :color="docStatusColor(row.value)" class="sh-tag">{{ $t(row.labelKey) }}</a-tag>
        <span class="sh-desc">{{ row.desc }}</span>
      </div>
    </div>

    <a-divider style="margin: 16px 0" />

    <div class="sh-section">
      <div class="sh-heading">{{ $t('PaymentStatus') }}</div>
      <p class="sh-intro">{{ copy.paymentIntro }}</p>
      <div v-for="row in copy.paymentRows" :key="row.value" class="sh-row">
        <a-tag :color="payStatusColor(row.value)" class="sh-tag">{{ $t(row.labelKey) }}</a-tag>
        <span class="sh-desc">{{ row.desc }}</span>
      </div>
    </div>
  </a-modal>
</template>

<script setup>
/**
 * Explains the Status and Payment Status filters/tags on the Sales and
 * Purchases lists -- what each value means and what it actually does
 * (stock movement, in particular), since neither is obvious from the tag
 * alone. Content is sourced from the real backend logic: stock only moves
 * when the document status is completed/received (SalesController /
 * PurchasesController gate every stock/batch/serial effect on it), and
 * paid/partial/unpaid are computed from paid_amount vs GrandTotal, never
 * picked directly -- pending/Cancelled payment statuses are legacy values
 * no current code path writes, kept filterable for old/imported records.
 */
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { QuestionCircleOutlined } from '@ant-design/icons-vue';
import { docStatusColor, payStatusColor } from '../lib/statusColors';

const props = defineProps({
  // 'sale' | 'purchase' -- only the terminology and stock direction differ.
  docType: { type: String, default: 'sale' },
});

const { t } = useI18n();
const open = ref(false);

const isPurchase = computed(() => props.docType === 'purchase');

const copy = computed(() => {
  const doneValue = isPurchase.value ? 'received' : 'completed';
  const doneLabelKey = isPurchase.value ? 'Received' : 'complete';
  const docWord = isPurchase.value ? t('Purchases') : t('Sales');
  const stockVerb = isPurchase.value ? 'added to' : 'deducted from';

  return {
    title: `${docWord} — ${t('Status')} & ${t('PaymentStatus')}`,
    statusIntro: isPurchase.value
      ? 'Tracks the purchase document itself, independent of how much has been paid.'
      : 'Tracks the sale document itself, independent of how much has been paid.',
    statusRows: [
      {
        value: doneValue, labelKey: doneLabelKey,
        desc: `Final state. Stock is ${stockVerb} the location the moment it becomes ${isPurchase.value ? 'Received' : 'Completed'} -- batches/serials are also consumed/released at this point, and deleting a document in this state reverses all of it.`,
      },
      {
        value: 'pending', labelKey: 'Pending',
        desc: `A draft/hold -- saved but not finalized yet. Stock is NOT touched while a document stays Pending.`,
      },
      {
        value: 'ordered', labelKey: 'Ordered',
        desc: `Same as Pending for stock purposes (nothing moves yet) -- used for documents placed but not yet ${isPurchase.value ? 'received' : 'fulfilled'}.`,
      },
      {
        value: 'cancelled', labelKey: 'Cancelled',
        desc: isPurchase.value
          ? 'Voided from Pending (row menu -> Cancel) -- the purchase and its lines stay on record, just disabled: no further edit or payment.'
          : 'Voided from Pending (row menu -> Cancel) -- the invoice and its lines stay on record, just disabled: no further edit/payment/return. Any loyalty points already used/earned by it are reversed on the customer.',
      },
    ],
    paymentIntro: 'Computed automatically from the amount paid vs the grand total -- not something you pick directly (the create form only asks how much to pay right now).',
    paymentRows: [
      { value: 'paid', labelKey: 'Paid', desc: 'Fully paid -- nothing left owing.' },
      { value: 'partial', labelKey: 'partial', desc: 'Some of the total has been paid, some is still due.' },
      { value: 'unpaid', labelKey: 'Unpaid', desc: 'Nothing has been paid yet.' },
      {
        value: 'pending', labelKey: 'Pending',
        desc: 'Legacy value -- no current save path produces this; only shows up on older/imported records.',
      },
      {
        value: 'Cancelled', labelKey: 'Cancelled',
        desc: 'Legacy value, same as above -- kept filterable so old records with it can still be found.',
      },
    ],
  };
});
</script>

<style scoped>
.sh-heading {
  font-size: 13px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  opacity: 0.65;
  margin-bottom: 6px;
}
.sh-intro {
  font-size: 13px;
  color: rgba(0, 0, 0, 0.55);
  margin-bottom: 10px;
}
.sh-row {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 6px 0;
}
.sh-tag {
  flex: none;
  min-width: 76px;
  text-align: center;
  margin: 0;
}
.sh-desc {
  font-size: 13px;
  line-height: 1.45;
  color: rgba(0, 0, 0, 0.75);
}
</style>
