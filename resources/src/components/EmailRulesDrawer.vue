<template>
  <a-drawer :open="open" title="Email rules" :width="760" destroy-on-close @close="$emit('update:open', false)">
    <div class="er-help">
      Your own sorting: when an email arrives, every active rule it matches is applied — after the automatic
      sorting, so a rule always wins. Example: <b>From contains</b> tiktok <b>or</b> facebook → move to <b>Social</b>.
    </div>

    <!-- Your own folders (created with "+ New folder" in a rule). -->
    <div v-if="EMAIL_FOLDERS.length" class="er-folders">
      <b>Your folders:</b>
      <a-tag v-for="f in EMAIL_FOLDERS" :key="f.key" color="purple" style="margin: 0">
        {{ f.name }}
        <a-popconfirm :title="`Delete the folder &quot;${f.name}&quot;? Its emails go back to General.`" @confirm="removeFolder(f)">
          <span class="er-folder-x">✕</span>
        </a-popconfirm>
      </a-tag>
    </div>

    <!-- Your rules first: edit / switch off / apply / delete any of them. -->
    <div class="er-head">
      <b>Your rules ({{ rules.length }})</b>
      <a-space>
        <a-tooltip title="Apply every active rule again, in order, to the emails already here (after editing rules)">
          <a-button size="small" :disabled="!rules.length" :loading="running === 'all'" @click="runAll">Re-apply all rules</a-button>
        </a-tooltip>
        <a-button type="primary" size="small" @click="edit(null)">+ New rule</a-button>
      </a-space>
    </div>
    <a-spin :spinning="loading">
      <a-empty v-if="!rules.length && !loading" description="No rules yet" />
      <div v-for="r in rules" :key="r.id" class="er-rule" :class="{ 'er-rule--off': !r.active, 'er-rule--editing': editing && editing.id === r.id }">
        <a-switch size="small" :checked="!!r.active" @change="v => toggle(r, v)" />
        <div class="er-rule__main">
          <div class="er-rule__name">{{ r.name }}<a-tag v-if="matchesEmail(r)" color="purple" style="margin-left: 6px">matches this email</a-tag></div>
          <div class="er-muted">{{ describe(r) }}</div>
          <div class="er-muted">Applied {{ r.applied_count || 0 }} time{{ r.applied_count === 1 ? '' : 's' }}</div>
        </div>
        <a-space>
          <a-tooltip title="Apply it now to the emails already here (not to ones you typed by hand or recorded)">
            <a-button size="small" :loading="running === r.id" @click="run(r)">Apply to existing</a-button>
          </a-tooltip>
          <a-button size="small" :type="editing && editing.id === r.id ? 'primary' : 'default'" @click="edit(r)">Edit</a-button>
          <a-popconfirm title="Delete this rule? (emails already moved stay where they are)" @confirm="remove(r)">
            <a-button size="small" danger>Delete</a-button>
          </a-popconfirm>
        </a-space>
      </div>
    </a-spin>

    <!-- Editor (new / edit), below the list -->
    <a-card v-if="editing" ref="editorRef" size="small" :title="editing.id ? `Edit rule: ${editing.name}` : 'New rule'" class="er-edit">
      <a-form layout="vertical">
        <a-form-item label="Name" required>
          <a-input v-model:value="editing.name" placeholder="e.g. Social networks" />
        </a-form-item>

        <div class="er-label">When an email matches
          <a-radio-group v-model:value="editing.match" size="small" button-style="solid" style="margin: 0 6px">
            <a-radio-button value="any">any</a-radio-button>
            <a-radio-button value="all">all</a-radio-button>
          </a-radio-group>
          of these conditions:
        </div>
        <div v-for="(c, i) in editing.conditions" :key="i" class="er-cond">
          <a-select v-model:value="c.field" :options="FIELD_OPTIONS" style="width: 150px" />
          <a-select v-model:value="c.op" :options="OP_OPTIONS" style="width: 120px" />
          <a-input v-model:value="c.value" placeholder="e.g. tiktok" style="flex: 1" />
          <a-button type="text" danger :disabled="editing.conditions.length === 1" @click="editing.conditions.splice(i, 1)">✕</a-button>
        </div>
        <a-button size="small" style="margin-bottom: 12px" @click="editing.conditions.push({ field: 'from', op: 'contains', value: '' })">+ Add condition</a-button>

        <div class="er-label">Then:</div>
        <div class="er-cond">
          <span style="width: 150px">Move to type / folder</span>
          <a-select v-model:value="editing.set_category" allow-clear show-search option-filter-prop="label" placeholder="(don't change)" :options="EMAIL_CATEGORY_OPTIONS" style="width: 220px" />
          <a-checkbox v-model:checked="editing.mark_read">Mark as read</a-checkbox>
        </div>
        <!-- A new folder of your own (e.g. Newsletters, Banks), picked right away. -->
        <div class="er-cond">
          <span style="width: 150px"></span>
          <template v-if="newFolder !== null">
            <a-input v-model:value="newFolder" size="small" placeholder="New folder name, e.g. Newsletters" style="width: 220px" @press-enter="addFolder" />
            <a-button size="small" type="primary" :loading="addingFolder" @click="addFolder">Create</a-button>
            <a-button size="small" @click="newFolder = null">Cancel</a-button>
          </template>
          <a v-else @click="newFolder = ''">+ New folder</a>
        </div>
        <!-- Zelle emails: a deposit ("We deposited your payment") -> a paid sale; a payment
             sent ("Confirmed: Your payment to ...") -> an expense. Both wait for Confirm. -->
        <div class="er-cond">
          <span style="width: 150px">Also prepare</span>
          <a-select v-model:value="editing.prep_kind" :options="PREP_OPTIONS" style="width: 300px" />
        </div>
        <div v-if="editing.prep_kind === 'zelle_out'" class="er-muted" style="margin: -4px 0 8px 158px">
          Each matching email waits in the list with a <b>Confirm expense</b> button: the first time a payee appears you say who
          it is (employee paid as a 1099 contractor, or just an expense category) and Murano remembers it. Paid with Zelle from the
          account ending in the email's last 4 digits, on the email's date.
        </div>
        <div v-if="editing.prep_kind === 'zelle_in'" class="er-cond">
          <span style="width: 150px"></span>
          <a-select v-model:value="editing.sale_product_id" show-search option-filter-prop="label" placeholder="Service" :options="serviceOptions" style="width: 220px" />
          <a-select v-model:value="editing.sale_agent_id" allow-clear show-search option-filter-prop="label" placeholder="Sales agent" :options="agentOptions" style="width: 200px" />
        </div>
        <div v-if="editing.prep_kind === 'zelle_in'" class="er-muted" style="margin: -4px 0 8px 158px">
          Each matching email waits in the list with a <b>Confirm sale</b> button: customer = the payer (created if new),
          date / time of the email, paid with Zelle into the account ending in the email's last 4 digits.
        </div>
        <a-checkbox v-model:checked="editing.active" style="margin-top: 8px">Active</a-checkbox>

        <a-space style="margin-top: 12px; display: flex">
          <a-button type="primary" :loading="saving" @click="save(false)">Save</a-button>
          <a-button :loading="saving" @click="save(true)">{{ editing.id ? 'Save & re-apply to existing emails' : 'Save & apply to existing emails' }}</a-button>
          <a-button @click="editing = null">Cancel</a-button>
        </a-space>
      </a-form>
    </a-card>
  </a-drawer>
</template>

<script setup>
/**
 * Email Office rules (email-office/rules): the company's own sorting, e.g.
 * From contains tiktok / facebook -> Social. prefill = a rule to start from
 * (e.g. "Create rule from this sender" in the email panel). Emits `changed`
 * after a rule moved emails.
 */
import { ref, computed, watch, nextTick } from 'vue';
import { message } from 'ant-design-vue';
import http from '../lib/http';
import { sortByLabel } from '../lib/filterOptions';
import { EMAIL_CATEGORY_OPTIONS, EMAIL_CATEGORY_LABEL, EMAIL_FOLDERS, loadEmailFolders, createEmailFolder } from '../lib/emailCategories';

const props = defineProps({
  open: { type: Boolean, default: false },
  prefill: { type: Object, default: null },
});
const emit = defineEmits(['update:open', 'changed']);

const FIELD_LABEL = { from: 'From (address)', from_name: 'From (name)', subject: 'Subject', body: 'Text', any: 'Anywhere' };
const OP_LABEL = { contains: 'contains', is: 'is exactly', ends_with: 'ends with' };
const FIELD_OPTIONS = Object.entries(FIELD_LABEL).map(([value, label]) => ({ value, label }));
const OP_OPTIONS = Object.entries(OP_LABEL).map(([value, label]) => ({ value, label }));

const rules = ref([]);
const loading = ref(false);
const editing = ref(null);
const saving = ref(false);
const running = ref(null);

const blank = () => ({ id: null, name: '', match: 'any', conditions: [{ field: 'from', op: 'contains', value: '' }], set_category: undefined, mark_read: false, active: true, prep_kind: '', sale_product_id: undefined, sale_agent_id: undefined });
const PREP_OPTIONS = [
  { value: '', label: 'Nothing' },
  { value: 'zelle_in', label: 'A paid sale (Zelle deposit received)' },
  { value: 'zelle_out', label: 'An expense (Zelle payment sent)' },
];
// What a saved rule prepares (an old rule without "kind" = a paid sale).
const kindOf = p => (p ? p.kind || 'zelle_in' : '');

// "Prepare a paid sale": the company's services and sales agents.
const saleOptions = ref({ services: [], agents: [] });
const serviceOptions = computed(() => sortByLabel((saleOptions.value.services || []).map(s => ({ value: s.id, label: s.name }))));
const agentOptions = computed(() => sortByLabel((saleOptions.value.agents || []).map(a => ({ value: a.id, label: a.name }))));
const prepareSaleBody = r => {
  if (r.prep_kind === 'zelle_out') return { kind: 'zelle_out' };
  return r.prep_kind === 'zelle_in' && r.sale_product_id ? { kind: 'zelle_in', product_id: r.sale_product_id, sales_agent_id: r.sale_agent_id || null } : null;
};

async function load() {
  loading.value = true;
  try {
    // conditions always as a list (never let one odd row break the whole window).
    const asList = c => {
      if (Array.isArray(c)) return c;
      try { const v = JSON.parse(c || '[]'); return Array.isArray(v) ? v : []; } catch (e) { return []; }
    };
    const res = await http.get('email-office/rules');
    saleOptions.value = res.sale_options || { services: [], agents: [] };
    rules.value = (res.rules || []).map(r => ({ ...r, conditions: asList(r.conditions) }));
  } catch (e) {
    message.error(e?.data?.message || 'Could not load the rules.');
  } finally {
    loading.value = false;
  }
}
// The email the window was opened from (⚡ / "Create rule from this sender"): an
// existing rule that already matches it opens for editing instead of a new one.
const sourceEmail = ref(null);
watch(() => props.open, async o => {
  if (!o) return;
  const pre = props.prefill ? JSON.parse(JSON.stringify(props.prefill)) : null;
  sourceEmail.value = pre?._email || null;
  if (pre) delete pre._email;
  editing.value = null;
  loadEmailFolders();
  await load();
  if (!pre) return;
  const existing = sourceEmail.value && rules.value.find(r => matchesEmail(r));
  if (existing) {
    edit(existing);
    message.info(`"${existing.name}" already matches this email — edit it, or press "+ New rule".`);
  } else {
    editing.value = { ...blank(), ...pre };
    scrollToEditor();
  }
}, { immediate: true });

// Same test as the server (EmailRules) on what the list knows of the email.
function matchesEmail(r) {
  const e = sourceEmail.value;
  if (!e || !r.active) return false;
  const vals = f => ({
    from: [e.from_email], from_name: [String(e.from_name || '').replace(/^"+|"+$/g, '')], subject: [e.subject], body: [e.snippet],
  }[f] || [e.from_email, e.from_name, e.subject, e.snippet]).map(v => String(v || '').toLowerCase().trim());
  const test = c => {
    const n = String(c.value || '').toLowerCase().trim();
    if (!n) return false;
    return vals(c.field).some(v => (c.op === 'is' ? v === n : c.op === 'ends_with' ? v.endsWith(n) : v.includes(n)));
  };
  const hits = (r.conditions || []).map(test);
  return hits.length > 0 && (r.match === 'all' ? hits.every(Boolean) : hits.some(Boolean));
}

const editorRef = ref(null);
function scrollToEditor() {
  nextTick(() => editorRef.value?.$el?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
}

function describe(r) {
  const conds = (r.conditions || []).map(c => `${FIELD_LABEL[c.field] || c.field} ${OP_LABEL[c.op] || c.op} "${c.value}"`).join(r.match === 'all' ? ' and ' : ' or ');
  const svc = r.prepare_sale && (saleOptions.value.services || []).find(s => s.id === r.prepare_sale.product_id);
  const acts = [
    r.set_category && `move to ${EMAIL_CATEGORY_LABEL[r.set_category] || r.set_category}`,
    r.mark_read && 'mark as read',
    kindOf(r.prepare_sale) === 'zelle_in' && `prepare a paid sale (${svc ? svc.name : 'service'})`,
    kindOf(r.prepare_sale) === 'zelle_out' && 'prepare an expense (Zelle payment sent)',
  ].filter(Boolean).join(', ');
  return `When ${conds} → ${acts}`;
}
function edit(r) {
  editing.value = r
    ? {
      id: r.id, name: r.name, match: r.match, conditions: JSON.parse(JSON.stringify(r.conditions || [])), set_category: r.set_category || undefined, mark_read: !!r.mark_read, active: !!r.active,
      prep_kind: kindOf(r.prepare_sale), sale_product_id: r.prepare_sale?.product_id || undefined, sale_agent_id: r.prepare_sale?.sales_agent_id || undefined,
    }
    : blank();
  scrollToEditor();
}
async function save(andRun) {
  const r = editing.value;
  r.conditions = r.conditions.filter(c => String(c.value || '').trim());
  if (!r.name.trim() || !r.conditions.length) {
    message.error('Give it a name and at least one condition with a value.');
    if (!r.conditions.length) r.conditions.push({ field: 'from', op: 'contains', value: '' });
    return;
  }
  if (r.prep_kind === 'zelle_in' && !r.sale_product_id) {
    message.error('Pick the service of the sale.');
    return;
  }
  saving.value = true;
  try {
    const body = { name: r.name, match: r.match, conditions: r.conditions, set_category: r.set_category || null, mark_read: r.mark_read, active: r.active, prepare_sale: prepareSaleBody(r) };
    const res = r.id ? await http.put(`email-office/rules/${r.id}`, body) : await http.post('email-office/rules', body);
    message.success('Rule saved — it applies to new emails from now on.');
    editing.value = null;
    await load();
    if (andRun) await run({ id: res.id || r.id });
  } catch (e) {
    const errs = e?.data?.errors;
    message.error((errs && Object.values(errs)[0]?.[0]) || e?.data?.message || 'Could not save the rule.');
  } finally {
    saving.value = false;
  }
}
async function run(r) {
  running.value = r.id;
  try {
    const res = await http.post(`email-office/rules/${r.id}/run`, {});
    message.success(`${res.changed} existing email${res.changed === 1 ? '' : 's'} changed.`);
    await load();
    if (res.changed) emit('changed');
  } catch (e) {
    message.error(e?.data?.message || 'Could not apply the rule.');
  } finally {
    running.value = null;
  }
}
// "+ New folder" next to Move to: create it and pick it for this rule.
const newFolder = ref(null);
const addingFolder = ref(false);
async function addFolder() {
  const name = String(newFolder.value || '').trim();
  if (!name) return;
  addingFolder.value = true;
  try {
    const key = await createEmailFolder(name);
    if (editing.value) editing.value.set_category = key;
    newFolder.value = null;
    message.success(`Folder "${name}" created — emails moved there leave the normal list and get their own card.`);
  } catch (e) {
    message.error(e?.data?.message || 'Could not create the folder.');
  } finally {
    addingFolder.value = false;
  }
}

async function removeFolder(f) {
  try {
    const r = await http.delete(`email-office/folders/${String(f.key).slice(1)}`);
    await loadEmailFolders(true);
    await load();
    message.success(`Folder "${f.name}" deleted — ${r.moved} email${r.moved === 1 ? '' : 's'} back to General.`);
    emit('changed');
  } catch (e) {
    message.error(e?.data?.message || 'Could not delete the folder.');
  }
}

async function runAll() {
  running.value = 'all';
  try {
    const res = await http.post('email-office/rules/run-all', {});
    message.success(`Rules re-applied: ${res.changed} email${res.changed === 1 ? '' : 's'} changed.`);
    await load();
    if (res.changed) emit('changed');
  } catch (e) {
    message.error(e?.data?.message || 'Could not re-apply the rules.');
  } finally {
    running.value = null;
  }
}
async function toggle(r, active) {
  try {
    await http.put(`email-office/rules/${r.id}`, { name: r.name, match: r.match, conditions: r.conditions, set_category: r.set_category, mark_read: !!r.mark_read, active, prepare_sale: r.prepare_sale || null });
    r.active = active;
  } catch (e) {
    message.error(e?.data?.message || 'Could not change the rule.');
  }
}
async function remove(r) {
  try {
    await http.delete(`email-office/rules/${r.id}`);
    await load();
  } catch (e) {
    message.error(e?.data?.message || 'Could not delete the rule.');
  }
}
</script>

<style scoped>
.er-help {
  color: #666;
  font-size: 13px;
  margin-bottom: 12px;
}
.er-edit {
  margin-top: 16px;
  border-color: #d3adf7;
}
.er-folders {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
  margin-bottom: 12px;
}
.er-folder-x {
  margin-left: 4px;
  cursor: pointer;
  opacity: 0.6;
}
.er-folder-x:hover {
  opacity: 1;
}
.er-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 4px;
}
.er-rule--editing {
  background: rgba(114, 46, 209, 0.06);
  border-radius: 6px;
  padding-left: 6px;
  padding-right: 6px;
}
.er-label {
  font-weight: 600;
  margin: 4px 0 8px;
}
.er-cond {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;
}
.er-rule {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 0;
  border-bottom: 1px solid #f0f0f0;
}
.er-rule--off {
  opacity: 0.55;
}
.er-rule__main {
  flex: 1;
  min-width: 0;
}
.er-rule__name {
  font-weight: 600;
}
.er-muted {
  color: #888;
  font-size: 12px;
}
</style>
