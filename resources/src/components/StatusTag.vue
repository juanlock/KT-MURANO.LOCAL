<template>
  <a-tag v-if="tag" :color="tag.color">{{ tag.label }}</a-tag>
</template>

<script setup>
/**
 * Renders a {color, label} from lib/statusTags, or nothing when the mapping
 * returns null (legacy showed a blank cell for an unknown shipping status).
 *
 * Only use this INSIDE a `v-else-if` that already matched a status column: a
 * component node is always truthy to Ant's bodyCell slot, so rendering one for
 * a non-status column would suppress that column's default cell instead of
 * falling through to it.
 */
defineProps({
  tag: { type: Object, default: null },
});
</script>
