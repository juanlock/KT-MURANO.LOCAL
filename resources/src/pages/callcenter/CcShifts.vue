<template>
  <!-- Agents' shifts (2026-11-17): the week planned per agent against what they worked (sessions: first start, hours, pauses). -->
  <div class="page">
    <PageHeader :title="tr('Agents\' shifts', 'Turnos de agentes')" :breadcrumb="[tr('Call center', 'Centro de llamadas'), tr('Shifts', 'Turnos')]">
      <template #actions>
        <a-button @click="copyWeek"><CopyOutlined /> {{ tr('Copy last week', 'Copiar la semana anterior') }}</a-button>
        <a-button type="primary" @click="openForm()"><PlusOutlined /> {{ tr('Assign shifts', 'Asignar turnos') }}</a-button>
      </template>
    </PageHeader>

    <a-card size="small">
      <div class="week-nav">
        <a-button @click="move(-1)"><LeftOutlined /></a-button>
        <b>{{ tr('Week of', 'Semana del') }} {{ fmt.date(week) }}</b>
        <a-button @click="move(1)"><RightOutlined /></a-button>
        <a-button size="small" @click="week = monday(dayjs()); load()">{{ tr('This week', 'Esta semana') }}</a-button>
        <span class="legend">
          <span><span class="dot plan" /> {{ tr('planned', 'planificado') }}</span>
          <span><span class="dot real" /> {{ tr('worked', 'trabajado') }}</span>
          <a-tag color="orange">{{ tr('late', 'tarde') }}</a-tag>
          <a-tag color="red">{{ tr('absent', 'falta') }}</a-tag>
        </span>
      </div>
      <a-table :data-source="rows" :columns="columns" :loading="loading" row-key="user_id" size="small" :pagination="false" :scroll="{ x: 1100 }" class="mt8">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'name'"><b>{{ record.name }}</b></template>
          <template v-else-if="column.day">
            <div class="cell" :class="{ today: column.day === todayStr }" @click="openForm(record, column.day)">
              <div v-if="record.cells[column.day].shift" class="plan">{{ record.cells[column.day].shift.start }}–{{ record.cells[column.day].shift.end }}</div>
              <div v-else class="muted">—</div>
              <div v-if="record.cells[column.day].worked_sec" class="real">
                {{ record.cells[column.day].first }}–{{ record.cells[column.day].last }} · {{ hours(record.cells[column.day].worked_sec) }}
              </div>
              <a-tag v-if="record.cells[column.day].late_min" color="orange" class="t">+{{ record.cells[column.day].late_min }} min</a-tag>
              <a-tag v-if="record.cells[column.day].absent" color="red" class="t">{{ tr('absent', 'falta') }}</a-tag>
              <div v-if="record.cells[column.day].paused_sec" class="muted small">{{ tr('pauses', 'pausas') }} {{ duration(record.cells[column.day].paused_sec) }}</div>
            </div>
          </template>
          <template v-else-if="column.key === 'total'">
            <div>{{ record.planned_h }} h <span class="muted small">{{ tr('planned', 'plan') }}</span></div>
            <div class="real">{{ record.worked_h }} h <span class="muted small">{{ tr('worked', 'trab.') }}</span></div>
            <div class="muted small">{{ record.paused_h }} h {{ tr('pauses', 'pausas') }}</div>
          </template>
        </template>
      </a-table>
    </a-card>

    <a-modal :open="!!form" :title="tr('Assign shifts', 'Asignar turnos')" :confirm-loading="saving" :ok-text="tr('Save', 'Guardar')" @ok="save" @cancel="form = null">
      <a-form v-if="form" layout="vertical">
        <a-form-item :label="tr('Agents', 'Agentes')">
          <a-select v-model:value="form.user_ids" mode="multiple" :options="agentOptions" />
        </a-form-item>
        <a-form-item :label="tr('Days', 'Días')">
          <a-checkbox-group v-model:value="form.days" :options="dayOptions" />
        </a-form-item>
        <a-space>
          <a-form-item :label="tr('From', 'Desde')"><a-time-picker v-model:value="form.start_time" value-format="HH:mm" format="HH:mm" :minute-step="15" /></a-form-item>
          <a-form-item :label="tr('To', 'Hasta')"><a-time-picker v-model:value="form.end_time" value-format="HH:mm" format="HH:mm" :minute-step="15" /></a-form-item>
        </a-space>
        <a-form-item :label="tr('Campaign (optional)', 'Campaña (opcional)')">
          <a-select v-model:value="form.campaign_id" :options="campaignOptions" allow-clear />
        </a-form-item>
        <a-form-item :label="tr('Note', 'Nota')"><a-input v-model:value="form.notes" /></a-form-item>
      </a-form>
      <template v-if="form?.shift_id" #footer>
        <div class="foot">
          <a-popconfirm :title="tr('Remove this shift?', '¿Quitar este turno?')" @confirm="removeShift"><a-button danger>{{ tr('Remove', 'Quitar') }}</a-button></a-popconfirm>
          <span class="grow" />
          <a-button @click="form = null">{{ tr('Cancel', 'Cancelar') }}</a-button>
          <a-button type="primary" :loading="saving" @click="save">{{ tr('Save', 'Guardar') }}</a-button>
        </div>
      </template>
    </a-modal>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import dayjs from 'dayjs';
import { PlusOutlined, CopyOutlined, LeftOutlined, RightOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import { sortByLabel } from '../../lib/filterOptions';
import http from '../../lib/http';
import { useTr, duration, hours, errText } from '../../lib/callcenter';

const tr = useTr();
const fmt = useFormat();
const monday = d => d.subtract((d.day() + 6) % 7, 'day').format('YYYY-MM-DD');
const week = ref(monday(dayjs()));
const days = ref([]);
const rows = ref([]);
const loading = ref(false);
const saving = ref(false);
const form = ref(null);
const opts = ref({ campaigns: [], agents: [] });
const todayStr = dayjs().format('YYYY-MM-DD');

const DAY_NAMES = () => [tr('Mon', 'Lun'), tr('Tue', 'Mar'), tr('Wed', 'Mié'), tr('Thu', 'Jue'), tr('Fri', 'Vie'), tr('Sat', 'Sáb'), tr('Sun', 'Dom')];
const columns = computed(() => [
  { title: tr('Agent', 'Agente'), key: 'name', width: 150, fixed: 'left' },
  ...days.value.map((d, i) => ({ title: `${DAY_NAMES()[i]} ${fmt.dayMonth(d)}`, key: d, day: d, width: 130 })),
  { title: tr('Week', 'Semana'), key: 'total', width: 120 },
]);
const dayOptions = computed(() => days.value.map((d, i) => ({ value: d, label: `${DAY_NAMES()[i]} ${fmt.dayMonth(d)}` })));
const agentOptions = computed(() => sortByLabel(opts.value.agents.map(a => ({ value: a.id, label: a.username }))));
const campaignOptions = computed(() => sortByLabel(opts.value.campaigns.filter(c => c.status !== 'finished').map(c => ({ value: c.id, label: c.name }))));

async function load() {
  loading.value = true;
  try {
    const r = await http.get('callcenter/shifts', { week: week.value });
    days.value = r.days;
    rows.value = r.rows;
  } catch (e) {
    message.error(errText(e, tr('Could not load.', 'No se pudo cargar.')));
  } finally {
    loading.value = false;
  }
}

function move(n) {
  week.value = dayjs(week.value).add(n * 7, 'day').format('YYYY-MM-DD');
  load();
}

function openForm(row, day) {
  const s = row && day ? row.cells[day].shift : null;
  form.value = {
    user_ids: row ? [row.user_id] : [], days: day ? [day] : days.value.slice(0, 5),
    start_time: s?.start || '09:00', end_time: s?.end || '18:00', campaign_id: s?.campaign_id || undefined, notes: s?.notes || '', shift_id: s?.id || null,
  };
}

async function save() {
  const f = form.value;
  if (!f.user_ids.length || !f.days.length) return message.warning(tr('Pick agents and days.', 'Elige agentes y días.'));
  saving.value = true;
  try {
    await http.post('callcenter/shifts', { ...f, campaign_id: f.campaign_id || null });
    form.value = null;
    load();
  } catch (e) {
    message.error(errText(e, tr('Could not save.', 'No se pudo guardar.')));
  } finally {
    saving.value = false;
  }
}

async function removeShift() {
  try {
    await http.delete(`callcenter/shifts/${form.value.shift_id}`);
    form.value = null;
    load();
  } catch (e) {
    message.error(errText(e, tr('Could not remove it.', 'No se pudo quitar.')));
  }
}

async function copyWeek() {
  try {
    const r = await http.post('callcenter/shifts/copy', { week: week.value });
    message.success(tr(`${r.copied} shifts copied.`, `${r.copied} turnos copiados.`));
    load();
  } catch (e) {
    message.error(errText(e, tr('Could not copy.', 'No se pudo copiar.')));
  }
}

onMounted(async () => {
  load();
  try {
    opts.value = await http.get('callcenter/campaigns/options');
  } catch (e) { /* the form still works without campaigns */ }
});
</script>

<style scoped>
.week-nav { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.legend { margin-left: auto; display: flex; gap: 10px; align-items: center; font-size: 12px; }
.dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 3px; }
.dot.plan { background: #1677ff; }
.dot.real { background: #389e0d; }
.mt8 { margin-top: 8px; }
.cell { cursor: pointer; min-height: 44px; padding: 2px 4px; border-radius: 6px; }
.cell:hover { background: rgba(22, 119, 255, 0.08); }
.cell.today { outline: 1px dashed rgba(22, 119, 255, 0.5); }
.plan { color: #1677ff; font-weight: 600; font-size: 12.5px; }
.real { color: #389e0d; font-size: 12px; }
.muted { opacity: 0.6; }
.small { font-size: 11.5px; }
.t { font-size: 11px; line-height: 16px; padding: 0 4px; margin-top: 2px; }
.foot { display: flex; gap: 8px; align-items: center; }
.grow { flex: 1; }
</style>
