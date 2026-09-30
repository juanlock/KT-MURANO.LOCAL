<template>
  <!-- Clears every filter dropdown / date on the list back to its default
       (composables/useFilterReset.js). Disabled while nothing is filtered. -->
  <a-tooltip :title="$t('Reset_Filters_Help')">
    <a-button :disabled="!dirty" @click="$emit('reset')">
      <template #icon><ClearOutlined /></template>
      {{ $t('Reset') }}
    </a-button>
  </a-tooltip>
</template>

<script setup>
import { ClearOutlined } from '@ant-design/icons-vue';

defineProps({ dirty: { type: Boolean, default: false } });
defineEmits(['reset']);
</script>
