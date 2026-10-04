<template>
  <div class="page">
    <PageHeader :title="isEdit ? 'Edit Sales Contract' : 'New Sales Contract'" :breadcrumb="[$t('Sales'), 'Sales Contracts', isEdit ? $t('Edit') : $t('Add')]" />

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0"><a-spin size="large" /></div>

    <a-form v-else ref="formRef" :model="form" :rules="rules" layout="vertical">
      <a-row :gutter="16">
        <a-col :xs="24" :xl="16">
          <!-- Contract -->
          <a-card size="small" title="Contract" style="margin-bottom: 16px">
            <a-row :gutter="12">
              <a-col :xs="24" :md="8">
                <a-form-item label="#" name="contract_number">
                  <a-input v-model:value="form.contract_number" :disabled="isEdit" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="16">
                <a-form-item label="Subject" name="subject">
                  <a-input v-model:value="form.subject" placeholder="e.g. Monthly garden maintenance" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Customer')" name="client_id">
                  <a-select v-model:value="form.client_id" show-search option-filter-prop="label" :options="clientOptions" placeholder="Choose a customer" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item label="Location" name="warehouse_id" extra="Where its invoices are recorded (empty = company default).">
                  <a-select v-model:value="form.warehouse_id" allow-clear :options="warehouseOptions" placeholder="Company default" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item label="Type" name="type">
                  <a-select v-model:value="form.type" allow-clear :options="TYPE_OPTIONS" placeholder="Choose" />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="8">
                <a-form-item :label="$t('start_date')" name="start_date">
                  <a-date-picker v-model:value="form.start_date" value-format="YYYY-MM-DD" style="width: 100%" />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="8">
                <a-form-item label="End date" name="end_date" extra="Empty = open-ended.">
                  <a-date-picker v-model:value="form.end_date" value-format="YYYY-MM-DD" style="width: 100%" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Status')" name="status">
                  <a-select v-model:value="form.status" :options="STATUS_OPTIONS" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="16">
                <a-form-item label=" ">
                  <a-checkbox v-model:checked="form.hide_from_customer">Hide from the customer's portal</a-checkbox>
                </a-form-item>
              </a-col>
              <a-col :span="24">
                <a-form-item :label="$t('Description')" name="description">
                  <RichTextEditor v-model="form.description" />
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>

          <!-- Lines -->
          <a-card size="small" title="Products / services billed" style="margin-bottom: 16px">
            <template #extra>
              <a-button size="small" @click="addLine()"><template #icon><PlusOutlined /></template>Add line</a-button>
            </template>
            <a-table :data-source="form.items" :columns="lineColumns" :pagination="false" size="small" row-key="_k">
              <template #bodyCell="{ column, record, index }">
                <template v-if="column.key === 'product'">
                  <a-select
                    v-model:value="record.product_id" show-search allow-clear option-filter-prop="label" style="width: 100%"
                    :options="productOptions" placeholder="Product / service (optional)" @change="v => onProduct(record, v)"
                  />
                  <a-input v-model:value="record.name" placeholder="Description on the invoice" style="margin-top: 4px" />
                </template>
                <template v-else-if="column.key === 'quantity'">
                  <a-input-number v-model:value="record.quantity" :min="0.001" style="width: 100%" />
                </template>
                <template v-else-if="column.key === 'price'">
                  <a-input-number v-model:value="record.price" :min="0" style="width: 100%" />
                </template>
                <template v-else-if="column.key === 'tax'">
                  <a-input-number v-model:value="record.tax_percent" :min="0" addon-after="%" style="width: 100%" />
                </template>
                <template v-else-if="column.key === 'total'">{{ money(lineTotal(record)) }}</template>
                <template v-else-if="column.key === 'del'">
                  <a-button type="text" danger size="small" @click="form.items.splice(index, 1)"><template #icon><DeleteOutlined /></template></a-button>
                </template>
              </template>
            </a-table>
            <div class="sc-total">Contract value per invoice: <b>{{ money(contractTotal) }}</b></div>
          </a-card>
        </a-col>

        <a-col :xs="24" :xl="8">
          <!-- Recurring invoices -->
          <a-card size="small" title="Recurring invoices" style="margin-bottom: 16px">
            <a-form-item style="margin-bottom: 8px">
              <a-switch v-model:checked="form.billing_enabled" /> <span style="margin-left: 8px">Invoice this contract automatically</span>
            </a-form-item>
            <template v-if="form.billing_enabled">
              <a-form-item label="Every">
                <a-input-group compact>
                  <a-input-number v-model:value="form.billing_interval" :min="1" :max="24" style="width: 30%" />
                  <a-select v-model:value="form.billing_frequency" :options="FREQUENCY_OPTIONS" style="width: 70%" />
                </a-input-group>
              </a-form-item>
              <a-form-item label="First / next invoice date" extra="Empty = the start date. Each invoice moves it one period on.">
                <a-date-picker v-model:value="form.next_invoice_date" value-format="YYYY-MM-DD" style="width: 100%" />
              </a-form-item>
              <a-alert
                type="info" show-icon
                :message="`${billingLabel(form)} invoice of ${money(contractTotal)} to the customer, created automatically every morning it's due (unpaid, like any sale). Only while the contract is Active.`"
              />
            </template>
            <div v-else style="opacity: 0.6; font-size: 13px">
              Off: invoices are created only with "Generate invoice" on the contract.
            </div>
          </a-card>

          <a-space>
            <a-button type="primary" :loading="saving" @click="submit">{{ $t('submit') }}</a-button>
            <a-button @click="$router.back()">{{ $t('Cancel') }}</a-button>
          </a-space>
        </a-col>
      </a-row>
    </a-form>
  </div>
</template>

<script setup>
/**
 * Sales Contract create / edit (sales_contracts/create | /{id}/edit, POST /
 * PUT sales_contracts). Lines: optional product/service (fills name, price,
 * tax) + description, quantity, price, tax %. The contract value is the sum
 * of the lines = what each recurring invoice bills.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import RichTextEditor from '../../components/RichTextEditor.vue';
import http from '../../lib/http';
import { sortByLabel } from '../../lib/filterOptions';
import { useFormat } from '../../composables/useFormat';
import { STATUS_OPTIONS, FREQUENCY_OPTIONS, billingLabel } from './salesContractVocab';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const { money } = useFormat();
const id = computed(() => route.params.id);
const isEdit = computed(() => !!id.value);

const TYPE_OPTIONS = sortByLabel([
  { value: 'service', label: 'Service' }, { value: 'maintenance', label: 'Maintenance' }, { value: 'subscription', label: 'Subscription' },
  { value: 'lease', label: 'Lease / rental' }, { value: 'supply', label: 'Supply' }, { value: 'other', label: 'Other' },
]);

const loading = ref(true);
const saving = ref(false);
const formRef = ref();
const lists = ref({ clients: [], warehouses: [], products: [] });
let seq = 0;
const newLine = (o = {}) => ({ _k: ++seq, product_id: undefined, name: '', quantity: 1, price: 0, tax_percent: 0, tax_method: '1', ...o });
const form = ref({
  contract_number: '', subject: '', client_id: undefined, warehouse_id: undefined, type: 'service',
  start_date: null, end_date: null, status: 'draft', hide_from_customer: false, description: '',
  billing_enabled: false, billing_frequency: 'monthly', billing_interval: 1, next_invoice_date: null,
  items: [newLine()],
});

const clientOptions = computed(() => sortByLabel(lists.value.clients.map(c => ({ value: c.id, label: c.name }))));
const warehouseOptions = computed(() => sortByLabel(lists.value.warehouses.map(w => ({ value: w.id, label: w.name }))));
const productOptions = computed(() => sortByLabel(lists.value.products.map(p => ({
  value: p.id, label: `${p.name}${p.code ? ' · ' + p.code : ''}${p.type === 'is_service' ? ' (service)' : ''}`,
}))));
const productById = computed(() => Object.fromEntries(lists.value.products.map(p => [p.id, p])));

function addLine() { form.value.items.push(newLine()); }
function onProduct(line, productId) {
  const p = productById.value[productId];
  if (!p) return;
  line.name = p.name;
  line.price = Number(p.price) || 0;
  line.tax_percent = Number(p.TaxNet) || 0;
  line.tax_method = String(p.tax_method || '1');
}
function lineTotal(l) {
  const base = (Number(l.quantity) || 0) * (Number(l.price) || 0);
  return Math.round((base + (String(l.tax_method) === '1' ? base * (Number(l.tax_percent) || 0) / 100 : 0)) * 100) / 100;
}
const contractTotal = computed(() => form.value.items.reduce((s, l) => s + lineTotal(l), 0));

const lineColumns = [
  { title: 'Product / description', key: 'product' },
  { title: 'Qty', key: 'quantity', width: 90 },
  { title: 'Price', key: 'price', width: 120 },
  { title: 'Tax', key: 'tax', width: 110 },
  { title: 'Total', key: 'total', width: 110, align: 'right' },
  { title: '', key: 'del', width: 44 },
];

const rules = computed(() => ({
  subject: [{ required: true, message: t('Field_is_required') }],
  client_id: [{ required: true, message: t('Field_is_required') }],
  start_date: [{ required: true, message: t('Field_is_required') }],
  status: [{ required: true, message: t('Field_is_required') }],
}));

async function submit() {
  try { await formRef.value.validate(); } catch (e) { return; }
  const items = form.value.items.filter(l => (l.name || '').trim() || l.product_id).map(l => ({
    product_id: l.product_id || null, name: (l.name || '').trim() || productById.value[l.product_id]?.name || 'Item',
    quantity: Number(l.quantity) || 1, price: Number(l.price) || 0, tax_percent: Number(l.tax_percent) || 0, tax_method: String(l.tax_method || '1'),
  }));
  if (form.value.billing_enabled && !items.length) { message.warning('Add at least one line to invoice.'); return; }
  saving.value = true;
  const payload = { ...form.value, items };
  try {
    if (isEdit.value) {
      await http.put(`sales_contracts/${id.value}`, payload);
      message.success(t('Successfully_Updated'));
      router.push(`/sales-contracts/${id.value}`);
    } else {
      const res = await http.post('sales_contracts', payload);
      message.success(t('Successfully_Created'));
      router.push(`/sales-contracts/${res.id}`);
    }
  } catch (e) {
    const errors = e?.data?.errors;
    if (errors) Object.values(errors).flat().forEach(m => message.error(String(m)));
    else message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

onMounted(async () => {
  try {
    const data = await http.get(isEdit.value ? `sales_contracts/${id.value}/edit` : 'sales_contracts/create');
    lists.value = { clients: data.clients || [], warehouses: data.warehouses || [], products: data.products || [] };
    if (isEdit.value) {
      const c = data.contract || {};
      form.value = {
        ...form.value, ...c,
        start_date: c.start_date ? String(c.start_date).slice(0, 10) : null,
        end_date: c.end_date ? String(c.end_date).slice(0, 10) : null,
        next_invoice_date: c.next_invoice_date ? String(c.next_invoice_date).slice(0, 10) : null,
        billing_enabled: !!c.billing_enabled, hide_from_customer: !!c.hide_from_customer,
        items: (c.items || []).map(i => newLine({ ...i, quantity: Number(i.quantity), price: Number(i.price), tax_percent: Number(i.tax_percent) })),
      };
      if (!form.value.items.length) form.value.items = [newLine()];
    } else {
      form.value.contract_number = data.next_contract_number || '';
      form.value.start_date = new Date().toISOString().slice(0, 10);
      // Opened from a customer's details (Contracts tab -> New contract).
      const qc = Number(route.query.client_id);
      if (qc && lists.value.clients.some(c => Number(c.id) === qc)) form.value.client_id = qc;
    }
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
    router.push('/sales-contracts');
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.sc-total {
  text-align: right;
  margin-top: 10px;
  font-size: 14px;
}
</style>
