<template>
  <div class="page">
    <PageHeader :title="$t('Mobile_App_Settings')" :breadcrumb="[$t('Settings'), $t('Mobile_App_Settings')]" />

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-form v-else layout="vertical">
      <!-- Master switch -->
      <a-card size="small" style="margin-bottom: 16px">
        <div class="m-toggle">
          <div>
            <div class="m-toggle__label">{{ $t('Mobile_App_Enabled') }}</div>
            <div class="m-toggle__help">{{ $t('Mobile_App_Enabled_Help') }}</div>
          </div>
          <a-switch v-model:checked="setting.mobile_app_enabled" />
        </div>

        <a-form-item
          v-if="!setting.mobile_app_enabled"
          :label="$t('Mobile_Maintenance_Message')"
          :help="$t('Mobile_Maintenance_Message_Help')"
          style="margin: 16px 0 0"
        >
          <a-textarea
            v-model:value="setting.mobile_maintenance_message"
            :rows="2"
            :maxlength="500"
            show-count
            allow-clear
          />
        </a-form-item>
      </a-card>

      <a-row :gutter="16">
        <a-col :xs="24" :lg="15">
          <!-- Branding -->
          <a-card size="small" :title="$t('Mobile_Branding')" style="margin-bottom: 16px">
            <a-row :gutter="16">
              <a-col :xs="24" :md="14">
                <a-form-item :label="$t('Mobile_App_Name')" :help="$t('Mobile_App_Name_Help')">
                  <a-input v-model:value="setting.mobile_app_name" :placeholder="setting.app_name" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="10">
                <a-form-item :label="$t('Mobile_Theme_Mode')" :help="$t('Mobile_Theme_Mode_Help')">
                  <a-select v-model:value="setting.mobile_theme_mode" :options="THEME_MODES" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Mobile_Primary_Color')" :help="$t('Mobile_Primary_Color_Help')">
                  <div class="color-field">
                    <input
                      class="color-field__swatch"
                      type="color"
                      :value="setting.mobile_primary_color || DEFAULT_COLOR"
                      @input="setting.mobile_primary_color = $event.target.value"
                    />
                    <a-input
                      class="color-field__hex"
                      v-model:value="setting.mobile_primary_color"
                      :placeholder="DEFAULT_COLOR"
                      allow-clear
                    />
                  </div>
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Mobile_Logo')" :help="$t('Mobile_Logo_Help')">
                  <a-upload
                    :file-list="logoList"
                    :before-upload="onLogoPicked"
                    accept="image/*"
                    list-type="picture"
                    :max-count="1"
                    @remove="clearPickedLogo"
                  >
                    <a-button><UploadOutlined /> {{ $t('Choose_File') || 'Choose file' }}</a-button>
                  </a-upload>
                  <a-button
                    v-if="setting.mobile_logo_url && !logoList.length"
                    type="link"
                    danger
                    size="small"
                    style="padding: 4px 0"
                    @click="removeLogo = true"
                  >
                    {{ $t('Delete') }}
                  </a-button>
                  <div v-if="removeLogo" style="color: #cf1322; font-size: 12px">
                    {{ $t('Mobile_Logo_Will_Be_Removed') }}
                  </div>
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>

          <!-- Features -->
          <a-card size="small" :title="$t('Mobile_Features')" style="margin-bottom: 16px">
            <div class="m-toggle">
              <div>
                <div class="m-toggle__label">{{ $t('Mobile_Offline') }}</div>
                <div class="m-toggle__help">{{ $t('Mobile_Offline_Help') }}</div>
              </div>
              <a-switch v-model:checked="setting.mobile_offline_enabled" />
            </div>
            <a-divider style="margin: 12px 0" />
            <div class="m-toggle">
              <div>
                <div class="m-toggle__label">{{ $t('Mobile_Scanner') }}</div>
                <div class="m-toggle__help">{{ $t('Mobile_Scanner_Help') }}</div>
              </div>
              <a-switch v-model:checked="setting.mobile_scanner_enabled" />
            </div>
            <a-divider style="margin: 12px 0" />
            <div class="m-toggle">
              <div>
                <div class="m-toggle__label">{{ $t('Mobile_Price_Edit') }}</div>
                <div class="m-toggle__help">{{ $t('Mobile_Price_Edit_Help') }}</div>
              </div>
              <a-switch v-model:checked="setting.mobile_allow_price_edit" />
            </div>
          </a-card>

          <!-- Modules -->
          <a-card size="small" :title="$t('Mobile_Modules')" style="margin-bottom: 16px">
            <div style="color: #8c8c8c; font-size: 13px; margin-bottom: 12px">
              {{ $t('Mobile_Modules_Help') }}
            </div>
            <a-row :gutter="[12, 4]">
              <a-col v-for="key in moduleKeys" :key="key" :xs="24" :sm="12" :md="8">
                <a-checkbox v-model:checked="modules[key]">{{ moduleLabel(key) }}</a-checkbox>
              </a-col>
            </a-row>
            <div style="margin-top: 12px">
              <a-button size="small" @click="setAllModules(true)">{{ $t('Select_All') || 'Select all' }}</a-button>
              <a-button size="small" style="margin-left: 8px" @click="setAllModules(false)">
                {{ $t('Deselect_All') || 'Deselect all' }}
              </a-button>
            </div>
          </a-card>

          <!-- Release + support -->
          <a-card size="small" :title="$t('Mobile_Release')" style="margin-bottom: 16px">
            <a-row :gutter="16">
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Mobile_Min_Version')" :help="$t('Mobile_Min_Version_Help')">
                  <a-input v-model:value="setting.mobile_min_version" placeholder="1.0.0" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Mobile_Support_Phone')">
                  <a-input v-model:value="setting.mobile_support_phone" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Mobile_Support_Email')">
                  <a-input v-model:value="setting.mobile_support_email" allow-clear />
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>
        </a-col>

        <!-- Live preview -->
        <a-col :xs="24" :lg="9">
          <a-card size="small" :title="$t('Preview')" style="margin-bottom: 16px">
            <div class="m-preview" :style="{ opacity: setting.mobile_app_enabled ? 1 : 0.45 }">
              <div class="m-preview__bar" :style="{ background: effectiveColor }"></div>
              <div class="m-preview__body">
                <img v-if="previewLogo" :src="previewLogo" class="m-preview__icon" alt="" />
                <div v-else class="m-preview__icon m-preview__icon--empty"></div>
                <div class="m-preview__name">{{ effectiveName }}</div>
                <div class="m-preview__panel" :style="{ background: effectiveColor }">
                  <div class="m-preview__label">TODAY'S SALES</div>
                  <div class="m-preview__value">$ 2,431.67</div>
                </div>
                <div class="m-preview__tiles">
                  <div v-for="key in enabledPreviewModules" :key="key" class="m-preview__tile">
                    {{ moduleLabel(key) }}
                  </div>
                </div>
              </div>
            </div>
            <div v-if="!setting.mobile_app_enabled" class="m-preview__off">
              {{ $t('Mobile_App_Disabled_Notice') }}
            </div>
          </a-card>
        </a-col>
      </a-row>

      <a-affix :offset-bottom="0">
        <div class="save-bar">
          <a-button type="primary" :loading="saving" @click="save">{{ $t('Save') }}</a-button>
        </div>
      </a-affix>
    </a-form>
  </div>
</template>

<script setup>
/**
 * System Settings → Mobile App.
 *
 * Everything here is read by the Flutter app from /api/mobile/ping at
 * launch and from /api/mobile/me after sign-in, so changes reach devices
 * on their next start without republishing the app.
 */
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { message } from 'ant-design-vue';
import { UploadOutlined } from '@ant-design/icons-vue';
import { useI18n } from 'vue-i18n';
import http from '../../lib/http';
import PageHeader from '../../components/PageHeader.vue';

const { t } = useI18n();

const DEFAULT_COLOR = '#4F46E5';
const THEME_MODES = [
  { value: 'system', label: 'Follow the device' },
  { value: 'light', label: 'Light' },
  { value: 'dark', label: 'Dark' },
];

// Labels for the module switches; keys must match the server's list.
const MODULE_LABELS = {
  dashboard: 'Dashboard',
  pos: 'POS',
  products: 'Products',
  sales: 'Sales',
  purchases: 'Purchases',
  quotations: 'Quotations',
  returns: 'Returns',
  customers: 'Customers',
  suppliers: 'Suppliers',
  expenses: 'Expenses',
  adjustments: 'Adjustments',
  transfers: 'Transfers',
  reports: 'Reports',
  accounting: 'Accounting',
  store_orders: 'Online orders',
  hrm: 'People',
  users: 'Users',
};

const loading = ref(true);
const saving = ref(false);
const setting = ref({});
const modules = ref({});
const logoList = ref([]);
const pickedLogoUrl = ref('');
const removeLogo = ref(false);

const moduleKeys = computed(() => setting.value.module_keys || Object.keys(MODULE_LABELS));
const moduleLabel = key => MODULE_LABELS[key] || key;

const effectiveName = computed(
  () => (setting.value.mobile_app_name || '').trim() || (setting.value.app_name || 'Stocky').split('|')[0].trim(),
);
const effectiveColor = computed(() => (setting.value.mobile_primary_color || '').trim() || DEFAULT_COLOR);
const previewLogo = computed(() => {
  if (pickedLogoUrl.value) return pickedLogoUrl.value;
  if (removeLogo.value) return setting.value.default_logo_url || '';
  return setting.value.mobile_logo_url || setting.value.default_logo_url || '';
});
// The preview only has room for a handful of tiles.
const enabledPreviewModules = computed(() =>
  moduleKeys.value.filter(k => modules.value[k] !== false).slice(0, 6),
);

function onLogoPicked(file) {
  logoList.value = [file];
  removeLogo.value = false;
  if (pickedLogoUrl.value) URL.revokeObjectURL(pickedLogoUrl.value);
  pickedLogoUrl.value = URL.createObjectURL(file);
  return false;
}

function clearPickedLogo() {
  logoList.value = [];
  if (pickedLogoUrl.value) URL.revokeObjectURL(pickedLogoUrl.value);
  pickedLogoUrl.value = '';
}

onBeforeUnmount(clearPickedLogo);

function setAllModules(value) {
  moduleKeys.value.forEach(key => {
    modules.value[key] = value;
  });
}

async function load() {
  try {
    const data = await http.get('get_mobile_settings');
    setting.value = data.settings || {};
    // A null map means "everything on" — start every switch checked.
    const saved = setting.value.mobile_modules || {};
    const next = {};
    (setting.value.module_keys || Object.keys(MODULE_LABELS)).forEach(key => {
      next[key] = saved[key] !== false;
    });
    modules.value = next;
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    loading.value = false;
  }
}

async function save() {
  saving.value = true;
  const s = setting.value;
  const fd = new FormData();
  if (logoList.value.length) fd.append('logo', logoList.value[0].originFileObj || logoList.value[0]);
  if (removeLogo.value) fd.append('remove_logo', '1');
  fd.append('mobile_app_enabled', s.mobile_app_enabled ? '1' : '0');
  fd.append('mobile_app_name', s.mobile_app_name || '');
  fd.append('mobile_primary_color', s.mobile_primary_color || '');
  fd.append('mobile_theme_mode', s.mobile_theme_mode || 'system');
  fd.append('mobile_min_version', s.mobile_min_version || '');
  fd.append('mobile_maintenance_message', s.mobile_maintenance_message || '');
  fd.append('mobile_offline_enabled', s.mobile_offline_enabled ? '1' : '0');
  fd.append('mobile_scanner_enabled', s.mobile_scanner_enabled ? '1' : '0');
  fd.append('mobile_allow_price_edit', s.mobile_allow_price_edit ? '1' : '0');
  fd.append('mobile_support_phone', s.mobile_support_phone || '');
  fd.append('mobile_support_email', s.mobile_support_email || '');
  fd.append('mobile_modules', JSON.stringify(modules.value));

  try {
    await http.postForm('update_mobile_settings', fd);
    message.success(t('Successfully_Updated'));
    clearPickedLogo();
    removeLogo.value = false;
    load();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.m-toggle {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}
.m-toggle__label {
  font-weight: 500;
}
.m-toggle__help {
  color: #8c8c8c;
  font-size: 13px;
}

/* Rough phone frame so branding choices can be judged together. */
.m-preview {
  border: 1px solid rgba(128, 128, 128, 0.25);
  border-radius: 18px;
  overflow: hidden;
  background: #fff;
}
.m-preview__bar {
  height: 24px;
}
.m-preview__body {
  padding: 16px 14px 20px;
  text-align: center;
}
.m-preview__icon {
  width: 56px;
  height: 56px;
  border-radius: 14px;
  object-fit: contain;
  background: #fff;
  border: 1px solid rgba(128, 128, 128, 0.2);
  display: block;
  margin: 0 auto 8px;
}
.m-preview__icon--empty {
  background: rgba(128, 128, 128, 0.15);
}
.m-preview__name {
  font-size: 14px;
  font-weight: 600;
  color: #1f1f1f;
  margin-bottom: 12px;
  word-break: break-word;
}
.m-preview__panel {
  border-radius: 14px;
  padding: 12px;
  color: #fff;
  text-align: left;
  margin-bottom: 12px;
}
.m-preview__label {
  font-size: 9px;
  letter-spacing: 0.1em;
  opacity: 0.8;
}
.m-preview__value {
  font-size: 20px;
  font-weight: 700;
}
.m-preview__tiles {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
}
.m-preview__tile {
  border: 1px solid rgba(128, 128, 128, 0.25);
  border-radius: 10px;
  padding: 12px 6px;
  font-size: 11px;
  color: #595959;
}
.m-preview__off {
  margin-top: 10px;
  font-size: 12px;
  color: #cf1322;
  text-align: center;
}

.save-bar {
  background: var(--ant-color-bg-container, #fff);
  border-top: 1px solid rgba(128, 128, 128, 0.2);
  padding: 10px 0;
  text-align: right;
}

:deep(.color-field) {
  display: flex;
  align-items: center;
  gap: 8px;
}
:deep(.color-field__swatch) {
  width: 48px;
  height: 32px;
  padding: 2px;
  border: 1px solid #d9d9d9;
  border-radius: 6px;
  background: transparent;
  cursor: pointer;
  flex: none;
}
:deep(.color-field__hex) {
  width: 130px;
}
</style>
