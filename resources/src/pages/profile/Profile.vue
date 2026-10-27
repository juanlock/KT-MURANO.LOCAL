<template>
  <div class="page">
    <PageHeader :title="$t('profil')" :breadcrumb="[$t('Settings'), $t('profil')]" />

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-row v-else :gutter="16">
      <!-- ============ Profile panel ============ -->
      <a-col :xs="24" :md="8" :lg="7">
        <a-card class="profile-card">
          <div class="profile-avatar">
            <img v-if="avatarSrc" :src="avatarSrc" alt="avatar" />
            <span v-else class="profile-initials">{{ initials }}</span>
          </div>
          <div class="profile-name">{{ displayName }}</div>
          <div class="profile-email">{{ form.email }}</div>
          <div class="profile-tags">
            <a-tag v-if="form.role_name" color="purple">{{ form.role_name }}</a-tag>
            <a-tag v-if="form.type" :color="typeColor(form.type)">{{ typeLabel(form.type) }}</a-tag>
          </div>

          <!-- Legacy capped this at an image under 200 KB. -->
          <a-upload
            :file-list="avatarList"
            accept="image/*"
            :before-upload="onAvatarPick"
            :max-count="1"
            :show-upload-list="false"
            @remove="clearAvatar"
          >
            <a-button block>
              <template #icon><UploadOutlined /></template>
              {{ $t('Choose_a_file') }}
            </a-button>
          </a-upload>
          <a-button
            v-if="pickedUrl" type="text" danger size="small" block
            style="margin-top: 8px" @click="clearAvatar"
          >
            {{ $t('Delete') }}
          </a-button>
        </a-card>
      </a-col>

      <!-- ============ Fields ============ -->
      <a-col :xs="24" :md="16" :lg="17">
        <a-card style="margin-bottom: 16px">
          <div class="section-title">
            <IdcardOutlined /> {{ $t('Account_Information') }}
          </div>
          <a-form ref="profileRef" :model="form" :rules="profileRules" layout="vertical">
            <a-row :gutter="16">
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Firstname') + ' *'" name="firstname">
                  <a-input v-model:value="form.firstname" :placeholder="$t('Enter_Firstname')" allow-clear>
                    <template #prefix><UserOutlined class="field-prefix" /></template>
                  </a-input>
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('lastname') + ' *'" name="lastname">
                  <a-input v-model:value="form.lastname" :placeholder="$t('Enter_Lastname')" allow-clear>
                    <template #prefix><UserOutlined class="field-prefix" /></template>
                  </a-input>
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('username') + ' *'" name="username">
                  <a-input v-model:value="form.username" :placeholder="$t('Enter_Username')" allow-clear>
                    <template #prefix><IdcardOutlined class="field-prefix" /></template>
                  </a-input>
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Phone') + ' *'" name="phone">
                  <a-input v-model:value="form.phone" :placeholder="$t('Enter_Phone')" allow-clear>
                    <template #prefix><PhoneOutlined class="field-prefix" /></template>
                  </a-input>
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Email') + ' *'" name="email">
                  <a-input v-model:value="form.email" :placeholder="$t('Enter_Email')" allow-clear>
                    <template #prefix><MailOutlined class="field-prefix" /></template>
                  </a-input>
                </a-form-item>
              </a-col>
            </a-row>

            <a-button type="primary" :loading="savingProfile" @click="saveProfile">{{ $t('submit') }}</a-button>
          </a-form>
        </a-card>

        <a-card>
          <div class="section-title">
            <LockOutlined /> {{ $t('ChangePassword') }}
          </div>
          <a-form ref="passwordRef" :model="passwordForm" :rules="passwordRules" layout="vertical">
            <a-row :gutter="16">
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('CurrentPassword') + ' *'" name="current_password">
                  <a-input-password v-model:value="passwordForm.current_password" :placeholder="$t('Enter_Password')" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Newpassword') + ' *'" name="new_password">
                  <a-input-password v-model:value="passwordForm.new_password" :placeholder="$t('Enter_Password')" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('ConfirmPassword') + ' *'" name="new_password_confirmation">
                  <a-input-password v-model:value="passwordForm.new_password_confirmation" :placeholder="$t('Enter_Password')" />
                </a-form-item>
              </a-col>
            </a-row>

            <a-button type="primary" :loading="savingPassword" @click="savePassword">
              {{ $t('UpdatePassword') }}
            </a-button>
          </a-form>
        </a-card>

        <!-- Login security (2026-10-27): my two-step verification. -->
        <TwoFactorCard />

        <!-- My header icons: show / hide each one for me ("Company default" = what the admin chose). -->
        <a-card style="margin-top: 16px">
          <div class="section-title">Header icons</div>
          <p class="hi-help">
            Choose which icons you see in the top bar and their order: the list goes from left to right — use
            <LeftOutlined /> / <RightOutlined /> to move an icon. "Company default" follows your company's setting.
            <a v-if="!orderIsStandard" style="margin-left: 6px" @click="resetOrder">Reset order</a>
          </p>
          <div v-for="(ic, idx) in orderedIcons" :key="ic.key" class="hi-row">
            <div class="hi-move">
              <a-tooltip title="Move left">
                <a-button size="small" type="text" :disabled="idx === 0" aria-label="Move left" @click="moveIcon(idx, -1)"><template #icon><LeftOutlined /></template></a-button>
              </a-tooltip>
              <a-tooltip title="Move right">
                <a-button size="small" type="text" :disabled="idx === orderedIcons.length - 1" aria-label="Move right" @click="moveIcon(idx, 1)"><template #icon><RightOutlined /></template></a-button>
              </a-tooltip>
            </div>
            <div class="hi-name">
              <div class="hi-label">{{ ic.label }}</div>
              <div class="hi-sub">Company: <span :class="companyShows(ic.key) ? 'hi-on' : 'hi-off'">{{ companyShows(ic.key) ? 'shown' : 'hidden' }}</span></div>
            </div>
            <!-- Colored by choice: Show = green, Hide = red, Company default = neutral. -->
            <a-segmented
              v-model:value="iconChoice[ic.key]" size="small" :class="['hi-seg', 'hi-seg--' + iconChoice[ic.key]]"
              :options="[{ label: 'Company default', value: 'default' }, { label: 'Show', value: 'show' }, { label: 'Hide', value: 'hide' }]"
            />
          </div>
          <a-button type="primary" :loading="savingIcons" :disabled="!iconsChanged" style="margin-top: 10px" @click="saveIcons">{{ $t('Save') }}</a-button>
        </a-card>
      </a-col>
    </a-row>
  </div>
</template>

<script setup>
/**
 * Profile — legacy profile.vue. Two independent forms on one page:
 *
 * - Profile: GET Get_user_profile → {user}; saved as multipart to
 *   POST update_user_profile/{id} with `_method=put` (Laravel needs the
 *   method override because PUT cannot carry a file upload).
 * - Password: POST update_user_password with current/new/confirmation. A 422
 *   means the current password was wrong; legacy surfaced the server message
 *   and fell back to CurrentPasswordIncorrect.
 *
 * Field rules are legacy's: firstname/lastname/username required 4–20 chars,
 * phone and email required, new password at least 6 characters and confirmed.
 * After a successful save the auth store is refreshed so the topbar avatar and
 * username update without a reload.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  UploadOutlined, UserOutlined, IdcardOutlined, MailOutlined,
  PhoneOutlined, LockOutlined, LeftOutlined, RightOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import TwoFactorCard from './TwoFactorCard.vue';
import { useAuthStore } from '../../stores/auth';
import { uploadForm } from '../../lib/upload';
import http from '../../lib/http';
import { HEADER_ICONS } from '../../lib/headerIcons';

const { t } = useI18n();
const auth = useAuthStore();

const loading = ref(true);
const savingProfile = ref(false);
const savingPassword = ref(false);
const profileRef = ref();
const passwordRef = ref();

const form = ref({ id: '', firstname: '', lastname: '', username: '', email: '', phone: '', type: '', role_name: '' });

// Same mapping as the Users list's Type column.
const TYPE_COLORS = { SuperAdmin: 'gold', admin: 'blue', employee: 'green', customer: 'purple', sales_agent: 'orange', supplier: 'cyan', Online_store: 'magenta' };
const TYPE_LABELS = { SuperAdmin: 'SuperAdmin', admin: 'Admin', employee: 'Employee', customer: 'Customer', sales_agent: 'Sales_Agent', supplier: 'Supplier', Online_store: 'Online_Store' };
const typeColor = type => TYPE_COLORS[type] || 'default';
const typeLabel = type => t(TYPE_LABELS[type] || type);
const passwordForm = ref({ current_password: '', new_password: '', new_password_confirmation: '' });

const avatarFile = ref(null);
const avatarList = ref([]);
// Live preview: a freshly-picked file wins, otherwise the saved avatar.
const pickedUrl = ref('');
const avatarSrc = computed(() => pickedUrl.value || auth.avatarUrl);
const displayName = computed(() => {
  const full = `${form.value.firstname || ''} ${form.value.lastname || ''}`.trim();
  return full || form.value.username || auth.username || '';
});
const initials = computed(() => {
  const f = (form.value.firstname || '').charAt(0);
  const l = (form.value.lastname || '').charAt(0);
  const both = (f + l).toUpperCase();
  return both || (form.value.username || auth.username || 'U').charAt(0).toUpperCase();
});

const len = { min: 4, max: 20 };
const profileRules = computed(() => ({
  firstname: [{ required: true, message: t('Field_is_required') }, { ...len }],
  lastname: [{ required: true, message: t('Field_is_required') }, { ...len }],
  username: [{ required: true, message: t('Field_is_required') }, { ...len }],
  phone: [{ required: true, message: t('Field_is_required') }],
  email: [{ required: true, message: t('Field_is_required') }],
}));

const passwordRules = computed(() => ({
  current_password: [{ required: true, message: t('Field_is_required') }],
  // Password rules (App\Support\PasswordPolicy, 2026-10-27): 8+ with letters and numbers; the rest the server says.
  new_password: [{ required: true, message: t('Field_is_required') }, { min: 8 }],
  new_password_confirmation: [
    { required: true, message: t('Field_is_required') },
    {
      validator: (_rule, value) =>
        value === passwordForm.value.new_password
          ? Promise.resolve()
          : Promise.reject(t('Please_fill_the_form_correctly')),
    },
  ],
}));

/** Returning false keeps the file local — we upload it with the form. */
function onAvatarPick(file) {
  if (file.size > 200 * 1024) {
    message.error(t('Please_fill_the_form_correctly'));
    return false;
  }
  avatarFile.value = file;
  avatarList.value = [file];
  if (pickedUrl.value) URL.revokeObjectURL(pickedUrl.value);
  pickedUrl.value = URL.createObjectURL(file);
  return false;
}

function clearAvatar() {
  if (pickedUrl.value) URL.revokeObjectURL(pickedUrl.value);
  pickedUrl.value = '';
  avatarFile.value = null;
  avatarList.value = [];
}

async function loadProfile() {
  try {
    const data = await http.get('Get_user_profile');
    if (data?.user) form.value = { ...form.value, ...data.user };
  } catch (e) { /* leaves the form empty, as legacy did */ } finally {
    loading.value = false;
  }
}

async function saveProfile() {
  try {
    await profileRef.value.validate();
  } catch (e) {
    message.error(t('Please_fill_the_form_correctly'));
    return;
  }
  savingProfile.value = true;
  const fd = new FormData();
  fd.append('firstname', form.value.firstname);
  fd.append('lastname', form.value.lastname);
  fd.append('username', form.value.username);
  fd.append('email', form.value.email);
  fd.append('phone', form.value.phone);
  if (avatarFile.value) fd.append('avatar', avatarFile.value);
  fd.append('_method', 'put');

  try {
    const res = await uploadForm(`update_user_profile/${form.value.id}`, fd);
    if (res.status >= 200 && res.status < 300) {
      message.success(t('Successfully_Updated'));
      clearAvatar();
      // Refresh so the topbar picks up the new name/avatar immediately.
      auth.loaded = false;
      await auth.load();
      await loadProfile();
    } else {
      message.error(res.data?.message || t('InvalidData'));
    }
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    savingProfile.value = false;
  }
}

async function savePassword() {
  try {
    await passwordRef.value.validate();
  } catch (e) {
    message.error(t('Please_fill_the_form_correctly'));
    return;
  }
  savingPassword.value = true;
  try {
    await http.post('update_user_password', { ...passwordForm.value });
    message.success(t('PasswordUpdated'));
    passwordForm.value = { current_password: '', new_password: '', new_password_confirmation: '' };
    passwordRef.value.clearValidate();
  } catch (e) {
    // 422 = the current password did not match.
    message.error(e?.data?.message || t('CurrentPasswordIncorrect'));
  } finally {
    savingPassword.value = false;
  }
}

// ---- My header icons (users.header_icons; missing = company default) ----
const iconChoice = ref({});
const savingIcons = ref(false);
const companyShows = key => !(auth.user?.header_icons_hidden || []).includes(key);
const savedChoice = key => {
  const v = (auth.user?.header_icons_user || {})[key];
  return v === true ? 'show' : v === false ? 'hide' : 'default';
};
// Left-to-right order (users.header_icons "_order"; keys missing = standard place).
const STANDARD_ORDER = HEADER_ICONS.map(ic => ic.key);
const savedOrder = () => {
  const o = (auth.user?.header_icons_order || []).filter(k => STANDARD_ORDER.includes(k));
  return [...o, ...STANDARD_ORDER.filter(k => !o.includes(k))];
};
const iconOrder = ref([...STANDARD_ORDER]);
const orderedIcons = computed(() => iconOrder.value.map(k => HEADER_ICONS.find(ic => ic.key === k)).filter(Boolean));
const orderIsStandard = computed(() => iconOrder.value.join() === STANDARD_ORDER.join());
function moveIcon(idx, dir) {
  const o = [...iconOrder.value];
  const j = idx + dir;
  if (j < 0 || j >= o.length) return;
  [o[idx], o[j]] = [o[j], o[idx]];
  iconOrder.value = o;
}
function resetOrder() { iconOrder.value = [...STANDARD_ORDER]; }
function loadIcons() {
  iconChoice.value = Object.fromEntries(HEADER_ICONS.map(ic => [ic.key, savedChoice(ic.key)]));
  iconOrder.value = savedOrder();
}
const iconsChanged = computed(() => HEADER_ICONS.some(ic => iconChoice.value[ic.key] !== savedChoice(ic.key))
  || iconOrder.value.join() !== savedOrder().join());
async function saveIcons() {
  savingIcons.value = true;
  try {
    const icons = Object.fromEntries(HEADER_ICONS.map(ic => [ic.key, { show: true, hide: false }[iconChoice.value[ic.key]] ?? null]));
    const r = await http.put('my_header_icons', { icons, order: iconOrder.value });
    if (auth.user) {
      // the header follows at once
      auth.user.header_icons_user = r.header_icons_user || {};
      auth.user.header_icons_order = r.header_icons_order || [...iconOrder.value];
    }
    loadIcons();
    message.success(t('Saved'));
  } catch (e) {
    message.error(e?.data?.message || 'Could not save.');
  } finally {
    savingIcons.value = false;
  }
}

onMounted(() => { loadProfile(); loadIcons(); });
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
.profile-email {
  opacity: 0.6;
  font-size: 13px;
  margin-bottom: 4px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.profile-tags {
  margin-bottom: 16px;
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
.hi-help { margin: -8px 0 10px; font-size: 13px; opacity: 0.7; }
.hi-row { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; padding: 8px 0; border-top: 1px solid rgba(5, 5, 5, 0.06); }
.hi-label { font-weight: 500; }
.hi-move { display: flex; gap: 2px; flex: 0 0 auto; }
.hi-name { flex: 1 1 200px; min-width: 0; }
.hi-sub { font-size: 12px; opacity: 0.75; }
.hi-on { color: #389e0d; font-weight: 600; }
.hi-off { color: #cf1322; font-weight: 600; }
/* Selected choice: Show = green, Hide = red; unselected Show / Hide keep a hint of their color. */
.hi-seg :deep(.ant-segmented-item:nth-child(2):not(.ant-segmented-item-selected)) { color: #389e0d; }
.hi-seg :deep(.ant-segmented-item:nth-child(3):not(.ant-segmented-item-selected)) { color: #cf1322; }
.hi-seg--show :deep(.ant-segmented-item-selected),
.hi-seg--show :deep(.ant-segmented-thumb) { background: #52c41a; color: #fff; }
.hi-seg--hide :deep(.ant-segmented-item-selected),
.hi-seg--hide :deep(.ant-segmented-thumb) { background: #ff4d4f; color: #fff; }
</style>
