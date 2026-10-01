<template>
  <!-- Booking date + start / end time, picked visually: a strip of day buttons,
       then time-slot buttons for that day -- the same picker as the public
       "Make an appointment" form, so every place a booking is created or edited
       (Create/Edit Booking, calendar modal) works the same way.
       Staff can still book a time that is booked, past or off the grid: those
       are marked (and warned about), and "Other time" opens exact time inputs.
       End = start + the service's duration whenever start or service changes. -->
  <div class="bwf">
    <a-form-item :label="$t('Date') + ' *'" :name="dateName" class="bwf-item">
      <div class="bwf-daybar">
        <a-button shape="circle" size="small" :disabled="page <= minPage" @click="page--">
          <template #icon><LeftOutlined /></template>
        </a-button>
        <div class="bwf-days">
          <button
            v-for="d in days" :key="d.iso" type="button"
            class="bwf-day" :class="{ 'is-on': d.iso === date, 'is-closed': d.closed, 'is-today': d.iso === today }"
            :title="d.closed ? closedLabel : undefined"
            @click="pickDay(d.iso)"
          >
            <span class="bwf-dow">{{ d.dow }}</span>
            <span class="bwf-num">{{ d.num }}</span>
            <span class="bwf-mon">{{ d.closed ? closedLabel : d.mon }}</span>
          </button>
        </div>
        <a-button shape="circle" size="small" @click="page++">
          <template #icon><RightOutlined /></template>
        </a-button>
        <a-date-picker
          :value="date || undefined" value-format="YYYY-MM-DD" :allow-clear="false" class="bwf-jump"
          :placeholder="''" @update:value="pickDay"
        >
          <template #suffixIcon><CalendarOutlined /></template>
        </a-date-picker>
      </div>
    </a-form-item>

    <a-form-item :label="$t('Start_Time') + ' *'" :name="startName" class="bwf-item">
      <a-spin :spinning="loading" size="small">
        <div v-if="!date" class="bwf-empty">{{ $t('Date') }}…</div>
        <template v-else>
          <div v-for="g in groups" :key="g.key" class="bwf-group">
            <div class="bwf-group-title">{{ g.title }}</div>
            <div class="bwf-slots">
              <a-tooltip v-for="s in g.slots" :key="s.hm" :title="s.taken ? bookedLabel : (s.past ? pastLabel : undefined)">
                <button
                  type="button" class="bwf-slot"
                  :class="{ 'is-on': s.hm === start, 'is-taken': s.taken, 'is-past': s.past }"
                  @click="pickTime(s.hm)"
                >{{ s.label }}</button>
              </a-tooltip>
            </div>
          </div>
          <div v-if="!groups.length" class="bwf-empty">No times between opening and closing for this service.</div>
        </template>
      </a-spin>

      <!-- Summary + warnings for the chosen time -->
      <div v-if="date && start" class="bwf-picked">
        ✓ {{ longDate(date) }} · {{ label12(toMin(start)) }}<template v-if="end"> – {{ label12(toMin(end)) }}</template>
        <span v-if="duration" class="bwf-muted"> ({{ fmtDur(duration) }})</span>
      </div>
      <a-alert
        v-if="warning" type="warning" show-icon :message="warning"
        style="margin-top: 8px; padding: 4px 10px"
      />

      <a class="bwf-other" @click="showExact = !showExact">
        {{ showExact ? 'Hide exact times' : 'Other time…' }}
      </a>
      <a-row v-if="showExact" :gutter="12" style="margin-top: 8px">
        <a-col :xs="12" :md="8">
          <div class="bwf-muted">{{ $t('Start_Time') }}</div>
          <a-time-picker
            :value="start || undefined" value-format="HH:mm" format="HH:mm" :minute-step="minuteStep" style="width: 100%" :allow-clear="false"
            @update:value="pickTime"
          />
        </a-col>
        <a-col :xs="12" :md="8">
          <div class="bwf-muted">{{ $t('End_Time') }}</div>
          <a-time-picker
            :value="end || undefined" value-format="HH:mm" format="HH:mm" :minute-step="minuteStep" style="width: 100%"
            :status="endBeforeStart ? 'error' : undefined"
            @update:value="v => emit('update:end', v || '')"
          />
          <div v-if="endBeforeStart" class="bwf-err">End time must be after the start time</div>
        </a-col>
      </a-row>
      <div v-if="hint" class="bwf-muted" style="margin-top: 6px">{{ hint }}</div>
    </a-form-item>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import dayjs from 'dayjs';
import { LeftOutlined, RightOutlined, CalendarOutlined } from '@ant-design/icons-vue';
import http from '../lib/http';

const props = defineProps({
  date: { type: String, default: '' },
  start: { type: String, default: '' },
  end: { type: String, default: '' },
  // Selected service's duration in minutes (0 = none -> slots assume 60).
  duration: { type: Number, default: 0 },
  minuteStep: { type: Number, default: 5 },
  dateName: { type: String, default: 'booking_date' },
  startName: { type: String, default: 'booking_time' },
  // Booking being edited: its own time isn't shown as booked.
  excludeId: { type: [Number, String], default: null },
  // Chosen customer: the company whose hours/bookings apply when a SuperAdmin
  // has no company selected (bookings belong to their customer's company).
  customerId: { type: [Number, String], default: null },
});
const emit = defineEmits(['update:date', 'update:start', 'update:end']);

const closedLabel = 'Closed';
const bookedLabel = 'Already booked at this time';
const pastLabel = 'This time has passed';

/* ---------------- availability (GET bookings_availability) ---------------- */
const hours = ref({ open: '09:00', close: '19:00', slot: 30, closed: [], capacity: 1 });
const busy = ref({});
const today = ref(dayjs().format('YYYY-MM-DD'));
const nowMin = ref(dayjs().hour() * 60 + dayjs().minute());
const loading = ref(false);

/* ---------------- day strip: 7 days per page ---------------- */
const PAGE = 7;
// Page 0 starts today; earlier pages are allowed so past bookings can be edited.
const page = ref(0);
const minPage = computed(() => Math.min(0, pageOf(props.date)));
function pageOf(iso) {
  if (!iso) return 0;
  return Math.floor(dayjs(iso).diff(dayjs(today.value), 'day') / PAGE);
}
const pageStart = computed(() => dayjs(today.value).add(page.value * PAGE, 'day'));
const days = computed(() => Array.from({ length: PAGE }, (_, i) => {
  const d = pageStart.value.add(i, 'day');
  return {
    iso: d.format('YYYY-MM-DD'),
    dow: d.format('ddd'),
    num: d.date(),
    mon: d.format('MMM'),
    closed: hours.value.closed.includes(d.day()),
  };
}));

let seq = 0;
async function loadAvailability() {
  const from = pageStart.value.format('YYYY-MM-DD');
  // The selected day may sit outside the visible week (jumped via the calendar).
  const lo = props.date && props.date < from ? props.date : from;
  const hiWeek = pageStart.value.add(PAGE - 1, 'day').format('YYYY-MM-DD');
  const hi = props.date && props.date > hiWeek ? props.date : hiWeek;
  const mySeq = ++seq;
  loading.value = true;
  try {
    const data = await http.get('bookings_availability', {
      from: lo, to: hi,
      ...(props.excludeId ? { exclude_id: props.excludeId } : {}),
      ...(props.customerId ? { customer_id: props.customerId } : {}),
    });
    if (mySeq !== seq) return;
    if (data?.hours) hours.value = data.hours;
    busy.value = data?.busy || {};
    if (data?.today) today.value = data.today;
    if (typeof data?.now_min === 'number') nowMin.value = data.now_min;
  } catch (e) {
    // Picker still works with default hours; it just can't mark booked times.
  } finally {
    if (mySeq === seq) loading.value = false;
  }
}
watch([page, () => props.excludeId, () => props.customerId], loadAvailability, { immediate: true });
// Show the week holding the chosen date (form opened on an existing booking,
// a date picked from the calendar, or a calendar slot pre-filling it).
watch(() => props.date, (d) => {
  if (!d) return;
  const p = pageOf(d);
  if (p !== page.value) page.value = p; else loadAvailability();
}, { immediate: true });

/* ---------------- time slots ---------------- */
const toMin = hm => Number(String(hm).slice(0, 2)) * 60 + Number(String(hm).slice(3, 5));
const hm = m => `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`;
function label12(m) {
  const h = Math.floor(m / 60);
  return `${(h % 12) || 12}:${String(m % 60).padStart(2, '0')} ${h < 12 ? 'AM' : 'PM'}`;
}
function fmtDur(m) {
  const h = Math.floor(m / 60);
  const r = m % 60;
  return [h ? `${h} h` : '', r ? `${r} min` : ''].filter(Boolean).join(' ');
}
const longDate = iso => dayjs(iso).format('dddd, MMMM D');

// Most bookings running at one moment inside [from, to) -- same as
// App\Support\BookingAvailability::maxConcurrent().
function maxConcurrent(list, from, to) {
  const points = [from, ...list.filter(b => b[0] > from && b[0] < to).map(b => b[0])];
  return Math.max(0, ...points.map(p => list.filter(b => b[0] <= p && b[1] > p).length));
}
const dur = computed(() => props.duration || 60);
function isTaken(iso, t) {
  return maxConcurrent(busy.value[iso] || [], t, t + dur.value) >= hours.value.capacity;
}
function isPast(iso, t) {
  return iso < today.value || (iso === today.value && t <= nowMin.value);
}
const groups = computed(() => {
  if (!props.date) return [];
  const open = toMin(hours.value.open);
  const close = toMin(hours.value.close);
  const list = [];
  for (let t = open; t + dur.value <= close; t += hours.value.slot) {
    list.push({ min: t, hm: hm(t), label: label12(t), taken: isTaken(props.date, t), past: isPast(props.date, t) });
  }
  return [
    { key: 'morning', title: 'Morning', from: 0, to: 720 },
    { key: 'afternoon', title: 'Afternoon', from: 720, to: 1020 },
    { key: 'evening', title: 'Evening', from: 1020, to: 1440 },
  ].map(g => ({ ...g, slots: list.filter(s => s.min >= g.from && s.min < g.to) })).filter(g => g.slots.length);
});

/* ---------------- picking ---------------- */
function endFor(start, d) {
  if (!d || !/^\d{2}:\d{2}$/.test(start || '')) return null;
  return hm(Math.min(toMin(start) + d, 23 * 60 + 59));
}
function pickDay(iso) {
  if (iso) emit('update:date', iso);
}
function pickTime(v) {
  emit('update:start', v || '');
  const e = endFor(v, props.duration);
  if (e) emit('update:end', e);
}
// Picking another service re-times the end from the current start.
watch(() => props.duration, d => {
  const e = endFor(props.start, d);
  if (e) emit('update:end', e);
});

const onGrid = computed(() => groups.value.some(g => g.slots.some(s => s.hm === props.start)));
// Off-grid times (e.g. an imported or hand-entered booking) open the exact inputs.
const showExact = ref(false);
watch(() => props.start, s => { if (s && !onGrid.value) showExact.value = true; });

const endBeforeStart = computed(() => !!(props.end && props.start && props.end <= props.start));
const hint = computed(() => (props.duration ? `Duration ${fmtDur(props.duration)} — end time follows the service` : undefined));
const warning = computed(() => {
  if (!props.date || !props.start) return '';
  const t = toMin(props.start);
  const parts = [];
  if (hours.value.closed.includes(dayjs(props.date).day())) parts.push('the business is closed this day');
  if (isTaken(props.date, t)) parts.push('this time overlaps another booking');
  if (t < toMin(hours.value.open) || t + dur.value > toMin(hours.value.close)) parts.push('it is outside opening hours');
  return parts.length ? `Heads-up: ${parts.join('; ')}. You can still save it.` : '';
});
</script>

<style scoped>
.bwf-item { margin-bottom: 14px; }
.bwf-daybar { display: flex; align-items: center; gap: 6px; }
.bwf-days { flex: 1; min-width: 0; display: grid; grid-template-columns: repeat(7, 1fr); gap: 6px; }
.bwf-day {
  display: flex; flex-direction: column; align-items: center; padding: 6px 0 5px;
  border: 1px solid rgba(128, 128, 128, 0.3); border-radius: 10px; background: transparent;
  color: inherit; cursor: pointer; font: inherit; line-height: 1.2; min-width: 0;
}
.bwf-day:hover { border-color: #1677ff; }
.bwf-dow, .bwf-mon { font-size: 11px; opacity: 0.65; text-transform: uppercase; }
.bwf-num { font-size: 18px; font-weight: 700; }
.bwf-day.is-today .bwf-num { text-decoration: underline; text-underline-offset: 3px; }
.bwf-day.is-closed { opacity: 0.45; }
.bwf-day.is-on { background: #1677ff; border-color: #1677ff; color: #fff; opacity: 1; }
.bwf-day.is-on .bwf-dow, .bwf-day.is-on .bwf-mon { opacity: 0.9; }
.bwf-jump { width: 40px; flex: none; }
.bwf-jump :deep(input) { width: 0; padding: 0; }
.bwf-group { margin-bottom: 8px; }
.bwf-group-title { font-size: 12px; font-weight: 600; opacity: 0.65; margin-bottom: 4px; }
.bwf-slots { display: grid; grid-template-columns: repeat(auto-fill, minmax(84px, 1fr)); gap: 6px; }
.bwf-slot {
  padding: 6px 0; border: 1px solid rgba(128, 128, 128, 0.3); border-radius: 8px;
  background: transparent; color: inherit; cursor: pointer; font: 600 13px/1.2 inherit; font-family: inherit;
}
.bwf-slot:hover { border-color: #1677ff; }
.bwf-slot.is-past { opacity: 0.4; }
.bwf-slot.is-taken { opacity: 0.55; text-decoration: line-through; border-style: dashed; }
.bwf-slot.is-on { background: #1677ff; border-color: #1677ff; color: #fff; opacity: 1; text-decoration: none; }
.bwf-picked { margin-top: 6px; font-weight: 600; }
.bwf-muted { font-size: 12px; opacity: 0.65; font-weight: normal; }
.bwf-empty { font-size: 13px; opacity: 0.65; padding: 6px 0; }
.bwf-other { display: inline-block; margin-top: 6px; font-size: 12px; }
.bwf-err { color: #ff4d4f; font-size: 12px; margin-top: 2px; }
@media (max-width: 480px) {
  .bwf-num { font-size: 15px; }
  .bwf-mon { display: none; }
  .bwf-days { gap: 3px; }
}
</style>
