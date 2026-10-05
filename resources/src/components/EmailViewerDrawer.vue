<template>
  <a-drawer :open="open" :width="760" :title="email?.subject || 'Email'" destroy-on-close @close="$emit('update:open', false)">
    <a-spin :spinning="loading">
      <template v-if="email">
        <div class="ev-head">
          <div><span class="ev-label">From</span> <b>{{ email.from_name || email.from_email }}</b> <span v-if="email.from_name" class="ev-muted">&lt;{{ email.from_email }}&gt;</span></div>
          <div><span class="ev-label">To</span> {{ email.to_emails || '—' }}</div>
          <div v-if="email.cc_emails"><span class="ev-label">Cc</span> {{ email.cc_emails }}</div>
          <div><span class="ev-label">Date</span> {{ dateTime(email.sent_at) }}
            <a-tag style="margin-inline-start: 8px">{{ email.direction === 'in' ? 'Received' : SOURCE[email.source] || 'Sent' }}</a-tag>
          </div>
          <div v-if="contactAddress" style="margin-top: 4px">
            <a-button size="small" @click="showSameAddress">
              <template #icon><FilterOutlined /></template>
              Show all emails with {{ contactAddress }}
            </a-button>
          </div>
        </div>

        <!-- 1. Who: the customer / supplier this email belongs to -->
        <a-card size="small" class="ev-box">
          <div class="ev-step">1. Customer / supplier</div>
          <div v-if="email.party_name" class="ev-row">
            <a-tag color="blue">{{ PARTY[email.party_type] }}</a-tag>
            <router-link v-if="partyLink" :to="partyLink">{{ email.party_name }}</router-link><span v-else>{{ email.party_name }}</span>
            <a-button size="small" type="link" @click="partyEditing = !partyEditing">{{ partyEditing ? 'Cancel' : 'Change' }}</a-button>
          </div>
          <div v-else class="ev-muted">Not matched to anyone yet{{ contactAddress ? ` (${contactAddress} is not on any customer or supplier)` : '' }}.</div>
          <div v-if="!email.party_name || partyEditing" class="ev-row" style="margin-top: 6px">
            <a-select v-model:value="partyType" size="small" style="width: 120px" :options="[{ value: 'client', label: 'Customer' }, { value: 'provider', label: 'Supplier' }]" />
            <a-select
              v-model:value="partyId" size="small" show-search :filter-option="false" style="width: 300px"
              :placeholder="`Find the ${partyType === 'client' ? 'customer' : 'supplier'}…`" :options="partyOptions" :loading="partyLoading"
              @search="searchParties" @focus="searchParties('')"
            />
            <a-button type="primary" size="small" :disabled="!partyId" :loading="assigning" @click="assignParty">Assign</a-button>
            <div v-if="contactAddress" class="ev-sub">
              <a-checkbox v-model:checked="allFromAddress">Every email {{ email.direction === 'in' ? 'from' : 'to' }} {{ contactAddress }}</a-checkbox>
              <a-checkbox v-model:checked="rememberAddress">Save this address on the {{ partyType === 'client' ? 'customer' : 'supplier' }} (future emails match by themselves)</a-checkbox>
            </div>
          </div>
        </a-card>

        <!-- 2. What: the sale / purchase / expense / claim it's about -->
        <a-card size="small" class="ev-box">
          <div class="ev-step">2. Record it in Murano</div>
          <div v-if="email.linked_type" class="ev-row">
            <a-tag color="purple">{{ LINKED[email.linked_type] }} {{ email.linked_label }}</a-tag>
            <router-link v-if="linkedRoute" :to="linkedRoute">Open</router-link>
            <a-button size="small" type="link" danger @click="patch({ linked_type: null })">Unlink</a-button>
          </div>
          <template v-else>
            <div class="ev-muted" style="margin-bottom: 6px">{{ HINT[email.category] || 'Link it to what it is about, if anything.' }}</div>

            <!-- A bill: create the expense in one step, its category already suggested. -->
            <div v-if="email.category === 'bill' || email.direction === 'in'" class="ev-create">
              <div class="ev-row">
                <span class="ev-label" style="min-width: auto">New expense —</span>
                <a-select
                  v-model:value="expenseCategory" size="small" show-search option-filter-prop="label" style="width: 300px"
                  placeholder="Expense category" :options="categoryOptions" :loading="categoriesLoading"
                />
                <a-button type="primary" size="small" @click="createExpense">Create expense</a-button>
              </div>
              <div v-if="suggestedCategory" class="ev-muted" style="margin-top: 2px">
                Suggested from the sender / subject: <b>{{ suggestedCategory.label }}</b> — change it if needed.
              </div>
            </div>

            <div class="ev-row" style="margin-top: 8px">
              <span class="ev-muted">or link it to an existing</span>
              <a-select v-model:value="linkType" size="small" style="width: 110px" :options="LINK_TYPES" />
              <a-select
                v-model:value="linkId" size="small" show-search :filter-option="false" style="width: 300px"
                :placeholder="`Find the ${LINKED[linkType].toLowerCase()}…`" :options="linkOptions" :loading="linkLoading"
                :not-found-content="linkLoading ? 'Searching…' : `No ${LINKED[linkType].toLowerCase()}s found${linkType === 'expense' ? ' — create it above' : ''}`"
                @search="searchLinks" @focus="searchLinks('')"
              />
              <a-button size="small" :disabled="!linkId" @click="patch({ linked_type: linkType, linked_id: linkId })">Link</a-button>
            </div>
            <div class="ev-row" style="margin-top: 6px">
              <span class="ev-muted">or</span>
              <a-button size="small" :loading="ticketing" @click="openTicket">Open helpdesk ticket (claim)</a-button>
            </div>
          </template>
        </a-card>

        <!-- 3. Type -->
        <a-card size="small" class="ev-box">
          <div class="ev-row">
            <span class="ev-step" style="margin: 0">3. Type</span>
            <a-select :value="email.category" size="small" style="width: 140px" :options="CATEGORY_OPTIONS" @change="v => patch({ category: v })" />
            <span class="ev-muted">Sorted automatically — change it if it's wrong.</span>
          </div>
        </a-card>

        <div v-if="email.attachments?.length" class="ev-attach">
          <span class="ev-label">Attachments</span>
          <a-space wrap>
            <a-button v-for="a in email.attachments" :key="a.index" size="small" :disabled="!a.downloadable" @click="download(a)">
              <template #icon><PaperClipOutlined /></template>
              {{ a.name }} <span class="ev-muted">({{ size(a.size) }})</span>
            </a-button>
          </a-space>
        </div>

        <a-segmented v-if="email.body_html" v-model:value="view" :options="[{ value: 'html', label: 'Formatted' }, { value: 'text', label: 'Plain text' }]" size="small" style="margin: 8px 0" />
        <!-- Sandboxed: no scripts, no navigation of the app. -->
        <iframe v-if="email.body_html && view === 'html'" class="ev-html" sandbox="allow-popups" :srcdoc="email.body_html" />
        <pre v-else class="ev-text">{{ email.body_text || '(empty message)' }}</pre>
      </template>
    </a-spin>
  </a-drawer>
</template>

<script setup>
/**
 * One Email Office email (GET email-office/emails/{id}; opening marks it
 * read). 1) assign it to a customer / supplier (optionally every email of
 * that address, and remember the address on the contact: POST
 * email-office/assign-address); 2) link it to a sale / purchase / expense
 * (or create the expense: /expenses/create?email=ID) or open a helpdesk
 * ticket; 3) change its type. Emits `changed` after any edit, and
 * `filter-address` (address) for "Show all emails with …".
 */
import { ref, computed, watch } from 'vue';
import { useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { PaperClipOutlined, FilterOutlined } from '@ant-design/icons-vue';
import http from '../lib/http';
import { sortByLabel } from '../lib/filterOptions';
import { localName } from '../lib/localName';
import { useFormat } from '../composables/useFormat';

const props = defineProps({
  open: { type: Boolean, default: false },
  emailId: { type: [Number, String], default: null },
});
const emit = defineEmits(['update:open', 'changed', 'filter-address']);
const router = useRouter();
const { dateTime } = useFormat();

const CATEGORY_OPTIONS = [
  { value: 'bill', label: 'Bill' }, { value: 'claim', label: 'Claim' },
  { value: 'reminder', label: 'Reminder' }, { value: 'general', label: 'General' },
];
const PARTY = { client: 'Customer', provider: 'Supplier', employee: 'Employee' };
const LINKED = { sale: 'Sale', purchase: 'Purchase', expense: 'Expense', helpdesk_ticket: 'Ticket' };
const LINK_TYPES = [{ value: 'sale', label: 'Sale' }, { value: 'purchase', label: 'Purchase' }, { value: 'expense', label: 'Expense' }];
const HINT = {
  bill: 'Looks like a bill: link it to its purchase / expense, or create the expense.',
  claim: 'Looks like a claim: open a helpdesk ticket, or link it to the sale it is about.',
  reminder: 'A reminder: link it to the sale it is about, if you want.',
};
const SOURCE = { system: 'Sent by Murano', compose: 'Sent from Email Office', mailbox: 'Sent (mailbox)' };

const loading = ref(false);
const email = ref(null);
const view = ref('html');
const linkType = ref('purchase');
const partyType = ref('client');
const partyId = ref(undefined);
const partyOptions = ref([]);
const partyLoading = ref(false);
const partyEditing = ref(false);
const allFromAddress = ref(true);
const rememberAddress = ref(true);
const assigning = ref(false);

/* ---- new expense from a bill: category list + suggestion ---- */
const categories = ref([]);
const categoriesLoading = ref(false);
const expenseCategory = ref(undefined);
const categoryOptions = computed(() => sortByLabel(categories.value.map(c => ({ value: c.id, label: localName(c) }))));
// Words in the sender / subject -> the default category it usually is.
const CATEGORY_HINTS = [
  [/\b(at&t|att|verizon|t-mobile|tmobile|sprint|comcast|xfinity|spectrum|optimum|cox|frontier|wireless|internet|phone bill|telecom)\b/i, 'Internet and telecommunications'],
  [/\b(electric|electricity|pseg|con ?edison|coned|water|sewer|gas bill|natural gas|energy|utility|utilities|power)\b/i, 'Utilities'],
  [/\b(insurance|geico|progressive|state farm|allstate|policy|premium)\b/i, 'Insurance'],
  [/\b(rent|lease|landlord)\b/i, 'Office rent'],
  [/\b(subscription|workspace|microsoft|office 365|adobe|quickbooks|intuit|zoom|dropbox|godaddy|hostinger|titan|aws|google cloud|software)\b/i, 'Software subscriptions'],
  [/\b(fuel|gasoline|shell|exxon|mobil|chevron|bp|sunoco|toll|e-?zpass|mileage)\b/i, 'Automobile and mileage'],
  [/\b(bank fee|service charge|overdraft|wire fee)\b/i, 'Bank fees'],
  [/\b(card processing|merchant|stripe|square|paypal fee)\b/i, 'Credit card processing fees'],
  [/\b(google ads|facebook ads|meta ads|advertis|yelp|thumbtack|angi|bark)\b/i, 'Advertising'],
  [/\b(attorney|lawyer|law firm|legal)\b/i, 'Legal services'],
  [/\b(accountant|cpa|bookkeeping|tax preparation)\b/i, 'Accounting services'],
  [/\b(staples|office depot|supplies)\b/i, 'Office supplies'],
  [/\b(irs|tax bill|property tax|dmv|license fee|permit)\b/i, 'Taxes and government fees'],
  [/\b(repair|maintenance|plumber|electrician|hvac)\b/i, 'Repairs and maintenance'],
];
const suggestedCategory = computed(() => {
  const e = email.value;
  if (!e || !categories.value.length) return null;
  const text = ` ${e.from_email || ''} ${e.subject || ''}`;
  const hit = CATEGORY_HINTS.find(([re]) => re.test(text));
  const cat = hit && categories.value.find(c => String(c.name).toLowerCase() === hit[1].toLowerCase());
  return cat ? { value: cat.id, label: localName(cat) } : null;
});
async function loadCategories() {
  if (categories.value.length || categoriesLoading.value) return;
  categoriesLoading.value = true;
  try {
    categories.value = (await http.get('expenses/create')).Expenses_category || [];
  } catch (e) { /* no expense access: the list stays empty */ } finally {
    categoriesLoading.value = false;
  }
}
watch(suggestedCategory, s => { if (s && !expenseCategory.value) expenseCategory.value = s.value; });
// The other side of the conversation: the sender (received) or the first recipient (sent).
const contactAddress = computed(() => {
  const e = email.value;
  if (!e) return '';
  return e.direction === 'in' ? (e.from_email || '') : String(e.to_emails || '').split(',')[0].trim();
});
const linkId = ref(undefined);
const linkOptions = ref([]);
const linkLoading = ref(false);
const ticketing = ref(false);

const partyLink = computed(() => {
  const e = email.value;
  if (!e?.party_id) return null;
  return { client: `/customers/${e.party_id}/details`, provider: `/suppliers/${e.party_id}/details` }[e.party_type] || null;
});
const linkedRoute = computed(() => {
  const e = email.value;
  if (!e?.linked_id) return null;
  return { sale: `/sales/${e.linked_id}`, purchase: `/purchases/${e.linked_id}`, expense: `/expenses/${e.linked_id}/edit`, helpdesk_ticket: `/helpdesk/tickets/${e.linked_id}` }[e.linked_type] || null;
});

async function load() {
  if (!props.emailId) return;
  loading.value = true;
  try {
    email.value = (await http.get(`email-office/emails/${props.emailId}`)).email;
    view.value = email.value.body_html ? 'html' : 'text';
    linkId.value = undefined;
    linkOptions.value = [];
    partyEditing.value = false;
    partyId.value = undefined;
    partyOptions.value = [];
    partyType.value = email.value.party_type === 'provider' ? 'provider' : (email.value.category === 'bill' ? 'provider' : 'client');
    linkType.value = email.value.category === 'bill' ? 'expense' : 'sale';
    expenseCategory.value = suggestedCategory.value?.value;
    if (email.value.direction === 'in') loadCategories();
  } catch (e) {
    message.error(e?.data?.message || 'Could not open the email.');
  } finally {
    loading.value = false;
  }
}
watch(() => [props.open, props.emailId], ([o]) => { if (o) load(); }, { immediate: true });
watch(linkType, () => { linkId.value = undefined; linkOptions.value = []; });
watch(partyType, () => { partyId.value = undefined; partyOptions.value = []; });

let partyTimer = null;
function searchParties(q) {
  clearTimeout(partyTimer);
  partyTimer = setTimeout(async () => {
    partyLoading.value = true;
    try {
      partyOptions.value = (await http.get('email-office/party-options', { type: partyType.value, search: q || '' })).options || [];
    } finally {
      partyLoading.value = false;
    }
  }, 250);
}

// This email -- or every email of the address -- belongs to the picked customer / supplier.
async function assignParty() {
  assigning.value = true;
  try {
    if (allFromAddress.value && contactAddress.value) {
      const r = await http.post('email-office/assign-address', { address: contactAddress.value, party_type: partyType.value, party_id: partyId.value, remember: rememberAddress.value });
      message.success(`Assigned ${r.updated} email(s)${r.remembered ? ' — address saved on the contact' : ''}.`);
    } else {
      await http.put(`email-office/emails/${email.value.id}`, { party_type: partyType.value, party_id: partyId.value });
      message.success('Assigned.');
    }
    await load();
    emit('changed');
  } catch (e) {
    message.error(e?.data?.message || 'Could not assign.');
  } finally {
    assigning.value = false;
  }
}

function showSameAddress() {
  emit('filter-address', contactAddress.value);
  emit('update:open', false);
}

async function patch(body) {
  try {
    await http.put(`email-office/emails/${email.value.id}`, body);
    await load();
    emit('changed');
  } catch (e) {
    message.error(e?.data?.message || 'Could not save.');
  }
}

let searchTimer = null;
function searchLinks(q) {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(async () => {
    linkLoading.value = true;
    try {
      linkOptions.value = (await http.get('email-office/link-options', {
        type: linkType.value, search: q || '',
        ...(email.value?.party_type === 'client' ? { client_id: email.value.party_id } : {}),
      })).options || [];
    } finally {
      linkLoading.value = false;
    }
  }, 250);
}

function createExpense() {
  emit('update:open', false);
  router.push({ path: '/expenses/create', query: { email: email.value.id, ...(expenseCategory.value ? { category: expenseCategory.value } : {}) } });
}

async function openTicket() {
  ticketing.value = true;
  try {
    const r = await http.post(`email-office/emails/${email.value.id}/ticket`);
    message.success(`Ticket ${r.ticket.number} opened.`);
    await load();
    emit('changed');
  } catch (e) {
    message.error(e?.data?.message || 'Could not open the ticket.');
  } finally {
    ticketing.value = false;
  }
}

function download(a) {
  http.download(`email-office/emails/${email.value.id}/attachments/${a.index}`, a.name);
}
const size = b => (b > 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`);
</script>

<style scoped>
.ev-head > div {
  margin-bottom: 4px;
}
.ev-label {
  display: inline-block;
  min-width: 64px;
  font-size: 12px;
  color: rgba(0, 0, 0, 0.5);
}
.ev-muted {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.55);
}
.ev-box {
  margin: 12px 0;
}
.ev-step {
  font-weight: 600;
  margin-bottom: 6px;
}
.ev-row {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  align-items: center;
}
.ev-sub {
  display: flex;
  flex-direction: column;
  gap: 2px;
  width: 100%;
  margin-top: 4px;
}
.ev-create {
  background: #fafafa;
  border: 1px dashed #d9d9d9;
  border-radius: 6px;
  padding: 8px;
}
.ev-actions {
  margin-top: 10px;
}
.ev-actions .ev-muted {
  margin-bottom: 6px;
}
.ev-attach {
  margin-bottom: 8px;
}
.ev-html {
  width: 100%;
  height: 520px;
  border: 1px solid #f0f0f0;
  border-radius: 6px;
  background: #fff;
}
.ev-text {
  white-space: pre-wrap;
  word-break: break-word;
  font-family: inherit;
  font-size: 13px;
  background: #fafafa;
  border: 1px solid #f0f0f0;
  border-radius: 6px;
  padding: 12px;
  max-height: 560px;
  overflow: auto;
}
</style>
