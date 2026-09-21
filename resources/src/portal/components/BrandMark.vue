<template>
  <!-- Portal branding/mark: uploaded logo or the accent glyph, plus the wordmark -->
  <span class="d-inline-flex align-items-center gap-2">
    <img v-if="brand.logo" :src="brand.logo" :alt="brand.name" class="rst-brand-logo" @error="brand.logo = ''">
    <svg v-else width="30" height="30" viewBox="0 0 44 44" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
      <rect width="44" height="44" rx="12" fill="var(--accent, #f97316)"/>
      <path d="M13 14h18v4H13zM13 20h18v4H13zM13 26h12v4H13z" fill="#fff"/>
    </svg>
    <span class="fs-3 fw-bold rst-brand-word">{{ brand.name }}</span>
  </span>
</template>

<script>
import { brand } from '../lib/ui';

export default {
  name: 'BrandMark',
  data() { return { brand: brand() }; },
};
</script>
