<template>
  <div class="batch-allocator">
    <a-spin :spinning="loading">
      <div v-for="(row, i) in line.batches" :key="i" class="batch-row">
        <a-select
          :value="row.product_batch_id"
          style="flex: 1; min-width: 220px"
          :placeholder="$t('Batch_No')"
          :options="availableOptions"
          @change="v => onSelect(row, v)"
        />
        <span class="muted" style="min-width: 70px; text-align: right">
          {{ Number(row.qty_available) || 0 }}
        </span>
        <a-input-number
          v-model:value="row.qty"
          :min="0"
          style="width: 110px"
          :status="Number(row.qty) > (Number(row.qty_available) || 0) ? 'error' : undefined"
        />
        <a-button type="text" size="small" danger @click="line.batches.splice(i, 1)">
          <template #icon><DeleteOutlined /></template>
        </a-button>
      </div>

      <div style="display: flex; align-items: center; gap: 12px; margin-top: 4px">
        <a-button size="small" @click="addRow">{{ $t('Add_Batch') }}</a-button>
        <span class="muted">
          {{ $t('Total') }}: <strong>{{ totalQty }}</strong> / {{ Number(line.quantity) || 0 }}
        </span>
      </div>
    </a-spin>
  </div>
</template>

<script setup>
/**
 * Allocate quantities from EXISTING batches (sales/quotations). Fetches
 * availability from `endpoint`/{product_id}/{warehouse_id}/{variant||0} →
 * {batches: [{id, batch_no, expiry_date, qty_available}]}, mutates
 * line.batches rows {product_batch_id, batch_no, expiry_date, qty_available,
 * qty} — first row seeded with the line quantity, like legacy. Validation
 * lives in lib/batchValidation.js, owned by the form.
 */
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { DeleteOutlined } from '@ant-design/icons-vue';
import { batchTotalQty } from '../lib/batchValidation';
import http from '../lib/http';

const props = defineProps({
  line: { type: Object, required: true },
  warehouseId: { type: [Number, String], required: true },
  // batches_for_sale | batches_for_quotation — same response shape.
  endpoint: { type: String, default: 'batches_for_sale' },
});

const { t } = useI18n();

const loading = ref(false);
const available = ref([]);

const availableOptions = computed(() =>
  available.value.map(b => ({
    value: b.id,
    label: `${b.batch_no}${b.expiry_date ? ` · ${t('Exp')} ${b.expiry_date}` : ''} · ${b.qty_available}`,
  }))
);

const totalQty = computed(() => batchTotalQty(props.line));

function addRow() {
  if (!Array.isArray(props.line.batches)) props.line.batches = [];
  props.line.batches.push({
    product_batch_id: null,
    batch_no: '',
    expiry_date: null,
    qty_available: 0,
    qty: props.line.batches.length === 0 ? (Number(props.line.quantity) || 0) : 0,
  });
}

function onSelect(row, batchId) {
  const picked = available.value.find(b => b.id === batchId);
  row.product_batch_id = batchId || null;
  if (picked) {
    row.batch_no = picked.batch_no;
    row.expiry_date = picked.expiry_date;
    row.qty_available = Number(picked.qty_available) || 0;
  } else {
    row.batch_no = '';
    row.expiry_date = null;
    row.qty_available = 0;
  }
}

onMounted(async () => {
  loading.value = true;
  const variantSeg = props.line.product_variant_id ?? 0;
  try {
    const data = await http.get(
      `${props.endpoint}/${props.line.product_id}/${props.warehouseId}/${variantSeg || 0}`
    );
    available.value = Array.isArray(data?.batches) ? data.batches : [];
    // Seed a first row when availability exists and none allocated yet.
    if ((props.line.batches || []).length === 0 && available.value.length > 0) {
      addRow();
    }
  } catch (e) {
    available.value = [];
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.batch-row {
  display: flex;
  gap: 8px;
  align-items: center;
  margin-bottom: 8px;
}
.muted {
  color: rgba(0, 0, 0, 0.45);
  font-size: 12px;
}
</style>
