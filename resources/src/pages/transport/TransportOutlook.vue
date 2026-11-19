<template>
  <!-- "Mando operativo" on its own page (user's request, 2026-11-18): the predictive board for Operations, who has no access
       to the owner's dashboard. Same panel as the dashboard tab. -->
  <div class="to">
    <h2 class="to-title">{{ L('Operations outlook', 'Mando operativo') }}</h2>
    <TransportOutlookPanel />
  </div>
</template>

<script setup>
import TransportOutlookPanel from '../../components/TransportOutlookPanel.vue';
import { useUiStore } from '../../stores/ui';

const ui = useUiStore();
const L = (en, es) => (String(ui.locale || 'es').startsWith('es') ? es : en);
</script>

<style scoped>
.to-title { margin: 0 0 10px; font-size: 18px; font-weight: 700; }
</style>
