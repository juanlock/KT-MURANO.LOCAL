<template>
  <div class="page">
    <PageHeader
      :title="isEdit ? $t('Edit') : $t('Create_Expense')"
      :breadcrumb="[$t('Accounting'), isEdit ? $t('Edit') : $t('Create_Expense')]"
    />

    <div v-if="loadingRecord" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <template v-else>
      <!-- Help for first-timers: pick a common case, the form fills itself in
           and explains each field and whether it repeats. -->
      <a-card v-if="!isEdit" size="small" class="ef-help">
        <div class="ef-help__title">
          <BulbOutlined /> Not sure how to fill it in? Pick an example:
        </div>
        <a-space wrap :size="[8, 8]">
          <a-button
            v-for="ex in EXAMPLES" :key="ex.key" size="small"
            :type="example?.key === ex.key ? 'primary' : 'default'" @click="applyExample(ex)"
          >{{ ex.title }}</a-button>
        </a-space>

        <a-alert v-if="example" type="info" show-icon class="ef-help__guide">
          <template #message><b>{{ example.title }}</b> — {{ example.summary }}</template>
          <template #description>
            <ul class="ef-steps">
              <li><b>Category:</b> {{ example.category }}<span v-if="!exampleCategoryFound" class="ef-warn"> (not in your categories — pick the closest one)</span></li>
              <li><b>Amount:</b> {{ example.amount }}</li>
              <li><b>Details:</b> {{ example.details(monthLabel) }}</li>
              <li><b>Payment method / Account:</b> {{ example.pay }}</li>
              <li><b>How often:</b> {{ example.howOften }}</li>
            </ul>
            <div class="ef-muted">We filled the category, details and how often for you — check the date and type the amount.</div>
          </template>
        </a-alert>
      </a-card>

      <!-- Created from a bill in Email Office (?email=ID): saved linked to that email. -->
      <!-- Pick the bill's values straight from the email text. -->
      <a-card v-if="fromEmail" size="small" class="ef-pick">
        <div class="ef-help__title">
          <MailOutlined /> From the email “{{ fromEmail.subject }}” — {{ fromEmail.from_name || fromEmail.from_email }}
        </div>
        <div class="ef-muted" style="margin-bottom: 8px">Pick what's in this bill — click a value to use it (the likely ones are already picked). The email is marked as recorded when you save.</div>

        <div v-for="row in pickRows" :key="row.key" class="ef-pick__row">
          <span class="ef-pick__label">{{ row.label }}</span>
          <template v-if="row.items.length">
            <a-tag
              v-for="it in row.items" :key="it.value" class="ef-pick__chip"
              :color="picked[row.key] === it.value ? 'purple' : undefined" @click="pick(row.key, it.value)"
            >
              <b>{{ it.display }}</b><span v-if="it.context" class="ef-pick__ctx"> · {{ it.context }}</span>
            </a-tag>
            <a v-if="picked[row.key]" class="ef-muted" @click="pick(row.key, null)">clear</a>
          </template>
          <span v-else class="ef-muted">none found</span>
        </div>

        <a-collapse ghost size="small" style="margin-top: 4px">
          <a-collapse-panel key="text" header="Read the email">
            <pre class="ef-pick__text">{{ fromEmail.body_text || '(empty message)' }}</pre>
          </a-collapse-panel>
        </a-collapse>
      </a-card>

      <a-alert
        v-if="isEdit && form.recurring_expense_id" type="info" show-icon style="margin-bottom: 16px"
        message="This expense was created automatically by a recurring expense."
        description="Changes here affect only this one. To change or stop the next ones, open Expenses → Recurring."
      />

      <a-card>
        <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
          <a-row :gutter="16">
            <a-col :xs="24" :md="12">
              <a-form-item :label="$t('date') + ' *'" name="date" :extra="repeatOn ? 'The date of the first payment; the next ones follow from it.' : 'The day you paid, or the date on the bill.'">
                <a-input v-model:value="form.date" type="date" />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="12">
              <a-form-item :label="$t('warehouse') + ' *'" name="warehouse_id" extra="The location (branch) this cost belongs to.">
                <a-select
                  v-model:value="form.warehouse_id" :placeholder="$t('Choose_Warehouse')"
                  :options="sortByLabel(warehouses.map(w => ({ label: w.name, value: w.id })))"
                  show-search option-filter-prop="label"
                />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="12">
              <a-form-item :label="$t('Expense_Category') + ' *'" name="category_id" :extra="categoryHint || 'What the money was for. ★ = the most used in its group.'">
                <a-select
                  v-model:value="form.category_id" :placeholder="$t('Choose_Category')"
                  :options="categoryOptions"
                  show-search option-filter-prop="label"
                />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="12">
              <a-form-item :label="$t('Amount') + ' *'" name="amount" extra="The total you paid, numbers only — e.g. 1250.50">
                <a-input v-model:value="form.amount" :placeholder="$t('Amount')" />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="12">
              <a-form-item :label="$t('Paymentchoice') + ' *'" name="payment_method_id" extra="How you paid: cash, card, bank transfer…">
                <a-select
                  v-model:value="form.payment_method_id" :placeholder="$t('PleaseSelect')"
                  :options="sortByLabel(paymentMethods.map(p => ({ label: p.name, value: p.id })))"
                  show-search option-filter-prop="label"
                />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="12">
              <a-form-item :label="$t('Account')" name="account_id" extra="Optional: the bank or cash account the money left from (its balance goes down).">
                <a-select
                  v-model:value="form.account_id" :placeholder="$t('Choose_Account')"
                  :options="sortByLabel(accounts.map(a => ({ label: a.account_name, value: a.id })))"
                  show-search option-filter-prop="label" allow-clear
                />
              </a-form-item>
            </a-col>
            <a-col :span="24">
              <a-form-item :label="$t('Details') + ' *'" name="details" extra="A short note to recognize it later: who you paid, what for, the invoice number.">
                <a-textarea v-model:value="form.details" :rows="3" :placeholder="example ? example.details(monthLabel) : $t('Afewwords')" />
              </a-form-item>
            </a-col>

            <!-- How often: one-time, or the same amount every period (then
                 the next expenses are created automatically). -->
            <a-col v-if="!isEdit" :span="24">
              <a-form-item label="How often?" class="ef-often">
                <a-radio-group v-model:value="repeat.mode">
                  <a-radio value="once">Only this time</a-radio>
                  <a-radio value="fixed">Same amount every…</a-radio>
                </a-radio-group>
                <a-space v-if="repeatOn" wrap style="margin-top: 8px">
                  <a-select v-model:value="repeat.frequency" style="width: 150px" :options="FREQ_OPTIONS" />
                  <span class="ef-muted">until</span>
                  <a-input v-model:value="repeat.end_date" type="date" style="width: 170px" :min="form.date" />
                  <span class="ef-muted">(empty = no end)</span>
                </a-space>
                <div class="ef-muted" style="margin-top: 6px">
                  <template v-if="repeatOn">
                    The system creates the next one by itself on <b>{{ nextDate || '…' }}</b>, then every {{ FREQ_WORD[repeat.frequency] }} — you'll find them under Expenses → Recurring (pause or stop anytime).
                  </template>
                  <template v-else>
                    Use “Same amount every…” for bills that never change (rent, internet, a software plan, a fixed insurance).
                    If the amount changes each time (electricity, fuel) or it happens once (a repair, a lawyer's case), keep “Only this time” and enter each bill.
                  </template>
                </div>
              </a-form-item>
            </a-col>
          </a-row>

          <a-space style="margin-top: 8px">
            <a-button type="primary" :loading="submitting" @click="submit">{{ $t('submit') }}</a-button>
            <a-button @click="$router.push('/expenses')">{{ $t('Cancel') }}</a-button>
          </a-space>
        </a-form>
      </a-card>
    </template>
  </div>
</template>

<script setup>
/**
 * Expense create/edit — bootstrap GET expenses/create → {Expenses_category,
 * warehouses, accounts, payment_methods}; edit GET expenses/{id}/edit →
 * {expense, expense_Category (lowercase e!), warehouses, accounts,
 * payment_methods}. Save POST/PUT expenses with the whole object nested
 * under {expense}. Legacy validation: date/warehouse/payment
 * method/category/details required; amount required + ^\d*\.?\d*$;
 * account optional.
 *
 * Beginner help: EXAMPLES (rent, fuel, car repair, lawyer...) fill the
 * category, details and how often, and explain every field. "How often?"
 * = Same amount every week/month/quarter/year saves a recurring expense
 * (expense.repeat {frequency, end_date}); the server creates the next ones
 * on their dates (App\Services\RecurringExpenses).
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import dayjs from 'dayjs';
import { BulbOutlined, MailOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { sortByLabel } from '../../lib/filterOptions';
import { localName } from '../../lib/localName';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();

const id = computed(() => route.params.id);
const isEdit = computed(() => !!id.value);

const loadingRecord = ref(true);
const submitting = ref(false);
const formRef = ref();

const warehouses = ref([]);
const categories = ref([]);
// The company's categories grouped by expense group (both alphabetical, in
// the user's language); ★ = the most commonly used one of its group.
const categoryOptions = computed(() => {
  const groups = new Map();
  categories.value.forEach(c => {
    const g = localName(c, 'expense_group') || '—';
    if (!groups.has(g)) groups.set(g, []);
    groups.get(g).push({ value: c.id, label: `${localName(c)}${c.is_common ? ' ★' : ''}` });
  });
  if (groups.size === 1 && groups.has('—')) return sortByLabel(groups.get('—'));
  return sortByLabel([...groups.entries()].map(([label, options]) => ({ label, options: sortByLabel(options) })));
});
const paymentMethods = ref([]);
const accounts = ref([]);

const form = ref({
  date: new Date().toISOString().slice(0, 10),
  warehouse_id: undefined,
  account_id: undefined,
  category_id: undefined,
  payment_method_id: undefined,
  details: '',
  amount: '',
});

/* ------------------------------------------------ how often */
const FREQ_OPTIONS = [
  { value: 'weekly', label: 'Week' },
  { value: 'monthly', label: 'Month' },
  { value: 'quarterly', label: '3 months' },
  { value: 'yearly', label: 'Year' },
];
const FREQ_WORD = { weekly: 'week', monthly: 'month', quarterly: '3 months', yearly: 'year' };
const repeat = ref({ mode: 'once', frequency: 'monthly', end_date: '' });
const repeatOn = computed(() => repeat.value.mode === 'fixed');
const nextDate = computed(() => {
  if (!form.value.date) return '';
  const d = dayjs(form.value.date);
  const n = { weekly: d.add(1, 'week'), quarterly: d.add(3, 'month'), yearly: d.add(1, 'year') }[repeat.value.frequency] || d.add(1, 'month');
  return n.format('YYYY-MM-DD');
});

/* ------------------------------------------------ category hint */
const selectedCategory = computed(() => categories.value.find(c => c.id === form.value.category_id));
const categoryHint = computed(() => {
  const c = selectedCategory.value;
  if (!c) return '';
  const parts = [];
  if (c.reference) parts.push(c.reference);
  const period = localName(c, 'period');
  if (period) parts.push(`Usually: ${period}.`);
  if (c.approximate_amount) parts.push(`Typical: ${c.approximate_amount}.`);
  return parts.join(' ');
});

/* ------------------------------------------------ examples */
// category: the default category name (App\Support\DefaultAccounting);
// repeat: what "How often?" becomes. details(month) = the sample note.
const EXAMPLES = [
  {
    key: 'rent', title: 'Office rent', category: 'Office rent', repeat: 'monthly',
    summary: 'the same amount every month: set it once and it repeats by itself.',
    amount: 'the rent written in your lease.',
    details: m => `Office rent — ${m}`,
    pay: 'usually Bank transfer, from your bank account.',
    howOften: 'Same amount every month (we set it). Add an end date if the lease ends.',
  },
  {
    key: 'fuel', title: 'Gas / fuel for a car', category: 'Automobile and mileage', repeat: null,
    summary: 'the amount changes every time: enter each receipt.',
    amount: 'the total on the gas station receipt.',
    details: () => 'Fuel — Toyota Hilux ABC-123 (plate)',
    pay: 'the card or cash you paid with.',
    howOften: 'Only this time — one expense per fill-up (or one per week with the total of the receipts).',
  },
  {
    key: 'car_repair', title: 'Car repair', category: 'Automobile and mileage', repeat: null,
    summary: 'a one-time cost for a company vehicle.',
    amount: 'the total of the workshop invoice.',
    details: () => 'Brake repair — Toyota Hilux ABC-123 — Joe\'s Garage, invoice #1234',
    pay: 'how you paid the workshop.',
    howOften: 'Only this time. Tip: after saving, attach the invoice (Documents) in the expenses list.',
  },
  {
    key: 'lawyer', title: 'Lawyer', category: 'Legal services', repeat: null,
    summary: 'usually one bill per case; a fixed monthly retainer can repeat.',
    amount: 'the lawyer\'s invoice total.',
    details: () => 'Contract review — Smith & Co. Law, invoice #456',
    pay: 'usually Bank transfer.',
    howOften: 'Only this time per case. If you pay a fixed retainer every month, choose “Same amount every month”.',
  },
  {
    key: 'electricity', title: 'Electricity / water', category: 'Utilities', repeat: null,
    summary: 'arrives every month but the amount changes: enter each bill.',
    amount: 'the amount due on this month\'s bill.',
    details: m => `Electricity — ${m}`,
    pay: 'how you paid the bill.',
    howOften: 'Only this time — enter each month\'s bill when it arrives (the amount changes).',
  },
  {
    key: 'internet', title: 'Internet / phone', category: 'Internet and telecommunications', repeat: 'monthly',
    summary: 'a fixed monthly plan: set it once.',
    amount: 'your plan\'s monthly price.',
    details: m => `Internet plan — ${m}`,
    pay: 'usually card or automatic bank debit.',
    howOften: 'Same amount every month (we set it).',
  },
  {
    key: 'software', title: 'Software subscription', category: 'Software subscriptions', repeat: 'monthly',
    summary: 'a fixed plan, monthly or yearly.',
    amount: 'the plan price (per month, or per year if billed yearly).',
    details: () => 'Microsoft 365 — 5 users',
    pay: 'usually the company card.',
    howOften: 'Same amount every month — change it to Year if you pay once a year.',
  },
  {
    key: 'insurance', title: 'Insurance', category: 'Insurance', repeat: 'monthly',
    summary: 'a fixed premium: set it once.',
    amount: 'the premium on your policy.',
    details: () => 'Business liability insurance — policy #789',
    pay: 'usually bank transfer or automatic debit.',
    howOften: 'Same amount every month — or every Year / 3 months, as your policy says.',
  },
  {
    key: 'accountant', title: 'Accountant', category: 'Accounting services', repeat: 'monthly',
    summary: 'a fixed monthly fee: set it once.',
    amount: 'the accountant\'s monthly fee.',
    details: m => `Bookkeeping — ${m}`,
    pay: 'usually bank transfer.',
    howOften: 'Same amount every month. Extra work (a tax return) goes as a separate one-time expense.',
  },
  {
    key: 'supplies', title: 'Office supplies', category: 'Office supplies', repeat: null,
    summary: 'small purchases that change every time.',
    amount: 'the receipt total.',
    details: () => 'Printer paper and toner — Office Depot',
    pay: 'the card or cash you paid with.',
    howOften: 'Only this time — one expense per receipt.',
  },
];
const example = ref(null);

/* ------------------------------------------------ from an email (Email Office) */
const fromEmail = ref(null);

/*
 * The bill's values found in the email text, each with the words before it
 * ("Total due", "Due date"...), for the user to pick: amounts, dates (bill
 * date / due date), account and invoice numbers. Likely ones are pre-picked.
 */
const MONTHS = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];
// The words just before a value ("Payment due", "Bill total"): the last line / sentence, without its colon.
const ctxOf = (s, i) => {
  const before = s.slice(Math.max(0, i - 60), i).split(/\r?\n/).pop();
  return before.replace(/^.*[.!?|]\s/, '').replace(/[\s:#-]+$/, '').replace(/\s+/g, ' ').trim().slice(-32);
};
function emailFacts(e) {
  const s = `${e.subject || ''}\n${String(e.body_text || '').slice(0, 30000)}`;
  const amounts = [];
  for (const m of s.matchAll(/(?:[$€£]|USD\s?)\s?(\d{1,3}(?:,\d{3})+(?:\.\d{2})?|\d+(?:\.\d{2}))/g)) {
    const v = Number(m[1].replace(/,/g, '')).toFixed(2);
    if (Number(v) > 0 && !amounts.some(a => a.value === v)) {
      const context = ctxOf(s, m.index);
      amounts.push({ value: v, display: `$${Number(v).toLocaleString('en-US', { minimumFractionDigits: 2 })}`, context, likely: /total|amount due|balance|pay|due|charges/i.test(context) });
    }
  }
  const dates = [];
  const addDate = (y, mo, d, i) => {
    y = Number(y) < 100 ? 2000 + Number(y) : Number(y);
    if (!y || mo < 1 || mo > 12 || d < 1 || d > 31 || y < 2000 || y > 2100) return;
    const iso = `${y}-${String(mo).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
    if (!dates.some(x => x.value === iso)) {
      const context = ctxOf(s, i);
      dates.push({ value: iso, display: dayjs(iso).format('MMM D, YYYY'), context, due: /due|by|before|pay/i.test(context) });
    }
  };
  for (const m of s.matchAll(/\b(\d{1,2})\/(\d{1,2})\/(\d{2,4})\b/g)) addDate(m[3], Number(m[1]), Number(m[2]), m.index);
  for (const m of s.matchAll(/\b(\d{4})-(\d{2})-(\d{2})\b/g)) addDate(m[1], Number(m[2]), Number(m[3]), m.index);
  for (const m of s.matchAll(/\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?\s+(\d{1,2}),?\s+(\d{4})\b/gi)) addDate(m[3], MONTHS.indexOf(m[1].toLowerCase().slice(0, 3)) + 1, Number(m[2]), m.index);
  const refs = (re) => {
    const out = [];
    for (const m of s.matchAll(re)) {
      const v = m[1].replace(/\s+/g, ' ').trim();
      if (v.length >= 3 && !out.some(o => o.value === v)) out.push({ value: v, display: v, context: '' });
    }
    return out.slice(0, 6);
  };
  return {
    amounts: amounts.slice(0, 12),
    dates: dates.slice(0, 10),
    accounts: refs(/(?:account|acct)\s*(?:number|no\.?|#)?\s*[:#]?\s*((?:[A-Z0-9]{2,}[ -]?){1,6}\d)/gi),
    invoices: refs(/(?:invoice|bill|statement|order)\s*(?:number|no\.?|#)\s*[:#]?\s*([A-Z0-9][A-Z0-9-]{2,24})/gi),
  };
}
const facts = computed(() => (fromEmail.value ? emailFacts(fromEmail.value) : { amounts: [], dates: [], accounts: [], invoices: [] }));
const picked = ref({ amount: null, date: null, due: null, account: null, invoice: null });
const pickRows = computed(() => [
  { key: 'amount', label: 'Amount', items: facts.value.amounts },
  { key: 'date', label: 'Bill date', items: facts.value.dates },
  { key: 'due', label: 'Payment due', items: facts.value.dates },
  { key: 'account', label: 'Account no.', items: facts.value.accounts },
  { key: 'invoice', label: 'Invoice no.', items: facts.value.invoices },
]);
// Details = subject — sender, then the picked account / invoice / due date.
function composeDetails() {
  const e = fromEmail.value;
  const p = picked.value;
  const parts = [`${e.subject} — ${e.from_name || e.from_email}`];
  if (p.account) parts.push(`Account ${p.account}`);
  if (p.invoice) parts.push(`Invoice ${p.invoice}`);
  if (p.due) parts.push(`Due ${dayjs(p.due).format('MMM D, YYYY')}`);
  form.value.details = parts.join(' · ');
}
function pick(key, value) {
  picked.value[key] = value;
  if (key === 'amount' && value) form.value.amount = value;
  if (key === 'date') form.value.date = value || String(fromEmail.value.sent_at || '').slice(0, 10);
  composeDetails();
}
function prefillFromEmail(e) {
  fromEmail.value = e;
  const f = facts.value;
  const amount = f.amounts.find(a => a.likely) || f.amounts.slice().sort((a, b) => b.value - a.value)[0];
  picked.value = {
    amount: amount?.value || null,
    date: null,
    due: (f.dates.find(d => d.due) || {}).value || null,
    account: f.accounts[0]?.value || null,
    invoice: f.invoices[0]?.value || null,
  };
  form.value.date = String(e.sent_at || '').slice(0, 10) || form.value.date;
  if (picked.value.amount) form.value.amount = picked.value.amount;
  composeDetails();
}
const exampleCategoryFound = ref(true);
const monthLabel = computed(() => (form.value.date ? dayjs(form.value.date).format('MMMM YYYY') : dayjs().format('MMMM YYYY')));

function applyExample(ex) {
  example.value = ex;
  const cat = categories.value.find(c => String(c.name).toLowerCase() === ex.category.toLowerCase());
  exampleCategoryFound.value = !!cat;
  if (cat) form.value.category_id = cat.id;
  form.value.details = ex.details(monthLabel.value);
  repeat.value = ex.repeat ? { mode: 'fixed', frequency: ex.repeat, end_date: '' } : { mode: 'once', frequency: 'monthly', end_date: '' };
}

const NUM_RE = /^\d*\.?\d*$/;
const rules = computed(() => ({
  date: [{ required: true, message: t('Field_is_required') }],
  warehouse_id: [{ required: true, message: t('Field_is_required') }],
  payment_method_id: [{ required: true, message: t('Field_is_required') }],
  category_id: [{ required: true, message: t('Field_is_required') }],
  details: [{ required: true, message: t('Field_is_required') }],
  amount: [
    { required: true, message: t('Field_is_required') },
    { validator: (_r, v) => (NUM_RE.test(String(v ?? '')) ? Promise.resolve() : Promise.reject(t('InvalidData'))) },
  ],
}));

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    message.error(t('Please_fill_the_form_correctly'));
    return;
  }
  if (repeatOn.value && repeat.value.end_date && repeat.value.end_date < form.value.date) {
    message.error('The end date is before the first date.');
    return;
  }
  submitting.value = true;
  try {
    const { recurring_expense_id: _r, ...fields } = form.value;
    if (fromEmail.value) fields.office_email_id = fromEmail.value.id;
    const expense = {
      ...fields,
      account_id: form.value.account_id || '',
    };
    if (isEdit.value) {
      await http.put(`expenses/${id.value}`, { expense });
      message.success(t('Successfully_Updated'));
    } else {
      if (repeatOn.value) expense.repeat = { frequency: repeat.value.frequency, end_date: repeat.value.end_date || null };
      await http.post('expenses', { expense });
      message.success(repeatOn.value
        ? `${t('Successfully_Created')} — the next one will be created on ${nextDate.value}.`
        : t('Successfully_Created'));
    }
    router.push('/expenses');
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    submitting.value = false;
  }
}

onMounted(async () => {
  try {
    if (isEdit.value) {
      const data = await http.get(`expenses/${id.value}/edit`);
      form.value = { ...form.value, ...data.expense };
      categories.value = data.expense_Category || [];
      warehouses.value = data.warehouses || [];
      accounts.value = data.accounts || [];
      paymentMethods.value = data.payment_methods || [];
    } else {
      const data = await http.get('expenses/create');
      categories.value = data.Expenses_category || [];
      warehouses.value = data.warehouses || [];
      accounts.value = data.accounts || [];
      paymentMethods.value = data.payment_methods || [];
      // One location: pre-pick it (a beginner shouldn't have to).
      if (warehouses.value.length === 1) form.value.warehouse_id = warehouses.value[0].id;
      if (route.query.email) {
        try {
          prefillFromEmail((await http.get(`email-office/emails/${route.query.email}`)).email);
        } catch (err) { /* email gone or no access: plain form */ }
      }
      // ?category= (picked in the email panel).
      if (route.query.category && categories.value.some(c => String(c.id) === String(route.query.category))) {
        form.value.category_id = Number(route.query.category);
      }
    }
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    loadingRecord.value = false;
  }
});
</script>

<style scoped>
.ef-help {
  margin-bottom: 16px;
}
.ef-help__title {
  font-weight: 600;
  margin-bottom: 8px;
}
.ef-help__guide {
  margin-top: 12px;
}
.ef-steps {
  margin: 4px 0 6px;
  padding-inline-start: 18px;
}
.ef-steps li {
  margin-bottom: 2px;
}
.ef-muted {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.55);
}
.ef-pick {
  margin-bottom: 16px;
  border-color: #d3adf7;
}
.ef-pick__row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 4px;
  margin-bottom: 6px;
}
.ef-pick__label {
  min-width: 96px;
  font-size: 12px;
  font-weight: 600;
  color: rgba(0, 0, 0, 0.6);
}
.ef-pick__chip {
  cursor: pointer;
  margin: 0;
}
.ef-pick__ctx {
  font-size: 11px;
  opacity: 0.7;
}
.ef-pick__text {
  white-space: pre-wrap;
  word-break: break-word;
  font-family: inherit;
  font-size: 12px;
  max-height: 300px;
  overflow: auto;
  background: #fafafa;
  padding: 8px;
  border-radius: 6px;
}
.ef-warn {
  color: #d46b08;
}
.ef-often :deep(.ant-form-item-label) {
  font-weight: 600;
}
</style>
