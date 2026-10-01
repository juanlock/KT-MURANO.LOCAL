<template>
  <div class="page">
    <PageHeader :title="$t('Booking_List')" :breadcrumb="[$t('Bookings'), $t('Booking_List')]">
      <template #actions>
        
        <a-button type="primary" @click="$router.push('/bookings/create')">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <!-- Stat tiles (from the list payload) -->
    <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
      <a-col v-for="tile in tiles" :key="tile.label" :xs="12" :md="6">
        <a-card size="small">
          <a-spin :spinning="loading">
            <a-statistic :title="tile.label" :value="tile.value" :value-style="tile.style" />
          </a-spin>
        </a-card>
      </a-col>
    </a-row>

    <!-- DataTable: search, show/hide columns (gear, remembered per user),
         column customization, refresh. Status/date filters sit in its toolbar. -->
    <DataTable :crud="crud" :columns="columns">
      <template #toolbar>
        <a-select
          v-model:value="status"
          style="width: 140px"
          allow-clear
          :placeholder="$t('Status')"
          :options="statusOptions"
          @change="crud.reload()"
        />
        <!-- Booking date range (one day = same start and end) -->
        <a-range-picker
          v-model:value="dateRange" value-format="YYYY-MM-DD" allow-clear style="width: 240px"
          :placeholder="[$t('start_date'), $t('to')]" @change="crud.reload()"
        />
        <!-- Start time of day, e.g. mornings only: 08:00 - 12:00 -->
        <a-time-range-picker
          v-model:value="timeRange" format="HH:mm" value-format="HH:mm" :minute-step="15" allow-clear
          style="width: 170px" :placeholder="[$t('Time_In'), $t('to')]" @change="crud.reload()"
        />
        <a-select
          v-model:value="customerId" style="width: 180px" allow-clear show-search option-filter-prop="label"
          :placeholder="$t('Customer')" :options="customerOptions" @change="crud.reload()"
        />
        <a-select
          v-model:value="serviceId" style="width: 200px" allow-clear show-search option-filter-prop="label"
          :placeholder="$t('Service') || 'Service'" :options="serviceOptions" @change="crud.reload()"
        />
        <a-select
          v-model:value="staffId" style="width: 170px" allow-clear show-search option-filter-prop="label"
          :placeholder="$t('Sales_Person')" :options="staffOptions" @change="crud.reload()"
        />
        <ResetFiltersButton :dirty="filtersDirty" @reset="resetFilters" />
      </template>
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
        <template v-else-if="column.key === 'actions'">
          <a-space>
            <a-tooltip :title="$t('Edit')">
              <a-button type="text" size="small" @click="$router.push(`/bookings/${record.id}/edit`)">
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
  </div>
</template>

<script setup>
/**
 * Bookings list: GET bookings → {bookings, totalRows, stats} with status and
 * date filters. Create/edit are BookingForm.vue; the calendar is
 * BookingCalendar.vue. Still on legacy: PDF and send-email.
 */
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { message } from 'ant-design-vue';
import {
  PlusOutlined, EditOutlined, DeleteOutlined, DownOutlined, CheckCircleOutlined,
  CheckSquareOutlined, StopOutlined,
} from '@ant-design/icons-vue';
import http from '../../lib/http';
import { sortByLabel } from '../../lib/filterOptions';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import ResetFiltersButton from '../../components/ResetFiltersButton.vue';
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
  || customerId.value || serviceId.value || staffId.value));

function resetFilters() {
  status.value = undefined;
  dateRange.value = null;
  timeRange.value = null;
  customerId.value = undefined;
  serviceId.value = undefined;
  staffId.value = undefined;
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
]);

// Filter dropdowns from the list payload (company-scoped), alphabetical.
const fo = computed(() => crud.payload.value?.filter_options || {});
const customerOptions = computed(() => sortByLabel((fo.value.customers || []).map(c => ({ value: c.id, label: c.name }))));
const serviceOptions = computed(() => sortByLabel((fo.value.services || []).map(s => ({
  value: s.id, label: s.group_name ? `${s.group_name} › ${s.name}` : s.name,
}))));
const staffOptions = computed(() => sortByLabel((fo.value.staff || []).map(s => ({ value: s.id, label: s.name }))));

const statusOptions = computed(() => [
  { value: 'pending', label: t('Pending') },
  { value: 'confirmed', label: t('Confirmed') },
  { value: 'completed', label: t('Completed') },
  { value: 'cancelled', label: t('Cancelled') },
]);

const columns = computed(() => [
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref', sorter: true },
  { title: t('Customer'), dataIndex: 'customer_name', key: 'customer_name' },
  { title: t('ProductName'), dataIndex: 'product_name', key: 'product_name' },
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
  { title: t('Action'), key: 'actions', width: 110, align: 'center' },
]);

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
