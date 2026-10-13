<template>
  <div class="page">
    <PageHeader :title="$t('Company')" :breadcrumb="[$t('HRM'), $t('Company')]">
      <template #actions>
        <a-button v-if="isSuperAdmin" type="primary" @click="openCreate">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <DataTable :crud="crud" :columns="columns" selectable>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'setup'">
          <!-- Required company information still missing (CompanyProfile::REQUIRED). -->
          <a-tooltip v-if="(record.profile_missing || []).length" :title="'Missing: ' + record.profile_missing.join(', ')">
            <a-tag color="orange" style="cursor: pointer" @click="openEdit(record)">{{ record.profile_missing.length }} missing</a-tag>
          </a-tooltip>
          <a-tag v-else color="green">Complete</a-tag>
        </template>
        <template v-else-if="column.key === 'website_mode'">
          <a-tag :color="record.website_mode === 'import' ? 'purple' : 'blue'">
            {{ record.website_mode === 'import' ? 'Imported website' : 'Default store' }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'demo_count'">
          <template v-if="record.demo_count">
            <a-tag color="purple">{{ record.demo_count }} demo rows</a-tag>
            <a-button v-if="isSuperAdmin" size="small" type="link" danger :loading="clearing === record.id" @click="clearDemo(record)">
              {{ $t('Clear') === 'Clear' ? 'Clear' : $t('Clear') }}
            </a-button>
          </template>
          <span v-else class="muted">—</span>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space>
            <a-tooltip :title="$t('Edit')">
              <a-button type="text" size="small" @click="openEdit(record)">
                <template #icon><EditOutlined style="color: #52c41a" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="isSuperAdmin" :title="$t('Del')">
              <a-button type="text" size="small" danger @click="crud.remove(record)">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>

    <!-- Edit: the company profile in steps (tabs), each with its legend. -->
    <a-modal
      v-model:open="editOpen"
      :title="$t('Edit') + (form.name ? ' — ' + form.name : '')"
      :width="780"
    >
      <div v-if="loadingEdit" style="display: flex; justify-content: center; padding: 48px 0"><a-spin /></div>
      <a-form v-else ref="editFormRef" :model="form" :rules="companyRules" layout="vertical" style="margin-top: 4px">
        <a-alert
          v-if="editMissing.length" type="warning" show-icon style="margin-bottom: 8px"
          :message="'This company is missing required information: ' + editMissing.join(', ') + '.'"
        />
        <a-tabs v-model:activeKey="editTab" class="step-tabs">
          <a-tab-pane v-for="(st, i) in editSteps" :key="st.key" force-render>
            <template #tab>
              <span class="step-tab" :class="{ 'step-tab--err': editErrTabs.includes(st.key) }">
                <span class="step-n">Step {{ i + 1 }}</span>
                <span class="step-name">{{ st.title }}</span>
              </span>
            </template>
            <div class="step-legend">{{ st.legend }}</div>
            <CompanyProfileFields v-if="st.profile" :form="form" :options="options" :section="st.key" />
            <template v-else-if="st.key === 'website'">
              <a-form-item
                label="Website domain(s)"
                extra="The company's own website address, e.g. kcoltech.com (www. optional; separate several with commas)."
              >
                <a-input v-model:value="form.domains" placeholder="example.com" />
              </a-form-item>
              <WebsiteChoice v-model:mode="form.website_mode" v-model:url="form.website_url" :current="form.current_website_mode" />
            </template>
          </a-tab-pane>
        </a-tabs>
      </a-form>
      <template #footer>
        <StepFooter
          :steps="editSteps" v-model:active="editTab" :busy="submitting" :disabled="loadingEdit"
          :submit-text="$t('Submit')" @cancel="editOpen = false" @submit="submitEdit"
        />
      </template>
    </a-modal>

    <!-- Create (SuperAdmin), in steps: the backend sets up the whole company
         (roles, settings, warehouse, walk-in customer, HR basics) plus its admin
         user + employee, and optionally demo data. -->
    <a-modal
      v-model:open="createOpen"
      :title="$t('Add') + ' ' + $t('Company')"
      :width="780"
    >
      <a-form ref="createFormRef" :model="cform" :rules="createRules" layout="vertical" style="margin-top: 4px">
        <a-tabs v-model:activeKey="createTab" class="step-tabs">
          <a-tab-pane v-for="(st, i) in createSteps" :key="st.key" force-render>
            <template #tab>
              <span class="step-tab" :class="{ 'step-tab--err': createErrTabs.includes(st.key) }">
                <span class="step-n">Step {{ i + 1 }}</span>
                <span class="step-name">{{ st.title }}</span>
              </span>
            </template>
            <div class="step-legend">{{ st.legend }}</div>

            <CompanyProfileFields v-if="st.profile" :form="cform" :options="options" :section="st.key" />

            <!-- Helpdesk step: + the optional helpdesk agent login -->
            <template v-if="st.key === 'helpdesk'">
              <div class="section-title">
                <a-checkbox v-model:checked="cform.with_helpdesk">Helpdesk user</a-checkbox>
                <span class="muted">(role "Helpdesk": answers the company's tickets; also created as an employee)</span>
              </div>
              <a-row v-if="cform.with_helpdesk" :gutter="16">
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('Firstname')" name="helpdesk_firstname"><a-input v-model:value="cform.helpdesk_firstname" placeholder="Helpdesk" /></a-form-item>
                </a-col>
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('lastname')" name="helpdesk_lastname"><a-input v-model:value="cform.helpdesk_lastname" /></a-form-item>
                </a-col>
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('Email') + ' (login)'" name="helpdesk_login_email" extra="This agent's own login (can be the same as the helpdesk email above).">
                    <a-input v-model:value="cform.helpdesk_login_email" type="email" autocomplete="off" />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('password')" name="helpdesk_password" extra="Min. 8 characters">
                    <a-input-password v-model:value="cform.helpdesk_password" autocomplete="new-password" />
                  </a-form-item>
                </a-col>
              </a-row>
            </template>

            <template v-else-if="st.key === 'admin'">
              <a-row :gutter="16">
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('Firstname')" name="admin_firstname"><a-input v-model:value="cform.admin_firstname" /></a-form-item>
                </a-col>
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('lastname')" name="admin_lastname"><a-input v-model:value="cform.admin_lastname" /></a-form-item>
                </a-col>
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('Email') + ' (login)'" name="admin_email">
                    <a-input v-model:value="cform.admin_email" type="email" autocomplete="off" />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('password')" name="admin_password" extra="Min. 8 characters">
                    <a-input-password v-model:value="cform.admin_password" autocomplete="new-password" />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('RoleName')" name="admin_role">
                    <a-select v-model:value="cform.admin_role" :options="roleOptions" />
                  </a-form-item>
                </a-col>
              </a-row>
            </template>

            <template v-else-if="st.key === 'website'">
              <a-form-item
                label="Website domain(s)"
                extra="Optional — the company's own website address, e.g. newcompany.com (www. optional; separate several with commas)."
              >
                <a-input v-model:value="cform.domains" placeholder="example.com" />
              </a-form-item>
              <WebsiteChoice v-model:mode="cform.website_mode" v-model:url="cform.website_url" />

              <div class="section-title" style="margin-top: 12px">Demo data</div>
              <a-checkbox v-model:checked="cform.demo_data">Fill this company with demo (example) data</a-checkbox>
              <a-alert
                v-if="cform.demo_data" type="warning" show-icon style="margin-top: 8px"
                message="Example records, not real data"
                description="Adds sample products, customers, suppliers, sales, purchases and examples in every enabled module, so the company can be explored right away. All of it is tagged as DEMO and can be removed at any time with Clear (in this list) or System Settings → Demo Data — real data is never touched."
              />
            </template>
          </a-tab-pane>
        </a-tabs>
      </a-form>
      <template #footer>
        <StepFooter
          :steps="createSteps" v-model:active="createTab" :busy="submitting"
          :submit-text="$t('Submit')" @cancel="createOpen = false" @submit="submitCreate"
        />
      </template>
    </a-modal>

    <!-- Result of a create -->
    <a-modal v-model:open="resultOpen" title="Company created" :footer="null" :width="560">
      <template v-if="result">
        <a-result status="success" :title="result.name" style="padding: 8px 0">
          <template #subTitle>
            Admin login: <strong>{{ result.admin_email }}</strong> (username {{ result.admin_username }})
            <div v-if="result.helpdesk_email">
              Helpdesk login: <strong>{{ result.helpdesk_email }}</strong> (username {{ result.helpdesk_username }})
            </div>
          </template>
        </a-result>
        <a-alert
          v-if="result.website" style="margin-bottom: 12px" show-icon
          :type="result.website.status === 'import_failed' ? 'warning' : 'info'"
          :message="websiteText(result.website)"
        />
        <div v-if="result.demo" class="demo-summary">
          <div class="section-title">Demo data</div>
          <div v-for="r in result.demo" :key="r.key">
            <strong>{{ r.key }}:</strong>
            <span v-if="r.status === 'created'"> {{ r.summary || 'nothing' }}</span>
            <span v-else-if="r.status === 'skipped'" class="muted"> skipped — {{ r.reason }}</span>
            <span v-else class="err"> failed — {{ r.reason }}</span>
          </div>
        </div>
      </template>
    </a-modal>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, createVNode } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined, ExclamationCircleOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import WebsiteChoice from '../../components/WebsiteChoice.vue';
import CompanyProfileFields, { emptyProfile, profileRules, profilePayload, PROFILE_SECTIONS } from '../../components/CompanyProfileFields.vue';
import StepFooter from '../../components/StepFooter.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useAuthStore } from '../../stores/auth';
import { sortByLabel } from '../../lib/filterOptions';
import http from '../../lib/http';

const { t } = useI18n();
const auth = useAuthStore();
const isSuperAdmin = computed(() => auth.user?.type === 'SuperAdmin');
const crud = useCrudTable('company', { rowsKey: 'companies' });

// Dropdown data for the profile (business types, currencies, languages) -- shipped with the list.
const options = computed(() => crud.payload.value?.options || {});

const columns = computed(() => [
  { title: t('Name'), dataIndex: 'name', key: 'name', sorter: true },
  { title: 'Setup', key: 'setup', width: 120, exportValue: r => ((r.profile_missing || []).length ? 'Missing: ' + r.profile_missing.join(', ') : 'Complete') },
  { title: t('Phone'), dataIndex: 'phone', key: 'phone' },
  { title: t('Country'), dataIndex: 'country', key: 'country' },
  { title: t('Email'), dataIndex: 'email', key: 'email' },
  { title: 'Domain', dataIndex: 'domains', key: 'domains' },
  { title: 'Website', dataIndex: 'website_mode', key: 'website_mode', exportValue: r => (r.website_mode === 'import' ? 'Imported website' : 'Default store') },
  { title: 'Demo data', key: 'demo_count', dataIndex: 'demo_count', width: 190, exportValue: r => String(r.demo_count || 0) },
  { title: t('Action'), key: 'actions', width: 110, align: 'center' },
]);

const submitting = ref(false);
const clearing = ref(null);

/* ---------------- steps (tabs) ---------------- */
// Each step: key, title, legend (what it is for), profile = CompanyProfileFields section.
const STEP = {
  company: { key: 'company', title: 'Company', profile: true, legend: 'Who the company is: name, business type, owner and how to reach it. Printed on invoices and quotes.' },
  helpdesk: { key: 'helpdesk', title: 'Helpdesk', profile: true, legend: 'Customer support: the mailbox tickets are answered from' },
  defaults: { key: 'defaults', title: 'Defaults', profile: true, legend: 'How the company works by default: currency, language, invoice numbers and the starting passwords for new logins.' },
  admin: { key: 'admin', title: 'Admin user', legend: 'The company\'s first login: it manages everything else (also created as an employee).' },
  website: { key: 'website', title: 'Website', legend: 'The company\'s own domain and website: import the existing site or use the default online store.' },
};
const editSteps = computed(() => [STEP.company, { ...STEP.helpdesk, legend: STEP.helpdesk.legend + '.' }, STEP.defaults, ...(isSuperAdmin.value ? [STEP.website] : [])]);
const createSteps = computed(() => [
  STEP.company,
  { ...STEP.helpdesk, legend: STEP.helpdesk.legend + ', and optionally a helpdesk agent login.' },
  STEP.defaults,
  STEP.admin,
  { ...STEP.website, title: 'Website & demo', legend: STEP.website.legend + ' Optionally fill it with demo data to explore it.' },
]);
// Field -> step, to open the step with the first error and mark the steps that have one.
const FIELD_STEP = {
  ...Object.fromEntries(Object.entries(PROFILE_SECTIONS).flatMap(([k, fields]) => fields.map(f => [f, k]))),
  admin_firstname: 'admin', admin_lastname: 'admin', admin_email: 'admin', admin_password: 'admin', admin_role: 'admin',
  helpdesk_firstname: 'helpdesk', helpdesk_lastname: 'helpdesk', helpdesk_login_email: 'helpdesk', helpdesk_password: 'helpdesk',
  domains: 'website', website_url: 'website',
};
const editTab = ref('company');
const createTab = ref('company');
const editErrTabs = ref([]);
const createErrTabs = ref([]);
/** Validate the whole form (every step is rendered); on errors, show the first step with one. */
async function validateSteps(formRef, steps, errTabs, activeTab) {
  try {
    await formRef.value.validate();
    errTabs.value = [];
    return true;
  } catch (e) {
    const tabs = [...new Set((e?.errorFields || []).map(f => FIELD_STEP[[].concat(f.name)[0]]).filter(Boolean))];
    errTabs.value = tabs;
    const first = steps.find(s => tabs.includes(s.key));
    if (first) activeTab.value = first.key;
    return false;
  }
}
// Server errors (422): same treatment, from the field names it reports.
function serverErrTabs(e, steps, errTabs, activeTab) {
  const tabs = [...new Set(Object.keys(e?.data?.errors || {}).map(k => FIELD_STEP[k]).filter(Boolean))];
  if (!tabs.length) return;
  errTabs.value = tabs;
  const first = steps.find(s => tabs.includes(s.key));
  if (first) activeTab.value = first.key;
}

/* ---------------- edit ---------------- */
const editOpen = ref(false);
const editFormRef = ref();
const emptyForm = () => ({
  id: null, ...emptyProfile(), domains: '',
  website_mode: 'default', website_url: '', current_website_mode: 'default',
});
const form = ref(emptyForm());
const loadingEdit = ref(false);
const editMissing = ref([]);
const companyRules = computed(() => profileRules());

// Edit loads the whole profile (GET company/{id}): companies + settings + helpdesk.
async function openEdit(record) {
  form.value = { ...emptyForm(), id: record.id, name: record.name || '', website_mode: record.website_mode || 'default', current_website_mode: record.website_mode || 'default' };
  editMissing.value = [];
  editErrTabs.value = [];
  editTab.value = 'company';
  editOpen.value = true;
  loadingEdit.value = true;
  try {
    const res = await http.get(`company/${record.id}`);
    const p = res.profile || {};
    form.value = {
      ...form.value, ...p,
      business_type: p.business_type || undefined,
      currency_id: p.currency_id || options.value.currencies?.[0]?.id,
      default_language: p.default_language || 'en',
      domains: p.domains || '',
      default_password_employee_new: '', default_password_customer_new: '',
    };
    editMissing.value = res.missing || [];
    // Open on the first step that lacks something.
    const missingLabels = { 'Company name': 'name', 'Business type': 'business_type', 'Owner name': 'owner_name', 'Company email': 'email', Phone: 'phone', Address: 'address', 'Helpdesk email': 'helpdesk_email' };
    const firstMissing = editMissing.value.map(l => FIELD_STEP[missingLabels[l]]).find(Boolean);
    if (firstMissing) editTab.value = firstMissing;
    editErrTabs.value = [...new Set(editMissing.value.map(l => FIELD_STEP[missingLabels[l]]).filter(Boolean))];
  } catch (e) {
    message.error(errorText(e));
    editOpen.value = false;
  } finally {
    loadingEdit.value = false;
  }
}

async function submitEdit() {
  if (!(await validateSteps(editFormRef, editSteps.value, editErrTabs, editTab))) return;
  submitting.value = true;
  try {
    const { id, website_mode, website_url, current_website_mode, domains, ...profile } = form.value;
    await http.put(`company/${id}`, { ...profilePayload(profile), ...(isSuperAdmin.value ? { domains } : {}) });
    // Website: re-import when a URL is given, or switch mode when changed.
    if (isSuperAdmin.value && ((website_mode === 'import' && website_url) || website_mode !== current_website_mode)) {
      if (website_mode === 'import' && !website_url) {
        message.warning('Enter the website address to import.');
        return;
      }
      const res = await http.post(`company/${id}/website`, { mode: website_mode, url: website_url || null });
      const w = res.website || {};
      (w.status === 'import_failed' ? message.warning : message.info)(websiteText(w), 8);
    }
    message.success(t('Successfully_Updated'));
    editOpen.value = false;
    crud.fetchRows();
  } catch (e) {
    serverErrTabs(e, editSteps.value, editErrTabs, editTab);
    message.error(errorText(e));
  } finally {
    submitting.value = false;
  }
}

/* ---------------- create ---------------- */
const createOpen = ref(false);
const createFormRef = ref();
const emptyCreate = () => ({
  ...emptyProfile(),
  currency_id: options.value.currencies?.[0]?.id,
  admin_firstname: '', admin_lastname: '', admin_email: '', admin_password: '', admin_role: 'Admin-ALL',
  with_helpdesk: true, helpdesk_firstname: 'Helpdesk', helpdesk_lastname: '', helpdesk_login_email: '', helpdesk_password: '',
  domains: '', website_mode: 'default', website_url: '',
  demo_data: false,
});

function websiteText(w) {
  if (!w) return '';
  if (w.status === 'imported') return `Website imported: ${w.pages} page(s), ${w.assets} file(s) — shown as the Home page in its original design.`;
  if (w.status === 'import_failed') return `The website could not be imported (${w.error}). The company uses the default online store instead${w.pages_created ? ` — ${w.pages_created} standard pages created` : ''}.`;
  return `Default online store${w.pages_created ? ` — ${w.pages_created} standard pages created (About Us, Contact Us, Shipping & Returns, Privacy Policy, FAQ)` : ''}.`;
}
const cform = ref(emptyCreate());
const roleOptions = sortByLabel([
  { value: 'Admin-ALL', label: 'Admin-ALL (full access)' },
  { value: 'Admin', label: 'Admin (standard)' },
  { value: 'Owner', label: 'Owner' },
]);
const createRules = computed(() => ({
  ...profileRules(),
  admin_firstname: [{ required: true, message: t('Field_is_required') }],
  admin_email: [{ required: true, message: t('Field_is_required') }, { type: 'email', message: t('InvalidData') }],
  admin_password: [{ required: true, min: 8, message: 'Min. 8 characters' }],
  ...(cform.value.with_helpdesk ? {
    helpdesk_login_email: [{ required: true, message: t('Field_is_required') }, { type: 'email', message: t('InvalidData') }],
    helpdesk_password: [{ required: true, min: 8, message: 'Min. 8 characters' }],
  } : {}),
}));

function openCreate() {
  cform.value = emptyCreate();
  createTab.value = 'company';
  createErrTabs.value = [];
  createOpen.value = true;
}

const resultOpen = ref(false);
const result = ref(null);

function summarize(created) {
  return Object.entries(created || {}).filter(([, n]) => Number(n) > 0).map(([l, n]) => `${n} ${l.toLowerCase()}`).join(', ');
}

async function submitCreate() {
  if (!(await validateSteps(createFormRef, createSteps.value, createErrTabs, createTab))) return;
  submitting.value = true;
  try {
    const { with_helpdesk: withHd, ...rest } = cform.value;
    const payload = profilePayload(rest);
    if (!withHd) ['helpdesk_firstname', 'helpdesk_lastname', 'helpdesk_login_email', 'helpdesk_password'].forEach(k => delete payload[k]);
    const res = await http.post('company', payload);
    result.value = {
      name: cform.value.name,
      admin_email: cform.value.admin_email,
      admin_username: res.admin_username,
      helpdesk_email: res.helpdesk_email || null,
      helpdesk_username: res.helpdesk_username || null,
      website: res.website || null,
      demo: res.demo
        ? Object.entries(res.demo).map(([key, r]) => ({ key, ...r, summary: summarize(r.created) }))
        : null,
    };
    createOpen.value = false;
    resultOpen.value = true;
    crud.fetchRows();
  } catch (e) {
    serverErrTabs(e, createSteps.value, createErrTabs, createTab);
    message.error(errorText(e));
  } finally {
    submitting.value = false;
  }
}

/* ---------------- clear demo ---------------- */
function clearDemo(record) {
  Modal.confirm({
    title: `Clear demo data of ${record.name}?`,
    icon: createVNode(ExclamationCircleOutlined),
    content: `Deletes the ${record.demo_count} demo rows generated for this company. Real data is not affected.`,
    okText: 'Clear',
    okType: 'danger',
    async onOk() {
      clearing.value = record.id;
      try {
        const res = await http.post(`company/${record.id}/clear-demo`);
        message.success(`Removed ${res.deleted ?? 0} demo rows.`);
        crud.fetchRows();
      } catch (e) {
        message.error(errorText(e));
      } finally {
        clearing.value = null;
      }
    },
  });
}

function errorText(e) {
  const errs = e?.data?.errors;
  const first = errs && Object.values(errs)[0];
  return (Array.isArray(first) ? first[0] : first) || e?.data?.message || t('InvalidData');
}

onMounted(crud.fetchRows);
</script>

<style scoped>
.section-title { font-weight: 600; margin: 4px 0 10px; }
/* Steps (tabs) of the Add / Edit company forms: "Step N" over the step name + a legend. */
.step-tab { display: inline-flex; flex-direction: column; align-items: flex-start; line-height: 1.2; }
.step-n { font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; opacity: 0.6; }
.step-name { font-weight: 600; }
.step-tab--err .step-n, .step-tab--err .step-name { color: #ff4d4f; opacity: 1; }
.step-tab--err .step-name::after { content: ' •'; }
.step-legend {
  font-size: 13px; padding: 8px 12px; margin-bottom: 14px; border-radius: 6px;
  background: rgba(22, 119, 255, 0.06); border-left: 3px solid #1677ff;
}
.muted { opacity: 0.6; font-weight: normal; }
.err { color: #ff4d4f; }
.demo-summary { max-height: 260px; overflow: auto; font-size: 13px; }
</style>
