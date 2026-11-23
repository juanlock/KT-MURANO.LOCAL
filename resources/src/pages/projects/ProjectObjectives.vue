<template>
  <a-spin :spinning="loading">
    <a-row :gutter="16">
      <!-- Objectives -->
      <a-col :span="24">
        <a-card size="small" class="card">
          <template #title>
            <AimOutlined class="ic-title" /> {{ tr('Objectives of the application', 'Objetivos del aplicativo') }}
            <a-tooltip :title="tr('Each objective is worked through its tasks: creating one creates its task and assigns it. Its progress = its tasks completed.', 'Cada objetivo se trabaja con sus tareas: al crearlo se crea su tarea y se asigna. Su avance = sus tareas completadas.')">
              <InfoCircleOutlined class="hint-ic" />
            </a-tooltip>
          </template>
          <template #extra>
            <span class="legend-av"><span class="av" /> {{ ownName }}</span><span class="legend-av"><span class="av client" /> {{ clientName }}</span>
            <span class="sum">{{ doneCount }}/{{ objectives.length }} {{ tr('met', 'cumplidos') }}</span>
            <template v-if="!readonly">
            <a-button type="primary" size="small" @click="openNew(null)"><PlusOutlined /> {{ tr('New objective', 'Nuevo objetivo') }}</a-button>
            </template>
          </template>

          <a-empty v-if="!objectives.length && !entities.length" :description="tr('No objectives yet.', 'Sin objetivos todavía.')" />
          <!-- The four pillars as the main grouping, each with its colour (user, 2026-11-22); an objective shows in every
               pillar where it has tasks, with those tasks; its entity (Jelaf, Operaciones...) is a tag. -->
          <div v-for="g in groups" :key="g.key" class="grp" :class="g.cls">
          <div class="grp-head" :class="{ 'obj-drop': objDrag.overGroup === g.key && objDrag.id }" @dragover.prevent="objDragOverGroup(g)" @drop.prevent="objDropOnGroup(g)">
            <FlagOutlined class="grp-ic" />
            <span class="grp-name">{{ g.name }}</span>
            <span v-if="g.description" class="grp-desc">{{ g.description }}</span>
            <span class="grp-sum">{{ g.done }}/{{ g.total }} {{ tr('tasks done', 'tareas hechas') }}</span>
          </div>
          <div v-if="!g.items.length" class="grp-empty">{{ tr('No objectives in this pillar yet.', 'Sin objetivos en este pilar todavía.') }}</div>
          <div v-for="{ o, ts, key } in g.items" :key="key" class="obj" :class="{ met: o.done }">
            <div
              class="obj-head" :class="{ dragging: objDrag.id === o.id, 'drop-before': objDrag.over === o.id && objDrag.id !== o.id }"
              :draggable="canReorder" @click="toggle(key)"
              @dragstart.self="objDragStart($event, o)" @dragover.prevent="objDragOver(o)" @drop.prevent="objDrop(g, o)" @dragend="objDrag.id = null; objDrag.over = null; objDrag.overGroup = null"
            >
              <HolderOutlined v-if="canReorder" class="grip" :title="tr('Drag to reorder or move to another pillar', 'Arrastra para ordenar o llevar a otro pilar')" @click.stop />
              <component :is="open[key] ? DownOutlined : RightOutlined" class="caret" />
              <span class="num" :class="{ met: o.done }"><CheckOutlined v-if="o.done" /><template v-else>{{ numberOf[o.id] }}</template></span>
              <div class="obj-main">
                <div class="obj-title">{{ o.title }} <a-tag v-if="entityName(o.entity_id)" class="ent-tag">{{ entityName(o.entity_id) }}</a-tag></div>
                <div v-if="o.description" class="obj-desc">{{ o.description }}</div>
              </div>
              <span class="obj-count">{{ ts.length !== o.tasks.length ? `${ts.length} ${tr('of', 'de')} ${o.tasks.length}` : o.tasks.length }} {{ o.tasks.length === 1 ? tr('task', 'tarea') : tr('tasks', 'tareas') }}</span>
              <span class="obj-prog"><a-progress :percent="o.progress" size="small" :stroke-color="progressColor(o.progress)" /></span>
              <span v-if="!readonly" class="acts" @click.stop>
                <a-tooltip :title="tr('Edit', 'Editar')"><a-button type="text" size="small" @click="openEdit(o)"><template #icon><EditOutlined /></template></a-button></a-tooltip>
                <a-tooltip :title="$t('Delete')"><a-button type="text" size="small" danger @click="remove(o)"><template #icon><DeleteOutlined /></template></a-button></a-tooltip>
              </span>
            </div>
            <div v-if="open[key]" class="obj-tasks">
              <template v-for="t in ts" :key="t.id">
                <div
                  class="otask" :class="{ mine: mine.includes(t.id), dragging: drag.id === t.id, 'drop-before': drag.over === t.id && drag.id !== t.id }"
                  :draggable="canReorder" @click="$emit('open-task', t.id)"
                  @dragstart="onDragStart($event, o, t)" @dragover.prevent="onDragOver(o, t)" @drop.prevent="onDrop(o, ts, t)" @dragend="drag.id = null; drag.over = null"
                >
                  <!-- The project manager drags tasks into their order inside the objective (2026-11-22). -->
                  <HolderOutlined v-if="canReorder" class="grip" :title="tr('Drag to reorder', 'Arrastra para ordenar')" />
                  <a-tag :color="projectStatusColor(t.status)" class="t-tag">{{ projectStatusLabel(t.status) }}</a-tag>
                  <span class="t-title">{{ t.title }}</span>
                  <!-- Stage and assigned as columns; assigned as avatars: our staff / the client's in two colours (2026-11-22). -->
                  <span class="t-stage" :title="t.stage || ''"><template v-if="t.stage"><FlagOutlined /> {{ t.stage }}</template></span>
                  <span class="t-avs">
                    <a-tooltip v-for="pp in t.people" :key="pp.id" :title="pp.name">
                      <span class="av" :class="pp.side">{{ pp.initials }}</span>
                    </a-tooltip>
                    <span v-if="!t.people || !t.people.length" class="muted-dash">—</span>
                  </span>
                  <span class="t-date">{{ t.end_date ? date(t.end_date) : '' }}</span>
                  <a-tooltip v-if="canReorder" :title="tr('Edit text, dates and who has it', 'Editar texto, fechas y asignados')">
                    <a-button type="text" size="small" @click.stop="qOpen(t)"><template #icon><EditOutlined /></template></a-button>
                  </a-tooltip>
                  <a-tooltip v-if="!readonly" :title="tr('Untie from this objective', 'Desvincular de este objetivo')">
                    <a-button type="text" size="small" @click.stop="unlink(o, t)"><template #icon><DisconnectOutlined /></template></a-button>
                  </a-tooltip>
                </div>
                <!-- A completed task: one line of what it was, the whole text on demand (2026-11-22). -->
                <div v-if="t.status === 'completed' && (t.requirement || t.reason)" class="t-more" :class="{ open: moreOpen[t.id] }" @click="moreOpen[t.id] = !moreOpen[t.id]">
                  <component :is="moreOpen[t.id] ? DownOutlined : RightOutlined" class="caret" />
                  <div v-if="!moreOpen[t.id]" class="t-line">{{ t.requirement || t.reason }}</div>
                  <div v-else class="t-full">
                    <div v-if="t.requirement"><b>{{ tr('What we sought', 'Qué buscamos') }}:</b> {{ t.requirement }}</div>
                    <div v-if="t.reason"><b>{{ tr('Why', 'Por qué') }}:</b> {{ t.reason }}</div>
                  </div>
                </div>
              </template>
              <div v-if="!readonly" class="link-row">
                <a v-if="!linkShow[o.id]" class="link-add" @click="linkShow[o.id] = true"><PlusOutlined /> {{ tr('Tie an existing task', 'Vincular una tarea existente') }}</a>
                <a-select
                  v-else v-model:value="linkPick[o.id]" size="small" show-search option-filter-prop="label" allow-clear class="link-sel"
                  :options="looseTaskOptions" :placeholder="tr('Tie an existing task…', 'Vincular una tarea existente…')"
                  @change="v => link(o, v)"
                />
              </div>
            </div>
          </div>
          </div>
        </a-card>
      </a-col>
    </a-row>

    <!-- New / edit objective -->
    <a-modal
      :open="formOpen" :title="editing ? tr('Edit objective', 'Editar objetivo') : tr('New objective', 'Nuevo objetivo')" :width="620"
      :confirm-loading="saving" :ok-text="tr('Save', 'Guardar')" :cancel-text="$t('Cancel')" @ok="save" @cancel="formOpen = false"
    >
      <a-form layout="vertical">
        <a-form-item :label="tr('Objective *', 'Objetivo *')">
          <a-input v-model:value="form.title" :placeholder="tr('e.g. Sell tickets in every agency', 'p. ej. Vender pasajes en todas las agencias')" />
        </a-form-item>
        <a-form-item :label="$t('Description')">
          <a-textarea v-model:value="form.description" :rows="2" />
        </a-form-item>
        <a-form-item :label="tr('Pillar', 'Pilar')">
          <a-select v-model:value="form.pillar" :options="PILLARS.map(p => ({ value: p.name, label: p.name }))" />
        </a-form-item>
        <a-form-item :label="tr('Entity', 'Entidad')">
          <a-select v-model:value="form.entity_id" allow-clear :options="entityOptions" :placeholder="tr('No entity', 'Sin entidad')" />
        </a-form-item>
        <template v-if="!editing">
          <a-alert type="info" show-icon class="mb" :message="tr('Its task is created with the same name and assigned now.', 'Su tarea se crea con el mismo nombre y se asigna ahora.')" />
          <a-row :gutter="12">
            <a-col :span="12">
              <a-form-item :label="tr('Stage', 'Etapa')">
                <a-select v-model:value="form.milestone_id" allow-clear :options="stageOptions" :placeholder="tr('No stage', 'Sin etapa')" />
              </a-form-item>
            </a-col>
            <a-col :span="12">
              <a-form-item :label="tr('Assigned to', 'Asignada a')" :extra="tr('Empty = the project manager', 'Vacío = el jefe de proyecto')">
                <a-select v-model:value="form.assignee_id" allow-clear show-search option-filter-prop="label" :options="memberOptions" />
              </a-form-item>
            </a-col>
            <a-col :span="12">
              <a-form-item :label="tr('Starts', 'Inicio')"><a-date-picker v-model:value="form.start_date" value-format="YYYY-MM-DD" style="width: 100%" /></a-form-item>
            </a-col>
            <a-col :span="12">
              <a-form-item :label="tr('Due', 'Vence')" :extra="tr('Empty = the stage end', 'Vacío = fin de la etapa')">
                <a-date-picker v-model:value="form.end_date" value-format="YYYY-MM-DD" style="width: 100%" />
              </a-form-item>
            </a-col>
          </a-row>
        </template>
      </a-form>
    </a-modal>

    <!-- Entity: who the objectives relate to -->
    <a-modal
      :open="entOpen" :title="entEditing ? tr('Edit entity', 'Editar entidad') : tr('New entity', 'Nueva entidad')" :width="520"
      :confirm-loading="saving" :ok-text="tr('Save', 'Guardar')" :cancel-text="$t('Cancel')" @ok="saveEntity" @cancel="entOpen = false"
    >
      <a-form layout="vertical">
        <a-form-item :label="tr('Name *', 'Nombre *')"><a-input v-model:value="entForm.name" :placeholder="tr('e.g. Operations', 'p. ej. Operaciones')" /></a-form-item>
        <a-form-item :label="tr('Who they are', 'Quién es')" style="margin-bottom: 0"><a-input v-model:value="entForm.description" :placeholder="tr('e.g. online ticket sales provider', 'p. ej. proveedora de venta de pasajes en línea')" /></a-form-item>
      </a-form>
    </a-modal>

    <!-- The project manager edits a task right here: text, dates, who has it (user, 2026-11-22). -->
    <a-modal
      :open="q.open" :title="tr('Edit task', 'Editar tarea')" :width="560" :confirm-loading="q.busy"
      :ok-text="tr('Save', 'Guardar')" :cancel-text="$t('Cancel')" @ok="qSave" @cancel="q.open = false"
    >
      <a-form layout="vertical">
        <a-form-item :label="tr('Task', 'Tarea')"><a-textarea v-model:value="q.title" :auto-size="{ minRows: 2, maxRows: 5 }" /></a-form-item>
        <a-row :gutter="10">
          <a-col :span="12"><a-form-item :label="tr('Starts', 'Inicio')"><a-date-picker v-model:value="q.start_date" value-format="YYYY-MM-DD" style="width: 100%" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="tr('Due', 'Vence')"><a-date-picker v-model:value="q.end_date" value-format="YYYY-MM-DD" style="width: 100%" /></a-form-item></a-col>
        </a-row>
        <a-form-item :label="tr('Assigned', 'Asignada')" style="margin-bottom: 0">
          <a-select v-model:value="q.assigned_to" mode="multiple" option-filter-prop="label" :options="teamOptions" :placeholder="tr('Nobody', 'Nadie')" />
        </a-form-item>
      </a-form>
    </a-modal>
  </a-spin>
</template>

<script setup>
/**
 * "Objetivos" (user, 2026-11-21; the description lives in the overview): the application's objectives. Each
 * objective is tied to tasks (tasks.objective_id); a new objective creates its task and assigns it (the person picked,
 * else the project manager).
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { Modal, message } from 'ant-design-vue';
import {
  AimOutlined, EditOutlined, DeleteOutlined, PlusOutlined, CheckOutlined, InfoCircleOutlined,
  FlagOutlined, UserOutlined, DisconnectOutlined, TeamOutlined, RightOutlined, DownOutlined, HolderOutlined,
} from '@ant-design/icons-vue';
import http from '../../lib/http';
import { useTr } from '../../lib/useTr';
import { useFormat } from '../../composables/useFormat';
import { progressColor } from './workspaceOptions';
import { projectStatusLabel, projectStatusColor } from './projectStatus';
import { sortByLabel } from '../../lib/filterOptions';

const props = defineProps({
  projectId: { type: Number, required: true },
  data: { type: Object, default: () => ({}) },
  // Mis proyectos (a member): to look, no editing (2026-11-22).
  readonly: { type: Boolean, default: false },
  // The signed-in member's tasks, highlighted.
  mine: { type: Array, default: () => [] },
});
const emit = defineEmits(['changed', 'open-task']);
const tr = useTr();
const { date } = useFormat();

const loading = ref(false);
const info = ref({ description: '', objectives: [] });
const objectives = computed(() => info.value.objectives || []);
const entities = computed(() => info.value.entities || []);
const entityOptions = computed(() => entities.value.map(e => ({ value: e.id, label: e.name })));
// The four pillars (App\Support\ProjectPillars) in their order; tasks fall in the pillar of their stage.
const PILLARS = [
  { name: 'Requerimiento', cls: 'p-req', en: 'What the client needs: meetings, information gathering and the agreed scope.', es: 'Qué necesita el cliente: reuniones, levantamiento de información y alcance acordado.' },
  { name: 'Análisis y Diseño', cls: 'p-design', en: 'How we will solve it: analysis, solution design, rules and prototypes.', es: 'Cómo lo vamos a resolver: análisis, diseño de la solución, reglas y prototipos.' },
  { name: 'Implementación', cls: 'p-build', en: 'Build and test it: development, setup, data loading and tests.', es: 'Construirlo y probarlo: desarrollo, configuración, carga de datos y pruebas.' },
  { name: 'Puesta en Marcha', cls: 'p-launch', en: 'Put it to use: training, final migration, pilot run, closing and support.', es: 'Ponerlo en uso: capacitación, migración final, marcha blanca, cierre y soporte.' },
];
const groups = computed(() => {
  const out = PILLARS.map(p => ({ key: p.name, name: p.name, cls: p.cls, description: tr(p.en, p.es), items: [], done: 0, total: 0 }));
  const other = { key: 'other', name: tr('Other', 'Otros'), cls: 'p-other', description: tr('Objectives without tasks, or tasks outside the four pillars.', 'Objetivos sin tareas, o tareas fuera de los cuatro pilares.'), items: [], done: 0, total: 0 };
  // Each objective sits in ONE pillar (its own, else where most of its tasks are) with all its tasks (2026-11-22).
  objectives.value.forEach(o => {
    const tasks = o.tasks || [];
    let pillar = PILLARS.some(p => p.name === o.pillar) ? o.pillar : null;
    if (!pillar) {
      const counts = {};
      tasks.forEach(t => { if (PILLARS.some(p => p.name === t.stage)) counts[t.stage] = (counts[t.stage] || 0) + 1; });
      pillar = PILLARS.map(p => p.name).reduce((best, p) => ((counts[p] || 0) > (counts[best] || 0) ? p : best), null);
    }
    const g = out.find(x => x.key === pillar) || other;
    g.items.push({ o, ts: tasks, key: `${g.key}:${o.id}` });
    g.total += tasks.filter(t => t.status !== 'cancelled').length;
    g.done += tasks.filter(t => t.status === 'completed').length;
  });  return other.items.length ? [...out, other] : out;
});
const entityName = id => (entities.value.find(e => e.id === id) || {}).name || '';
// One consecutive numbering across the groups (2026-11-22), in the objectives' own order.
// The two colours of the avatars: our staff and the client's (names from the members' companies).
const ownName = computed(() => (meta.value.members || []).find(m => m.side === 'own')?.company_name || tr('Our staff', 'Nuestro personal'));
const clientName = computed(() => (meta.value.members || []).find(m => m.side === 'client')?.company_name || tr('Client', 'Cliente'));
// Numbered in the order they show, pillar by pillar: consecutive down the page (2026-11-22).
const numberOf = computed(() => {
  const m = {};
  let n = 0;
  groups.value.forEach(g => g.items.forEach(({ o }) => { if (!(o.id in m)) m[o.id] = ++n; }));
  return m;
});
const doneCount = computed(() => objectives.value.filter(o => o.done).length);
const open = reactive({});
// Open / closed objectives remembered per project in this browser (user, 2026-11-22).
const OPEN_KEY = () => `murano.objectivesOpen.${props.projectId}`;
function saveOpen() { try { localStorage.setItem(OPEN_KEY(), JSON.stringify(open)); } catch (e) { /* private window */ } }
const toggle = id => { open[id] = !open[id]; saveOpen(); };

const meta = ref({ stages: [], members: [], staff: [] });
const stageOptions = computed(() => (meta.value.stages || []).map(s => ({ value: s.id, label: s.title })));
const memberOptions = computed(() => [
  ...(meta.value.members || []).map(m => ({ value: m.id, label: `${m.name}${m.role ? ' · ' + m.role : ''}${m.is_manager ? ' ★' : ''}` })),
  ...(meta.value.staff || []).map(m => ({ value: m.id, label: m.name })),
]);
const looseTaskOptions = computed(() => sortByLabel((props.data.tasks || []).filter(t => !t.objective_id).map(t => ({ value: t.id, label: t.title }))));
const linkPick = reactive({});
const linkShow = reactive({});
const moreOpen = reactive({});
// Quick edit of a task by the project manager (2026-11-22): PUT projects/{id}/tasks/{tid}/quick.
const teamOptions = computed(() => sortByLabel((meta.value.members || []).map(m => ({ value: m.id, label: `${m.name}${m.role ? ' · ' + m.role : ''}` }))));
const q = reactive({ open: false, busy: false, id: null, title: '', start_date: null, end_date: null, assigned_to: [] });
function qOpen(t) {
  Object.assign(q, { open: true, busy: false, id: t.id, title: t.title, start_date: t.start_date ? String(t.start_date).slice(0, 10) : null,
    end_date: t.end_date ? String(t.end_date).slice(0, 10) : null, assigned_to: (t.people || []).map(p => p.id) });
}
async function qSave() {
  if (!q.title.trim()) return message.warning(tr('Write the task.', 'Escribe la tarea.'));
  if (!q.start_date || !q.end_date) return message.warning(tr('Give both dates.', 'Indica las dos fechas.'));
  if (q.end_date < q.start_date) return message.warning(tr('The due date cannot be before the start.', 'La fecha de fin no puede ser anterior a la de inicio.'));
  q.busy = true;
  try {
    await http.put(`projects/${props.projectId}/tasks/${q.id}/quick`, { title: q.title.trim(), start_date: q.start_date, end_date: q.end_date, assigned_to: q.assigned_to });
    message.success(tr('Task saved.', 'Tarea guardada.'));
    q.open = false;
    await load();
    emit('changed');
  } catch (e) {
    const errs = e?.data?.errors ? Object.values(e.data.errors).flat() : [];
    message.error(errs[0] || e?.data?.message || tr('Could not save.', 'No se pudo guardar.'));
  } finally { q.busy = false; }
}
// Drag and drop of the objectives (2026-11-22): order, and into another pillar. PUT projects/{id}/objectives-order.
const objDrag = reactive({ id: null, over: null, overGroup: null });
function objDragStart(e, o) {
  if (!canReorder.value) return;
  objDrag.id = o.id;
  try { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', 'o' + o.id); } catch (err) { /* old browsers */ }
}
function objDragOver(o) { if (objDrag.id) { objDrag.over = o.id; objDrag.overGroup = null; } }
function objDragOverGroup(g) { if (objDrag.id) { objDrag.overGroup = g.key; objDrag.over = null; } }
async function saveObjectiveOrder(order, pillar) {
  const moved = objDrag.id;
  objDrag.id = null; objDrag.over = null; objDrag.overGroup = null;
  try {
    await http.put(`projects/${props.projectId}/objectives-order`, { ids: order, moved_id: moved, pillar });
    await load();
    emit('changed');
  } catch (e) {
    message.error(e?.data?.message || tr('Could not save the order.', 'No se pudo guardar el orden.'));
    load();
  }
}
function flatOrder() { return groups.value.flatMap(g => g.items.map(({ o }) => o.id)); }
function objDrop(g, target) {
  const from = objDrag.id;
  if (!from || from === target.id) { objDrag.id = null; return; }
  const order = flatOrder().filter(id => id !== from);
  order.splice(order.indexOf(target.id), 0, from);
  saveObjectiveOrder(order, PILLARS.some(p => p.name === g.key) ? g.key : null);
}
function objDropOnGroup(g) {
  const from = objDrag.id;
  if (!from || !PILLARS.some(p => p.name === g.key)) { objDrag.id = null; return; }
  // Dropped on a pillar's title: at the end of that pillar.
  const order = [];
  groups.value.forEach(x => { x.items.forEach(({ o }) => { if (o.id !== from) order.push(o.id); }); if (x.key === g.key) order.push(from); });
  saveObjectiveOrder(order, g.key);
}
// Drag and drop of tasks inside one objective -- project manager / editors only (server: can_reorder).
const canReorder = computed(() => !!info.value.can_reorder);
const drag = reactive({ id: null, oid: null, over: null });
function onDragStart(e, o, t) {
  if (!canReorder.value) return;
  drag.id = t.id; drag.oid = o.id;
  try { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', String(t.id)); } catch (err) { /* old browsers */ }
}
function onDragOver(o, t) { if (drag.id && drag.oid === o.id) drag.over = t.id; }
async function onDrop(o, ts, target) {
  const from = drag.id;
  drag.id = null; drag.over = null;
  if (!from || drag.oid !== o.id || from === target.id) return;
  const list = [...ts];
  const moved = list.find(x => x.id === from);
  list.splice(list.indexOf(moved), 1);
  list.splice(list.indexOf(target), 0, moved);
  o.tasks.splice(0, o.tasks.length, ...list); // shown at once
  try {
    const r = await http.put(`projects/${props.projectId}/objectives/${o.id}/order`, { task_ids: list.map(x => x.id), moved_id: from });
    // Its new dates: the next business day after the task now before it (2026-11-22).
    if (r?.moved) {
      Object.assign(moved, { start_date: r.moved.start_date, end_date: r.moved.end_date });
      message.success(tr('Order saved; the task now runs ', 'Orden guardado; la tarea ahora va del ') + date(r.moved.start_date) + tr(' to ', ' al ') + date(r.moved.end_date) + '.');
    }
  } catch (e) {
    message.error(e?.data?.message || tr('Could not save the order.', 'No se pudo guardar el orden.'));
    load();
  }
}

async function load() {
  loading.value = !objectives.value.length;
  try {
    const [a, b] = await Promise.all([http.get(`projects/${props.projectId}/objectives`), http.get(`projects/${props.projectId}/task-meta`)]);
    info.value = a;
    meta.value = b;
    if (!Object.keys(open).length) {
      let saved = null;
      try { saved = JSON.parse(localStorage.getItem(OPEN_KEY()) || 'null'); } catch (e) { saved = null; }
      groups.value.forEach(g => g.items.forEach(({ key, ts }) => {
        open[key] = saved && key in saved ? !!saved[key] : ts.some(t => t.status !== 'completed');
      }));
    }
  } finally { loading.value = false; }
}

// ---- objectives
const formOpen = ref(false);
const saving = ref(false);
const editing = ref(null);
const form = ref({});
function openNew(entityId) {
  editing.value = null;
  form.value = { title: '', description: '', pillar: 'Requerimiento', entity_id: entityId || undefined, milestone_id: undefined, assignee_id: undefined, start_date: null, end_date: null };
  formOpen.value = true;
}
function openEdit(o) {
  editing.value = o;
  form.value = { title: o.title, description: o.description || '', position: o.position, entity_id: o.entity_id || undefined,
    pillar: o.pillar || (groups.value.find(g => g.items.some(it => it.o.id === o.id)) || {}).key || 'Requerimiento' };
  formOpen.value = true;
}
async function save() {
  if (!form.value.title) { message.warning(tr('Write the objective.', 'Escribe el objetivo.')); return; }
  saving.value = true;
  try {
    if (editing.value) await http.put(`projects/${props.projectId}/objectives/${editing.value.id}`, form.value);
    else {
      const r = await http.post(`projects/${props.projectId}/objectives`, form.value);
      open[r.id] = true;
      message.success(tr('Objective and its task created.', 'Objetivo y su tarea creados.'));
    }
    formOpen.value = false;
    await load();
    emit('changed');
  } catch (e) {
    message.error(e?.data?.message || tr('Could not save.', 'No se pudo guardar.'));
  } finally { saving.value = false; }
}
function remove(o) {
  Modal.confirm({
    title: tr('Delete this objective?', '¿Eliminar este objetivo?'), content: tr('Its tasks are kept.', 'Sus tareas se conservan.'),
    okType: 'danger', okText: tr('Delete', 'Eliminar'), cancelText: tr('Cancel', 'Cancelar'),
    async onOk() { await http.delete(`projects/${props.projectId}/objectives/${o.id}`); await load(); emit('changed'); },
  });
}
async function link(o, taskId) {
  if (!taskId) return;
  await http.post(`projects/${props.projectId}/objectives/${o.id}/tasks`, { task_id: taskId });
  linkPick[o.id] = undefined;
  await load();
  emit('changed');
}
async function unlink(o, t) {
  await http.post(`projects/${props.projectId}/objectives/${o.id}/tasks`, { task_id: t.id, unlink: 1 });
  await load();
  emit('changed');
}

// ---- entities
const entOpen = ref(false);
const entEditing = ref(null);
const entForm = ref({ name: '', description: '' });
function openEntity(e) {
  entEditing.value = e;
  entForm.value = e ? { name: e.name, description: e.description || '', position: e.position } : { name: '', description: '' };
  entOpen.value = true;
}
async function saveEntity() {
  if (!entForm.value.name) { message.warning(tr('Write the name.', 'Escribe el nombre.')); return; }
  saving.value = true;
  try {
    if (entEditing.value) await http.put(`projects/${props.projectId}/entities/${entEditing.value.id}`, entForm.value);
    else await http.post(`projects/${props.projectId}/entities`, entForm.value);
    entOpen.value = false;
    await load();
  } catch (e) {
    message.error(e?.data?.message || tr('Could not save.', 'No se pudo guardar.'));
  } finally { saving.value = false; }
}
function removeEntity(e) {
  Modal.confirm({
    title: tr('Delete this entity?', '¿Eliminar esta entidad?'), content: tr('Its objectives are kept, without an entity.', 'Sus objetivos se conservan, sin entidad.'),
    okType: 'danger', okText: tr('Delete', 'Eliminar'), cancelText: tr('Cancel', 'Cancelar'),
    async onOk() { await http.delete(`projects/${props.projectId}/entities/${e.id}`); await load(); },
  });
}

onMounted(load);
</script>

<style scoped>
.card { height: 100%; }
.ic-title { color: #6366f1; }
.hint-ic { margin-inline-start: 6px; opacity: 0.55; cursor: help; }
.desc { white-space: pre-wrap; line-height: 1.6; }
.sum { font-size: 12px; opacity: 0.65; margin-inline-end: 10px; }
.mr { margin-inline-end: 6px; }
.grp { margin-bottom: 14px; border-radius: 10px; padding: 6px 10px 5px; border: 1px solid transparent; }
/* The objectives sit inside their pillar with a 5 px margin, so the section visibly holds them (2026-11-22). */
.grp .obj { margin: 0 5px 5px; }
/* One colour per pillar (2026-11-22). */
.grp.p-req { background: rgba(37, 99, 235, 0.06); border-color: rgba(37, 99, 235, 0.22); }
.grp.p-design { background: rgba(147, 51, 234, 0.06); border-color: rgba(147, 51, 234, 0.22); }
.grp.p-build { background: rgba(217, 119, 6, 0.07); border-color: rgba(217, 119, 6, 0.25); }
.grp.p-launch { background: rgba(22, 163, 74, 0.07); border-color: rgba(22, 163, 74, 0.25); }
.grp.p-other { background: rgba(100, 116, 139, 0.06); border-color: rgba(100, 116, 139, 0.2); }
.p-req .grp-ic, .p-req .grp-name { color: #1d4ed8; }
.p-design .grp-ic, .p-design .grp-name { color: #7e22ce; }
.p-build .grp-ic, .p-build .grp-name { color: #b45309; }
.p-launch .grp-ic, .p-launch .grp-name { color: #15803d; }
.grp .obj { background: var(--obj-bg, #fff); }
.ent-tag { margin-inline-start: 6px; font-size: 10.5px; font-weight: 500; }
.grp-head { display: flex; align-items: center; gap: 8px; padding: 6px 4px; margin-bottom: 6px; border-bottom: 2px solid rgba(99, 102, 241, 0.35); }
.grp-ic { color: #6366f1; }
.grp-name { font-weight: 700; font-size: 14px; }
.grp-desc { font-size: 12px; opacity: 0.65; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.grp-sum { font-size: 12px; opacity: 0.6; margin-inline-start: auto; white-space: nowrap; }
.grp-empty { font-size: 12px; opacity: 0.55; padding: 2px 8px 6px; }
.obj { border: 1px solid rgba(128, 128, 128, 0.18); border-left: 4px solid #6366f1; border-radius: 8px; margin-bottom: 8px; }
.obj.met { border-left-color: #16a34a; }
.caret { font-size: 10px; opacity: 0.55; flex: none; }
.obj-head { display: flex; align-items: center; gap: 10px; padding: 8px 10px; cursor: pointer; }
.obj-head:hover { background: rgba(128, 128, 128, 0.06); }
.num { flex: none; display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; border-radius: 12px; background: #6366f1; color: #fff; font-size: 12px; font-weight: 600; }
.num.met { background: #16a34a; }
.obj-main { flex: 1; min-width: 0; }
.obj-title { font-weight: 600; }
.obj-desc { font-size: 12px; opacity: 0.65; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.obj-count { font-size: 12px; opacity: 0.6; white-space: nowrap; }
.obj-prog { width: 130px; flex: none; }
.acts { white-space: nowrap; }
.obj-tasks { padding: 0 10px 8px 44px; }
.otask { display: flex; align-items: center; gap: 8px; padding: 4px; border-top: 1px dashed rgba(128, 128, 128, 0.18); cursor: pointer; font-size: 13px; }
.otask.mine { background: rgba(250, 204, 21, 0.14); border-radius: 4px; }
/* Drag to reorder (project manager): the grip, the row being dragged, where it will land. */
.grip { cursor: grab; opacity: 0.45; font-size: 13px; }
.otask:hover .grip { opacity: 0.9; }
.otask.dragging, .obj-head.dragging { opacity: 0.4; }
.obj-head.drop-before { box-shadow: inset 0 3px 0 #6d28d9; }
.grp-head.obj-drop { outline: 2px dashed #6d28d9; outline-offset: 2px; border-radius: 6px; }
.otask.drop-before { box-shadow: inset 0 2px 0 #6d28d9; }
.otask:hover { background: rgba(128, 128, 128, 0.06); }
.t-tag { margin: 0; min-width: 84px; text-align: center; }
.t-title { flex: 1; min-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.link-add { font-size: 12px; }
.t-stage { font-size: 11.5px; opacity: 0.7; white-space: nowrap; width: 190px; flex: none; overflow: hidden; text-overflow: ellipsis; }
.t-avs { width: 92px; flex: none; display: flex; gap: 3px; flex-wrap: wrap; }
.av { display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; border-radius: 12px; font-size: 10.5px; font-weight: 700; color: #fff; background: #6366f1; cursor: default; }
.av.client { background: #0d9488; }
.muted-dash { opacity: 0.4; }
.legend-av { display: inline-flex; align-items: center; gap: 4px; font-size: 11.5px; opacity: 0.75; margin-inline-end: 10px; }
.legend-av .av { width: 14px; height: 14px; }
.t-date { font-size: 11.5px; opacity: 0.65; width: 80px; text-align: right; }
.link-row { padding-top: 6px; }
.t-more { display: flex; gap: 6px; align-items: flex-start; padding: 0 300px 5px 96px; font-size: 12px; opacity: 0.7; cursor: pointer; }
.t-more:hover { opacity: 1; }
.t-more .caret { margin-top: 4px; }
.t-line { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.t-full { flex: 1; min-width: 0; white-space: pre-wrap; }
.t-full > div + div { margin-top: 4px; }
.link-sel { width: 320px; max-width: 100%; }
.mb { margin-bottom: 12px; }
@media (max-width: 768px) { .t-stage, .t-who, .obj-count { display: none; } .obj-prog { width: 80px; } }
</style>
