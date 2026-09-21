<template>
  <div class="page">
    <PageHeader
      :title="isEdit ? $t('Edit') : $t('Create_Damage')"
      :breadcrumb="[$t('Damages'), isEdit ? $t('Edit') : $t('Create_Damage')]"
    />

    <div v-if="loadingRecord" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-form v-else layout="vertical">
      <a-card size="small" style="margin-bottom: 16px">
        <a-row :gutter="16">
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('date')" required>
              <a-date-picker v-model:value="damage.date" value-format="YYYY-MM-DD" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('warehouse')" required>
              <a-select
                v-model:value="damage.warehouse_id"
                show-search option-filter-prop="label"
                :placeholder="$t('Choose_Warehouse')"
                :options="warehouseOptions"
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
            :disabled="!damage.warehouse_id"
            @select="onProductPicked"
          />
          <ProductScanModal :disabled="!damage.warehouse_id" @scan="onScan" />
        </div>

        <a-table
          :columns="lineColumns"
          :data-source="lines"
          :pagination="false"
          size="middle"
          :row-key="r => r.detail_id"
          :scroll="{ x: 'max-content' }"
          :expanded-row-keys="expandedKeys"
          :expand-icon="() => null"
        >
          <template #bodyCell="{ column, record, index }">
            <template v-if="column.key === 'product'">
              <div style="font-weight: 500">{{ record.name }}</div>
              <div class="muted">{{ record.code }}</div>
              <a-tag v-if="record.is_batch_tracked" color="warning" style="margin-top: 2px">Batch</a-tag>
            </template>
            <template v-else-if="column.key === 'current'">{{ record.current }} {{ record.unit }}</template>
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
          <!-- Batch allocation inline for batch-tracked lines (damage always
               subtracts). Keyed by warehouse so it remounts and refetches
               availability when the warehouse changes (legacy behavior). -->
          <template #expandedRowRender="{ record }">
            <BatchAllocator
              :key="record.detail_id + '-' + (damage.warehouse_id || 0)"
              :line="record" :warehouse-id="damage.warehouse_id" endpoint="batches_for_damage"
            />
          </template>
          <template #emptyText>
            <a-empty :description="$t('NodataAvailable')" style="padding: 24px 0" />
          </template>
        </a-table>
      </a-card>

      <a-card size="small" style="margin-bottom: 16px">
        <a-form-item :label="$t('Note')" style="margin-bottom: 0">
          <a-textarea v-model:value="damage.notes" :rows="2" :placeholder="$t('Afewwords')" />
        </a-form-item>
      </a-card>

      <a-space>
        <a-button type="primary" size="large" :loading="submitting" @click="submit">
          {{ $t('submit') }}
        </a-button>
        <a-button size="large" @click="$router.push('/damages')">{{ $t('Cancel') }}</a-button>
      </a-space>
    </a-form>
  </div>
</template>

<script setup>
/**
 * Damage create/edit — the adjustment form with type ALWAYS "sub". Contracts
 * from legacy Create_Damage/Edit_Damage.vue:
 * - bootstrap GET damages/create → {warehouses}; edit GET damages/{id}/edit
 * - products GET get_Products_by_warehouse/{wid}?stock=0&product_service=0
 *   &product_combo=1; batches via batches_for_damage/{pid}/{wid}/{variant||0}
 * - qty rule = legacy Verified_Qty for sub: NaN → current; qty > current →
 *   LowStock toast + reset to current
 * - submit gate = legacy verifiedForm: AddProductToList, AddQuantity, then
 *   batch select errors (lib/batchValidation — same rules/messages)
 * - POST damages / PUT damages/{id} {warehouse_id, date, notes, details};
 *   batch lines submit batches as [{product_batch_id, qty}] (filtered:
 *   product_batch_id set and qty > 0); non-batch lines omit the key.
 *   Server 422 with errors.details[0] surfaces verbatim (legacy behavior).
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
import BatchAllocator from '../../components/BatchAllocator.vue';
import { hasBatchSelectErrors, firstBatchSelectError } from '../../lib/batchValidation';
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

const damage = ref({
  date: dayjs().format('YYYY-MM-DD'),
  warehouse_id: undefined,
  notes: '',
});

const warehouseOptions = computed(() => warehouses.value.map(w => ({ value: w.id, label: w.name })));
const productOptions = computed(() =>
  products.value.map((p, i) => ({ value: i, label: `${p.code} — ${p.name}` }))
);
const expandedKeys = computed(() => lines.value.filter(l => l.is_batch_tracked).map(l => l.detail_id));

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
  { title: t('Quantity'), key: 'quantity', align: 'center' },
  { title: t('Action'), key: 'actions', width: 70, align: 'center' },
]);

async function loadProducts() {
  if (!damage.value.warehouse_id) return;
  try {
    products.value = await http.get(
      `get_Products_by_warehouse/${damage.value.warehouse_id}`,
      { stock: 0, product_service: 0, product_combo: 1 }
    ) || [];
  } catch (e) {
    products.value = [];
    message.error(t('InvalidData'));
  }
}

// Legacy Selected_Warehouse: keep lines but clear batch allocations so they
// re-resolve against the new warehouse (the allocator remounts via its key).
function onWarehouseChange() {
  for (const l of lines.value) {
    if (l.is_batch_tracked) l.batches = [];
  }
  loadProducts();
}

// Camera / handheld scan: match the code exactly (code or barcode) and add the
// single matching product, as legacy's damage search() did.
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
      quantity: current < 1 ? current : 1,
      type: 'sub', // damage always subtracts
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

// Legacy Verified_Qty (type is always sub): NaN → current; qty > current →
// LowStock warning + reset to current.
function setQty(line, value) {
  let qty = Number(value);
  if (Number.isNaN(qty)) qty = Number(line.current) || 0;
  if (!oversellingAllowed.value && qty > Number(line.current)) {
    message.warning(t('LowStock'));
    qty = Number(line.current) || 0;
  }
  line.quantity = qty;
}

function validateForm() {
  if (!damage.value.warehouse_id) {
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
  if (hasBatchSelectErrors(lines.value)) {
    message.error(firstBatchSelectError(lines.value, t));
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
      type: 'sub',
      quantity: l.quantity,
      unit: l.unit,
      is_batch_tracked: !!l.is_batch_tracked,
    };
    if (l.is_batch_tracked && Array.isArray(l.batches)) {
      out.batches = l.batches
        .filter(b => b && b.product_batch_id && Number(b.qty) > 0)
        .map(b => ({ product_batch_id: Number(b.product_batch_id), qty: Number(b.qty) || 0 }));
    }
    return out;
  });
}

async function submit() {
  if (!validateForm()) return;
  submitting.value = true;
  const body = {
    warehouse_id: damage.value.warehouse_id,
    date: damage.value.date,
    notes: damage.value.notes,
    details: detailsPayload(),
  };
  try {
    if (isEdit.value) {
      await http.put(`damages/${id.value}`, body);
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('damages', body);
      message.success(t('Successfully_Created'));
    }
    router.push('/damages');
  } catch (e) {
    // Legacy surfaces errors.details[0] verbatim when present.
    const detailsErr = e?.data?.errors?.details;
    if (Array.isArray(detailsErr) && detailsErr.length) message.error(String(detailsErr[0]));
    else message.error(e?.data?.message || t('InvalidData'));
  } finally {
    submitting.value = false;
  }
}

onMounted(async () => {
  try {
    if (isEdit.value) {
      const data = await http.get(`damages/${id.value}/edit`);
      warehouses.value = data.warehouses || [];
      const a = data.damage || {};
      damage.value = {
        date: a.date,
        warehouse_id: a.warehouse_id,
        notes: a.notes || '',
      };
      lines.value = (data.details || []).map(d => ({
        ...d,
        detail_id: nextDetailId++,
        type: 'sub',
        is_batch_tracked: !!d.is_batch_tracked,
        batches: Array.isArray(d.batches) ? d.batches : [],
      }));
      await loadProducts();
    } else {
      const data = await http.get('damages/create');
      warehouses.value = data.warehouses || [];
    }
  } catch (e) {
    message.error(t('InvalidData'));
    router.push('/damages');
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
