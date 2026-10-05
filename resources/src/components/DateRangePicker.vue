<template>
  <!-- With time (show-time): the plain range picker + quick presets -- the
       Start/End dates box has no hours. -->
  <a-range-picker
    v-if="showTime"
    v-bind="$attrs" :value="value" :value-format="valueFormat" :allow-clear="allowClear" show-time :presets="timePresets"
    @update:value="v => emit('update:value', v)" @change="(...a) => emit('change', ...a)"
  />
  <!-- Everything else: the system's date filter (preset buttons + editable
       Start date ... to ... End date). -->
  <PresetRangeFilter
    v-else
    :value="asStrings" :allow-clear="allowClear"
    @update:value="onUpdate" @change="onChange"
  />
</template>

<script setup>
/**
 * The reports' date filter. Drop-in for the old a-range-picker wrapper:
 * same v-model and @change, so pages are unchanged -- the value keeps the
 * page's own type (dayjs pair, or 'YYYY-MM-DD' strings when the page sets
 * value-format). Renders PresetRangeFilter: the shared preset buttons +
 * the editable Start / End dates. allow-clear=false = the range is
 * required (no "All").
 */
import { computed } from 'vue';
import dayjs from 'dayjs';
import PresetRangeFilter from './PresetRangeFilter.vue';
import { t as tf } from '../i18n';

defineOptions({ inheritAttrs: false });

const props = defineProps({
  value: { type: Array, default: null },
  valueFormat: { type: String, default: undefined },
  allowClear: { type: Boolean, default: true },
  showTime: { type: [Boolean, Object], default: false },
});
const emit = defineEmits(['update:value', 'change']);

const ymd = v => (v ? dayjs(v).format('YYYY-MM-DD') : null);
const asStrings = computed(() => (props.value && (props.value[0] || props.value[1])
  ? [ymd(props.value[0]), ymd(props.value[1])]
  : null));

// Back to the page's own type: strings in its format, or dayjs (whole days).
function toPage(range) {
  if (!range) return null;
  if (props.valueFormat) return [dayjs(range[0]).format(props.valueFormat), dayjs(range[1]).format(props.valueFormat)];
  return [dayjs(range[0]).startOf('day'), dayjs(range[1]).endOf('day')];
}

let last = null;
function onUpdate(range) {
  last = toPage(range);
  emit('update:value', last);
}
function onChange() {
  emit('change', last, last ? last.map(ymd) : ['', '']);
}

const d = () => dayjs();
const timePresets = computed(() => [
  { label: tf('Today', 'Today'), value: [d().startOf('day'), d().endOf('day')] },
  { label: tf('Yesterday', 'Yesterday'), value: [d().subtract(1, 'day').startOf('day'), d().subtract(1, 'day').endOf('day')] },
  { label: tf('This_Week', 'This week'), value: [d().startOf('week'), d()] },
  { label: tf('This_Month', 'This month'), value: [d().startOf('month'), d()] },
  { label: tf('This_Year', 'This year'), value: [d().startOf('year'), d()] },
]);
</script>
