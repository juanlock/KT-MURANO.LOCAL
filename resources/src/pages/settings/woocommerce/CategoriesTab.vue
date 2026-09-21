<template>
  <EntitySyncTab
    entity="categories" list-endpoint="categories"
    @ready="$emit('ready')" @refreshed="$emit('refreshed')"
  />
</template>

<script setup>
import EntitySyncTab from './EntitySyncTab.vue';
defineEmits(['ready', 'refreshed']);
</script>
