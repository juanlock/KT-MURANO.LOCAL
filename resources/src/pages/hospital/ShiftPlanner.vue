<template>
  <!--
    Shift planning by month / semester (user's analysis, 2026-10-25, phase 4):
    repeat a model week over the period, optionally rotating each person to
    the next shift every N weeks; a calendar shows the coverage of each day
    (green = full, orange = some places empty, red = nobody) and the plans
    made can be undone from today on.
  -->
  <a-drawer :open="open" :width="1000" :title="s('Plan month / semester')" @close="$emit('close')">
    <a-form layout="vertical">
      <a-row :gutter="12">
        <a-col :xs="24" :md="8">
          <a-form-item :label="s('Period')">
            <a-radio-group v-model:value="kind" button-style="solid" @change="loadCoverage">
              <a-radio-button value="month">{{ s('Month') }}</a-radio-button>
              <a-radio-button value="semester">{{ s('Semester') }}</a-radio-button>
              <a-radio-button value="custom">{{ s('Other') }}</a-radio-button>
            </a-radio-group>
          </a-form-item>
        </a-col>
        <a-col :xs="24" :md="8">
          <a-form-item v-if="kind !== 'custom'" :label="kind === 'month' ? s('Month') : s('First month of the semester')">
            <a-date-picker v-model:value="month" picker="month" format="MM/YYYY" value-format="YYYY-MM" :allow-clear="false" style="width: 100%" @change="loadCoverage" />
          </a-form-item>
          <a-form-item v-else :label="s('From / to')">
            <a-space>
              <a-date-picker v-model:value="from" format="DD/MM/YYYY" value-format="YYYY-MM-DD" :allow-clear="false" @change="loadCoverage" />
              <a-date-picker v-model:value="to" format="DD/MM/YYYY" value-format="YYYY-MM-DD" :allow-clear="false" @change="loadCoverage" />
            </a-space>
          </a-form-item>
        </a-col>
        <a-col :xs="24" :md="8">
          <a-form-item :label="s('Model week starts on')" :extra="s('Fill in one week by hand; the plan repeats it day by day.')">
            <a-date-picker v-model:value="modelFrom" format="DD/MM/YYYY" value-format="YYYY-MM-DD" :allow-clear="false" style="width: 100%" />
          </a-form-item>
        </a-col>
      </a-row>
      <a-row :gutter="12" align="bottom">
        <a-col :xs="24" :md="8">
          <a-form-item :label="s('Rotation')">
            <a-select v-model:value="rotate" :options="rotateOptions" />
          </a-form-item>
        </a-col>
        <a-col :xs="24" :md="8">
          <a-form-item :label="s('Name (optional)')">
            <a-input v-model:value="name" :maxlength="120" :placeholder="periodLabel" />
          </a-form-item>
        </a-col>
        <a-col :xs="24" :md="8">
          <a-form-item>
            <a-space>
              <a-button :loading="busy" @click="run(true)">{{ s('Preview') }}</a-button>
              <a-button type="primary" :loading="busy" @click="run(false)">{{ s('Create the plan') }}</a-button>
            </a-space>
          </a-form-item>
        </a-col>
      </a-row>
    </a-form>

    <a-alert v-if="previewed" type="info" show-icon style="margin-bottom: 12px"
      :message="s('{n} shifts will be created; {k} skipped (place taken or the person already works that day); {l} skipped for approved leave.', { n: previewed.to_create, k: previewed.skipped, l: previewed.on_leave })" />

    <div class="cal-head">
      <b>{{ s('Coverage') }} · {{ periodLabel }}</b>
      <span class="muted">{{ s('{n} shifts with empty places', { n: cov.gaps || 0 }) }}</span>
      <span class="legend"><i class="lg full" />{{ s('Full') }} <i class="lg part" />{{ s('Places empty') }} <i class="lg none" />{{ s('Nobody') }}</span>
    </div>
    <a-spin :spinning="loading">
      <div class="cal">
        <div v-for="w in weekdays" :key="w" class="wd">{{ w }}</div>
        <div v-for="n in lead" :key="'b' + n" class="day blank" />
        <button v-for="d in cov.days || []" :key="d.date" type="button" class="day" :class="{ today: d.date === today }" @click="$emit('go', d.date)">
          <div class="dn">{{ d.date.slice(8) }}<span v-if="d.date.slice(8) === '01'" class="mn">{{ monthName(d.date) }}</span></div>
          <div class="dots">
            <a-tooltip v-for="sh in shifts" :key="sh.code" :title="tip(d, sh)">
              <span class="dot" :class="state(d, sh)" :style="{ borderColor: sh.color || '#94a3b8' }" />
            </a-tooltip>
          </div>
        </button>
      </div>
    </a-spin>

    <div class="h">{{ s('Plans made') }}</div>
    <a-table :data-source="cov.plans || []" :columns="planColumns" size="small" row-key="id" :pagination="false">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'period'">{{ fmt(record.from_date) }} – {{ fmt(record.to_date) }}</template>
        <template v-else-if="column.key === 'rotate'">{{ record.rotate_weeks ? s('every {n} week(s)', { n: record.rotate_weeks }) : s('No rotation') }}</template>
        <template v-else-if="column.key === 'actions'">
          <a-popconfirm :title="s('Remove this plan\'s shifts from today on? The days already worked stay.')" :ok-text="s('Yes')" :cancel-text="s('No')" @confirm="undo(record)">
            <a-button size="small" danger>{{ s('Undo') }}</a-button>
          </a-popconfirm>
        </template>
      </template>
    </a-table>
  </a-drawer>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import dayjs from 'dayjs';
import { useI18n } from 'vue-i18n';
import { message } from 'ant-design-vue';
import http from '../../lib/http';
import { useHospitalText } from './lang/useHospitalText';
import ES from './lang/StaffShifts.es.js';

const props = defineProps({ open: Boolean, date: { type: String, default: null } });
const emit = defineEmits(['close', 'changed', 'go']);
const { s } = useHospitalText(ES);
const { locale } = useI18n();
const loc = computed(() => (String(locale.value || '').startsWith('es') ? 'es' : 'en'));

const today = dayjs().format('YYYY-MM-DD');
const kind = ref('month');
const month = ref(dayjs().add(1, 'month').format('YYYY-MM'));
const from = ref(dayjs().format('YYYY-MM-DD'));
const to = ref(dayjs().add(27, 'day').format('YYYY-MM-DD'));
const modelFrom = ref(dayjs().startOf('week').add(1, 'day').format('YYYY-MM-DD'));
const rotate = ref(0);
const name = ref('');
const busy = ref(false);
const loading = ref(false);
const previewed = ref(null);
const cov = ref({ days: [], gaps: 0, shifts: [], plans: [] });

// Fixed order (no rotation first, then by frequency), not alphabetical.
const rotateOptions = computed(() => [
  { value: 0, label: s('No rotation (same shift every week)') },
  { value: 1, label: s('Every week (morning -> afternoon -> night)') },
  { value: 2, label: s('Every 2 weeks') },
  { value: 4, label: s('Every 4 weeks') },
]);
const range = computed(() => {
  if (kind.value === 'custom') return [from.value, to.value];
  const start = dayjs(`${month.value}-01`);
  const end = kind.value === 'month' ? start.endOf('month') : start.add(5, 'month').endOf('month');
  return [start.format('YYYY-MM-DD'), end.format('YYYY-MM-DD')];
});
const fmt = d => (d ? dayjs(d).format('DD/MM/YYYY') : '');
const periodLabel = computed(() => `${fmt(range.value[0])} – ${fmt(range.value[1])}`);
const shifts = computed(() => cov.value.shifts || []);
// Monday-first calendar.
const weekdays = computed(() => [1, 2, 3, 4, 5, 6, 0].map(i => dayjs().day(i).toDate().toLocaleDateString(loc.value, { weekday: 'short' })));
const lead = computed(() => (cov.value.days?.length ? (dayjs(cov.value.days[0].date).day() + 6) % 7 : 0));
const monthName = d => dayjs(d).toDate().toLocaleDateString(loc.value, { month: 'short' });
const planColumns = computed(() => [
  { title: s('Name'), dataIndex: 'name' }, { title: s('Period'), key: 'period' }, { title: s('Rotation'), key: 'rotate' },
  { title: s('Shifts'), dataIndex: 'rows_created', width: 90 }, { title: '', key: 'actions', width: 90 },
]);

function state(d, sh) {
  const x = d.shifts[sh.code];
  if (!x) return '';
  const need = Object.values(x.positions).reduce((n, p) => n + p.need, 0);
  if (!x.missing) return 'full';
  return x.missing >= need ? 'none' : 'part';
}
function tip(d, sh) {
  const x = d.shifts[sh.code];
  if (!x) return sh.name;
  const POS = { er_doctor: 'Emergency doctor', specialist: 'Specialists', nurse_supervisor: 'Nurse supervisor', nurse: 'Nurses' };
  return `${sh.name}: ${Object.entries(x.positions).map(([p, v]) => `${s(POS[p] || p)} ${v.have}/${v.need}`).join(' · ')}`;
}

async function loadCoverage() {
  previewed.value = null;
  loading.value = true;
  try {
    cov.value = await http.get('hospital/shifts/coverage', { from: range.value[0], to: range.value[1] });
  } catch (e) {
    message.error(s(e?.data?.message || 'Could not load'));
  } finally {
    loading.value = false;
  }
}

async function run(preview) {
  busy.value = true;
  try {
    const res = await http.post('hospital/shifts/plan', {
      from: range.value[0], to: range.value[1], model_from: modelFrom.value, rotate_weeks: rotate.value,
      name: name.value || null, preview: preview ? 1 : 0,
    });
    if (preview) {
      previewed.value = res;
    } else {
      message.success(s('{n} shifts created.', { n: res.to_create }));
      name.value = '';
      await loadCoverage();
      emit('changed');
    }
  } catch (e) {
    message.error(s(e?.data?.message || (e?.data?.errors && Object.values(e.data.errors)[0]?.[0]) || 'Could not save'));
  } finally {
    busy.value = false;
  }
}

async function undo(p) {
  try {
    const res = await http.delete(`hospital/shifts/plans/${p.id}`);
    message.success(s('{n} shifts removed.', { n: res.removed }));
    await loadCoverage();
    emit('changed');
  } catch (e) {
    message.error(s(e?.data?.message || 'Could not delete'));
  }
}

watch(() => props.open, (o) => {
  if (!o) return;
  // The model week = the week of the day shown on the roster.
  if (props.date) modelFrom.value = dayjs(props.date).subtract((dayjs(props.date).day() + 6) % 7, 'day').format('YYYY-MM-DD');
  loadCoverage();
}, { immediate: true });
</script>

<style scoped>
.muted { opacity: 0.6; font-size: 12px; }
.h { font-weight: 700; margin: 18px 0 8px; }
.cal-head { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 8px; }
.legend { margin-left: auto; font-size: 12px; display: flex; align-items: center; gap: 6px; }
.lg { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-left: 6px; }
.cal { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }
.wd { text-align: center; font-size: 12px; font-weight: 600; opacity: 0.7; text-transform: capitalize; }
.day { border: 1px solid rgba(128, 128, 128, 0.22); border-radius: 8px; padding: 4px 6px; min-height: 52px; background: transparent; cursor: pointer; text-align: left; color: inherit; font: inherit; }
.day:hover { border-color: #1677ff; }
.day.blank { border: 0; cursor: default; }
.day.today { box-shadow: 0 0 0 2px #1677ff inset; }
.dn { font-weight: 600; font-size: 12.5px; }
.mn { margin-left: 4px; font-weight: 400; opacity: 0.6; text-transform: capitalize; }
.dots { display: flex; gap: 3px; flex-wrap: wrap; margin-top: 4px; }
.dot { width: 12px; height: 12px; border-radius: 50%; border: 2px solid; background: #e5e7eb; }
.dot.full, .lg.full { background: #16a34a; }
.dot.part, .lg.part { background: #f59e0b; }
.dot.none, .lg.none { background: #dc2626; }
</style>
