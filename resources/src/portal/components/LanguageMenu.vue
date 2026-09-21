<template>
  <!-- Portal topbar language menu -->
  <div class="dropdown">
    <button type="button" class="btn btn-icon rst-tool" data-bs-toggle="dropdown" :title="$t('language')" :aria-label="$t('language')">
      <i class="ti ti-world"></i>
    </button>
    <div class="dropdown-menu dropdown-menu-end rst-menu">
      <div class="rst-menu-head">
        <span class="rst-menu-title">{{ $t('language') }}</span>
        <span class="rst-menu-count">{{ locales[current] || current }}</span>
      </div>
      <button
        v-for="(label, code) in locales"
        :key="code"
        type="button"
        class="dropdown-item rst-lang"
        :class="{ active: current === code }"
        :disabled="busy"
        @click="choose(code)"
      >
        <span class="rst-lang-code">{{ code }}</span>
        <span class="rst-lang-name">{{ label }}</span>
        <i v-if="current === code" class="ti ti-check rst-lang-check"></i>
      </button>
    </div>
  </div>
</template>

<script>
import { SUPPORTED_LOCALES, setLocale, i18n } from '../i18n';

export default {
  name: 'LanguageMenu',
  data() {
    return { locales: SUPPORTED_LOCALES, busy: false };
  },
  computed: {
    current() { return i18n.global.locale.value; },
  },
  methods: {
    async choose(code) {
      if (!code || code === this.current || this.busy) return;
      this.busy = true;
      try { await setLocale(code); } finally { this.busy = false; }
    },
  },
};
</script>
