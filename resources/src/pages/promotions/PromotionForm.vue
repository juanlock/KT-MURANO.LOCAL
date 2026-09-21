<template>
  <div class="page">
    <PageHeader
      :title="isEdit ? 'Edit promotion' : 'New promotion'"
      :breadcrumb="['Promotions', isEdit ? $t('Edit') : $t('Add')]"
    >
      <template #actions>
        <a-button :disabled="saving" @click="$router.push('/promotions')">{{ $t('Cancel') }}</a-button>
        <a-button :disabled="saving" @click="submit(false)">
          <template #icon><InboxOutlined /></template>
          Save as draft
        </a-button>
        <a-button type="primary" :loading="saving" @click="submit(true)">
          <template #icon><CheckOutlined /></template>
          {{ isEdit ? 'Save changes' : 'Save & activate' }}
        </a-button>
      </template>
    </PageHeader>

    <div v-if="isLoading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <div v-else class="form-grid">
      <!-- ================= Main column ================= -->
      <main class="form-main">
        <!-- 1 · Basics -->
        <a-card class="section">
          <div class="section-head">
            <span class="step">1</span>
            <div>
              <h3>Basics</h3>
              <p>Give the promotion a clear name and choose its type.</p>
            </div>
          </div>

          <a-form layout="vertical">
            <a-form-item
              label="Name" required
              :validate-status="fieldErrors.name ? 'error' : ''"
              :help="fieldErrors.name"
            >
              <a-input v-model:value="form.name" placeholder="e.g. Summer Weekend Special" @input="fieldErrors.name = ''" />
            </a-form-item>

            <a-row :gutter="16">
              <a-col :xs="24" :md="12">
                <a-form-item label="Type">
                  <div class="seg">
                    <button type="button" :class="{ active: form.kind === 'discount' }" @click="form.kind = 'discount'">
                      <PercentageOutlined /> Discount
                    </button>
                    <button type="button" :class="{ active: form.kind === 'promotion' }" @click="form.kind = 'promotion'">
                      <GiftOutlined /> Promotion
                    </button>
                  </div>
                  <div class="hint">
                    {{ form.kind === 'discount'
                      ? 'Unconditional reduction that auto-applies.'
                      : 'Conditional offer; usually paired with a code.' }}
                  </div>
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item>
                  <template #label>Code <span class="optional">optional</span></template>
                  <a-input
                    v-model:value="form.code" class="mono" placeholder="SUMMER15"
                    @input="form.code = (form.code || '').toUpperCase().replace(/\s+/g, '')"
                  />
                  <div class="hint">If set, customers must enter this code at checkout.</div>
                </a-form-item>
              </a-col>
            </a-row>

            <a-form-item label="Description" style="margin-bottom: 0">
              <a-textarea v-model:value="form.description" :rows="2" placeholder="A short note for staff or receipts." />
            </a-form-item>
          </a-form>
        </a-card>

        <!-- 2 · Discount value -->
        <a-card class="section">
          <div class="section-head">
            <span class="step">2</span>
            <div>
              <h3>Discount value</h3>
              <p>How much to take off the cart.</p>
            </div>
          </div>

          <a-row :gutter="16">
            <a-col :xs="24" :md="12">
              <a-form-item label="Discount type">
                <div class="seg">
                  <button type="button" :class="{ active: form.discount_type === 'percentage' }" @click="form.discount_type = 'percentage'">
                    % Percentage
                  </button>
                  <button type="button" :class="{ active: form.discount_type === 'fixed' }" @click="form.discount_type = 'fixed'">
                    {{ auth.currency }} Fixed
                  </button>
                </div>
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="12">
              <a-form-item
                label="Value" required
                :validate-status="fieldErrors.discount_value ? 'error' : ''"
                :help="fieldErrors.discount_value"
              >
                <a-input-number
                  v-model:value="form.discount_value" style="width: 100%"
                  :min="0" :step="form.discount_type === 'percentage' ? 1 : 0.01"
                  :placeholder="form.discount_type === 'percentage' ? '15' : '10.00'"
                  @change="fieldErrors.discount_value = ''"
                >
                  <template v-if="form.discount_type === 'fixed'" #prefix>{{ auth.currency }}</template>
                  <template v-else #addonAfter>%</template>
                </a-input-number>
              </a-form-item>
            </a-col>
          </a-row>
        </a-card>

        <!-- 3 · Validity -->
        <a-card class="section">
          <div class="section-head">
            <span class="step">3</span>
            <div>
              <h3>Validity</h3>
              <p>When the promotion is honored.</p>
            </div>
          </div>

          <a-row :gutter="16">
            <a-col :xs="24" :md="12">
              <a-form-item>
                <template #label>Starts at <span class="optional">optional</span></template>
                <a-date-picker
                  v-model:value="form.starts_at" style="width: 100%"
                  show-time value-format="YYYY-MM-DD HH:mm:ss" format="YYYY-MM-DD HH:mm"
                />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="12">
              <a-form-item>
                <template #label>Ends at <span class="optional">optional</span></template>
                <a-date-picker
                  v-model:value="form.ends_at" style="width: 100%"
                  show-time value-format="YYYY-MM-DD HH:mm:ss" format="YYYY-MM-DD HH:mm"
                />
              </a-form-item>
            </a-col>
          </a-row>

          <div class="toggle-row">
            <a-switch v-model:checked="restrictHours" size="small" />
            <span>Restrict to specific hours of the day</span>
          </div>
          <a-row v-if="restrictHours" :gutter="16" style="margin-top: 12px">
            <a-col :xs="24" :md="12">
              <a-form-item label="From time">
                <a-time-picker v-model:value="form.time_of_day_start" style="width: 100%" value-format="HH:mm:ss" />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="12">
              <a-form-item label="To time">
                <a-time-picker v-model:value="form.time_of_day_end" style="width: 100%" value-format="HH:mm:ss" />
                <div class="hint">Times can cross midnight (e.g. 22:00 → 02:00).</div>
              </a-form-item>
            </a-col>
          </a-row>
        </a-card>

        <!-- 4 · Conditions -->
        <a-card class="section">
          <div class="section-head">
            <span class="step">4</span>
            <div>
              <h3>Conditions</h3>
              <p>Minimum requirements before the promotion kicks in.</p>
            </div>
          </div>

          <a-row :gutter="16">
            <a-col :xs="24" :md="12">
              <a-form-item label="Minimum cart total">
                <a-input-number v-model:value="form.min_cart_total" style="width: 100%" :min="0" :step="0.01" placeholder="0.00">
                  <template #prefix>{{ auth.currency }}</template>
                </a-input-number>
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="12">
              <a-form-item label="Minimum item count">
                <a-input-number v-model:value="form.min_item_count" style="width: 100%" :min="0" placeholder="0" />
              </a-form-item>
            </a-col>
          </a-row>

          <a-form-item label="Product scope" style="margin-bottom: 0">
            <div class="choice-grid">
              <button type="button" class="choice-card" :class="{ active: form.product_scope === 'all' }" @click="form.product_scope = 'all'">
                <span class="choice-icon"><AppstoreOutlined /></span>
                <span class="choice-text">
                  <strong>All products</strong>
                  <small>Applies to every item in the cart.</small>
                </span>
                <span class="choice-check"><CheckOutlined /></span>
              </button>
              <button type="button" class="choice-card" :class="{ active: form.product_scope === 'specific' }" @click="form.product_scope = 'specific'">
                <span class="choice-icon"><TagsOutlined /></span>
                <span class="choice-text">
                  <strong>Specific products</strong>
                  <small>Only triggers when one of these is in the cart.</small>
                </span>
                <span class="choice-check"><CheckOutlined /></span>
              </button>
            </div>
          </a-form-item>

          <a-form-item v-if="form.product_scope === 'specific'" style="margin: 16px 0 0">
            <template #label>
              Products
              <a-tag v-if="form.product_ids.length" color="purple" style="margin-left: 6px">{{ form.product_ids.length }}</a-tag>
            </template>
            <a-select
              v-model:value="form.product_ids" mode="multiple" style="width: 100%"
              placeholder="Search products…" option-filter-prop="label"
              :options="productOptions" :max-tag-count="6"
            />
          </a-form-item>
        </a-card>

        <!-- 5 · Warehouses -->
        <a-card class="section">
          <div class="section-head">
            <span class="step">5</span>
            <div>
              <h3>{{ $t('Warehouses') }}</h3>
              <p>Where this promotion is honored.</p>
            </div>
            <a-button v-if="warehouses.length" type="link" size="small" class="head-action" @click="toggleAllWarehouses">
              {{ allWarehousesSelected ? 'Deselect all' : 'Select all' }}
            </a-button>
          </div>

          <div v-if="warehouses.length" class="warehouse-grid">
            <button
              v-for="w in warehouses" :key="w.id" type="button"
              class="choice-card" :class="{ active: form.warehouse_ids.includes(w.id) }"
              @click="toggleWarehouse(w.id)"
            >
              <span class="choice-icon"><ShopOutlined /></span>
              <span class="choice-text">
                <strong>{{ w.name }}</strong>
                <small>{{ [w.city, w.country].filter(Boolean).join(', ') || '—' }}</small>
              </span>
              <span class="choice-check"><CheckOutlined /></span>
            </button>
          </div>
          <a-empty v-else description="No warehouses configured yet." style="padding: 16px 0" />
        </a-card>

        <!-- 6 · Stacking & limits -->
        <a-card class="section">
          <div class="section-head">
            <span class="step">6</span>
            <div>
              <h3>Stacking & limits</h3>
              <p>How this competes with other promotions, and how often it can be used.</p>
            </div>
          </div>

          <a-row :gutter="16">
            <a-col :xs="24" :md="12">
              <a-form-item label="Priority">
                <a-input-number v-model:value="form.priority" style="width: 100%" placeholder="0" />
                <div class="hint">Higher priority wins when promotions overlap.</div>
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="12">
              <a-form-item label="Stackable">
                <div class="toggle-row" style="margin-top: 4px">
                  <a-switch v-model:checked="form.stackable" size="small" />
                  <span>{{ form.stackable
                    ? 'Yes — stacks with other stackable promotions'
                    : 'No — exclusive (only this one wins)' }}</span>
                </div>
              </a-form-item>
            </a-col>
          </a-row>

          <a-row :gutter="16">
            <a-col :xs="24" :md="12">
              <a-form-item label="Total usage limit" style="margin-bottom: 0">
                <a-input-number v-model:value="form.usage_limit_total" style="width: 100%" :min="0" placeholder="Unlimited" />
                <div class="hint">Leave blank for unlimited.</div>
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="12">
              <a-form-item label="Per-customer limit" style="margin-bottom: 0">
                <a-input-number v-model:value="form.usage_limit_per_customer" style="width: 100%" :min="0" placeholder="Unlimited" />
                <div class="hint">Leave blank for unlimited.</div>
              </a-form-item>
            </a-col>
          </a-row>
        </a-card>
      </main>

      <!-- ================= Sticky preview ================= -->
      <aside class="form-side">
        <div class="side-sticky">
          <div class="preview-card">
            <div class="preview-eyebrow">Preview</div>
            <div class="preview-value-row">
              <span class="preview-value">{{ previewValue }}</span>
              <span class="preview-off">OFF</span>
            </div>
            <div class="preview-name">{{ form.name || 'Untitled promotion' }}</div>
            <div v-if="form.description" class="preview-desc">{{ form.description }}</div>
            <div class="preview-tags">
              <span class="ptag" :class="`ptag-${form.kind}`">{{ form.kind }}</span>
              <span v-if="form.code" class="ptag ptag-code">{{ form.code }}</span>
              <span class="ptag" :class="form.is_active ? 'ptag-active' : 'ptag-draft'">
                {{ form.is_active ? 'Active' : 'Draft' }}
              </span>
            </div>
          </div>

          <a-card size="small" class="side-card">
            <div class="side-head">
              <EnvironmentOutlined />
              <h4>Applies at</h4>
              <a-tag color="purple">{{ selectedWarehouses.length }}</a-tag>
            </div>
            <div v-if="selectedWarehouses.length" class="chip-row">
              <a-tag v-for="w in selectedWarehouses" :key="w.id">{{ w.name }}</a-tag>
            </div>
            <div v-else class="hint" style="margin: 0">Pick at least one warehouse.</div>
          </a-card>

          <a-card size="small" class="side-card">
            <div class="side-head">
              <CalendarOutlined />
              <h4>Validity</h4>
            </div>
            <div class="summary-line">
              <span class="summary-label">Window</span>
              <span class="summary-value">{{ validityWindowLabel }}</span>
            </div>
            <div v-if="restrictHours && (form.time_of_day_start || form.time_of_day_end)" class="summary-line">
              <span class="summary-label">Hours</span>
              <span class="summary-value">{{ form.time_of_day_start || '00:00:00' }} → {{ form.time_of_day_end || '23:59:59' }}</span>
            </div>
          </a-card>

          <a-card size="small" class="side-card">
            <div class="side-head">
              <FilterOutlined />
              <h4>Conditions</h4>
            </div>
            <div class="summary-line">
              <span class="summary-label">Cart</span>
              <span class="summary-value">{{ form.min_cart_total ? `≥ ${money(form.min_cart_total)}` : 'Any' }}</span>
            </div>
            <div class="summary-line">
              <span class="summary-label">Items</span>
              <span class="summary-value">{{ form.min_item_count ? `≥ ${form.min_item_count}` : 'Any' }}</span>
            </div>
            <div class="summary-line">
              <span class="summary-label">Scope</span>
              <span class="summary-value">
                {{ form.product_scope === 'specific' ? `${form.product_ids.length} products` : 'All products' }}
              </span>
            </div>
          </a-card>
        </div>
      </aside>
    </div>
  </div>
</template>

<script setup>
/**
 * Promotion create/edit — ported from the ksa project's promotions/form.vue.
 * Same API contract: GET promotions/{id} → {promotion (+warehouses/products)};
 * POST promotions / PUT promotions/{id} with the flat payload (dates as
 * "YYYY-MM-DD HH:mm:ss", times as "HH:mm:ss", null = unlimited/unset).
 * Matching legacy behaviour: "Save as draft" submits is_active=false and the
 * primary button submits is_active=true — on edit too, so saving an inactive
 * promotion via the primary button re-activates it.
 */
import { ref, computed, watch, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  CheckOutlined, InboxOutlined, PercentageOutlined, GiftOutlined,
  AppstoreOutlined, TagsOutlined, ShopOutlined, EnvironmentOutlined,
  CalendarOutlined, FilterOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useAuthStore } from '../../stores/auth';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';

const { t } = useI18n();
const { money } = useFormat();
const auth = useAuthStore();
const route = useRoute();
const router = useRouter();

const isEdit = computed(() => !!route.params.id);
const isLoading = ref(true);
const saving = ref(false);

const emptyForm = () => ({
  id: null,
  name: '',
  code: '',
  description: '',
  kind: 'discount',
  discount_type: 'percentage',
  discount_value: 0,
  is_active: true,
  starts_at: null,
  ends_at: null,
  time_of_day_start: null,
  time_of_day_end: null,
  min_cart_total: null,
  min_item_count: null,
  product_scope: 'all',
  priority: 0,
  stackable: false,
  usage_limit_total: null,
  usage_limit_per_customer: null,
  warehouse_ids: [],
  product_ids: [],
});

const form = ref(emptyForm());
const fieldErrors = ref({});
const restrictHours = ref(false);

const warehouses = ref([]);
const products = ref([]);

const productOptions = computed(() => products.value.map(p => ({
  value: p.id,
  label: p.code ? `${p.name} (${p.code})` : p.name,
})));

const selectedWarehouses = computed(() =>
  warehouses.value.filter(w => form.value.warehouse_ids.includes(w.id)));
const allWarehousesSelected = computed(() =>
  warehouses.value.length > 0 && form.value.warehouse_ids.length === warehouses.value.length);

const previewValue = computed(() => {
  const val = Number(form.value.discount_value || 0);
  return form.value.discount_type === 'percentage' ? `${val}%` : money(val);
});

const validityWindowLabel = computed(() => {
  const f = form.value.starts_at ? form.value.starts_at.substring(0, 16) : null;
  const to = form.value.ends_at ? form.value.ends_at.substring(0, 16) : null;
  if (!f && !to) return 'Always';
  if (f && !to) return `From ${f}`;
  if (!f && to) return `Until ${to}`;
  return `${f} → ${to}`;
});

watch(restrictHours, on => {
  if (!on) {
    form.value.time_of_day_start = null;
    form.value.time_of_day_end = null;
  }
});

function toggleWarehouse(id) {
  const ids = form.value.warehouse_ids;
  const i = ids.indexOf(id);
  if (i === -1) ids.push(id);
  else ids.splice(i, 1);
}
function toggleAllWarehouses() {
  form.value.warehouse_ids = allWarehousesSelected.value ? [] : warehouses.value.map(w => w.id);
}

/** "2026-05-14T10:00:00.000000Z" | "2026-05-14 10:00:00" → picker string. */
function toDateTimeString(value) {
  if (!value) return null;
  const s = String(value).replace('T', ' ');
  return s.length >= 19 ? s.substring(0, 19) : s;
}

function buildPayload(activate) {
  const f = form.value;
  const numOrNull = v => (v === '' || v === null || v === undefined ? null : Number(v));
  return {
    name: (f.name || '').trim(),
    code: f.code ? f.code.trim() : null,
    description: f.description || null,
    kind: f.kind,
    discount_type: f.discount_type,
    discount_value: Number(f.discount_value) || 0,
    is_active: activate === undefined ? !!f.is_active : !!activate,
    starts_at: f.starts_at || null,
    ends_at: f.ends_at || null,
    time_of_day_start: f.time_of_day_start || null,
    time_of_day_end: f.time_of_day_end || null,
    min_cart_total: numOrNull(f.min_cart_total),
    min_item_count: numOrNull(f.min_item_count),
    product_scope: f.product_scope,
    priority: Number(f.priority) || 0,
    stackable: !!f.stackable,
    usage_limit_total: numOrNull(f.usage_limit_total),
    usage_limit_per_customer: numOrNull(f.usage_limit_per_customer),
    warehouse_ids: f.warehouse_ids || [],
    product_ids: f.product_scope === 'specific' ? (f.product_ids || []) : [],
  };
}

function validate() {
  fieldErrors.value = {};
  if (!form.value.name || !form.value.name.trim()) {
    fieldErrors.value.name = t('Field_is_required');
  }
  if (Number(form.value.discount_value) < 0) {
    fieldErrors.value.discount_value = 'Must be ≥ 0';
  }
  return Object.keys(fieldErrors.value).length === 0;
}

async function submit(activate) {
  if (!validate()) {
    message.error(t('Please_fill_the_form_correctly'));
    return;
  }
  saving.value = true;
  const payload = buildPayload(activate);
  try {
    if (isEdit.value) {
      await http.put(`promotions/${form.value.id}`, payload);
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('promotions', payload);
      message.success(t('Successfully_Created'));
    }
    router.push('/promotions');
  } catch (e) {
    const errors = e?.data?.errors;
    if (errors) Object.values(errors).flat().forEach(msg => message.error(String(msg)));
    else message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

async function fetchPromotion(id) {
  const data = await http.get(`promotions/${id}`);
  const p = data?.promotion;
  if (!p) throw new Error('Not found');
  form.value = {
    id: p.id,
    name: p.name || '',
    code: p.code || '',
    description: p.description || '',
    kind: p.kind || 'discount',
    discount_type: p.discount_type || 'percentage',
    discount_value: Number(p.discount_value) || 0,
    is_active: !!p.is_active,
    starts_at: toDateTimeString(p.starts_at),
    ends_at: toDateTimeString(p.ends_at),
    time_of_day_start: p.time_of_day_start || null,
    time_of_day_end: p.time_of_day_end || null,
    min_cart_total: p.min_cart_total !== null ? Number(p.min_cart_total) : null,
    min_item_count: p.min_item_count !== null ? Number(p.min_item_count) : null,
    product_scope: p.product_scope || 'all',
    priority: Number(p.priority) || 0,
    stackable: !!p.stackable,
    usage_limit_total: p.usage_limit_total !== null ? Number(p.usage_limit_total) : null,
    usage_limit_per_customer: p.usage_limit_per_customer !== null ? Number(p.usage_limit_per_customer) : null,
    warehouse_ids: (p.warehouses || []).map(w => w.id),
    product_ids: (p.products || []).map(x => x.id),
  };
  restrictHours.value = !!(p.time_of_day_start || p.time_of_day_end);
}

async function fetchWarehouses() {
  try {
    const data = await http.get('warehouses', { page: 1, SortField: 'id', SortType: 'asc', search: '', limit: -1 });
    warehouses.value = data?.warehouses || [];
  } catch (e) { /* grid renders its empty state */ }
}
async function fetchProducts() {
  try {
    const data = await http.get('products', { page: 1, SortField: 'id', SortType: 'asc', search: '', limit: -1 });
    if (Array.isArray(data)) products.value = data;
    else if (Array.isArray(data?.products)) products.value = data.products;
    else if (Array.isArray(data?.data)) products.value = data.data;
  } catch (e) { /* select stays empty */ }
}

onMounted(async () => {
  const tasks = [fetchWarehouses(), fetchProducts()];
  if (isEdit.value) tasks.push(fetchPromotion(route.params.id));
  try {
    await Promise.all(tasks);
  } catch (e) {
    message.error(t('InvalidData'));
    router.push('/promotions');
    return;
  } finally {
    isLoading.value = false;
  }
});
</script>

<style scoped>
/* ---------------- layout ---------------- */
.form-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 340px;
  gap: 16px;
  align-items: start;
}
@media (max-width: 1100px) {
  .form-grid {
    grid-template-columns: 1fr;
  }
}
.section {
  margin-bottom: 16px;
}
.section-head {
  display: flex;
  align-items: flex-start;
  gap: 14px;
  margin-bottom: 20px;
}
.step {
  width: 30px;
  height: 30px;
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  background: linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%);
  color: #fff;
  font-weight: 700;
  font-size: 13px;
}
.section-head h3 {
  margin: 2px 0 2px;
  font-size: 16px;
  font-weight: 600;
}
.section-head p {
  margin: 0;
  font-size: 12.5px;
  opacity: 0.55;
}
.head-action {
  margin-left: auto;
}
.hint {
  font-size: 12px;
  opacity: 0.55;
  margin-top: 4px;
  line-height: 1.45;
}
.optional {
  font-size: 11px;
  opacity: 0.45;
  margin-left: 4px;
}
.mono :deep(input) {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  letter-spacing: 0.02em;
}
.toggle-row {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 13.5px;
}

/* ---------------- hand-rolled segment (dark-safe) ---------------- */
.seg {
  display: flex;
  gap: 4px;
  padding: 3px;
  border-radius: 10px;
  background: rgba(128, 128, 128, 0.13);
}
.seg button {
  flex: 1;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  height: 32px;
  border: 0;
  border-radius: 8px;
  background: transparent;
  cursor: pointer;
  font-size: 13px;
  font-weight: 500;
  color: inherit;
  opacity: 0.65;
  transition: background 0.15s ease, opacity 0.15s ease;
}
.seg button.active {
  background: #7c3aed;
  color: #fff;
  opacity: 1;
}

/* ---------------- selectable cards ---------------- */
.choice-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}
@media (max-width: 640px) {
  .choice-grid {
    grid-template-columns: 1fr;
  }
}
.warehouse-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 10px;
}
.choice-card {
  display: flex;
  align-items: center;
  text-align: left;
  gap: 12px;
  padding: 12px 14px;
  border: 1.5px solid rgba(128, 128, 128, 0.25);
  border-radius: 10px;
  background: transparent;
  cursor: pointer;
  color: inherit;
  transition: border-color 0.15s ease, background 0.15s ease;
}
.choice-card:hover {
  border-color: rgba(124, 58, 237, 0.5);
}
.choice-card.active {
  border-color: #7c3aed;
  background: rgba(124, 58, 237, 0.07);
}
.choice-icon {
  width: 34px;
  height: 34px;
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 9px;
  background: rgba(128, 128, 128, 0.12);
  color: #7c3aed;
  font-size: 15px;
  transition: background 0.15s ease, color 0.15s ease;
}
.choice-card.active .choice-icon {
  background: #7c3aed;
  color: #fff;
}
.choice-text {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 1px;
}
.choice-text strong {
  font-size: 13.5px;
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.choice-text small {
  font-size: 11.5px;
  opacity: 0.55;
}
.choice-check {
  width: 20px;
  height: 20px;
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  border: 1.5px solid rgba(128, 128, 128, 0.35);
  color: transparent;
  font-size: 10px;
  transition: all 0.15s ease;
}
.choice-card.active .choice-check {
  background: #7c3aed;
  border-color: #7c3aed;
  color: #fff;
}

/* ---------------- sticky preview ---------------- */
.side-sticky {
  position: sticky;
  top: 16px;
}
.preview-card {
  position: relative;
  overflow: hidden;
  border-radius: 14px;
  padding: 22px;
  color: #fff;
  background: linear-gradient(135deg, #1e1b31 0%, #312952 100%);
  margin-bottom: 12px;
}
.preview-card::before {
  content: '';
  position: absolute;
  top: -40%;
  right: -10%;
  width: 200px;
  height: 200px;
  background: radial-gradient(circle, rgba(124, 58, 237, 0.45) 0%, transparent 70%);
  pointer-events: none;
}
.preview-eyebrow {
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: rgba(255, 255, 255, 0.55);
  margin-bottom: 14px;
}
.preview-value-row {
  display: flex;
  align-items: baseline;
  gap: 8px;
  margin-bottom: 14px;
}
.preview-value {
  font-size: 38px;
  font-weight: 800;
  line-height: 1;
  letter-spacing: -0.02em;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}
.preview-off {
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.14em;
  color: rgba(255, 255, 255, 0.5);
}
.preview-name {
  font-size: 15px;
  font-weight: 600;
  margin-bottom: 4px;
  position: relative;
}
.preview-desc {
  font-size: 12px;
  color: rgba(255, 255, 255, 0.65);
  line-height: 1.45;
  position: relative;
}
.preview-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 12px;
  position: relative;
}
.ptag {
  font-size: 10.5px;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  padding: 3px 9px;
  border-radius: 6px;
  background: rgba(255, 255, 255, 0.1);
  color: rgba(255, 255, 255, 0.8);
}
.ptag-discount {
  background: rgba(59, 130, 246, 0.22);
  color: #93c5fd;
}
.ptag-promotion {
  background: rgba(46, 213, 115, 0.2);
  color: #6ee7b7;
}
.ptag-code {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  background: rgba(255, 255, 255, 0.16);
  color: #fff;
}
.ptag-active {
  background: rgba(34, 197, 94, 0.22);
  color: #6ee7b7;
}
.ptag-draft {
  background: rgba(234, 179, 8, 0.2);
  color: #fcd34d;
}

/* ---------------- side summary cards ---------------- */
.side-card {
  margin-bottom: 12px;
}
.side-head {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 10px;
}
.side-head > .anticon {
  color: #7c3aed;
}
.side-head h4 {
  margin: 0;
  flex: 1;
  font-size: 13px;
  font-weight: 600;
}
.chip-row {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}
.chip-row :deep(.ant-tag) {
  margin-inline-end: 0;
}
.summary-line {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  font-size: 12.5px;
  padding: 5px 0;
  border-bottom: 1px solid rgba(128, 128, 128, 0.12);
}
.summary-line:last-child {
  border-bottom: 0;
}
.summary-label {
  opacity: 0.55;
}
.summary-value {
  font-weight: 600;
  text-align: right;
}
</style>
