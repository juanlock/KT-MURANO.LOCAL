<template>
  <div class="page">
    <PageHeader
      :title="isEdit ? $t('Edit') : $t('CreateAdjustment')"
      :breadcrumb="[$t('ListAdjustments'), isEdit ? $t('Edit') : $t('CreateAdjustment')]"
    />

    <div v-if="loadingRecord" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-form v-else layout="vertical">
      <a-card size="small" style="margin-bottom: 16px">
        <a-row :gutter="16">
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('date')" required>
              <a-date-picker v-model:value="adjustment.date" value-format="YYYY-MM-DD" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('warehouse')" required>
              <a-select
                v-model:value="adjustment.warehouse_id"
                show-search option-filter-prop="label"
                :placeholder="$t('Choose_Warehouse')"
                :options="warehouseOptions"
                :disabled="isEdit"
                @change="onWarehouseChange"
              />
            </a-form-item>
          </a-col>
        </a-row>
      </a-card>

      <a-card size="small" style="margin-bottom: 16px">
        <div style="display: flex; gap: 8px; margin-bottom: 16px">
          <a-select
            v-model:value="searchValue"
            show-search
            :filter-option="filterProduct"
            :placeholder="$t('Scan_Search_Product_by_Code_Name')"
            style="flex: 1"
            :options="productOptions"
            :disabled="!adjustment.warehouse_id"
            @select="onProductPicked"
          />
          <ProductScanModal :disabled="!adjustment.warehouse_id" @scan="onScan" />
        </div>

        <a-table
          :columns="lineColumns"
          :data-source="lines"
          :pagination="false"
          size="middle"
          :row-key="(r, i) => r.detail_id"
          :scroll="{ x: 'max-content' }"
        >
          <template #bodyCell="{ column, record, index }">
            <template v-if="column.key === 'product'">
              <div style="font-weight: 500">{{ record.name }}</div>
              <div class="muted">{{ record.code }}</div>
              <a-tag v-if="record.is_batch_tracked" color="warning" style="margin-top: 2px">Batch</a-tag>
            </template>
            <template v-else-if="column.key === 'current'">{{ record.current }} {{ record.unit }}</template>
            <template v-else-if="column.key === 'type'">
              <a-select
                v-model:value="record.type"
                style="width: 140px"
                :options="[
                  { value: 'add', label: $t('Addition') },
                  { value: 'sub', label: $t('Subtraction') },
                ]"
              />
            </template>
            <template v-else-if="column.key === 'quantity'">
              <a-input-number
                :value="record.quantity"
                :min="0"
                style="width: 110px"
                @update:value="v => setQty(record, v)"
              />
            </template>
            <template v-else-if="column.key === 'actions'">
              <a-button type="text" size="small" danger @click="lines.splice(index, 1)">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </template>
          </template>
          <template #emptyText>
            <a-empty :description="$t('NodataAvailable')" style="padding: 24px 0" />
          </template>
        </a-table>
      </a-card>

      <a-card size="small" style="margin-bottom: 16px">
        <a-form-item :label="$t('Note')" style="margin-bottom: 0">
          <a-textarea v-model:value="adjustment.notes" :rows="2" :placeholder="$t('Afewwords')" />
        </a-form-item>
      </a-card>

      <a-space>
        <a-button type="primary" size="large" :loading="submitting" @click="submit">
          {{ $t('submit') }}
        </a-button>
        <a-button size="large" @click="$router.push('/adjustments')">{{ $t('Cancel') }}</a-button>
      </a-space>
    </a-form>
  </div>
</template>

<script setup>
/**
 * Adjustment create/edit. Contracts:
 * - bootstrap GET adjustments/create → {warehouses}; edit
 *   GET adjustments/{id}/edit → {adjustment, details, warehouses}
 * - products GET get_Products_by_warehouse/{wid}?stock=0&product_service=0
 *   &product_combo=1; line stock (`current`) comes from the list row's qte
 * - lines are qty + type (add|sub) only, no pricing
 * - validation = legacy: NaN qty resets to `current`; type 'sub' caps qty at
 *   `current` (LowStock + reset); submit = AddProductToList + AddQuantity +
 *   Select_Batch_Required_For on batch-tracked lines (no picker in v1)
 * - POST adjustments / PUT adjustments/{id} {warehouse_id, date, notes,
 *   details}; non-batch lines OMIT the batches key entirely (legacy deletes it)
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { DeleteOutlined } from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import PageHeader from '../../components/PageHeader.vue';
import ProductScanModal from '../../components/ProductScanModal.vue';
import { resolveScan } from '../../lib/scanMatch';
import http from '../../lib/http';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();

const id = computed(() => route.params.id);
const isEdit = computed(() => !!id.value);

const loadingRecord = ref(true);
const submitting = ref(false);

const warehouses = ref([]);
const products = ref([]);
const lines = ref([]);
// `null`, never `undefined`: antd's Select falls back to its own internal
// selection whenever the bound value is undefined, so clearing it that way
// leaves the last picked product on screen instead of the placeholder.
const searchValue = ref(null);
let nextDetailId = 1;

const adjustment = ref({
  date: dayjs().format('YYYY-MM-DD'),
  warehouse_id: undefined,
  notes: '',
});

const warehouseOptions = computed(() => warehouses.value.map(w => ({ value: w.id, label: w.name })));
const productOptions = computed(() =>
  products.value.map((p, i) => ({ value: i, label: `${p.code} — ${p.name}` }))
);

// A variant's label carries the VARIANT code, so also match the parent
// product's code — otherwise typing the main code finds none of its variants.
function filterProduct(input, option) {
  const p = products.value[option.value];
  return `${option.label} ${p?.product_code ?? ''}`
    .toLowerCase()
    .includes(String(input).toLowerCase());
}

const lineColumns = computed(() => [
  { title: t('ProductName'), key: 'product' },
  { title: t('Stock'), key: 'current', align: 'right' },
  { title: t('Type'), key: 'type', align: 'center' },
  { title: t('Quantity'), key: 'quantity', align: 'center' },
  { title: t('Action'), key: 'actions', width: 70, align: 'center' },
]);

async function loadProducts() {
  if (!adjustment.value.warehouse_id) return;
  try {
    products.value = await http.get(
      `get_Products_by_warehouse/${adjustment.value.warehouse_id}`,
      { stock: 0, product_service: 0, product_combo: 1 }
    ) || [];
  } catch (e) {
    products.value = [];
    message.error(t('InvalidData'));
  }
}

function onWarehouseChange() {
  lines.value = [];
  loadProducts();
}

// Camera / handheld scan: match the code exactly (code or barcode) and add the
// single matching product, as legacy's adjustment search() did.
function onScan(code) {
  const hit = resolveScan(products.value, code);
  if (!hit) {
    message.warning(t('Product_Not_Found'));
    return;
  }
  onProductPicked(hit.idx);
}

async function onProductPicked(idx) {
  searchValue.value = null;
  const p = products.value[idx];
  if (!p) return;
  const variantId = p.product_variant_id ?? 0;

  const existing = lines.value.find(
    l => l.product_id === p.id && (l.product_variant_id ?? 0) === (variantId || 0)
  );
  if (existing) {
    setQty(existing, Number(existing.quantity) + 1);
    return;
  }

  try {
    const d = await http.get(`show_product_data/${p.id}/${variantId}`);
    const current = Number(p.qte) || 0;
    lines.value.push({
      detail_id: nextDetailId++,
      product_id: d.id,
      product_variant_id: p.product_variant_id ?? null,
      code: d.code || p.code,
      name: d.name,
      current,
      // Legacy: default qty is 1 unless less than one unit remains.
      quantity: current < 1 ? current : 1,
      type: 'add',
      unit: d.unit,
      is_batch_tracked: !!d.is_batch_tracked,
      batches: [],
    });
  } catch (e) {
    message.error(t('InvalidData'));
  }
}

// Global Allow Overselling (Settings > Features): when ON, stock clamps on
// every transaction form are bypassed.
const oversellingAllowed = ref(false);
http.get('get_pos_Settings')
  .then(d => { oversellingAllowed.value = !!d?.pos_settings?.allow_overselling; })
  .catch(() => {});

// Legacy Verified_Qty: NaN → current; 'sub' caps at current stock (reset, not clamp-to-input).
function setQty(line, value) {
  let qty = Number(value);
  if (Number.isNaN(qty)) qty = Number(line.current) || 0;
  if (!oversellingAllowed.value && line.type === 'sub' && qty > Number(line.current)) {
    message.warning(t('LowStock'));
    qty = Number(line.current) || 0;
  }
  line.quantity = qty;
}

function validateForm() {
  if (!adjustment.value.warehouse_id) {
    message.warning(t('Please_fill_the_form_correctly'));
    return false;
  }
  if (!lines.value.length) {
    message.warning(t('AddProductToList'));
    return false;
  }
  if (lines.value.some(l => l.quantity === '' || Number(l.quantity) === 0)) {
    message.warning(t('AddQuantity'));
    return false;
  }
  const missingBatch = lines.value.find(l => l.is_batch_tracked && !(l.batches || []).length);
  if (missingBatch) {
    message.warning(`${t('Select_Batch_Required_For')} ${missingBatch.name}`);
    return false;
  }
  return true;
}

function detailsPayload() {
  return lines.value.map(l => {
    const out = {
      // Existing rows carry their detail id: the controller uses it to tell an
      // update from an insert, and to spot lines removed from the document.
      // Newly added lines have none, and null keeps them out of the old-id set.
      id: l.id ?? null,
      product_id: l.product_id,
      product_variant_id: l.product_variant_id ?? null,
      code: l.code,
      name: l.name,
      current: l.current,
      type: l.type,
      quantity: l.quantity,
      unit: l.unit,
      is_batch_tracked: !!l.is_batch_tracked,
    };
    // Legacy omits the key entirely for non-batch lines.
    if (l.is_batch_tracked && Array.isArray(l.batches) && l.batches.length) {
      out.batches = l.batches;
    }
    return out;
  });
}

async function submit() {
  if (!validateForm()) return;
  submitting.value = true;
  const body = {
    warehouse_id: adjustment.value.warehouse_id,
    date: adjustment.value.date,
    notes: adjustment.value.notes,
    details: detailsPayload(),
  };
  try {
    if (isEdit.value) {
      await http.put(`adjustments/${id.value}`, body);
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('adjustments', body);
      message.success(t('Successfully_Created'));
    }
    router.push('/adjustments');
  } catch (e) {
    const errors = e?.data?.errors;
    if (errors) Object.values(errors).flat().forEach(msg => message.error(String(msg)));
    else message.error(e?.data?.message || t('InvalidData'));
  } finally {
    submitting.value = false;
  }
}

onMounted(async () => {
  try {
    if (isEdit.value) {
      const data = await http.get(`adjustments/${id.value}/edit`);
      warehouses.value = data.warehouses || [];
      const a = data.adjustment || {};
      adjustment.value = {
        date: a.date,
        warehouse_id: a.warehouse_id,
        notes: a.notes || '',
      };
      lines.value = (data.details || []).map(d => ({
        ...d,
        detail_id: nextDetailId++,
        is_batch_tracked: !!d.is_batch_tracked,
        batches: Array.isArray(d.batches) ? d.batches : [],
      }));
      await loadProducts();
    } else {
      const data = await http.get('adjustments/create');
      warehouses.value = data.warehouses || [];
    }
  } catch (e) {
    message.error(t('InvalidData'));
    router.push('/adjustments');
    return;
  } finally {
    loadingRecord.value = false;
  }
});
</script>

<style scoped>
.muted {
  color: rgba(0, 0, 0, 0.45);
  font-size: 12px;
}
</style>
