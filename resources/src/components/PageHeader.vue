<template>
  <div class="page-header">
    <div>
      <!-- Hide single-item breadcrumbs: a lone crumb just repeats the title. -->
      <a-breadcrumb v-if="breadcrumb.length > 1">
        <a-breadcrumb-item v-for="(crumb, i) in breadcrumb" :key="i">{{ crumb }}</a-breadcrumb-item>
      </a-breadcrumb>
      <a-typography-title :level="3" style="margin: 8px 0 0">{{ title }}</a-typography-title>
      <a-typography-text v-if="subtitle" type="secondary">{{ subtitle }}</a-typography-text>
    </div>
    <a-space wrap>
      <slot name="actions" />
      <slot name="extra" />
    </a-space>
  </div>
</template>

<script setup>
/**
 * Standard page heading: breadcrumb + title + right-aligned buttons.
 *
 * The button slot answers to BOTH names: `actions` and `extra`. Ant names this
 * slot `extra` on a-card / a-page-header, so pages reach for `#extra` by habit;
 * accepting it here stops that from silently rendering nothing (Vue drops
 * content passed to an undeclared slot, which quietly hid the Add button on
 * ~50 pages). Use either — they render side by side in the same row.
 */
defineProps({
  title: { type: String, required: true },
  subtitle: { type: String, default: '' },
  breadcrumb: { type: Array, default: () => [] },
});
</script>
