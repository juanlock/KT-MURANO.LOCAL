<template>
  <!-- The shared date preset buttons (All, This Year ... Last Year,
       lib/datePresets.js). Phones get the same choice as a dropdown -- 8
       buttons in a row overlapped the other filters. Double-click a preset =
       the user's default date range on every page (★,
       lib/datePresetDefault.js). Kept in the presets' own order, not
       alphabetical (a time range reads better in date order). -->
  <div class="dpb">
    <div class="dpb-buttons" :title="$t('tx_Double_click_a_date_button_to_make_it_your_default_on_every__9834a')" @dblclick="toggleDatePreset(value)">
      <a-radio-group :value="value" button-style="solid" :disabled="disabled" @change="e => pick(e.target.value)">
        <a-radio-button v-for="p in DATE_PRESETS" :key="p.value" :value="p.value">
          {{ $t(p.labelKey) }}<StarFilled v-if="savedDatePreset === p.value" class="dpb-star" />
        </a-radio-button>
      </a-radio-group>
    </div>
    <div class="dpb-select" :title="$t('tx_Double_click_a_date_button_to_make_it_your_default_on_every__9834a')" @dblclick="toggleDatePreset(value)">
      <a-select
        :value="value ?? undefined" style="width: 100%" :disabled="disabled" :placeholder="$t('CustomRange')"
        :options="DATE_PRESETS.map(p => ({ value: p.value, label: savedDatePreset === p.value ? `${$t(p.labelKey)} ★` : $t(p.labelKey) }))"
        @change="pick"
      />
    </div>
  </div>
</template>

<script setup>
/**
 * v-model:value = the active preset key ('today', 'month'...; null when the
 * page shows a hand-picked date instead). @change(preset) fires on a pick,
 * like the a-radio-group it replaces -- pages keep their own applyPreset().
 * Pages start on startPreset(<their own default>) from lib/datePresetDefault.
 */
import { StarFilled } from '@ant-design/icons-vue';
import { DATE_PRESETS } from '../lib/datePresets';
import { savedDatePreset, toggleDatePreset } from '../lib/datePresetDefault';

defineProps({
  value: { type: String, default: null },
  disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:value', 'change']);

function pick(preset) {
  emit('update:value', preset);
  emit('change', preset);
}
</script>

<style scoped>
.dpb {
  display: inline-block;
  max-width: 100%;
}
.dpb-select {
  display: none;
}
.dpb-star {
  font-size: 10px;
  margin-left: 4px;
  color: #faad14;
}
@media (max-width: 767px) {
  /* min-width: inside an a-space row the item is only as wide as its
     content, so 100% alone could leave the dropdown tiny. */
  .dpb {
    display: block;
    width: 100%;
    min-width: 220px;
  }
  .dpb-buttons {
    display: none;
  }
  .dpb-select {
    display: block;
    width: 100%;
  }
}
</style>
