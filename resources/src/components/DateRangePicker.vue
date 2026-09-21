<template>
  <!-- Single root: props/events/v-model fall through to the picker automatically. -->
  <a-range-picker :presets="presets">
    <template v-for="(_, name) in $slots" #[name]="slotProps">
      <slot :name="name" v-bind="slotProps" />
    </template>
  </a-range-picker>
</template>

<script setup>
/**
 * a-range-picker with the standard quick-range presets (Today, This week,
 * Last month, …) baked in. Drop-in replacement: every prop/event/slot is
 * forwarded, so `v-model:value`, `allow-clear`, `@change`… work unchanged.
 */
import { computed } from 'vue';
import dayjs from 'dayjs';
import { t as tf } from '../i18n';

const d = () => dayjs();

// Recomputed per open state isn't needed — a computed on locale keeps labels
// live on language switch; values are rebuilt on each re-render anyway.
const presets = computed(() => [
  { label: tf('Today', 'Today'), value: [d().startOf('day'), d()] },
  { label: tf('Yesterday', 'Yesterday'), value: [d().subtract(1, 'day').startOf('day'), d().subtract(1, 'day').endOf('day')] },
  { label: tf('This_Week', 'This week'), value: [d().startOf('week'), d()] },
  { label: tf('Last_Week', 'Last week'), value: [d().subtract(1, 'week').startOf('week'), d().subtract(1, 'week').endOf('week')] },
  { label: tf('Last_7_Days', 'Last 7 days'), value: [d().subtract(6, 'day').startOf('day'), d()] },
  { label: tf('This_Month', 'This month'), value: [d().startOf('month'), d()] },
  { label: tf('Last_Month', 'Last month'), value: [d().subtract(1, 'month').startOf('month'), d().subtract(1, 'month').endOf('month')] },
  { label: tf('Last_30_Days', 'Last 30 days'), value: [d().subtract(29, 'day').startOf('day'), d()] },
  { label: tf('This_Year', 'This year'), value: [d().startOf('year'), d()] },
]);
</script>
