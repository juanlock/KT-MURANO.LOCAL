<template>
  <!-- A company's website: its own imported site (original design) or the
       default online store with the standard pages (HRM -> Companies). -->
  <div class="website-choice">
    <a-radio-group :value="mode" style="width: 100%" @update:value="v => emit('update:mode', v)">
      <a-radio value="default" class="wc-option">
        <strong>Use the default online store</strong>
        <div class="wc-help">
          The company's domain opens the online store, with standard pages (About Us, Contact Us,
          Shipping &amp; Returns, Privacy Policy, FAQ) filled with its name and contact details — all editable in
          Store → Pages.
        </div>
      </a-radio>
      <a-radio value="import" class="wc-option">
        <strong>Import the existing website</strong>
        <div class="wc-help">
          Copies the company's current website (its pages, design and images) and shows it as the Home page
          exactly as it looks today. If it can't be read, the default online store is used instead.
        </div>
      </a-radio>
    </a-radio-group>
    <a-form-item v-if="mode === 'import'" label="Current website address" style="margin-top: 8px">
      <a-input :value="url" placeholder="https://www.example.com" @update:value="v => emit('update:url', v)" />
      <div v-if="current === 'import'" class="wc-help">Already imported — enter the address again to refresh the copy.</div>
    </a-form-item>
    <div v-else-if="current === 'import'" class="wc-help wc-warn">
      Saving switches this company from its imported website to the default online store (the imported copy is kept and can be switched back in Store → Pages).
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
