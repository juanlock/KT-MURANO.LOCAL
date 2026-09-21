<template>
  <EntitySyncTab
    entity="brands" list-endpoint="brands"
    @ready="$emit('ready')" @refreshed="$emit('refreshed')"
  />
</template>

<script setup>
import EntitySyncTab from './EntitySyncTab.vue';
defineEmits(['ready', 'refreshed']);
</script>
