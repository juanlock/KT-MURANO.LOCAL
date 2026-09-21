<template>
  <div class="page">
    <PageHeader :title="$t('E_Wallet_Settings')" :breadcrumb="[$t('E_Wallet'), $t('E_Wallet_Settings')]" />

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

    <a-row v-else :gutter="[16, 16]">
      <a-col :xs="24" :lg="16">
        <!-- Master switch -->
        <a-card size="small" style="margin-bottom: 16px">
          <div class="setting-row">
            <div>
              <strong>{{ $t('Enable_E_Wallet') }}</strong>
              <div class="help">{{ $t('Enable_E_Wallet_Help') }}</div>
            </div>
            <a-switch v-model:checked="form.wallet_enabled" />
          </div>
        </a-card>

        <!-- Balance settings -->
        <a-card size="small" :title="$t('Balance_Settings')" style="margin-bottom: 16px">
          <div class="setting-row">
            <div>
              {{ $t('Allow_Negative_Balance') }}
              <div class="help">{{ $t('Allow_Negative_Balance_Help') }}</div>
            </div>
            <a-switch v-model:checked="form.wallet_allow_negative" />
          </div>
        </a-card>

        <!-- Refund behavior -->
        <a-card size="small" :title="$t('Refund_Behavior')" style="margin-bottom: 16px">
          <div class="help" style="margin-bottom: 12px">{{ $t('Refund_Behavior_Help') }}</div>
          <a-form-item :label="$t('Refund_Destination')" style="margin-bottom: 0">
            <a-radio-group v-model:value="form.wallet_refund_destination">
              <a-radio value="wallet" style="display: block; margin-bottom: 6px">{{ $t('Refund_To_Wallet') }}</a-radio>
              <a-radio value="original" style="display: block">{{ $t('Refund_To_Original') }}</a-radio>
            </a-radio-group>
            <div class="help" style="margin-top: 8px">{{ $t('Refund_Destination_Help') }}</div>
          </a-form-item>
        </a-card>

        <!-- Withdrawal settings -->
        <a-card size="small" :title="$t('Withdrawal_Settings')" style="margin-bottom: 16px">
          <div class="setting-row">
            <div>
              {{ $t('Enable_Withdrawal') }}
              <div class="help">{{ $t('Enable_Withdrawal_Help') }}</div>
            </div>
            <a-switch v-model:checked="form.wallet_withdrawal_enabled" />
          </div>
          <a-form-item
            v-if="form.wallet_withdrawal_enabled"
            :label="$t('Minimum_Withdrawal_Amount')" style="margin: 16px 0 0; max-width: 240px"
          >
            <a-input-number v-model:value="form.wallet_min_withdrawal" :min="0" :step="0.01" style="width: 100%" />
          </a-form-item>
        </a-card>

        <a-button type="primary" :loading="saving" @click="save">{{ $t('submit') }}</a-button>
      </a-col>

      <a-col :xs="24" :lg="8">
        <a-card size="small" :title="$t('E_Wallet')">
          <a-space direction="vertical" style="width: 100%">
            <a-button block @click="$router.push('/ewallet/dashboard')">{{ $t('E_Wallet_Dashboard') }}</a-button>
            <a-button block @click="$router.push('/ewallet/items')">{{ $t('Wallet_Items') }}</a-button>
          </a-space>
        </a-card>
      </a-col>
    </a-row>
  </div>
</template>

<script setup>
/**
 * E-Wallet settings — GET store/wallet/settings; save POST with 1/0 flags
 * (legacy payload verbatim: wallet_enabled, wallet_allow_negative,
 * wallet_refund_destination wallet|original, wallet_withdrawal_enabled,
 * wallet_min_withdrawal).
 */
import { ref, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

const isLoading = ref(true);
const saving = ref(false);
// POS spends wallets through the payment method named "Wallet" — warn when
// it is missing (e.g. renamed back after the id-8 collision).
const walletMethodExists = ref(true);
const form = ref({
  wallet_enabled: false,
  wallet_allow_negative: false,
  wallet_refund_destination: 'wallet',
  wallet_withdrawal_enabled: false,
  wallet_min_withdrawal: 10,
});

onMounted(async () => {
  try {
    const r = await http.get('store/wallet/settings');
    walletMethodExists.value = r.wallet_payment_method_exists !== false;
    form.value = {
      wallet_enabled: !!r.wallet_enabled,
      wallet_allow_negative: !!r.wallet_allow_negative,
      wallet_refund_destination: r.wallet_refund_destination || 'wallet',
      wallet_withdrawal_enabled: !!r.wallet_withdrawal_enabled,
      wallet_min_withdrawal: Number(r.wallet_min_withdrawal || 0),
    };
  } catch (e) {
    message.error(t('Failed'));
  } finally {
    isLoading.value = false;
  }
});

async function save() {
  saving.value = true;
  try {
    await http.post('store/wallet/settings', {
      wallet_enabled: form.value.wallet_enabled ? 1 : 0,
      wallet_allow_negative: form.value.wallet_allow_negative ? 1 : 0,
      wallet_refund_destination: form.value.wallet_refund_destination,
      wallet_withdrawal_enabled: form.value.wallet_withdrawal_enabled ? 1 : 0,
      wallet_min_withdrawal: form.value.wallet_min_withdrawal || 0,
    });
    message.success(t('Successfully_Updated'));
  } catch (e) {
    const msg = e?.data?.errors ? Object.values(e.data.errors)[0][0] : t('InvalidData');
    message.error(msg);
  } finally {
    saving.value = false;
  }
}
</script>

<style scoped>
.setting-row {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
}
.help {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.45);
  margin-top: 2px;
}
</style>
