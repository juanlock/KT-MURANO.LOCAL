<template>
  <div class="page">
    <PageHeader :title="T('Vehicle monitoring', 'Monitoreo de vehículos')" :breadcrumb="[T('Transport', 'Transporte'), T('Vehicle monitoring', 'Monitoreo de vehículos')]" />

    <a-card size="small" :body-style="{ padding: '4px 12px 12px' }">
      <a-tabs v-model:active-key="tab" size="small">
        <a-tab-pane key="live" :tab="T('Control board', 'Tablero de control')">
          <TransportLiveBoard pick-date />
        </a-tab-pane>

        <a-tab-pane key="punctuality" :tab="T('Punctuality', 'Puntualidad')">
          <div class="tm-filters"><PresetRangeFilter v-model:value="range" value-format="YYYY-MM-DD" @change="loadPunctuality" /></div>
          <a-spin :spinning="punctualityLoading">
            <a-row :gutter="[12, 12]">
              <a-col v-for="g in punctualityGroups" :key="g.key" :xs="24" :xl="8">
                <div class="tm-sub">{{ g.title }}</div>
                <a-table :columns="punctualityColumns" :data-source="g.rows" row-key="name" size="small" :pagination="false" :scroll="{ x: 'max-content' }">
                  <template #bodyCell="{ column, record }">
                    <template v-if="column.key === 'pct'"><span :class="{ 'tm-bad': record.on_time_pct !== null && record.on_time_pct < 80 }">{{ record.on_time_pct === null ? '—' : record.on_time_pct + ' %' }}</span></template>
                    <template v-else-if="column.key === 'avg'">{{ record.avg_delay === null ? '—' : record.avg_delay + ' min' }}</template>
                  </template>
                </a-table>
              </a-col>
            </a-row>
          </a-spin>
        </a-tab-pane>
      </a-tabs>
    </a-card>
  </div>
</template>

<script setup>
/** Transport -> Vehicle monitoring (2026-11-06): the control board and punctuality by route, bus and pilot. */
import { computed, onMounted, ref } from 'vue';
import { message } from 'ant-design-vue';
import dayjs from 'dayjs';
import PageHeader from '../../components/PageHeader.vue';
import PresetRangeFilter from '../../components/PresetRangeFilter.vue';
import TransportLiveBoard from '../../components/TransportLiveBoard.vue';
import http from '../../lib/http';
import { useUiStore } from '../../stores/ui';

const ui = useUiStore();
const es = computed(() => ui.locale === 'es');
const T = (en, sp) => (es.value ? sp : en);
const errorOf = e => { const errs = e?.data?.errors; return (errs && Object.values(errs)[0]?.[0]) || e?.data?.message || e.message; };

const tab = ref('live');
const range = ref([dayjs().subtract(30, 'day').format('YYYY-MM-DD'), dayjs().format('YYYY-MM-DD')]);
const punctuality = ref({ routes: [], vehicles: [], pilots: [] });
const punctualityLoading = ref(false);
const punctualityGroups = computed(() => [
  { key: 'routes', title: T('By route', 'Por ruta'), rows: punctuality.value.routes },
  { key: 'vehicles', title: T('By bus', 'Por bus'), rows: punctuality.value.vehicles },
  { key: 'pilots', title: T('By pilot', 'Por piloto'), rows: punctuality.value.pilots },
]);
const punctualityColumns = computed(() => [
  { title: T('Name', 'Nombre'), dataIndex: 'name', key: 'name' },
  { title: T('Trips', 'Viajes'), dataIndex: 'trips', key: 'trips', align: 'right' },
  { title: T('On time', 'A tiempo'), key: 'pct', align: 'right' },
  { title: T('Delay', 'Retraso'), key: 'avg', align: 'right' },
]);
async function loadPunctuality() {
  punctualityLoading.value = true;
  try {
    punctuality.value = await http.get('transport/control/punctuality', { from: range.value?.[0], to: range.value?.[1] });
  } catch (e) {
    message.error(errorOf(e));
  } finally {
    punctualityLoading.value = false;
  }
}
onMounted(loadPunctuality);
</script>

<style scoped>
.tm-filters { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 10px; }
.tm-sub { font-weight: 700; margin: 6px 0; }
.tm-bad { color: #dc2626; font-weight: 600; }
</style>
