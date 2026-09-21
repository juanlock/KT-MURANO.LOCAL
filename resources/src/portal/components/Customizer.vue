<template>
  <!-- Portal theme customizer: floating button + drawer -->
  <button type="button" class="rst-custom-fab d-print-none" :aria-label="tr('customize_theme', 'Customize theme')" :title="tr('customize_theme', 'Customize theme')" @click="open = true">
    <i class="ti ti-palette"></i>
  </button>

  <div class="rst-custom-backdrop" @click="open = false"></div>

  <aside class="rst-custom-drawer" role="dialog" aria-labelledby="rst-custom-title" :aria-hidden="open ? 'false' : 'true'">
    <div class="rst-custom-drawer-header">
      <span id="rst-custom-title"><i class="ti ti-palette me-2 rst-accent-ink"></i>{{ tr('customize', 'Customize') }}</span>
      <button type="button" class="btn btn-icon btn-ghost-secondary" :aria-label="$t('close') || 'Close'" @click="open = false">
        <i class="ti ti-x"></i>
      </button>
    </div>

    <div class="rst-custom-drawer-body">
      <div>
        <div class="rst-custom-label">{{ tr('theme', 'Theme') }}</div>
        <div class="rst-custom-segment">
          <button type="button" :class="{ active: state.choice === 'light' }" @click="setThemeChoice('light')"><i class="ti ti-sun"></i>{{ tr('theme_light', 'Light') }}</button>
          <button type="button" :class="{ active: state.choice === 'dark' }" @click="setThemeChoice('dark')"><i class="ti ti-moon"></i>{{ tr('theme_dark', 'Dark') }}</button>
          <button type="button" :class="{ active: state.choice === 'system' }" @click="setThemeChoice('system')"><i class="ti ti-device-desktop"></i>{{ tr('theme_system', 'System') }}</button>
        </div>
      </div>

      <div>
        <div class="rst-custom-label">{{ tr('primary_color', 'Primary color') }}</div>
        <div class="rst-custom-swatches">
          <button
            v-for="[color, label] in swatches"
            :key="color"
            type="button"
            class="rst-custom-swatch"
            :class="{ active: state.accent.toLowerCase() === color.toLowerCase() }"
            :style="{ background: color }"
            :aria-label="label"
            @click="setAccent(color)"
          ></button>
        </div>
      </div>

      <div>
        <div class="rst-custom-label">{{ tr('custom_color', 'Custom color') }}</div>
        <div class="rst-custom-picker-row">
          <input type="color" :value="state.accent" :aria-label="tr('pick_custom_color', 'Pick a custom color')" @input="setAccent($event.target.value)">
          <input type="text" class="form-control" :value="state.accent" maxlength="7" :aria-label="tr('hex_color_value', 'Hex color value')" @change="onHex($event.target.value)">
        </div>
      </div>

      <div>
        <div class="rst-custom-label">{{ tr('corner_radius', 'Corner radius') }}</div>
        <div class="rst-custom-segment">
          <button type="button" :class="{ active: state.radius === 'sharp' }" @click="setRadius('sharp')"><i class="ti ti-square"></i>{{ tr('radius_sharp', 'Sharp') }}</button>
          <button type="button" :class="{ active: state.radius === 'regular' }" @click="setRadius('regular')"><i class="ti ti-squares"></i>{{ tr('radius_regular', 'Regular') }}</button>
          <button type="button" :class="{ active: state.radius === 'round' }" @click="setRadius('round')"><i class="ti ti-circle-square"></i>{{ tr('radius_round', 'Rounded') }}</button>
        </div>
      </div>

      <button type="button" class="btn btn-outline-secondary" @click="resetTheme">
        <i class="ti ti-restore me-1"></i>{{ tr('reset_to_defaults', 'Reset to defaults') }}
      </button>

      <div class="text-secondary small">{{ tr('customizer_hint', 'Changes apply instantly and are remembered on this browser.') }}</div>
    </div>
  </aside>
</template>

<script>
import { themeState, setThemeChoice, setAccent, setRadius, resetTheme, SWATCHES } from '../lib/theme';

export default {
  name: 'Customizer',
  data() {
    return { open: false, state: themeState, swatches: SWATCHES };
  },
  watch: {
    open(v) { document.body.classList.toggle('rst-customizer-open', v); },
  },
  mounted() {
    document.addEventListener('keydown', this.onKey);
    document.addEventListener('portal:open-customizer', this.show);
  },
  beforeUnmount() {
    document.removeEventListener('keydown', this.onKey);
    document.removeEventListener('portal:open-customizer', this.show);
    document.body.classList.remove('rst-customizer-open');
  },
  methods: {
    setThemeChoice, setAccent, setRadius, resetTheme,
    show() { this.open = true; },
    onKey(e) { if (e.key === 'Escape') this.open = false; },
    onHex(v) {
      let hex = String(v || '').trim();
      if (/^[0-9a-f]{6}$/i.test(hex)) hex = '#' + hex;
      setAccent(hex);
    },
  },
};
</script>
