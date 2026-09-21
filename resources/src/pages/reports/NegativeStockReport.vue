<template>
  <ReportPage
    :title="$t('Negative_Stock_Report')"
    :breadcrumb="[$t('Reports'), $t('Negative_Stock_Report')]"
    :crud="crud"
    :columns="columns"
    :row-key="rowKey"
    export-endpoint="report/negative_stock"
    :export-params="filterParams"
    export-rows-key="rows"
  >
    <template #actions>
      <!-- Multi-Currency: view amounts converted (display-only) -->
      <ViewCurrencySelect />
    </template>
    <!-- Triage view over the WHOLE filtered set (backend summary /
         worst_products / by_warehouse), not the visible page. -->
    <template #chart>
      <!-- Alert banner: severity headline, or an all-clear state. -->
      <a-card size="small" class="alert-banner" :class="{ clear: !hasIssues }" style="margin-bottom: 16px">
        <div class="banner-inner">
          <span class="banner-icon" :class="{ clear: !hasIssues }">
            <CheckCircleOutlined v-if="!hasIssues && !crud.loading.value" />
            <WarningOutlined v-else />
          </span>
          <div class="banner-text">
            <div class="banner-headline">
              <a-spin v-if="crud.loading.value" size="small" />
              <template v-else-if="hasIssues">
                {{ fmt(summary.units_short) }} {{ tf('Units_Short', 'units short') }}
                <span class="banner-dim">· {{ fmt(summary.lines) }} {{ tf('Stock_Lines', 'stock lines') }}</span>
              </template>
              <template v-else>{{ tf('No_Negative_Stock', 'No negative stock — all clear.') }}</template>
            </div>
            <a-typography-text v-if="hasIssues && !crud.loading.value" type="secondary">
              {{ fmt(summary.products) }} {{ $t('Products') }}
              · {{ fmt(summary.warehouses_affected) }} {{ tf('Warehouses_Affected', 'warehouses affected') }}
              · {{ tf('Shortage_Value', 'shortage value') }} ≈ <strong>{{ money(summary.shortage_value) }}</strong>
            </a-typography-text>
            <a-typography-text v-else-if="!crud.loading.value" type="secondary">
              {{ tf('No_Negative_Stock_Hint', 'Every product is at zero or above in the selected scope.') }}
            </a-typography-text>
          </div>
        </div>
      </a-card>

      <a-row v-if="hasIssues" :gutter="[16, 16]" style="margin-bottom: 16px">
        <a-col :xs="24" :xl="15">
          <a-card size="small" class="chart-card" :title="tf('Worst_Offenders', 'Worst offenders')">
            <apexchart
              v-if="worstSeries[0].data.length"
              type="bar"
              height="320"
              :options="worstOptions"
              :series="worstSeries"
            />
            <a-empty v-else :description="$t('NodataAvailable')" style="padding: 48px 0" />
          </a-card>
        </a-col>
        <a-col :xs="24" :xl="9">
          <a-card size="small" class="chart-card" :title="tf('By_Warehouse', 'By warehouse')">
            <template v-if="byWarehouse.length">
              <div v-for="w in byWarehouse" :key="w.name" class="wh-row">
                <div class="wh-text">
                  <a-typography-text strong class="wh-name">{{ w.name }}</a-typography-text>
                  <a-typography-text type="secondary" class="wh-sub">
                    {{ fmt(w.lines) }} {{ tf('Stock_Lines', 'stock lines') }}
                  </a-typography-text>
                </div>
                <div class="wh-meter">
                  <span class="wh-units">-{{ fmt(w.units_short) }}</span>
                  <span class="wh-track">
                    <span class="wh-fill" :style="{ width: shareOf(w) + '%' }"></span>
                  </span>
                </div>
              </div>
            </template>
            <a-empty v-else :description="$t('NodataAvailable')" style="padding: 32px 0" />
          </a-card>
        </a-col>
      </a-row>
    </template>

    <template #filters>
      <a-select
        v-model:value="warehouseId" allow-clear show-search option-filter-prop="label" style="width: 200px"
        :placeholder="$t('warehouse')" :options="warehouseOptions" @change="crud.reload()"
      />
    </template>

    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'quantity'">
        <!-- Negative stock is the point of this report: make it obvious. -->
        <a-tag :color="Number(record.quantity) < 0 ? 'error' : 'default'">{{ record.quantity }}</a-tag>
      </template>
    </template>
  </ReportPage>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { WarningOutlined, CheckCircleOutlined } from '@ant-design/icons-vue';
import ReportPage from '../../components/ReportPage.vue';
import ViewCurrencySelect from '../../components/ViewCurrencySelect.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useUiStore } from '../../stores/ui';
import { t as tf } from '../../i18n';

const { t } = useI18n();
const { money } = useFormat();
const ui = useUiStore();

const warehouseId = ref(undefined);
const filterParams = () => ({
  ...(warehouseId.value ? { warehouse_id: warehouseId.value } : {}),
});

// Payload is { rows, totalRows, summary, worst_products, by_warehouse,
// warehouses } — rowsKey is required, otherwise row auto-detection could
// pick `warehouses`.
const crud = useCrudTable('report/negative_stock', { rowsKey: 'rows', params: filterParams });

const warehouseOptions = computed(() =>
  (crud.payload.value?.warehouses || []).map(w => ({ value: w.id, label: w.name }))
);

const fmt = v => (Number(v) || 0).toLocaleString();

/* --------------------------------------------------------------- banner */
const summary = computed(() => crud.payload.value?.summary || {});
const hasIssues = computed(() => (Number(summary.value.lines) || 0) > 0);

/* ----------------------------------------------- worst offenders (bars) */
const worstRows = computed(() => crud.payload.value?.worst_products || []);
const worstSeries = computed(() => [{
  name: tf('Units_Short', 'units short'),
  data: worstRows.value.map(r => Number(r.units_short) || 0),
}]);
const worstOptions = computed(() => {
  const axisColor = ui.dark ? '#d9d9d9' : '#595959';
  return {
    chart: { type: 'bar', background: 'transparent', fontFamily: 'inherit', toolbar: { show: false } },
    plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '60%' } },
    colors: ['#f43f5e'],
    dataLabels: { enabled: false },
    xaxis: {
      categories: worstRows.value.map(r => r.name),
      labels: { style: { colors: axisColor } },
      axisBorder: { show: false },
      axisTicks: { show: false },
    },
    yaxis: { labels: { style: { colors: axisColor }, maxWidth: 220 } },
    grid: { borderColor: 'rgba(128, 128, 128, 0.2)' },
    tooltip: {
      theme: ui.dark ? 'dark' : 'light',
      y: { formatter: v => `-${fmt(v)}` },
    },
  };
});

/* ----------------------------------------------------- warehouse meters */
const byWarehouse = computed(() => crud.payload.value?.by_warehouse || []);
const maxWarehouseShort = computed(() =>
  Math.max(1, ...byWarehouse.value.map(w => Number(w.units_short) || 0))
);
function shareOf(w) {
  return Math.round(((Number(w.units_short) || 0) / maxWarehouseShort.value) * 100);
}

/* ---------------------------------------------------------------- table */
// Rows are product/warehouse pairs with no single id field.
const rowKey = 'id';

const columns = computed(() => [
  { title: t('Ref'), dataIndex: 'code', key: 'code', sorter: true },
  { title: t('Name_product'), dataIndex: 'name', key: 'name', sorter: true },
  { title: t('warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name' },
  { title: t('Quantity'), key: 'quantity', dataIndex: 'quantity', sorter: true, align: 'right', sum: true, exportValue: r => r.quantity },
]);

onMounted(crud.fetchRows);
</script>

<style scoped>
.chart-card { border-radius: 10px; }

/* Severity banner: red accent while issues exist, green when all clear. */
.alert-banner {
  border-radius: 10px;
  border-inline-start: 4px solid #f43f5e;
  background: rgba(244, 63, 94, 0.05);
}
.alert-banner.clear {
  border-inline-start-color: #10b981;
  background: rgba(16, 185, 129, 0.05);
}
.banner-inner {
  display: flex;
  align-items: center;
  gap: 14px;
}
.banner-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  border-radius: 12px;
  font-size: 22px;
  flex: none;
  color: #f43f5e;
  background: rgba(244, 63, 94, 0.12);
}
.banner-icon.clear {
  color: #10b981;
  background: rgba(16, 185, 129, 0.12);
}
.banner-text { min-width: 0; }
.banner-headline {
  font-size: 18px;
  font-weight: 700;
  line-height: 1.3;
}
.banner-dim {
  font-weight: 400;
  font-size: 14px;
  opacity: 0.65;
}

/* Warehouse rows: name + line count, units short + share meter. */
.wh-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 8px 4px;
}
.wh-row + .wh-row { border-top: 1px solid rgba(128, 128, 128, 0.14); }
.wh-text {
  min-width: 0;
  display: flex;
  flex-direction: column;
}
.wh-name,
.wh-sub {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.wh-sub { font-size: 12px; }
.wh-meter {
  display: flex;
  align-items: center;
  gap: 8px;
  flex: none;
}
.wh-units {
  font-weight: 600;
  color: #f43f5e;
  min-width: 48px;
  text-align: end;
}
.wh-track {
  display: inline-block;
  width: 90px;
  height: 6px;
  border-radius: 999px;
  background: rgba(128, 128, 128, 0.2);
  overflow: hidden;
}
.wh-fill {
  display: block;
  height: 100%;
  border-radius: 999px;
  background: #f43f5e;
}
</style>
