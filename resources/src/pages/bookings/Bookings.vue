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

    <a-card :body-style="{ padding: 0 }">
      <div style="display: flex; gap: 12px; flex-wrap: wrap; padding: 16px; border-bottom: 1px solid rgba(5,5,5,0.06)">
        <a-input-search
          :value="crud.search.value"
          :placeholder="$t('Search_this_table')"
          allow-clear
          style="max-width: 260px"
          @update:value="v => (crud.search.value = v)"
          @search="crud.reload()"
        />
        <a-select
          v-model:value="status"
          style="width: 160px"
          allow-clear
          :placeholder="$t('Status')"
          :options="statusOptions"
          @change="crud.reload()"
        />
        <a-date-picker v-model:value="day" value-format="YYYY-MM-DD" allow-clear @change="crud.reload()" />
      </div>

      <a-table
        :columns="columns"
        :data-source="crud.rows.value"
        :loading="crud.loading.value"
        :pagination="crud.pagination.value"
        row-key="id"
        :scroll="{ x: 'max-content' }"
        size="middle"
        @change="crud.onChange"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'booking_date'">{{ date(record.booking_date) }}</template>
          <template v-else-if="column.key === 'price'">{{ money(record.price) }}</template>
          <template v-else-if="column.key === 'status'">
            <a-tag :color="statusColor(record.status)">{{ statusLabel(record.status) }}</a-tag>
          </template>
          <template v-else-if="column.key === 'sale'">
            <router-link v-if="record.sale_id" :to="`/sales/${record.sale_id}`">{{ record.sale_ref }}</router-link>
            <a-tooltip v-else-if="record.status === 'completed'" :title="$t('Booking_create_invoice_help')">
              <a-button size="small" :loading="creatingSale === record.id" @click="createSale(record)">
                <template #icon><FileAddOutlined /></template>
                {{ $t('Create_Invoice') }}
              </a-button>
            </a-tooltip>
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
      </a-table>
    </a-card>
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
import { PlusOutlined, EditOutlined, DeleteOutlined, FileAddOutlined } from '@ant-design/icons-vue';
import http from '../../lib/http';
import PageHeader from '../../components/PageHeader.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';

const { t } = useI18n();
const { money, date } = useFormat();

const status = ref(undefined);
const day = ref(null);

const filterParams = () => ({
  ...(status.value ? { status: status.value } : {}),
  ...(day.value ? { date: day.value } : {}),
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
const tiles = computed(() => [
  { label: t('Total'), value: stats.value.total || 0 },
  { label: t('Pending'), value: stats.value.pending || 0, style: { color: '#faad14' } },
  { label: t('Confirmed'), value: stats.value.confirmed || 0, style: { color: '#1677ff' } },
  { label: t('Completed'), value: stats.value.completed || 0, style: { color: '#52c41a' } },
]);

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
  { title: t('Price'), key: 'price', dataIndex: 'price', align: 'right', exportValue: r => money(r.price) },
  { title: t('date'), key: 'booking_date', dataIndex: 'booking_date', sorter: true, exportValue: r => date(r.booking_date) },
  { title: t('Time_In'), dataIndex: 'booking_time', key: 'booking_time' },
  { title: t('Time_Out'), dataIndex: 'booking_end_time', key: 'booking_end_time' },
  { title: t('Status'), key: 'status', dataIndex: 'status', exportValue: r => statusLabel(r.status) },
  { title: t('Invoice'), key: 'sale', dataIndex: 'sale_ref', exportValue: r => r.sale_ref || '' },
  { title: t('Action'), key: 'actions', width: 110, align: 'center' },
]);

function statusLabel(s) {
  const map = {
    pending: t('Pending'), confirmed: t('Confirmed'),
    completed: t('Completed'), cancelled: t('Cancelled'),
  };
  return map[s] || s;
}

function statusColor(s) {
  if (s === 'confirmed') return 'processing';
  if (s === 'completed') return 'success';
  if (s === 'pending') return 'warning';
  if (s === 'cancelled') return 'error';
  return 'default';
}

// Completed bookings are invoiced automatically (paid in cash); this retries
// one that couldn't be, e.g. it had no service when it was completed.
const creatingSale = ref(null);
async function createSale(record) {
  creatingSale.value = record.id;
  try {
    const res = await http.post(`bookings/${record.id}/sale`);
    message.success(`${t('Invoice')} ${res.sale_ref}`);
    crud.reload();
  } catch (e) {
    message.error(e?.data?.message || e?.message || t('Error'));
  } finally {
    creatingSale.value = null;
  }
}

onMounted(crud.fetchRows);
</script>
