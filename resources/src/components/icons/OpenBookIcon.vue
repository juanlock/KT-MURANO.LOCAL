<template>
  <!-- Colored open book (the user manual's icon, 2026-10-17): two violet pages
       with lines and an amber bookmark. Sizes with the font like an Ant icon. -->
  <span role="img" aria-label="manual" class="anticon open-book-icon">
    <svg viewBox="0 0 24 24" width="1em" height="1em" aria-hidden="true">
      <defs>
        <linearGradient :id="`obL${uid}`" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" stop-color="#8b5cf6" />
          <stop offset="1" stop-color="#4f46e5" />
        </linearGradient>
        <linearGradient :id="`obR${uid}`" x1="1" y1="0" x2="0" y2="1">
          <stop offset="0" stop-color="#a78bfa" />
          <stop offset="1" stop-color="#6366f1" />
        </linearGradient>
      </defs>
      <!-- left page -->
      <path d="M12 6.2C10 4.7 7.3 4.1 3.6 4.3a1 1 0 0 0-.9 1v12.3a1 1 0 0 0 1.05 1c3.2-.15 5.8.4 7.6 1.75.2.15.65.3.65.3z" :fill="`url(#obL${uid})`" />
      <!-- right page -->
      <path d="M12 6.2c2-1.5 4.7-2.1 8.4-1.9a1 1 0 0 1 .9 1v12.3a1 1 0 0 1-1.05 1c-3.2-.15-5.8.4-7.6 1.75-.2.15-.65.3-.65.3z" :fill="`url(#obR${uid})`" />
      <!-- lines -->
      <g stroke="#fff" stroke-opacity=".85" stroke-width="1.1" stroke-linecap="round">
        <path d="M5.2 8.2c1.7-.05 3.1.25 4.4.85M5.2 11c1.7-.05 3.1.25 4.4.85M5.2 13.8c1.7-.05 3.1.25 4.4.85" />
        <path d="M14.4 9.05c1.3-.6 2.7-.9 4.4-.85M14.4 11.85c1.3-.6 2.7-.9 4.4-.85" />
      </g>
      <!-- bookmark -->
      <path d="M16.6 4.4h2.6v6.4l-1.3-1.1-1.3 1.1z" fill="#f59e0b" />
    </svg>
  </span>
</template>

<script setup>
// Unique gradient ids when several icons are on the page.
const uid = Math.random().toString(36).slice(2, 8);
</script>

<style scoped>
.open-book-icon { display: inline-flex; align-items: center; line-height: 0; }
.open-book-icon svg { display: block; }
</style>
