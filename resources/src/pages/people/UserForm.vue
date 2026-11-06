<template>
  <div class="page">
    <PageHeader
      :title="isEdit ? $t('Edit') : $t('Add')"
      :breadcrumb="[$t('User_Management'), $t('Users'), isEdit ? $t('Edit') : $t('Add')]"
    />

    <div v-if="loadingRecord" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-form v-else ref="formRef" :model="form" :rules="rules" layout="vertical">
      <a-row :gutter="16">
        <!-- ============ Profile panel ============ -->
        <a-col :xs="24" :md="8" :lg="7">
          <a-card class="profile-card">
            <div class="profile-avatar">
              <img v-if="avatarPreview" :src="avatarPreview" alt="avatar" />
              <span v-else class="profile-initials">{{ initials }}</span>
            </div>
            <div class="profile-name">{{ displayName }}</div>
            <div class="profile-role">
              <a-tag v-if="roleName" color="purple">{{ roleName }}</a-tag>
            </div>

            <a-upload
              :file-list="fileList"
              :before-upload="beforeUpload"
              :max-count="1"
              accept="image/*"
              :show-upload-list="false"
              @remove="clearAvatar"
            >
              <a-button block>
                <template #icon><UploadOutlined /></template>{{ $t('UserImage') }}
              </a-button>
            </a-upload>
            <a-button
              v-if="avatarPreview" type="text" danger size="small" block
              style="margin-top: 8px" @click="clearAvatar"
            >
              {{ $t('Delete') }}
            </a-button>
          </a-card>
        </a-col>

        <!-- ============ Fields ============ -->
        <a-col :xs="24" :md="16" :lg="17">
          <a-card>
            <div class="section-title">
              <IdcardOutlined /> {{ $t('Account_Information') }}
            </div>
            <a-row :gutter="16">
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Firstname')" name="firstname">
                  <a-input v-model:value="form.firstname" :placeholder="$t('Enter_Firstname')" allow-clear>
                    <template #prefix><UserOutlined class="field-prefix" /></template>
                  </a-input>
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('lastname')" name="lastname">
                  <a-input v-model:value="form.lastname" :placeholder="$t('Enter_Lastname')" allow-clear>
                    <template #prefix><UserOutlined class="field-prefix" /></template>
                  </a-input>
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('username')" name="username">
                  <a-input v-model:value="form.username" :placeholder="$t('Enter_Username')" allow-clear>
                    <template #prefix><IdcardOutlined class="field-prefix" /></template>
                  </a-input>
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Email')" name="email">
                  <a-input v-model:value="form.email" type="email" :placeholder="$t('Enter_Email')" allow-clear>
                    <template #prefix><MailOutlined class="field-prefix" /></template>
                  </a-input>
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Phone')" name="phone" :validate-status="phoneDup ? 'warning' : undefined"
                  :help="phoneDup ? $t('Phone_Already_Registered') : undefined">
                  <a-input v-model:value="form.phone" :placeholder="$t('Enter_Phone')" allow-clear @blur="checkPhone">
                    <template #prefix><PhoneOutlined class="field-prefix" /></template>
                  </a-input>
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('password')" name="password">
                  <a-input-password
                    v-model:value="form.password"
                    :placeholder="isEdit ? $t('LeaveBlank') : $t('Enter_Password')"
                    autocomplete="new-password"
                  >
                    <template #prefix><LockOutlined class="field-prefix" /></template>
                  </a-input-password>
                </a-form-item>
              </a-col>
              <!-- Cashier PIN: typed on the POS keypad to open / close a cash
                   register (the POS then works under this user). Unique per
                   company; blank = no PIN. -->
              <a-col :xs="24" :md="12">
                <a-form-item
                  :label="$t('tx_Cashier_PIN_848f4')" name="pin"
                  extra="4 to 8 digits, unique within the company. Used on the POS to open and close the register. Blank = no PIN."
                >
                  <a-input
                    v-model:value="form.pin" :maxlength="8" inputmode="numeric"
                    autocomplete="off" placeholder="4-8 digits" allow-clear
                  >
                    <template #prefix><LockOutlined class="field-prefix" /></template>
                  </a-input>
                </a-form-item>
              </a-col>
              <a-col v-if="!isEdit" :xs="24" :md="12">
                <a-form-item :label="$t('Type')" name="type">
                  <a-select v-model:value="form.type" :options="typeOptions" :placeholder="$t('PleaseSelect')" />
                </a-form-item>
              </a-col>
              <a-col v-if="isEdit || !isAutoProvisionType" :xs="24" :md="12">
                <a-form-item :label="$t('RoleName')" name="role_id">
                  <a-select
                    v-model:value="form.role_id" :options="roleOptions"
                    show-search option-filter-prop="label" :placeholder="$t('PleaseSelect')"
                  />
                </a-form-item>
              </a-col>
              <a-col v-if="!isEdit && isAutoProvisionType" :span="24">
                <a-alert type="info" show-icon :message="autoProvisionHint" style="margin-bottom: 16px" />
              </a-col>
              <a-col v-if="!isEdit" :span="24">
                <a-alert
                  type="info" show-icon style="margin-bottom: 16px"
                  :message="$t('tx_Here_you_add_Admin_SuperAdmin_logins_An_employee_s_login_is__8867e')"
                />
              </a-col>
              <!-- Active = can sign in; off disables the login (nothing is deleted). Not for your own account. -->
              <a-col v-if="isEdit" :xs="24" :md="12">
                <a-form-item :label="$t('tx_Login_99dea')">
                  <div class="switch-row">
                    <a-switch v-model:checked="form.active" :disabled="isSelf" />
                    <span class="switch-label">{{ form.active ? $t('tx_Active_can_sign_in_7ee5e') : $t('tx_Disabled_cannot_sign_in_744f3') }}</span>
                  </div>
                  <a-typography-text v-if="isSelf" type="secondary" style="display: block; font-size: 12px">{{ $t('tx_You_can_t_disable_your_own_login_6a71c') }}</a-typography-text>
                </a-form-item>
              </a-col>
            </a-row>

            <a-divider style="margin: 4px 0 16px" />

            <div class="section-title">
              <ShopOutlined /> {{ $t('Warehouse_Access') }}
            </div>
            <a-row :gutter="16">
              <a-col :xs="24" :md="12">
                <a-form-item>
                  <div class="switch-row">
                    <a-switch v-model:checked="form.is_all_warehouses" />
                    <span class="switch-label">{{ $t('All_Warehouses') }}</span>
                  </div>
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item>
                  <div class="switch-row">
                    <a-switch v-model:checked="form.record_view" />
                    <span class="switch-label">{{ $t('ShowAll') }}</span>
                  </div>
                  <a-typography-text type="secondary" style="display: block; font-size: 12px">
                    {{ $t('ShowAll_Records_Help') }}
                  </a-typography-text>
                </a-form-item>
              </a-col>
              <a-col v-if="!form.is_all_warehouses" :span="24">
                <a-form-item :label="$t('Some_warehouses')" name="assigned_to">
                  <a-select
                    v-model:value="form.assigned_to"
                    mode="multiple"
                    :options="warehouseOptions"
                    option-filter-prop="label"
                    :placeholder="$t('PleaseSelect')"
                  />
                </a-form-item>
              </a-col>
            </a-row>

            <!-- The employee this login belongs to (read-only; edited in HRM -> Employees). -->
            <template v-if="employee">
              <a-divider style="margin: 4px 0 16px" />
              <div class="section-title emp-title">
                <span><TeamOutlined /> {{ $t('Employee') }}</span>
                <PermLink :to="`/hrm/employees/${employee.id}/details`" permission="view_employee" class="emp-open">
                  {{ $t('tx_Open_in_HRM_Employees_0af88') }}
                </PermLink>
              </div>
              <a-descriptions size="small" bordered :column="{ xs: 1, sm: 2, lg: 3 }" style="margin-bottom: 16px">
                <a-descriptions-item :label="$t('Name')">{{ [employee.firstname, employee.lastname].filter(Boolean).join(' ') || '—' }}</a-descriptions-item>
                <a-descriptions-item :label="$t('Serial_Status')">
                  <a-tag :color="employee.status === 'left' ? 'red' : 'green'">{{ employee.status === 'left' ? $t('tx_Left_945d5') : $t('Actif') }}</a-tag>
                </a-descriptions-item>
                <a-descriptions-item :label="$t('department')">{{ employee.department || '—' }}</a-descriptions-item>
                <a-descriptions-item :label="$t('Position')">{{ employee.designation || '—' }}</a-descriptions-item>
                <a-descriptions-item :label="$t('Office_Shift')">{{ employee.office_shift || '—' }}</a-descriptions-item>
                <a-descriptions-item :label="$t('Employment_type')">{{ humanize(employee.employment_type) }}</a-descriptions-item>
                <a-descriptions-item :label="$t('Email')">{{ employee.email || '—' }}</a-descriptions-item>
                <a-descriptions-item :label="$t('Phone')">{{ employee.phone || '—' }}</a-descriptions-item>
                <a-descriptions-item :label="$t('joining_date')">{{ employee.joining_date || '—' }}</a-descriptions-item>
                <a-descriptions-item v-if="employee.leaving_date" :label="$t('Leaving_Date')">{{ employee.leaving_date }}</a-descriptions-item>
                <a-descriptions-item :label="$t('Pay')">{{ payText }}</a-descriptions-item>
              </a-descriptions>
            </template>

            <a-space style="margin-top: 8px">
              <a-button type="primary" :loading="submitting" @click="submit()">{{ $t('submit') }}</a-button>
              <a-button @click="$router.push('/users')">{{ $t('Cancel') }}</a-button>
            </a-space>
          </a-card>
        </a-col>
      </a-row>
    </a-form>
  </div>
</template>

<script setup>
/**
 * Create: POST users (FormData: firstname, lastname, username, email,
 * password, phone, role, is_all_warehouses, record_view, avatar,
 * assigned_to[i]). Roles/warehouses bootstrap from GET users?limit=1.
 * Edit: GET users/{id}/edit → { user, roles, warehouses, assigned_warehouses };
 * save POST users/{id} with `_method=put` and NewPassword instead of password.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  UploadOutlined, UserOutlined, IdcardOutlined, MailOutlined,
  PhoneOutlined, LockOutlined, ShopOutlined, TeamOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { useAuthStore } from '../../stores/auth';
import { sortByLabel } from '../../lib/filterOptions';

const { t, locale } = useI18n();
const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();

const id = computed(() => route.params.id);
const isEdit = computed(() => !!id.value);

const loadingRecord = ref(true);
const submitting = ref(false);
const phoneDup = ref(false);
const formRef = ref();
const fileList = ref([]);
const roles = ref([]);
const warehouses = ref([]);
// Linked employee (GET users/{id}/edit -> employee), null when the login has none.
const employee = ref(null);
// "full_time" -> "Full time"
const humanize = v => (v ? String(v).replace(/_/g, ' ').replace(/^./, c => c.toUpperCase()) : '—');
const payText = computed(() => {
  const e = employee.value;
  if (!e) return '—';
  if (e.pay_type === 'commission') return `Commission ${Number(e.commission_rate || 0)}%`;
  if (e.pay_type === 'hourly') return `Hourly $${Number(e.hourly_rate || 0).toFixed(2)}`;
  if (Number(e.basic_salary)) return `Salary $${Number(e.basic_salary).toFixed(2)}`;
  return 'Not set';
});

const form = ref({
  firstname: '', lastname: '', username: '', email: '', phone: '',
  password: '', role_id: undefined, type: 'admin', is_all_warehouses: false,
  record_view: false, assigned_to: [], pin: '', active: true,
});
const isSelf = computed(() => isEdit.value && Number(id.value) === Number(authStore.user?.id));

const roleOptions = computed(() => sortByLabel(roles.value.map(r => ({ value: r.id, label: r.name }))));
const warehouseOptions = computed(() => warehouses.value.map(w => ({ value: w.id, label: w.name })));

// SuperAdmin is only offered to an acting SuperAdmin -- the backend rejects
// anyone else who tries to force it anyway, this just keeps the option out
// of sight for everyone who could never actually use it.
// Users -> Add creates Admin / SuperAdmin logins only (user's rule, 2026-10-06):
// employees, customers, suppliers and sales agents get their login when they
// are created in their own module (the server refuses them here too).
const typeOptions = computed(() => {
  const base = [
    { value: 'admin', label: t('Admin') },
  ];
  if (authStore.user?.type === 'SuperAdmin') {
    base.unshift({ value: 'SuperAdmin', label: t('SuperAdmin') });
  }
  return base;
});
// employee/customer/sales_agent/supplier auto-create their matching record
// and get their role auto-assigned -- the manual role picker doesn't apply.
const isAutoProvisionType = computed(() => ['employee', 'customer', 'sales_agent', 'supplier'].includes(form.value.type));
const autoProvisionHint = computed(() => {
  const labels = { employee: t('Employee'), customer: t('Customer'), sales_agent: t('Sales_Agent'), supplier: t('Supplier') };
  return t('AutoProvision_Hint', { type: labels[form.value.type] || form.value.type });
});

// ---- Profile panel preview ----
const avatarPreview = ref('');
const displayName = computed(() => {
  const full = `${form.value.firstname || ''} ${form.value.lastname || ''}`.trim();
  return full || form.value.username || t('username');
});
const initials = computed(() => {
  const f = (form.value.firstname || '').charAt(0);
  const l = (form.value.lastname || '').charAt(0);
  const both = (f + l).toUpperCase();
  return both || (form.value.username || 'U').charAt(0).toUpperCase();
});
const roleName = computed(() => roles.value.find(r => r.id === form.value.role_id)?.name || '');

const rules = computed(() => ({
  firstname: [{ required: true, message: t('Field_is_required') }],
  lastname: [{ required: true, message: t('Field_is_required') }],
  username: [{ required: true, message: t('Field_is_required') }],
  email: [
    { required: true, message: t('Field_is_required') },
    { type: 'email', message: t('InvalidData') },
  ],
  // Password required only when creating; on edit blank = keep current.
  password: isEdit.value ? [] : [{ required: true, min: 6, message: t('Field_is_required') }],
  // Auto-provisioned types (employee/customer/sales_agent) get their role
  // assigned automatically on create -- no manual pick required there.
  role_id: (isEdit.value || !isAutoProvisionType.value) ? [{ required: true, message: t('Field_is_required') }] : [],
  pin: [{ pattern: /^\d{4,8}$/, message: 'The PIN must be 4 to 8 digits.' }],
}));

function beforeUpload(file) {
  fileList.value = [file];
  // Live preview for the profile panel; revoke the previous object URL first.
  if (avatarPreview.value) URL.revokeObjectURL(avatarPreview.value);
  avatarPreview.value = URL.createObjectURL(file);
  return false;
}

function clearAvatar() {
  if (avatarPreview.value) URL.revokeObjectURL(avatarPreview.value);
  avatarPreview.value = '';
  fileList.value = [];
}

async function checkPhone() {
  phoneDup.value = false;
  const phone = (form.value.phone || '').trim();
  if (!phone) return;
  try {
    const data = await http.get('check_phone_duplicate', { phone, type: 'user' });
    phoneDup.value = !!data.exists && !isEdit.value;
  } catch (e) { /* advisory only */ }
}

function buildFormData() {
  const fd = new FormData();
  fd.append('firstname', form.value.firstname);
  fd.append('lastname', form.value.lastname);
  fd.append('username', form.value.username);
  fd.append('email', form.value.email);
  fd.append('phone', form.value.phone || '');
  if (!isEdit.value) fd.append('type', form.value.type);
  // Auto-provision types don't send a role -- the backend resolves their
  // own per-company role and would otherwise reject an empty one.
  if (isEdit.value || !isAutoProvisionType.value) fd.append('role', form.value.role_id);
  fd.append('is_all_warehouses', form.value.is_all_warehouses ? 1 : 0);
  fd.append('record_view', form.value.record_view ? 1 : 0);
  if (fileList.value.length) fd.append('avatar', fileList.value[0]);
  const assigned = form.value.is_all_warehouses ? [] : form.value.assigned_to;
  assigned.forEach((wid, i) => fd.append(`assigned_to[${i}]`, wid));
  // Legacy field name differs by mode: password on create, NewPassword on edit.
  if (isEdit.value) fd.append('NewPassword', form.value.password || '');
  else fd.append('password', form.value.password);
  // Always sent: blank clears the PIN (the backend treats an empty value as none).
  fd.append('pin', (form.value.pin || '').trim());
  return fd;
}

// The role the login had when the form opened ("keep it as it is" puts it back).
const originalRoleId = ref(null);

/*
 * Making a login the company admin (role Admin Compañía) while it belongs to an
 * employee / customer / supplier (user's rule, 2026-11-02): an admin never is
 * one. Ask: remove that record and make it an admin, or keep it with its own role.
 */
function askAdminRelease(linked) {
  const es = locale.value === 'es';
  const kinds = { employee: es ? 'empleado' : 'employee', customer: es ? 'cliente' : 'customer', supplier: es ? 'proveedor' : 'supplier', 'sales agent': es ? 'vendedor' : 'sales agent' };
  const list = (linked || []).map(l => `${kinds[l.kind] || l.kind} "${l.name}"`).join(', ');
  Modal.confirm({
    title: es ? '¿Convertir este login en admin?' : 'Make this login an admin?',
    content: es
      ? `Este login es del ${list}. Un admin solo administra los accesos y no puede ser empleado, cliente ni proveedor. Para hacerlo admin se da de baja esa ficha (su historial se conserva). Si no, déjalo como está con su propio rol.`
      : `This login belongs to the ${list}. An admin only manages the access and cannot be an employee, customer or supplier. To make it an admin that record is removed (its history is kept). Otherwise keep it as it is, with its own role.`,
    okText: es ? 'Dar de baja y hacerlo admin' : 'Remove it and make it an admin',
    okType: 'danger',
    cancelText: es ? 'Dejarlo como está' : 'Keep it as it is',
    onOk: () => submit({ makeAdmin: 'release' }),
    onCancel: () => { form.value.role_id = originalRoleId.value; },
  });
}

async function submit(opts = {}) {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }
  submitting.value = true;
  try {
    const fd = buildFormData();
    if (isEdit.value) {
      // Active / disabled login (the server ignores it for your own account).
      fd.append('statut', form.value.active ? 1 : 0);
      if (opts.makeAdmin) fd.append('make_admin', opts.makeAdmin);
      fd.append('_method', 'put');
      await http.postForm(`users/${id.value}`, fd);
      message.success(t('Successfully_Updated'));
    } else {
      const result = await http.postForm('users', fd);
      message.success(t('Successfully_Created'));
      if (result?.warning === 'employee_missing_hr_setup') {
        message.warning(t('Warning_Employee_Missing_HR_Setup'), 8);
      }
    }
    router.push('/users');
  } catch (e) {
    if (e?.data?.code === 'admin_linked') {
      askAdminRelease(e.data.linked);
      return;
    }
    // Show the backend's own reason (e.g. "This Email already taken.")
    // instead of a generic "Invalid data" that hid why the save failed.
    const errs = e?.data?.errors;
    const first = errs && Object.values(errs)[0];
    message.error((Array.isArray(first) ? first[0] : first) || e?.data?.message || t('InvalidData'));
  } finally {
    submitting.value = false;
  }
}

async function bootstrap() {
  loadingRecord.value = true;
  try {
    if (isEdit.value) {
      const data = await http.get(`users/${id.value}/edit`);
      roles.value = data.roles || [];
      warehouses.value = data.warehouses || [];
      employee.value = data.employee || null;
      const u = data.user || {};
      form.value = {
        firstname: u.firstname || '', lastname: u.lastname || '',
        username: u.username || '', email: u.email || '', phone: u.phone || '',
        password: '', role_id: u.role_id, type: u.type || 'admin',
        is_all_warehouses: !!Number(u.is_all_warehouses),
        record_view: !!Number(u.record_view),
        assigned_to: data.assigned_warehouses || [],
        pin: data.pin || '',
        active: Number(u.statut) === 1,
      };
      originalRoleId.value = u.role_id;
      // Show the current avatar in the profile panel (placeholder file aside);
      // picking a new file replaces this preview with a local object URL.
      if (u.avatar && u.avatar !== 'no_avatar.png') {
        // The backend stores the avatar's full relative path (e.g.
        // images/company_2/avatar/xxx.jpg), not a bare filename under a
        // shared /images/avatar/ folder -- use it as-is.
        avatarPreview.value = u.avatar.startsWith('/') ? u.avatar : `/${u.avatar}`;
      }
    } else {
      // Legacy bootstraps role/warehouse lists from the users list endpoint —
      // and the backend feeds SortField straight into orderBy(), so the sort
      // params are REQUIRED or the request 500s.
      const data = await http.get('users', {
        page: 1, SortField: 'id', SortType: 'desc', search: '', limit: 1,
      });
      roles.value = data.roles || [];
      warehouses.value = data.warehouses || [];
    }
  } catch (e) {
    if (isEdit.value) {
      // Without the record there is nothing to edit.
      message.error(t('InvalidData'));
      router.push('/users');
    } else {
      // Keep the form usable; only the selects are degraded.
      message.warning(t('InvalidData'));
    }
  } finally {
    loadingRecord.value = false;
  }
}

onMounted(bootstrap);
</script>

<style scoped>
/* ---------------- Profile panel ---------------- */
.profile-card {
  text-align: center;
  position: sticky;
  top: 88px;
}
.profile-avatar {
  width: 96px;
  height: 96px;
  margin: 4px auto 12px;
  border-radius: 50%;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #6d28d9, #9333ea);
  box-shadow: 0 6px 18px rgba(109, 40, 217, 0.28);
}
.profile-avatar img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.profile-initials {
  color: #fff;
  font-size: 36px;
  font-weight: 700;
  letter-spacing: 1px;
}
.profile-name {
  font-size: 16px;
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.profile-role {
  margin: 6px 0 16px;
  min-height: 24px;
}

/* ---------------- Field sections ---------------- */
.section-title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  opacity: 0.65;
  margin-bottom: 16px;
}
.emp-title {
  justify-content: space-between;
}
.emp-open {
  font-size: 12px;
  text-transform: none;
  letter-spacing: 0;
  font-weight: 500;
}
.field-prefix {
  opacity: 0.4;
}
.switch-row {
  display: flex;
  align-items: center;
  gap: 10px;
  height: 32px;
}
.switch-label {
  font-weight: 500;
}
</style>
