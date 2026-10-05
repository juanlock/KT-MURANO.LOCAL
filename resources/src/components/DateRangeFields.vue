<template>
  <!-- "Start date [....] to [....]": the active date range as two editable
       dates. A preset fills them; changing either one turns the filter into
       a custom range (the preset buttons then show none active). -->
  <div class="drf">
    <span class="drf-label">{{ tf('start_date', 'Start date') }}</span>
    <a-date-picker
      :value="from" value-format="YYYY-MM-DD" class="drf-date" :bordered="false" :allow-clear="allowClear"
      :placeholder="tf('start_date', 'Start date')" :disabled="disabled"
      @change="v => set(v, to)"
    />
    <span class="drf-label">{{ tf('to', 'to') }}</span>
    <a-date-picker
      :value="to" value-format="YYYY-MM-DD" class="drf-date" :bordered="false" :allow-clear="allowClear"
      :placeholder="tf('end_date', 'End date')" :disabled="disabled"
      :disabled-date="d => !!from && d.format('YYYY-MM-DD') < from"
      @change="v => set(from, v)"
    />
  </div>
</template>

<script setup>
/**
 * v-model:value = ['YYYY-MM-DD', 'YYYY-MM-DD'] or null (no date filter).
 * @change(range) fires after update:value, once per edit.
 *
 * Editing one side only: the other side follows when it's empty or would
 * end up before the start, so the range is always complete and in order --
 * pages and APIs can keep reading range[0] / range[1].
 */
import { computed } from 'vue';
import { t as tf } from '../i18n';

const props = defineProps({
  value: { type: Array, default: null },
  // false where "no date filter" isn't an option (the range must stay set).
  allowClear: { type: Boolean, default: true },
  disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:value', 'change']);

const from = computed(() => (props.value && props.value[0]) || null);
const to = computed(() => (props.value && props.value[1]) || null);

function set(f, t) {
  f = f || null;
  t = t || null;
  let range;
  if (!f && !t) {
    range = null;
  } else {
    if (!f) f = t;
    if (!t || t < f) t = f;
    range = [f, t];
  }
  emit('update:value', range);
  emit('change', range);
}
</script>

<style scoped>
.drf {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 2px 8px;
  border: 1px solid #d9d9d9;
  border-radius: 6px;
  background: #fff;
  max-width: 100%;
}
.drf-label {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.55);
  white-space: nowrap;
}
.drf-date {
  flex: 1 1 125px;
  min-width: 112px;
  padding-left: 4px;
  padding-right: 4px;
}
@media (max-width: 767px) {
  .drf {
    display: flex;
    width: 100%;
  }
  .drf-date {
    min-width: 0;
  }
}
</style>
