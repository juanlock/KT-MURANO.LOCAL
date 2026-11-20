<template>
  <div :class="{ page: !fixedProject }">
    <PageHeader v-if="!fixedProject" :title="$t('Project Reports')" :breadcrumb="[$t('Projects Management'), $t('Reports')]">
      <template #actions>
        <a-button :loading="exporting === 'excel'" @click="doExport('excel')">
          <template #icon><FileExcelOutlined /></template>
          {{ $t('Export') }} Excel
        </a-button>
        <a-button :loading="exporting === 'pdf'" @click="doExport('pdf')">
          <template #icon><FilePdfOutlined /></template>
          {{ $t('Export') }} PDF
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <a-tabs v-model:activeKey="report" @change="onReportChange">
        <a-tab-pane v-for="r in REPORT_LIST" :key="r.key" :tab="r.title" />
      </a-tabs>

      <div class="toolbar">
        <a-input-search
          v-model:value="crud.search.value" :placeholder="current.searchHint"
          allow-clear class="tb-search" @search="crud.reload"
        />
        <template v-if="fixedProject">
          <a-button :loading="exporting === 'excel'" @click="doExport('excel')">
            <template #icon><FileExcelOutlined /></template>
            Excel
          </a-button>
          <a-button :loading="exporting === 'pdf'" @click="doExport('pdf')">
            <template #icon><FilePdfOutlined /></template>
            PDF
          </a-button>
        </template>
        <a-select
          v-if="report !== 'delivery' && !fixedProject"
          v-model:value="projectId" class="tb-item" allow-clear show-search
          option-filter-prop="label" :placeholder="$t('All_Projects')"
          :options="projectOptions" @change="crud.reload"
        />
        <a-select
          v-if="report === 'delivery'"
          v-model:value="status" class="tb-item-sm" allow-clear
          :placeholder="$t('Status')" :options="PROJECT_STATUSES()" @change="crud.reload"
        />
        <PresetRangeFilter
          v-if="current.dateRange"
          v-model:value="range" class="tb-range" value-format="YYYY-MM-DD" @change="crud.reload"
        />
      </div>

      <div v-if="totalTiles.length" class="totals">
        <div v-for="tile in totalTiles" :key="tile.label" class="total">
          <span class="total-label">{{ tile.label }}</span>
          <span class="total-value">{{ tile.value }}</span>
        </div>
      </div>
    </a-card>

    <DataTable :crud="crud" :columns="columns" :searchable="false">
      <template #bodyCell="{ column, record }">
        <template v-if="column.money">{{ money(record[column.dataIndex] || 0) }}</template>
        <template v-else-if="column.hours">{{ number(record[column.dataIndex] || 0, 2) }}</template>
        <template v-else-if="column.percent">
          <span v-if="record[column.dataIndex] === null || record[column.dataIndex] === undefined" class="muted">—</span>
          <a-progress
            v-else :percent="record[column.dataIndex]" size="small"
            :stroke-color="progressColor(record[column.dataIndex])"
          />
        </template>
        <template v-else-if="column.key === 'title'">
          <a class="link" @click="$router.push(`/projects/${record.id}`)">{{ record.title }}</a>
          <div v-if="record.client_name" class="cell-sub">{{ record.client_name }}</div>
        </template>
        <template v-else-if="column.key === 'status'">
          <a-tag :color="optionOf(PROJECT_STATUSES, record.status).color">
            {{ labelOf(PROJECT_STATUSES, record.status) }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'milestone_status'">
          <a-tag :color="optionOf(MILESTONE_STATUSES, record.status).color">
            {{ labelOf(MILESTONE_STATUSES, record.status) }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'end_date'">
          <span v-if="record.end_date">
            {{ date(record.end_date) }}
            <a-tag v-if="record.is_late" color="error" class="tag-tight">{{ record.days_late + $t('d_late') }}</a-tag>
          </span>
          <span v-else class="muted">—</span>
        </template>
        <template v-else-if="column.key === 'due_date'">
          <span v-if="record.due_date">{{ date(record.due_date) }}</span>
          <span v-else class="muted">—</span>
        </template>
        <template v-else-if="column.key === 'slippage'">
          <span v-if="record.slippage === null" class="muted">{{ $t('Open') }}</span>
          <a-tag v-else :color="record.slippage > 0 ? 'error' : 'success'">
            {{ record.slippage > 0 ? record.slippage + $t('d_late') : Math.abs(record.slippage) + $t('d_early') }}
          </a-tag>
        </template>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
import PresetRangeFilter from '../../components/PresetRangeFilter.vue';
/**
 * Project reports — three views over one shell, sharing the same filters and
 * differing only in columns and endpoint. Switching tabs swaps the endpoint on
 * the SAME crud instance so filters and search survive the switch.
 *
 * Totals come from the server for the whole filtered set, never summed from the
 * page on screen.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { FileExcelOutlined, FilePdfOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { exportExcel, exportPdf } from '../../lib/exporters';
import { PROJECT_STATUSES, MILESTONE_STATUSES, labelOf, optionOf, progressColor } from './workspaceOptions';
import http from '../../lib/http';
import { useTr } from '../../lib/useTr';

// Inside a project (ProjectDetail's "Informe" tab): that project's workload and milestones only.
const props = defineProps({ projectId: { type: [Number, String], default: null } });
const fixedProject = computed(() => (props.projectId ? Number(props.projectId) : null));

const tr = useTr();
const { money, number, date } = useFormat();

// Titles and hints are computed so they follow the language picked.
const REPORT_LIST = computed(() => [
  ...(fixedProject.value ? [] : [{ key: 'delivery', title: tr('Delivery', 'Entregas'), endpoint: 'projects/reports/delivery', sort: 'logged_hours', searchHint: tr('Search project…', 'Buscar proyecto…'), dateRange: true }]),
  { key: 'workload', title: tr('Workload', 'Carga de trabajo'), endpoint: 'projects/reports/workload', sort: 'hours', searchHint: tr('Search person…', 'Buscar persona…'), dateRange: true },
  { key: 'milestones', title: tr('Milestones', 'Hitos'), endpoint: 'projects/reports/milestones', sort: 'due_date', searchHint: tr('Search milestone…', 'Buscar hito…'), dateRange: false },
]);

const report = ref(props.projectId ? 'workload' : 'delivery');
const current = computed(() => REPORT_LIST.value.find(r => r.key === report.value) || REPORT_LIST.value[0]);

const projectId = ref(props.projectId ? Number(props.projectId) : undefined);
const status = ref(undefined);
const range = ref(null);
const endpoint = ref(REPORT_LIST.value[0].endpoint);

const crud = useCrudTable(() => endpoint.value, {
  sortField: REPORT_LIST.value[0].sort,
  select: p => ({ rows: p?.rows || [], total: p?.totalRows || 0 }),
  params: () => ({
    project_id: projectId.value || '',
    status: status.value || '',
    start_date: range.value?.[0] || '',
    end_date: range.value?.[1] || '',
  }),
});

function onReportChange() {
  endpoint.value = current.value.endpoint;
  crud.sortField.value = current.value.sort;
  // Milestones read best oldest-deadline first; the others biggest first.
  crud.sortType.value = report.value === 'milestones' ? 'asc' : 'desc';
  crud.reload();
}

const COLUMNS = computed(() => ({
  delivery: [
    { title: tr('Project', 'Proyecto'), key: 'title', dataIndex: 'title', sorter: true },
    { title: tr('Status', 'Estado'), key: 'status', dataIndex: 'status', width: 140 },
    { title: tr('Due', 'Vence'), key: 'end_date', dataIndex: 'end_date', sorter: true, width: 190 },
    { title: tr('Tasks', 'Tareas'), dataIndex: 'tasks', key: 'tasks', sorter: true, width: 90 },
    { title: tr('Done', 'Hechas'), dataIndex: 'tasks_done', key: 'tasks_done', sorter: true, width: 90 },
    { title: tr('Progress', 'Avance'), dataIndex: 'progress', key: 'progress', sorter: true, percent: true, width: 150 },
    { title: tr('Milestones', 'Hitos'), dataIndex: 'milestones_done', key: 'milestones_done', width: 110 },
    { title: tr('Est. hours', 'Horas estimadas'), dataIndex: 'estimated_hours', key: 'estimated_hours', sorter: true, hours: true, width: 120 },
    { title: tr('Logged', 'Registradas'), dataIndex: 'logged_hours', key: 'logged_hours', sorter: true, hours: true, width: 110 },
    { title: tr('Billable', 'Facturable'), dataIndex: 'billable_amount', key: 'billable_amount', sorter: true, money: true, width: 130 },
  ],
  workload: [
    { title: tr('Person', 'Persona'), dataIndex: 'name', key: 'name', sorter: true },
    { title: tr('Entries', 'Registros'), dataIndex: 'entries', key: 'entries', sorter: true, width: 110 },
    { title: tr('Projects', 'Proyectos'), dataIndex: 'projects', key: 'projects', sorter: true, width: 110 },
    { title: tr('Hours', 'Horas'), dataIndex: 'hours', key: 'hours', sorter: true, hours: true, width: 110 },
    { title: tr('Billable hours', 'Horas facturables'), dataIndex: 'billable_hours', key: 'billable_hours', sorter: true, hours: true, width: 140 },
    { title: tr('Billable %', '% facturable'), dataIndex: 'billable_rate', key: 'billable_rate', sorter: true, percent: true, width: 150 },
    { title: tr('Value', 'Valor'), dataIndex: 'billable_amount', key: 'billable_amount', sorter: true, money: true, width: 130 },
  ],
  milestones: [
    { title: tr('Milestone', 'Hito'), dataIndex: 'title', key: 'title_plain', sorter: true },
    ...(fixedProject.value ? [] : [{ title: tr('Project', 'Proyecto'), dataIndex: 'project_title', key: 'project_title', width: 180 }]),
    { title: tr('Due', 'Vence'), key: 'due_date', dataIndex: 'due_date', sorter: true, width: 130 },
    { title: tr('Status', 'Estado'), key: 'milestone_status', dataIndex: 'status', width: 140 },
    { title: tr('Progress', 'Avance'), dataIndex: 'progress', key: 'progress', sorter: true, percent: true, width: 150 },
    { title: tr('Slippage', 'Desfase'), key: 'slippage', dataIndex: 'slippage', sorter: true, width: 140 },
    { title: tr('Budget', 'Presupuesto'), dataIndex: 'budget', key: 'budget', money: true, width: 120 },
  ],
}));

const columns = computed(() => COLUMNS.value[report.value] || COLUMNS.value.delivery);

const TOTAL_LABELS = computed(() => ({
  tasks: tr('Tasks', 'Tareas'), tasks_done: tr('Tasks done', 'Tareas completadas'),
  logged_hours: tr('Hours logged', 'Horas registradas'), billable_amount: tr('Billable value', 'Valor facturable'),
  hours: tr('Hours', 'Horas'), milestones: tr('Milestones', 'Hitos'), overdue: tr('Overdue', 'Atrasado'),
}));
const MONEY_TOTALS = ['billable_amount'];
const HOUR_TOTALS = ['logged_hours', 'hours'];

const totalTiles = computed(() => {
  const totals = crud.payload.value?.totals || {};
  return Object.entries(totals).map(([key, value]) => ({
    label: TOTAL_LABELS.value[key] || key,
    value: MONEY_TOTALS.includes(key)
      ? money(value || 0)
      : HOUR_TOTALS.includes(key)
        ? number(value || 0, 2) + 'h'
        : number(value || 0, 0),
  }));
});

const projects = ref([]);
const projectOptions = computed(() => projects.value.map(p => ({ value: p.id, label: p.title })));

const exporting = ref('');

async function doExport(kind) {
  exporting.value = kind;
  try {
    // limit=-1 is the legacy "all rows" convention, so the export covers the
    // whole filtered set rather than the visible page.
    const data = await http.get(endpoint.value, {
      page: 1,
      limit: -1,
      SortField: crud.sortField.value,
      SortType: crud.sortType.value,
      search: crud.search.value,
      project_id: projectId.value || '',
      status: status.value || '',
      start_date: range.value?.[0] || '',
      end_date: range.value?.[1] || '',
    });
    const rows = data?.rows || [];
    if (!rows.length) {
      message.warning(tr('Nothing to export', 'No hay nada para exportar'));
      return;
    }
    const title = `${tr('Projects', 'Proyectos')} — ${current.value.title}`;
    if (kind === 'excel') await exportExcel(title, columns.value, rows);
    else await exportPdf(title, columns.value, rows);
  } catch (e) {
    message.error(tr('Could not export this report', 'No se pudo exportar este reporte'));
  } finally {
    exporting.value = '';
  }
}

onMounted(async () => {
  crud.fetchRows();
  if (fixedProject.value) return;
  try {
    const meta = await http.get('projects/meta');
    projects.value = meta?.projects || [];
  } catch (e) { /* the filter stays empty */ }
});
</script>

<style scoped>
.muted {
  color: rgba(128, 128, 128, 0.7);
}
.link {
  color: #6d28d9;
  cursor: pointer;
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}
.tb-search {
  flex: 1 1 220px;
  min-width: 180px;
}
.tb-item {
  width: 180px;
}
.tb-item-sm {
  width: 150px;
}
.tb-range {
  width: 230px;
}
.totals {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 12px;
  margin-top: 16px;
  padding-top: 16px;
  border-top: 1px solid rgba(128, 128, 128, 0.18);
}
.total {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.total-label {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  opacity: 0.55;
}
.total-value {
  font-size: 17px;
  font-weight: 600;
}
.cell-sub {
  font-size: 11.5px;
  opacity: 0.55;
}
.tag-tight {
  margin-inline-start: 6px;
}
</style>
