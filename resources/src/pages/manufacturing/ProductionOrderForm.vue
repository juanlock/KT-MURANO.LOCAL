<template>
  <div class="page">
    <PageHeader
      :title="isEdit ? 'Edit production order' : 'New production order'"
      :breadcrumb="['Manufacturing', 'Production Orders', isEdit ? form.reference : 'New']"
    >
      <template #actions>
        <a-button @click="$router.push('/mrp/production-orders')">{{ $t('Cancel') }}</a-button>
        <a-button type="primary" :loading="saving" @click="submit">
          <template #icon><SaveOutlined /></template>
          {{ $t('submit') }}
        </a-button>
      </template>
    </PageHeader>

    <div v-if="loadingRecord" class="loading"><a-spin size="large" /></div>

    <a-row v-else :gutter="16">
      <a-col :xs="24" :xl="16">
        <a-card size="small" :title="$t('Details')">
          <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
            <a-row :gutter="16">
              <a-col :xs="24" :md="12">
                <a-form-item label="Product to make *" name="product_id">
                  <a-select
                    v-model:value="form.product_id" show-search :filter-option="false"
                    :options="productOptions" placeholder="Search a product"
                    :disabled="isEdit" @search="searchProducts" @change="onProductChange"
                  />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item label="Bill of materials" name="bom_id" :extra="bomHint">
                  <a-select
                    v-model:value="form.bom_id" :options="bomOptions" allow-clear
                    :disabled="isEdit" placeholder="Default for the product"
                  />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="6">
                <a-form-item label="Quantity *" name="qty_planned">
                  <a-input-number v-model:value="form.qty_planned" :min="0.0001" style="width: 100%" />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="6">
                <a-form-item :label="$t('Status')" name="priority">
                  <a-select v-model:value="form.priority" :options="PRIORITIES" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item label="Build in *" name="warehouse_id" extra="Materials are drawn from here">
                  <a-select
                    v-model:value="form.warehouse_id" show-search option-filter-prop="label"
                    :options="warehouseOptions" placeholder="Select a warehouse"
                  />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item
                  label="Finished goods to" name="fg_warehouse_id"
                  extra="Leave blank to receive back into the build warehouse"
                >
                  <a-select
                    v-model:value="form.fg_warehouse_id" show-search option-filter-prop="label"
                    :options="warehouseOptions" allow-clear placeholder="Same as build"
                  />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="6">
                <a-form-item label="Start" name="planned_start">
                  <a-date-picker v-model:value="form.planned_start" style="width: 100%" value-format="YYYY-MM-DD" />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="6">
                <a-form-item label="Due" name="planned_end">
                  <a-date-picker v-model:value="form.planned_end" style="width: 100%" value-format="YYYY-MM-DD" />
                </a-form-item>
              </a-col>
              <a-col :span="24">
                <a-form-item :label="$t('Note')" style="margin-bottom: 0">
                  <a-textarea v-model:value="form.notes" :rows="2" allow-clear />
                </a-form-item>
              </a-col>
            </a-row>
          </a-form>
        </a-card>
      </a-col>

      <a-col :xs="24" :xl="8">
        <a-card size="small" title="What this will need" class="sticky">
          <a-empty
            v-if="!preview.length" :image="simpleImage"
            :description="form.bom_id ? 'Set a quantity' : 'Pick a product with a bill of materials'"
          />
          <template v-else>
            <ul class="preview">
              <li v-for="row in preview" :key="row.product_id">
                <span class="pv-name">{{ row.product_name }}</span>
                <span class="pv-qty">{{ number(row.qty, 3) }}</span>
              </li>
            </ul>
            <a-divider style="margin: 12px 0 10px" />
            <div class="pv-total">
              <span>Estimated material</span>
              <strong>{{ money(previewCost) }}</strong>
            </div>
            <p class="pv-note">
              Availability is checked when the order is released, not now — stock
              moves between then and here.
            </p>
          </template>
        </a-card>
      </a-col>
    </a-row>
  </div>
</template>

<script setup>
/**
 * Creating or rescheduling a production order.
 *
 * The sidebar previews what the BOM will draw so a quantity typo is visible
 * before the order exists. Product and BOM lock on edit: changing what an order
 * builds after the fact would leave its material lines describing a different
 * product entirely.
 */
import { ref, reactive, computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message, Empty } from 'ant-design-vue';
import { SaveOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import { PRIORITIES } from './mrpOptions';
import http from '../../lib/http';
import { t } from '../../i18n';

const route = useRoute();
const router = useRouter();
const { money, number } = useFormat();
const simpleImage = Empty.PRESENTED_IMAGE_SIMPLE;

const id = computed(() => route.params.id);
const isEdit = computed(() => !!id.value);

const loadingRecord = ref(true);
const saving = ref(false);
const formRef = ref();

const form = reactive({
  reference: '', product_id: undefined, bom_id: undefined, qty_planned: 1,
  warehouse_id: undefined, fg_warehouse_id: undefined, priority: 'normal',
  planned_start: new Date().toISOString().slice(0, 10), planned_end: null, notes: '',
});

const products = ref([]);
const boms = ref([]);
const warehouses = ref([]);

const productOptions = computed(() => products.value.map(p => ({ value: p.id, label: p.label })));
const warehouseOptions = computed(() => warehouses.value.map(w => ({ value: w.id, label: w.name })));
const bomOptions = computed(() => boms.value
  .filter(b => !form.product_id || b.product_id === form.product_id)
  .map(b => ({ value: b.id, label: b.label })));

const bomHint = computed(() => {
  if (!form.product_id) return 'Pick a product first';
  return bomOptions.value.length
    ? `${bomOptions.value.length} recipe(s) available`
    : 'No active recipe — the order will have no materials';
});

const required = () => [{ required: true, message: t('Field_is_required', 'This field is required') }];
const rules = computed(() => ({
  product_id: required(), qty_planned: required(), warehouse_id: required(),
}));

// ---------------- preview ----------------

const preview = ref([]);
const previewCost = ref(0);

let previewTimer = null;
watch(() => [form.bom_id, form.qty_planned], () => {
  clearTimeout(previewTimer);
  previewTimer = setTimeout(loadPreview, 300);
});

async function loadPreview() {
  if (!form.bom_id || !form.qty_planned) {
    preview.value = [];
    previewCost.value = 0;
    return;
  }
  try {
    const res = await http.get(`mrp/boms/${form.bom_id}/explode`, { qty: form.qty_planned });
    // Only the top level: deeper rows are sub-assemblies this order does not build.
    preview.value = (res?.rows || []).filter(r => r.level === 1);
    previewCost.value = preview.value.reduce((sum, r) => sum + (r.total_cost || 0), 0);
  } catch (e) {
    preview.value = [];
    previewCost.value = 0;
  }
}

function onProductChange() {
  const match = boms.value.find(b => b.product_id === form.product_id);
  form.bom_id = match ? match.id : undefined;
  loadPreview();
}

let searchTimer = null;
function searchProducts(term) {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(async () => {
    try {
      const res = await http.get('mrp/products', { search: term || '' });
      products.value = res?.products || [];
    } catch (e) { /* leave the list as it was */ }
  }, 250);
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    message.error('Fill in the highlighted fields');
    return;
  }

  saving.value = true;
  try {
    if (isEdit.value) {
      await http.put(`mrp/production-orders/${id.value}`, form);
      message.success(t('Successfully_Updated', 'Order updated'));
      router.push(`/mrp/production-orders/${id.value}`);
    } else {
      const res = await http.post('mrp/production-orders', form);
      message.success(t('Successfully_Created', 'Order created'));
      router.push(res?.id ? `/mrp/production-orders/${res.id}` : '/mrp/production-orders');
    }
  } catch (e) {
    message.error(firstError(e) || 'Could not save that order');
  } finally {
    saving.value = false;
  }
}

function firstError(e) {
  const errors = e?.data?.errors;
  if (errors) {
    const first = Object.values(errors)[0];
    if (Array.isArray(first) && first.length) return first[0];
  }
  return e?.data?.message || '';
}

async function bootstrap() {
  loadingRecord.value = true;
  try {
    const meta = await http.get('mrp/meta');
    warehouses.value = meta?.warehouses || [];
    boms.value = meta?.boms || [];

    const res = await http.get('mrp/products', { search: '' });
    products.value = res?.products || [];

    if (isEdit.value) {
      const detail = await http.get(`mrp/production-orders/${id.value}`);
      const o = detail.order;
      Object.assign(form, {
        reference: o.reference, product_id: o.product_id, bom_id: o.bom_id,
        qty_planned: o.qty_planned, warehouse_id: o.warehouse_id,
        fg_warehouse_id: o.fg_warehouse_id, priority: o.priority,
        planned_start: o.planned_start, planned_end: o.planned_end, notes: o.notes,
      });

      if (!products.value.some(p => p.id === o.product_id)) {
        products.value.push({ id: o.product_id, label: `${o.product_code} — ${o.product_name}`, cost: 0 });
      }
      loadPreview();
    } else if (warehouses.value.length === 1) {
      form.warehouse_id = warehouses.value[0].id;
    }
  } catch (e) {
    message.error('Could not load that order');
    router.push('/mrp/production-orders');
  } finally {
    loadingRecord.value = false;
  }
}

onMounted(bootstrap);
</script>

<style scoped>
.loading {
  display: flex;
  justify-content: center;
  padding: 96px 0;
}
.sticky {
  position: sticky;
  top: 16px;
}
.preview {
  list-style: none;
  margin: 0;
  padding: 0;
}
.preview li {
  display: flex;
  justify-content: space-between;
  gap: 10px;
  padding: 5px 0;
  font-size: 13px;
  border-bottom: 1px dashed rgba(128, 128, 128, 0.18);
}
.pv-name {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.pv-qty {
  flex: none;
  font-weight: 600;
}
.pv-total {
  display: flex;
  justify-content: space-between;
  font-size: 14px;
}
.pv-note {
  margin: 10px 0 0;
  font-size: 11.5px;
  opacity: 0.6;
}
</style>
