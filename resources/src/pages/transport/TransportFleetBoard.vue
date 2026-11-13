<template>
  <div class="page">
    <PageHeader :title="T('Fleet board', 'Tablero de flota')" :breadcrumb="[T('Transport', 'Transporte'), T('Fleet board', 'Tablero de flota')]" />

    <a-card size="small" :body-style="{ padding: '4px 12px 12px' }">
      <a-tabs v-model:active-key="tab" size="small">
        <a-tab-pane key="pilots" :tab="T('Pilot alerts', 'Alertas de pilotos')">
          <div class="fb-filters"><PresetRangeFilter v-model:value="range" value-format="YYYY-MM-DD" /></div>
          <div class="fb-pilots"><PilotFatigueList :range="range" /></div>
        </a-tab-pane>
        <a-tab-pane key="live" :tab="T('Control board', 'Tablero de control')">
          <TransportLiveBoard v-if="seen.live" pick-date />
        </a-tab-pane>
        <a-tab-pane key="board" :tab="T('Transport board', 'Tablero de transporte')">
          <template v-if="seen.board">
            <div class="fb-filters"><PresetRangeFilter v-model:value="range" value-format="YYYY-MM-DD" /></div>
            <TransportBoard :key="range.join('|')" :range="range" />
          </template>
        </a-tab-pane>
      </a-tabs>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Transport -> Fleet board (2026-11-13): the fleet supervisor's screen -- the pilots' fatigue alerts (why, on a click),
 * the control board (buses on the road, the pilots' reports and swaps) and the transport board. No money on it.
 * Maintenance, parts and the plan are in the Fleet menu.
 */
import { computed, reactive, ref, watch } from 'vue';
import dayjs from 'dayjs';
import PageHeader from '../../components/PageHeader.vue';
import PresetRangeFilter from '../../components/PresetRangeFilter.vue';
import PilotFatigueList from '../../components/PilotFatigueList.vue';
import TransportLiveBoard from '../../components/TransportLiveBoard.vue';
import TransportBoard from '../../components/TransportBoard.vue';
import { useUiStore } from '../../stores/ui';

const ui = useUiStore();
const es = computed(() => ui.locale === 'es');
const T = (en, sp) => (es.value ? sp : en);

const tab = ref('pilots');
const seen = reactive({ pilots: true, live: false, board: false });
watch(tab, k => { seen[k] = true; });
const range = ref([dayjs().subtract(29, 'day').format('YYYY-MM-DD'), dayjs().format('YYYY-MM-DD')]);
</script>

<style scoped>
.fb-filters { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 10px; }
.fb-pilots { height: calc(100vh - 260px); min-height: 320px; max-width: 720px; }
</style>
