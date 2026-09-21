<template>
  <div class="page">
    <PageHeader
      title="MRP Planning"
      subtitle="What to make and what to buy, worked out from open demand."
      :breadcrumb="['Manufacturing', 'Planning']"
    >
      <template #actions>
        <a-button type="primary" :loading="running" @click="runOpen = true">
          <template #icon><CalculatorOutlined /></template>
          Run planning
        </a-button>
      </template>
    </PageHeader>

    <a-alert
      v-if="latestRun?.last_error" type="warning" show-icon style="margin-bottom: 16px"
      message="The last run skipped something" :description="latestRun.last_error"
    />

    <a-card v-if="latestRun" size="small" style="margin-bottom: 16px">
      <div class="run-head">
        <div class="run-meta">
          <strong>{{ latestRun.reference }}</strong>
          <span class="sub">
            {{ latestRun.warehouse_name }} ·
            {{ latestRun.horizon_start }} to {{ latestRun.horizon_end }} ·
            {{ latestRun.demand_lines }} demand line(s)
          </span>
        </div>
        <a-space wrap>
          <a-tag color="purple">{{ latestRun.make_suggestions }} to make</a-tag>
          <a-tag color="blue">{{ latestRun.buy_suggestions }} to buy</a-tag>
          <a-button
            v-if="pendingMake > 0" size="small" type="primary"
            :loading="accepting" @click="acceptAll"
          >
            Create {{ pendingMake }} production order(s)
          </a-button>
        </a-space>
      </div>
    </a-card>

    <a-card size="small" style="margin-bottom: 16px">
      <div class="toolbar">
        <a-input-search
          v-model:value="crud.search.value" placeholder="Search product…"
          allow-clear class="tb-search" @search="crud.reload"
        />
        <a-select
          v-model:value="filters.action" class="tb-item" allow-clear
          placeholder="Make and buy" :options="SUGGESTION_ACTIONS" @change="crud.reload"
        />
        <a-select
          v-model:value="filters.status" class="tb-item" allow-clear
          placeholder="All statuses" :options="SUGGESTION_STATUSES" @change="crud.reload"
        />
        <a-select
          v-model:value="filters.planning_run_id" class="tb-item tb-run" allow-clear
          placeholder="Latest run" :options="runOptions" @change="crud.reload"
        />
      </div>
    </a-card>

    <a-alert
      type="info" show-icon banner style="margin-bottom: 12px"
      message="Net requirement = demand − stock on hand − already incoming + safety stock. Accepting a suggestion creates a DRAFT order; nothing is issued until you release it."
    />

    <DataTable :crud="crud" :columns="columns" :searchable="false">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'product'">
          {{ record.product_name }}
          <div class="sub">{{ record.product_code }} · level {{ record.level }}</div>
        </template>
        <template v-else-if="column.key === 'action'">
          <a-tag :color="optionOf(SUGGESTION_ACTIONS, record.action).color">
            {{ labelOf(SUGGESTION_ACTIONS, record.action) }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'workings'">
          <span class="workings">
            {{ number(record.gross_requirement, 2) }}
            <span class="op">−</span> {{ number(record.on_hand, 2) }}
            <span class="op">−</span> {{ number(record.incoming, 2) }}
            <template v-if="record.safety_stock">
              <span class="op">+</span> {{ number(record.safety_stock, 2) }}
            </template>
          </span>
          <div class="sub">demand − on hand − incoming{{ record.safety_stock ? ' + safety' : '' }}</div>
        </template>
        <template v-else-if="column.key === 'suggested_qty'">
          <strong>{{ number(record.suggested_qty, 2) }}</strong>
        </template>
        <template v-else-if="column.key === 'status'">
          <a-tag :color="optionOf(SUGGESTION_STATUSES, record.status).color">
            {{ labelOf(SUGGESTION_STATUSES, record.status) }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space :size="0">
            <a-button
              v-if="record.status === 'pending' && record.action === 'make'"
              type="link" size="small" @click="accept(record)"
            >
              Create order
            </a-button>
            <a-button
              v-else-if="record.status === 'accepted' && record.created_order_id"
              type="link" size="small"
              @click="$router.push(`/mrp/production-orders/${record.created_order_id}`)"
            >
              View order
            </a-button>
            <a-button
              v-if="record.status === 'pending'" type="link" size="small" danger
              @click="dismiss(record)"
            >
              Dismiss
            </a-button>
          </a-space>
        </template>
      </template>
    </DataTable>

    <a-modal
      :open="runOpen" title="Run planning" :width="520"
      :confirm-loading="running" ok-text="Run" :cancel-text="$t('Cancel')"
      @ok="run" @cancel="runOpen = false"
    >
      <a-alert
        type="info" show-icon banner style="margin-bottom: 14px"
        message="Reads open sales in the window, explodes bills of materials down every level, and nets off what you already have or have coming."
      />
      <a-form layout="vertical">
        <a-form-item :label="$t('Warehouse')" extra="Leave blank to plan across all of them">
          <a-select
            v-model:value="runForm.warehouse_id" show-search option-filter-prop="label"
            :options="warehouseOptions" allow-clear placeholder="All warehouses"
          />
        </a-form-item>
        <a-form-item label="Demand window">
          <a-range-picker v-model:value="runForm.range" style="width: 100%" value-format="YYYY-MM-DD" />
        </a-form-item>
        <a-form-item style="margin-bottom: 0">
          <a-checkbox v-model:checked="runForm.include_safety_stock">
            Keep safety stock on top of demand
          </a-checkbox>
          <div class="hint">Uses each product's stock alert level.</div>
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * The planning screen.
 *
 * The workings column shows the whole sum, not just the answer — a planner who
 * cannot see why 18 was suggested has no way to tell a real shortage from stale
 * data, and will not trust the number.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { CalculatorOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { SUGGESTION_ACTIONS, SUGGESTION_STATUSES, labelOf, optionOf } from './mrpOptions';
import http from '../../lib/http';

const { number } = useFormat();

const filters = reactive({ action: undefined, status: undefined, planning_run_id: undefined });

const crud = useCrudTable('mrp/planning/suggestions', {
  rowsKey: 'suggestions',
  params: () => ({
    action: filters.action || '',
    status: filters.status || '',
    planning_run_id: filters.planning_run_id || '',
  }),
});

const columns = computed(() => [
  { title: 'Product', key: 'product', dataIndex: 'product_name' },
  { title: 'Action', key: 'action', dataIndex: 'action', width: 100 },
  { title: 'Workings', key: 'workings', width: 260 },
  { title: 'Net need', dataIndex: 'net_requirement', key: 'net_requirement', width: 110, align: 'right' },
  { title: 'Suggested', key: 'suggested_qty', dataIndex: 'suggested_qty', width: 110, align: 'right' },
  { title: 'By', dataIndex: 'required_by', key: 'required_by', width: 120 },
  { title: 'Status', key: 'status', dataIndex: 'status', width: 120 },
  { title: '', key: 'actions', width: 180, align: 'center' },
]);

const runs = ref([]);
const warehouses = ref([]);
const latestRun = computed(() => {
  if (filters.planning_run_id) return runs.value.find(r => r.id === filters.planning_run_id) || runs.value[0];
  return runs.value[0];
});
const runOptions = computed(() => runs.value.map(r => ({
  value: r.id,
  label: `${r.reference} — ${r.make_suggestions + r.buy_suggestions} suggestion(s)`,
})));
const warehouseOptions = computed(() => warehouses.value.map(w => ({ value: w.id, label: w.name })));

const pendingMake = computed(
  () => (crud.rows.value || []).filter(r => r.status === 'pending' && r.action === 'make').length,
);

// ---------------- run ----------------

const runOpen = ref(false);
const running = ref(false);
const runForm = reactive({ warehouse_id: undefined, range: null, include_safety_stock: true });

async function run() {
  running.value = true;
  try {
    const res = await http.post('mrp/planning/run', {
      warehouse_id: runForm.warehouse_id,
      horizon_start: runForm.range?.[0] || '',
      horizon_end: runForm.range?.[1] || '',
      include_safety_stock: runForm.include_safety_stock,
    });
    const r = res?.run;
    message.success(r
      ? `${r.make_suggestions} to make, ${r.buy_suggestions} to buy`
      : 'Planning finished');
    runOpen.value = false;
    filters.planning_run_id = undefined;
    await loadRuns();
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || 'Planning failed');
  } finally {
    running.value = false;
  }
}

// ---------------- suggestions ----------------

const accepting = ref(false);

async function accept(record) {
  try {
    const res = await http.post(`mrp/planning/suggestions/${record.id}/accept`);
    message.success(`Draft order ${res?.reference || ''} created`);
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || 'Could not create that order');
  }
}

async function acceptAll() {
  if (!latestRun.value) return;
  accepting.value = true;
  try {
    const res = await http.post('mrp/planning/suggestions/accept-all', {
      planning_run_id: latestRun.value.id,
    });
    const failed = res?.failed?.length || 0;
    message.success(`${res?.created || 0} draft order(s) created`
      + (failed ? `, ${failed} could not be raised` : ''));
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || 'Could not create those orders');
  } finally {
    accepting.value = false;
  }
}

async function dismiss(record) {
  try {
    await http.post(`mrp/planning/suggestions/${record.id}/dismiss`);
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || 'Could not dismiss that suggestion');
  }
}

async function loadRuns() {
  try {
    const res = await http.get('mrp/planning/runs', { limit: 25 });
    runs.value = res?.runs || [];
  } catch (e) {
    runs.value = [];
  }
}

onMounted(async () => {
  crud.fetchRows();
  loadRuns();
  try {
    const meta = await http.get('mrp/meta');
    warehouses.value = meta?.warehouses || [];
  } catch (e) { /* the warehouse select stays empty */ }
});
</script>

<style scoped>
.sub {
  font-size: 11.5px;
  opacity: 0.55;
}
.hint {
  font-size: 11.5px;
  opacity: 0.55;
  margin-top: 2px;
}
.run-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}
.run-meta {
  display: flex;
  flex-direction: column;
}
.workings {
  font-size: 12.5px;
  font-variant-numeric: tabular-nums;
}
.op {
  opacity: 0.45;
  margin: 0 1px;
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}
.tb-search {
  flex: 1 1 200px;
  min-width: 170px;
}
.tb-item {
  width: 160px;
}
.tb-run {
  width: 240px;
}
@media (max-width: 767px) {
  .tb-item,
  .tb-run {
    width: 100%;
  }
}
</style>
