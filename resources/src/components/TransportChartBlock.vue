<template>
  <!-- One chart, or its table. What the chart means goes in the (i) popup, not on the board. -->
  <div class="tcb">
    <div class="tcb-head">
      <span class="tcb-title">{{ chart.title }}</span>
      <a-tooltip v-if="chart.help" :title="chart.help"><InfoCircleOutlined class="tcb-info" /></a-tooltip>
    </div>
    <div v-if="asTable" class="tcb-wrap" :style="{ maxHeight: `${chart.height}px` }">
      <table class="tcb-table">
        <thead><tr><th v-for="c in chart.table.cols" :key="c">{{ c }}</th></tr></thead>
        <tbody><tr v-for="(row, i) in chart.table.rows" :key="i"><td v-for="(x, j) in row" :key="j">{{ x }}</td></tr></tbody>
      </table>
    </div>
    <apexchart v-else :key="chart.height" :type="chart.type" :height="chart.height" :options="chart.options" :series="chart.series" />
  </div>
</template>

<script setup>
import { InfoCircleOutlined } from '@ant-design/icons-vue';

defineProps({ chart: { type: Object, required: true }, asTable: Boolean });
</script>

<style scoped>
.tcb { border: 1px solid rgba(128, 128, 128, 0.2); border-radius: 8px; padding: 6px 8px 4px; min-width: 0; }
.tcb-head { display: flex; align-items: center; gap: 5px; line-height: 20px; }
.tcb-title { font-weight: 600; font-size: 13px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tcb-info { opacity: 0.45; font-size: 12px; cursor: help; }
.tcb-wrap { overflow: auto; margin-top: 4px; }
.tcb-table { width: 100%; border-collapse: collapse; font-size: 12px; font-variant-numeric: tabular-nums; }
.tcb-table th, .tcb-table td { padding: 2px 6px; border-bottom: 1px solid rgba(128, 128, 128, 0.2); text-align: right; white-space: nowrap; }
.tcb-table th:first-child, .tcb-table td:first-child { text-align: left; white-space: normal; }
</style>
