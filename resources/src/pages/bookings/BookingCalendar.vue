<template>
  <div class="page">
    <PageHeader :title="$t('Calendar_View')" :breadcrumb="[$t('Bookings'), $t('Calendar_View')]">
      <template #extra>
        <a-space wrap>
          <a-tooltip title="Or click an empty day / time slot on the calendar">
            <a-button type="primary" @click="newAppointment()">
              <template #icon><PlusOutlined /></template>
              New appointment
            </a-button>
          </a-tooltip>
          <!-- Staff photos: a staff member sees only their own appointments
               (their photo); an admin sees everyone and can click a photo to
               show only that person's appointments. -->
          <div v-if="!isAdmin && me" class="staff-me">
            <a-avatar :size="40" :src="me.avatar || undefined" class="staff-avatar staff-avatar--on">{{ initials(me.name) }}</a-avatar>
            <div class="staff-me__text">
              <div class="staff-me__name">{{ me.name }}</div>
              <div class="staff-me__sub">My appointments</div>
            </div>
          </div>
          <div v-else-if="isAdmin && staff.length" class="staff-strip">
            <a-tooltip title="Everyone">
              <a-avatar :size="36" class="staff-avatar" :class="{ 'staff-avatar--on': !staffFilter }" @click="setStaff(null)">All</a-avatar>
            </a-tooltip>
            <a-tooltip v-for="s in staff" :key="s.id" :title="s.name">
              <a-avatar
                :size="36" :src="s.avatar || undefined" class="staff-avatar"
                :class="{ 'staff-avatar--on': staffFilter === s.id }" @click="setStaff(staffFilter === s.id ? null : s.id)"
              >{{ initials(s.name) }}</a-avatar>
            </a-tooltip>
          </div>
        </a-space>
      </template>
    </PageHeader>

    <a-row :gutter="[16, 16]">
      <!-- Calendar (a bit narrower) + the appointments list next to it. -->
      <a-col :xs="24" :xl="16">
        <a-card class="bkc-card">
          <a-spin :spinning="isLoading">
            <!-- --bkc-primary = the app theme color (Appearance / customizer). -->
            <!-- Wrapper carries .bkc: FullCalendar puts its own .fc class on calendarEl itself. -->
            <div class="bkc" :style="{ '--bkc-primary': ui.primaryColor || '#6d28d9' }">
              <div ref="calendarEl" style="min-height: 420px"></div>
            </div>
          </a-spin>
        </a-card>
        <!-- Status summary of what the calendar shows; click = only that status. -->
        <div class="bkc-pills">
          <button
            v-for="(c, s) in STATUS_COLORS" :key="s" type="button" class="bkc-pill"
            :class="{ 'is-on': statusFilter === s, 'is-dim': statusFilter && statusFilter !== s }"
            :style="{ '--pill-bg': c.bg, '--pill-bd': c.border, '--pill-tx': c.text }" @click="toggleStatus(s)"
          >
            <span class="bkc-pill__dot"></span>
            <span class="bkc-pill__label">{{ statusLabel(s) }}</span>
            <b>{{ statusCounts[s] }}</b>
          </button>
          <a-button v-if="statusFilter" type="link" size="small" @click="toggleStatus(statusFilter)">Show all</a-button>
        </div>
      </a-col>
      <a-col :xs="24" :xl="8">
        <a-card size="small" class="appt-list-card">
          <template #title>
            <span>Appointments</span>
            <span class="appt-list-range">{{ listRangeLabel }}</span>
          </template>
          <a-segmented v-model:value="listPeriod" :options="LIST_PERIODS" block style="margin-bottom: 12px" @change="loadList" />
          <a-spin :spinning="listLoading">
            <div v-if="listGroups.length" class="appt-list">
              <div v-for="g in listGroups" :key="g.date" class="appt-day">
                <div class="appt-day__head">{{ g.label }} <span>{{ g.items.length }}</span></div>
                <div v-for="b in g.items" :key="b.id" class="appt-item" :style="{ borderLeftColor: (STATUS_COLORS[b.status] || STATUS_COLORS.pending).border }" @click="openBooking(b)">
                  <div class="appt-item__time">{{ fmtTime(b.booking_time) }}<span v-if="b.booking_end_time">{{ fmtTime(b.booking_end_time) }}</span></div>
                  <div class="appt-item__main">
                    <div class="appt-item__name">{{ b.customer_name || `Booking #${b.id}` }}</div>
                    <div class="appt-item__sub">{{ b.product_name || '—' }}</div>
                  </div>
                  <a-tooltip v-if="staffById[b.sales_person_id]" :title="staffById[b.sales_person_id].name">
                    <a-avatar :size="26" :src="staffById[b.sales_person_id].avatar || undefined">{{ initials(staffById[b.sales_person_id].name) }}</a-avatar>
                  </a-tooltip>
                  <a-tag :style="tagStyle(b.status)" style="margin: 0">{{ statusLabel(b.status) }}</a-tag>
                </div>
              </div>
            </div>
            <a-empty v-else description="No appointments in this period" />
          </a-spin>
        </a-card>
      </a-col>
    </a-row>

    <!-- Appointment quick edit (shared with the Bookings list). -->
    <BookingEditModal v-model:open="detailOpen" :booking="selectedBooking" @saved="afterSave" />
  </div>
</template>

<script setup>
/**
 * Booking calendar — FullCalendar 5.11.5 loaded on demand from the same CDN
 * as legacy (window.FullCalendar global; css + js injected once). Events =
 * GET bookings?from&to&limit=-1&SortField=booking_date&SortType=asc →
 * {bookings}; each becomes a timed event (end defaults to start + 1h when
 * booking_end_time is empty). Status colors and the click-to-details modal
 * match legacy.
 */
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { PlusOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import BookingEditModal from '../../components/BookingEditModal.vue';
import http from '../../lib/http';
import { sortByLabel } from '../../lib/filterOptions';
import { useAuthStore } from '../../stores/auth';
import { useUiStore } from '../../stores/ui';
import dayjs from 'dayjs';

const { t } = useI18n();

const isLoading = ref(true);
const calendarEl = ref();
const detailOpen = ref(false);
const selectedBooking = ref(null);
let calendar = null;
let resizeHandler = null;
let loadingLib = null;

const STATUS_COLORS = {
  pending: { bg: '#fed7aa', border: '#f97316', text: '#9a3412' },
  confirmed: { bg: '#dbeafe', border: '#3b82f6', text: '#1d4ed8' },
  cancelled: { bg: '#fee2e2', border: '#ef4444', text: '#b91c1c' },
  completed: { bg: '#bbf7d0', border: '#22c55e', text: '#15803d' },
};

function statusLabel(status) {
  if (status === 'pending') return t('Pending');
  if (status === 'confirmed') return t('Confirmed');
  if (status === 'cancelled') return t('Cancelled');
  if (status === 'completed') return t('complete');
  return status;
}
function tagStyle(status) {
  const c = STATUS_COLORS[status] || STATUS_COLORS.pending;
  return { background: c.bg, borderColor: c.border, color: c.text };
}

function ensureFullCalendar() {
  if (window.FullCalendar && window.FullCalendar.Calendar) return Promise.resolve();
  if (!loadingLib) {
    loadingLib = new Promise((resolve, reject) => {
      const cssId = 'fullcalendar-main-css';
      if (!document.getElementById(cssId)) {
        const link = document.createElement('link');
        link.id = cssId;
        link.rel = 'stylesheet';
        link.href = 'https://cdn.jsdelivr.net/npm/fullcalendar@5.11.5/main.min.css';
        document.head.appendChild(link);
      }
      const script = document.createElement('script');
      script.src = 'https://cdn.jsdelivr.net/npm/fullcalendar@5.11.5/main.min.js';
      script.async = true;
      script.onload = () => resolve();
      script.onerror = err => reject(err);
      document.head.appendChild(script);
    });
  }
  return loadingLib;
}

// ---------------- whose appointments ----------------
// A staff member sees only their own appointments (sales person = them); an
// admin (or SuperAdmin) sees everyone's and can narrow to one person by
// clicking their photo.
const auth = useAuthStore();
const ui = useUiStore();
// "Admin" here = may see / book other staff: admins, SuperAdmin, or a role
// with "Booking to other staff" (same rule the server applies).
const isAdmin = computed(() => ['admin', 'superadmin'].includes(String(auth.user?.type || '').toLowerCase())
  || (auth.permissions || []).includes('booking_other_staff'));
const staff = ref([]); // [{id, name, avatar}] -- the company's staff (bookings filter_options)
const staffById = computed(() => Object.fromEntries(staff.value.map(s => [s.id, s])));
const staffFilter = ref(null);
const staffParam = () => (isAdmin.value ? (staffFilter.value || undefined) : auth.user?.id);
const me = computed(() => staffById.value[auth.user?.id]
  || (auth.user ? { id: auth.user.id, name: auth.username, avatar: null } : null));
function initials(name) {
  return String(name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0].toUpperCase()).join('');
}
function setStaff(id) {
  staffFilter.value = id;
  calendar?.refetchEvents();
  loadList();
}

async function fetchEvents(startStr, endStr, success, failure) {
  try {
    const data = await http.get('bookings', {
      from: startStr.slice(0, 10), to: endStr.slice(0, 10), limit: -1, SortField: 'booking_date', SortType: 'asc',
      sales_person_id: staffParam(),
    });
    if (data.filter_options?.staff) staff.value = data.filter_options.staff;
    visibleRows.value = data.bookings || [];
    const events = (data.bookings || []).filter(b => !statusFilter.value || b.status === statusFilter.value).map(b => {
      if (!b.booking_date) return null;
      const date = b.booking_date;
      const startTime = (b.booking_time || '00:00').slice(0, 5);
      const endTime = b.booking_end_time ? b.booking_end_time.slice(0, 5) : null;
      const start = `${date}T${startTime}:00`;
      let end;
      if (endTime) {
        end = `${date}T${endTime}:00`;
      } else {
        const d = new Date(start);
        d.setHours(d.getHours() + 1);
        end = d.toISOString().slice(0, 19);
      }
      const colors = STATUS_COLORS[b.status] || STATUS_COLORS.pending;
      return {
        id: String(b.id),
        title: b.customer_name || `Booking #${b.id}`,
        start,
        end,
        allDay: false,
        backgroundColor: colors.bg,
        borderColor: colors.border,
        textColor: colors.text,
        extendedProps: { booking: b },
      };
    }).filter(Boolean);
    success(events);
  } catch (err) {
    if (failure) failure(err);
    else success([]);
  }
}

// Status summary above the calendar: counts of what the calendar is showing
// (current month / week / day); a click shows only that status.
const visibleRows = ref([]);
const statusFilter = ref(null);
const statusCounts = computed(() => {
  const c = { pending: 0, confirmed: 0, completed: 0, cancelled: 0 };
  visibleRows.value.forEach(b => { if (c[b.status] !== undefined) c[b.status]++; });
  return c;
});
function toggleStatus(s) {
  statusFilter.value = statusFilter.value === s ? null : s;
  calendar?.refetchEvents();
}

// Appointment card inside the calendar: staff photo (or initials), time,
// customer and service -- the status shows as the card's color.
const esc = s => String(s ?? '').replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
function eventContent(arg) {
  const b = arg.event.extendedProps.booking || {};
  const sp = staffById.value[b.sales_person_id];
  const avatar = sp
    ? (sp.avatar
      ? `<img class="bkc-av" src="${esc(sp.avatar)}" alt="">`
      : `<span class="bkc-av bkc-av--txt">${esc(initials(sp.name))}</span>`)
    : '';
  const time = `${(b.booking_time || '').slice(0, 5)}${b.booking_end_time ? '–' + b.booking_end_time.slice(0, 5) : ''}`;
  return {
    html: `<div class="bkc-ev">${avatar}<div class="bkc-ev__txt">`
      + `<div class="bkc-ev__top"><b>${esc(time)}</b> ${esc(b.customer_name || '')}</div>`
      + (b.product_name ? `<div class="bkc-ev__svc">${esc(b.product_name)}</div>` : '')
      + '</div></div>',
  };
}

// The shared edit window shows its own messages; refresh both views.
function afterSave() {
  calendar?.refetchEvents();
  loadList();
}

// ---------------- appointments list (next to the calendar) ----------------
// Day / Week / Month / Year around today (default: today), same staff scope
// as the calendar, grouped by day; a click opens the same edit modal.
const LIST_PERIODS = [
  { value: 'day', label: 'Day' },
  { value: 'week', label: 'Week' },
  { value: 'month', label: 'Month' },
  { value: 'year', label: 'Year' },
];
const listPeriod = ref('day');
const listLoading = ref(false);
const listRows = ref([]);
function listRange() {
  const now = dayjs();
  if (listPeriod.value === 'week') {
    // Weeks start on Monday, like the calendar (firstDay: 1).
    const monday = now.subtract((now.day() + 6) % 7, 'day');
    return [monday, monday.add(6, 'day')];
  }
  if (listPeriod.value === 'month') return [now.startOf('month'), now.endOf('month')];
  if (listPeriod.value === 'year') return [now.startOf('year'), now.endOf('year')];
  return [now, now];
}
const listRangeLabel = computed(() => {
  const [a, b] = listRange();
  return listPeriod.value === 'day' ? a.format('ddd, MMM D') : `${a.format('MMM D')} – ${b.format('MMM D, YYYY')}`;
});
async function loadList() {
  listLoading.value = true;
  const [from, to] = listRange();
  try {
    const data = await http.get('bookings', {
      from: from.format('YYYY-MM-DD'), to: to.format('YYYY-MM-DD'), limit: -1, SortField: 'booking_date', SortType: 'asc',
      sales_person_id: staffParam(),
    });
    if (data.filter_options?.staff) staff.value = data.filter_options.staff;
    listRows.value = (data.bookings || []).slice().sort((x, y) =>
      `${x.booking_date} ${x.booking_time || ''}`.localeCompare(`${y.booking_date} ${y.booking_time || ''}`));
  } catch (e) {
    listRows.value = [];
  } finally {
    listLoading.value = false;
  }
}
const listGroups = computed(() => {
  const today = dayjs().format('YYYY-MM-DD');
  const groups = [];
  listRows.value.forEach(b => {
    const date = String(b.booking_date || '').slice(0, 10);
    let g = groups[groups.length - 1];
    if (!g || g.date !== date) {
      g = { date, label: date === today ? `Today · ${dayjs(date).format('ddd, MMM D')}` : dayjs(date).format('ddd, MMM D, YYYY'), items: [] };
      groups.push(g);
    }
    g.items.push(b);
  });
  return groups;
});
const fmtTime = v => (v ? String(v).slice(0, 5) : '');
function openBooking(b) {
  selectedBooking.value = b;
  detailOpen.value = true;
}

// New appointment: the button opens the booking form; clicking an empty day
// (month view) or time slot (week/day view) pre-fills that date/time.
const router = useRouter();
function newAppointment(date, time) {
  const query = {};
  if (date) query.date = date;
  if (time) query.time = time;
  router.push({ path: '/bookings/create', query });
}
function handleDateClick(info) {
  const d = info.date;
  const pad = n => String(n).padStart(2, '0');
  const date = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  newAppointment(date, info.allDay ? null : `${pad(d.getHours())}:${pad(d.getMinutes())}`);
}

function handleEventClick(info) {
  const data = info?.event?.extendedProps?.booking || null;
  if (!data) return;
  selectedBooking.value = data;
  detailOpen.value = true;
}

async function initCalendar() {
  try {
    await ensureFullCalendar();
    await nextTick();
    const el = calendarEl.value;
    if (!el || !window.FullCalendar?.Calendar) {
      message.error('Failed to load calendar. Please refresh the page.');
      return;
    }
    const { Calendar } = window.FullCalendar;
    if (calendar) calendar.destroy();
    const isMobile = window.innerWidth < 768;
    calendar = new Calendar(el, {
      initialView: 'dayGridMonth',
      height: 'auto',
      headerToolbar: {
        left: isMobile ? 'prev,next' : 'prev,next today',
        center: 'title',
        right: isMobile ? 'dayGridMonth' : 'dayGridMonth,timeGridWeek,timeGridDay',
      },
      firstDay: 1,
      selectable: false,
      navLinks: true,
      eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
      events: (info, success, failure) => fetchEvents(info.startStr, info.endStr, success, failure),
      eventClick: handleEventClick,
      dateClick: handleDateClick,
      eventDisplay: 'block',
      displayEventTime: true,
      displayEventEnd: true,
      eventContent,
      nowIndicator: true,
      scrollTime: '08:00:00',
      dayMaxEvents: isMobile ? 2 : 3,
      moreLinkClick: 'popover',
    });
    resizeHandler = () => {
      if (!calendar) return;
      const mobile = window.innerWidth < 768;
      calendar.setOption('headerToolbar', mobile
        ? { left: 'prev,next', center: 'title', right: 'dayGridMonth' }
        : { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay' });
    };
    window.addEventListener('resize', resizeHandler);
    calendar.render();
  } catch (e) {
    message.error('Failed to load calendar. Please refresh the page.');
  } finally {
    isLoading.value = false;
  }
}

onMounted(() => {
  initCalendar();
  loadList();
});

onBeforeUnmount(() => {
  if (resizeHandler) window.removeEventListener('resize', resizeHandler);
  if (calendar) { calendar.destroy(); calendar = null; }
});
</script>

<style scoped>
/* Staff photos next to "New appointment". */
.staff-strip {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}
.staff-avatar {
  cursor: pointer;
  background: #ede9fe;
  color: #6d28d9;
  font-weight: 600;
  border: 2px solid transparent;
  transition: border-color 0.15s, transform 0.15s;
}
.staff-avatar:hover {
  transform: translateY(-1px);
}
.staff-avatar--on {
  border-color: var(--ant-color-primary, #6d28d9);
}
.staff-me {
  display: flex;
  align-items: center;
  gap: 8px;
}
.staff-me__name {
  font-weight: 600;
  line-height: 1.2;
}
.staff-me__sub {
  font-size: 12px;
  opacity: 0.6;
}
/* Appointments list next to the calendar. */
.appt-list-range {
  margin-left: 8px;
  font-weight: 400;
  font-size: 12px;
  opacity: 0.6;
}
.appt-list {
  max-height: 640px;
  overflow-y: auto;
  padding-right: 2px;
}
.appt-day + .appt-day {
  margin-top: 12px;
}
.appt-day__head {
  display: flex;
  justify-content: space-between;
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.3px;
  opacity: 0.7;
  margin-bottom: 6px;
}
.appt-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 10px;
  margin-bottom: 6px;
  border: 1px solid rgba(128, 128, 128, 0.18);
  border-left-width: 4px;
  border-radius: 8px;
  cursor: pointer;
  transition: background 0.15s;
}
.appt-item:hover {
  background: rgba(128, 128, 128, 0.07);
}
.appt-item__time {
  width: 46px;
  flex: none;
  font-weight: 600;
  font-size: 13px;
  display: flex;
  flex-direction: column;
}
.appt-item__time span {
  font-weight: 400;
  font-size: 11px;
  opacity: 0.6;
}
.appt-item__main {
  flex: 1;
  min-width: 0;
}
.appt-item__name,
.appt-item__sub {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.appt-item__name {
  font-weight: 500;
}
.appt-item__sub {
  font-size: 12px;
  opacity: 0.65;
}
.appt-status {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 16px;
  padding: 8px 12px;
  border-radius: 8px;
  background: rgba(128, 128, 128, 0.08);
}
.appt-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}
</style>

<style scoped>
/* Status summary pills above the calendar. */
.bkc-pills {
  margin-top: 12px;
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 10px;
}
.bkc-pill {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 6px 12px;
  border-radius: 999px;
  border: 1px solid var(--pill-bd);
  background: var(--pill-bg);
  color: var(--pill-tx);
  font-size: 13px;
  cursor: pointer;
  transition: opacity 0.15s, box-shadow 0.15s, transform 0.15s;
}
.bkc-pill:hover {
  transform: translateY(-1px);
}
.bkc-pill.is-on {
  box-shadow: 0 0 0 2px var(--pill-bd);
}
.bkc-pill.is-dim {
  opacity: 0.45;
}
.bkc-pill__dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--pill-bd);
}
.bkc-pill b {
  font-size: 14px;
}
.bkc-card :deep(.ant-card-body) {
  padding: 14px;
}
</style>

<style>
/* FullCalendar look (it renders its own DOM, so these are not scoped). */
.bkc .fc .fc-toolbar-title {
  font-size: 18px;
  font-weight: 600;
}
.bkc .fc .fc-button-primary {
  background: #fff;
  color: #4b5563;
  border-color: #e5e7eb;
  box-shadow: none;
  text-transform: capitalize;
  font-weight: 500;
}
.bkc .fc .fc-button-primary:hover {
  background: #f5f3ff;
  color: var(--bkc-primary);
  border-color: var(--bkc-primary);
}
.bkc .fc .fc-button-primary:not(:disabled).fc-button-active,
.bkc .fc .fc-button-primary:not(:disabled):active {
  background: var(--bkc-primary);
  border-color: var(--bkc-primary);
  color: #fff;
}
.bkc .fc .fc-button-primary:focus {
  box-shadow: 0 0 0 2px rgba(109, 40, 217, 0.25);
}
/* Day column headers: the theme color, white text. */
.bkc .fc .fc-col-header-cell {
  background: var(--bkc-primary);
  border-color: var(--bkc-primary);
}
.bkc .fc .fc-col-header-cell-cushion {
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.4px;
  color: #fff;
  padding: 9px 4px;
}
.bkc .fc .fc-col-header-cell.fc-day-today {
  background: var(--bkc-primary) !important;
  box-shadow: inset 0 -3px 0 rgba(255, 255, 255, 0.7);
}
/* Day / week views: the time column on the left matches the header (theme color, white text). */
.bkc .fc .fc-timegrid-axis,
.bkc .fc .fc-timegrid-slot-label {
  background: var(--bkc-primary);
  border-color: var(--bkc-primary);
  color: #fff;
}
.bkc .fc .fc-timegrid-axis-cushion,
.bkc .fc .fc-timegrid-slot-label-cushion {
  color: #fff;
  font-size: 12px;
  font-weight: 500;
}
.bkc .fc .fc-daygrid-day-number {
  font-size: 13px;
  color: #374151;
}
.bkc .fc .fc-day-today {
  background: rgba(109, 40, 217, 0.06) !important;
}
.bkc .fc .fc-day-today .fc-daygrid-day-number {
  background: var(--bkc-primary);
  color: #fff;
  border-radius: 999px;
  min-width: 24px;
  text-align: center;
  margin: 4px;
  padding: 2px 6px;
}
.bkc .fc .fc-daygrid-day:hover {
  background: rgba(109, 40, 217, 0.03);
  cursor: pointer;
}
.bkc .fc-theme-standard td,
.bkc .fc-theme-standard th,
.bkc .fc-theme-standard .fc-scrollgrid {
  border-color: #eef0f3;
}
.bkc .fc .fc-event {
  border-radius: 8px;
  border-width: 0 0 0 4px;
  padding: 2px 4px;
  margin-bottom: 3px;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.06);
}
.bkc .fc .fc-event:hover {
  filter: brightness(0.97);
}
.bkc .fc .fc-more-link {
  font-weight: 600;
  color: var(--bkc-primary);
}
/* Appointment card content. */
.bkc-ev {
  display: flex;
  align-items: center;
  gap: 6px;
  min-width: 0;
  overflow: hidden;
}
.bkc-av {
  width: 20px;
  height: 20px;
  flex: none;
  border-radius: 50%;
  object-fit: cover;
  border: 1px solid #fff;
}
.bkc-av--txt {
  display: inline-grid;
  place-items: center;
  background: #6d28d9;
  color: #fff;
  font-size: 9px;
  font-weight: 700;
}
.bkc-ev__txt {
  min-width: 0;
  line-height: 1.25;
}
.bkc-ev__top,
.bkc-ev__svc {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  font-size: 12px;
}
.bkc-ev__svc {
  font-size: 11px;
  opacity: 0.75;
}
</style>
