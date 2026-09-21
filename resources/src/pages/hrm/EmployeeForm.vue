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
            <a-form-item :label="$t('Department')" name="department_id">
              <a-select
                v-model:value="form.department_id"
                :placeholder="$t('Department')"
                show-search
                option-filter-prop="label"
                :options="departmentOptions"
                @change="onDepartmentChange"
              />
            </a-form-item>
          </a-col>

          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Designation')" name="designation_id">
              <a-select
                v-model:value="form.designation_id"
                :placeholder="$t('Choose_Designation')"
                show-search
                option-filter-prop="label"
                :options="designationOptions"
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
import PageHeader from '../../components/PageHeader.vue';
import { GENDERS } from './hrmVocab';
import http from '../../lib/http';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();

const id = computed(() => route.params.id);
const isEdit = computed(() => !!id.value);

const loadingRecord = ref(true);
const submitting = ref(false);
const formRef = ref();

const companies = ref([]);
const departments = ref([]);
const designations = ref([]);
const officeShifts = ref([]);

const form = ref({
  firstname: '', lastname: '', gender: undefined, birth_date: null,
  email: '', country: '', phone: '', joining_date: null,
  company_id: undefined, department_id: undefined,
  designation_id: undefined, office_shift_id: undefined,
});

const companyOptions = computed(() => companies.value.map(c => ({ value: c.id, label: c.name })));
const departmentOptions = computed(() => departments.value.map(d => ({ value: d.id, label: d.department })));
const designationOptions = computed(() => designations.value.map(d => ({ value: d.id, label: d.designation })));
const officeShiftOptions = computed(() => officeShifts.value.map(s => ({ value: s.id, label: s.name })));

const rules = computed(() => ({
  firstname: [{ required: true, message: t('Field_is_required') }],
  lastname: [{ required: true, message: t('Field_is_required') }],
  gender: [{ required: true, message: t('Field_is_required') }],
  company_id: [{ required: true, message: t('Field_is_required') }],
  department_id: [{ required: true, message: t('Field_is_required') }],
  designation_id: [{ required: true, message: t('Field_is_required') }],
  office_shift_id: [{ required: true, message: t('Field_is_required') }],
}));

async function loadDepartments(companyId) {
  try {
    departments.value = await http.get('core/get_departments_by_company', { id: companyId }) || [];
  } catch (e) { departments.value = []; }
}

async function loadOfficeShifts(companyId) {
  try {
    officeShifts.value = await http.get('core/get_office_shift_by_company', { id: companyId }) || [];
  } catch (e) { officeShifts.value = []; }
}

async function loadDesignations(departmentId) {
  try {
    designations.value = await http.get('core/get_designations_by_department', { id: departmentId }) || [];
  } catch (e) { designations.value = []; }
}

function onCompanyChange(value) {
  form.value.department_id = undefined;
  form.value.designation_id = undefined;
  form.value.office_shift_id = undefined;
  departments.value = [];
  designations.value = [];
  officeShifts.value = [];
  if (value) {
    loadDepartments(value);
    loadOfficeShifts(value);
  }
}

function onDepartmentChange(value) {
  form.value.designation_id = undefined;
  designations.value = [];
  if (value) loadDesignations(value);
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }
  submitting.value = true;
  const payload = { ...form.value };
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
      departments.value = data.departments || [];
      designations.value = data.designations || [];
      officeShifts.value = data.office_shifts || [];
      const e = data.employee || {};
      form.value = {
        firstname: e.firstname || '', lastname: e.lastname || '',
        gender: e.gender || undefined, birth_date: e.birth_date || null,
        email: e.email || '', country: e.country || '', phone: e.phone || '',
        joining_date: e.joining_date || null,
        company_id: e.company_id || undefined, department_id: e.department_id || undefined,
        designation_id: e.designation_id || undefined, office_shift_id: e.office_shift_id || undefined,
      };
    } else {
      const data = await http.get('employees/create');
      companies.value = data.companies || [];
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
