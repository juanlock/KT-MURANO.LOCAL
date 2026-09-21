<template>
  <div class="page">
    <PageHeader :title="$t('PWA_Settings')" :breadcrumb="[$t('Settings'), $t('PWA_Settings')]" />

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-form v-else layout="vertical">
      <a-card size="small" style="margin-bottom: 16px">
        <div class="pwa-toggle">
          <div>
            <div class="pwa-toggle__label">{{ $t('PWA_Enabled') }}</div>
            <div class="pwa-toggle__help">{{ $t('PWA_Enabled_Help') }}</div>
          </div>
          <a-switch v-model:checked="setting.pwa_enabled" />
        </div>
      </a-card>

      <a-row :gutter="16">
        <a-col :xs="24" :lg="15">
          <a-card size="small" :title="$t('PWA_Identity')" style="margin-bottom: 16px">
            <a-row :gutter="16">
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('PWA_App_Name')" :help="$t('PWA_App_Name_Help')">
                  <a-input v-model:value="setting.pwa_name" :placeholder="setting.app_name" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('PWA_Short_Name')" :help="$t('PWA_Short_Name_Help')">
                  <a-input
                    v-model:value="setting.pwa_short_name"
                    :placeholder="setting.default_short_name"
                    :maxlength="60"
                    allow-clear
                  />
                </a-form-item>
              </a-col>
              <a-col :xs="24">
                <a-form-item :label="$t('PWA_Description')" :help="$t('PWA_Description_Help')">
                  <a-input v-model:value="setting.pwa_description" :placeholder="effectiveName" allow-clear />
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>

          <a-card size="small" :title="$t('PWA_Launch')" style="margin-bottom: 16px">
            <a-row :gutter="16">
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('PWA_Start_Url')" :help="$t('PWA_Start_Url_Help')">
                  <a-auto-complete
                    v-model:value="setting.pwa_start_url"
                    :options="START_URLS"
                    placeholder="/"
                    style="width: 100%"
                  />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="6">
                <a-form-item :label="$t('PWA_Display_Mode')" :help="$t('PWA_Display_Mode_Help')">
                  <a-select v-model:value="setting.pwa_display" :options="DISPLAY_MODES" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="6">
                <a-form-item :label="$t('PWA_Orientation')">
                  <a-select v-model:value="setting.pwa_orientation" :options="ORIENTATIONS" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('PWA_Theme_Color')" :help="$t('PWA_Theme_Color_Help')">
                  <ColorField v-model="setting.pwa_theme_color" :fallback="setting.default_theme_color" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('PWA_Background_Color')" :help="$t('PWA_Background_Color_Help')">
                  <ColorField v-model="setting.pwa_background_color" :fallback="setting.default_background_color" />
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>

          <a-card size="small" :title="$t('PWA_Icons')" style="margin-bottom: 16px">
            <a-alert type="info" show-icon :message="$t('PWA_Icons_Help')" style="margin-bottom: 16px" />
            <a-row :gutter="24">
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('PWA_Icon_192')" :help="$t('PWA_Icon_192_Hint')">
                  <a-space align="start" size="middle">
                    <div v-if="setting.icon_192_exists" class="pwa-icon-preview">
                      <img :src="setting.icon_192_url" alt="192" width="64" height="64" />
                      <div class="pwa-icon-caption">{{ $t('Current_Icon') }}</div>
                    </div>
                    <a-upload
                      :file-list="icon192List" :max-count="1" accept="image/png,image/jpeg,image/webp" list-type="picture"
                      :before-upload="onIcon192Picked" @remove="clearIcon192"
                    >
                      <a-button><UploadOutlined /> {{ $t('Choose') }}</a-button>
                    </a-upload>
                  </a-space>
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('PWA_Icon_512')" :help="$t('PWA_Icon_512_Hint')">
                  <a-space align="start" size="middle">
                    <div v-if="setting.icon_512_exists" class="pwa-icon-preview">
                      <img :src="setting.icon_512_url" alt="512" width="64" height="64" />
                      <div class="pwa-icon-caption">{{ $t('Current_Icon') }}</div>
                    </div>
                    <a-upload
                      :file-list="icon512List" :max-count="1" accept="image/png,image/jpeg,image/webp" list-type="picture"
                      :before-upload="f => { icon512List = [f]; return false; }" @remove="icon512List = []"
                    >
                      <a-button><UploadOutlined /> {{ $t('Choose') }}</a-button>
                    </a-upload>
                  </a-space>
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>
        </a-col>

        <!-- Live preview: what the installed app looks like on a home screen. -->
        <a-col :xs="24" :lg="9">
          <a-card size="small" :title="$t('PWA_Preview')" style="margin-bottom: 16px">
            <div class="pwa-preview" :style="{ background: effectiveBackground }">
              <div class="pwa-preview__bar" :style="{ background: effectiveTheme }"></div>
              <div class="pwa-preview__body">
                <img v-if="previewIcon" class="pwa-preview__icon" :src="previewIcon" alt="" />
                <div v-else class="pwa-preview__icon pwa-preview__icon--empty"></div>
                <div class="pwa-preview__short">{{ effectiveShortName }}</div>
              </div>
            </div>
            <a-descriptions size="small" :column="1" bordered style="margin-top: 16px">
              <a-descriptions-item :label="$t('PWA_App_Name')">{{ effectiveName }}</a-descriptions-item>
              <a-descriptions-item :label="$t('PWA_Start_Url')">{{ setting.pwa_start_url || '/' }}</a-descriptions-item>
              <a-descriptions-item :label="$t('PWA_Display_Mode')">{{ setting.pwa_display || 'standalone' }}</a-descriptions-item>
            </a-descriptions>
            <a-alert type="warning" show-icon :message="$t('PWA_Reinstall_Notice')" style="margin-top: 16px" />
            <a-button block style="margin-top: 12px" @click="openManifest">
              {{ $t('PWA_View_Manifest') }}
            </a-button>
          </a-card>
        </a-col>
      </a-row>

      <a-button type="primary" size="large" :loading="saving" @click="save">{{ $t('submit') }}</a-button>
    </a-form>
  </div>
</template>

<script setup>
/**
 * GET get_pwa_settings → {settings}; save = multipart POST update_pwa_settings
 * (no id, no _method spoof — it is a real POST route) carrying the identity
 * fields plus the optional icon_192 / icon_512 files.
 *
 * Identity fields are overrides: an empty value is saved as NULL and the
 * manifest falls back to the app name / built-in default, which is what the
 * placeholders show. Icons are files under public/pwa_images/, not DB rows;
 * their returned URLs carry a filemtime cache-buster so previews refresh right
 * after upload.
 */
import { ref, computed, h, onMounted, onBeforeUnmount } from 'vue';
import { message, Input, Button } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { UploadOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

const DISPLAY_MODES = [
  { value: 'standalone', label: 'standalone' },
  { value: 'fullscreen', label: 'fullscreen' },
  { value: 'minimal-ui', label: 'minimal-ui' },
  { value: 'browser', label: 'browser' },
];
const ORIENTATIONS = [
  { value: 'any', label: 'any' },
  { value: 'portrait', label: 'portrait' },
  { value: 'landscape', label: 'landscape' },
];
// Suggestions only — any in-app path is allowed.
const START_URLS = [
  { value: '/' },
  { value: '/next/dashboard' },
  { value: '/next/pos' },
];

/**
 * Color swatch + hex input, with a reset that clears the override back to the
 * built-in default. Same shape as the login background color field in
 * Appearance settings.
 */
const ColorField = {
  name: 'ColorField',
  props: { modelValue: { type: String, default: '' }, fallback: { type: String, default: '#000000' } },
  emits: ['update:modelValue'],
  setup(props, { emit }) {
    return () => h('div', { class: 'color-field' }, [
      h('input', {
        type: 'color',
        value: props.modelValue || props.fallback,
        class: 'color-field__swatch',
        onInput: e => emit('update:modelValue', e.target.value),
      }),
      h(Input, {
        class: 'color-field__hex',
        value: props.modelValue,
        placeholder: props.fallback,
        'onUpdate:value': v => emit('update:modelValue', v),
      }),
      props.modelValue
        ? h(Button, { onClick: () => emit('update:modelValue', '') }, () => t('Reset'))
        : null,
    ]);
  },
};

const loading = ref(true);
const saving = ref(false);
const setting = ref({});
const icon192List = ref([]);
const icon512List = ref([]);

// What the manifest will actually contain, mirroring the server-side fallbacks
// in PwaManifestController so the preview never lies.
const effectiveName = computed(() => setting.value.pwa_name?.trim() || setting.value.app_name || 'Stocky');
const effectiveShortName = computed(() => {
  const explicit = setting.value.pwa_short_name?.trim();
  if (explicit) return explicit;
  return effectiveName.value.split('|')[0].trim() || effectiveName.value;
});
const effectiveTheme = computed(() => setting.value.pwa_theme_color?.trim() || setting.value.default_theme_color || '#2f3640');
const effectiveBackground = computed(() => setting.value.pwa_background_color?.trim() || setting.value.default_background_color || '#ffffff');

// Preview the icon being uploaded, if any, otherwise the saved one.
const pickedIconUrl = ref('');
const previewIcon = computed(() => pickedIconUrl.value || (setting.value.icon_192_exists ? setting.value.icon_192_url : ''));

function onIcon192Picked(file) {
  icon192List.value = [file];
  if (pickedIconUrl.value) URL.revokeObjectURL(pickedIconUrl.value);
  pickedIconUrl.value = URL.createObjectURL(file);
  return false;
}

function clearIcon192() {
  icon192List.value = [];
  if (pickedIconUrl.value) URL.revokeObjectURL(pickedIconUrl.value);
  pickedIconUrl.value = '';
}

onBeforeUnmount(clearIcon192);

function openManifest() {
  window.open('/manifest.webmanifest', '_blank', 'noopener');
}

async function load() {
  try {
    const data = await http.get('get_pwa_settings');
    setting.value = data.settings || {};
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
  if (icon192List.value.length) fd.append('icon_192', icon192List.value[0].originFileObj || icon192List.value[0]);
  if (icon512List.value.length) fd.append('icon_512', icon512List.value[0].originFileObj || icon512List.value[0]);
  fd.append('pwa_enabled', s.pwa_enabled ? '1' : '0');
  fd.append('pwa_name', s.pwa_name || '');
  fd.append('pwa_short_name', s.pwa_short_name || '');
  fd.append('pwa_description', s.pwa_description || '');
  fd.append('pwa_start_url', s.pwa_start_url || '');
  fd.append('pwa_display', s.pwa_display || '');
  fd.append('pwa_orientation', s.pwa_orientation || '');
  fd.append('pwa_theme_color', s.pwa_theme_color || '');
  fd.append('pwa_background_color', s.pwa_background_color || '');
  try {
    await http.postForm('update_pwa_settings', fd);
    message.success(t('Successfully_Updated'));
    clearIcon192();
    icon512List.value = [];
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
.pwa-toggle {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}
.pwa-toggle__label {
  font-weight: 500;
}
.pwa-toggle__help {
  color: #8c8c8c;
  font-size: 13px;
}

.pwa-icon-preview {
  text-align: center;
}
.pwa-icon-preview img {
  border-radius: 12px;
  border: 1px solid rgba(128, 128, 128, 0.25);
  background: #fff;
  padding: 4px;
  display: block;
}
.pwa-icon-caption {
  font-size: 12px;
  color: #8c8c8c;
  margin-top: 4px;
}

/* Rough phone frame so the name + colors can be judged together. */
.pwa-preview {
  border: 1px solid rgba(128, 128, 128, 0.25);
  border-radius: 14px;
  overflow: hidden;
}
.pwa-preview__bar {
  height: 26px;
}
.pwa-preview__body {
  padding: 28px 16px 32px;
  text-align: center;
}
.pwa-preview__icon {
  width: 72px;
  height: 72px;
  border-radius: 16px;
  display: block;
  margin: 0 auto 10px;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.18);
}
.pwa-preview__icon--empty {
  background: rgba(128, 128, 128, 0.2);
}
.pwa-preview__short {
  font-size: 13px;
  font-weight: 500;
  color: #1f1f1f;
  word-break: break-word;
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
