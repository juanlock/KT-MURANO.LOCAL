<template>
  <div class="page">
    <PageHeader :title="$t('Booking_List')" :breadcrumb="[$t('Bookings'), $t('Booking_List')]">
      <template #actions>
        <!-- Same date presets + Start date / to as Sales / Purchases
             (lib/datePresets.js): the booking date filter. Editing a date
             makes it a custom range (one day = same start and end). -->
        <a-space wrap :size="12">
        <DateRangeFields v-model:value="dateRange" @change="datePreset = presetOf(dateRange?.[0], dateRange?.[1]); crud.reload()" />
        <DatePresetButtons v-model:value="datePreset" @change="applyPreset(datePreset)" />
        <a-button type="primary" @click="$router.push('/bookings/create')">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
        </a-space>
      </template>
    </PageHeader>

    <!-- Stat tiles (from the list payload) -->
    <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
      <!-- 5 cards sharing the row (2 per row on phones). -->
      <a-col v-for="tile in tiles" :key="tile.label" flex="1 1 170px">
        <a-card size="small">
          <a-spin :spinning="loading">
            <a-statistic :title="tile.label" :value="tile.value" :value-style="tile.style" />
          </a-spin>
        </a-card>
      </a-col>
    </a-row>

    <!-- All filters on one row (wraps only on small screens). -->
    <a-card size="small" class="bk-filters" style="margin-bottom: 12px">
      <div class="bk-filters__row">
        <a-select
          v-model:value="status" class="bk-f bk-f--status" allow-clear
          :placeholder="$t('Status')" :options="statusOptions" @change="crud.reload()"
        >
          <template #option="{ label, value }">
            <span><span class="dot" :class="`dot--${value}`"></span>{{ label }}</span>
          </template>
        </a-select>
        <!-- Start time of day, e.g. mornings only: 08:00 - 12:00 -->
        <a-time-range-picker
          v-model:value="timeRange" class="bk-f bk-f--time" format="HH:mm" value-format="HH:mm" :minute-step="15" allow-clear
          :placeholder="[$t('Time_In'), $t('to')]" @change="crud.reload()"
        />
        <a-select
          v-model:value="customerId" class="bk-f" allow-clear show-search option-filter-prop="label"
          :placeholder="$t('Customer')" :options="customerOptions" @change="crud.reload()"
        >
          <template #option="{ label }"><span><UserOutlined style="opacity: 0.5; margin-right: 6px" />{{ label }}</span></template>
        </a-select>
        <a-select
          v-model:value="serviceId" class="bk-f" allow-clear show-search option-filter-prop="label"
          :placeholder="$t('Service') || 'Service'" :options="serviceOptions" @change="crud.reload()"
        >
          <template #option="{ label }"><span><ScissorOutlined style="opacity: 0.5; margin-right: 6px" />{{ label }}</span></template>
        </a-select>
        <!-- Staff photos (same as the calendar), on the filter row: click = only that
             person's bookings, hover = link to their employee details. Up to 8 staff;
             with more (or when locked to the signed-in user) the Staff dropdown instead. -->
        <StaffStrip
          v-if="showStaffStrip" class="bk-f--staff" :size="30" :staff="fo.staff" :model-value="staffId || null"
          @change="id => { staffId = id || undefined; crud.reload(); }"
        />
        <!-- Staff: locked to the signed-in user unless admin / "Booking to other staff". -->
        <a-select
          v-else
          v-model:value="staffId" class="bk-f" :allow-clear="canOtherStaff" show-search option-filter-prop="label"
          placeholder="Staff" :options="staffOptions" :disabled="!canOtherStaff" @change="crud.reload()"
        >
          <template #option="{ label, avatar }">
            <span class="opt-staff">
              <a-avatar :size="22" :src="avatar || undefined" class="opt-staff__avatar">{{ initials(label) }}</a-avatar>{{ label }}
            </span>
          </template>
        </a-select>
        <ResetFiltersButton :dirty="filtersDirty" @reset="resetFilters" />
      </div>
    </a-card>

    <!-- DataTable: search, show/hide columns (gear, remembered per user),
         column customization, refresh. -->
    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'booking_date'">{{ date(record.booking_date) }}</template>
        <template v-else-if="column.key === 'delivery_date'">{{ record.delivery_date ? date(record.delivery_date) : '' }}</template>
        <template v-else-if="column.key === 'price'">{{ money(record.price, moneyDec) }}</template>
        <template v-else-if="column.key === 'amount'">{{ money(record.amount, moneyDec) }}</template>
        <template v-else-if="column.key === 'notes'">
          <span class="notes-cell" :title="record.notes">{{ record.notes }}</span>
        </template>
        <template v-else-if="column.key === 'status'">
          <!-- Pending / Confirmed tags are clickable: move the booking on
               (Confirm, Complete) or Cancel it. Confirming gives a customer
               without an account (e.g. from the public booking page) its
               user. -->
          <a-dropdown v-if="NEXT_STATUSES[record.status]" :trigger="['click']">
            <a-tag :color="statusColor(record.status)" class="status-tag--action">
              {{ statusLabel(record.status) }} <DownOutlined style="font-size: 9px" />
            </a-tag>
            <template #overlay>
              <a-menu @click="({ key }) => changeStatus(record, key)">
                <a-menu-item v-for="s in NEXT_STATUSES[record.status]" :key="s" :danger="s === 'cancelled'">
                  <CheckCircleOutlined v-if="s === 'confirmed'" style="color: #1677ff" />
                  <CheckSquareOutlined v-else-if="s === 'completed'" style="color: #52c41a" />
                  <StopOutlined v-else />
                  {{ statusActionLabel(s) }}
                </a-menu-item>
              </a-menu>
            </template>
          </a-dropdown>
          <a-tag v-else :color="statusColor(record.status)">{{ statusLabel(record.status) }}</a-tag>
        </template>
        <template v-else-if="column.key === 'sale'">
          <router-link v-if="record.sale_id" :to="`/sales/${record.sale_id}`">{{ record.sale_ref }}</router-link>
          <span v-else style="opacity: 0.45">—</span>
        </template>
        <template v-else-if="column.key === 'customer_name'">
          <span class="cust-cell"><a-avatar :size="24" class="cust-cell__avatar">{{ initials(record.customer_name) }}</a-avatar>{{ record.customer_name }}</span>
        </template>
        <template v-else-if="column.key === 'staff'">
          <!-- Linked to their employee details when there is an employee record. -->
          <router-link
            v-if="staffById[record.sales_person_id] && staffById[record.sales_person_id].employee_id && canEmployee"
            :to="`/hrm/employees/${staffById[record.sales_person_id].employee_id}/details`" class="opt-staff" title="Employee details"
          >
            <a-avatar :size="24" :src="staffById[record.sales_person_id].avatar || undefined" class="opt-staff__avatar">{{ initials(staffById[record.sales_person_id].name) }}</a-avatar>
            {{ staffById[record.sales_person_id].name }}
          </router-link>
          <span v-else-if="staffById[record.sales_person_id]" class="opt-staff">
            <a-avatar :size="24" :src="staffById[record.sales_person_id].avatar || undefined" class="opt-staff__avatar">{{ initials(staffById[record.sales_person_id].name) }}</a-avatar>
            {{ staffById[record.sales_person_id].name }}
          </span>
          <span v-else style="opacity: 0.45">—</span>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space :size="2">
            <!-- Edit: the same quick-edit window as the calendar. -->
            <a-tooltip :title="$t('Edit')">
              <a-button type="text" size="small" @click="openEdit(record)">
                <template #icon><EditOutlined style="color: #52c41a" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Del')">
              <a-button type="text" size="small" danger @click="crud.remove(record)">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>

    <BookingEditModal v-model:open="editOpen" :booking="editRow" @saved="crud.fetchRows()" />
  </div>
</template>

<script setup>
import DatePresetButtons from '../../components/DatePresetButtons.vue';
import DateRangeFields from '../../components/DateRangeFields.vue';
import { startPreset } from '../../lib/datePresetDefault';
/**
 * Bookings list: GET bookings → {bookings, totalRows, stats} with status and
 * date filters. Create/edit are BookingForm.vue; the calendar is
 * BookingCalendar.vue. Still on legacy: PDF and send-email.
 */
import { ref, computed, watch, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { message } from 'ant-design-vue';
import {
  PlusOutlined, EditOutlined, DeleteOutlined, DownOutlined, CheckCircleOutlined,
  CheckSquareOutlined, StopOutlined, UserOutlined, ScissorOutlined,
} from '@ant-design/icons-vue';
import http from '../../lib/http';
import { sortByLabel } from '../../lib/filterOptions';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import ResetFiltersButton from '../../components/ResetFiltersButton.vue';
import BookingEditModal from '../../components/BookingEditModal.vue';
import StaffStrip from '../../components/StaffStrip.vue';
import { useAuthStore } from '../../stores/auth';
import { presetDates, presetOf } from '../../lib/datePresets';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';

const { t } = useI18n();
const { money, date } = useFormat();

const status = ref(undefined);
const dateRange = ref(null); // [from, to] booking date
const timeRange = ref(null); // [from, to] start time of day, HH:mm
const customerId = ref(undefined);
const serviceId = ref(undefined);
const staffId = ref(undefined);

const filtersDirty = computed(() => !!(status.value || dateRange.value?.length || timeRange.value?.length
  || customerId.value || serviceId.value || (staffId.value && crud.payload.value?.can_book_other_staff !== false)));

// Quick date presets (shared list): fill the booking-date range filter.
// Default: the user's default date (double-click a button, ★), else All =
// no date limit (the list's previous behavior); picking a range by hand
// clears the highlight.
const datePreset = ref(startPreset('all'));
const rangeOf = preset => {
  const { from, to } = presetDates(preset);
  return from ? [from, to] : null;
};
dateRange.value = rangeOf(datePreset.value); // before the first fetch (onMounted)
function applyPreset(preset) {
  dateRange.value = rangeOf(preset);
  crud.reload();
}

function resetFilters() {
  status.value = undefined;
  datePreset.value = startPreset('all');
  dateRange.value = rangeOf(datePreset.value);
  timeRange.value = null;
  customerId.value = undefined;
  serviceId.value = undefined;
  // Locked to the user (no "Booking to other staff"): stays on them.
  staffId.value = crud.payload.value?.can_book_other_staff === false ? crud.payload.value.me : undefined;
  crud.reload();
}

const filterParams = () => ({
  ...(status.value ? { status: status.value } : {}),
  ...(dateRange.value?.[0] ? { from: dateRange.value[0] } : {}),
  ...(dateRange.value?.[1] ? { to: dateRange.value[1] } : {}),
  ...(timeRange.value?.[0] ? { time_from: timeRange.value[0] } : {}),
  ...(timeRange.value?.[1] ? { time_to: timeRange.value[1] } : {}),
  ...(customerId.value ? { customer_id: customerId.value } : {}),
  ...(serviceId.value ? { product_id: serviceId.value } : {}),
  ...(staffId.value ? { sales_person_id: staffId.value } : {}),
});

// Payload: { bookings, totalRows, stats: {total, pending, confirmed, completed} }
const crud = useCrudTable('bookings', {
  rowsKey: 'bookings',
  sortField: 'booking_date',
  sortType: 'desc',
  params: filterParams,
});

const loading = computed(() => crud.loading.value);
const stats = computed(() => crud.payload.value?.stats || {});
// Defaults -> Booking -> Use decimals off: Price/Amount without decimals.
const moneyDec = computed(() => (crud.payload.value?.decimals === false ? 0 : undefined));
const tiles = computed(() => [
  { label: t('Total'), value: stats.value.total || 0 },
  { label: t('Pending'), value: stats.value.pending || 0, style: { color: '#faad14' } },
  { label: t('Confirmed'), value: stats.value.confirmed || 0, style: { color: '#1677ff' } },
  { label: t('Completed'), value: stats.value.completed || 0, style: { color: '#52c41a' } },
  { label: t('Cancelled'), value: stats.value.cancelled || 0, style: { color: '#ff4d4f' } },
]);

// Filter dropdowns from the list payload (company-scoped), alphabetical.
const fo = computed(() => crud.payload.value?.filter_options || {});
const customerOptions = computed(() => sortByLabel((fo.value.customers || []).map(c => ({ value: c.id, label: c.name }))));
const serviceOptions = computed(() => sortByLabel((fo.value.services || []).map(s => ({
  value: s.id, label: s.group_name ? `${s.group_name} › ${s.name}` : s.name,
}))));
const staffOptions = computed(() => sortByLabel((fo.value.staff || []).map(s => ({ value: s.id, label: s.name, avatar: s.avatar || null }))));
const staffById = computed(() => Object.fromEntries((fo.value.staff || []).map(s => [s.id, s])));
// Without "Booking to other staff" the server returns only the user's own
// bookings; the Staff filter shows them, locked.
const canOtherStaff = computed(() => crud.payload.value?.can_book_other_staff !== false);
const canEmployee = computed(() => useAuthStore().can('view_employee'));
const showStaffStrip = computed(() => canOtherStaff.value && (fo.value.staff || []).length > 0 && fo.value.staff.length <= 8);
watch(() => crud.payload.value?.can_book_other_staff, can => {
  if (can === false && crud.payload.value?.me) staffId.value = crud.payload.value.me;
});

const statusOptions = computed(() => [
  { value: 'pending', label: t('Pending') },
  { value: 'confirmed', label: t('Confirmed') },
  { value: 'completed', label: t('Completed') },
  { value: 'cancelled', label: t('Cancelled') },
]);

const columns = computed(() => [
  // Actions first (left), as asked.
  { title: t('Action'), key: 'actions', width: 90, align: 'center' },
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref', sorter: true },
  { title: t('Customer'), dataIndex: 'customer_name', key: 'customer_name' },
  { title: t('ProductName'), dataIndex: 'product_name', key: 'product_name' },
  { title: 'Staff', key: 'staff', dataIndex: 'sales_person_id', exportValue: r => staffById.value[r.sales_person_id]?.name || '' },
  { title: t('Price'), key: 'price', dataIndex: 'price', align: 'right', exportValue: r => money(r.price, moneyDec.value) },
  { title: t('date'), key: 'booking_date', dataIndex: 'booking_date', sorter: true, exportValue: r => date(r.booking_date) },
  { title: t('Time_In'), dataIndex: 'booking_time', key: 'booking_time' },
  { title: t('Time_Out'), dataIndex: 'booking_end_time', key: 'booking_end_time' },
  { title: t('Status'), key: 'status', dataIndex: 'status', exportValue: r => statusLabel(r.status) },
  { title: t('Invoice'), key: 'sale', dataIndex: 'sale_ref', exportValue: r => r.sale_ref || '' },
  // Extra columns, off until switched on in the column picker (gear).
  { title: t('Amount'), key: 'amount', dataIndex: 'amount', align: 'right', defaultHidden: true, exportValue: r => money(r.amount, moneyDec.value) },
  { title: t('Delivery_Date'), key: 'delivery_date', dataIndex: 'delivery_date', defaultHidden: true },
  { title: t('Delivery_Time'), key: 'delivery_time', dataIndex: 'delivery_time', defaultHidden: true },
  { title: t('Delivery_Address'), key: 'delivery_address', dataIndex: 'delivery_address', defaultHidden: true },
  { title: t('Note'), key: 'notes', dataIndex: 'notes', defaultHidden: true },
]);

// Quick edit = the calendar's appointment window (components/BookingEditModal.vue).
const editOpen = ref(false);
const editRow = ref(null);
function openEdit(record) {
  editRow.value = record;
  editOpen.value = true;
}
function initials(name) {
  return String(name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0].toUpperCase()).join('');
}

function statusLabel(s) {
  const map = {
    pending: t('Pending'), confirmed: t('Confirmed'),
    completed: t('Completed'), cancelled: t('Cancelled'),
  };
  return map[s] || s;
}

// Quick status moves from the Status tag (PUT bookings/{id}/status).
const NEXT_STATUSES = {
  pending: ['confirmed', 'cancelled'],
  confirmed: ['completed', 'cancelled'],
};
const statusActionLabel = s => ({ confirmed: t('Confirm'), completed: t('complete'), cancelled: t('Cancel') }[s] || s);

async function changeStatus(record, status) {
  try {
    const data = await http.put(`bookings/${record.id}/status`, { status });
    message.success(`${record.Ref}: ${statusLabel(status)}`);
    if (data?.customer_account_created) {
      message.info(`${record.customer_name || t('Customer')}: customer account created`);
    }
    if (data?.sale_warning) message.warning(data.sale_warning, 6);
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  }
}

function statusColor(s) {
  if (s === 'confirmed') return 'processing';
  if (s === 'completed') return 'success';
  if (s === 'pending') return 'warning';
  if (s === 'cancelled') return 'error';
  return 'default';
}

onMounted(crud.fetchRows);
</script>

<style scoped>
/* Date-range keycard -- same look as Sales -> List Sales. */
.range-card {
  border-radius: 10px;
}
.range-card :deep(.ant-card-body) {
  padding: 6px 14px;
  display: flex;
  align-items: center;
  gap: 14px;
}
.range-row {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  white-space: nowrap;
}
.range-label {
  opacity: 0.6;
}
.range-value {
  font-size: 13px;
}
/* Filters: one row; each control shares the width (wraps on small screens). */
.bk-filters__row {
  display: flex;
  align-items: center;
  gap: 8px;
}
.bk-f {
  flex: 1 1 140px;
  min-width: 0;
}
.bk-f--status { flex: 0 1 130px; }
.bk-f--time { flex: 1 1 160px; }
.bk-filters__row .bk-f--staff { flex: 0 0 auto; flex-wrap: nowrap; }
@media (max-width: 1100px) {
  .bk-filters__row { flex-wrap: wrap; }
}
.dot {
  display: inline-block;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  margin-right: 6px;
  vertical-align: middle;
}
.dot--pending { background: #f97316; }
.dot--confirmed { background: #3b82f6; }
.dot--completed { background: #22c55e; }
.dot--cancelled { background: #ef4444; }
.cust-cell,
.opt-staff {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  white-space: nowrap;
}
.cust-cell__avatar {
  background: #e0f2fe;
  color: #0369a1;
  font-size: 11px;
  font-weight: 600;
}
.opt-staff__avatar {
  background: #ede9fe;
  color: #6d28d9;
  font-size: 11px;
  font-weight: 600;
}
.status-tag--action {
  cursor: pointer;
}
.notes-cell {
  display: inline-block;
  max-width: 280px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  vertical-align: bottom;
}
</style>
