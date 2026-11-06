<template>
  <!-- A company's website: its own imported site (original design) or the
       default online store with the standard pages (HRM -> Companies). -->
  <div class="website-choice">
    <a-radio-group :value="mode" style="width: 100%" @update:value="v => emit('update:mode', v)">
      <a-radio value="default" class="wc-option">
        <strong>{{ $t('tx_Use_the_default_online_store_35168') }}</strong>
        <div class="wc-help">
          {{ $t('tx_The_company_s_domain_opens_the_online_store_with_standard_pa_db2fe') }}
        </div>
      </a-radio>
      <a-radio value="import" class="wc-option">
        <strong>{{ $t('tx_Import_the_existing_website_a8a2c') }}</strong>
        <div class="wc-help">
          {{ $t('tx_Copies_the_company_s_current_website_its_pages_design_and_im_2386e') }}
        </div>
      </a-radio>
    </a-radio-group>
    <a-form-item v-if="mode === 'import'" :label="$t('tx_Current_website_address_cf56b')" style="margin-top: 8px">
      <a-input :value="url" placeholder="https://www.example.com" @update:value="v => emit('update:url', v)" />
      <div v-if="current === 'import'" class="wc-help">{{ $t('tx_Already_imported_enter_the_address_again_to_refresh_the_copy_08c3c') }}</div>
    </a-form-item>
    <div v-else-if="current === 'import'" class="wc-help wc-warn">
      {{ $t('tx_Saving_switches_this_company_from_its_imported_website_to_th_6499f') }}
    </div>
  </div>
</template>

<script setup>
defineProps({
  mode: { type: String, default: 'default' },
  url: { type: String, default: '' },
  // The company's current mode (edit only) -- drives the hints.
  current: { type: String, default: null },
});
const emit = defineEmits(['update:mode', 'update:url']);
</script>

<style scoped>
.wc-option { display: flex; align-items: flex-start; white-space: normal; margin-bottom: 10px; }
.wc-help { font-size: 12px; opacity: 0.7; font-weight: normal; margin-top: 2px; }
.wc-warn { color: #d48806; opacity: 1; margin-top: 4px; }
</style>
