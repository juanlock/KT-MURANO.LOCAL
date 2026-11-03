<template>
  <div class="page">
    <PageHeader :title="$t('Company')" :breadcrumb="[$t('HRM'), $t('Company')]">
      <template #actions>
        <a-button v-if="isSystem" type="primary" @click="openCreate">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <a-alert
      v-if="isSuperAdmin && !isSystem" type="info" show-icon style="margin-bottom: 12px"
      :message="$i18n.locale.startsWith('es')
        ? `Trabajando como ${companyStore.activeCompanyName || 'esta empresa'}: solo ves y editas esta empresa. Para ver todas, crear o borrar empresas, elige AdminSystem en el selector de arriba.`
        : `Working as ${companyStore.activeCompanyName || 'this company'}: you see and edit only this company. To see them all, or to add or delete companies, pick AdminSystem in the switcher above.`"
    />
    <DataTable :crud="crud" :columns="columns" :selectable="isSystem">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'logo'">
          <!-- The company's logo (Settings -> logo). -->
          <img v-if="record.logo_url" :src="record.logo_url" :alt="record.name" class="co-logo" loading="lazy" />
          <span v-else class="muted">—</span>
        </template>
        <template v-else-if="column.key === 'name'">
          {{ record.name }}
          <a-tooltip v-if="record.is_live" :title="liveText.hint">
            <a-tag color="green" style="margin-left: 6px">{{ liveText.tag }}</a-tag>
          </a-tooltip>
        </template>
        <template v-else-if="column.key === 'setup'">
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
            <a-tooltip v-if="isSuperAdmin" :title="L('Rename', 'Renombrar')">
              <a-button type="text" size="small" @click="openRename(record)">
                <template #icon><FontColorsOutlined style="color: #1677ff" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="isSystem" :title="$t('Del')">
              <a-button type="text" size="small" danger @click="crud.remove(record)">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>

    <!-- Rename (2026-11-02, SuperAdmin): the name everywhere it shows, in one step. -->
    <a-modal
      v-model:open="renameOpen" :title="L('Rename company', 'Renombrar compañía') + (renameRow ? ` · ${renameRow.name}` : '')"
      :ok-text="L('Rename', 'Renombrar')" :ok-button-props="{ disabled: !canRename }" :confirm-loading="renaming" :width="620" @ok="submitRename"
    >
      <a-form layout="vertical">
        <a-form-item :label="L('New name', 'Nombre nuevo')" required>
          <a-input v-model:value="renameForm.name" :maxlength="190" @update:value="schedulePreview" />
        </a-form-item>
      </a-form>
      <a-spin :spinning="previewLoading">
        <template v-if="preview">
          <p class="rn-always">
            {{ L('Always changes: the company name in Murano and on invoices, documents and emails.', 'Siempre cambia: el nombre de la compañía en Murano y en facturas, documentos y correos.') }}
          </p>
          <div class="rn-opt">
            <a-checkbox v-model:checked="renameForm.website">{{ L('Website and online store name', 'Nombre del sitio web y de la tienda en línea') }}</a-checkbox>
            <div class="rn-from">{{ preview.website.from || '—' }} → <b>{{ renameForm.name }}</b></div>
          </div>
          <div class="rn-opt">
            <a-checkbox v-model:checked="renameForm.helpdesk">{{ L('Helpdesk sender name', 'Nombre del remitente del helpdesk') }}</a-checkbox>
            <div class="rn-from">{{ preview.helpdesk.from || '—' }} → <b>{{ renameForm.name }}</b></div>
          </div>
          <div class="rn-opt">
            <a-checkbox v-model:checked="renameForm.path">{{ L('Website address', 'Dirección del sitio web') }}</a-checkbox>
            <div class="rn-from">/{{ preview.path.from || '—' }} →
              <a-input v-model:value="renameForm.pathValue" size="small" style="width: 240px" :disabled="!renameForm.path" addon-before="/" />
            </div>
            <div v-if="renameForm.path" class="rn-warn">{{ L('Links to the old address stop working (shared links, QR codes, Google).', 'Los enlaces a la dirección vieja dejan de funcionar (enlaces compartidos, códigos QR, Google).') }}</div>
          </div>
          <div class="rn-opt">
            <a-checkbox v-model:checked="renameForm.logins" :disabled="!preview.logins.list.length || preview.logins.taken.length > 0">
              {{ L('Standard logins (admin / helpdesk / info)', 'Logins estándar (admin / helpdesk / info)') }}
            </a-checkbox>
            <div v-for="l in preview.logins.list" :key="l.id" class="rn-from">{{ l.from }} → <b>{{ l.to }}</b></div>
            <div v-if="!preview.logins.list.length" class="rn-from">{{ L('This company has no standard logins.', 'Esta compañía no tiene logins estándar.') }}</div>
            <div v-if="preview.logins.taken.length" class="rn-warn">{{ L('Already used by other logins:', 'Ya los usan otros logins:') }} {{ preview.logins.taken.join(', ') }}</div>
            <div v-else-if="renameForm.logins" class="rn-warn">{{ L('Whoever uses them signs in with the new email from now on (same password).', 'Quien los usa entra desde ahora con el correo nuevo (la misma contraseña).') }}</div>
          </div>
        </template>
      </a-spin>
    </a-modal>

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
            <a-form-item v-if="st.key === 'company'" label="Logo" name="logo_data" extra="Optional — choose a new image to replace the logo (crop / resize before saving).">
              <LogoCropper v-model="form.logo_data" :current="form.logo_url" />
            </a-form-item>
            <template v-if="st.key === 'website'">
              <a-form-item
                label="Website domain(s)"
                extra="The company's own website address, e.g. kcoltech.com (www. optional; separate several with commas)."
              >
                <a-input v-model:value="form.domains" placeholder="example.com" />
              </a-form-item>
              <a-form-item v-if="isSystem" :label="liveText.label" :extra="liveText.hint">
                <a-switch v-model:checked="form.is_live" />
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
            <a-form-item v-if="st.key === 'company'" label="Logo" name="logo_data" extra="Required — on invoices, the menu, the customer portal and the website. Crop / resize it after choosing.">
              <LogoCropper v-model="cform.logo_data" />
            </a-form-item>

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
                  <a-form-item :label="$t('password')" name="helpdesk_password" extra="Empty = the standard password of new companies. Min. 8 characters.">
                    <a-input-password v-model:value="cform.helpdesk_password" autocomplete="new-password" />
                  </a-form-item>
                </a-col>
              </a-row>
            </template>

            <!-- Modules step: pre-ticked from the business type (BusinessTypes::modules), the rest by hand. -->
            <template v-else-if="st.key === 'modules'">
              <a-alert
                type="info" show-icon style="margin-bottom: 12px"
                :message="cform.business_type ? `Ticked: what a ${typeLabel(cform.business_type)} company uses. Add or remove any module.` : 'Choose the business type (step Company) to tick its modules.'"
                description="Always on (not switchable): sales, POS, purchases, products, customers, suppliers, reports and settings. Unticked modules stay hidden for this company until turned on in System Settings → Business Modules."
              />
              <div class="mod-tools">
                <a-space>
                  <a-button size="small" :disabled="!cform.business_type" @click="tickTypeModules">Modules of the business type</a-button>
                  <a-button size="small" @click="cform.modules = []">Clear</a-button>
                </a-space>
                <span class="muted">{{ cform.modules.length }} of {{ moduleChoices.length }} selected</span>
              </div>
              <a-checkbox-group v-model:value="cform.modules" class="mod-grid">
                <a-checkbox v-for="m in moduleChoices" :key="m.key" :value="m.key">
                  <a-tooltip :title="m.description"><span>{{ m.label }}</span></a-tooltip>
                  <a-tag v-if="typeModuleSet.has(m.key)" color="purple" class="mod-tag">type</a-tag>
                </a-checkbox>
              </a-checkbox-group>
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
                  <a-form-item :label="$t('password')" name="admin_password" extra="Empty = the standard password of new companies (also given to the -info and -helpdesk logins). Min. 8 characters.">
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
import { ref, computed, watch, onMounted, createVNode } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined, ExclamationCircleOutlined, FontColorsOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import WebsiteChoice from '../../components/WebsiteChoice.vue';
import CompanyProfileFields, { emptyProfile, profileRules, profilePayload, PROFILE_SECTIONS } from '../../components/CompanyProfileFields.vue';
import StepFooter from '../../components/StepFooter.vue';
import LogoCropper from '../../components/LogoCropper.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useAuthStore } from '../../stores/auth';
import { useCompanyStore } from '../../stores/company';
import { sortByLabel } from '../../lib/filterOptions';
import { TOGGLEABLE_MODULES } from '../../config/modules';
import http from '../../lib/http';

const { t, locale } = useI18n();
const auth = useAuthStore();
const isSuperAdmin = computed(() => auth.user?.type === 'SuperAdmin');
// superadmin/AdminSystem (2026-10-21): every company, Add and Delete; with a
// company picked in the switcher the SuperAdmin sees and edits only that one.
const companyStore = useCompanyStore();
const isSystem = computed(() => isSuperAdmin.value && [999, null, undefined, 0].includes(companyStore.actingCompanyId === null ? null : Number(companyStore.actingCompanyId)));
const crud = useCrudTable('company', { rowsKey: 'companies' });

// Dropdown data for the profile (business types, currencies, languages) -- shipped with the list.
const options = computed(() => crud.payload.value?.options || {});

const columns = computed(() => [
  { title: 'Logo', key: 'logo', width: 90, align: 'center', exportValue: r => r.logo_url || '' },
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
  modules: { key: 'modules', title: 'Modules', legend: 'What the company uses: its business type ticks the usual modules; tick any other it needs. Can be changed later in System Settings → Business Modules.' },
  defaults: { key: 'defaults', title: 'Defaults', profile: true, legend: 'How the company works by default: currency, language, invoice numbers and the starting passwords for new logins.' },
  admin: { key: 'admin', title: 'Admin user', legend: 'The company\'s first login: it manages everything else (also created as an employee).' },
  website: { key: 'website', title: 'Website', legend: 'The company\'s own domain and website: import the existing site or use the default online store.' },
};
const editSteps = computed(() => [STEP.company, { ...STEP.helpdesk, legend: STEP.helpdesk.legend + '.' }, STEP.defaults, ...(isSuperAdmin.value ? [STEP.website] : [])]);
const createSteps = computed(() => [
  STEP.company,
  STEP.modules,
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
  domains: 'website', website_url: 'website', module_flags: 'modules', logo_data: 'company',
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
  id: null, ...emptyProfile(), domains: '', logo_data: '', logo_url: '', is_live: false,
  website_mode: 'default', website_url: '', current_website_mode: 'default',
});
// "In production" (2026-10-26): production owns its data; the dev -> production push never sends it.
const liveText = computed(() => (locale.value.startsWith('es')
  ? { tag: 'En producción', label: 'Compañía en producción', hint: 'Sus datos viven en producción: lo que se haga en una PC de desarrollo nunca se sube para esta compañía.' }
  : { tag: 'In production', label: 'Company in production', hint: 'Its data lives in production: nothing done on a development PC is ever sent up for this company.' }));
const form = ref(emptyForm());
const loadingEdit = ref(false);
const editMissing = ref([]);
const companyRules = computed(() => profileRules());

// Edit loads the whole profile (GET company/{id}): companies + settings + helpdesk.
async function openEdit(record) {
  form.value = { ...emptyForm(), id: record.id, name: record.name || '', is_live: !!record.is_live, website_mode: record.website_mode || 'default', current_website_mode: record.website_mode || 'default' };
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
      logo_url: p.logo_url || '', logo_data: '',
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
    const { id, website_mode, website_url, current_website_mode, domains, logo_data, logo_url, is_live, ...profile } = form.value;
    await http.put(`company/${id}`, {
      ...profilePayload(profile), ...(isSuperAdmin.value ? { domains } : {}), ...(isSystem.value ? { is_live } : {}), ...(logo_data ? { logo_data } : {}),
    });
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
  // Language: the one the SuperAdmin is working in (2026-10-20: Bicentenario was
  // created in English because it started as AdminSystem's), else AdminSystem's.
  // Currency starts as the AdminSystem company's (template 999).
  default_language: (options.value.languages || []).some(l => l.locale === locale.value)
    ? locale.value
    : (options.value.template_defaults?.default_language || 'en'),
  currency_id: options.value.template_defaults?.currency_id || options.value.currencies?.[0]?.id,
  admin_firstname: '', admin_lastname: '', admin_email: '', admin_password: '', admin_role: 'IT Admin',
  with_helpdesk: true, helpdesk_firstname: 'Helpdesk', helpdesk_lastname: '', helpdesk_login_email: '', helpdesk_password: '',
  domains: '', website_mode: 'default', website_url: '',
  demo_data: false,
  modules: [],
  logo_data: '',
});

// Modules step: every switchable module (alphabetical), pre-ticked from the business type.
const moduleChoices = sortByLabel(TOGGLEABLE_MODULES.map(m => ({ ...m })));
const typeLabel = v => ((options.value.business_types || []).find(t => t.value === v) || {}).label || v;
const typeModules = v => ((options.value.business_types || []).find(t => t.value === v) || {}).modules || [];
const typeModuleSet = computed(() => new Set(typeModules(cform.value.business_type)));
function tickTypeModules() {
  cform.value.modules = [...typeModules(cform.value.business_type)];
}

function websiteText(w) {
  if (!w) return '';
  if (w.status === 'imported') return `Website imported: ${w.pages} page(s), ${w.assets} file(s) — shown as the Home page in its original design.`;
  if (w.status === 'import_failed') return `The website could not be imported (${w.error}). The company uses the default online store instead${w.pages_created ? ` — ${w.pages_created} standard pages created` : ''}.`;
  return `Default online store${w.pages_created ? ` — ${w.pages_created} standard pages created (About Us, Contact Us, Shipping & Returns, Privacy Policy, FAQ)` : ''}.`;
}
const cform = ref(emptyCreate());
// Choosing (or changing) the business type ticks its modules again. (After
// cform exists: declared earlier, its getter failed and it never fired --
// AquaExpress was created with every module off, 2026-10-19.)
watch(() => cform.value.business_type, v => { if (v) tickTypeModules(); });
// Every company has one admin role now (App\Support\CompanyRoles, 2026-11-01).
const roleOptions = [{ value: 'IT Admin', label: 'IT Admin' }];
const createRules = computed(() => ({
  ...profileRules(),
  logo_data: [{ required: true, message: 'Upload the company logo' }],
  admin_firstname: [{ required: true, message: t('Field_is_required') }],
  admin_email: [{ required: true, message: t('Field_is_required') }, { type: 'email', message: t('InvalidData') }],
  admin_password: [{ min: 8, message: 'Min. 8 characters' }],
  ...(cform.value.with_helpdesk ? {
    helpdesk_login_email: [{ required: true, message: t('Field_is_required') }, { type: 'email', message: t('InvalidData') }],
    helpdesk_password: [{ min: 8, message: 'Min. 8 characters' }],
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
  if (!cform.value.modules.length) {
    createErrTabs.value = ['modules'];
    createTab.value = 'modules';
    message.warning('Tick the modules this company uses (or "Modules of the business type").');
    return;
  }
  submitting.value = true;
  try {
    const { with_helpdesk: withHd, modules, ...rest } = cform.value;
    const payload = profilePayload(rest);
    // Every switchable module, on or off (ticked = on).
    payload.module_flags = Object.fromEntries(TOGGLEABLE_MODULES.map(m => [m.key, modules.includes(m.key)]));
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

/* ---------------- rename (2026-11-02) ---------------- */
const L = (en, es) => (locale.value.startsWith('es') ? es : en);
const renameOpen = ref(false);
const renameRow = ref(null);
const renaming = ref(false);
const preview = ref(null);
const previewLoading = ref(false);
const renameForm = ref({ name: '', website: true, helpdesk: true, path: false, pathValue: '', logins: false });
let previewTimer = null;
const canRename = computed(() => !!renameForm.value.name.trim() && renameForm.value.name.trim() !== (renameRow.value?.name || '') && !!preview.value);

function openRename(record) {
  renameRow.value = record;
  renameForm.value = { name: record.name, website: true, helpdesk: true, path: false, pathValue: '', logins: false };
  preview.value = null;
  renameOpen.value = true;
  loadPreview(true);
}
function schedulePreview() {
  clearTimeout(previewTimer);
  previewTimer = setTimeout(() => loadPreview(false), 400);
}
async function loadPreview(first) {
  if (!renameRow.value || !renameForm.value.name.trim()) return;
  previewLoading.value = true;
  try {
    const res = await http.get(`company/${renameRow.value.id}/rename`, { name: renameForm.value.name.trim() });
    preview.value = res;
    if (first) {
      renameForm.value.website = res.website.default;
      renameForm.value.helpdesk = res.helpdesk.default;
    }
    renameForm.value.pathValue = res.path.to || '';
    if (res.logins.taken.length) renameForm.value.logins = false;
  } catch (e) {
    message.error(errorText(e));
  } finally {
    previewLoading.value = false;
  }
}
async function submitRename() {
  renaming.value = true;
  try {
    const f = renameForm.value;
    await http.post(`company/${renameRow.value.id}/rename`, {
      name: f.name.trim(), website: f.website, helpdesk: f.helpdesk, logins: f.logins, path: f.path ? (f.pathValue || true) : null,
    });
    message.success(L('Company renamed.', 'Compañía renombrada.'));
    renameOpen.value = false;
    crud.fetchRows();
  } catch (e) {
    message.error(errorText(e));
  } finally {
    renaming.value = false;
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
/* Logo column: fits the row, keeps the logo's proportions. */
.co-logo { max-width: 72px; max-height: 32px; object-fit: contain; display: block; margin: 0 auto; }
/* Modules step: two columns of checkboxes (one on phones). */
.mod-tools { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 10px; }
.mod-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 6px 16px; width: 100%; }
.mod-grid :deep(.ant-checkbox-wrapper) { margin-inline-start: 0; }
.mod-tag { margin-inline-start: 6px; font-size: 11px; line-height: 16px; padding: 0 4px; }
@media (max-width: 576px) { .mod-grid { grid-template-columns: 1fr; } }
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
/* Rename window */
.rn-always { font-size: 13px; background: rgba(22, 119, 255, 0.06); border-left: 3px solid #1677ff; padding: 8px 12px; border-radius: 6px; }
.rn-opt { padding: 10px 0; border-bottom: 1px solid #f0f0f0; }
.rn-from { font-size: 12.5px; color: #64748b; margin: 4px 0 0 24px; word-break: break-all; }
.rn-warn { font-size: 12.5px; color: #b45309; margin: 4px 0 0 24px; }
.err { color: #ff4d4f; }
.demo-summary { max-height: 260px; overflow: auto; font-size: 13px; }
</style>
