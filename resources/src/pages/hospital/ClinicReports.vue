<template>
  <div class="page">
    <PageHeader :title="s('Clinic reports')" :breadcrumb="[s('Clinic'), s('Clinic reports')]" />
    <a-card size="small" style="margin-bottom: 12px">
      <PresetRangeFilter v-model:value="range" value-format="YYYY-MM-DD" @change="load" />
    </a-card>

    <a-spin :spinning="loading">
      <div class="kpis">
        <div class="kpi"><div class="kpi-v">{{ t.attended ?? 0 }}</div><div class="kpi-l">{{ s('Patients attended') }}</div><div class="kpi-s">{{ t.first_visits ?? 0 }} {{ s('first visits') }}</div></div>
        <div class="kpi"><div class="kpi-v">{{ money(t.revenue ?? 0) }}</div><div class="kpi-l">{{ s('Billed') }}</div><div class="kpi-s">{{ s('collected') }} {{ money(t.collected ?? 0) }}</div></div>
        <div class="kpi"><div class="kpi-v" :class="{ bad: t.no_show_rate > 15 }">{{ t.no_show_rate ?? '—' }}<small v-if="t.no_show_rate != null">%</small></div><div class="kpi-l">{{ s('No-show rate') }}</div><div class="kpi-s">{{ t.no_show ?? 0 }} {{ s('no-shows') }} · {{ t.cancelled ?? 0 }} {{ s('cancelled') }}</div></div>
        <div class="kpi"><div class="kpi-v">{{ t.avg_wait ?? '—' }}<small v-if="t.avg_wait != null"> min</small></div><div class="kpi-l">{{ s('Average wait (check-in to consultation)') }}</div></div>
        <div class="kpi"><div class="kpi-v">{{ t.avg_consult ?? '—' }}<small v-if="t.avg_consult != null"> min</small></div><div class="kpi-l">{{ s('Average consultation') }}</div></div>
        <div class="kpi"><div class="kpi-v" :class="npsClass(t.nps)">{{ t.nps ?? '—' }}</div><div class="kpi-l">NPS</div><div class="kpi-s">{{ t.surveys ?? 0 }} {{ s('answers') }}</div></div>
      </div>

      <a-card size="small" :title="s('Patients attended per day')" style="margin-bottom: 12px">
        <div class="bars">
          <div v-for="x in data.daily" :key="x.date" class="bar" :title="`${x.date}: ${x.attended} · ${s('no-shows')} ${x.no_show}`">
            <div class="b-fill" :style="{ height: `${(x.attended / maxDaily) * 100}%` }"></div>
            <div class="b-ns" :style="{ height: `${(x.no_show / maxDaily) * 100}%` }"></div>
            <div class="b-lbl">{{ x.date.slice(8) }}</div>
          </div>
        </div>
      </a-card>

      <a-card size="small" :title="s('By doctor')" style="margin-bottom: 12px">
        <a-table :data-source="data.by_doctor" :columns="columns" row-key="doctor_id" size="small" :pagination="false" :scroll="{ x: 900 }">
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'doctor'"><b>{{ record.doctor }}</b><div class="muted">{{ record.specialty }}</div></template>
            <template v-else-if="column.key === 'revenue'">{{ money(record.revenue) }}</template>
            <template v-else-if="column.key === 'no_show_rate'">{{ record.no_show_rate ?? '—' }}<template v-if="record.no_show_rate != null">%</template></template>
            <template v-else-if="column.key === 'nps'"><span :class="npsClass(record.nps)">{{ record.nps ?? '—' }}</span> <span class="muted">({{ record.surveys }})</span></template>
            <template v-else-if="['avg_wait', 'avg_consult'].includes(column.key)">{{ record[column.key] ?? '—' }}<template v-if="record[column.key] != null"> min</template></template>
          </template>
        </a-table>
      </a-card>

      <a-card size="small" :title="s('Latest comments')">
        <a-empty v-if="!data.comments.length" :description="s('No comments')" />
        <div v-for="(c, i) in data.comments" :key="i" class="comment">
          <a-tag :color="c.score >= 9 ? 'green' : c.score >= 7 ? 'gold' : 'red'">{{ c.score }}</a-tag>
          <span>{{ c.comment }}</span> <span class="muted">· {{ c.doctor }} · {{ (c.answered_at || '').slice(0, 10) }}</span>
        </div>
      </a-card>
    </a-spin>
  </div>
</template>

<script setup>
/**
 * Clinic reports (specialist clinic, 2026-10-26): patients attended, billing,
 * no-show rate, average wait and consultation times, NPS (promoters 9-10 minus
 * detractors 0-6) and the survey comments, in total and by doctor.
 */
import { ref, computed, onMounted } from 'vue';
import dayjs from 'dayjs';
import { message } from 'ant-design-vue';
import PageHeader from '../../components/PageHeader.vue';
import PresetRangeFilter from '../../components/PresetRangeFilter.vue';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';
import { useHospitalText } from './lang/useHospitalText';
import ES from './lang/ClinicFlow.es.js';

const { s } = useHospitalText(ES);
const { money } = useFormat();
const range = ref([dayjs().startOf('month').format('YYYY-MM-DD'), dayjs().format('YYYY-MM-DD')]);
const loading = ref(false);
const data = ref({ totals: {}, by_doctor: [], daily: [], comments: [] });
const t = computed(() => data.value.totals || {});
const maxDaily = computed(() => Math.max(1, ...data.value.daily.map(x => x.attended + x.no_show)));
const npsClass = v => (v == null ? '' : v >= 50 ? 'good' : v >= 0 ? 'mid' : 'bad');
const columns = computed(() => [
  { title: s('Doctor'), key: 'doctor' }, { title: s('Attended'), dataIndex: 'attended', width: 90 }, { title: s('First visits'), dataIndex: 'first_visits', width: 100 },
  { title: s('No-show rate'), key: 'no_show_rate', width: 110 }, { title: s('Billed'), key: 'revenue', width: 120 },
  { title: s('Avg. wait'), key: 'avg_wait', width: 100 }, { title: s('Avg. consultation'), key: 'avg_consult', width: 120 }, { title: 'NPS', key: 'nps', width: 100 },
]);

async function load() {
  loading.value = true;
  try {
    data.value = await http.get('hospital/clinic/reports', { start_date: range.value?.[0] || '', end_date: range.value?.[1] || '' });
  } catch (e) {
    message.error(s(e?.data?.message || 'Could not load'));
  } finally {
    loading.value = false;
  }
}
onMounted(load);
</script>

<style scoped>
.kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; margin-bottom: 12px; }
.kpi { padding: 12px 14px; border-radius: 12px; background: rgba(128, 128, 128, 0.08); }
.kpi-v { font-size: 24px; font-weight: 700; }
.kpi-v small { font-size: 13px; }
.kpi-l { font-size: 12px; opacity: 0.75; }
.kpi-s { font-size: 11px; opacity: 0.55; }
.good { color: #16a34a; }
.mid { color: #ca8a04; }
.bad { color: #dc2626; }
.bars { display: flex; align-items: flex-end; gap: 4px; height: 160px; padding-bottom: 18px; overflow-x: auto; }
.bar { position: relative; flex: 1; min-width: 14px; height: 100%; display: flex; flex-direction: column; justify-content: flex-end; }
.b-fill { background: #2563eb; border-radius: 3px 3px 0 0; }
.b-ns { background: #dc2626; opacity: 0.7; }
.b-lbl { position: absolute; bottom: -16px; width: 100%; text-align: center; font-size: 10px; opacity: 0.6; }
.muted { opacity: 0.6; font-size: 12px; }
.comment { padding: 6px 0; border-bottom: 1px dashed rgba(128, 128, 128, 0.2); }
</style>
