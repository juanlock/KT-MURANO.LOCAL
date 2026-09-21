<template>
  <div class="page">
    <PageHeader :title="$t('Calendar_View')" :breadcrumb="[$t('Bookings'), $t('Calendar_View')]">
      <template #extra>
        <a-space wrap>
          <a-tag v-for="(c, s) in STATUS_COLORS" :key="s" :style="{ background: c.bg, borderColor: c.border, color: c.text }">
            {{ statusLabel(s) }}
          </a-tag>
        </a-space>
      </template>
    </PageHeader>

    <a-card>
      <a-spin :spinning="isLoading">
        <div ref="calendarEl" style="min-height: 480px"></div>
      </a-spin>
    </a-card>

    <!-- Booking details -->
    <a-modal
      v-model:open="detailOpen"
      :title="$t('Booking_Details') || 'Booking Details'"
      :footer="null" width="720px"
    >
      <a-descriptions v-if="selectedBooking" :column="{ xs: 1, md: 2 }" size="small" bordered>
        <a-descriptions-item :label="$t('Customer')">{{ selectedBooking.customer_name || '-' }}</a-descriptions-item>
        <a-descriptions-item :label="$t('ProductName')">{{ selectedBooking.product_name || '-' }}</a-descriptions-item>
        <a-descriptions-item :label="$t('date')">{{ selectedBooking.booking_date }}</a-descriptions-item>
        <a-descriptions-item :label="$t('Start_Time') || 'Start Time'">{{ selectedBooking.booking_time }}</a-descriptions-item>
        <a-descriptions-item :label="$t('End_Time') || 'End Time'">{{ selectedBooking.booking_end_time || '-' }}</a-descriptions-item>
        <a-descriptions-item :label="$t('Status')">
          <a-tag :style="tagStyle(selectedBooking.status)">{{ statusLabel(selectedBooking.status) }}</a-tag>
        </a-descriptions-item>
        <a-descriptions-item :label="$t('Note')" :span="2">
          <span style="white-space: pre-line">{{ selectedBooking.notes || '-' }}</span>
        </a-descriptions-item>
      </a-descriptions>
    </a-modal>
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
import { ref, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

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

async function fetchEvents(startStr, endStr, success, failure) {
  try {
    const data = await http.get('bookings', {
      from: startStr, to: endStr, limit: -1, SortField: 'booking_date', SortType: 'asc',
    });
    const events = (data.bookings || []).map(b => {
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
      eventDisplay: 'block',
      displayEventTime: true,
      displayEventEnd: true,
      ...(isMobile && { dayMaxEvents: 2, moreLinkClick: 'popover' }),
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

onMounted(initCalendar);
onBeforeUnmount(() => {
  if (resizeHandler) window.removeEventListener('resize', resizeHandler);
  if (calendar) { calendar.destroy(); calendar = null; }
});
</script>
