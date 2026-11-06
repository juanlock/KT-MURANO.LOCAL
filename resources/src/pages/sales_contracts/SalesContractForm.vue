<template>
  <div class="page">
    <PageHeader :title="isEdit ? $t('tx_Edit_Sales_Contract_929a7') : $t('tx_New_Sales_Contract_bdb00')" :breadcrumb="[$t('Sales'), 'Sales Contracts', isEdit ? $t('Edit') : $t('Add')]" />

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0"><a-spin size="large" /></div>

    <a-form v-else ref="formRef" :model="form" :rules="rules" layout="vertical">
      <a-row :gutter="16">
        <a-col :xs="24" :xl="16">
          <!-- Contract -->
          <a-card size="small" :title="$t('Contract')" style="margin-bottom: 16px">
            <a-row :gutter="12">
              <a-col :xs="24" :md="8">
                <a-form-item label="#" name="contract_number">
                  <a-input v-model:value="form.contract_number" :disabled="isEdit" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="16">
                <a-form-item :label="$t('Subject')" name="subject">
                  <a-input v-model:value="form.subject" placeholder="e.g. Monthly garden maintenance" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Customer')" name="client_id">
                  <a-select v-model:value="form.client_id" show-search option-filter-prop="label" :options="clientOptions" :placeholder="$t('tx_Choose_a_customer_a2cdd')" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Location')" name="warehouse_id" extra="Where its invoices are recorded (empty = company default).">
                  <a-select v-model:value="form.warehouse_id" allow-clear :options="warehouseOptions" :placeholder="$t('tx_Company_default_85038')" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('type')" name="type">
                  <a-select v-model:value="form.type" allow-clear :options="TYPE_OPTIONS" :placeholder="$t('tx_Choose_961f2')" />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="8">
                <a-form-item :label="$t('start_date')" name="start_date">
                  <a-date-picker v-model:value="form.start_date" value-format="YYYY-MM-DD" style="width: 100%" />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="8">
                <a-form-item :label="$t('tx_End_date_b7de7')" name="end_date" extra="Empty = open-ended.">
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
                  <a-checkbox v-model:checked="form.hide_from_customer">{{ $t('tx_Hide_from_the_customer_s_portal_02eb8') }}</a-checkbox>
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
          <a-card size="small" :title="$t('tx_Products_services_billed_41188')" style="margin-bottom: 16px">
            <template #extra>
              <a-space>
                <!-- A product/service that doesn't exist yet: create it here
                     (same Quick Add as the sale form) and bill it on a new line. -->
                <a-button size="small" @click="quickProductOpen = true"><template #icon><PlusOutlined /></template>{{ $t('tx_New_product_service_e5f74') }}</a-button>
                <!-- Recurring visits (service jobs) open in a modal; "Edit" once switched on. -->
                <a-button v-if="serviceEnabled" size="small" @click="jobsModalOpen = true">
                  <template #icon><component :is="form.jobs_enabled ? EditOutlined : PlusOutlined" /></template>
                  {{ form.jobs_enabled ? $t('Edit_Service_Job') : $t('tx_Add_Service_Job_90a45') }}
                </a-button>
                <a-button size="small" @click="addLine()"><template #icon><PlusOutlined /></template>{{ $t('Add_Line') }}</a-button>
              </a-space>
            </template>
            <a-table :data-source="form.items" :columns="lineColumns" :pagination="false" size="small" row-key="_k">
              <template #bodyCell="{ column, record, index }">
                <template v-if="column.key === 'product'">
                  <a-select
                    v-model:value="record.product_id" show-search allow-clear option-filter-prop="label" style="width: 100%"
                    :options="productOptions" :placeholder="$t('tx_Product_service_optional_94276')" @change="v => onProduct(record, v)"
                  />
                  <a-input v-model:value="record.name" :placeholder="$t('tx_Description_on_the_invoice_44906')" style="margin-top: 4px" />
                  <!-- The line's note on each invoice: static text, or with
                       placeholders filled per invoice ({{month}}, {{number}}...). -->
                  <a-input
                    v-model:value="record.note" allow-clear style="margin-top: 4px"
                    placeholder="Note on each invoice, e.g. Note: {{month}} installment services"
                  >
                    <template #addonAfter>
                      <NotePlaceholderHelp @insert="tk => (record.note = appendToken(record.note, tk))" @use="ex => (record.note = ex)" />
                    </template>
                  </a-input>
                  <div v-if="record.note && scheduleRef?.firstNote(index)" class="note-preview">
                    {{ $t('tx_1st_invoice_62445') }} <i>{{ scheduleRef.firstNote(index) }}</i>
                  </div>
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
            <!-- Order Tax on each invoice (like a sale's Order Tax); a new
                 contract starts with the company's Default Tax. -->
            <div class="sc-total">
              <span class="sc-tax">
                {{ $t('OrderTax') }}
                <a-input-number v-model:value="form.order_tax_rate" :min="0" :max="100" :step="0.001" addon-after="%" size="small" style="width: 130px" />
              </span>
              <span>{{ $t('Lines') }} {{ money(linesTotal) }}</span>
              <span v-if="orderTax > 0">{{ $t('tx_tax_904fd') }} {{ money(orderTax) }}</span>
              <span>= per invoice <b>{{ money(contractTotal) }}</b></span>
            </div>
          </a-card>
          <QuickAddProduct v-model:open="quickProductOpen" context="sale" @created="onProductCreated" />
        </a-col>

        <a-col :xs="24" :xl="8">
          <!-- Recurring invoices -->
          <a-card size="small" :title="$t('tx_Recurring_invoices_3f486')" style="margin-bottom: 16px">
            <a-form-item style="margin-bottom: 8px">
              <a-switch v-model:checked="form.billing_enabled" /> <span style="margin-left: 8px">{{ $t('tx_Invoice_this_contract_automatically_3cfae') }}</span>
            </a-form-item>
            <!-- Each invoice created as a Draft (Pending) to review and finalize
                 -- no stock out until it's completed. -->
            <a-form-item v-if="form.billing_enabled" style="margin-bottom: 8px" :extra="$t('tx_Each_invoice_is_created_as_a_Draft_Pending_no_stock_out_for__4128e')">
              <a-switch v-model:checked="form.invoice_as_draft" size="small" /> <span style="margin-left: 8px">{{ $t('tx_Create_invoices_as_drafts_review_first_66cce') }}</span>
            </a-form-item>
            <!-- The note on each invoice: fixed text or placeholders filled
                 per invoice (empty = the automatic contract text). -->
            <a-form-item v-if="form.billing_enabled" :label="$t('tx_Invoice_note_93f39')" style="margin-bottom: 12px">
              <a-input v-model:value="form.invoice_note" allow-clear placeholder="e.g. Service made on {{month}}">
                <template #addonAfter>
                  <NotePlaceholderHelp @insert="tk => (form.invoice_note = appendToken(form.invoice_note, tk))" @use="ex => (form.invoice_note = ex)" />
                </template>
              </a-input>
              <div v-if="form.invoice_note && scheduleRef?.firstInvoiceNote()" class="note-preview">
                {{ $t('tx_1st_invoice_62445') }} <i>{{ scheduleRef.firstInvoiceNote() }}</i>
              </div>
            </a-form-item>
            <RecurringSchedule
              v-if="form.billing_enabled" ref="scheduleRef"
              :value="form" :start-date="form.start_date" :end-date="form.end_date"
              :notes="form.items.map(l => l.note)" :invoice-note="form.invoice_note || ''" :contract-id="isEdit ? Number(id) : null"
            >
              <template #summary>
                <a-alert
                  type="info" show-icon style="margin-bottom: 12px"
                  :message="`${billingLabel(form)} invoice of ${money(contractTotal)} to the customer, created automatically every morning it's due (unpaid, like any sale). Only while the contract is Active.`"
                />
              </template>
            </RecurringSchedule>
            <div v-else style="opacity: 0.6; font-size: 13px">
              {{ $t('tx_Off_invoices_are_created_only_with_Generate_invoice_on_the_c_6b28b') }}
            </div>
          </a-card>

          <!-- Recurring service jobs (visits): e.g. weekly mowing billed monthly.
               Own schedule; each visit becomes a Service Job (no price -- the
               invoices above bill the work). Needs the Service module. -->
          <a-modal v-if="serviceEnabled" v-model:open="jobsModalOpen" width="720px" :title="$t('Contract_Service_Jobs')">
            <template #footer>
              <span style="opacity: 0.6; margin-right: 12px">{{ $t('tx_Saved_together_with_the_contract_2d7c4') }}</span>
              <a-button type="primary" @click="jobsModalOpen = false">{{ $t('Done') }}</a-button>
            </template>
            <a-form-item style="margin-bottom: 8px">
              <a-switch v-model:checked="form.jobs_enabled" /> <span style="margin-left: 8px">{{ $t('Contract_Jobs_Switch') }}</span>
            </a-form-item>
            <template v-if="form.jobs_enabled">
              <a-form-item :label="$t('tx_Visit_title_78f3b')" name="jobs_service_item" style="margin-bottom: 12px">
                <a-input v-model:value="form.jobs_service_item" allow-clear placeholder="e.g. Lawn mowing - {{weekday}} {{date}}">
                  <template #addonAfter>
                    <NotePlaceholderHelp @insert="tk => (form.jobs_service_item = appendToken(form.jobs_service_item, tk))" @use="ex => (form.jobs_service_item = ex)" />
                  </template>
                </a-input>
                <div class="note-preview">Empty = the contract subject.</div>
              </a-form-item>
              <a-row :gutter="8">
                <a-col :span="12">
                  <a-form-item :label="$t('tx_Operator_crew_lead_330ce')">
                    <a-select v-model:value="form.jobs_technician_id" allow-clear :options="technicianOptions" :placeholder="$t('tx_Assign_later_c40c6')" />
                  </a-form-item>
                </a-col>
                <a-col :span="12">
                  <a-form-item :label="$t('Job_Type')">
                    <a-input v-model:value="form.jobs_job_type" :placeholder="$t('tx_e_g_Maintenance_b212b')" />
                  </a-form-item>
                </a-col>
                <a-col :span="12">
                  <a-form-item :label="$t('tx_Visit_time_4b44d')" extra="Empty = all-day task on the visit day.">
                    <a-time-picker v-model:value="form.jobs_time" value-format="HH:mm" format="HH:mm" :minute-step="15" :placeholder="$t('Intranet_All_Day')" style="width: 100%" />
                  </a-form-item>
                </a-col>
                <a-col :span="12">
                  <a-form-item :label="$t('tx_Create_ahead_e0597')" :extra="$t('tx_Days_before_each_visit_35366')">
                    <a-input-number v-model:value="form.jobs_lead_days" :min="0" :max="60" addon-after="days" style="width: 100%" />
                  </a-form-item>
                </a-col>
              </a-row>
              <RecurringSchedule
                :value="jobSchedule" :start-date="form.start_date" :end-date="form.end_date"
                :invoice-note="form.jobs_service_item || ''" :contract-id="null"
                noun="visit" day-label="Visit day" note-label="Title" :show-period="false"
                first-date-label="First / next visit date" first-date-help="Empty = the start date, moved to the first matching visit day."
              >
                <template #summary>
                  <a-alert
                    type="info" show-icon style="margin-bottom: 12px"
                    :message="$t('tx_Each_visit_is_created_as_a_Service_Job_status_Pending_for_th_7bd84')"
                  />
                </template>
              </RecurringSchedule>
            </template>
            <div v-else style="opacity: 0.6; font-size: 13px">
              {{ $t('tx_Off_no_service_jobs_are_created_from_this_contract_78729') }}
            </div>
          </a-modal>

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
import { t as tEs } from '../../i18n/index.js';
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
import { PlusOutlined, DeleteOutlined, EditOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import RichTextEditor from '../../components/RichTextEditor.vue';
import QuickAddProduct from '../../components/QuickAddProduct.vue';
import RecurringSchedule from '../../components/RecurringSchedule.vue';
import NotePlaceholderHelp, { appendToken } from '../../components/NotePlaceholderHelp.vue';
import http from '../../lib/http';
import { sortByLabel } from '../../lib/filterOptions';
import { useFormat } from '../../composables/useFormat';
import { STATUS_OPTIONS, billingLabel } from './salesContractVocab';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const { money, date, roundMoney } = useFormat();
const id = computed(() => route.params.id);
const isEdit = computed(() => !!id.value);

const TYPE_OPTIONS = sortByLabel([
  { value: 'service', label: tEs('Service', 'Service') }, { value: 'maintenance', label: tEs('Maintenance', 'Maintenance') }, { value: 'subscription', label: tEs('Subscription', 'Subscription') },
  { value: 'lease', label: tEs('tx_Lease_rental_b2ca6', 'Lease / rental') }, { value: 'supply', label: tEs('tx_Supply_4288e', 'Supply') }, { value: 'other', label: tEs('Other', 'Other') },
]);

const loading = ref(true);
const saving = ref(false);
const formRef = ref();
const lists = ref({ clients: [], warehouses: [], products: [] });
let seq = 0;
const newLine = (o = {}) => ({ _k: ++seq, product_id: undefined, name: '', note: '', quantity: 1, price: 0, tax_percent: 0, tax_method: '1', ...o });
const form = ref({
  contract_number: '', subject: '', client_id: undefined, warehouse_id: undefined, type: 'service',
  start_date: null, end_date: null, status: 'draft', hide_from_customer: false, description: '', invoice_note: '',
  order_tax_rate: 0,
  // New recurring contracts: invoices start as drafts to review.
  invoice_as_draft: true,
  billing_enabled: false, billing_frequency: 'monthly', billing_interval: 1, next_invoice_date: null,
  billing_count: null, billing_day_mode: 'same', billing_day: null,
  jobs_enabled: false, jobs_service_item: '', jobs_job_type: '', jobs_technician_id: undefined,
  jobs_time: null, jobs_lead_days: 7,
  items: [newLine()],
});

// Visit schedule: RecurringSchedule edits billing_* keys, so the visits get
// their own object, mapped to/from the contract's jobs_* fields.
const jobSchedule = ref({
  billing_frequency: 'weekly', billing_interval: 1, billing_count: null,
  billing_day_mode: 'same', billing_day: null, next_invoice_date: null,
});
const serviceEnabled = ref(false);
// "Add / Edit Service Job" button next to "New product / service" opens the visits modal.
const jobsModalOpen = ref(false);
const technicianOptions = computed(() => sortByLabel((lists.value.technicians || []).map(t2 => ({ value: t2.id, label: t2.name }))));

// Recurring settings + invoice preview: components/RecurringSchedule.vue
// (ref scheduleRef -> firstNote(i) for the line notes).
const scheduleRef = ref(null);

// ---------------- new product / service ----------------
const quickProductOpen = ref(false);
async function onProductCreated({ code }) {
  try {
    const data = await http.get('sales_contracts/create');
    lists.value.products = data.products || lists.value.products;
    const p = lists.value.products.find(x => x.code === code);
    if (!p) return;
    // Use the first line if it's still empty, else add one.
    const empty = form.value.items.find(l => !l.product_id && !(l.name || '').trim());
    const line = empty || newLine();
    if (!empty) form.value.items.push(line);
    line.product_id = p.id;
    onProduct(line, p.id);
  } catch (e) { /* the list just isn't refreshed */ }
}

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
const linesTotal = computed(() => form.value.items.reduce((s, l) => s + lineTotal(l), 0));
// Order Tax on the lines' total (rounded like the invoice: company decimals).
const orderTax = computed(() => roundMoney(linesTotal.value * (Number(form.value.order_tax_rate) || 0) / 100));
const contractTotal = computed(() => roundMoney(linesTotal.value + orderTax.value));

const lineColumns = [
  { title: tEs('tx_Product_description_78b07', 'Product / description'), key: 'product' },
  { title: tEs('Qty', 'Qty'), key: 'quantity', width: 90 },
  { title: tEs('Price', 'Price'), key: 'price', width: 120 },
  { title: tEs('Tax', 'Tax'), key: 'tax', width: 110 },
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
    note: (l.note || '').trim() || null,
    quantity: Number(l.quantity) || 1, price: Number(l.price) || 0, tax_percent: Number(l.tax_percent) || 0, tax_method: String(l.tax_method || '1'),
  }));
  if (form.value.billing_enabled && !items.length) { message.warning(tEs('tx_Add_at_least_one_line_to_invoice_fe564', 'Add at least one line to invoice.')); return; }
  saving.value = true;
  const js = jobSchedule.value;
  const payload = {
    ...form.value, items,
    jobs_technician_id: form.value.jobs_technician_id || null,
    jobs_frequency: js.billing_frequency, jobs_interval: js.billing_interval || 1, jobs_count: js.billing_count || null,
    jobs_day_mode: js.billing_day_mode, jobs_day: js.billing_day || null, next_job_date: js.next_invoice_date || null,
  };
  try {
    let res;
    if (isEdit.value) {
      res = await http.put(`sales_contracts/${id.value}`, payload);
      message.success(t('Successfully_Updated'));
    } else {
      res = await http.post('sales_contracts', payload);
      message.success(t('Successfully_Created'));
    }
    // Visits already due were created right away.
    if (res?.jobs_created?.length) message.info(`${res.jobs_created.length} service job(s) created: ${res.jobs_created.map(j => j.ref).join(', ')}`, 6);
    router.push(`/sales-contracts/${isEdit.value ? id.value : res.id}`);
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
    lists.value = { clients: data.clients || [], warehouses: data.warehouses || [], products: data.products || [], technicians: data.technicians || [] };
    serviceEnabled.value = !!data.service_enabled;
    if (isEdit.value) {
      const c = data.contract || {};
      jobSchedule.value = {
        billing_frequency: c.jobs_frequency || 'weekly', billing_interval: c.jobs_interval || 1, billing_count: c.jobs_count || null,
        billing_day_mode: c.jobs_day_mode || 'same', billing_day: c.jobs_day || null,
        next_invoice_date: c.next_job_date ? String(c.next_job_date).slice(0, 10) : null,
      };
      form.value = {
        ...form.value, ...c,
        start_date: c.start_date ? String(c.start_date).slice(0, 10) : null,
        end_date: c.end_date ? String(c.end_date).slice(0, 10) : null,
        next_invoice_date: c.next_invoice_date ? String(c.next_invoice_date).slice(0, 10) : null,
        billing_enabled: !!c.billing_enabled, hide_from_customer: !!c.hide_from_customer, invoice_as_draft: !!c.invoice_as_draft,
        billing_count: c.billing_count || null, billing_day_mode: c.billing_day_mode || 'same', billing_day: c.billing_day || null,
        jobs_enabled: !!c.jobs_enabled, jobs_service_item: c.jobs_service_item || '', jobs_job_type: c.jobs_job_type || '',
        jobs_technician_id: c.jobs_technician_id || undefined, jobs_time: c.jobs_time || null, jobs_lead_days: c.jobs_lead_days ?? 7,
        items: (c.items || []).map(i => newLine({ ...i, note: i.note || '', quantity: Number(i.quantity), price: Number(i.price), tax_percent: Number(i.tax_percent) })),
      };
      if (!form.value.items.length) form.value.items = [newLine()];
    } else {
      form.value.contract_number = data.next_contract_number || '';
      // New contract: the company's Default Tax as its Order Tax.
      form.value.order_tax_rate = Number(data.default_tax) || 0;
      form.value.start_date = new Date().toISOString().slice(0, 10);
      // System Settings -> Defaults -> default Location.
      if (data.default_warehouse_id) form.value.warehouse_id = data.default_warehouse_id;
      // Opened from a customer's details (Contracts tab -> New contract).
      const qc = Number(route.query.client_id);
      if (qc && lists.value.clients.some(c => Number(c.id) === qc)) form.value.client_id = qc;
      // Opened from Edit Sale -> "Add Contract Sale": that sale's customer, location and lines.
      if (route.query.from_sale) {
        let p = null;
        try { p = JSON.parse(sessionStorage.getItem('contract_prefill') || 'null'); sessionStorage.removeItem('contract_prefill'); } catch (e) { p = null; }
        if (p && String(p.from_sale) === String(route.query.from_sale)) {
          if (lists.value.clients.some(c => Number(c.id) === Number(p.client_id))) form.value.client_id = p.client_id;
          if (p.warehouse_id) form.value.warehouse_id = p.warehouse_id;
          if (p.order_tax_rate) form.value.order_tax_rate = p.order_tax_rate;
          if ((p.items || []).length) form.value.items = p.items.map(i => newLine({ ...i, note: i.note || '' }));
          const cl = lists.value.clients.find(c => Number(c.id) === Number(p.client_id));
          form.value.subject = form.value.subject || (cl ? `Recurring invoice - ${cl.name}` : '');
          form.value.billing_enabled = true;
        }
      }
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
  display: flex;
  justify-content: flex-end;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
  margin-top: 10px;
  font-size: 14px;
}
.sc-tax {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin-right: auto;
}
.note-preview {
  font-size: 12px;
  opacity: 0.7;
  margin-top: 2px;
}
</style>
