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
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('RoleName')" name="role_id">
                  <a-select
                    v-model:value="form.role_id" :options="roleOptions"
                    show-search option-filter-prop="label" :placeholder="$t('PleaseSelect')"
                  />
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

            <a-space style="margin-top: 8px">
              <a-button type="primary" :loading="submitting" @click="submit">{{ $t('submit') }}</a-button>
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
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  UploadOutlined, UserOutlined, IdcardOutlined, MailOutlined,
  PhoneOutlined, LockOutlined, ShopOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();

const id = computed(() => route.params.id);
const isEdit = computed(() => !!id.value);

const loadingRecord = ref(true);
const submitting = ref(false);
const phoneDup = ref(false);
const formRef = ref();
const fileList = ref([]);
const roles = ref([]);
const warehouses = ref([]);

const form = ref({
  firstname: '', lastname: '', username: '', email: '', phone: '',
  password: '', role_id: undefined, is_all_warehouses: false,
  record_view: false, assigned_to: [],
});

const roleOptions = computed(() => roles.value.map(r => ({ value: r.id, label: r.name })));
const warehouseOptions = computed(() => warehouses.value.map(w => ({ value: w.id, label: w.name })));

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
  role_id: [{ required: true, message: t('Field_is_required') }],
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
  fd.append('role', form.value.role_id);
  fd.append('is_all_warehouses', form.value.is_all_warehouses ? 1 : 0);
  fd.append('record_view', form.value.record_view ? 1 : 0);
  if (fileList.value.length) fd.append('avatar', fileList.value[0]);
  const assigned = form.value.is_all_warehouses ? [] : form.value.assigned_to;
  assigned.forEach((wid, i) => fd.append(`assigned_to[${i}]`, wid));
  // Legacy field name differs by mode: password on create, NewPassword on edit.
  if (isEdit.value) fd.append('NewPassword', form.value.password || '');
  else fd.append('password', form.value.password);
  return fd;
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }
  submitting.value = true;
  try {
    const fd = buildFormData();
    if (isEdit.value) {
      fd.append('statut', 1);
      fd.append('_method', 'put');
      await http.postForm(`users/${id.value}`, fd);
      message.success(t('Successfully_Updated'));
    } else {
      await http.postForm('users', fd);
      message.success(t('Successfully_Created'));
    }
    router.push('/users');
  } catch (e) {
    message.error(t('InvalidData'));
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
      const u = data.user || {};
      form.value = {
        firstname: u.firstname || '', lastname: u.lastname || '',
        username: u.username || '', email: u.email || '', phone: u.phone || '',
        password: '', role_id: u.role_id,
        is_all_warehouses: !!Number(u.is_all_warehouses),
        record_view: !!Number(u.record_view),
        assigned_to: data.assigned_warehouses || [],
      };
      // Show the current avatar in the profile panel (placeholder file aside);
      // picking a new file replaces this preview with a local object URL.
      if (u.avatar && u.avatar !== 'no_avatar.png') {
        avatarPreview.value = `/images/avatar/${u.avatar}`;
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
