<template>
  <!-- Gate on the same setting legacy used for its customizer button.
       Opened from the topbar's Customize button (next to POS), not a
       floating handle. -->
  <template v-if="visible">
    <a-drawer
      v-model:open="open"
      :placement="ui.direction === 'rtl' ? 'left' : 'right'"
      root-class-name="cz-drawer"
      :width="300"
      :title="$t('Settings')"
      :body-style="{ padding: '16px' }"
    >
      <!-- Sidebar layout — legacy's "Sidebar 1 / Sidebar 2" picker. -->
      <div class="cz-section">
        <div class="cz-label">Sidebar Layout</div>
        <div class="cz-layouts">
          <button
            v-for="opt in SIDEBAR_LAYOUTS" :key="opt.value"
            class="cz-layout" :class="{ active: ui.sidebarLayout === opt.value }"
            @click="ui.setSidebarLayout(opt.value)"
          >
            <div class="cz-layout-preview" :class="opt.value">
              <!-- Flat: a single grouped list; others: a rail (+panel) + body. -->
              <template v-if="opt.value === 'flat'">
                <span class="cz-flatcol">
                  <i class="cz-hd"></i><i class="cz-ln"></i><i class="cz-ln"></i>
                  <i class="cz-hd"></i><i class="cz-ln"></i>
                </span>
                <span class="cz-body"></span>
              </template>
              <template v-else>
                <span class="cz-rail"></span>
                <span v-if="opt.value === 'large'" class="cz-panel"></span>
                <span class="cz-body"></span>
              </template>
            </div>
            <span>{{ opt.label }}</span>
          </button>
        </div>
      </div>

      <a-divider style="margin: 16px 0" />

      <!-- Primary colour -->
      <div class="cz-section">
        <div class="cz-label">Primary Color</div>
        <div class="cz-swatches">
          <button
            v-for="c in PRESETS" :key="c"
            class="cz-swatch" :class="{ active: sameColor(c) }"
            :style="{ background: c }" :title="c"
            @click="ui.setPrimaryColor(c)"
          >
            <CheckOutlined v-if="sameColor(c)" />
          </button>
        </div>
        <div class="cz-custom">
          <span>Custom:</span>
          <input type="color" :value="ui.primaryColor" @input="e => ui.setPrimaryColor(e.target.value)" />
          <code>{{ ui.primaryColor }}</code>
          <a-button type="link" size="small" @click="ui.resetPrimaryColor()">Reset</a-button>
        </div>
      </div>

      <a-divider style="margin: 16px 0" />

      <!-- Dark mode -->
      <div class="cz-section cz-row">
        <span class="cz-label" style="margin: 0">Dark Mode</span>
        <a-switch :checked="ui.dark" @change="ui.toggleDark()" />
      </div>

      <a-divider style="margin: 16px 0" />

      <!-- RTL -->
      <div class="cz-section cz-row">
        <span class="cz-label" style="margin: 0">Enable RTL</span>
        <a-switch :checked="ui.direction === 'rtl'" @change="v => ui.setRtl(v)" />
      </div>

      <a-divider style="margin: 16px 0" />

      <!-- Language: the full active list from /languages, real flags. -->
      <div class="cz-section">
        <div class="cz-label">{{ $t('Language') }}</div>
        <a-menu :selected-keys="[ui.locale]" @click="({ key }) => ui.setLocale(key, auth.user?.id)">
          <a-menu-item v-for="l in languages" :key="l.value">
            <span class="cz-lang">
              <img v-if="l.image" :src="l.image" :alt="l.label" class="cz-flag" />
              <span v-else>{{ l.flag }}</span>
              <span>{{ l.label }}</span>
            </span>
          </a-menu-item>
        </a-menu>
      </div>
    </a-drawer>
  </template>
</template>

<script setup>
/**
 * Theme customizer — the Vue 3 counterpart of the legacy customizer.vue panel.
 * Opened via the topbar's Customize button (next to POS) into a LEFT drawer
 * (legacy's slid in from the edge) with the settings that apply to this
 * Ant-Design app: primary colour, dark mode, RTL and language. Sidebar-layout
 * is dropped — vue3-app has one layout.
 *
 * Every choice is persisted in the ui store (localStorage) and applied live:
 * the colour flows into App.vue's a-config-provider token, dark into Ant's
 * algorithm, RTL into the provider direction, language into vue-i18n. Shown
 * only when the customize_button_visible setting is on, matching legacy.
 */
import { ref, computed, onMounted } from 'vue';
import { CheckOutlined } from '@ant-design/icons-vue';
import { useUiStore } from '../stores/ui';
import { useAuthStore } from '../stores/auth';
import { SUPPORTED_LOCALES } from '../i18n';
import http from '../lib/http';

const ui = useUiStore();
const auth = useAuthStore();

// The drawer's own open state lives in the ui store, so the topbar button can
// drive it without a parent/child prop chain.
const open = computed({
  get: () => ui.customizerOpen,
  set: v => { ui.customizerOpen = v; },
});

// Every active language from GET /languages (name + flag image), falling back
// to the built-in emoji list if that endpoint is unavailable — same source and
// fallback the topbar picker uses.
const remoteLanguages = ref([]);
const languages = computed(() =>
  remoteLanguages.value.length
    ? remoteLanguages.value
    : SUPPORTED_LOCALES.map(l => ({ value: l.value, label: l.label, flag: l.flag, image: null }))
);

onMounted(async () => {
  try {
    const data = await http.get('languages');
    if (Array.isArray(data) && data.length) {
      remoteLanguages.value = data.map(l => ({
        value: l.locale,
        label: l.name,
        flag: '',
        image: l.flag ? `/flags/${l.flag}` : null,
      }));
    }
  } catch (e) { /* fall back to SUPPORTED_LOCALES */ }
});

// Legacy palette + the brand colour first.
const PRESETS = ['#6d28d9', '#2f47c2', '#0f9d58', '#e91e63', '#ff9800', '#f44336', '#00bcd4', '#212121'];

// The sidebar layouts, like legacy's Sidebar picker.
const SIDEBAR_LAYOUTS = [
  { value: 'default', label: 'Default' },
  { value: 'large', label: 'Large' },
  { value: 'flat', label: 'Flat' },
  { value: 'dark', label: 'Dark' },
];

const visible = computed(() => auth.customizeButtonVisible);

function sameColor(c) {
  return String(ui.primaryColor).toLowerCase() === c.toLowerCase();
}
</script>

<style scoped>
.cz-layouts {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}
.cz-layout {
  /* Four options wrap into a 2×2 grid in the drawer. */
  flex: 1 1 40%;
  border: 2px solid rgba(5, 5, 5, 0.1);
  border-radius: 8px;
  background: none;
  padding: 8px;
  cursor: pointer;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  font-size: 12px;
}
.cz-layout.active {
  border-color: #6d28d9;
}
.cz-layout-preview {
  width: 100%;
  height: 44px;
  border-radius: 4px;
  overflow: hidden;
  display: flex;
  background: rgba(128, 128, 128, 0.08);
}
.cz-rail {
  background: #6d28d9;
  opacity: 0.85;
}
.cz-panel {
  width: 26%;
  background: rgba(109, 40, 217, 0.3);
}
.cz-body {
  flex: 1;
  background: rgba(128, 128, 128, 0.18);
}
/* One column for default; a thin rail + submenu column for large. */
.cz-layout-preview.default .cz-rail {
  width: 30%;
}
.cz-layout-preview.large .cz-rail {
  width: 16%;
}
/* Dark: same single rail as default, but a dark navy fill. */
.cz-layout-preview.dark .cz-rail {
  width: 30%;
  background: #001529;
  opacity: 1;
}
/* Flat: a sidebar column drawn as grouped header/line marks. */
.cz-flatcol {
  width: 40%;
  background: rgba(109, 40, 217, 0.14);
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 3px;
  padding: 0 5px;
}
.cz-flatcol .cz-hd {
  height: 3px;
  width: 40%;
  border-radius: 2px;
  background: #6d28d9;
  opacity: 0.9;
}
.cz-flatcol .cz-ln {
  height: 3px;
  width: 80%;
  border-radius: 2px;
  background: rgba(109, 40, 217, 0.5);
  margin-inline-start: 6px;
}
.cz-lang {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}
.cz-flag {
  width: 20px;
  height: 14px;
  object-fit: cover;
  border-radius: 2px;
}
.cz-label {
  font-weight: 600;
  margin-bottom: 10px;
}
.cz-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.cz-swatches {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.cz-swatch {
  width: 30px;
  height: 30px;
  border-radius: 6px;
  border: 2px solid transparent;
  cursor: pointer;
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
}
.cz-swatch.active {
  border-color: rgba(0, 0, 0, 0.35);
}
.cz-custom {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 12px;
  font-size: 13px;
}
.cz-custom input[type='color'] {
  width: 32px;
  height: 24px;
  padding: 0;
  border: 1px solid rgba(5, 5, 5, 0.15);
  border-radius: 4px;
  background: none;
  cursor: pointer;
}
</style>
