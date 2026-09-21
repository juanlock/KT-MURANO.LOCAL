<template>
  <div class="page">
    <PageHeader title="Billing" :breadcrumb="['Hospital', 'Billing']">
      <template #actions>
        <a-button type="primary" @click="openForm(null)">
          <template #icon><PlusOutlined /></template>
          New invoice
        </a-button>
      </template>
    </PageHeader>

    <div class="summary">
      <div class="sum">
        <span class="sum-label">Billed</span>
        <span class="sum-value">{{ money(totals.billed || 0) }}</span>
      </div>
      <div class="sum">
        <span class="sum-label">Collected</span>
        <span class="sum-value ok">{{ money(totals.paid || 0) }}</span>
      </div>
      <div class="sum" :class="{ 'sum--danger': (totals.due || 0) > 0 }">
        <span class="sum-label">Outstanding</span>
        <span class="sum-value">{{ money(totals.due || 0) }}</span>
      </div>
    </div>

    <a-card size="small" style="margin-bottom: 16px">
      <div class="toolbar">
        <a-input-search
          v-model:value="crud.search.value" placeholder="Search patient, MRN or reference…"
          allow-clear class="tb-search" @search="crud.reload"
        />
        <a-select
          v-model:value="filters.status" class="tb-item" allow-clear
          placeholder="All statuses" :options="INVOICE_STATUSES" @change="crud.reload"
        />
        <a-range-picker v-model:value="filters.range" class="tb-range" value-format="YYYY-MM-DD" @change="crud.reload" />
        <a-checkbox v-model:checked="filters.outstanding" class="tb-check" @change="crud.reload">
          Unpaid only
        </a-checkbox>
      </div>
    </a-card>

    <DataTable :crud="crud" :columns="columns" :searchable="false">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'patient'">
          <button type="button" class="link-cell" @click="$router.push(`/hospital/patients/${record.patient_id}`)">
            <div class="cell-name">{{ record.patient_name }}</div>
            <div class="cell-mrn">{{ record.patient_mrn }} · {{ record.reference }}</div>
          </button>
        </template>
        <template v-else-if="column.key === 'invoice_date'">{{ date(record.invoice_date) }}</template>
        <template v-else-if="column.key === 'total'">{{ money(record.total) }}</template>
        <template v-else-if="column.key === 'paid'">{{ money(record.paid) }}</template>
        <template v-else-if="column.key === 'due'">
          <span :class="{ danger: record.due > 0 }">{{ money(record.due) }}</span>
        </template>
        <template v-else-if="column.key === 'status'">
          <a-tag :color="optionOf(INVOICE_STATUSES, record.status).color">
            {{ labelOf(INVOICE_STATUSES, record.status) }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space :size="0">
            <a-tooltip title="Open">
              <a-button type="text" size="small" @click="openDetail(record)">
                <template #icon><EyeOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="record.due > 0 && record.status !== 'cancelled'" title="Take payment">
              <a-button type="text" size="small" @click="openPayment(record)">
                <template #icon><DollarOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Edit')">
              <a-button type="text" size="small" @click="openForm(record)">
                <template #icon><EditOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Delete')">
              <a-button type="text" size="small" danger @click="crud.remove(record, { label: record.reference })">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>

    <!-- Invoice form -->
    <a-modal
      :open="formOpen" :title="editing ? 'Edit invoice' : 'New invoice'" :width="820"
      :confirm-loading="saving" :ok-text="$t('submit')" :cancel-text="$t('Cancel')"
      @ok="submit" @cancel="formOpen = false"
    >
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
        <a-row :gutter="16">
          <a-col :xs="24" :md="12">
            <a-form-item label="Patient *" name="patient_id">
              <PatientPicker v-model="form.patient_id" :initial-option="editingPatient" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-form-item label="Invoice date">
              <a-date-picker v-model:value="form.invoice_date" style="width: 100%" value-format="YYYY-MM-DD" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-form-item label="Due date">
              <a-date-picker v-model:value="form.due_date" style="width: 100%" value-format="YYYY-MM-DD" />
            </a-form-item>
          </a-col>
        </a-row>

        <div class="lines-head">
          <span>Lines</span>
          <a-button size="small" type="primary" ghost @click="addLine">
            <template #icon><PlusOutlined /></template>
            Add line
          </a-button>
        </div>

        <div v-if="form.items.length" class="lines">
          <div v-for="(line, i) in form.items" :key="i" class="line">
            <a-select v-model:value="line.type" :options="INVOICE_ITEM_TYPES" class="line-type" />
            <a-input v-model:value="line.description" placeholder="Description" class="line-desc" />
            <a-input-number v-model:value="line.quantity" :min="0" :step="1" placeholder="Qty" class="line-qty" />
            <a-input-number v-model:value="line.unit_price" :min="0" :step="1" placeholder="Price" class="line-price" />
            <span class="line-total">{{ money(lineTotal(line)) }}</span>
            <a-button type="text" danger @click="form.items.splice(i, 1)">
              <template #icon><DeleteOutlined /></template>
            </a-button>
          </div>
        </div>
        <a-empty v-else :image="simpleEmptyImage" description="No lines yet" style="padding: 12px 0" />

        <a-row :gutter="16" style="margin-top: 12px">
          <a-col :xs="24" :md="8">
            <a-form-item label="Discount">
              <a-input-number v-model:value="form.discount" :min="0" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item label="Tax">
              <a-input-number v-model:value="form.tax" :min="0" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item label="Status">
              <a-select v-model:value="form.status" :options="INVOICE_STATUSES" />
            </a-form-item>
          </a-col>
        </a-row>

        <div class="totals-box">
          <div><span>Subtotal</span><b>{{ money(subtotal) }}</b></div>
          <div><span>Discount</span><b>-{{ money(form.discount || 0) }}</b></div>
          <div><span>Tax</span><b>{{ money(form.tax || 0) }}</b></div>
          <div class="grand"><span>Total</span><b>{{ money(grandTotal) }}</b></div>
        </div>

        <a-form-item :label="$t('Note')" style="margin-bottom: 0">
          <a-textarea v-model:value="form.notes" :rows="2" />
        </a-form-item>
      </a-form>
    </a-modal>

    <!-- Detail drawer -->
    <a-drawer :open="detailOpen" :width="680" :title="detail?.reference" @close="detailOpen = false">
      <template #extra>
        <a-button
          v-if="detail && detail.due > 0 && detail.status !== 'cancelled'"
          type="primary" @click="openPayment(detail)"
        >
          Take payment
        </a-button>
      </template>

      <template v-if="detail">
        <a-descriptions :column="2" size="small" bordered style="margin-bottom: 16px">
          <a-descriptions-item label="Patient">{{ detail.patient_name }}</a-descriptions-item>
          <a-descriptions-item label="MRN">{{ detail.patient_mrn }}</a-descriptions-item>
          <a-descriptions-item label="Date">{{ date(detail.invoice_date) }}</a-descriptions-item>
          <a-descriptions-item label="Status">
            <a-tag :color="optionOf(INVOICE_STATUSES, detail.status).color">
              {{ labelOf(INVOICE_STATUSES, detail.status) }}
            </a-tag>
          </a-descriptions-item>
        </a-descriptions>

        <a-table
          :columns="lineColumns" :data-source="detail.items" :row-key="r => r.id"
          :pagination="false" size="small"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'type'">{{ labelOf(INVOICE_ITEM_TYPES, record.type) }}</template>
            <template v-else-if="column.key === 'unit_price'">{{ money(record.unit_price) }}</template>
            <template v-else-if="column.key === 'total'">{{ money(record.total) }}</template>
          </template>
        </a-table>

        <div class="totals-box" style="margin-top: 16px">
          <div><span>Subtotal</span><b>{{ money(detail.subtotal) }}</b></div>
          <div><span>Discount</span><b>-{{ money(detail.discount) }}</b></div>
          <div><span>Tax</span><b>{{ money(detail.tax) }}</b></div>
          <div class="grand"><span>Total</span><b>{{ money(detail.total) }}</b></div>
          <div><span>Paid</span><b>{{ money(detail.paid) }}</b></div>
          <div class="grand" :class="{ danger: detail.due > 0 }"><span>Due</span><b>{{ money(detail.due) }}</b></div>
        </div>

        <a-divider orientation="left">Payments</a-divider>
        <a-list :data-source="detail.payments" size="small">
          <template #renderItem="{ item }">
            <a-list-item>
              <a-list-item-meta
                :title="money(item.amount)"
                :description="`${date(item.paid_on)} · ${labelOf(PAYMENT_METHODS, item.method)}${item.notes ? ' · ' + item.notes : ''}`"
              />
              <template #actions>
                <a-button type="text" size="small" danger @click="removePayment(item)">
                  <template #icon><DeleteOutlined /></template>
                </a-button>
              </template>
            </a-list-item>
          </template>
          <template #empty><a-empty :image="simpleEmptyImage" description="Nothing paid yet" /></template>
        </a-list>
      </template>
    </a-drawer>

    <!-- Payment -->
    <a-modal
      :open="paymentOpen" title="Take payment" :width="440"
      :confirm-loading="paying" :ok-text="$t('submit')" :cancel-text="$t('Cancel')"
      @ok="submitPayment" @cancel="paymentOpen = false"
    >
      <p v-if="payTarget" class="target">
        {{ payTarget.patient_name }} — outstanding <b>{{ money(payTarget.due) }}</b>
      </p>
      <a-form layout="vertical">
        <a-form-item label="Amount">
          <a-input-number v-model:value="paymentForm.amount" :min="0.01" :max="roundMoney(payTarget?.due)" style="width: 100%" />
        </a-form-item>
        <a-row :gutter="16">
          <a-col :span="12">
            <a-form-item label="Date">
              <a-date-picker v-model:value="paymentForm.paid_on" style="width: 100%" value-format="YYYY-MM-DD" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item label="Method">
              <a-select v-model:value="paymentForm.method" :options="PAYMENT_METHODS" />
            </a-form-item>
          </a-col>
        </a-row>
        <a-form-item :label="$t('Note')" style="margin-bottom: 0">
          <a-input v-model:value="paymentForm.notes" allow-clear />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Hospital billing.
 *
 * Totals shown while editing are a PREVIEW — the server recomputes every line
 * and the invoice total on save, and derives the status from the payments that
 * actually exist. Overpayment is refused rather than absorbed, so the drawer's
 * amount is capped at what is outstanding.
 *
 * Arriving with ?draft=visit&id=… (from a consultation, admission or lab order)
 * opens the form pre-filled with lines priced from that episode of care.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message, Empty } from 'ant-design-vue';
import {
  PlusOutlined, EditOutlined, DeleteOutlined, EyeOutlined, DollarOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import PatientPicker from './PatientPicker.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { INVOICE_STATUSES, INVOICE_ITEM_TYPES, PAYMENT_METHODS, labelOf, optionOf } from './hospitalOptions';
import http from '../../lib/http';
import { t } from '../../i18n';

const route = useRoute();
const { money, date, roundMoney } = useFormat();
const simpleEmptyImage = Empty.PRESENTED_IMAGE_SIMPLE;

const filters = reactive({
  status: undefined, range: null, outstanding: route.query.outstanding === '1',
});

const crud = useCrudTable('hospital/invoices', {
  rowsKey: 'invoices',
  sortField: 'invoice_date',
  params: () => ({
    status: filters.status || '',
    outstanding: filters.outstanding ? 1 : '',
    start_date: filters.range?.[0] || '',
    end_date: filters.range?.[1] || '',
  }),
});

const totals = computed(() => crud.payload.value?.totals || {});

const columns = computed(() => [
  { title: 'Patient', key: 'patient', dataIndex: 'patient_name' },
  { title: 'Date', key: 'invoice_date', dataIndex: 'invoice_date', sorter: true, width: 130 },
  { title: 'Total', key: 'total', dataIndex: 'total', sorter: true, width: 130 },
  { title: 'Paid', key: 'paid', dataIndex: 'paid', sorter: true, width: 130 },
  { title: 'Due', key: 'due', dataIndex: 'due', width: 130 },
  { title: 'Status', key: 'status', dataIndex: 'status', sorter: true, width: 140 },
  { title: '', key: 'actions', width: 150 },
]);

const lineColumns = [
  { title: 'Type', key: 'type', dataIndex: 'type', width: 130 },
  { title: 'Description', dataIndex: 'description', key: 'description' },
  { title: 'Qty', dataIndex: 'quantity', key: 'quantity', width: 70 },
  { title: 'Price', key: 'unit_price', dataIndex: 'unit_price', width: 110 },
  { title: 'Total', key: 'total', dataIndex: 'total', width: 120 },
];

// ---------------- invoice form ----------------

const formRef = ref();
const formOpen = ref(false);
const saving = ref(false);
const editing = ref(null);
const editingPatient = ref(null);
const form = ref(empty());

function empty() {
  return {
    patient_id: undefined, visit_id: null, admission_id: null, lab_order_id: null,
    invoice_date: new Date().toISOString().slice(0, 10), due_date: null,
    items: [], discount: null, tax: null, status: 'unpaid', notes: '',
  };
}

const rules = computed(() => ({
  patient_id: [{ required: true, message: t('Field_is_required', 'This field is required') }],
}));

function lineTotal(line) {
  return (Number(line.quantity) || 0) * (Number(line.unit_price) || 0);
}
const subtotal = computed(() => form.value.items.reduce((sum, l) => sum + lineTotal(l), 0));
const grandTotal = computed(() =>
  Math.max(0, subtotal.value - (Number(form.value.discount) || 0) + (Number(form.value.tax) || 0)));

function addLine() {
  form.value.items.push({ type: 'other', description: '', quantity: 1, unit_price: 0, product_id: null });
}

async function openForm(record) {
  editing.value = record;
  editingPatient.value = record
    ? { id: record.patient_id, name: record.patient_name, mrn: record.patient_mrn }
    : null;

  if (record) {
    try {
      const data = await http.get(`hospital/invoices/${record.id}`);
      const i = data?.invoice;
      form.value = {
        patient_id: i.patient_id,
        visit_id: i.visit_id, admission_id: i.admission_id, lab_order_id: i.lab_order_id,
        invoice_date: i.invoice_date,
        due_date: i.due_date,
        items: (i.items || []).map(x => ({ ...x })),
        discount: i.discount,
        tax: i.tax,
        status: i.status,
        notes: i.notes || '',
      };
    } catch (e) {
      message.error(t('InvalidData', 'Could not load this invoice'));
      return;
    }
  } else {
    form.value = empty();
  }

  formOpen.value = true;
  formRef.value?.clearValidate?.();
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }
  if (!form.value.items.length) {
    message.warning('Add at least one line.');
    return;
  }

  saving.value = true;
  try {
    if (editing.value) await http.put(`hospital/invoices/${editing.value.id}`, form.value);
    else await http.post('hospital/invoices', form.value);
    message.success(t('Created_in_successfully', 'Saved successfully'));
    formOpen.value = false;
    editing.value = null;
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData', 'Could not save this invoice'));
  } finally {
    saving.value = false;
  }
}

// ---------------- detail ----------------

const detailOpen = ref(false);
const detail = ref(null);

async function openDetail(record) {
  try {
    const data = await http.get(`hospital/invoices/${record.id}`);
    detail.value = data?.invoice || null;
    detailOpen.value = true;
  } catch (e) {
    message.error(t('InvalidData', 'Could not load this invoice'));
  }
}

// ---------------- payment ----------------

const paymentOpen = ref(false);
const paying = ref(false);
const payTarget = ref(null);
const paymentForm = reactive({ amount: null, paid_on: null, method: 'cash', notes: '' });

function openPayment(record) {
  payTarget.value = record;
  Object.assign(paymentForm, {
    amount: roundMoney(record.due),
    paid_on: new Date().toISOString().slice(0, 10),
    method: 'cash',
    notes: '',
  });
  paymentOpen.value = true;
}

async function submitPayment() {
  paying.value = true;
  try {
    await http.post(`hospital/invoices/${payTarget.value.id}/payments`, { ...paymentForm });
    message.success('Payment recorded.');
    paymentOpen.value = false;
    crud.fetchRows();
    if (detailOpen.value) openDetail(payTarget.value);
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData', 'Could not record this payment'));
  } finally {
    paying.value = false;
  }
}

async function removePayment(payment) {
  try {
    await http.delete(`hospital/invoices/${detail.value.id}/payments/${payment.id}`);
    message.success(t('Deleted_in_successfully', 'Deleted successfully'));
    openDetail(detail.value);
    crud.fetchRows();
  } catch (e) {
    message.error(t('InvalidData', 'Could not remove this payment'));
  }
}

// ---------------- draft from an episode of care ----------------

async function loadDraft() {
  const source = route.query.draft;
  const id = route.query.id;
  if (!source || !id) return;

  try {
    const data = await http.post('hospital/invoices/draft-from', { source, id: Number(id) });
    const draft = data?.draft;
    if (!draft) return;

    form.value = { ...empty(), ...draft, items: draft.items || [] };
    editing.value = null;
    // The picker needs a label for the pre-selected patient.
    const patient = await http.get(`hospital/patients/${draft.patient_id}`).catch(() => null);
    editingPatient.value = patient?.patient
      ? { id: draft.patient_id, name: patient.patient.name, mrn: patient.patient.mrn }
      : { id: draft.patient_id, name: 'Patient' };
    formOpen.value = true;
  } catch (e) {
    message.error(e?.data?.message || 'Could not draft this invoice.');
  }
}

onMounted(() => {
  crud.fetchRows();
  loadDraft();
});
</script>

<style scoped>
.danger {
  color: #ff4d4f;
}
.ok {
  color: #16a34a;
}
.summary {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 12px;
  margin-bottom: 16px;
}
.sum {
  display: flex;
  flex-direction: column;
  padding: 12px 16px;
  border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 12px;
}
.sum--danger {
  border-color: rgba(255, 77, 79, 0.4);
  background: rgba(255, 77, 79, 0.05);
}
.sum-label {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  opacity: 0.55;
}
.sum-value {
  font-size: 19px;
  font-weight: 600;
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}
.tb-search {
  flex: 1 1 220px;
  min-width: 180px;
}
.tb-item {
  width: 170px;
}
.tb-range {
  width: 240px;
}
.tb-check {
  white-space: nowrap;
}
.link-cell {
  border: 0;
  background: none;
  padding: 0;
  cursor: pointer;
  color: inherit;
  font: inherit;
  text-align: left;
}
.cell-name {
  font-weight: 500;
}
.link-cell:hover .cell-name {
  color: #6d28d9;
}
.cell-mrn {
  font-size: 11.5px;
  opacity: 0.55;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}
.lines-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
  font-weight: 500;
}
.lines {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.line {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}
.line-type {
  width: 130px;
  flex: none;
}
.line-desc {
  flex: 2 1 180px;
  min-width: 150px;
}
.line-qty {
  width: 80px;
  flex: none;
}
.line-price {
  width: 110px;
  flex: none;
}
.line-total {
  width: 110px;
  flex: none;
  text-align: right;
  font-weight: 600;
  font-size: 13px;
}
.totals-box {
  margin-top: 12px;
  padding: 12px 14px;
  border-radius: 12px;
  background: rgba(128, 128, 128, 0.08);
  font-size: 13px;
}
.totals-box > div {
  display: flex;
  justify-content: space-between;
  padding: 2px 0;
}
.totals-box .grand {
  margin-top: 6px;
  padding-top: 6px;
  border-top: 1px solid rgba(128, 128, 128, 0.2);
  font-size: 15px;
}
.target {
  margin: 0 0 14px;
  padding: 10px 12px;
  border-radius: 10px;
  background: rgba(128, 128, 128, 0.1);
  font-size: 13px;
}
</style>
