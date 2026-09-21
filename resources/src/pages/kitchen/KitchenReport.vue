<template>
  <ReportPage
    :title="$t('KitchenReport')"
    :breadcrumb="[$t('Kitchen'), $t('KitchenReport')]"
    :crud="crud"
    :columns="columns"
    row-key="id"
    export-endpoint="kitchen/report"
    :export-params="filterParams"
    export-rows-key="report"
  >
    <template #filters>
      <DateRangePicker v-model:value="range" :allow-clear="false" @change="crud.reload()" />
    </template>

    <template #chart>
      <!-- KPI tiles — same stat-card design as the board and the Sales list -->
      <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
        <a-col v-for="k in kpiTiles" :key="k.label" :xs="12" :sm="8" :xl="4">
          <a-card size="small" class="stat-card">
            <div class="stat-inner">
              <div class="stat-icon" :style="{ background: k.tint, color: k.color }">
                <component :is="k.icon" />
              </div>
              <div class="stat-meta">
                <div class="stat-label">{{ k.label }}</div>
                <div class="stat-value">
                  {{ k.value }}<small v-if="k.suffix" class="stat-suffix">{{ k.suffix }}</small>
                </div>
                <div v-if="k.note" class="stat-note">{{ k.note }}</div>
              </div>
            </div>
          </a-card>
        </a-col>
      </a-row>

      <ReportChart
        :data="timeseries"
        :fields="chartFields"
        :title="$t('Avg_Prep_Min')"
        type="area"
      />

      <!-- Staff + top products side panels -->
      <a-row :gutter="[16, 16]" style="margin: 16px 0">
        <a-col :xs="24" :md="12">
          <a-card size="small" :title="$t('AssignedStaff')">
            <a-table
              :columns="staffColumns" :data-source="byStaff" size="small"
              :pagination="false" :row-key="r => r.staff_name"
            />
          </a-card>
        </a-col>
        <a-col :xs="24" :md="12">
          <a-card size="small" :title="$t('Top_Selling_Products')">
            <a-table
              :columns="productColumns" :data-source="topProducts" size="small"
              :pagination="false" :row-key="r => r.name"
            />
          </a-card>
        </a-col>
      </a-row>
    </template>

    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'ref'">
        {{ record.ref }}
        <a-tag v-if="record.token_number">#{{ record.token_number }}</a-tag>
      </template>
      <template v-else-if="column.key === 'source'">
        <a-tag v-if="record.source === 'online'" color="geekblue">{{ $t('OnlineOrder') }}</a-tag>
        <template v-else>{{ record.source === 'manual' ? $t('SendLater') : 'POS' }}</template>
      </template>
      <template v-else-if="column.key === 'status'">
        <a-tag :color="statusColor(record.status)">{{ statusLabel(record.status) }}</a-tag>
      </template>
      <template v-else-if="column.key === 'sent_at'">{{ dateTime(record.sent_at) }}</template>
      <template v-else-if="MINUTE_KEYS.includes(column.key)">
        <span :class="{ overdue: column.key === 'total_minutes' && isOverdue(record) }">
          {{ record[column.key] == null ? '—' : record[column.key] }}
        </span>
      </template>
    </template>
  </ReportPage>
</template>

<script setup>
/**
 * Kitchen performance report — per-ticket wait/prep/total minutes from the
 * timestamps the board already records (sent_at / started_at / completed_at),
 * with KPI tiles, a per-day prep trend, staff and top-product summaries.
 * Endpoint: GET kitchen/report (standard report paging contract).
 */
import { ref, computed, onMounted } from 'vue';
import dayjs from 'dayjs';
import { useI18n } from 'vue-i18n';
import {
  OrderedListOutlined, CheckCircleOutlined, HourglassOutlined,
  FireOutlined, ClockCircleOutlined, WarningOutlined,
} from '@ant-design/icons-vue';
import ReportPage from '../../components/ReportPage.vue';
import ReportChart from '../../components/ReportChart.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import DateRangePicker from '../../components/DateRangePicker.vue';

const { t } = useI18n();
const { dateTime } = useFormat();

const MINUTE_KEYS = ['wait_minutes', 'prep_minutes', 'total_minutes'];

const range = ref([dayjs().subtract(29, 'day'), dayjs()]);

const filterParams = () => ({
  from: range.value?.[0]?.format?.('YYYY-MM-DD') || '',
  to: range.value?.[1]?.format?.('YYYY-MM-DD') || '',
});

const crud = useCrudTable('kitchen/report', {
  rowsKey: 'report',
  sortField: 'sent_at',
  sortType: 'desc',
  params: filterParams,
});

const kpis = computed(() => crud.payload.value?.kpis || {});
const timeseries = computed(() => crud.payload.value?.timeseries || []);
const byStaff = computed(() => crud.payload.value?.by_staff || []);
const topProducts = computed(() => crud.payload.value?.top_products || []);

/** Lane-style stat cards: soft-tinted icon square + label + value, matching the
 *  Kitchen Display board and the Sales list. Minute tiles carry a small unit
 *  suffix instead of cramming "(min)" into the value. */
const kpiTiles = computed(() => {
  const k = kpis.value;
  const mins = v => (v == null ? '—' : v);
  const tiles = [
    {
      label: t('Tickets'), value: k.tickets ?? 0,
      icon: OrderedListOutlined, color: '#64748b', tint: 'rgba(100, 116, 139, 0.14)',
    },
    {
      label: t('Completed'), value: k.completed ?? 0,
      icon: CheckCircleOutlined, color: '#22c55e', tint: 'rgba(34, 197, 94, 0.12)',
    },
    {
      label: t('Avg_Wait_Min'), value: mins(k.avg_wait), suffix: k.avg_wait != null ? 'min' : '',
      icon: HourglassOutlined, color: '#f59e0b', tint: 'rgba(245, 158, 11, 0.14)',
    },
    {
      label: t('Avg_Prep_Min'), value: mins(k.avg_prep), suffix: k.avg_prep != null ? 'min' : '',
      icon: FireOutlined, color: '#3b82f6', tint: 'rgba(59, 130, 246, 0.12)',
    },
    {
      label: t('Avg_Total_Min'), value: mins(k.avg_total), suffix: k.avg_total != null ? 'min' : '',
      icon: ClockCircleOutlined, color: '#6d28d9', tint: 'rgba(109, 40, 217, 0.12)',
    },
  ];
  if (k.target_minutes) {
    tiles.push({
      label: t('Overdue'), value: k.overdue ?? 0,
      note: `> ${k.target_minutes} min`,
      icon: WarningOutlined, color: '#ef4444', tint: 'rgba(239, 68, 68, 0.12)',
    });
  }
  return tiles;
});

const chartFields = computed(() => [{ key: 'avg_prep', label: t('Avg_Prep_Min') }]);

/** Overdue against the same target the board uses (only meaningful when set). */
function isOverdue(record) {
  const target = kpis.value.target_minutes;
  return !!(target && record.total_minutes != null && record.total_minutes > target);
}

const STATUS_LABELS = {
  pending: 'PendingOrders', preparing: 'PreparingOrders',
  completed: 'CompletedOrders', on_hold: 'OnHoldOrders',
};
function statusLabel(s) {
  return STATUS_LABELS[s] ? t(STATUS_LABELS[s]) : s;
}
function statusColor(s) {
  return { pending: 'default', preparing: 'processing', completed: 'success', on_hold: 'warning' }[s] || 'default';
}

const columns = computed(() => [
  { title: t('Order'), key: 'ref', dataIndex: 'ref', sorter: true, exportValue: r => r.ref },
  { title: t('Source'), key: 'source', dataIndex: 'source', sorter: true },
  { title: t('Customer'), key: 'customer_name', dataIndex: 'customer_name' },
  { title: t('AssignedStaff'), key: 'staff_name', dataIndex: 'staff_name', sorter: true },
  { title: t('date'), key: 'sent_at', dataIndex: 'sent_at', sorter: true, exportValue: r => dateTime(r.sent_at) },
  { title: t('Wait_Min'), key: 'wait_minutes', dataIndex: 'wait_minutes', align: 'right', sorter: true },
  { title: t('Prep_Min'), key: 'prep_minutes', dataIndex: 'prep_minutes', align: 'right', sorter: true },
  { title: t('Total_Min'), key: 'total_minutes', dataIndex: 'total_minutes', align: 'right', sorter: true },
  { title: t('Status'), key: 'status', dataIndex: 'status', sorter: true, exportValue: r => statusLabel(r.status) },
]);

const staffColumns = computed(() => [
  { title: t('AssignedStaff'), dataIndex: 'staff_name', key: 'staff_name' },
  { title: t('Tickets'), dataIndex: 'tickets', key: 'tickets', align: 'right' },
  { title: t('Completed'), dataIndex: 'completed', key: 'completed', align: 'right' },
  { title: t('Avg_Prep_Min'), dataIndex: 'avg_prep', key: 'avg_prep', align: 'right' },
]);

const productColumns = computed(() => [
  { title: t('Product'), dataIndex: 'name', key: 'name' },
  { title: t('Quantity'), dataIndex: 'qty', key: 'qty', align: 'right' },
]);

onMounted(crud.fetchRows);
</script>

<style scoped>
.overdue {
  color: #dc2626;
  font-weight: 700;
}

/* Stat tiles — same design as the Kitchen Display board / Sales list cards. */
.stat-card {
  border-radius: 10px;
  height: 100%;
}
.stat-inner {
  display: flex;
  align-items: center;
  gap: 12px;
}
.stat-icon {
  width: 44px;
  height: 44px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  flex: none;
}
.stat-meta {
  min-width: 0;
}
.stat-label {
  opacity: 0.65;
  font-size: 13px;
  margin-bottom: 2px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.stat-value {
  font-size: 20px;
  font-weight: 700;
  white-space: nowrap;
  line-height: 1.2;
}
.stat-suffix {
  font-size: 12px;
  font-weight: 600;
  opacity: 0.6;
  margin-left: 4px;
}
.stat-note {
  font-size: 11px;
  opacity: 0.55;
  white-space: nowrap;
}
@media (max-width: 575px) {
  .stat-inner {
    gap: 8px;
  }
  .stat-icon {
    width: 36px;
    height: 36px;
    font-size: 16px;
  }
  .stat-value {
    font-size: 16px;
  }
}
</style>
