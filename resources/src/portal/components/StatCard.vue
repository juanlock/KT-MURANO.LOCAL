<template>
  <!-- Portal stat card: a headline figure with its context. -->
  <div class="card h-100">
    <div class="card-body d-flex align-items-center gap-3">
      <span :class="`bg-${tone}-lt rounded-3 d-flex align-items-center justify-content-center flex-none rst-icon-tile`">
        <i :class="`ti ti-${icon} fs-2`"></i>
      </span>
      <div class="min-w-0">
        <div class="text-secondary text-truncate small">{{ label }}</div>
        <div class="h2 mb-0">{{ value }}</div>
        <div v-if="change !== null && change !== undefined" class="small" :class="change > 0 ? 'text-success' : (change < 0 ? 'text-danger' : 'text-secondary')">
          <i :class="`ti ti-${change > 0 ? 'trending-up' : (change < 0 ? 'trending-down' : 'minus')}`"></i>
          {{ change > 0 ? '+' : '' }}{{ change }}%
          <span class="text-secondary">{{ tr('vs_previous', 'vs previous') }}</span>
        </div>
        <div v-else-if="sub" class="text-secondary small">{{ sub }}</div>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: 'StatCard',
  props: {
    label: { type: String, required: true },
    value: { type: [String, Number], required: true },
    icon: { type: String, default: 'chart-bar' },
    tone: { type: String, default: 'primary' },
    change: { type: Number, default: null },
    sub: { type: String, default: '' },
  },
};
</script>
