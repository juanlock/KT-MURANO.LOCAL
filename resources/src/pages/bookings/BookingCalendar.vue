<template>
  <div class="page">
    <PageHeader :title="$t('Calendar_View')" :breadcrumb="[$t('Bookings'), $t('Calendar_View')]">
      <template #extra>
        <a-space wrap>
          <a-tag v-for="(c, s) in STATUS_COLORS" :key="s" :style="{ background: c.bg, borderColor: c.border, color: c.text }">
            {{ statusLabel(s) }}
          </a-tag>
          <a-tooltip title="Or click an empty day / time slot on the calendar">
            <a-button type="primary" @click="newAppointment()">
              <template #icon><PlusOutlined /></template>
              New appointment
            </a-button>
          </a-tooltip>
        </a-space>
      </template>
    </PageHeader>

    <a-card>
      <a-spin :spinning="isLoading">
        <div ref="calendarEl" style="min-height: 480px"></div>
      </a-spin>
    </a-card>

    <!-- Appointment: edit right from the calendar (status, time, service,
         staff, station, notes). Loads/saves the full booking through the
         same endpoints as the Edit page, so nothing else is overwritten. -->
    <a-modal
      v-model:open="detailOpen"
      :title="`${selectedBooking?.Ref || ''} — ${selectedBooking?.customer_name || ''}`"
      width="720px"
    >
      <a-spin :spinning="editLoading">
        <template v-if="edit">
          <!-- Status + one-click moves -->
          <div class="appt-status">
            <span>{{ $t('Status') }}:</span>
            <a-tag :style="tagStyle(edit.status)">{{ statusLabel(edit.status) }}</a-tag>
            <a-space wrap>
              <a-button v-if="edit.status === 'pending'" type="primary" size="small" :loading="statusSaving === 'confirmed'" @click="quickStatus('confirmed')">
                <template #icon><CheckCircleOutlined /></template>{{ $t('Confirm') }}
              </a-button>
              <a-button v-if="edit.status === 'confirmed'" size="small" style="color: #15803d; border-color: #22c55e" :loading="statusSaving === 'completed'" @click="quickStatus('completed')">
                <template #icon><CheckSquareOutlined /></template>{{ $t('complete') }}
              </a-button>
              <a-button v-if="edit.status === 'pending' || edit.status === 'confirmed'" danger size="small" :loading="statusSaving === 'cancelled'" @click="quickStatus('cancelled')">
                <template #icon><StopOutlined /></template>{{ $t('Cancel') }}
              </a-button>
            </a-space>
          </div>

          <a-form layout="vertical">
            <!-- Same date / start / end controls as Create/Edit Booking. -->
            <BookingWhenFields
              v-model:date="edit.booking_date" v-model:start="edit.booking_time" v-model:end="edit.booking_end_time"
              :duration="Number(selectedService?.service_duration) || 0"
              :exclude-id="edit.id" :customer-id="edit.customer_id"
            />
            <a-row :gutter="12">
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Product_Name')">
                  <a-select
                    v-model:value="edit.product_id" show-search option-filter-prop="label" allow-clear
                    :options="serviceOptions" :placeholder="$t('PleaseSelect')" @change="onServiceChange"
                  />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Sales_Person')">
                  <a-select
                    v-model:value="edit.sales_person_id" show-search option-filter-prop="label" allow-clear
                    :options="staffOptions" :placeholder="$t('PleaseSelect')"
                  />
                </a-form-item>
              </a-col>
              <a-col v-if="trayOptions.length" :xs="24" :md="12">
                <a-form-item :label="$t('Tray')">
                  <a-select v-model:value="edit.tray_id" allow-clear :options="trayOptions" :placeholder="$t('PleaseSelect')" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('warehouse')">
                  <a-select v-model:value="edit.warehouse_id" allow-clear show-search option-filter-prop="label" :options="warehouseOptions" :placeholder="$t('PleaseSelect')" />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="6">
                <a-form-item :label="$t('Price')">
                  <a-input-number v-model:value="edit.price" :min="0" style="width: 100%" />
                </a-form-item>
              </a-col>
              <a-col :span="24">
                <a-form-item :label="$t('Note')">
                  <a-textarea v-model:value="edit.notes" :rows="3" />
                </a-form-item>
              </a-col>
            </a-row>
          </a-form>
        </template>
      </a-spin>
      <template #footer>
        <div class="appt-footer">
          <a-button type="link" style="padding: 0" :disabled="!selectedBooking" @click="$router.push(`/bookings/${selectedBooking.id}/edit`)">
            {{ $t('Edit') }} — full form
          </a-button>
          <a-space>
            <a-button @click="detailOpen = false">{{ $t('Cancel') }}</a-button>
            <a-button type="primary" :loading="saving" :disabled="!edit || editLoading" @click="saveEdit">{{ $t('submit') }}</a-button>
          </a-space>
        </div>
      </template>
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
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { CheckCircleOutlined, CheckSquareOutlined, StopOutlined, PlusOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import BookingWhenFields from '../../components/BookingWhenFields.vue';
import http from '../../lib/http';
import { sortByLabel } from '../../lib/filterOptions';

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

// ---------------- edit an appointment from the calendar ----------------
// GET bookings/{id}/edit -> full booking + services/staff/stations; the modal
// changes a few fields and PUT bookings/{id} sends the FULL booking back
// (that endpoint validates and overwrites every field). Status buttons use
// PUT bookings/{id}/status (confirming also creates the customer's account).
const editLoading = ref(false);
const saving = ref(false);
const statusSaving = ref(null);
const edit = ref(null);
const lists = ref({ products: [], sales_persons: [], trays: [], warehouses: [] });
const warehouseOptions = computed(() => sortByLabel((lists.value.warehouses || []).map(w => ({ value: w.id, label: w.name }))));

const serviceOptions = computed(() => sortByLabel((lists.value.products || []).map(p => ({
  value: p.id,
  label: [p.group_name, p.name].filter(Boolean).join(' › ') + (p.service_duration ? ` · ${fmtDuration(p.service_duration)}` : ''),
}))));
const staffOptions = computed(() => sortByLabel((lists.value.sales_persons || []).map(s => ({ value: s.id, label: s.name }))));
const trayOptions = computed(() => sortByLabel((lists.value.trays || []).map(s => ({ value: s.id, label: s.name }))));

function fmtDuration(m) {
  const h = Math.floor(m / 60);
  const r = m % 60;
  return [h ? `${h} h` : '', r ? `${r} min` : ''].filter(Boolean).join(' ');
}
const selectedService = computed(() => (lists.value.products || []).find(p => p.id === edit.value?.product_id));

// Same rule as the booking form: picking a product/service sets its name and
// its price (the default, still editable); End Time follows its duration
// inside BookingWhenFields.
function onServiceChange() {
  const p = selectedService.value;
  edit.value.product_name = p ? p.name : null;
  edit.value.price = p && p.price != null ? Number(p.price) : null;
}

const hhmm = v => (v ? String(v).slice(0, 5) : null);

async function loadEdit(id) {
  editLoading.value = true;
  edit.value = null;
  try {
    const data = await http.get(`bookings/${id}/edit`);
    const b = data.booking || {};
    lists.value = { products: data.products || [], sales_persons: data.sales_persons || [], trays: data.trays || [], warehouses: data.warehouses || [] };
    edit.value = {
      ...b,
      booking_date: b.booking_date ? String(b.booking_date).slice(0, 10) : null,
      booking_time: hhmm(b.booking_time),
      booking_end_time: hhmm(b.booking_end_time),
      delivery_date: b.delivery_date ? String(b.delivery_date).slice(0, 10) : null,
      delivery_time: hhmm(b.delivery_time),
    };
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
    detailOpen.value = false;
  } finally {
    editLoading.value = false;
  }
}

function payload() {
  const b = edit.value;
  return {
    customer_id: b.customer_id,
    sales_person_id: b.sales_person_id || null,
    tray_id: b.tray_id || null,
    warehouse_id: b.warehouse_id || null,
    product_id: b.product_id || null,
    product_name: b.product_name,
    product_description: b.product_description,
    price: b.price ? parseFloat(b.price) : null,
    amount: b.amount ? parseFloat(b.amount) : null,
    booking_date: b.booking_date,
    booking_time: b.booking_time,
    booking_end_time: b.booking_end_time || null,
    delivery_date: b.delivery_date || null,
    delivery_time: b.delivery_time || null,
    delivery_address: b.delivery_address,
    status: b.status,
    notes: b.notes,
  };
}

function afterSave(data) {
  if (data?.customer_account_created) message.info('Customer account created');
  if (data?.sale_warning) message.warning(data.sale_warning, 6);
  calendar?.refetchEvents();
}

async function saveEdit() {
  if (!edit.value) return;
  if (edit.value.booking_end_time && edit.value.booking_end_time <= edit.value.booking_time) {
    message.warning('End time must be after the start time');
    return;
  }
  saving.value = true;
  try {
    const data = await http.put(`bookings/${edit.value.id}`, payload());
    message.success(t('Successfully_Updated'));
    detailOpen.value = false;
    afterSave(data);
  } catch (e) {
    const errs = e?.data?.errors;
    const first = errs && Object.values(errs)[0];
    message.error((Array.isArray(first) ? first[0] : first) || e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

async function quickStatus(status) {
  statusSaving.value = status;
  try {
    const data = await http.put(`bookings/${edit.value.id}/status`, { status });
    edit.value.status = status;
    message.success(`${edit.value.Ref}: ${statusLabel(status)}`);
    afterSave(data);
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    statusSaving.value = null;
  }
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
  loadEdit(data.id);
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

<style scoped>
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
