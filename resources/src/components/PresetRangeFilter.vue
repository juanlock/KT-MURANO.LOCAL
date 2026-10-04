<template>
  <!-- Date filter: the shared preset buttons (All, This Year ... Last Year,
       lib/datePresets.js -- same as Sales / Purchases / Dashboard) plus the
       calendar for an exact range. Phones get the presets as a dropdown.
       Double-click a preset = this user's default for this page (★). -->
  <div class="prf">
    <div class="prf-buttons" :title="DEFAULT_HINT" @dblclick="toggleDefault(active)">
      <a-radio-group :value="active" button-style="solid" @change="e => pick(e.target.value)">
        <a-radio-button v-for="p in presets" :key="p.value" :value="p.value">
          {{ $t(p.labelKey) }}<StarFilled v-if="savedDefault === p.value" class="prf-star" />
        </a-radio-button>
      </a-radio-group>
    </div>
    <div class="prf-select" :title="DEFAULT_HINT" @dblclick="toggleDefault(active)">
      <a-select
        :value="active ?? undefined" style="width: 100%" placeholder="Custom range"
        :options="presets.map(p => ({ value: p.value, label: savedDefault === p.value ? `${$t(p.labelKey)} ★` : $t(p.labelKey) }))"
        @change="pick"
      />
    </div>
    <a-range-picker
      v-bind="$attrs"
      :value="value"
      value-format="YYYY-MM-DD"
      @update:value="v => emit('update:value', v)"
      @change="(...args) => emit('change', ...args)"
    />
  </div>
</template>

<script setup>
/**
 * Drop-in for `<a-range-picker v-model:value="range" value-format="YYYY-MM-DD"
 * @change="reload">`: same v-model (['YYYY-MM-DD', 'YYYY-MM-DD'] or null =
 * no date filter) and the same @change, plus the preset buttons in front.
 * class/style/other attrs go to the calendar (pages size it with e.g.
 * .tb-range), not to the whole bar.
 *
 * The active button is derived from the value, so a page that resets its
 * filters (range = null) shows "All" again, and a hand-picked calendar range
 * that matches no preset leaves every button off.
 *
 * Default: double-click a preset -> saved on the user's account
 * (users.ui_preferences, key date_filter_default|<route>[|prefKey]) and
 * applied when the page opens; double-click it again to clear.
 */
import { computed, ref, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message } from 'ant-design-vue';
import { StarFilled } from '@ant-design/icons-vue';
import { DATE_PRESETS, presetDates } from '../lib/datePresets';
import { getUserPref, setUserPref } from '../lib/userPrefs';
import { t } from '../i18n';

defineOptions({ inheritAttrs: false });

const props = defineProps({
  value: { type: Array, default: null },
  // false where an empty range doesn't mean "everything" (e.g. a dashboard
  // that falls back to the last 30 days) -- hides the All button.
  allowAll: { type: Boolean, default: true },
  // Tells several date filters on the same page apart for their defaults.
  prefKey: { type: String, default: '' },
});
const emit = defineEmits(['update:value', 'change']);

const DEFAULT_HINT = 'Double-click a preset to make it your default (again to clear)';
const presets = computed(() => DATE_PRESETS.filter(p => props.allowAll || p.value !== 'all'));

const active = computed(() => {
  const v = props.value;
  if (!v || !v.length || (!v[0] && !v[1])) return props.allowAll ? 'all' : null;
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

// ---------------- per-user default ----------------

const route = useRoute();
const PREF_KEY = `date_filter_default|${String(route.name || route.path)}${props.prefKey ? `|${props.prefKey}` : ''}`;
const savedDefault = ref(getUserPref(PREF_KEY) ?? null);

function toggleDefault(preset) {
  if (!preset) {
    message.info('Pick one of the preset buttons first, then double-click it.');
    return;
  }
  const label = t(DATE_PRESETS.find(p => p.value === preset)?.labelKey || preset);
  if (savedDefault.value === preset) {
    savedDefault.value = null;
    setUserPref(PREF_KEY, null);
    message.info(`${label}: no longer your default here`);
  } else {
    savedDefault.value = preset;
    setUserPref(PREF_KEY, preset);
    message.success(`${label} is now your default here`);
  }
}

// Open the page on the saved default. On mount (not during setup) so the
// page's own state exists; `change` makes pages that already fetched reload.
onMounted(() => {
  const d = savedDefault.value;
  if (d && presets.value.some(p => p.value === d) && d !== active.value) pick(d);
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

/* Phones: 8 buttons can't fit in a row -- the same choice as a dropdown,
   then the calendar, each full width. */
@media (max-width: 767px) {
  .prf {
    display: flex;
    width: 100%;
  }
  .prf-buttons {
    display: none;
  }
  .prf-select {
    display: block;
    width: 100%;
  }
  .prf > :deep(.ant-picker) {
    width: 100% !important;
  }
}
</style>
