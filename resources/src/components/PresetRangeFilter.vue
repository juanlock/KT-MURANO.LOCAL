<template>
  <!-- Date filter: the shared preset buttons (All, This Year ... Last Year,
       lib/datePresets.js -- same as Sales / Purchases / Dashboard) plus the
       editable Start date ... to ... End date (DateRangeFields): a preset
       fills them, editing either one makes it a custom range. Phones get
       the presets as a dropdown.
       Double-click a preset = this user's default date range on every page
       with these buttons (★, lib/datePresetDefault.js). `compact`: the
       presets as a dropdown on every screen, for narrow filter columns. -->
  <div class="prf" :class="{ 'prf--compact': compact }">
    <div class="prf-buttons" :title="DATE_DEFAULT_HINT" @dblclick="toggleDatePreset(active)">
      <a-radio-group :value="active" button-style="solid" @change="e => pick(e.target.value)">
        <a-radio-button v-for="p in presets" :key="p.value" :value="p.value">
          {{ $t(p.labelKey) }}<StarFilled v-if="savedDatePreset === p.value" class="prf-star" />
        </a-radio-button>
      </a-radio-group>
    </div>
    <div class="prf-select" :title="DATE_DEFAULT_HINT" @dblclick="toggleDatePreset(active)">
      <a-select
        :value="active ?? undefined" style="width: 100%" placeholder="Custom range"
        :options="presets.map(p => ({ value: p.value, label: savedDatePreset === p.value ? `${$t(p.labelKey)} ★` : $t(p.labelKey) }))"
        @change="pick"
      />
    </div>
    <DateRangeFields
      v-bind="fieldAttrs"
      :value="value"
      :allow-clear="canAll"
      @update:value="v => emit('update:value', v)"
      @change="v => emit('change', v)"
    />
  </div>
</template>

<script setup>
/**
 * Drop-in for `<a-range-picker v-model:value="range" value-format="YYYY-MM-DD"
 * @change="reload">`: same v-model (['YYYY-MM-DD', 'YYYY-MM-DD'] or null =
 * no date filter) and the same @change, plus the preset buttons in front.
 * Other attrs go to the Start/End dates box -- except class/style: pages
 * sized the old one-input calendar (.tb-range { width: 240px }), which
 * would squeeze the two dates; the box sizes itself.
 *
 * The active button is derived from the value, so a page that resets its
 * filters (range = null) shows "All" again, and a hand-picked calendar range
 * that matches no preset leaves every button off.
 *
 * Default: double-click a preset -> the user's default date range on every
 * page with the shared date buttons (lib/datePresetDefault.js), applied when
 * the page opens; double-click it again to clear.
 */
import { computed, onMounted, useAttrs } from 'vue';
import { StarFilled } from '@ant-design/icons-vue';
import DateRangeFields from './DateRangeFields.vue';
import { DATE_PRESETS, presetDates } from '../lib/datePresets';
import { DATE_DEFAULT_HINT, savedDatePreset, startPreset, toggleDatePreset } from '../lib/datePresetDefault';

defineOptions({ inheritAttrs: false });

const props = defineProps({
  value: { type: Array, default: null },
  // false where an empty range doesn't mean "everything" (e.g. a dashboard
  // that falls back to the last 30 days) -- hides the All button.
  allowAll: { type: Boolean, default: true },
  // a-range-picker leftovers some pages still pass: allow-clear=false keeps
  // the dates required; value-format is always YYYY-MM-DD here.
  allowClear: { type: Boolean, default: true },
  valueFormat: { type: String, default: 'YYYY-MM-DD' },
  compact: { type: Boolean, default: false },
  // false when the page opens on a range given to it (e.g. a link with
  // ?from=&to=): the user's default preset must not replace it.
  applyDefault: { type: Boolean, default: true },
});
const emit = defineEmits(['update:value', 'change']);

const attrs = useAttrs();
const fieldAttrs = computed(() => {
  const { class: _c, style: _s, ...rest } = attrs;
  return rest;
});

// "All" = no range (null); off when the page needs the dates set.
const canAll = computed(() => props.allowAll && props.allowClear);
const presets = computed(() => DATE_PRESETS.filter(p => canAll.value || p.value !== 'all'));

const active = computed(() => {
  const v = props.value;
  if (!v || !v.length || (!v[0] && !v[1])) return canAll.value ? 'all' : null;
  const hit = presets.value.find(p => {
    const d = presetDates(p.value);
    return p.value !== 'all' && d.from === v[0] && d.to === v[1];
  });
  return hit ? hit.value : null;
});

function rangeOf(preset) {
  if (preset === 'all') return null;
  const d = presetDates(preset);
  return [d.from, d.to];
}

function pick(preset) {
  if (!preset) return;
  const range = rangeOf(preset);
  emit('update:value', range);
  emit('change', range);
}

// Open the page on the user's default date range. On mount (not during
// setup) so the page's own state exists; `change` makes pages that already
// fetched reload.
onMounted(() => {
  if (!props.applyDefault) return;
  const d = startPreset(null, presets.value.map(p => p.value));
  if (d && d !== active.value) pick(d);
});
</script>

<style scoped>
.prf {
  display: inline-flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  max-width: 100%;
}
.prf-select {
  display: none;
}
.prf-star {
  font-size: 10px;
  margin-left: 4px;
  color: #faad14;
}

/* Compact (narrow filter columns, report toolbars): the presets as a
   dropdown in front of the dates, wrapping as one block. */
.prf--compact {
  display: flex;
  width: 100%;
}
.prf--compact .prf-buttons {
  display: none;
}
.prf--compact .prf-select {
  display: block;
  flex: 0 0 140px;
}
.prf--compact > .drf {
  flex: 1 1 260px;
}

/* Phones: 8 buttons can't fit in a row -- the same choice as a dropdown,
   then the dates, each full width. */
@media (max-width: 767px) {
  .prf {
    display: flex;
    width: 100%;
    min-width: 220px; /* inside an a-space row 100% alone can collapse */
  }
  .prf-buttons {
    display: none;
  }
  .prf-select,
  .prf--compact .prf-select {
    display: block;
    flex: 1 1 100%;
    width: 100%;
  }
  .prf > .drf {
    width: 100% !important;
  }
}
</style>
