<template>
  <!-- Portal chart: a canvas described by a spec, drawn by lib/charts.js -->
  <div class="rst-chart" :style="{ height: height + 'px' }">
    <canvas ref="canvas" :data-rst-chart="specJson" role="img" :aria-label="ariaLabel || tr('chart', 'Chart')"></canvas>
  </div>
</template>

<script>
import { renderChart, destroyChart } from '../lib/charts';
import { brand } from '../lib/ui';

export default {
  name: 'RstChart',
  props: {
    type: { type: String, default: 'line' },
    labels: { type: Array, default: () => [] },
    series: { type: Array, default: () => [] },  // [{ label, data, color, colors, fill, dashed, width, tension, soft }]
    height: { type: Number, default: 260 },
    format: { type: String, default: 'currency' },
    stacked: { type: Boolean, default: false },
    horizontal: { type: Boolean, default: false },
    legend: { type: Boolean, default: null },
    legendPosition: { type: String, default: 'bottom' },
    cutout: { type: String, default: '72%' },
    ticks: { type: Number, default: 5 },
    max: { type: Number, default: null },
    total: { type: Number, default: null },
    ariaLabel: { type: String, default: '' },
  },
  computed: {
    specJson() {
      const spec = {
        type: this.type,
        labels: this.labels,
        series: this.series,
        format: this.format,
        currency: this.format === 'currency' ? brand().currency : null,
        decimals: 2,
        stacked: this.stacked || null,
        horizontal: this.horizontal || null,
        legend: this.legend,
        legendPosition: this.legendPosition,
        cutout: this.type === 'doughnut' ? this.cutout : null,
        ticks: this.ticks,
        max: this.max,
        total: this.total,
      };
      Object.keys(spec).forEach((k) => { if (spec[k] === null || spec[k] === undefined) delete spec[k]; });
      return JSON.stringify(spec);
    },
  },
  watch: {
    specJson() { this.$nextTick(this.draw); },
  },
  mounted() { this.draw(); },
  beforeUnmount() { if (this.$refs.canvas) destroyChart(this.$refs.canvas); },
  methods: {
    draw() { if (this.$refs.canvas) renderChart(this.$refs.canvas); },
  },
};
</script>
