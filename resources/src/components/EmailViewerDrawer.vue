<template>
  <a-drawer :open="open" :width="760" :title="email?.subject || 'Email'" destroy-on-close @close="$emit('update:open', false)">
    <a-spin :spinning="loading">
      <template v-if="email">
        <div ref="headRef" class="ev-head">
          <div><span class="ev-label">From</span> <b>{{ email.from_name || email.from_email }}</b> <span v-if="email.from_name" class="ev-muted">&lt;{{ email.from_email }}&gt;</span></div>
          <div><span class="ev-label">To</span> {{ email.to_emails || '—' }}</div>
          <div v-if="email.cc_emails"><span class="ev-label">Cc</span> {{ email.cc_emails }}</div>
          <div><span class="ev-label">Date</span> {{ dateTime(email.sent_at) }}
            <a-tag style="margin-inline-start: 8px">{{ email.direction === 'in' ? 'Received' : SOURCE[email.source] || 'Sent' }}</a-tag>
          </div>
          <div v-if="contactAddress && !readOnly" style="margin-top: 4px">
            <a-button size="small" @click="showSameAddress">
              <template #icon><FilterOutlined /></template>
              Show all emails with {{ contactAddress }}
            </a-button>
            <!-- A rule for this sender (e.g. -> Social), opened in the Rules window. -->
            <a-button v-if="email.direction === 'in' && auth.can('email_office_manage')" size="small" style="margin-left: 6px" @click="createRule">
              Create rule from this sender
            </a-button>
          </div>
        </div>

        <!-- Classification (Email Office only; read-only viewers skip it). -->
        <template v-if="!readOnly">
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
            <!-- Not in Murano yet: add the sender as a customer or supplier (quick window). -->
            <a-button v-if="email.direction === 'in'" size="small" @click="openNewContact">
              <template #icon><PlusOutlined /></template>
              New customer / supplier
            </a-button>
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
          <!-- One tab per kind of record; the email's own kind opens first
               (a customer's email -> Customer, anything else -> Expenses). -->
          <a-tabs v-else v-model:activeKey="recordTab" size="small" class="ev-tabs">
            <!-- Expenses: bills and supplies the company pays. -->
            <a-tab-pane key="expenses">
              <template #tab><DollarOutlined /> Expenses</template>
              <div class="ev-muted" style="margin-bottom: 6px">{{ HINT[email.category] || 'A bill or supplies the company pays: record it as an expense or a purchase.' }}</div>
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
              <!-- Supplies / goods bought: a purchase (products + stock), not an expense. -->
              <div class="ev-row" style="margin-top: 6px">
                <span class="ev-label" style="min-width: auto">New purchase —</span>
                <a-button size="small" @click="createPurchase">
                  <template #icon><PlusOutlined /></template>
                  Create purchase
                </a-button>
                <span class="ev-muted">supplies / products bought (adds the items, and stock once received)</span>
              </div>
            </a-tab-pane>

            <!-- Customer: what a customer asks for. -->
            <a-tab-pane key="customer">
              <template #tab><UserOutlined /> Customer</template>
              <div class="ev-muted" style="margin-bottom: 6px">
                <template v-if="fromCustomer">From the customer <b>{{ email.party_name }}</b>: record what they ask for.</template>
                <template v-else>Assign the email to a customer first (1. Customer / supplier) to create their quotation or work order.</template>
              </div>
              <div class="ev-row">
                <a-button v-if="auth.can('Quotations_add')" type="primary" size="small" :disabled="!fromCustomer" @click="createFor('/quotations/create')">
                  <template #icon><PlusOutlined /></template>
                  Create quotation
                </a-button>
                <a-button v-if="canWorkOrder" size="small" :disabled="!fromCustomer" @click="createFor('/service/jobs/create')">
                  <template #icon><PlusOutlined /></template>
                  Create work order
                </a-button>
                <a-button size="small" :loading="ticketing" @click="openTicket">Open helpdesk ticket</a-button>
              </div>
              <div class="ev-muted" style="margin-top: 4px">
                <b>Quotation</b>: they ask for a price / estimate. <b>Work order</b>: they ask for a job or a visit (service job).
                <b>Ticket</b>: a problem or complaint to follow up. Customer and notes come from the email.
              </div>
            </a-tab-pane>

            <!-- Sales: the email is about an invoice already in Murano. -->
            <a-tab-pane key="sales">
              <template #tab><ShoppingOutlined /> Sales</template>
              <div class="ev-muted" style="margin-bottom: 6px">
                About an invoice already in Murano (a payment, a question about a charge…): link it to that sale below.
              </div>
            </a-tab-pane>
          </a-tabs>

          <!-- Link to an existing document of the open tab's kind. -->
          <div v-if="!email.linked_type" class="ev-row ev-link">
            <span class="ev-muted">Link it to an existing</span>
            <a-select v-model:value="linkType" size="small" style="width: 130px" :options="linkTypeOptions" />
            <a-select
              v-model:value="linkId" size="small" show-search :filter-option="false" style="width: 300px"
              :placeholder="`Find the ${LINKED[linkType].toLowerCase()}…`" :options="linkOptions" :loading="linkLoading"
              :not-found-content="linkLoading ? 'Searching…' : `No ${LINKED[linkType].toLowerCase()}s found`"
              @search="searchLinks" @focus="searchLinks('')"
            />
            <a-button size="small" :disabled="!linkId" @click="patch({ linked_type: linkType, linked_id: linkId })">Link</a-button>
          </div>
        </a-card>

        <!-- 3. Type -->
        <a-card size="small" class="ev-box">
          <div class="ev-row">
            <span class="ev-step" style="margin: 0">3. Type</span>
            <a-select :value="email.category" size="small" style="width: 140px" :options="CATEGORY_OPTIONS" @change="v => patch({ category: v })" />
            <span class="ev-muted">Sorted automatically — change it if it's wrong.</span>
          </div>
        </a-card>
        </template>

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

        <!-- History, below the email: the other emails of this conversation (when the page passes them). -->
        <div v-if="thread.length > 1" class="ev-thread">
          <div class="ev-step">Conversation history ({{ thread.length }})</div>
          <div
            v-for="m in thread" :key="m.id" class="ev-thread-msg" :class="{ current: String(m.id) === String(currentId) }"
            @click="showFromHistory(m)"
          >
            <a-tag :color="m.direction === 'in' ? 'green' : 'blue'" style="margin: 0">{{ m.direction === 'in' ? 'Received' : 'Sent' }}</a-tag>
            <span class="ev-muted" style="flex: none; width: 130px">{{ dateTime(m.sent_at) }}</span>
            <span class="ev-thread-text"><b>{{ m.from_name || m.from_email }}</b> — {{ m.snippet }}</span>
            <span v-if="String(m.id) === String(currentId)" class="ev-muted" style="flex: none">(showing)</span>
          </div>
        </div>
      </template>
    </a-spin>
    <!-- New customer / supplier from this email's sender. -->
    <a-modal
      v-model:open="contactOpen" title="Add the sender to Murano" :ok-text="`Create ${contact.type === 'provider' ? 'supplier' : 'customer'}`"
      :confirm-loading="contactSaving" :width="560" @ok="saveContact"
    >
      <a-form layout="vertical" style="margin-top: 8px">
        <a-form-item label="Add as">
          <a-radio-group v-model:value="contact.type" button-style="solid">
            <a-radio-button value="provider">Supplier</a-radio-button>
            <a-radio-button value="client">Customer</a-radio-button>
          </a-radio-group>
          <div class="ev-muted" style="margin-top: 4px">
            {{ contact.type === 'provider' ? 'A company you buy from or pay bills to (phone, internet, rent…).' : 'Someone you sell to.' }}
          </div>
        </a-form-item>
        <a-form-item label="Name" required>
          <a-input v-model:value="contact.name" />
        </a-form-item>
        <a-form-item label="Email" :extra="senderAddrCount > 1 ? `This sender writes from ${senderAddrCount} different addresses${contact.type === 'provider' ? ' — its emails are matched by the name' : ''}.` : undefined">
          <a-input v-model:value="contact.email" placeholder="(optional)" />
        </a-form-item>
        <a-form-item label="Phone">
          <a-input v-model:value="contact.phone" placeholder="(optional)" />
          <div v-if="phonesFound.length" style="margin-top: 4px">
            <span class="ev-muted">In the email: </span>
            <a-tag v-for="p in phonesFound" :key="p" style="cursor: pointer" :color="contact.phone === p ? 'purple' : undefined" @click="contact.phone = p">{{ p }}</a-tag>
          </div>
        </a-form-item>

        <!-- Address -->
        <div class="ev-step" style="margin-top: 4px">Address</div>
        <div v-if="addressesFound.length" style="margin-bottom: 6px">
          <span class="ev-muted">In the email: </span>
          <a-tag v-for="a in addressesFound" :key="a.label" style="cursor: pointer; white-space: normal" @click="useAddress(a)">{{ a.label }}</a-tag>
        </div>
        <a-form-item label="Street" style="margin-bottom: 8px">
          <a-input v-model:value="contact.adresse" placeholder="(optional)" />
        </a-form-item>
        <a-row :gutter="8">
          <a-col :span="10"><a-form-item label="City" style="margin-bottom: 8px"><a-input v-model:value="contact.city" /></a-form-item></a-col>
          <a-col :span="5"><a-form-item label="State" style="margin-bottom: 8px"><a-input v-model:value="contact.state" /></a-form-item></a-col>
          <a-col :span="9"><a-form-item label="ZIP" style="margin-bottom: 8px"><a-input v-model:value="contact.zip" /></a-form-item></a-col>
        </a-row>
        <a-form-item label="Country">
          <a-input v-model:value="contact.country" />
        </a-form-item>

        <!-- Contact person -->
        <div class="ev-step">Contact person</div>
        <a-row :gutter="8">
          <a-col :span="24"><a-form-item label="Name" style="margin-bottom: 8px"><a-input v-model:value="contact.contact_name" placeholder="(optional)" /></a-form-item></a-col>
          <a-col :span="14"><a-form-item label="Email" style="margin-bottom: 8px"><a-input v-model:value="contact.contact_email" /></a-form-item></a-col>
          <a-col :span="10"><a-form-item label="Phone" style="margin-bottom: 8px"><a-input v-model:value="contact.contact_phone" /></a-form-item></a-col>
        </a-row>
        <a-checkbox v-if="senderCount > 1" v-model:checked="contact.linkAll">
          Link all {{ senderCount }} emails from {{ senderName }} to it
        </a-checkbox>
        <div v-else class="ev-muted">This email is linked to it.</div>
      </a-form>
    </a-modal>
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
 * readOnly: just the email (e.g. Customer -> Email conversation) -- none of
 * the classify / link / type tools. thread: the conversation's emails
 * ({id, direction, sent_at, from_name, from_email, snippet}), listed on top
 * so any of them can be opened in place.
 */
import { ref, computed, watch } from 'vue';
import { useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { PaperClipOutlined, FilterOutlined, PlusOutlined, DollarOutlined, UserOutlined, ShoppingOutlined } from '@ant-design/icons-vue';
import http from '../lib/http';
import { sortByLabel } from '../lib/filterOptions';
import { localName } from '../lib/localName';
import { useFormat } from '../composables/useFormat';
import { useAuthStore } from '../stores/auth';
import { EMAIL_CATEGORY_OPTIONS } from '../lib/emailCategories';

const props = defineProps({
  open: { type: Boolean, default: false },
  emailId: { type: [Number, String], default: null },
  readOnly: { type: Boolean, default: false },
  thread: { type: Array, default: () => [] },
});
// The email on screen: the one asked for, or another of its conversation.
const currentId = ref(props.emailId);
// History (below the email): open another one, back at the top to read it.
const headRef = ref(null);
function showFromHistory(m) {
  currentId.value = m.id;
  headRef.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
watch(() => props.emailId, v => { currentId.value = v; });
const emit = defineEmits(['update:open', 'changed', 'filter-address', 'create-rule']);
const router = useRouter();
const { dateTime } = useFormat();

const CATEGORY_OPTIONS = EMAIL_CATEGORY_OPTIONS;
const PARTY = { client: 'Customer', provider: 'Supplier', employee: 'Employee' };
const LINKED = { sale: 'Sale', purchase: 'Purchase', expense: 'Expense', helpdesk_ticket: 'Ticket', quotation: 'Quotation', service_job: 'Work order' };
// 2. Record it in Murano: what each tab can link to.
const TAB_LINK_TYPES = {
  expenses: [{ value: 'expense', label: 'Expense' }, { value: 'purchase', label: 'Purchase' }],
  customer: [{ value: 'quotation', label: 'Quotation' }, { value: 'service_job', label: 'Work order' }],
  sales: [{ value: 'sale', label: 'Sale' }],
};
const recordTab = ref('expenses');
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
// Words in the sender / subject -> the default category it usually is. A list
// of names = the first one the company has (e.g. a landscaping company's own
// categories first, else the generic one).
const CATEGORY_HINTS = [
  [/\b(mulch|nursery|nurseries|landscape supply|landscape depot|garden center|siteone|sod|topsoil|soil|fertilizer|pavers?|stone|boulders?|plants?|trees?)\b/i, ['Materials and supplies', 'Inventory purchases']],
  [/\b(harbor freight|stihl|husqvarna|echo|toro|small engine|tools?|equipment parts)\b/i, ['Equipment and tool expense', 'Repairs and maintenance']],
  [/\b(fuel|gasoline|diesel|shell|exxon|mobil|chevron|bp|sunoco|wawa|quickchek|oil change|auto parts|autozone|napa|advance auto|trailer)\b/i, ['Fuel, oil and vehicle maintenance', 'Automobile and mileage']],
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
  const text = ` ${e.from_name || ''} ${e.from_email || ''} ${e.subject || ''}`;
  for (const [re, names] of CATEGORY_HINTS) {
    if (!re.test(text)) continue;
    for (const n of [].concat(names)) {
      const cat = categories.value.find(c => String(c.name).toLowerCase() === n.toLowerCase());
      if (cat) return { value: cat.id, label: localName(cat) };
    }
  }
  return null;
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
  return {
    sale: `/sales/${e.linked_id}`, purchase: `/purchases/${e.linked_id}`, expense: `/expenses/${e.linked_id}/edit`, helpdesk_ticket: `/helpdesk/tickets/${e.linked_id}`,
    quotation: `/quotations/${e.linked_id}`, service_job: `/service/jobs/details/${e.linked_id}`,
  }[e.linked_type] || null;
});

/* ---- customer emails: quotation / work order ---- */
const auth = useAuthStore();
const fromCustomer = computed(() => email.value?.party_type === 'client' && !!email.value?.party_id);
const canWorkOrder = computed(() => auth.moduleEnabled('service') && auth.can('service_jobs'));
const linkTypeOptions = computed(() => sortByLabel((TAB_LINK_TYPES[recordTab.value] || TAB_LINK_TYPES.expenses)
  .filter(o => o.value !== 'service_job' || canWorkOrder.value)));
// Changing tab: link to that tab's first kind of document.
watch(recordTab, () => {
  if (!linkTypeOptions.value.some(o => o.value === linkType.value)) linkType.value = linkTypeOptions.value[0]?.value || 'sale';
});
// Opens the form prefilled from this email (customer + notes); saving links the email.
function createFor(path) {
  emit('update:open', false);
  router.push({ path, query: { email: email.value.id, client_id: email.value.party_id } });
}

// Each open reloads the email fresh: the previous one is cleared first (never
// shown while the next loads), and a slower earlier answer can't overwrite a
// newer one (loadSeq).
let loadSeq = 0;
async function load() {
  if (!currentId.value) return;
  const seq = ++loadSeq;
  const wanted = currentId.value;
  loading.value = true;
  email.value = null;
  expenseCategory.value = undefined;
  try {
    const fresh = (await http.get(`email-office/emails/${wanted}`)).email;
    if (seq !== loadSeq) return; // a newer open / email took over
    contactOpen.value = false; // (declared below; only touched after the await)
    email.value = fresh;
    view.value = email.value.body_html ? 'html' : 'text';
    linkId.value = undefined;
    linkOptions.value = [];
    partyEditing.value = false;
    partyId.value = undefined;
    partyOptions.value = [];
    partyType.value = email.value.party_type === 'provider' ? 'provider' : (email.value.category === 'bill' ? 'provider' : 'client');
    // Open the tab that fits the email: a customer's -> Customer, else Expenses.
    recordTab.value = email.value.party_type === 'client' ? 'customer' : 'expenses';
    linkType.value = email.value.party_type === 'client' ? 'quotation' : 'expense';
    expenseCategory.value = suggestedCategory.value?.value;
    if (email.value.direction === 'in' && !props.readOnly) loadCategories();
  } catch (e) {
    if (seq === loadSeq) message.error(e?.data?.message || 'Could not open the email.');
  } finally {
    if (seq === loadSeq) loading.value = false;
  }
}
watch(() => [props.open, currentId.value], ([o]) => { if (o) load(); }, { immediate: true });
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

/* ---- New customer / supplier from the sender (quick window) ---- */
const contactOpen = ref(false);
const contactSaving = ref(false);
const emptyContact = () => ({
  type: 'provider', name: '', email: '', phone: '', linkAll: true,
  adresse: '', city: '', state: '', zip: '', country: 'United States',
  contact_name: '', contact_email: '', contact_phone: '',
});
const contact = ref(emptyContact());
// US-style addresses in the email text: "<City>, <ST> <ZIP>", with the street
// before it on the same line ("765 Wyckoff Ave | Wyckoff, NJ 07481") or on the line above.
const addressesFound = computed(() => {
  const lines = String(email.value?.body_text || '').replace(/[​-‍﻿ ]/g, ' ')
    .split(/\r?\n/).map(l => l.trim()).filter(Boolean);
  const re = /(?:(\d[^|,\n]{2,60}?)\s*[|,]\s*)?([A-Za-z][A-Za-z .'-]{1,40}),\s*([A-Z]{2})\s+(\d{5}(?:-\d{4})?)\b/g;
  const out = [];
  lines.forEach((l, i) => {
    for (const m of l.matchAll(re)) {
      const above = lines[i - 1] || '';
      const street = (m[1] || (/^\d/.test(above) && above.length < 80 ? above : '')).trim();
      const a = { adresse: street, city: m[2].trim(), state: m[3], zip: m[4] };
      a.label = [a.adresse, `${a.city}, ${a.state} ${a.zip}`].filter(Boolean).join(', ');
      if (!out.some(o => o.label === a.label)) out.push(a);
    }
  });
  return out.slice(0, 4);
});
function useAddress(a) {
  Object.assign(contact.value, { adresse: a.adresse || contact.value.adresse, city: a.city, state: a.state, zip: a.zip, country: contact.value.country || 'United States' });
}
const senderCount = ref(1);
const senderAddrCount = ref(1);
const senderName = computed(() => String(email.value?.from_name || '').trim().replace(/^"+|"+$/g, '').trim() || email.value?.from_email || '');
const phonesFound = computed(() => {
  const hits = String(email.value?.body_text || '').match(/(?:\+?1[\s.-]?)?\(?\b\d{3}\)?[\s.-]\d{3}[\s.-]\d{4}\b/g) || [];
  return [...new Set(hits.map(p => p.trim()))].slice(0, 6);
});
async function openNewContact() {
  const e = email.value;
  // A bill is usually from a supplier; anything else is more often a customer.
  contact.value = { ...emptyContact(), type: e.category === 'bill' || partyType.value === 'provider' ? 'provider' : 'client', name: senderName.value, email: e.from_email || '' };
  senderCount.value = 1;
  senderAddrCount.value = 1;
  contactOpen.value = true;
  try {
    senderCount.value = (await http.post('email-office/assign-sender', { email_id: e.id, count_only: 1 })).count || 1;
    const s = ((await http.get('email-office/addresses', { direction: 'in', search: senderName.value })).senders || [])
      .find(x => x.sender.toLowerCase() === senderName.value.toLowerCase());
    senderAddrCount.value = s?.addresses || 1;
    // A sender with a new address per email (AT&T): that address is useless on the contact.
    if (senderAddrCount.value > 1) contact.value.email = '';
  } catch (err) { /* counts are only a help */ }
}
async function saveContact() {
  const c = contact.value;
  if (!c.name.trim()) {
    message.error('Type the name.');
    return;
  }
  contactSaving.value = true;
  try {
    const tr = v => String(v || '').trim();
    const body = {
      name: tr(c.name), email: tr(c.email), phone: tr(c.phone),
      adresse: tr(c.adresse), city: tr(c.city), state: tr(c.state), zip: tr(c.zip), country: tr(c.country),
      contact_name: tr(c.contact_name), contact_email: tr(c.contact_email), contact_phone: tr(c.contact_phone),
    };
    const res = c.type === 'provider' ? await http.post('providers', body) : await http.post('clients', body);
    const newId = res?.id;
    if (!newId) throw new Error('no id');
    if (c.linkAll && senderCount.value > 1) {
      await http.post('email-office/assign-sender', { email_id: email.value.id, party_type: c.type, party_id: newId });
    } else {
      await http.put(`email-office/emails/${email.value.id}`, { party_type: c.type, party_id: newId });
    }
    message.success(`${c.type === 'provider' ? 'Supplier' : 'Customer'} ${body.name} created${c.linkAll && senderCount.value > 1 ? ` and ${senderCount.value} emails linked` : ''}.`);
    contactOpen.value = false;
    await load();
    emit('changed');
  } catch (e) {
    const errs = e?.data?.errors;
    message.error((errs && Object.values(errs)[0]?.[0]) || e?.data?.message || 'Could not create it.');
  } finally {
    contactSaving.value = false;
  }
}

// "Create rule from this sender": From contains its domain (account.tiktok.com -> tiktok.com).
function createRule() {
  const e = email.value;
  const domain = String(e.from_email || '').split('@')[1] || e.from_email || '';
  const parts = domain.split('.');
  const base = parts.length > 2 ? parts.slice(-2).join('.') : domain;
  const name = String(e.from_name || '').trim().replace(/^"+|"+$/g, '') || base;
  emit('update:open', false);
  emit('create-rule', {
    name: `From ${name}`, conditions: [{ field: 'from', op: 'contains', value: base }], set_category: undefined,
    _email: { from_email: e.from_email, from_name: e.from_name, subject: e.subject, snippet: e.snippet },
  });
}

function createPurchase() {
  emit('update:open', false);
  router.push({ path: '/purchases/create', query: { email: email.value.id } });
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
.ev-tabs :deep(.ant-tabs-nav) {
  margin-bottom: 8px;
}
.ev-link {
  margin-top: 10px;
  padding-top: 8px;
  border-top: 1px dashed #eee;
}
.ev-box {
  margin: 12px 0;
}
/* Conversation history (thread prop). */
.ev-thread {
  margin: 12px 0;
  padding: 8px 10px;
  border: 1px solid #f0f0f0;
  border-radius: 8px;
}
.ev-thread-msg {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 5px 6px;
  border-radius: 6px;
  cursor: pointer;
  min-width: 0;
}
.ev-thread-msg:hover {
  background: rgba(0, 0, 0, 0.04);
}
.ev-thread-msg.current {
  background: rgba(114, 46, 209, 0.1);
}
.ev-thread-text {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
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
