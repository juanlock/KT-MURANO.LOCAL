<template>
  <!-- When a service job happens: one day, several days (a range), or -- when
       creating from a sale -- several separate dates or a weekly / monthly
       repeat (one job each). The time is optional: without it the job is an
       all-day task on its day(s) and shows as that day's task on the Service
       Jobs list. -->
  <div class="sjw">
    <a-radio-group v-model:value="value.mode" size="small" button-style="solid" style="margin-bottom: 8px">
      <a-radio-button value="day">One day</a-radio-button>
      <a-radio-button value="range">Several days</a-radio-button>
      <a-radio-button v-if="allowDates" value="dates">Specific dates</a-radio-button>
      <a-radio-button v-if="allowDates" value="repeat">Repeat</a-radio-button>
    </a-radio-group>

    <a-row :gutter="8">
      <template v-if="value.mode === 'day'">
        <a-col :xs="24" :md="10">
          <a-form-item label="Date" style="margin-bottom: 8px">
            <a-date-picker v-model:value="value.date" value-format="YYYY-MM-DD" style="width: 100%" />
          </a-form-item>
        </a-col>
      </template>
      <template v-else-if="value.mode === 'range'">
        <a-col :xs="12" :md="8">
          <a-form-item label="From" style="margin-bottom: 8px">
            <a-date-picker v-model:value="value.date" value-format="YYYY-MM-DD" style="width: 100%" />
          </a-form-item>
        </a-col>
        <a-col :xs="12" :md="8">
          <a-form-item label="To" style="margin-bottom: 8px">
            <a-date-picker v-model:value="value.end_date" value-format="YYYY-MM-DD" :disabled-date="d => value.date && d.format('YYYY-MM-DD') < value.date" style="width: 100%" />
          </a-form-item>
        </a-col>
      </template>
      <!-- Repeat: every N weeks / months from the first date, N times or until a date. -->
      <template v-else-if="value.mode === 'repeat'">
        <a-col :xs="12" :md="6">
          <a-form-item label="First date" style="margin-bottom: 8px">
            <a-date-picker v-model:value="value.date" value-format="YYYY-MM-DD" style="width: 100%" />
          </a-form-item>
        </a-col>
        <a-col :xs="12" :md="6">
          <a-form-item label="Every" style="margin-bottom: 8px">
            <a-input-group compact>
              <a-input-number v-model:value="value.repeat_every" :min="1" :max="12" style="width: 40%" />
              <a-select v-model:value="value.repeat_unit" style="width: 60%" :options="[{ value: 'week', label: 'week(s)' }, { value: 'month', label: 'month(s)' }]" />
            </a-input-group>
          </a-form-item>
        </a-col>
        <a-col :xs="12" :md="6">
          <a-form-item label="How many times" style="margin-bottom: 8px">
            <a-input-number v-model:value="value.repeat_count" :min="1" :max="60" placeholder="e.g. 4" style="width: 100%" />
          </a-form-item>
        </a-col>
        <a-col :xs="12" :md="6">
          <a-form-item label="Or until" style="margin-bottom: 8px">
            <a-date-picker v-model:value="value.repeat_until" value-format="YYYY-MM-DD" :disabled-date="d => value.date && d.format('YYYY-MM-DD') < value.date" style="width: 100%" />
          </a-form-item>
        </a-col>
        <a-col :span="24">
          <div class="sjw-preview">
            <template v-if="repeatDates.length">
              <b>{{ repeatDates.length }} job{{ repeatDates.length === 1 ? '' : 's' }}:</b>
              <a-tag v-for="d in repeatDates.slice(0, 12)" :key="d" style="margin: 2px">{{ date(d) }}</a-tag>
              <span v-if="repeatDates.length > 12">… until {{ date(repeatDates[repeatDates.length - 1]) }}</span>
            </template>
            <span v-else>Pick the first date and how many times (or until when).</span>
          </div>
        </a-col>
      </template>
      <template v-else>
        <a-col :span="24">
          <a-form-item label="Dates (one job each)" style="margin-bottom: 8px">
            <a-space wrap>
              <a-tag v-for="d in sortedDates" :key="d" closable @close.prevent="removeDate(d)">{{ date(d) }}</a-tag>
              <a-date-picker :value="null" value-format="YYYY-MM-DD" placeholder="Add a date" size="small" @change="addDate" />
            </a-space>
          </a-form-item>
        </a-col>
      </template>

      <a-col :xs="12" :md="['dates', 'repeat'].includes(value.mode) ? 6 : (value.mode === 'range' ? 4 : 7)">
        <a-form-item label="From time" style="margin-bottom: 8px">
          <a-time-picker v-model:value="value.time" value-format="HH:mm" format="HH:mm" :minute-step="15" placeholder="All day" style="width: 100%" />
        </a-form-item>
      </a-col>
      <a-col :xs="12" :md="['dates', 'repeat'].includes(value.mode) ? 6 : (value.mode === 'range' ? 4 : 7)">
        <a-form-item label="To time" style="margin-bottom: 8px">
          <a-time-picker v-model:value="value.end_time" value-format="HH:mm" format="HH:mm" :minute-step="15" :disabled="!value.time" placeholder="Optional" style="width: 100%" />
        </a-form-item>
      </a-col>
    </a-row>
    <div class="sjw-help">
      <template v-if="!value.time">No time: an <b>all-day task</b> on {{ value.mode === 'range' ? 'each of those days' : (['dates', 'repeat'].includes(value.mode) ? 'each date' : 'that day') }} -- it shows in that day's list of jobs.</template>
      <template v-else-if="value.mode === 'range'">Starts {{ value.time }} on the first day{{ value.end_time ? `, ends ${value.end_time} on the last` : '' }}.</template>
      <template v-else>At {{ value.time }}{{ value.end_time ? ` – ${value.end_time}` : '' }}.</template>
    </div>
  </div>
</template>

<script>
import dayjs from 'dayjs';

/** Empty "When" value (mode day | range | dates | repeat; times optional). */
export function emptyWhen(dateStr = null) {
  return {
    mode: 'day', date: dateStr, end_date: null, dates: [], time: null, end_time: null,
    // Repeat: every N week(s) / month(s), N times or until a date.
    repeat_every: 1, repeat_unit: 'week', repeat_count: 4, repeat_until: null,
  };
}

/**
 * Repeat -> its dates: from the first date, every N weeks / months (a monthly
 * repeat keeps the day of the month, capped at the month's end), stopping at
 * repeat_count dates or repeat_until, max 60.
 */
export function repeatDatesOf(w) {
  if (!w?.date) return [];
  const count = Number(w.repeat_count) || 0;
  const until = w.repeat_until || null;
  if (!count && !until) return [];
  const every = Math.max(1, Number(w.repeat_every) || 1);
  const unit = w.repeat_unit === 'month' ? 'month' : 'week';
  const first = dayjs(w.date);
  const out = [];
  for (let i = 0; out.length < 60; i++) {
    const d = first.add(i * every, unit).format('YYYY-MM-DD');
    if (until && d > until) break;
    out.push(d);
    if (count && out.length >= count) break;
  }
  return out;
}

/** A saved job's schedule -> "When" value (Service Job edit). */
export function whenFromJob(job) {
  const start = job?.scheduled_date ? String(job.scheduled_date).replace('T', ' ') : '';
  const end = job?.scheduled_end_date ? String(job.scheduled_end_date).replace('T', ' ') : '';
  if (!start) return emptyWhen();
  const allDay = !!job.all_day;
  const d1 = start.slice(0, 10);
  const d2 = end ? end.slice(0, 10) : null;
  return {
    ...emptyWhen(),
    mode: d2 && d2 !== d1 ? 'range' : 'day',
    date: d1,
    end_date: d2 && d2 !== d1 ? d2 : null,
    time: allDay ? null : (start.slice(11, 16) || null),
    end_time: allDay || !end ? null : end.slice(11, 16),
  };
}

/**
 * "When" value -> API fields. day / range: scheduled_date, scheduled_end_date,
 * all_day. dates / repeat: { dates, time, end_time } (one job per date, server side).
 */
export function whenToSchedule(w) {
  if (!w) return {};
  if (w.mode === 'dates' || w.mode === 'repeat') {
    const dates = w.mode === 'repeat' ? repeatDatesOf(w) : [...(w.dates || [])];
    return { dates, time: w.time || null, end_time: w.time ? (w.end_time || null) : null };
  }
  if (!w.date) return { scheduled_date: null, scheduled_end_date: null, all_day: false };
  const allDay = !w.time;
  const lastDay = w.mode === 'range' && w.end_date ? w.end_date : w.date;
  return {
    scheduled_date: allDay ? w.date : `${w.date} ${w.time}`,
    scheduled_end_date: allDay
      ? (lastDay !== w.date ? lastDay : null)
      : (w.end_time ? `${lastDay} ${w.end_time}` : (lastDay !== w.date ? `${lastDay} ${w.time}` : null)),
    all_day: allDay,
  };
}
</script>

<script setup>
import { computed } from 'vue';
import { useFormat } from '../composables/useFormat';

const props = defineProps({
  // Edited in place: { mode, date, end_date, dates, time, end_time, repeat_* }.
  value: { type: Object, required: true },
  // "Specific dates" / "Repeat" (one job per date) -- only where several jobs can be made.
  allowDates: { type: Boolean, default: false },
});
const { date } = useFormat();

const sortedDates = computed(() => [...(props.value.dates || [])].sort());
const repeatDates = computed(() => repeatDatesOf(props.value));
function addDate(d) {
  if (!d) return;
  const list = props.value.dates || (props.value.dates = []);
  if (!list.includes(d)) list.push(d);
}
function removeDate(d) {
  props.value.dates = (props.value.dates || []).filter(x => x !== d);
}
</script>

<style scoped>
.sjw-help {
  font-size: 12px;
  opacity: 0.7;
}
.sjw-preview {
  font-size: 12px;
  margin-bottom: 8px;
}
</style>
