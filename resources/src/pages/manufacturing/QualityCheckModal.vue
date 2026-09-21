<template>
  <a-modal
    :open="open" title="Record an inspection" :width="720"
    :confirm-loading="saving" :ok-text="$t('submit')" :cancel-text="$t('Cancel')"
    @ok="submit" @cancel="close"
  >
    <a-alert
      type="info" show-icon banner style="margin-bottom: 14px"
      message="The outcome is worked out from the numbers, not chosen — any reject means this is not a clean pass."
    />

    <a-form layout="vertical">
      <a-row :gutter="16">
        <a-col v-if="!productionOrderId" :span="24">
          <a-form-item label="Production order *">
            <a-select
              v-model:value="form.production_order_id" show-search option-filter-prop="label"
              :options="orderOptions" placeholder="Select an order"
            />
          </a-form-item>
        </a-col>
        <a-col :xs="24" :md="8">
          <a-form-item label="Stage *">
            <a-select v-model:value="form.type" :options="QC_TYPES" />
          </a-form-item>
        </a-col>
        <a-col :xs="12" :md="8">
          <a-form-item label="Units inspected *">
            <a-input-number v-model:value="form.qty_inspected" :min="0" style="width: 100%" />
          </a-form-item>
        </a-col>
        <a-col :xs="12" :md="8">
          <a-form-item label="Units rejected">
            <a-input-number v-model:value="form.qty_rejected" :min="0" :max="form.qty_inspected || 0" style="width: 100%" />
          </a-form-item>
        </a-col>
      </a-row>

      <div class="outcome">
        <span class="outcome-label">Outcome</span>
        <a-tag :color="optionOf(QC_STATUSES, derivedStatus).color">{{ labelOf(QC_STATUSES, derivedStatus) }}</a-tag>
        <span v-if="passRate !== null" class="outcome-rate">{{ passRate }}% pass</span>
        <span v-if="derivedStatus === 'failed' && form.type === 'final'" class="outcome-warn">
          A failed final check stops the batch being received into stock.
        </span>
      </div>

      <a-divider orientation="left" style="margin: 14px 0 12px">Measurements</a-divider>

      <a-table
        size="small" :columns="lineColumns" :data-source="form.lines"
        row-key="key" :pagination="false"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'parameter'">
            <a-input v-model:value="record.parameter" placeholder="e.g. Height" size="small" />
          </template>
          <template v-else-if="column.key === 'expected'">
            <a-input v-model:value="record.expected" placeholder="e.g. 75cm" size="small" />
          </template>
          <template v-else-if="column.key === 'actual'">
            <a-input v-model:value="record.actual" placeholder="measured" size="small" />
          </template>
          <template v-else-if="column.key === 'result'">
            <a-select v-model:value="record.result" size="small" style="width: 100%" :options="resultOptions" />
          </template>
          <template v-else-if="column.key === 'actions'">
            <a-button type="text" size="small" danger @click="removeLine(record)">
              <template #icon><DeleteOutlined /></template>
            </a-button>
          </template>
        </template>
      </a-table>

      <a-button size="small" type="dashed" block style="margin-top: 8px" @click="addLine">
        <template #icon><PlusOutlined /></template>
        Add a measurement
      </a-button>

      <a-form-item :label="$t('Note')" style="margin: 14px 0 0">
        <a-textarea v-model:value="form.notes" :rows="2" allow-clear />
      </a-form-item>
    </a-form>
  </a-modal>
</template>

<script setup>
/**
 * Recording an inspection.
 *
 * The outcome is shown live as the numbers are typed, and it is derived exactly
 * the way the server derives it. A dropdown that let someone mark a batch
 * "passed" while entering rejects would put a lie in the quality record.
 */
import { ref, reactive, computed, watch } from 'vue';
import { message } from 'ant-design-vue';
import { PlusOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import { QC_TYPES, QC_STATUSES, labelOf, optionOf } from './mrpOptions';
import http from '../../lib/http';

const props = defineProps({
  open: { type: Boolean, default: false },
  productionOrderId: { type: Number, default: null },
  defaultQty: { type: [Number, String], default: 0 },
});
const emit = defineEmits(['update:open', 'saved']);

const saving = ref(false);
const orders = ref([]);
const orderOptions = computed(() => orders.value.map(o => ({
  value: o.id,
  label: `${o.reference} — ${o.product_name || ''}`,
})));

const resultOptions = [
  { value: 'pass', label: 'Pass' },
  { value: 'fail', label: 'Fail' },
];

let lineKey = 0;
const form = reactive({
  production_order_id: null,
  type: 'final',
  qty_inspected: 0,
  qty_rejected: 0,
  notes: '',
  lines: [],
});

const passRate = computed(() => {
  const inspected = Number(form.qty_inspected) || 0;
  if (inspected <= 0) return null;
  const rejected = Number(form.qty_rejected) || 0;
  return Math.round(((inspected - rejected) / inspected) * 1000) / 10;
});

/** Mirrors MrpQualityCheck::deriveStatus() plus the failed-parameter rule. */
const derivedStatus = computed(() => {
  const inspected = Number(form.qty_inspected) || 0;
  if (inspected <= 0) return 'pending';
  const rejected = Number(form.qty_rejected) || 0;
  if (rejected <= 0) {
    return form.lines.some(l => l.result === 'fail' && l.parameter) ? 'partial' : 'passed';
  }
  return inspected - rejected > 0 ? 'partial' : 'failed';
});

const lineColumns = [
  { title: 'Parameter', key: 'parameter', dataIndex: 'parameter' },
  { title: 'Expected', key: 'expected', dataIndex: 'expected', width: 150 },
  { title: 'Actual', key: 'actual', dataIndex: 'actual', width: 150 },
  { title: 'Result', key: 'result', dataIndex: 'result', width: 110 },
  { title: '', key: 'actions', width: 50 },
];

function addLine() {
  lineKey += 1;
  form.lines.push({ key: lineKey, parameter: '', expected: '', actual: '', result: 'pass', notes: '' });
}

function removeLine(record) {
  const i = form.lines.findIndex(l => l.key === record.key);
  if (i >= 0) form.lines.splice(i, 1);
}

watch(() => props.open, (isOpen) => {
  if (!isOpen) return;

  form.production_order_id = props.productionOrderId || null;
  form.type = 'final';
  form.qty_inspected = Number(props.defaultQty) || 0;
  form.qty_rejected = 0;
  form.notes = '';
  form.lines = [];
  addLine();

  if (!props.productionOrderId) loadOrders();
});

async function loadOrders() {
  try {
    const res = await http.get('mrp/production-orders', { status: 'open', limit: 100 });
    orders.value = res?.orders || [];
  } catch (e) { /* the select stays empty */ }
}

async function submit() {
  if (!form.production_order_id) {
    message.error('Pick the production order this inspection belongs to');
    return;
  }
  if (!form.qty_inspected || Number(form.qty_inspected) <= 0) {
    message.error('Enter how many units were inspected');
    return;
  }
  if (Number(form.qty_rejected) > Number(form.qty_inspected)) {
    message.error('More units were rejected than were inspected');
    return;
  }

  saving.value = true;
  try {
    const res = await http.post('mrp/quality-checks', {
      production_order_id: form.production_order_id,
      type: form.type,
      qty_inspected: form.qty_inspected,
      qty_rejected: form.qty_rejected || 0,
      notes: form.notes,
      lines: form.lines.filter(l => l.parameter),
    });
    message.success(`Inspection recorded — ${labelOf(QC_STATUSES, res?.status || 'pending')}`);
    emit('saved');
  } catch (e) {
    message.error(e?.data?.message || 'Could not record that inspection');
  } finally {
    saving.value = false;
  }
}

function close() {
  emit('update:open', false);
}
</script>

<style scoped>
.outcome {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  padding: 10px 14px;
  border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 10px;
}
.outcome-label {
  font-size: 12.5px;
  opacity: 0.65;
}
.outcome-rate {
  font-size: 12.5px;
  opacity: 0.75;
}
.outcome-warn {
  font-size: 12px;
  color: #dc2626;
}
</style>
