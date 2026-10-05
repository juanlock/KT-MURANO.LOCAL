<template>
  <div class="page">
    <PageHeader
      :title="isEdit ? $t('Edit') : $t('Add_Employee')"
      :breadcrumb="[$t('Employees'), isEdit ? $t('Edit') : $t('Add')]"
    />

    <div v-if="loadingRecord" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-card v-else>
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
        <a-row :gutter="16">
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('FirstName')" name="firstname">
              <a-input v-model:value="form.firstname" :placeholder="$t('Enter_FirstName')" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('LastName')" name="lastname">
              <a-input v-model:value="form.lastname" :placeholder="$t('Enter_LastName')" />
            </a-form-item>
          </a-col>

          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Gender')" name="gender">
              <a-select v-model:value="form.gender" :placeholder="$t('Choose_Gender')" :options="GENDERS" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Birth_date')" name="birth_date">
              <a-date-picker v-model:value="form.birth_date" value-format="YYYY-MM-DD" style="width: 100%" />
            </a-form-item>
          </a-col>

          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Email_Address')" name="email">
              <a-input v-model:value="form.email" :placeholder="$t('Enter_email_address')" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Country')" name="country">
              <a-input v-model:value="form.country" :placeholder="$t('Enter_Country')" />
            </a-form-item>
          </a-col>

          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Phone')" name="phone">
              <a-input v-model:value="form.phone" :placeholder="$t('Enter_Phone_Number')" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('joining_date')" name="joining_date">
              <a-date-picker v-model:value="form.joining_date" value-format="YYYY-MM-DD" style="width: 100%" />
            </a-form-item>
          </a-col>

          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Company')" name="company_id">
              <a-select
                v-model:value="form.company_id"
                :placeholder="$t('Choose_Company')"
                show-search
                option-filter-prop="label"
                :options="companyOptions"
                @change="onCompanyChange"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Office_Shift')" name="office_shift_id">
              <a-select
                v-model:value="form.office_shift_id"
                :placeholder="$t('Choose_Office_Shift')"
                show-search
                option-filter-prop="label"
                :options="officeShiftOptions"
              />
            </a-form-item>
          </a-col>
        </a-row>

        <!-- Positions: one person = one employee, who can work in several
             departments / designations (e.g. Nail Artist + Acrylics expert).
             The first one is the primary position. -->
        <a-card size="small" class="access-card">
          <div class="access-title" style="margin-bottom: 8px">{{ $t('Department') }} / {{ $t('Designation') }}</div>
          <a-row v-for="(p, i) in positions" :key="i" :gutter="8" style="margin-bottom: 8px" align="middle">
            <a-col :xs="24" :md="10">
              <a-select
                v-model:value="p.department_id" style="width: 100%" show-search option-filter-prop="label"
                :placeholder="$t('Department')" :options="departmentOptions" :disabled="!form.company_id"
                @change="p.designation_id = undefined"
              />
            </a-col>
            <a-col :xs="20" :md="11">
              <a-select
                v-model:value="p.designation_id" style="width: 100%" show-search option-filter-prop="label"
                :placeholder="$t('Choose_Designation')" :options="designationOptionsFor(p.department_id)" :disabled="!p.department_id"
              />
            </a-col>
            <a-col :xs="4" :md="3" style="display: flex; gap: 6px; align-items: center">
              <a-tag v-if="i === 0" color="blue">Primary</a-tag>
              <a-button v-else type="text" danger size="small" @click="positions.splice(i, 1)"><DeleteOutlined /></a-button>
            </a-col>
          </a-row>
          <div v-if="positionsError" style="color: #cf1322; font-size: 12px; margin-bottom: 6px">{{ positionsError }}</div>
          <a-button type="dashed" size="small" :disabled="!form.company_id" @click="positions.push({ department_id: undefined, designation_id: undefined })">
            <PlusOutlined /> Add position
          </a-button>
        </a-card>

        <!-- System access: the employee's own login and the company role that
             decides what they can see and do (Users & Roles). -->
        <a-card size="small" class="access-card">
          <div class="access-head">
            <div>
              <div class="access-title"><SafetyCertificateOutlined /> System access</div>
              <div class="access-help">
                <template v-if="access.has_login">Login: <b>{{ access.username }}</b>. </template>
                Turn on to let this employee sign in to the system with the role you choose.
              </div>
            </div>
            <a-switch v-model:checked="access.enabled" checked-children="Can sign in" un-checked-children="No access" />
          </div>
          <a-row v-if="access.enabled" :gutter="16" style="margin-top: 12px">
            <a-col :xs="24" :md="12">
              <a-form-item :label="$t('RoleName') + ' *'" :validate-status="accessErr.role_id ? 'error' : undefined" :help="accessErr.role_id">
                <a-select
                  v-model:value="access.role_id" show-search option-filter-prop="label"
                  :placeholder="form.company_id ? $t('PleaseSelect') : $t('Choose_Company')"
                  :options="roleOptions" :disabled="!form.company_id"
                />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="12">
              <a-form-item :label="$t('username')" :validate-status="accessErr.username ? 'error' : undefined"
                :help="accessErr.username || 'Unique in the whole system. Empty on a new login = made from the name.'">
                <a-input v-model:value="access.username" autocomplete="off" placeholder="e.g. amaya" />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="12">
              <a-form-item label="Login email *" :validate-status="accessErr.email ? 'error' : undefined" :help="accessErr.email">
                <a-input v-model:value="access.email" type="email" autocomplete="off" placeholder="name@company.com" />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="12">
              <a-form-item
                :label="access.has_login ? $t('password') : $t('password') + ' *'"
                :validate-status="accessErr.password ? 'error' : undefined"
                :help="accessErr.password || (access.has_login ? 'Leave blank to keep the current password.' : 'At least 6 characters.')"
              >
                <a-input-password v-model:value="access.password" autocomplete="new-password" />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="12">
              <a-form-item label="Cashier PIN" :validate-status="accessErr.pin ? 'error' : undefined"
                :help="accessErr.pin || 'Optional: 4-8 digits to open / close POS registers. Unique in the company.'">
                <a-input v-model:value="access.pin" :maxlength="8" inputmode="numeric" autocomplete="off" allow-clear />
              </a-form-item>
            </a-col>
          </a-row>
        </a-card>

        <!-- Other modules: the same person in the modules that work with
             employees. Saved with the employee (Submit). -->
        <a-card size="small" class="access-card">
          <div class="access-title"><AppstoreAddOutlined /> Other modules</div>
          <div class="access-help" style="margin-bottom: 10px">Add this employee to other modules. Changes are saved when you submit.</div>
          <div v-for="m in SALES_MODULES" :key="m.key" class="module-row">
            <div>
              <div style="font-weight: 500">{{ m.label }}</div>
              <div class="access-help">{{ m.help }}</div>
            </div>
            <a-space v-if="salesRoles[m.key]">
              <a-tag color="green" style="margin: 0"><CheckOutlined /> {{ m.label }}</a-tag>
              <a-button type="link" danger size="small" @click="salesRoles[m.key] = false">Remove</a-button>
            </a-space>
            <a-button v-else size="small" @click="salesRoles[m.key] = true">
              <PlusOutlined /> Add to {{ m.label }}
            </a-button>
          </div>
        </a-card>

        <a-space style="margin-top: 8px">
          <a-button type="primary" :loading="submitting" @click="submit">{{ $t('submit') }}</a-button>
          <a-button @click="$router.push('/hrm/employees')">{{ $t('Cancel') }}</a-button>
        </a-space>
      </a-form>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Create: GET employees/create → {companies}; POST employees (JSON).
 * Edit: GET employees/{id}/edit → {employee, companies, departments,
 * designations, office_shifts}; PUT employees/{id}.
 * Cascades (same as legacy): company → core/get_departments_by_company +
 * core/get_office_shift_by_company; department →
 * core/get_designations_by_department. Changing company clears the three
 * dependents; changing department clears designation.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { SafetyCertificateOutlined, PlusOutlined, DeleteOutlined, AppstoreAddOutlined, CheckOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { GENDERS } from './hrmVocab';
import http from '../../lib/http';
import { sortByLabel } from '../../lib/filterOptions';
import { useAuthStore } from '../../stores/auth';

// Service Technician button: only with the company's Service module.
const auth = useAuthStore();

const { t } = useI18n();
const route = useRoute();
const router = useRouter();

const id = computed(() => route.params.id);
const isEdit = computed(() => !!id.value);

const loadingRecord = ref(true);
const submitting = ref(false);
const formRef = ref();

const companies = ref([]);
const officeShifts = ref([]);

const form = ref({
  firstname: '', lastname: '', gender: undefined, birth_date: null,
  email: '', country: '', phone: '', joining_date: null,
  company_id: undefined, department_id: undefined,
  designation_id: undefined, office_shift_id: undefined,
});

const companyOptions = computed(() => sortByLabel(companies.value.map(c => ({ value: c.id, label: c.name }))));
const officeShiftOptions = computed(() => sortByLabel(officeShifts.value.map(s => ({ value: s.id, label: s.name }))));

// Positions: [{department_id, designation_id}], first = primary. Options are
// the company's departments / designations (from all_departments / all_designations).
const allDepartments = ref([]);
const allDesignations = ref([]);
const positions = ref([{ department_id: undefined, designation_id: undefined }]);
const positionsError = ref('');
const departmentOptions = computed(() => sortByLabel(allDepartments.value
  .filter(d => Number(d.company_id) === Number(form.value.company_id))
  .map(d => ({ value: d.id, label: d.department }))));
function designationOptionsFor(departmentId) {
  return sortByLabel(allDesignations.value
    .filter(d => Number(d.department_id) === Number(departmentId))
    .map(d => ({ value: d.id, label: d.designation })));
}

// System access (login + role) -- saved with the employee as `access`.
const roles = ref([]);
const access = ref({ enabled: false, has_login: false, username: '', email: '', password: '', role_id: undefined, pin: '' });
const originalUsername = ref('');
const accessErr = ref({});
const roleOptions = computed(() => sortByLabel(roles.value
  .filter(r => !form.value.company_id || Number(r.company_id) === Number(form.value.company_id))
  .map(r => ({ value: r.id, label: r.name }))));

// Other modules card -- saved with the employee as `sales_roles`.
const SALES_MODULES = computed(() => [
  { key: 'sales_staff', label: 'Sales Staff', help: 'Can be picked as the Seller / Salesperson on sales and in the POS.' },
  { key: 'sales_agent', label: 'Sales Agent', help: 'Commissions sales agent, selectable as Sales Agent on sales. Remove = deactivate (history stays).' },
  // Service module only: a technician for Service Jobs (no login needed).
  ...(auth.moduleEnabled('service') ? [{ key: 'service_technician', label: 'Service Technician', help: 'Can be assigned to Service Jobs and contract visits (technician / crew lead). Remove = deactivate (their jobs stay).' }] : []),
]);
const salesRoles = ref({ sales_staff: false, sales_agent: false, service_technician: false });

const rules = computed(() => ({
  firstname: [{ required: true, message: t('Field_is_required') }],
  gender: [{ required: true, message: t('Field_is_required') }],
  company_id: [{ required: true, message: t('Field_is_required') }],
  office_shift_id: [{ required: true, message: t('Field_is_required') }],
}));

async function loadOfficeShifts(companyId) {
  try {
    officeShifts.value = await http.get('core/get_office_shift_by_company', { id: companyId }) || [];
  } catch (e) { officeShifts.value = []; }
}

function onCompanyChange(value) {
  // Roles are per company: another company's role can't stay selected.
  if (!roleOptions.value.some(o => o.value === access.value.role_id)) access.value.role_id = undefined;
  // Positions and shift belong to the company too.
  positions.value = [{ department_id: undefined, designation_id: undefined }];
  form.value.office_shift_id = undefined;
  officeShifts.value = [];
  if (value) loadOfficeShifts(value);
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }
  // At least one complete position; the same designation only once.
  const filled = positions.value.filter(p => p.department_id && p.designation_id);
  const seen = new Set();
  positionsError.value = !filled.length
    ? 'Add at least one department and designation.'
    : (filled.some(p => (seen.has(p.designation_id) ? true : (seen.add(p.designation_id), false))) ? 'Each designation only once.' : '');
  if (positionsError.value) return;

  submitting.value = true;
  accessErr.value = {};
  const payload = {
    ...form.value,
    department_id: filled[0].department_id,
    designation_id: filled[0].designation_id,
    positions: filled.map(p => ({ department_id: p.department_id, designation_id: p.designation_id })),
    access: {
      enabled: access.value.enabled,
      role_id: access.value.role_id || null,
      // Only when changed (blank = keep / auto-generated for a new login).
      username: (access.value.username || '').trim() !== originalUsername.value ? (access.value.username || '').trim() : '',
      email: (access.value.email || '').trim(),
      password: access.value.password || '',
      pin: (access.value.pin || '').trim(),
    },
    sales_roles: { ...salesRoles.value },
  };
  try {
    if (isEdit.value) {
      await http.put(`employees/${id.value}`, payload);
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('employees', payload);
      message.success(t('Successfully_Created'));
    }
    router.push('/hrm/employees');
  } catch (e) {
    const errors = e?.data?.errors;
    // System access errors show under their own fields.
    if (errors) {
      Object.entries(errors).forEach(([k, v]) => {
        if (k.startsWith('access.')) accessErr.value[k.slice(7)] = [].concat(v)[0];
      });
    }
    if (errors) Object.values(errors).flat().forEach(msg => message.error(String(msg)));
    else message.error(e?.data?.message || t('InvalidData'));
  } finally {
    submitting.value = false;
  }
}

async function bootstrap() {
  loadingRecord.value = true;
  try {
    if (isEdit.value) {
      const data = await http.get(`employees/${id.value}/edit`);
      companies.value = data.companies || [];
      allDepartments.value = data.all_departments || [];
      allDesignations.value = data.all_designations || [];
      officeShifts.value = data.office_shifts || [];
      positions.value = (data.positions && data.positions.length)
        ? data.positions.map(p => ({ department_id: p.department_id, designation_id: p.designation_id }))
        : [{ department_id: data.employee?.department_id, designation_id: data.employee?.designation_id }];
      const e = data.employee || {};
      form.value = {
        firstname: e.firstname || '', lastname: e.lastname || '',
        gender: e.gender || undefined, birth_date: e.birth_date || null,
        email: e.email || '', country: e.country || '', phone: e.phone || '',
        joining_date: e.joining_date || null,
        company_id: e.company_id || undefined, department_id: e.department_id || undefined,
        designation_id: e.designation_id || undefined, office_shift_id: e.office_shift_id || undefined,
      };
      roles.value = data.roles || [];
      const a = data.access || {};
      access.value = {
        enabled: !!a.enabled, has_login: !!a.has_login, username: a.username || '',
        email: a.email || '', password: '', role_id: a.role_id || undefined, pin: a.pin || '',
      };
      originalUsername.value = a.username || '';
      const sr = data.sales_roles || {};
      salesRoles.value = { sales_staff: !!sr.sales_staff, sales_agent: !!sr.sales_agent, service_technician: !!sr.service_technician };
    } else {
      const data = await http.get('employees/create');
      companies.value = data.companies || [];
      roles.value = data.roles || [];
      allDepartments.value = data.all_departments || [];
      allDesignations.value = data.all_designations || [];
      // One company offered (Admin, or SuperAdmin with a company in the
      // header switcher): pick it and load its shifts.
      if (companies.value.length === 1) {
        form.value.company_id = companies.value[0].id;
        loadOfficeShifts(form.value.company_id);
      }
    }
  } catch (e) {
    if (isEdit.value) {
      message.error(t('InvalidData'));
      router.push('/hrm/employees');
    } else {
      message.warning(t('InvalidData'));
    }
  } finally {
    loadingRecord.value = false;
  }
}

onMounted(bootstrap);
</script>

<style scoped>
.access-card { margin-top: 8px; margin-bottom: 8px; background: rgba(22, 119, 255, 0.03); }
.access-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
.access-title { font-weight: 600; }
.access-help { font-size: 12px; opacity: 0.65; }
.module-row { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 8px 0; border-top: 1px solid rgba(0, 0, 0, 0.06); }
</style>
