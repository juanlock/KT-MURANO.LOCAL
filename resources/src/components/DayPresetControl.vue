<template>
  <!-- "Día" (user's rule, 2026-11-24): part of the standard date filter, between the Start/End dates and "All". A click
       opens a calendar to pick one day; once a day is picked, ‹ and › on its sides go to the day before / after.
       Double-click = the user's default (★), like the other date buttons; the arrows never trigger it. -->
  <span class="dpc" :title="$t('tx_Double_click_a_date_button_to_make_it_your_default_on_every__9834a')" @dblclick="toggleDatePreset('day')">
    <a-button v-if="active || single" :size="size" class="dpc-arrow" :disabled="disabled" :title="L('Previous day', 'Día anterior')" @click="move(-1)" @dblclick.stop>
      <LeftOutlined />
    </a-button>
    <a-popover v-model:open="open" trigger="click" placement="bottomLeft" overlay-class-name="dpc-pop">
      <template #content>
        <div class="dpc-cal">
          <!-- Its own shown month (2026-11-25, the client: changing Nov to Oct did nothing -- the calendar was bound to the
               picked day, so it snapped back). It follows the month / year chosen in its header. -->
          <!-- The header's month / year lists open inside the popover: outside it, a click on them closed the popover. -->
          <a-config-provider :get-popup-container="calContainer">
            <a-calendar :fullscreen="false" :value="viewValue" @select="onSelect" @panel-change="d => (viewValue = d)" @change="d => (viewValue = d)" />
          </a-config-provider>
          <div class="dpc-foot">
            <a-button size="small" type="link" @click="pickDate(today)">{{ $t('Today') }}</a-button>
          </div>
        </div>
      </template>
      <a-button :size="size" :type="active ? 'primary' : 'default'" :disabled="disabled" class="dpc-btn">
        <CalendarOutlined />
        <span>{{ $t('Day') }}<template v-if="active">: <b>{{ date(pickedDay) }}</b></template></span>
        <StarFilled v-if="savedDatePreset === 'day'" class="dpc-star" />
      </a-button>
    </a-popover>
    <a-button v-if="active || single" :size="size" class="dpc-arrow" :disabled="disabled" :title="L('Next day', 'Día siguiente')" @click="move(1)" @dblclick.stop>
      <RightOutlined />
    </a-button>
  </span>
</template>

<script setup>
/**
 * Emits `pick` (the picked day is already in lib/datePresets' pickedDay) when a day is chosen on the calendar or moved
 * with the arrows; the parent then selects the "day" preset and reloads, so presetRange('day') returns that day.
 * `active` = the filter is on one day picked this way.
 */
import { ref, watch } from 'vue';
import dayjs from 'dayjs';
import { LeftOutlined, RightOutlined, CalendarOutlined, StarFilled } from '@ant-design/icons-vue';
import { pickedDay, shiftPickedDay } from '../lib/datePresets';
import { savedDatePreset, toggleDatePreset } from '../lib/datePresetDefault';
import { useFormat } from '../composables/useFormat';
import { i18n } from '../i18n';

const props = defineProps({
  active: { type: Boolean, default: false },
  // The filter is on one day another way ("Hoy"): the arrows show too and move from that day.
  single: { type: Boolean, default: false },
  size: { type: String, default: undefined },
  disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['pick']);
const { date } = useFormat();
const L = (en, es) => (String(i18n.global.locale.value || 'en').startsWith('es') ? es : en);
const open = ref(false);
const today = dayjs().format('YYYY-MM-DD');
const viewValue = ref(dayjs(pickedDay.value || today));
const calContainer = trigger => trigger?.closest?.('.dpc-cal') || document.body;
// Each time it opens, it starts on the picked day's month.
watch(open, o => { if (o) viewValue.value = dayjs(pickedDay.value || today); });

function pickDate(d) {
  pickedDay.value = d;
  open.value = false;
  emit('pick', d);
}
// The calendar also fires select when its month / year changes: only a day closes it.
function onSelect(d, info) {
  if (info && info.source && info.source !== 'date') {
    viewValue.value = d;
    return;
  }
  pickDate(d.format('YYYY-MM-DD'));
}
function move(n) {
  if (!props.active) pickedDay.value = today;
  emit('pick', shiftPickedDay(n));
}
</script>

<style scoped>
.dpc { display: inline-flex; align-items: center; gap: 2px; }
.dpc-btn { display: inline-flex; align-items: center; gap: 4px; }
.dpc-arrow { padding: 0 6px; }
.dpc-star { font-size: 10px; color: #faad14; }
.dpc-cal { width: 290px; position: relative; }
.dpc-foot { display: flex; justify-content: flex-end; border-top: 1px solid rgba(128, 128, 128, 0.15); }
</style>
