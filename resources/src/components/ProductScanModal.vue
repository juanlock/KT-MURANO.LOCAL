<template>
  <span class="product-scan">
    <a-tooltip :title="$t('Scan')">
      <a-button :disabled="disabled" @click="openScan">
        <template #icon><ScanOutlined /></template>
      </a-button>
    </a-tooltip>

    <!-- destroy-on-close remounts the scanner each time so the camera stream is
         re-acquired on open and released on close (the compat component clears
         itself in beforeUnmount). -->
    <a-modal
      v-model:open="open"
      :title="$t('Scan')"
      :footer="null"
      :width="440"
      centered
      destroy-on-close
    >
      <div v-if="!hasScanner" style="padding: 16px 0">
        <a-alert type="warning" show-icon :message="tf('Scanner_unavailable', 'Barcode scanner is not available on this device.')" />
      </div>
      <QrcodeScanner v-else :fps="10" :qrbox="250" @result="onResult" />
    </a-modal>
  </span>
</template>

<script setup>
/**
 * Camera barcode scanner shared by the sale/quotation/purchase/adjustment/
 * damage/transfer forms — the Vue 3 port of legacy's scan.png icon + "Barcode
 * Scanner" modal. Reuses the html5-qrcode global (loaded by next.blade.php);
 * emits `scan` with the decoded text and closes, leaving the matching/adding to
 * the parent (which mirrors each form's legacy search()).
 */
import { ref } from 'vue';
import { ScanOutlined } from '@ant-design/icons-vue';
import { t as tf } from '../i18n';
import QrcodeScanner from '../pos-compat/QrcodeScanner';

defineProps({
  disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['scan']);

const open = ref(false);
const hasScanner = ref(true);

function openScan() {
  // qrcode.js is loaded before the app module, but guard anyway.
  hasScanner.value = typeof window !== 'undefined' && !!window.Html5QrcodeScanner;
  open.value = true;
}

function onResult(code) {
  emit('scan', code);
  open.value = false;
}
</script>
