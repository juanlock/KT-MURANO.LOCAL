<template>
  <div class="page">
    <PageHeader :title="$t('Login_Page_Settings')" :breadcrumb="[$t('Settings'), $t('Login_Page_Settings')]" />

    <a-alert
      v-if="!isSuperAdmin"
      type="warning" show-icon banner style="margin-bottom: 16px"
      :message="$t('SuperAdmin_Only')"
      description="Only a SuperAdmin can view or change the login page -- it renders before anyone signs in, so it's one shared look for every company, not something a single company's Admin should control."
    />

    <template v-else>
      <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
        <a-spin size="large" />
      </div>

      <a-form v-else layout="vertical">
        <a-alert
          type="info" show-icon banner style="margin-bottom: 16px"
          message="Shared across every company"
          description="The login screen renders before anyone signs in, so there's no company to show branding for yet. Saving here changes what EVERY company's users see when they log in."
        />

        <!-- Fields below are grouped and ordered to match how they actually
             appear on the login page itself, top to bottom: General (page
             identity, not tied to either half) -> Hero section (the left
             half) -> Background (that same left half's backdrop) -> Sign-in
             panel (the right half, where the form itself lives). -->

        <a-card size="small" title="General">
          <a-row :gutter="16">
            <a-col :xs="24" :md="12">
              <a-form-item label="Page title" help="Shown in the browser tab and the login page's footer credit.">
                <a-input v-model:value="setting.app_name" placeholder="KcolTech - Murano | Ultimate Inventory With POS" />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="12">
              <a-form-item label="Default language" help="What a first-time visitor sees before picking one themselves from the login page's own language switcher (top-right).">
                <a-select v-model:value="setting.default_language" :options="LANGUAGE_OPTIONS" style="width: 100%" />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="12">
              <a-form-item label="Favicon" help="Browser tab icon. .ico or .png only.">
                <a-space align="start">
                  <img
                    v-if="currentFaviconUrl"
                    :src="currentFaviconUrl" alt="Current favicon"
                    style="height: 24px; width: 24px; object-fit: contain; border: 1px solid #d9d9d9; border-radius: 4px; padding: 2px"
                  />
                  <a-upload
                    :file-list="faviconList" :max-count="1" accept="image/x-icon,image/png,.ico,.png" list-type="picture"
                    :before-upload="f => { faviconList = [f]; return false; }" @remove="faviconList = []"
                  >
                    <a-button><UploadOutlined /> {{ $t('Choose') }}</a-button>
                  </a-upload>
                </a-space>
              </a-form-item>
            </a-col>
          </a-row>
        </a-card>

        <!-- One locale switcher for BOTH text cards below (Hero + Sign-in
             Panel) -- every text field is a per-locale map, so this decides
             which language's value the inputs in both cards are reading from
             and writing to. Switching it does NOT change what's saved
             elsewhere; only Save persists anything, for whichever locale is
             selected at that moment. -->
        <a-card size="small" style="margin-top: 16px">
          <div class="cz-label" style="margin-bottom: 10px">Editing text for</div>
          <a-radio-group v-model:value="textLocale" button-style="solid">
            <a-radio-button v-for="opt in LANGUAGE_OPTIONS" :key="opt.value" :value="opt.value">
              {{ opt.label }}
              <span v-if="hasText(opt.value)" class="lang-has-text">●</span>
            </a-radio-button>
          </a-radio-group>
        </a-card>

        <a-card size="small" title="Hero Section (left side)" style="margin-top: 16px">
          <a-row :gutter="16">
            <a-col :xs="24" :md="12">
              <a-form-item label="Hero badge">
                <a-input v-model:value="setting.login_hero_badge[textLocale]" />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="12">
              <a-form-item label="Hero title">
                <a-input v-model:value="setting.login_hero_title[textLocale]" />
              </a-form-item>
            </a-col>
            <a-col :xs="24">
              <a-form-item label="Hero subtitle">
                <a-input v-model:value="setting.login_hero_subtitle[textLocale]" />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="8">
              <a-form-item label="Hero feature 1" help="Leave blank to show fewer than 3.">
                <a-input v-model:value="setting.login_hero_feature_1[textLocale]" />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="8">
              <a-form-item label="Hero feature 2">
                <a-input v-model:value="setting.login_hero_feature_2[textLocale]" />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="8">
              <a-form-item label="Hero feature 3">
                <a-input v-model:value="setting.login_hero_feature_3[textLocale]" />
              </a-form-item>
            </a-col>
          </a-row>
        </a-card>

        <a-card size="small" title="Background (hero section's backdrop)" style="margin-top: 16px">
          <a-radio-group v-model:value="backgroundMode" style="margin-bottom: 16px">
            <a-radio-button value="color">Solid color</a-radio-button>
            <a-radio-button value="image">Image</a-radio-button>
          </a-radio-group>

          <div v-if="backgroundMode === 'color'">
            <a-space>
              <input
                type="color"
                :value="setting.login_bg_color || '#4f46e5'"
                style="width: 48px; height: 32px; padding: 2px; border: 1px solid #d9d9d9; border-radius: 6px; background: transparent; cursor: pointer"
                @input="e => (setting.login_bg_color = e.target.value)"
              />
              <a-input v-model:value="setting.login_bg_color" placeholder="#4f46e5" style="width: 130px" />
              <a-button v-if="setting.login_bg_color" @click="setting.login_bg_color = null">{{ $t('Reset') }}</a-button>
            </a-space>
          </div>

          <div v-else>
            <a-space align="start">
              <img
                v-if="currentBgImageUrl"
                :src="currentBgImageUrl" alt="Current background"
                style="height: 90px; width: 70px; object-fit: cover; border: 1px solid #d9d9d9; border-radius: 6px"
              />
              <a-upload
                :file-list="bgImageList" :max-count="1" accept="image/*" list-type="picture"
                :before-upload="f => { bgImageList = [f]; return false; }" @remove="bgImageList = []"
              >
                <a-button><UploadOutlined /> {{ $t('Choose') }}</a-button>
              </a-upload>
            </a-space>
            <div class="muted-help">
              Recommended: at least 1200×1600px (portrait, taller than wide -- it fills a half-screen panel top to bottom), JPG/PNG/WebP, under ~2 MB. It's cropped to cover the panel, so keep the important part of the image centered.
            </div>
          </div>
        </a-card>

        <a-card size="small" title="Sign-in Panel (right side)" style="margin-top: 16px">
          <a-row :gutter="16">
            <a-col :xs="24" :md="12">
              <a-form-item label="Logo">
                <a-space align="start">
                  <img
                    v-if="currentLogoUrl"
                    :src="currentLogoUrl" alt="Current logo"
                    style="height: 40px; max-width: 160px; object-fit: contain; border: 1px solid #d9d9d9; border-radius: 6px; padding: 4px"
                  />
                  <a-upload
                    :file-list="logoList" :max-count="1" accept="image/*" list-type="picture"
                    :before-upload="f => { logoList = [f]; return false; }" @remove="logoList = []"
                  >
                    <a-button><UploadOutlined /> {{ $t('Choose') }}</a-button>
                  </a-upload>
                </a-space>
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="12">
              <a-form-item label="Login button text">
                <a-input v-model:value="setting.login_btn_text[textLocale]" />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="12">
              <a-form-item label="Panel title">
                <a-input v-model:value="setting.login_panel_title[textLocale]" />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="12">
              <a-form-item label="Panel subtitle">
                <a-input v-model:value="setting.login_panel_subtitle[textLocale]" />
              </a-form-item>
            </a-col>
            <a-col :xs="24">
              <a-form-item label="Login footer text" help="Leave blank to keep the automatic '© (this year) (page title). All rights reserved.' line.">
                <a-input v-model:value="setting.login_footer_text[textLocale]" />
              </a-form-item>
            </a-col>
          </a-row>
        </a-card>

        <a-button type="primary" size="large" :loading="saving" style="margin-top: 16px" @click="save">{{ $t('submit') }}</a-button>
      </a-form>
    </template>
  </div>
</template>

<style scoped>
.muted-help {
  margin-top: 10px;
  font-size: 12px;
  color: rgba(0, 0, 0, 0.45);
  max-width: 480px;
}
.cz-label {
  font-weight: 600;
}
.lang-has-text {
  margin-inline-start: 4px;
  font-size: 8px;
  vertical-align: middle;
  color: #52c41a;
}
</style>

<script setup>
/**
 * Split out of AppearanceSettings.vue: this is the ONE shared login screen
 * every company's users see, not a per-company setting, so it gets its own
 * page + SuperAdmin-only backend endpoints (login_page_settings /
 * update_login_page_settings) instead of living on the per-company
 * Appearance Settings page where a company-level Admin might reach it via
 * the general 'appearance_settings' permission.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { UploadOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { useAuthStore } from '../../stores/auth';
import { resolveImageUrl } from '../../lib/imagePath';

const { t } = useI18n();
const auth = useAuthStore();
const isSuperAdmin = computed(() => auth.user?.type === 'SuperAdmin');

// Plain text fields saved as-is; the template lays each one out explicitly
// now (grouped/ordered to match the actual login page -- see the cards
// above), so this is just the save() loop's list of keys, not a render list.
const LOGIN_TEXT_FIELDS = [
  'login_hero_badge', 'login_hero_title', 'login_hero_subtitle',
  'login_hero_feature_1', 'login_hero_feature_2', 'login_hero_feature_3',
  'login_btn_text', 'login_panel_title', 'login_panel_subtitle', 'login_footer_text',
];

// Matches app\Http\Middleware\SetLocale::SUPPORTED -- the only locales this
// app actually ships a resources/lang bundle for.
const LANGUAGE_OPTIONS = [
  { value: 'en', label: 'English' },
  { value: 'fr', label: 'Français' },
  { value: 'es', label: 'Español' },
  { value: 'ar', label: 'العربية' },
];

// Which language's copy the text-field inputs below are currently showing
// (independent of "Default language" above, which is what a FIRST-TIME
// guest sees before picking a language themselves).
const textLocale = ref('en');
function hasText(locale) {
  return LOGIN_TEXT_FIELDS.some(key => !!setting.value[key]?.[locale]);
}

const loading = ref(true);
const saving = ref(false);
const setting = ref({});
const logoList = ref([]);
const faviconList = ref([]);
const bgImageList = ref([]);
// Background is color OR image -- 'color'|'image', seeded from whichever the
// server says is actually active (backend derives this the same way: image
// wins when a login_bg_image is present).
const backgroundMode = ref('color');
// Bare legacy filename ("logo.png") or a full relative path
// ("images/loginpage/xxx.png") -- same dual-format resolution as every other
// company-owned image in this app (resolveImageUrl handles both).
const currentLogoUrl = computed(() => resolveImageUrl(setting.value.logo, 'loginpage'));
const currentBgImageUrl = computed(() => resolveImageUrl(setting.value.login_bg_image, 'loginpage'));
const currentFaviconUrl = computed(() => resolveImageUrl(setting.value.favicon, 'loginpage'));

async function save() {
  saving.value = true;
  const s = setting.value;
  const fd = new FormData();
  if (logoList.value.length) fd.append('logo', logoList.value[0].originFileObj || logoList.value[0]);
  if (faviconList.value.length) fd.append('favicon', faviconList.value[0].originFileObj || faviconList.value[0]);
  fd.append('app_name', s.app_name || '');
  fd.append('default_language', s.default_language || 'en');
  // Only THIS locale's value for each text field -- the backend merges it
  // into that field's existing per-locale map, leaving other languages as-is.
  fd.append('text_locale', textLocale.value);
  LOGIN_TEXT_FIELDS.forEach(key => fd.append(key, s[key]?.[textLocale.value] || ''));
  fd.append('background_mode', backgroundMode.value);
  if (backgroundMode.value === 'color') {
    fd.append('login_bg_color', s.login_bg_color || '');
  } else if (bgImageList.value.length) {
    fd.append('login_bg_image', bgImageList.value[0].originFileObj || bgImageList.value[0]);
  }
  fd.append('_method', 'put');
  try {
    await http.postForm('update_login_page_settings', fd);
    message.success(t('Successfully_Updated'));
    logoList.value = [];
    faviconList.value = [];
    bgImageList.value = [];
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

onMounted(async () => {
  if (!isSuperAdmin.value) {
    loading.value = false;
    return;
  }
  try {
    const data = await http.get('login_page_settings');
    const s = data.settings || {};
    // The backend always sends an object (possibly {}) for each text field,
    // but guard anyway -- setting.value.field[locale] below assumes it's
    // never null/undefined.
    LOGIN_TEXT_FIELDS.forEach(key => { s[key] = s[key] && typeof s[key] === 'object' ? s[key] : {}; });
    setting.value = s;
    backgroundMode.value = s.background_mode || 'color';
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    loading.value = false;
  }
});
</script>
