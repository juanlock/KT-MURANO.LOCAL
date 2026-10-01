<template>
  <!-- Booking date + start / end time: one control set for every place a
       booking is created or edited (Create/Edit Booking, calendar modal), so
       they all pick date and time the same way. End = start + the service's
       duration whenever start or service changes. -->
  <a-row :gutter="12">
    <a-col :xs="24" :md="8">
      <a-form-item :label="$t('Date') + ' *'" :name="dateName">
        <a-date-picker
          :value="date" value-format="YYYY-MM-DD" format="ddd, MMM D, YYYY" style="width: 100%" :allow-clear="false"
          @update:value="v => emit('update:date', v)"
        />
      </a-form-item>
    </a-col>
    <a-col :xs="12" :md="8">
      <a-form-item :label="$t('Start_Time') + ' *'" :name="startName">
        <a-time-picker
          :value="start" value-format="HH:mm" format="HH:mm" :minute-step="minuteStep" style="width: 100%" :allow-clear="false"
          @update:value="onStart"
        />
      </a-form-item>
    </a-col>
    <a-col :xs="12" :md="8">
      <a-form-item :label="$t('End_Time')" :extra="hint" :validate-status="endBeforeStart ? 'error' : undefined"
        :help="endBeforeStart ? 'End time must be after the start time' : undefined">
        <a-time-picker
          :value="end" value-format="HH:mm" format="HH:mm" :minute-step="minuteStep" style="width: 100%"
          @update:value="v => emit('update:end', v || '')"
        />
      </a-form-item>
    </a-col>
  </a-row>
</template>

<script setup>
import { computed, watch } from 'vue';

const props = defineProps({
  date: { type: String, default: '' },
  start: { type: String, default: '' },
  end: { type: String, default: '' },
  // Selected service's duration in minutes (0 = none).
  duration: { type: Number, default: 0 },
  minuteStep: { type: Number, default: 5 },
  dateName: { type: String, default: 'booking_date' },
  startName: { type: String, default: 'booking_time' },
});
const emit = defineEmits(['update:date', 'update:start', 'update:end']);

function fmt(m) {
  const h = Math.floor(m / 60);
  const r = m % 60;
  return [h ? `${h} h` : '', r ? `${r} min` : ''].filter(Boolean).join(' ');
}
const hint = computed(() => (props.duration ? `Duration ${fmt(props.duration)} — end time follows the service` : undefined));
const endBeforeStart = computed(() => !!(props.end && props.start && props.end <= props.start));

function endFor(start, dur) {
  if (!dur || !/^\d{2}:\d{2}$/.test(start || '')) return null;
  const m = Math.min(Number(start.slice(0, 2)) * 60 + Number(start.slice(3, 5)) + dur, 23 * 60 + 59);
  return `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`;
}
function onStart(v) {
  emit('update:start', v || '');
  const e = endFor(v, props.duration);
  if (e) emit('update:end', e);
}
// Picking another service re-times the end from the current start.
watch(() => props.duration, d => {
  const e = endFor(props.start, d);
  if (e) emit('update:end', e);
});
</script>
