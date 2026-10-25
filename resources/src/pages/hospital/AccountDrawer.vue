<template>
  <!--
    Administrative file and account of a stay (user's analysis, 2026-10-25,
    phase 2): 1 origin + admission order, 2 coverage, 3 consents, 4 deposit,
    5 account (fee for service / DRG, insurer / patient split, close -> invoice,
    payment plan). Each step shows a check when it is done.
  -->
  <a-drawer :open="open" :width="760" :title="title" @close="$emit('close')">
    <a-spin :spinning="loading">
      <a-form v-if="d" layout="vertical">
        <div class="who">
          <b>{{ a.patient_name }}</b> <span class="muted">{{ a.patient_mrn }} · {{ a.ward }}{{ a.bed ? ' / ' + a.bed : '' }} · {{ s('{n} nights', { n: a.nights }) }}</span>
        </div>

        <!-- 1. Origin and admission order -->
        <section class="st" :class="{ done: !!a.origin }">
          <h3><span class="n">{{ a.origin ? '✓' : '1' }}</span>{{ s('Origin and admission order') }}</h3>
          <a-row :gutter="10">
            <a-col :span="8"><a-form-item :label="s('Origin')"><a-select v-model:value="f.origin" :options="originOptions" allow-clear :disabled="closed && false" /></a-form-item></a-col>
            <a-col :span="8"><a-form-item :label="s('Ordered by')"><a-select v-model:value="f.ordered_by" :options="doctorOptions" allow-clear show-search option-filter-prop="label" /></a-form-item></a-col>
            <a-col :span="8"><a-form-item :label="s('Order time')"><a-date-picker v-model:value="f.ordered_at" show-time format="DD/MM/YYYY HH:mm" value-format="YYYY-MM-DD HH:mm" style="width: 100%" /></a-form-item></a-col>
          </a-row>
          <div v-if="a.wait_minutes != null" class="muted">{{ s('From the order to the bed: {t}', { t: mins(a.wait_minutes) }) }}</div>
        </section>

        <!-- 2. Coverage -->
        <section class="st" :class="{ done: coverageDone }">
          <h3><span class="n">{{ coverageDone ? '✓' : '2' }}</span>{{ s('Coverage') }}</h3>
          <a-radio-group v-model:value="f.payer_type" button-style="solid" :disabled="closed" style="margin-bottom: 10px">
            <a-radio-button value="self">{{ s('Self-pay') }}</a-radio-button>
            <a-radio-button value="private">{{ s('Private insurer') }}</a-radio-button>
            <a-radio-button value="public">{{ s('Public coverage') }}</a-radio-button>
          </a-radio-group>
          <a-row v-if="f.payer_type !== 'self'" :gutter="10">
            <a-col :span="8"><a-form-item :label="s('Insurer / plan')"><a-input v-model:value="f.insurer" :disabled="closed" /></a-form-item></a-col>
            <a-col :span="8"><a-form-item :label="s('Policy number')"><a-input v-model:value="f.policy_number" :disabled="closed" /></a-form-item></a-col>
            <a-col :span="8"><a-form-item :label="s('Authorization')"><a-select v-model:value="f.authorization_status" :options="authOptions" :disabled="closed" /></a-form-item></a-col>
            <a-col :span="8"><a-form-item :label="s('Authorization number')"><a-input v-model:value="f.authorization_no" :disabled="closed" /></a-form-item></a-col>
            <a-col :span="5"><a-form-item :label="s('Coverage %')"><a-input-number v-model:value="f.coverage_percent" :min="0" :max="100" style="width: 100%" :disabled="closed" /></a-form-item></a-col>
            <a-col :span="5"><a-form-item :label="s('Copay')"><a-input-number v-model:value="f.copay" :min="0" style="width: 100%" :disabled="closed" /></a-form-item></a-col>
            <a-col :span="6"><a-form-item :label="s('Deductible')"><a-input-number v-model:value="f.deductible" :min="0" style="width: 100%" :disabled="closed" /></a-form-item></a-col>
          </a-row>
          <a-button type="primary" :loading="saving" @click="save">{{ s('Save') }}</a-button>
        </section>

        <!-- 3. Consents -->
        <section class="st" :class="{ done: consentsDone }">
          <h3><span class="n">{{ consentsDone ? '✓' : '3' }}</span>{{ s('Signed consents') }}</h3>
          <div v-for="(label, key) in d.consent_types" :key="key" class="consent">
            <span class="c-ok">{{ a.consents[key] ? '✅' : '⬜' }}</span>
            <span class="c-l">{{ s(label) }}</span>
            <template v-if="a.consents[key]">
              <span class="muted">{{ a.consents[key].signed_by }}{{ a.consents[key].relation ? ' (' + a.consents[key].relation + ')' : '' }} · {{ dt(a.consents[key].at) }}</span>
              <a-button type="link" size="small" danger @click="unsign(key)">{{ s('Undo') }}</a-button>
            </template>
            <a-button v-else size="small" @click="openSign(key)">{{ s('Mark signed') }}</a-button>
          </div>
        </section>

        <!-- 4. Deposit -->
        <section class="st" :class="{ done: a.deposit_amount > 0 || f.payer_type !== 'self' }">
          <h3><span class="n">{{ a.deposit_amount > 0 || f.payer_type !== 'self' ? '✓' : '4' }}</span>{{ s('Guarantee deposit') }}</h3>
          <div v-if="a.deposit_amount > 0" class="muted" style="margin-bottom: 6px">{{ s('Taken: {amount} ({method}) · {when}', { amount: money(a.deposit_amount), method: a.deposit_method, when: dt(a.deposit_at) }) }}</div>
          <a-space v-if="!closed" wrap>
            <a-input-number v-model:value="dep.amount" :min="0" :placeholder="s('Amount')" style="width: 140px" />
            <a-select v-model:value="dep.method" :options="methodOptions" style="width: 160px" />
            <a-button :loading="saving" @click="saveDeposit">{{ a.deposit_amount > 0 ? s('Change deposit') : s('Take deposit') }}</a-button>
          </a-space>
          <div class="muted small">{{ s('It is applied to the patient\'s share when the account is closed.') }}</div>
        </section>

        <!-- 5. Account -->
        <section class="st" :class="{ done: closed }">
          <h3><span class="n">{{ closed ? '✓' : '5' }}</span>{{ s('Account') }}</h3>
          <template v-if="!closed">
            <a-space wrap style="margin-bottom: 10px">
              <a-radio-group v-model:value="f.billing_mode" button-style="solid">
                <a-radio-button value="fee">{{ s('Fee for service') }}</a-radio-button>
                <a-radio-button value="drg" :disabled="!d.drg.length">{{ s('DRG fixed rate') }}</a-radio-button>
              </a-radio-group>
              <a-select v-if="f.billing_mode === 'drg'" v-model:value="f.drg_id" :options="drgOptions" style="width: 300px" show-search option-filter-prop="label" :placeholder="s('Pick the DRG rate')" />
              <a-button v-if="f.billing_mode !== a.billing_mode || f.drg_id !== a.drg_id" size="small" @click="save">{{ s('Apply') }}</a-button>
            </a-space>
            <a-table :data-source="d.estimate.lines" :columns="lineColumns" size="small" :pagination="false" row-key="description">
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'unit_price'">{{ money(record.unit_price) }}</template>
                <template v-else-if="column.key === 'total'">{{ money(record.total) }}</template>
              </template>
            </a-table>
            <div class="split">
              <div><span>{{ s('Total') }}</span><b>{{ money(d.estimate.total) }}</b></div>
              <div v-if="d.estimate.insurer_amount > 0"><span>{{ s('Insurer pays') }}</span><b>{{ money(d.estimate.insurer_amount) }}</b></div>
              <div><span>{{ s('Patient pays') }}</span><b>{{ money(d.estimate.patient_amount) }}</b></div>
              <div v-if="d.estimate.deposit > 0"><span>{{ s('Patient still owes after the deposit') }}</span><b>{{ money(d.estimate.patient_after_deposit) }}</b></div>
            </div>
            <a-button type="primary" :loading="saving" :disabled="!d.estimate.lines.length" @click="close">{{ s('Close the account and bill') }}</a-button>
          </template>
          <template v-else>
            <div class="split">
              <div><span>{{ s('Invoice') }}</span><b><PermLink to="/hospital/invoices" permission="hms_billing">{{ d.invoice.reference }}</PermLink></b></div>
              <div><span>{{ s('Total') }}</span><b>{{ money(d.invoice.total) }}</b></div>
              <div v-if="d.invoice.insurer_amount > 0"><span>{{ s('Insurer owes') }}</span><b>{{ money(d.invoice.insurer_due) }} / {{ money(d.invoice.insurer_amount) }}</b></div>
              <div><span>{{ s('Patient owes') }}</span><b>{{ money(d.invoice.patient_due) }} / {{ money(d.invoice.patient_amount || d.invoice.total) }}</b></div>
            </div>
            <div class="h4">{{ s('Payment plan (patient)') }}</div>
            <a-space wrap>
              <a-input-number v-model:value="plan.installments" :min="0" :max="36" style="width: 110px" :addon-after="s('payments')" />
              <a-date-picker v-model:value="plan.first_due" value-format="YYYY-MM-DD" :placeholder="s('First payment')" />
              <a-select v-model:value="plan.every_days" :options="[{ value: 7, label: s('weekly') }, { value: 15, label: s('every 15 days') }, { value: 30, label: s('monthly') }]" style="width: 150px" />
              <a-button :loading="saving" @click="savePlan">{{ s('Set plan') }}</a-button>
            </a-space>
            <a-table v-if="d.invoice.payment_plan.length" :data-source="d.invoice.payment_plan" :columns="planColumns" size="small" :pagination="false" row-key="n" style="margin-top: 8px">
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'amount'">{{ money(record.amount) }}</template>
                <template v-else-if="column.key === 'due'">{{ date(record.due) }}</template>
              </template>
            </a-table>
          </template>
        </section>
      </a-form>
    </a-spin>

    <a-modal :open="signOpen" :title="s(d?.consent_types?.[signType] || '')" :ok-text="s('Signed')" :cancel-text="s('Cancel')" @ok="sign" @cancel="signOpen = false">
      <a-form layout="vertical">
        <a-form-item :label="s('Signed by')"><a-input v-model:value="signer.name" /></a-form-item>
        <a-form-item :label="s('Relationship (guardian)')"><a-input v-model:value="signer.relation" :placeholder="s('Patient')" /></a-form-item>
      </a-form>
    </a-modal>
  </a-drawer>
</template>

<script setup>
import { ref, reactive, computed, watch } from 'vue';
import dayjs from 'dayjs';
import { message, Modal } from 'ant-design-vue';
import http from '../../lib/http';
import { sortByLabel } from '../../lib/filterOptions';
import { useFormat } from '../../composables/useFormat';
import { useHospitalText } from './lang/useHospitalText';
import ES from './lang/AccountDrawer.es.js';

const props = defineProps({ open: Boolean, admissionId: { type: Number, default: null } });
const emit = defineEmits(['close', 'changed']);
const { s } = useHospitalText(ES);
const { money } = useFormat();

const loading = ref(false);
const saving = ref(false);
const d = ref(null);
const a = computed(() => d.value?.admission || {});
const closed = computed(() => !!d.value?.invoice);
const title = computed(() => (d.value ? `${s('Administrative file and account')} · ${a.value.reference}` : ''));
const f = reactive({});
const dep = reactive({ amount: null, method: 'cash' });
const plan = reactive({ installments: 3, first_due: null, every_days: 30 });

const ORIGINS = { er: 'Emergency room', elective: 'Elective surgery', outpatient: 'Outpatient consultation', referral: 'Referral', transfer: 'Transfer from another hospital', other: 'Other' };
const originOptions = computed(() => Object.entries(ORIGINS).map(([v, l]) => ({ value: v, label: s(l) })));
const authOptions = computed(() => [['not_needed', 'Not needed'], ['pending', 'Pending'], ['approved', 'Approved'], ['denied', 'Denied']].map(([v, l]) => ({ value: v, label: s(l) })));
const methodOptions = computed(() => [['cash', 'Cash'], ['card', 'Card'], ['bank_transfer', 'Transfer'], ['cheque', 'Cheque']].map(([v, l]) => ({ value: v, label: s(l) })));
const doctorOptions = computed(() => sortByLabel((d.value?.doctors || []).map(x => ({ value: x.id, label: x.name }))));
const drgOptions = computed(() => sortByLabel((d.value?.drg || []).map(x => ({ value: x.id, label: `${x.code} — ${x.name} · ${money(x.price)}` }))));
const coverageDone = computed(() => f.payer_type === 'self' || (f.insurer && ['approved', 'not_needed'].includes(f.authorization_status)));
const consentsDone = computed(() => d.value && Object.keys(d.value.consent_types).every(k => a.value.consents?.[k]));
const lineColumns = computed(() => [
  { title: s('Concept'), dataIndex: 'description' }, { title: s('Qty'), dataIndex: 'quantity', width: 70 },
  { title: s('Price'), key: 'unit_price', width: 110, align: 'right' }, { title: s('Total'), key: 'total', width: 110, align: 'right' },
]);
const planColumns = computed(() => [{ title: '#', dataIndex: 'n', width: 50 }, { title: s('Due date'), key: 'due' }, { title: s('Amount'), key: 'amount', align: 'right' }]);
const mins = m => (m >= 60 ? `${Math.floor(m / 60)} h ${m % 60} min` : `${m} min`);
const dt = iso => (iso ? dayjs(iso).format('DD/MM/YYYY HH:mm') : '');
const date = v => (v ? dayjs(v).format('DD/MM/YYYY') : '');
const err = e => message.error(s(e?.data?.message || (e?.data?.errors && Object.values(e.data.errors)[0]?.[0]) || 'Could not save'));

function take(data) {
  d.value = data;
  const x = data.admission;
  Object.assign(f, {
    origin: x.origin, ordered_by: x.ordered_by, ordered_at: x.ordered_at, payer_type: x.payer_type || 'self', insurer: x.insurer,
    policy_number: x.policy_number, authorization_no: x.authorization_no, authorization_status: x.authorization_status || (x.payer_type === 'self' ? 'not_needed' : 'pending'),
    coverage_percent: x.coverage_percent, copay: x.copay, deductible: x.deductible, billing_mode: x.billing_mode || 'fee', drg_id: x.drg_id,
  });
  dep.amount = x.deposit_amount || null;
  dep.method = x.deposit_method || 'cash';
}
async function load() {
  if (!props.admissionId) return;
  loading.value = true;
  try { take(await http.get(`hospital/admissions/${props.admissionId}/account`)); } catch (e) { err(e); } finally { loading.value = false; }
}
watch(() => [props.open, props.admissionId], ([o]) => { if (o) load(); }, { immediate: true });

async function save() {
  saving.value = true;
  try {
    take(await http.put(`hospital/admissions/${props.admissionId}/account`, { ...f, drg_id: f.billing_mode === 'drg' ? f.drg_id : null }));
    message.success(s('Saved'));
    emit('changed');
  } catch (e) { err(e); } finally { saving.value = false; }
}
async function saveDeposit() {
  saving.value = true;
  try { take(await http.post(`hospital/admissions/${props.admissionId}/deposit`, { amount: dep.amount || 0, method: dep.method })); message.success(s('Saved')); } catch (e) { err(e); } finally { saving.value = false; }
}

const signOpen = ref(false);
const signType = ref(null);
const signer = reactive({ name: '', relation: '' });
function openSign(key) { signType.value = key; signer.name = a.value.patient_name || ''; signer.relation = ''; signOpen.value = true; }
async function sign() {
  if (!signer.name.trim()) return message.warning(s('Type who signed.'));
  try { take(await http.post(`hospital/admissions/${props.admissionId}/consents`, { type: signType.value, signed_by: signer.name.trim(), relation: signer.relation })); signOpen.value = false; } catch (e) { err(e); }
}
async function unsign(key) {
  try { take(await http.delete(`hospital/admissions/${props.admissionId}/consents/${key}`)); } catch (e) { err(e); }
}

function close() {
  Modal.confirm({
    title: s('Close the account of this stay?'),
    content: s('An invoice (a sale) is made with every line above; the deposit is applied as the patient\'s first payment. Charges added later go on a new invoice.'),
    okText: s('Close and bill'), cancelText: s('Cancel'),
    async onOk() {
      try {
        const r = await http.post(`hospital/admissions/${props.admissionId}/close-account`);
        take(r);
        message.success(s('Invoice {ref} created', { ref: r.reference }));
        emit('changed');
      } catch (e) { err(e); }
    },
  });
}
async function savePlan() {
  saving.value = true;
  try {
    const r = await http.post(`hospital/invoices/${d.value.invoice.id}/plan`, { installments: plan.installments, first_due: plan.first_due, every_days: plan.every_days });
    d.value.invoice.payment_plan = r.payment_plan;
    message.success(s('Saved'));
  } catch (e) { err(e); } finally { saving.value = false; }
}
</script>

<style scoped>
.who { margin-bottom: 12px; font-size: 15px; }
.muted { opacity: 0.65; font-size: 12.5px; }
.small { font-size: 12px; margin-top: 4px; }
.st { border: 1.5px solid rgba(128, 128, 128, 0.22); border-radius: 12px; padding: 12px 14px 6px; margin-bottom: 12px; }
.st.done { border-color: rgba(22, 163, 74, 0.55); }
.st h3 { display: flex; align-items: center; gap: 8px; font-size: 15px; margin: 0 0 10px; }
.n { width: 24px; height: 24px; border-radius: 50%; background: #1677ff; color: #fff; display: inline-flex; align-items: center; justify-content: center; font-size: 12.5px; }
.st.done .n { background: #16a34a; }
.consent { display: flex; align-items: center; gap: 8px; padding: 5px 0; border-bottom: 1px dashed rgba(128, 128, 128, 0.2); flex-wrap: wrap; }
.c-l { font-weight: 500; min-width: 190px; }
.split { margin: 10px 0; display: grid; gap: 4px; max-width: 420px; }
.split div { display: flex; justify-content: space-between; gap: 10px; }
.h4 { font-weight: 600; margin: 12px 0 6px; }
</style>
