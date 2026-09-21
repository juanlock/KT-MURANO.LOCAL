<template>
  <div class="page">
    <PageHeader :title="$t('Calendar')" :breadcrumb="[$t('Meeting_Management'), $t('Calendar')]">
      <template #extra>
        <a-button type="primary" @click="$router.push('/meetings')">
          <template #icon><PlusOutlined /></template>
          {{ $t('New_Meeting') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small">
      <div class="cal-toolbar">
        <a-space>
          <a-button size="small" @click="prevMonth"><template #icon><LeftOutlined /></template></a-button>
          <a-button size="small" @click="goToday">{{ $t('Today') }}</a-button>
          <a-button size="small" @click="nextMonth"><template #icon><RightOutlined /></template></a-button>
        </a-space>
        <strong style="font-size: 16px">{{ monthLabel }}</strong>
        <span></span>
      </div>

      <a-spin :spinning="isLoading">
        <div class="cal-grid">
          <div v-for="d in weekdays" :key="d" class="cal-weekday">{{ d }}</div>
          <div
            v-for="(cell, idx) in cells" :key="idx"
            class="cal-cell"
            :class="{ 'cal-cell--out': !cell.inMonth, 'cal-cell--today': cell.isToday }"
          >
            <div class="cal-daynum">{{ cell.day }}</div>
            <div class="cal-events">
              <a-tooltip v-for="m in cell.meetings" :key="m.id" :title="m.title + ' · ' + shortTime(m.start_time)">
                <a class="cal-event" :class="'cal-ev--' + m.status" @click="$router.push(`/meeting/details/${m.id}`)">
                  <span class="cal-dot" :class="{ 'cal-dot--online': m.type === 'online' }"></span>
                  {{ shortTime(m.start_time) }} {{ m.title }}
                </a>
              </a-tooltip>
            </div>
          </div>
        </div>
      </a-spin>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Meeting calendar — custom month grid like legacy (weeks start Sunday via
 * toLocaleString weekday order of the runtime locale — legacy used
 * moment.weekdaysShort()). Events: GET meeting/calendar?from&to where
 * from/to span the visible grid (month padded to full weeks).
 */
import { ref, computed, onMounted } from 'vue';
import { PlusOutlined, LeftOutlined, RightOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const isLoading = ref(true);
const cursor = ref(startOfMonth(new Date()));
const meetings = ref([]);

function startOfMonth(d) { return new Date(d.getFullYear(), d.getMonth(), 1); }
function fmt(d) {
  const p = n => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
}
function gridStart() {
  const d = new Date(cursor.value);
  d.setDate(1 - d.getDay());
  return d;
}
function gridEnd() {
  const last = new Date(cursor.value.getFullYear(), cursor.value.getMonth() + 1, 0);
  const d = new Date(last);
  d.setDate(last.getDate() + (6 - last.getDay()));
  return d;
}

const monthLabel = computed(() =>
  cursor.value.toLocaleString(undefined, { month: 'long', year: 'numeric' }));

const weekdays = computed(() => {
  const base = gridStart();
  return Array.from({ length: 7 }, (_, i) => {
    const d = new Date(base);
    d.setDate(base.getDate() + i);
    return d.toLocaleString(undefined, { weekday: 'short' });
  });
});

const cells = computed(() => {
  const todayStr = fmt(new Date());
  const out = [];
  const day = gridStart();
  const end = gridEnd();
  while (day <= end) {
    const dateStr = fmt(day);
    out.push({
      day: day.getDate(),
      inMonth: day.getMonth() === cursor.value.getMonth(),
      isToday: dateStr === todayStr,
      meetings: meetings.value.filter(m => String(m.meeting_date).substring(0, 10) === dateStr),
    });
    day.setDate(day.getDate() + 1);
  }
  return out;
});

function shortTime(x) { return x ? String(x).substring(0, 5) : ''; }

async function fetchMeetings() {
  isLoading.value = true;
  try {
    const data = await http.get('meeting/calendar', { from: fmt(gridStart()), to: fmt(gridEnd()) });
    meetings.value = data.meetings || [];
  } catch (e) {
    meetings.value = [];
  }
  isLoading.value = false;
}
function prevMonth() {
  cursor.value = new Date(cursor.value.getFullYear(), cursor.value.getMonth() - 1, 1);
  fetchMeetings();
}
function nextMonth() {
  cursor.value = new Date(cursor.value.getFullYear(), cursor.value.getMonth() + 1, 1);
  fetchMeetings();
}
function goToday() {
  cursor.value = startOfMonth(new Date());
  fetchMeetings();
}

onMounted(fetchMeetings);
</script>

<style scoped>
.cal-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 16px;
}
.cal-grid {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 4px;
}
.cal-weekday {
  text-align: center;
  font-size: 12px;
  font-weight: 700;
  color: rgba(0, 0, 0, 0.45);
  padding: 6px 0;
  text-transform: uppercase;
}
.cal-cell {
  min-height: 104px;
  border: 1px solid rgba(5, 5, 5, 0.06);
  border-radius: 8px;
  padding: 4px 5px;
  overflow: hidden;
}
.cal-cell--out {
  background: rgba(0, 0, 0, 0.02);
}
.cal-cell--out .cal-daynum {
  color: rgba(0, 0, 0, 0.25);
}
.cal-cell--today {
  border-color: #4361ee;
  box-shadow: inset 0 0 0 1px #4361ee;
}
.cal-daynum {
  font-size: 12px;
  font-weight: 600;
  color: rgba(0, 0, 0, 0.65);
  text-align: right;
}
.cal-events {
  display: flex;
  flex-direction: column;
  gap: 2px;
  margin-top: 2px;
}
.cal-event {
  font-size: 11px;
  padding: 2px 5px;
  border-radius: 5px;
  cursor: pointer;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  background: #eef1ff;
  color: #3b4cca;
  display: flex;
  align-items: center;
  gap: 4px;
}
.cal-ev--completed { background: #e9fbef; color: #16a34a; }
.cal-ev--cancelled { background: #fde8e8; color: #dc2626; text-decoration: line-through; }
.cal-ev--ongoing { background: #fff5e5; color: #b45309; }
.cal-dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: #4361ee;
  flex-shrink: 0;
}
.cal-dot--online { background: #06b6d4; }
</style>
