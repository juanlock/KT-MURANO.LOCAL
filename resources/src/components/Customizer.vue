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
        <div class="cz-label">{{ tr('Sidebar layout', 'Diseño del menú lateral') }}</div>
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
            <span>{{ tr(opt.label, opt.es) }}</span>
          </button>
        </div>
      </div>

      <a-divider style="margin: 16px 0" />

      <!-- Menu font — row text size + bold, for the Ant-menu-based sidebar
           (Default/Dark Menu; SidebarLarge/SidebarFlat use their own custom
           markup and aren't wired to this yet). -->
      <div class="cz-section">
        <div class="cz-label">{{ tr('Menu font', 'Letra del menú') }}</div>
        <div class="cz-row" style="margin-bottom: 10px">
          <span>{{ tr('Size', 'Tamaño') }}</span>
          <a-input-number
            :value="ui.sidebarFontSize"
            :min="MIN_FONT_SIZE" :max="MAX_FONT_SIZE"
            size="small" style="width: 90px"
            @change="v => ui.setSidebarFontSize(v)"
          />
        </div>
        <div class="cz-row">
          <span>{{ tr('Bold', 'Negrita') }}</span>
          <a-switch :checked="ui.sidebarBold" @change="v => ui.setSidebarBold(v)" />
        </div>
      </div>

      <a-divider style="margin: 16px 0" />

      <!-- Primary colour -->
      <div class="cz-section">
        <div class="cz-label">{{ tr('Primary color', 'Color principal') }}</div>
        <div class="cz-swatches cz-grid">
          <button
            v-for="c in PRESETS" :key="c"
            class="cz-swatch" :class="{ active: sameColor(c) }"
            :style="{ background: c }" :title="c"
            @click="ui.setPrimaryColor(c, auth.user?.id)"
          >
            <CheckOutlined v-if="sameColor(c)" />
          </button>
        </div>
        <!-- The company logo's own colours, read from the image file (2026-11-19: the eyedropper picks a smoothed
             screen pixel and gives a different colour). One click = that exact colour. -->
        <div v-if="logoSwatches.length" class="cz-sub">
          <div class="cz-sublabel">{{ tr('Colors of your logo', 'Colores de su logo') }}</div>
          <div class="cz-swatches">
            <button
              v-for="c in logoSwatches" :key="'l' + c"
              class="cz-swatch cz-swatch-lg" :class="{ active: sameColor(c) }"
              :style="{ background: c }" :title="c"
              @click="ui.setPrimaryColor(c, auth.user?.id)"
            >
              <CheckOutlined v-if="sameColor(c)" />
            </button>
          </div>
        </div>
        <div class="cz-sub">
          <div class="cz-sublabel">{{ tr('Custom color', 'Color personalizado') }}</div>
          <div class="cz-custom">
            <input type="color" :value="ui.primaryColor" :title="tr('Pick a color', 'Elegir un color')"
              @input="e => ui.setPrimaryColor(e.target.value, auth.user?.id)" />
            <a-input :value="ui.primaryColor" class="cz-hex" maxlength="7"
              @change="e => /^#[0-9a-f]{6}$/i.test(e.target.value) && ui.setPrimaryColor(e.target.value.toLowerCase(), auth.user?.id)" />
          </div>
        </div>
      </div>

      <!-- Dark Menu's text colour: defaults to auto (picks white or black for
           contrast against whichever Primary Color was chosen above), with a
           manual override for the rare case auto doesn't call it right. Only
           relevant once Dark Menu is actually on. -->
      <template v-if="isDarkMenu">
        <a-divider style="margin: 16px 0" />
        <div class="cz-section">
          <div class="cz-label">{{ tr('Menu text color', 'Color del texto del menú') }}</div>
          <a-radio-group :value="ui.sidebarTextMode" @change="e => ui.setSidebarTextMode(e.target.value)">
            <a-radio-button value="auto">{{ tr('Auto', 'Automático') }}</a-radio-button>
            <a-radio-button value="white">{{ tr('White', 'Blanco') }}</a-radio-button>
            <a-radio-button value="black">{{ tr('Black', 'Negro') }}</a-radio-button>
          </a-radio-group>
        </div>
      </template>

      <a-divider style="margin: 16px 0" />

      <!-- Dark mode -->
      <div class="cz-section cz-row">
        <span class="cz-label" style="margin: 0">{{ tr('Dark mode', 'Modo oscuro') }}</span>
        <a-switch :checked="ui.dark" @change="ui.toggleDark()" />
      </div>

      <a-divider style="margin: 16px 0" />

      <!-- RTL -->
      <div class="cz-section cz-row">
        <span class="cz-label" style="margin: 0">{{ tr('Right to left (RTL)', 'De derecha a izquierda (RTL)') }}</span>
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
import { useUiStore, MIN_FONT_SIZE, MAX_FONT_SIZE } from '../stores/ui';
import { useAuthStore } from '../stores/auth';
import { SUPPORTED_LOCALES } from '../i18n';
import http from '../lib/http';
import { logoColors } from '../lib/logoTone';
import { watch } from 'vue';

const ui = useUiStore();
const auth = useAuthStore();
const tr = (en, es) => (ui.locale === 'es' ? es : en);

// The company logo's colours (see logoColors), refreshed when the logo changes (another company picked).
const logoSwatches = ref([]);
watch(() => auth.logoUrl, async (url) => { logoSwatches.value = url ? await logoColors(url) : []; }, { immediate: true });

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

// Microsoft/SharePoint theme palette -- https://learn.microsoft.com/en-us/sharepoint/dev/design/themes-colors
// Blue (the brand colour/default) first, then the rest of the "Main colors"
// set, then the palette's own "Dark themes" set (brighter, higher-contrast
// variants of the same hues) -- 18 colours total, every one taken from that
// page rather than invented to hit a round number. Any of them (or a custom
// pick) works for Dark Menu too -- SidebarMenu.vue picks white or black text
// automatically for contrast against whichever one is chosen.
const PRESETS = [
  // Main colors
  '#0078d4', '#004e8c', '#4f6bed', '#007780', '#498205', '#986f0b',
  '#ca5010', '#a4262c', '#881798', '#8764b8', '#69797e', '#7a7574',
  // Dark themes
  '#3a96dd', '#9c89e9', '#00b294', '#ffc83d', '#ef6950', '#b1adab',
  // Twice as many (user, 2026-11-19): more of Fluent's shared colours -- reds, oranges, greens, teals, purples, pinks, charcoal
  '#d13438', '#750b1c', '#c50f1f', '#ff8c00', '#eaa300', '#8e562e',
  '#c19c00', '#8cbd18', '#107c10', '#0b6a0b', '#00ad56', '#038387',
  '#0099bc', '#005b70', '#5c2e91', '#c239b3', '#e3008c', '#393939',
];

// The sidebar layouts, like legacy's Sidebar picker.
const SIDEBAR_LAYOUTS = [
  { value: 'default', label: 'Default', es: 'Normal' },
  { value: 'large', label: 'Large', es: 'Grande' },
  { value: 'flat', label: 'Flat', es: 'Plano' },
  { value: 'dark', label: 'Dark Menu', es: 'Menú oscuro' },
];

const visible = computed(() => auth.customizeButtonVisible);

function sameColor(c) {
  return String(ui.primaryColor).toLowerCase() === c.toLowerCase();
}

const isDarkMenu = computed(() => ui.sidebarLayout === 'dark');
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
  border-color: #0078d4;
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
  background: #0078d4;
  opacity: 0.85;
}
.cz-panel {
  width: 26%;
  background: rgba(0, 120, 212, 0.3);
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
  background: rgba(0, 120, 212, 0.14);
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
  background: #0078d4;
  opacity: 0.9;
}
.cz-flatcol .cz-ln {
  height: 3px;
  width: 80%;
  border-radius: 2px;
  background: rgba(0, 120, 212, 0.5);
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
/* The 36 primary colours: 4 rows of 9 (2026-11-19). */
.cz-grid {
  display: grid;
  grid-template-columns: repeat(9, 1fr);
  gap: 6px;
}
.cz-grid .cz-swatch {
  width: 100%;
  height: auto;
  aspect-ratio: 1;
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
.cz-sub {
  margin-top: 14px;
}
.cz-sublabel {
  font-size: 13px;
  font-weight: 600;
  opacity: 0.8;
  margin-bottom: 8px;
}
.cz-swatch-lg {
  width: 38px;
  height: 38px;
  border-radius: 8px;
  box-shadow: inset 0 0 0 1px rgba(0, 0, 0, 0.12);
}
.cz-custom {
  display: flex;
  align-items: center;
  gap: 10px;
}
/* Big enough to see and to click (2026-11-19: the 32x24 box was too small). */
.cz-custom input[type='color'] {
  width: 64px;
  height: 40px;
  padding: 2px;
  border: 1px solid rgba(5, 5, 5, 0.2);
  border-radius: 8px;
  background: none;
  cursor: pointer;
}
.cz-hex {
  width: 110px;
  font-family: ui-monospace, Consolas, monospace;
  font-size: 15px;
}
</style>
