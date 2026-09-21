<template>
  <div class="page">
    <PageHeader :title="$t('Employee_Details')" :breadcrumb="[$t('Employee'), $t('Employee_Details')]">
      <template #actions>
        <a-button @click="$router.push('/hrm/employees')">
          <template #icon><LeftOutlined /></template>
          {{ $t('Back') }}
        </a-button>
      </template>
    </PageHeader>

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-tabs v-else v-model:activeKey="tab">
      <!-- ============ Basic information ============ -->
      <a-tab-pane key="basic" :tab="$t('Basic_Information')">
        <a-card size="small">
          <a-form ref="basicRef" :model="employee" :rules="basicRules" layout="vertical">
            <a-row :gutter="16">
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('FirstName') + ' *'" name="firstname">
                  <a-input v-model:value="employee.firstname" :placeholder="$t('Enter_FirstName')" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('LastName') + ' *'" name="lastname">
                  <a-input v-model:value="employee.lastname" :placeholder="$t('Enter_LastName')" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Gender') + ' *'" name="gender">
                  <a-select
                    v-model:value="employee.gender" :placeholder="$t('Choose_Gender')"
                    :options="[{ value: 'male', label: 'Male' }, { value: 'female', label: 'Female' }]"
                  />
                </a-form-item>
              </a-col>

              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Family_status')">
                  <a-select
                    v-model:value="employee.marital_status" allow-clear
                    :placeholder="$t('Choose_Family_status')" :options="MARITAL"
                  />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Employment_type')">
                  <a-select
                    v-model:value="employee.employment_type" allow-clear
                    :placeholder="$t('Select_Employment_type')" :options="EMPLOYMENT_TYPES"
                  />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Birth_date')">
                  <a-date-picker v-model:value="employee.birth_date" value-format="YYYY-MM-DD" style="width: 100%" />
                </a-form-item>
              </a-col>

              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Email_Address')">
                  <a-input v-model:value="employee.email" :placeholder="$t('Enter_email_address')" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Phone')" name="phone">
                  <a-input v-model:value="employee.phone" :placeholder="$t('Enter_Phone_Number')" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Country')" name="country">
                  <a-input v-model:value="employee.country" :placeholder="$t('Enter_Country')" />
                </a-form-item>
              </a-col>

              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('City')">
                  <a-input v-model:value="employee.city" :placeholder="$t('Enter_City')" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Province')">
                  <a-input v-model:value="employee.province" :placeholder="$t('Enter_Province')" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Zip_code')">
                  <a-input v-model:value="employee.zipcode" :placeholder="$t('Enter_Zip_code')" />
                </a-form-item>
              </a-col>

              <a-col :span="24">
                <a-form-item :label="$t('Adress')">
                  <a-input v-model:value="employee.address" :placeholder="$t('Enter_Address')" />
                </a-form-item>
              </a-col>

              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('joining_date')">
                  <a-date-picker v-model:value="employee.joining_date" value-format="YYYY-MM-DD" style="width: 100%" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Leaving_Date')">
                  <a-date-picker v-model:value="employee.leaving_date" value-format="YYYY-MM-DD" style="width: 100%" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="4">
                <a-form-item :label="$t('Annual_Leave') + ' *'" name="total_leave">
                  <a-input-number v-model:value="employee.total_leave" :min="0" style="width: 100%" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="4">
                <a-form-item :label="$t('Remaining_leave')">
                  <a-input-number :value="employee.remaining_leave" disabled style="width: 100%" />
                </a-form-item>
              </a-col>

              <a-col :xs="24" :md="6">
                <a-form-item :label="$t('Company') + ' *'" name="company_id">
                  <a-select
                    v-model:value="employee.company_id" :placeholder="$t('Choose_Company')"
                    :options="companies.map(c => ({ value: c.id, label: c.name }))"
                    @change="onCompanyChange"
                  />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="6">
                <a-form-item :label="$t('Department') + ' *'" name="department_id">
                  <a-select
                    v-model:value="employee.department_id" :placeholder="$t('Department')"
                    :options="departments.map(d => ({ value: d.id, label: d.department }))"
                    @change="onDepartmentChange"
                  />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="6">
                <a-form-item :label="$t('Designation') + ' *'" name="designation_id">
                  <a-select
                    v-model:value="employee.designation_id" :placeholder="$t('Choose_Designation')"
                    :options="designations.map(d => ({ value: d.id, label: d.designation }))"
                  />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="6">
                <a-form-item :label="$t('Office_Shift') + ' *'" name="office_shift_id">
                  <a-select
                    v-model:value="employee.office_shift_id" :placeholder="$t('Choose_Office_Shift')"
                    :options="officeShifts.map(o => ({ value: o.id, label: o.name }))"
                  />
                </a-form-item>
              </a-col>

              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Hourly_rate')">
                  <a-input-number v-model:value="employee.hourly_rate" :min="0" style="width: 100%" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Basic_salary')">
                  <a-input-number v-model:value="employee.basic_salary" :min="0" style="width: 100%" />
                </a-form-item>
              </a-col>
            </a-row>

            <a-button type="primary" :loading="savingBasic" @click="saveBasic">{{ $t('submit') }}</a-button>
          </a-form>
        </a-card>
      </a-tab-pane>

      <!-- ============ Social ============ -->
      <a-tab-pane key="social" :tab="$t('Social_Media')">
        <a-card size="small">
          <a-form layout="vertical">
            <a-row :gutter="16">
              <a-col v-for="s in SOCIAL" :key="s.key" :xs="24" :md="8">
                <a-form-item :label="$t(s.label)">
                  <a-input v-model:value="employee[s.key]" :placeholder="$t(s.placeholder)" />
                </a-form-item>
              </a-col>
            </a-row>
            <a-button type="primary" :loading="savingSocial" @click="saveSocial">{{ $t('submit') }}</a-button>
          </a-form>
        </a-card>
      </a-tab-pane>

      <!-- ============ Experiences ============ -->
      <a-tab-pane key="experiences" :tab="$t('Experiences')">
        <a-card size="small">
          <a-button type="primary" style="margin-bottom: 12px" @click="openExperience()">
            <template #icon><PlusOutlined /></template>
            {{ $t('Add') }}
          </a-button>
          <a-table
            :columns="experienceColumns" :data-source="experiences.rows" :loading="experiences.loading"
            :pagination="{ current: experiences.page, pageSize: experiences.limit, total: experiences.total, showSizeChanger: false }"
            size="small" :row-key="r => r.id"
            @change="p => { experiences.page = p.current; fetchExperiences(); }"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'actions'">
                <a-space>
                  <a-button type="text" size="small" @click="openExperience(record)">
                    <template #icon><EditOutlined style="color: #52c41a" /></template>
                  </a-button>
                  <a-popconfirm :title="$t('Delete_Text')" @confirm="removeExperience(record.id)">
                    <a-button type="text" size="small" danger>
                      <template #icon><DeleteOutlined /></template>
                    </a-button>
                  </a-popconfirm>
                </a-space>
              </template>
            </template>
          </a-table>
        </a-card>
      </a-tab-pane>

      <!-- ============ Bank accounts ============ -->
      <a-tab-pane key="accounts" :tab="$t('bank_account')">
        <a-card size="small">
          <a-button type="primary" style="margin-bottom: 12px" @click="openAccount()">
            <template #icon><PlusOutlined /></template>
            {{ $t('Add') }}
          </a-button>
          <a-table
            :columns="accountColumns" :data-source="accounts.rows" :loading="accounts.loading"
            :pagination="{ current: accounts.page, pageSize: accounts.limit, total: accounts.total, showSizeChanger: false }"
            size="small" :row-key="r => r.id"
            @change="p => { accounts.page = p.current; fetchAccounts(); }"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'actions'">
                <a-space>
                  <a-button type="text" size="small" @click="openAccount(record)">
                    <template #icon><EditOutlined style="color: #52c41a" /></template>
                  </a-button>
                  <a-popconfirm :title="$t('Delete_Text')" @confirm="removeAccount(record.id)">
                    <a-button type="text" size="small" danger>
                      <template #icon><DeleteOutlined /></template>
                    </a-button>
                  </a-popconfirm>
                </a-space>
              </template>
            </template>
          </a-table>
        </a-card>
      </a-tab-pane>
    </a-tabs>

    <!-- Experience modal -->
    <a-modal
      v-model:open="expModal" :title="expForm.id ? $t('Edit') : $t('Add')"
      :confirm-loading="savingExp" width="720px" @ok="saveExperience"
    >
      <a-form ref="expRef" :model="expForm" :rules="expRules" layout="vertical">
        <a-row :gutter="16">
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('title') + ' *'" name="title">
              <a-input v-model:value="expForm.title" :placeholder="$t('Enter_title')" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Company_Name') + ' *'" name="company_name">
              <a-input v-model:value="expForm.company_name" :placeholder="$t('Enter_Company_Name')" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Location')">
              <a-input v-model:value="expForm.location" :placeholder="$t('Enter_location')" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Employment_type') + ' *'" name="employment_type">
              <a-select
                v-model:value="expForm.employment_type"
                :placeholder="$t('Select_Employment_type')" :options="EMPLOYMENT_TYPES"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('start_date') + ' *'" name="start_date">
              <a-date-picker v-model:value="expForm.start_date" value-format="YYYY-MM-DD" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Finish_Date') + ' *'" name="end_date">
              <a-date-picker v-model:value="expForm.end_date" value-format="YYYY-MM-DD" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="$t('Description')">
              <a-textarea v-model:value="expForm.description" :rows="3" :placeholder="$t('Enter_Description')" />
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>

    <!-- Bank account modal -->
    <a-modal
      v-model:open="accModal" :title="accForm.id ? $t('Edit') : $t('Add')"
      :confirm-loading="savingAcc" width="640px" @ok="saveAccount"
    >
      <a-form ref="accRef" :model="accForm" :rules="accRules" layout="vertical">
        <a-row :gutter="16">
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Bank_Name') + ' *'" name="bank_name">
              <a-input v-model:value="accForm.bank_name" :placeholder="$t('Enter_Bank_Name')" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Bank_Branch') + ' *'" name="bank_branch">
              <a-input v-model:value="accForm.bank_branch" :placeholder="$t('Enter_Bank_Branch')" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Bank_Number') + ' *'" name="account_no">
              <a-input v-model:value="accForm.account_no" :placeholder="$t('Enter_Bank_Number')" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="$t('Please_provide_any_details')">
              <a-textarea v-model:value="accForm.note" :rows="3" :placeholder="$t('Enter_Description')" />
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Employee detail — legacy employee_details.vue. Despite the name it is an
 * EDIT page: two forms (basic info, social links) plus CRUD tables for work
 * experience and bank accounts.
 *
 * Bugs from legacy that are not reproduced:
 * - the experiences pager was bound to a field the fetch never wrote, so it
 *   never showed more than page one;
 * - `accounts_bank` was never declared, making the bank table non-reactive;
 * - `remaining_leave` was omitted from the PUT while the server computes the
 *   new balance as `remaining_leave ± (new total − old total)`, so editing an
 *   employee collapsed their remaining leave to the raw delta. It is sent now.
 * - saving either form redirected back to the employee list; both now stay put
 *   and refresh, since this page is where you keep working.
 *
 * The cascading selects (company → department → designation, company → shift)
 * clear their dependents on change, as legacy did.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { LeftOutlined, PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();
const route = useRoute();
const id = route.params.id;

const loading = ref(true);
const tab = ref('basic');
const employee = ref({});
const companies = ref([]);
const departments = ref([]);
const designations = ref([]);
const officeShifts = ref([]);

const MARITAL = [
  { value: 'married', label: 'Married' },
  { value: 'single', label: 'Single' },
  { value: 'divorced', label: 'Divorced' },
];
const EMPLOYMENT_TYPES = [
  { value: 'full_time', label: 'Full-time' },
  { value: 'part_time', label: 'Part-time' },
  { value: 'self_employed', label: 'Self-employed' },
  { value: 'freelance', label: 'Freelance' },
  { value: 'contract', label: 'Contract' },
  { value: 'internship', label: 'Internship' },
  { value: 'apprenticeship', label: 'Apprenticeship' },
  { value: 'seasonal', label: 'Seasonal' },
];
const SOCIAL = [
  { key: 'skype', label: 'Skype', placeholder: 'Enter_Skype' },
  { key: 'facebook', label: 'Facebook', placeholder: 'Enter_Facebook' },
  { key: 'whatsapp', label: 'WhatsApp', placeholder: 'Enter_WhatsApp' },
  { key: 'linkedin', label: 'LinkedIn', placeholder: 'Enter_LinkedIn' },
  { key: 'twitter', label: 'Twitter', placeholder: 'Enter_Twitter' },
];

const req = () => ({ required: true, message: t('Field_is_required') });
const basicRef = ref();
const savingBasic = ref(false);
const savingSocial = ref(false);
const basicRules = computed(() => ({
  firstname: [req()], lastname: [req()], gender: [req()], total_leave: [req()],
  company_id: [req()], department_id: [req()], designation_id: [req()], office_shift_id: [req()],
  // The server requires these two even though legacy never validated them.
  country: [req()], phone: [req()],
}));

// ---------------- cascading selects ----------------

async function onCompanyChange(value) {
  employee.value.department_id = undefined;
  employee.value.designation_id = undefined;
  employee.value.office_shift_id = undefined;
  departments.value = [];
  designations.value = [];
  officeShifts.value = [];
  if (!value) return;
  const [d, s] = await Promise.allSettled([
    http.get('core/get_departments_by_company', { id: value }),
    http.get('core/get_office_shift_by_company', { id: value }),
  ]);
  if (d.status === 'fulfilled') departments.value = d.value || [];
  if (s.status === 'fulfilled') officeShifts.value = s.value || [];
}

async function onDepartmentChange(value) {
  employee.value.designation_id = undefined;
  designations.value = [];
  if (!value) return;
  try {
    designations.value = await http.get('core/get_designations_by_department', { id: value }) || [];
  } catch (e) { /* select stays empty */ }
}

// ---------------- saving ----------------

const BASIC_KEYS = [
  'firstname', 'lastname', 'country', 'email', 'gender', 'phone', 'birth_date',
  'company_id', 'department_id', 'designation_id', 'office_shift_id', 'joining_date',
  'leaving_date', 'marital_status', 'employment_type', 'city', 'province', 'address',
  'zipcode', 'hourly_rate', 'basic_salary', 'total_leave',
  // Sent so the server's remaining-leave arithmetic has a base to work from.
  'remaining_leave',
];

async function saveBasic() {
  try {
    await basicRef.value.validate();
  } catch (e) {
    message.error(t('Please_fill_the_form_correctly'));
    return;
  }
  savingBasic.value = true;
  const body = {};
  BASIC_KEYS.forEach(k => { body[k] = employee.value[k]; });
  try {
    await http.put(`employees/${employee.value.id}`, body);
    message.success(t('Updated_in_successfully'));
    await loadEmployee();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    savingBasic.value = false;
  }
}

async function saveSocial() {
  savingSocial.value = true;
  const body = {};
  SOCIAL.forEach(s => { body[s.key] = employee.value[s.key]; });
  try {
    await http.put(`update_social_profile/${employee.value.id}`, body);
    message.success(t('Updated_in_successfully'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    savingSocial.value = false;
  }
}

// ---------------- experiences ----------------

const experiences = reactive({ rows: [], total: 0, page: 1, limit: 10, loading: false });
const expModal = ref(false);
const savingExp = ref(false);
const expRef = ref();
const blankExp = () => ({
  id: null, title: '', company_name: '', employment_type: undefined,
  location: '', start_date: null, end_date: null, description: '',
});
const expForm = ref(blankExp());
const expRules = computed(() => ({
  title: [req()], company_name: [req()], employment_type: [req()],
  start_date: [req()], end_date: [req()],
}));

const experienceColumns = computed(() => [
  { title: t('title'), dataIndex: 'title', key: 'title' },
  { title: t('Company'), dataIndex: 'company_name', key: 'company_name' },
  { title: t('start_date'), dataIndex: 'start_date', key: 'start_date' },
  { title: t('Finish_Date'), dataIndex: 'end_date', key: 'end_date' },
  { title: t('Action'), key: 'actions', width: 110, align: 'center' },
]);

async function fetchExperiences() {
  experiences.loading = true;
  try {
    const data = await http.get('get_experiences_by_employee', {
      id, page: experiences.page, limit: experiences.limit,
    });
    experiences.rows = data?.experiences || [];
    experiences.total = Number(data?.totalRows) || 0;
  } catch (e) {
    experiences.rows = [];
  } finally {
    experiences.loading = false;
  }
}

/** Cloned, so editing and cancelling does not mutate the table row. */
function openExperience(row) {
  expForm.value = row ? { ...row } : blankExp();
  expModal.value = true;
}

async function saveExperience() {
  try {
    await expRef.value.validate();
  } catch (e) {
    message.error(t('Please_fill_the_form_correctly'));
    return;
  }
  savingExp.value = true;
  const body = {
    title: expForm.value.title,
    company_name: expForm.value.company_name,
    employee_id: employee.value.id,
    location: expForm.value.location,
    employment_type: expForm.value.employment_type,
    start_date: expForm.value.start_date,
    end_date: expForm.value.end_date,
    description: expForm.value.description,
  };
  try {
    if (expForm.value.id) {
      await http.put(`work_experience/${expForm.value.id}`, body);
      message.success(t('Updated_in_successfully'));
    } else {
      await http.post('work_experience', body);
      message.success(t('Created_in_successfully'));
    }
    expModal.value = false;
    await fetchExperiences();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    savingExp.value = false;
  }
}

async function removeExperience(expId) {
  try {
    await http.delete(`work_experience/${expId}`);
    message.success(t('Deleted_in_successfully'));
    await fetchExperiences();
  } catch (e) {
    message.error(t('Delete_Therewassomethingwronge'));
  }
}

// ---------------- bank accounts ----------------

const accounts = reactive({ rows: [], total: 0, page: 1, limit: 10, loading: false });
const accModal = ref(false);
const savingAcc = ref(false);
const accRef = ref();
const blankAcc = () => ({ id: null, bank_name: '', bank_branch: '', account_no: '', note: '' });
const accForm = ref(blankAcc());
const accRules = computed(() => ({
  bank_name: [req()], bank_branch: [req()], account_no: [req()],
}));

const accountColumns = computed(() => [
  { title: t('Bank_Name'), dataIndex: 'bank_name', key: 'bank_name' },
  { title: t('Bank_Branch'), dataIndex: 'bank_branch', key: 'bank_branch' },
  { title: t('Bank_Number'), dataIndex: 'account_no', key: 'account_no' },
  { title: t('Action'), key: 'actions', width: 110, align: 'center' },
]);

async function fetchAccounts() {
  accounts.loading = true;
  try {
    const data = await http.get('get_accounts_by_employee', {
      id, page: accounts.page, limit: accounts.limit,
    });
    accounts.rows = data?.accounts_bank || [];
    accounts.total = Number(data?.totalRows) || 0;
  } catch (e) {
    accounts.rows = [];
  } finally {
    accounts.loading = false;
  }
}

function openAccount(row) {
  accForm.value = row ? { ...row } : blankAcc();
  accModal.value = true;
}

async function saveAccount() {
  try {
    await accRef.value.validate();
  } catch (e) {
    message.error(t('Please_fill_the_form_correctly'));
    return;
  }
  savingAcc.value = true;
  const body = {
    employee_id: employee.value.id,
    bank_name: accForm.value.bank_name,
    bank_branch: accForm.value.bank_branch,
    account_no: accForm.value.account_no,
    note: accForm.value.note,
  };
  try {
    if (accForm.value.id) {
      await http.put(`employee_account/${accForm.value.id}`, body);
      message.success(t('Updated_in_successfully'));
    } else {
      await http.post('employee_account', body);
      message.success(t('Created_in_successfully'));
    }
    accModal.value = false;
    await fetchAccounts();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    savingAcc.value = false;
  }
}

async function removeAccount(accId) {
  try {
    await http.delete(`employee_account/${accId}`);
    message.success(t('Deleted_in_successfully'));
    await fetchAccounts();
  } catch (e) {
    message.error(t('Delete_Therewassomethingwronge'));
  }
}

// ---------------- load ----------------

async function loadEmployee() {
  const data = await http.get(`employees/${id}`);
  employee.value = data?.employee || {};
  companies.value = data?.companies || [];
  departments.value = data?.departments || [];
  designations.value = data?.designations || [];
  officeShifts.value = data?.office_shifts || [];
}

onMounted(async () => {
  try {
    await loadEmployee();
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    loading.value = false;
  }
  fetchExperiences();
  fetchAccounts();
});
</script>
