<template>
  <a-modal
    :open="open"
    :title="`${editing ? $t('EditPayment') : $t('AddPayment')} — ${active?.Ref || ''}`"
    :confirm-loading="saving"
    :ok-text="$t('submit')"
    @update:open="v => $emit('update:open', v)"
    @ok="$emit('submit')"
  >
    <a-form layout="vertical">
      <a-alert type="info" show-icon style="margin-bottom: 12px" :message="$t('Payment_Amounts_Help')" />
      <a-row :gutter="12">
        <a-col :span="12">
          <a-form-item :label="$t('date')">
            <a-date-picker v-model:value="form.date" value-format="YYYY-MM-DD" style="width: 100%" />
          </a-form-item>
        </a-col>
        <a-col :span="12">
          <a-form-item :label="$t('Amount_Paid')">
            <a-input-number v-model:value="form.montant" style="width: 100%" :min="0" :max="maxDue ?? undefined" />
            <a-typography-text type="secondary" style="font-size: 12px; display: block">
              {{ $t('Amount_Paid_Help') }}
            </a-typography-text>
            <a-typography-text v-if="maxDue != null" type="secondary" style="font-size: 12px; display: block">
              {{ $t('Maximum_payment') }}: {{ money(maxDue) }}
            </a-typography-text>
          </a-form-item>
        </a-col>
        <a-col :span="12">
          <a-form-item :label="$t('Received_Amount')">
            <a-input-number v-model:value="form.received_amount" style="width: 100%" :min="0" />
            <a-typography-text type="secondary" style="font-size: 12px; display: block">
              {{ $t('Received_Amount_Help') }}
            </a-typography-text>
          </a-form-item>
        </a-col>
        <a-col :span="12">
          <a-form-item :label="$t('Paymentchoice')">
            <a-select v-model:value="form.payment_method_id" :options="methodOptions" />
          </a-form-item>
        </a-col>
        <a-col :span="12">
          <a-form-item :label="$t('Account')">
            <a-select v-model:value="form.account_id" allow-clear :placeholder="$t('Choose_Account')" :options="accountOptions" />
          </a-form-item>
        </a-col>
        <a-col :span="12">
          <a-form-item :label="$t('Change')">
            <a-input :value="money(change)" disabled />
          </a-form-item>
        </a-col>
        <a-col :span="12">
          <a-form-item :label="$t('Due')">
            <a-input :value="money(active?.due)" disabled />
          </a-form-item>
        </a-col>
        <a-col :span="24">
          <a-form-item :label="$t('Note')">
            <a-textarea v-model:value="form.notes" :rows="2" />
          </a-form-item>
        </a-col>
      </a-row>
    </a-form>
  </a-modal>
</template>

<script setup>
/**
 * Add/edit payment form for a return document. The parent owns the `form` ref
 * (v-model style would fight the async Ref fetch) and submits on @submit.
 */
import { computed } from 'vue';
import { useFormat } from '../composables/useFormat';

const props = defineProps({
  open: { type: Boolean, default: false },
  active: { type: Object, default: null },
  editing: { type: Object, default: null },
  form: { type: Object, required: true },
  methodOptions: { type: Array, default: () => [] },
  accountOptions: { type: Array, default: () => [] },
  saving: { type: Boolean, default: false },
  /** Cap for the amount input (remaining due); null disables the cap. */
  maxDue: { type: Number, default: null },
});

defineEmits(['update:open', 'submit']);

const { money } = useFormat();

const change = computed(() =>
  Math.max(0, (Number(props.form.received_amount) || 0) - (Number(props.form.montant) || 0))
);
</script>
