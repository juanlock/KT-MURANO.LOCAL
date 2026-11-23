<template>
  <!-- The tab's key figures in one row. Explanations live in the (i) popup, never on the board. A card can carry, Power BI
       style (2026-11-23), its change vs the period before (▲▼, green when it goes the good way) and a small trend line. -->
  <div class="ts">
    <div v-for="i in items" :key="i.label" class="ts-item">
      <component :is="LEVEL[i.level].icon" v-if="i.level" class="ts-level" :style="{ color: LEVEL[i.level].color }" />
      <div class="ts-text">
        <div class="ts-label">
          <span class="ts-name">{{ i.label }}</span>
          <a-tooltip v-if="i.help" :title="i.help"><InfoCircleOutlined class="ts-info" :style="i.warn ? { color: '#d48806', opacity: 1 } : null" /></a-tooltip>
        </div>
        <div class="ts-value">{{ i.value }}<span v-if="i.note" class="ts-note">{{ i.note }}</span></div>
        <a-tooltip v-if="i.delta != null && isFinite(i.delta)" :title="i.deltaHelp || ''">
          <div class="ts-delta" :style="{ color: deltaColor(i) }">
            <CaretUpFilled v-if="i.delta > 0" /><CaretDownFilled v-else-if="i.delta < 0" /><MinusOutlined v-else />
            {{ Math.abs(i.delta).toFixed(Math.abs(i.delta) < 10 ? 1 : 0) }}%
            <span class="ts-delta-lbl">{{ i.deltaLabel || (es ? 'vs anterior' : 'vs before') }}</span>
          </div>
        </a-tooltip>
      </div>
      <svg v-if="i.spark && i.spark.length > 1" class="ts-spark" viewBox="0 0 80 28" preserveAspectRatio="none" aria-hidden="true">
        <path :d="sparkArea(i.spark)" :fill="i.sparkColor || BLUE" fill-opacity="0.16" />
        <path :d="sparkLine(i.spark)" fill="none" :stroke="i.sparkColor || BLUE" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
        <circle :cx="80" :cy="sparkY(i.spark, i.spark.length - 1)" r="2.2" :fill="i.sparkColor || BLUE" />
      </svg>
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
import { BarChartOutlined, CheckCircleFilled, CloseCircleFilled, ExclamationCircleFilled, InfoCircleOutlined, TableOutlined, CaretUpFilled, CaretDownFilled, MinusOutlined } from '@ant-design/icons-vue';
import { useUiStore } from '../stores/ui';
import { BLUE, GOOD, BAD } from '../lib/transportCharts';

defineProps({ items: { type: Array, required: true }, asTable: Boolean, toggle: { type: Boolean, default: true } });
defineEmits(['update:asTable']);

const ui = useUiStore();
const es = computed(() => ui.locale === 'es');
const LEVEL = {
  ok: { icon: CheckCircleFilled, color: '#0ca30c' },
  warn: { icon: ExclamationCircleFilled, color: '#fab219' },
  bad: { icon: CloseCircleFilled, color: '#d03b3b' },
};
// Up is good for income / profit; for a cost (upIsGood: false) going up is bad.
const deltaColor = i => (i.delta === 0 ? 'inherit' : ((i.delta > 0) === (i.upIsGood !== false) ? GOOD : BAD));
// The trend: 80 x 28 box, 2 px margin top and bottom.
const sparkY = (v, k) => {
  const min = Math.min(...v);
  const max = Math.max(...v);
  return max === min ? 14 : 26 - ((v[k] - min) / (max - min)) * 24;
};
const sparkX = (v, k) => (k / (v.length - 1)) * 80;
const sparkLine = v => v.map((_, k) => `${k ? 'L' : 'M'}${sparkX(v, k).toFixed(1)},${sparkY(v, k).toFixed(1)}`).join(' ');
const sparkArea = v => `${sparkLine(v)} L80,28 L0,28 Z`;
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
.ts-delta { font-size: 11px; font-weight: 600; line-height: 1.2; white-space: nowrap; }
.ts-delta-lbl { font-weight: 400; opacity: 0.75; margin-left: 2px; color: var(--ant-color-text, inherit); }
.ts-spark { width: 72px; height: 28px; flex: none; overflow: visible; }
.ts-toggle { align-self: center; }
</style>
