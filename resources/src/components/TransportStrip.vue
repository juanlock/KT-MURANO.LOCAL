<template>
  <!-- The tab's key figures in one row. Explanations live in the (i) popup, never on the board. -->
  <div class="ts">
    <div v-for="i in items" :key="i.label" class="ts-item">
      <component :is="LEVEL[i.level].icon" v-if="i.level" class="ts-level" :style="{ color: LEVEL[i.level].color }" />
      <div class="ts-text">
        <div class="ts-label">
          <span class="ts-name">{{ i.label }}</span>
          <a-tooltip v-if="i.help" :title="i.help"><InfoCircleOutlined class="ts-info" :style="i.warn ? { color: '#d48806', opacity: 1 } : null" /></a-tooltip>
        </div>
        <div class="ts-value">{{ i.value }}<span v-if="i.note" class="ts-note">{{ i.note }}</span></div>
      </div>
    </div>
    <a-tooltip v-if="toggle" :title="asTable ? (es ? 'Ver gráficos' : 'Show charts') : (es ? 'Ver como tablas' : 'Show as tables')">
      <a-button class="ts-toggle" size="small" type="text" :aria-label="es ? 'Cambiar entre gráficos y tablas' : 'Switch between charts and tables'" @click="$emit('update:asTable', !asTable)">
        <template #icon><BarChartOutlined v-if="asTable" /><TableOutlined v-else /></template>
      </a-button>
    </a-tooltip>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { BarChartOutlined, CheckCircleFilled, CloseCircleFilled, ExclamationCircleFilled, InfoCircleOutlined, TableOutlined } from '@ant-design/icons-vue';
import { useUiStore } from '../stores/ui';

defineProps({ items: { type: Array, required: true }, asTable: Boolean, toggle: { type: Boolean, default: true } });
defineEmits(['update:asTable']);

const ui = useUiStore();
const es = computed(() => ui.locale === 'es');
const LEVEL = {
  ok: { icon: CheckCircleFilled, color: '#0ca30c' },
  warn: { icon: ExclamationCircleFilled, color: '#fab219' },
  bad: { icon: CloseCircleFilled, color: '#d03b3b' },
};
</script>

<style scoped>
.ts { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 8px; align-items: stretch; }
.ts-item { flex: 1 1 auto; min-width: 0; display: flex; align-items: center; gap: 8px; border: 1px solid rgba(128, 128, 128, 0.2); border-radius: 8px; padding: 5px 10px; }
.ts-level { font-size: 16px; }
.ts-text { min-width: 0; flex: 1; }
.ts-label { display: flex; align-items: center; gap: 4px; font-size: 11px; line-height: 1.3; }
.ts-name { opacity: 0.7; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ts-info { opacity: 0.45; font-size: 11px; cursor: help; }
.ts-value { font-size: 16px; font-weight: 700; line-height: 1.25; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ts-note { font-size: 11px; font-weight: 400; opacity: 0.7; margin-left: 6px; }
.ts-toggle { align-self: center; }
</style>
