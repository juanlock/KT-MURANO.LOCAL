<template>
  <!-- Portal empty state -->
  <div class="empty">
    <div class="empty-icon"><i :class="`ti ti-${icon} fs-1 text-secondary`"></i></div>
    <p class="empty-title">{{ title || tr('nothing_here_yet', 'Nothing here yet') }}</p>
    <p v-if="subtitle" class="empty-subtitle text-secondary">{{ subtitle }}</p>
    <div v-if="$slots.default" class="empty-action"><slot /></div>
  </div>
</template>

<script>
export default {
  name: 'EmptyState',
  props: {
    icon: { type: String, default: 'inbox' },
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
  },
};
</script>
