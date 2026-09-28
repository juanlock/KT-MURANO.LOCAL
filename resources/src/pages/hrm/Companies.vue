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
        <template v-if="column.key === 'website_mode'">
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

    <!-- Edit: just the company's own details. -->
    <a-modal
      v-model:open="editOpen"
      :title="$t('Edit')"
      :confirm-loading="submitting"
      :ok-text="$t('Submit')"
      :cancel-text="$t('Delete_cancelButtonText')"
      @ok="submitEdit"
    >
      <a-form ref="editFormRef" :model="form" :rules="companyRules" layout="vertical" style="margin-top: 8px">
        <a-form-item :label="$t('Name')" name="name"><a-input v-model:value="form.name" /></a-form-item>
        <a-form-item :label="$t('Phone')" name="phone"><a-input v-model:value="form.phone" /></a-form-item>
        <a-form-item :label="$t('Email')" name="email"><a-input v-model:value="form.email" type="email" /></a-form-item>
        <a-form-item :label="$t('Country')" name="country"><a-input v-model:value="form.country" /></a-form-item>
        <template v-if="isSuperAdmin">
          <div class="section-title">Website</div>
          <a-form-item
            label="Website domain(s)"
            extra="The company's own website address, e.g. kcoltech.com (www. optional; separate several with commas)."
          >
            <a-input v-model:value="form.domains" placeholder="example.com" />
          </a-form-item>
          <WebsiteChoice v-model:mode="form.website_mode" v-model:url="form.website_url" :current="form.current_website_mode" />
        </template>
      </a-form>
    </a-modal>

    <!-- Create (SuperAdmin): minimum information; the backend sets up the
         whole company (roles, settings, warehouse, walk-in customer, HR
         basics) plus its admin user + employee, and optionally demo data. -->
    <a-modal
      v-model:open="createOpen"
      :title="$t('Add') + ' ' + $t('Company')"
      :width="640"
      :confirm-loading="submitting"
      :ok-text="$t('Submit')"
      :cancel-text="$t('Delete_cancelButtonText')"
      @ok="submitCreate"
    >
      <a-form ref="createFormRef" :model="cform" :rules="createRules" layout="vertical" style="margin-top: 8px">
        <div class="section-title">Company</div>
        <a-row :gutter="16">
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Name')" name="name"><a-input v-model:value="cform.name" /></a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Email')" name="email"><a-input v-model:value="cform.email" type="email" /></a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Phone')" name="phone"><a-input v-model:value="cform.phone" /></a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Country')" name="country"><a-input v-model:value="cform.country" /></a-form-item>
          </a-col>
        </a-row>

        <div class="section-title">Admin user <span class="muted">(also created as an employee)</span></div>
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

        <div class="section-title">Website</div>
        <a-form-item
          label="Website domain(s)"
          extra="Optional — the company's own website address, e.g. newcompany.com (www. optional; separate several with commas)."
        >
          <a-input v-model:value="cform.domains" placeholder="example.com" />
        </a-form-item>
        <WebsiteChoice v-model:mode="cform.website_mode" v-model:url="cform.website_url" />

        <div class="section-title">Demo data</div>
        <a-checkbox v-model:checked="cform.demo_data">Fill this company with demo (example) data</a-checkbox>
        <a-alert
          v-if="cform.demo_data" type="warning" show-icon style="margin-top: 8px"
          message="Example records, not real data"
          description="Adds sample products, customers, suppliers, sales, purchases and examples in every enabled module, so the company can be explored right away. All of it is tagged as DEMO and can be removed at any time with Clear (in this list) or System Settings → Demo Data — real data is never touched."
        />
      </a-form>
    </a-modal>

    <!-- Result of a create -->
    <a-modal v-model:open="resultOpen" title="Company created" :footer="null" :width="560">
      <template v-if="result">
        <a-result status="success" :title="result.name" style="padding: 8px 0">
          <template #subTitle>
            Admin login: <strong>{{ result.admin_email }}</strong> (username {{ result.admin_username }})
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
import { useCrudTable } from '../../composables/useCrudTable';
import { useAuthStore } from '../../stores/auth';
import { sortByLabel } from '../../lib/filterOptions';
import http from '../../lib/http';

const { t } = useI18n();
const auth = useAuthStore();
const isSuperAdmin = computed(() => auth.user?.type === 'SuperAdmin');
const crud = useCrudTable('company', { rowsKey: 'companies' });

const columns = computed(() => [
  { title: t('Name'), dataIndex: 'name', key: 'name', sorter: true },
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

/* ---------------- edit ---------------- */
const editOpen = ref(false);
const editFormRef = ref();
const emptyForm = () => ({
  id: null, name: '', phone: '', email: '', country: '', domains: '',
  website_mode: 'default', website_url: '', current_website_mode: 'default',
});
const form = ref(emptyForm());
const companyRules = computed(() => ({
  name: [{ required: true, message: t('Field_is_required') }],
  email: [{ type: 'email', message: t('InvalidData') }],
}));

function openEdit(record) {
  form.value = {
    id: record.id, name: record.name || '', phone: record.phone || '', email: record.email || '',
    country: record.country || '', domains: record.domains || '',
    website_mode: record.website_mode || 'default', website_url: '',
    current_website_mode: record.website_mode || 'default',
  };
  editOpen.value = true;
}

async function submitEdit() {
  try { await editFormRef.value.validate(); } catch (e) { return; }
  submitting.value = true;
  try {
    const { id, website_mode, website_url, current_website_mode, ...body } = form.value;
    await http.put(`company/${id}`, body);
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
    message.error(errorText(e));
  } finally {
    submitting.value = false;
  }
}

/* ---------------- create ---------------- */
const createOpen = ref(false);
const createFormRef = ref();
const emptyCreate = () => ({
  name: '', email: '', phone: '', country: 'United States',
  admin_firstname: '', admin_lastname: '', admin_email: '', admin_password: '', admin_role: 'Admin-ALL',
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
  name: [{ required: true, message: t('Field_is_required') }],
  email: [{ type: 'email', message: t('InvalidData') }],
  admin_firstname: [{ required: true, message: t('Field_is_required') }],
  admin_email: [{ required: true, message: t('Field_is_required') }, { type: 'email', message: t('InvalidData') }],
  admin_password: [{ required: true, min: 8, message: 'Min. 8 characters' }],
}));

function openCreate() {
  cform.value = emptyCreate();
  createOpen.value = true;
}

const resultOpen = ref(false);
const result = ref(null);

function summarize(created) {
  return Object.entries(created || {}).filter(([, n]) => Number(n) > 0).map(([l, n]) => `${n} ${l.toLowerCase()}`).join(', ');
}

async function submitCreate() {
  try { await createFormRef.value.validate(); } catch (e) { return; }
  submitting.value = true;
  try {
    const res = await http.post('company', { ...cform.value });
    result.value = {
      name: cform.value.name,
      admin_email: cform.value.admin_email,
      admin_username: res.admin_username,
      website: res.website || null,
      demo: res.demo
        ? Object.entries(res.demo).map(([key, r]) => ({ key, ...r, summary: summarize(r.created) }))
        : null,
    };
    createOpen.value = false;
    resultOpen.value = true;
    crud.fetchRows();
  } catch (e) {
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
.muted { opacity: 0.6; font-weight: normal; }
.err { color: #ff4d4f; }
.demo-summary { max-height: 260px; overflow: auto; font-size: 13px; }
</style>
