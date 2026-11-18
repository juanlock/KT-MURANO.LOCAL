<template>
  <!-- Quality control (user's rule, 2026-11-18): the complaints board on its own page, for the "Control de Calidad" role --
       the complaints counts and the forms, never the owner's money (the dashboard needs the dashboard permission). -->
  <div class="tq">
    <div class="tq-bar">
      <h2 class="tq-title">{{ L('Quality control · Complaints', 'Control de calidad · Reclamos') }}</h2>
      <PresetRangeFilter v-model:value="range" value-format="YYYY-MM-DD" allow-all />
      <span class="tq-sp" />
      <PermLink to="/forms" permission="forms_view" class="tq-link">{{ L('Forms', 'Formularios') }}</PermLink>
    </div>
    <TransportClaimsPanel :range="range" />
  </div>
</template>

<script setup>
import { ref } from 'vue';
import TransportClaimsPanel from '../../components/TransportClaimsPanel.vue';
import PresetRangeFilter from '../../components/PresetRangeFilter.vue';
import { useUiStore } from '../../stores/ui';

const ui = useUiStore();
const L = (en, es) => (String(ui.locale || 'es').startsWith('es') ? es : en);
const range = ref(null);
</script>

<style scoped>
.tq-bar { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-bottom: 10px; }
.tq-title { margin: 0; font-size: 18px; font-weight: 700; }
.tq-sp { flex: 1; }
.tq-link { font-weight: 600; }
</style>
