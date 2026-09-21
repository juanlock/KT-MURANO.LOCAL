<template>
  <div class="page">
    <PageHeader
      :title="order.reference || 'Production order'"
      :subtitle="order.product_name ? `${order.product_name} · ${number(order.qty_planned, 0)} planned` : ''"
      :breadcrumb="['Manufacturing', 'Production Orders', order.reference || '']"
    >
      <template #actions>
        <a-button @click="$router.push('/mrp/production-orders')">
          <template #icon><ArrowLeftOutlined /></template>
          {{ $t('Back') }}
        </a-button>
        <a-button v-if="canEdit(order)" @click="$router.push(`/mrp/production-orders/${id}/edit`)">
          <template #icon><EditOutlined /></template>
          {{ $t('Edit') }}
        </a-button>
        <a-button v-if="canRelease(order)" type="primary" :loading="busy" @click="release()">
          <template #icon><PlayCircleOutlined /></template>
          Release
        </a-button>
        <a-button v-if="canComplete(order)" type="primary" @click="openComplete">
          <template #icon><CheckOutlined /></template>
          Complete
        </a-button>
        <a-button v-if="canCancel(order)" danger @click="confirmCancel">
          <template #icon><StopOutlined /></template>
          Cancel
        </a-button>
      </template>
    </PageHeader>

    <a-spin :spinning="loading">
      <!-- lifecycle -->
      <a-card size="small" style="margin-bottom: 16px">
        <a-steps
          v-if="order.status !== 'cancelled'"
          :current="currentStep" size="small" :status="stepStatus"
          :items="stepItems"
        />
        <a-alert
          v-else type="error" show-icon banner
          message="This order was cancelled. Any material issued was returned to stock."
        />
      </a-card>

      <a-alert
        v-if="shortages.length && canRelease(order)"
        type="warning" show-icon style="margin-bottom: 16px"
        :message="`${shortages.length} component(s) are not fully in stock`"
        description="Release is blocked until the stock is there, or until you confirm going negative."
      />

      <div class="kpis">
        <div class="kpi">
          <span class="kpi-label">Status</span>
          <span class="kpi-value">
            <a-tag :color="optionOf(ORDER_STATUSES, order.status).color">
              {{ labelOf(ORDER_STATUSES, order.status) }}
            </a-tag>
            <a-tag v-if="order.priority && order.priority !== 'normal'" :color="optionOf(PRIORITIES, order.priority).color">
              {{ labelOf(PRIORITIES, order.priority) }}
            </a-tag>
          </span>
          <span class="kpi-sub">{{ order.warehouse_name || '' }}</span>
        </div>
        <div class="kpi">
          <span class="kpi-label">Output</span>
          <span class="kpi-value">{{ number(order.qty_produced, 0) }}<span class="kpi-of">/{{ number(order.qty_planned, 0) }}</span></span>
          <span class="kpi-sub">
            <template v-if="order.qty_scrapped">{{ number(order.qty_scrapped, 0) }} scrapped</template>
            <template v-else-if="order.yield_pct !== null">{{ order.yield_pct }}% yield</template>
          </span>
        </div>
        <div class="kpi kpi--accent">
          <span class="kpi-label">Actual cost</span>
          <span class="kpi-value">{{ money(order.total_cost || 0) }}</span>
          <span class="kpi-sub">
            <template v-if="order.unit_cost">{{ money(order.unit_cost) }} per unit</template>
            <template v-else>not yet costed</template>
          </span>
        </div>
        <div class="kpi">
          <span class="kpi-label">Against plan</span>
          <span class="kpi-value kpi-value--sm">
            <a-tag v-if="order.cost_variance_pct !== null" :color="varianceColor(order.cost_variance_pct)">
              {{ signedPct(order.cost_variance_pct) }}
            </a-tag>
            <span v-else class="muted">—</span>
          </span>
          <span class="kpi-sub">planned {{ money(order.planned_cost || 0) }}</span>
        </div>
        <div class="kpi">
          <span class="kpi-label">Shop-floor time</span>
          <span class="kpi-value kpi-value--sm">{{ hours(order.actual_minutes) }}</span>
          <span class="kpi-sub">of {{ hours(order.planned_minutes) }} allowed</span>
        </div>
      </div>

      <a-card size="small">
        <a-tabs v-model:activeKey="tab">
          <!-- ------------------------------------------- materials -->
          <a-tab-pane key="materials">
            <template #tab>
              <span>Materials <a-badge :count="materials.length" :number-style="badgeStyle" /></span>
            </template>
            <a-table
              size="small" :columns="materialColumns" :data-source="materials"
              row-key="id" :pagination="false" :scroll="{ x: 900 }"
            >
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'product'">
                  {{ record.product_name }}
                  <div class="sub">{{ record.product_code }}</div>
                </template>
                <template v-else-if="column.key === 'on_hand'">
                  <span :class="record.on_hand < record.qty_required - record.qty_consumed ? 'bad' : ''">
                    {{ number(record.on_hand, 2) }}
                  </span>
                </template>
                <template v-else-if="column.key === 'shortfall'">
                  <a-tag v-if="record.shortfall > 0" color="warning">{{ number(record.shortfall, 2) }}</a-tag>
                  <span v-else class="muted">—</span>
                </template>
                <template v-else-if="['unit_cost', 'total_cost'].includes(column.key)">
                  {{ money(record[column.key]) }}
                </template>
                <template v-else-if="column.key === 'is_optional'">
                  <a-tag v-if="record.is_optional">Optional</a-tag>
                </template>
              </template>
              <template #summary>
                <a-table-summary-row>
                  <a-table-summary-cell :col-span="6" />
                  <a-table-summary-cell align="right"><strong>Material cost</strong></a-table-summary-cell>
                  <a-table-summary-cell align="right">
                    <strong>{{ money(order.material_cost || 0) }}</strong>
                  </a-table-summary-cell>
                  <a-table-summary-cell />
                </a-table-summary-row>
              </template>
            </a-table>
          </a-tab-pane>

          <!-- ------------------------------------------- routing -->
          <a-tab-pane key="routing">
            <template #tab>
              <span>Routing <a-badge :count="workOrders.length" :number-style="badgeStyle" /></span>
            </template>
            <a-table
              size="small" :columns="workOrderColumns" :data-source="workOrders"
              row-key="id" :pagination="false" :scroll="{ x: 1000 }"
            >
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'status'">
                  <a-tag :color="optionOf(WORK_ORDER_STATUSES, record.status).color">
                    {{ labelOf(WORK_ORDER_STATUSES, record.status) }}
                  </a-tag>
                </template>
                <template v-else-if="column.key === 'time'">
                  {{ number(record.actual_minutes, 0) }} / {{ number(record.planned_minutes, 0) }} min
                  <a-tag
                    v-if="record.time_variance_pct !== null" class="mini"
                    :color="varianceColor(record.time_variance_pct)"
                  >
                    {{ signedPct(record.time_variance_pct) }}
                  </a-tag>
                </template>
                <template v-else-if="column.key === 'labour_cost'">{{ money(record.labour_cost) }}</template>
                <template v-else-if="column.key === 'actions'">
                  <a-space :size="0">
                    <a-button
                      v-if="record.status === 'pending'" type="link" size="small"
                      :disabled="!canWork" @click="startStep(record)"
                    >
                      Start
                    </a-button>
                    <a-button
                      v-else-if="record.status === 'in_progress'" type="link" size="small"
                      @click="openFinish(record)"
                    >
                      Finish
                    </a-button>
                  </a-space>
                </template>
              </template>
            </a-table>
          </a-tab-pane>

          <!-- ------------------------------------------- quality -->
          <a-tab-pane key="quality">
            <template #tab>
              <span>Quality <a-badge :count="checks.length" :number-style="badgeStyle" /></span>
            </template>
            <div class="tab-actions">
              <a-button size="small" type="primary" @click="openQc">
                <template #icon><PlusOutlined /></template>
                Record inspection
              </a-button>
              <span v-if="order.qc_required" class="tab-note">
                A final inspection is required before this order can be completed.
              </span>
            </div>
            <a-empty v-if="!checks.length" :image="simpleImage" description="No inspections yet" />
            <a-collapse v-else ghost>
              <a-collapse-panel v-for="c in checks" :key="c.id">
                <template #header>
                  <span class="qc-head">
                    <a-tag :color="optionOf(QC_STATUSES, c.status).color">{{ labelOf(QC_STATUSES, c.status) }}</a-tag>
                    <strong>{{ c.reference }}</strong>
                    <span class="sub">{{ labelOf(QC_TYPES, c.type) }}</span>
                    <span class="sub">
                      {{ number(c.qty_passed, 0) }}/{{ number(c.qty_inspected, 0) }} passed
                      <template v-if="c.pass_rate !== null">({{ c.pass_rate }}%)</template>
                    </span>
                  </span>
                </template>
                <a-table
                  v-if="c.lines?.length" size="small" :columns="qcLineColumns"
                  :data-source="c.lines" row-key="id" :pagination="false"
                >
                  <template #bodyCell="{ column, record }">
                    <template v-if="column.key === 'result'">
                      <a-tag :color="record.result === 'fail' ? 'error' : 'success'">
                        {{ record.result === 'fail' ? 'Fail' : 'Pass' }}
                      </a-tag>
                    </template>
                  </template>
                </a-table>
                <p v-if="c.notes" class="qc-notes">{{ c.notes }}</p>
              </a-collapse-panel>
            </a-collapse>
          </a-tab-pane>

          <!-- ------------------------------------------- costing -->
          <a-tab-pane key="costing" tab="Costing">
            <a-row :gutter="16">
              <a-col :xs="24" :md="12">
                <a-descriptions bordered size="small" :column="1" title="Cost build-up">
                  <a-descriptions-item label="Material">{{ money(order.material_cost || 0) }}</a-descriptions-item>
                  <a-descriptions-item label="Labour">{{ money(order.labour_cost || 0) }}</a-descriptions-item>
                  <a-descriptions-item label="Overhead">{{ money(order.overhead_cost || 0) }}</a-descriptions-item>
                  <a-descriptions-item label="Total"><strong>{{ money(order.total_cost || 0) }}</strong></a-descriptions-item>
                  <a-descriptions-item label="Planned">{{ money(order.planned_cost || 0) }}</a-descriptions-item>
                  <a-descriptions-item label="Variance">
                    <a-tag v-if="order.cost_variance !== null" :color="varianceColor(order.cost_variance_pct)">
                      {{ money(order.cost_variance) }} ({{ signedPct(order.cost_variance_pct) }})
                    </a-tag>
                    <span v-else class="muted">Available once the order completes</span>
                  </a-descriptions-item>
                </a-descriptions>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-descriptions bordered size="small" :column="1" title="Output">
                  <a-descriptions-item label="Planned">{{ number(order.qty_planned, 2) }}</a-descriptions-item>
                  <a-descriptions-item label="Produced">{{ number(order.qty_produced, 2) }}</a-descriptions-item>
                  <a-descriptions-item label="Scrapped">{{ number(order.qty_scrapped, 2) }}</a-descriptions-item>
                  <a-descriptions-item label="Yield">
                    {{ order.yield_pct !== null ? order.yield_pct + '%' : '—' }}
                  </a-descriptions-item>
                  <a-descriptions-item label="Scrap rate">
                    {{ order.scrap_pct !== null ? order.scrap_pct + '%' : '—' }}
                  </a-descriptions-item>
                  <a-descriptions-item label="Unit cost">
                    <strong>{{ order.unit_cost ? money(order.unit_cost) : '—' }}</strong>
                  </a-descriptions-item>
                </a-descriptions>
                <p class="cost-note">
                  Unit cost divides the total by the <strong>good</strong> quantity only —
                  scrap is absorbed by the units that survived.
                </p>
              </a-col>
            </a-row>
          </a-tab-pane>
        </a-tabs>
      </a-card>
    </a-spin>

    <!-- complete -->
    <a-modal
      :open="completeOpen" title="Complete this order" :width="520"
      :confirm-loading="busy" ok-text="Complete" :cancel-text="$t('Cancel')"
      @ok="submitComplete" @cancel="completeOpen = false"
    >
      <a-alert
        type="info" show-icon banner style="margin-bottom: 14px"
        message="Good units go into stock; scrap does not. Both are recorded so the yield figure means something."
      />
      <a-form layout="vertical">
        <a-form-item label="Good units produced *">
          <a-input-number v-model:value="completeForm.qty_produced" :min="0" style="width: 100%" />
        </a-form-item>
        <a-form-item label="Scrapped" style="margin-bottom: 0">
          <a-input-number v-model:value="completeForm.qty_scrapped" :min="0" style="width: 100%" />
        </a-form-item>
      </a-form>
      <p v-if="estimatedUnitCost" class="estimate">
        At those numbers the unit cost lands near <strong>{{ money(estimatedUnitCost) }}</strong>.
      </p>
    </a-modal>

    <!-- finish a routing step -->
    <a-modal
      :open="finishOpen" title="Finish this step" :width="480"
      :confirm-loading="busy" ok-text="Finish" :cancel-text="$t('Cancel')"
      @ok="submitFinish" @cancel="finishOpen = false"
    >
      <a-form layout="vertical">
        <a-form-item label="Minutes taken" extra="Leave blank to use the clock since the step was started">
          <a-input-number v-model:value="finishForm.actual_minutes" :min="0" style="width: 100%" />
        </a-form-item>
        <a-form-item label="Operator">
          <a-select
            v-model:value="finishForm.employee_id" show-search option-filter-prop="label"
            :options="employeeOptions" allow-clear placeholder="Who did the work"
          />
        </a-form-item>
        <a-row :gutter="12">
          <a-col :span="12">
            <a-form-item label="Good">
              <a-input-number v-model:value="finishForm.qty_completed" :min="0" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item label="Rejected">
              <a-input-number v-model:value="finishForm.qty_rejected" :min="0" style="width: 100%" />
            </a-form-item>
          </a-col>
        </a-row>
        <a-form-item :label="$t('Note')" style="margin-bottom: 0">
          <a-textarea v-model:value="finishForm.notes" :rows="2" allow-clear />
        </a-form-item>
      </a-form>
    </a-modal>

    <!-- quality check -->
    <QualityCheckModal
      v-model:open="qcOpen"
      :production-order-id="Number(id)"
      :default-qty="order.qty_planned"
      @saved="onQcSaved"
    />
  </div>
</template>

<script setup>
/**
 * One production order end to end: materials, routing, quality and costing.
 *
 * The lifecycle bar at the top is the point of the page — someone opening it
 * mid-shift needs to know where the order is before anything else.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message, Modal, Empty } from 'ant-design-vue';
import {
  ArrowLeftOutlined, EditOutlined, PlayCircleOutlined, CheckOutlined,
  StopOutlined, PlusOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import QualityCheckModal from './QualityCheckModal.vue';
import { useFormat } from '../../composables/useFormat';
import {
  ORDER_STATUSES, PRIORITIES, WORK_ORDER_STATUSES, QC_STATUSES, QC_TYPES,
  labelOf, optionOf, canRelease, canComplete, canCancel, canEdit,
  varianceColor, signedPct,
} from './mrpOptions';
import http from '../../lib/http';

const route = useRoute();
const { money, number } = useFormat();
const simpleImage = Empty.PRESENTED_IMAGE_SIMPLE;
const badgeStyle = { backgroundColor: 'rgba(128,128,128,0.25)', color: 'inherit', boxShadow: 'none' };

const id = route.params.id;
const tab = ref('materials');
const loading = ref(false);
const busy = ref(false);

const order = ref({});
const materials = ref([]);
const workOrders = ref([]);
const checks = ref([]);
const shortages = ref([]);
const employees = ref([]);

const employeeOptions = computed(() => employees.value.map(e => ({ value: e.id, label: e.name })));
const canWork = computed(() => ['released', 'in_progress'].includes(order.value.status));

const stepItems = [
  { title: 'Draft' }, { title: 'Planned' }, { title: 'Released' },
  { title: 'In progress' }, { title: 'Completed' },
];
const currentStep = computed(() => {
  const step = optionOf(ORDER_STATUSES, order.value.status).step;
  return step >= 0 ? step : 0;
});
const stepStatus = computed(() => (order.value.status === 'completed' ? 'finish' : 'process'));

/** Rough unit cost preview, so the number is not a surprise after the fact. */
const estimatedUnitCost = computed(() => {
  const qty = Number(completeForm.qty_produced) || 0;
  if (qty <= 0) return 0;
  const total = Number(order.value.total_cost) || 0;
  return total > 0 ? total / qty : 0;
});

function hours(minutes) {
  const m = Number(minutes) || 0;
  if (m === 0) return '0h';
  return m >= 60 ? `${(m / 60).toFixed(1)}h` : `${Math.round(m)}m`;
}

const materialColumns = [
  { title: 'Component', key: 'product', dataIndex: 'product_name' },
  { title: 'Required', dataIndex: 'qty_required', key: 'qty_required', width: 100, align: 'right' },
  { title: 'Issued', dataIndex: 'qty_issued', key: 'qty_issued', width: 100, align: 'right' },
  { title: 'Consumed', dataIndex: 'qty_consumed', key: 'qty_consumed', width: 100, align: 'right' },
  { title: 'On hand', key: 'on_hand', dataIndex: 'on_hand', width: 100, align: 'right' },
  { title: 'Short', key: 'shortfall', dataIndex: 'shortfall', width: 90, align: 'right' },
  { title: 'Unit cost', key: 'unit_cost', dataIndex: 'unit_cost', width: 110, align: 'right' },
  { title: 'Line cost', key: 'total_cost', dataIndex: 'total_cost', width: 120, align: 'right' },
  { title: '', key: 'is_optional', width: 90 },
];

const workOrderColumns = [
  { title: '#', dataIndex: 'sequence', key: 'sequence', width: 55 },
  { title: 'Operation', dataIndex: 'name', key: 'name' },
  { title: 'Work centre', dataIndex: 'work_center_name', key: 'work_center_name', width: 160 },
  { title: 'Operator', dataIndex: 'employee_name', key: 'employee_name', width: 150 },
  { title: 'Status', key: 'status', dataIndex: 'status', width: 120 },
  { title: 'Time', key: 'time', width: 190 },
  { title: 'Labour', key: 'labour_cost', dataIndex: 'labour_cost', width: 110, align: 'right' },
  { title: '', key: 'actions', width: 90, align: 'center' },
];

const qcLineColumns = [
  { title: 'Parameter', dataIndex: 'parameter', key: 'parameter' },
  { title: 'Expected', dataIndex: 'expected', key: 'expected', width: 140 },
  { title: 'Actual', dataIndex: 'actual', key: 'actual', width: 140 },
  { title: 'Result', key: 'result', dataIndex: 'result', width: 100 },
];

// ---------------- actions ----------------

async function release(allowShortage = false) {
  busy.value = true;
  try {
    await http.post(`mrp/production-orders/${id}/release`, { allow_shortage: allowShortage });
    message.success('Materials issued — order released');
    load();
  } catch (e) {
    const list = e?.data?.shortages || [];
    if (list.length) {
      Modal.confirm({
        title: 'Not enough material',
        content: `${list.length} component(s) are short. Releasing anyway drives their stock negative, `
          + 'which affects every valuation report. Continue only if the stock is physically there.',
        okText: 'Release anyway',
        okType: 'danger',
        cancelText: 'Cancel',
        onOk: () => release(true),
      });
    } else {
      message.error(e?.data?.message || 'Could not release that order');
    }
  } finally {
    busy.value = false;
  }
}

const completeOpen = ref(false);
const completeForm = reactive({ qty_produced: 0, qty_scrapped: 0 });

function openComplete() {
  completeForm.qty_produced = Number(order.value.qty_planned) || 0;
  completeForm.qty_scrapped = 0;
  completeOpen.value = true;
}

async function submitComplete(allowOverproduction = false) {
  busy.value = true;
  try {
    const res = await http.post(`mrp/production-orders/${id}/complete`, {
      qty_produced: completeForm.qty_produced,
      qty_scrapped: completeForm.qty_scrapped,
      allow_overproduction: allowOverproduction,
    });
    message.success(`Completed — unit cost ${money(res?.unit_cost || 0)}`);
    completeOpen.value = false;
    load();
  } catch (e) {
    if (e?.data?.needs_confirmation) {
      Modal.confirm({
        title: 'That is well above the planned quantity',
        content: e.data.message,
        okText: 'Yes, that is right',
        cancelText: 'Let me check',
        onOk: () => submitComplete(true),
      });
    } else {
      message.error(e?.data?.message || 'Could not complete that order');
    }
  } finally {
    busy.value = false;
  }
}

function confirmCancel() {
  Modal.confirm({
    title: `Cancel ${order.value.reference}?`,
    content: order.value.materials_issued
      ? 'Everything already issued goes back to the warehouse it came from, and the order closes at zero cost.'
      : 'The order closes. Nothing has been issued, so no stock moves.',
    okText: 'Cancel order',
    okType: 'danger',
    cancelText: 'Keep it',
    async onOk() {
      try {
        await http.post(`mrp/production-orders/${id}/cancel`, { reason: 'Cancelled from the order page' });
        message.success('Order cancelled');
        load();
      } catch (e) {
        message.error(e?.data?.message || 'Could not cancel that order');
      }
    },
  });
}

async function startStep(record) {
  try {
    await http.post(`mrp/work-orders/${record.id}/start`);
    message.success('Step started');
    load();
  } catch (e) {
    message.error(e?.data?.message || 'Could not start that step');
  }
}

const finishOpen = ref(false);
const finishForm = reactive({ actual_minutes: null, employee_id: undefined, qty_completed: null, qty_rejected: null, notes: '' });
const finishing = ref(null);

function openFinish(record) {
  finishing.value = record;
  finishForm.actual_minutes = null;
  finishForm.employee_id = record.employee_id || undefined;
  finishForm.qty_completed = Number(order.value.qty_planned) || null;
  finishForm.qty_rejected = null;
  finishForm.notes = '';
  finishOpen.value = true;
}

async function submitFinish() {
  busy.value = true;
  try {
    await http.post(`mrp/work-orders/${finishing.value.id}/finish`, { ...finishForm });
    message.success('Step finished');
    finishOpen.value = false;
    load();
  } catch (e) {
    message.error(e?.data?.message || 'Could not finish that step');
  } finally {
    busy.value = false;
  }
}

const qcOpen = ref(false);
function openQc() {
  qcOpen.value = true;
}
function onQcSaved() {
  qcOpen.value = false;
  load();
}

async function load() {
  loading.value = true;
  try {
    const res = await http.get(`mrp/production-orders/${id}`);
    order.value = res?.order || {};
    materials.value = res?.materials || [];
    workOrders.value = res?.work_orders || [];
    checks.value = res?.quality_checks || [];
    shortages.value = (res?.shortages || []).filter(s => !s.is_optional);
  } catch (e) {
    message.error('Could not load that order');
  } finally {
    loading.value = false;
  }
}

onMounted(async () => {
  load();
  try {
    const meta = await http.get('mrp/meta');
    employees.value = meta?.employees || [];
  } catch (e) { /* the operator select stays empty */ }
});
</script>

<style scoped>
.muted {
  color: rgba(128, 128, 128, 0.7);
}
.bad {
  color: #dc2626;
  font-weight: 600;
}
.sub {
  font-size: 11.5px;
  opacity: 0.55;
}
.mini {
  font-size: 10.5px;
  line-height: 16px;
  margin-inline-start: 4px;
}
.kpis {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 12px;
  margin-bottom: 16px;
}
.kpi {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 13px 16px;
  border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 14px;
}
.kpi--accent {
  border-color: rgba(109, 40, 217, 0.45);
  background: rgba(109, 40, 217, 0.05);
}
.kpi-label {
  font-size: 12.5px;
  opacity: 0.65;
}
.kpi-value {
  font-size: 19px;
  font-weight: 600;
  line-height: 1.35;
}
.kpi-value--sm {
  font-size: 15px;
}
.kpi-of {
  font-size: 14px;
  opacity: 0.5;
}
.kpi-sub {
  font-size: 11.5px;
  opacity: 0.55;
}
.tab-actions {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 12px;
  flex-wrap: wrap;
}
.tab-note {
  font-size: 12px;
  opacity: 0.65;
}
.qc-head {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.qc-notes {
  margin: 8px 0 0;
  font-size: 12.5px;
  opacity: 0.75;
}
.cost-note {
  margin-top: 10px;
  font-size: 12px;
  opacity: 0.65;
}
.estimate {
  margin: 12px 0 0;
  font-size: 12.5px;
  opacity: 0.75;
}
</style>
