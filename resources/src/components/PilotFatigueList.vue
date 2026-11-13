<template>
  <!-- Pilots' fatigue (user's rule, 2026-11-13): per pilot the alerts of the period (12 h in a row, 30 h in 2 days,
       46 h in 3 days, no swap with the copilot, two buses at once), not a sum of hours. A click opens why. -->
  <div class="pf">
    <div class="pf-head">
      <span class="pf-title">{{ T('Pilot alerts', 'Alertas de pilotos') }}</span>
      <a-tooltip :title="rulesHelp"><InfoCircleOutlined class="pf-info" /></a-tooltip>
      <a v-if="canEditRules" class="pf-gear" :title="T('Limits', 'Límites')" @click="openRules"><SettingOutlined /></a>
      <span class="pf-sum" :class="{ 'pf-sum-bad': withAlerts }">{{ withAlerts }} / {{ list.length }}</span>
    </div>
    <div class="pf-list">
      <div v-for="p in list" :key="p.id" class="pf-row" :title="rowTitle(p)" @click="open(p)">
        <span class="pf-name">{{ p.name }}</span>
        <span class="pf-hours">{{ p.driving }} h</span>
        <span class="pf-badge" :class="p.alerts ? 'pf-badge-bad' : 'pf-badge-ok'">{{ p.alerts ? p.alerts : '✓' }}</span>
      </div>
      <a-empty v-if="!list.length && !loading" :image="simpleImage" />
    </div>

    <a-modal v-model:open="detailOpen" :title="detail ? `${detail.name} · ${T('alerts', 'alertas')}` : ''" :footer="null" width="820px">
      <a-spin :spinning="detailLoading">
        <template v-if="detail">
          <div class="pf-kpis">
            <div><b>{{ detail.alerts }}</b><span>{{ T('Alerts', 'Alertas') }}</span></div>
            <div><b>{{ detail.driving }} h</b><span>{{ T('At the wheel', 'Al volante') }}</span></div>
            <div><b>{{ detail.onboard }} h</b><span>{{ T('On board', 'A bordo') }}</span></div>
            <div><b>{{ detail.trips }}</b><span>{{ T('Trips', 'Viajes') }}</span></div>
          </div>
          <div class="pf-period">{{ fmt.date(period[0]) }} — {{ fmt.date(period[1]) }}</div>
          <a-empty v-if="!detail.list?.length" :description="T('No alerts in this period.', 'Sin alertas en este periodo.')" />
          <div v-for="(a, i) in detail.list || []" :key="i" class="pf-alert">
            <div class="pf-alert-head">
              <span class="pf-tag" :class="'pf-tag-' + a.rule">{{ ruleName(a.rule) }}</span>
              <span class="pf-when">{{ fmt.dateTime(a.from) }} → {{ fmt.dateTime(a.to) }}</span>
            </div>
            <div class="pf-why">{{ why(a) }}</div>
            <div v-for="t in a.trips" :key="t.id" class="pf-trip">
              {{ fmt.dateTime(t.departure_at) }} · {{ t.route }}<span v-if="t.bus"> · {{ t.bus }}</span>
            </div>
          </div>
        </template>
      </a-spin>
    </a-modal>

    <a-modal v-model:open="rulesOpen" :title="T('Pilot limits', 'Límites de los pilotos')" :ok-text="T('Save', 'Guardar')" :cancel-text="T('Cancel', 'Cancelar')" :confirm-loading="saving" @ok="saveRules">
      <a-form layout="vertical" size="small">
        <a-form-item v-for="f in ruleFields" :key="f.key" :label="f.label">
          <a-input-number v-model:value="form[f.key]" :min="1" :max="f.max" :addon-after="f.unit" style="width: 160px" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { Empty, message } from 'ant-design-vue';
import { InfoCircleOutlined, SettingOutlined } from '@ant-design/icons-vue';
import http from '../lib/http';
import { useUiStore } from '../stores/ui';
import { useAuthStore } from '../stores/auth';
import { useFormat } from '../composables/useFormat';

// pilots / rules from the transport board when it has them; else this list loads its own.
const props = defineProps({
  range: { type: Array, default: null },
  pilots: { type: Array, default: null },
  rules: { type: Object, default: null },
});
const emit = defineEmits(['rules-saved']);
const ui = useUiStore();
const auth = useAuthStore();
const fmt = useFormat();
const T = (en, sp) => (ui.locale === 'es' ? sp : en);
const simpleImage = Empty.PRESENTED_IMAGE_SIMPLE;
const errorOf = e => { const errs = e?.data?.errors; return (errs && Object.values(errs)[0]?.[0]) || e?.data?.message || e.message; };

const own = ref({ pilots: [], rules: null });
const loading = ref(false);
async function load() {
  if (props.pilots) return;
  loading.value = true;
  try {
    own.value = await http.get('transport/pilot-fatigue', { from: props.range?.[0], to: props.range?.[1] });
  } catch (e) {
    message.error(errorOf(e));
  } finally {
    loading.value = false;
  }
}
watch(() => [props.range?.[0], props.range?.[1], !!props.pilots], load, { immediate: true });

const list = computed(() => props.pilots || own.value.pilots || []);
const rules = computed(() => props.rules || own.value.rules || {});
const withAlerts = computed(() => list.value.filter(p => p.alerts).length);
const period = computed(() => [own.value.from || props.range?.[0], own.value.to || props.range?.[1]]);
const rulesHelp = computed(() => {
  const r = rules.value;
  return T(
    `An alert when a pilot drives more than ${r.continuous} h in a row (trips less than ${r.gap_min} min apart count as one), more than ${r.h48} h in 2 days, more than ${r.h72} h in 3 days, does not hand the wheel to the copilot on a trip over ${r.swap_after} h, or is on two buses at once. The number = alerts in the period; the hours = at the wheel.`,
    `Alerta cuando un piloto maneja más de ${r.continuous} h seguidas (viajes a menos de ${r.gap_min} min cuentan como uno), más de ${r.h48} h en 2 días, más de ${r.h72} h en 3 días, no cede el volante al copiloto en un viaje de más de ${r.swap_after} h, o está en dos buses a la vez. El número = alertas del periodo; las horas = al volante.`,
  );
});
const ruleName = rule => ({
  continuous: T('Hours in a row', 'Horas seguidas'),
  h48: T('Hours in 2 days', 'Horas en 2 días'),
  h72: T('Hours in 3 days', 'Horas en 3 días'),
  swap_missing: T('No swap', 'Sin cambio de piloto'),
  overlap: T('Two buses at once', 'Dos buses a la vez'),
})[rule] || rule;
function why(a) {
  const n = a.trips?.length || 0;
  switch (a.rule) {
    case 'continuous': return T(`Drove ${a.hours} h in a row (limit ${a.limit} h) over ${n} trip(s) with no rest in between.`, `Manejó ${a.hours} h seguidas (límite ${a.limit} h) en ${n} viaje(s) sin descanso entre ellos.`);
    case 'h48': return T(`Drove ${a.hours} h within 48 hours (limit ${a.limit} h) over ${n} trips.`, `Manejó ${a.hours} h dentro de 48 horas (límite ${a.limit} h) en ${n} viajes.`);
    case 'h72': return T(`Drove ${a.hours} h within 72 hours (limit ${a.limit} h) over ${n} trips.`, `Manejó ${a.hours} h dentro de 72 horas (límite ${a.limit} h) en ${n} viajes.`);
    case 'swap_missing': return T(`A ${a.hours} h trip with no swap on record${a.with_name ? ` with ${a.with_name}` : ''}: counted at the wheel all the way (swap after ${a.limit} h).`, `Viaje de ${a.hours} h sin cambio registrado${a.with_name ? ` con ${a.with_name}` : ''}: cuenta al volante todo el viaje (cambio pasadas ${a.limit} h).`);
    case 'overlap': return T(`Assigned to two buses at the same time for ${a.hours} h: a planning error.`, `Asignado a dos buses al mismo tiempo durante ${a.hours} h: error de programación.`);
    default: return '';
  }
}
const rowTitle = p => `${p.name}: ${p.alerts} ${T('alerts', 'alertas')} · ${p.driving} h ${T('at the wheel', 'al volante')} · ${p.onboard} h ${T('on board', 'a bordo')} · ${p.trips} ${T('trips', 'viajes')}`;

const detailOpen = ref(false);
const detailLoading = ref(false);
const detail = ref(null);
async function open(p) {
  detail.value = { ...p, list: null };
  detailOpen.value = true;
  detailLoading.value = true;
  try {
    const res = await http.get('transport/pilot-fatigue', { from: props.range?.[0], to: props.range?.[1], employee_id: p.id });
    own.value = { ...own.value, from: res.from, to: res.to };
    detail.value = res.pilots?.[0] || { ...p, list: [] };
  } catch (e) {
    message.error(errorOf(e));
  } finally {
    detailLoading.value = false;
  }
}

const canEditRules = computed(() => auth.can('transport_manage'));
const rulesOpen = ref(false);
const saving = ref(false);
const form = ref({});
const ruleFields = computed(() => [
  { key: 'continuous', label: T('Max. hours in a row at the wheel', 'Máx. horas seguidas al volante'), unit: 'h', max: 48 },
  { key: 'h48', label: T('Max. hours at the wheel in 2 days', 'Máx. horas al volante en 2 días'), unit: 'h', max: 48 },
  { key: 'h72', label: T('Max. hours at the wheel in 3 days', 'Máx. horas al volante en 3 días'), unit: 'h', max: 72 },
  { key: 'swap_after', label: T('Swap with the copilot on trips over', 'Cambio con el copiloto en viajes de más de'), unit: 'h', max: 24 },
  { key: 'gap_min', label: T('A stop shorter than this is not rest', 'Una parada menor a esto no es descanso'), unit: 'min', max: 600 },
  { key: 'swap_late_min', label: T('Swap late after', 'Cambio atrasado pasados'), unit: 'min', max: 240 },
]);
function openRules() {
  form.value = { ...rules.value };
  rulesOpen.value = true;
}
async function saveRules() {
  saving.value = true;
  try {
    await http.put('transport/pilot-fatigue/rules', form.value);
    rulesOpen.value = false;
    message.success(T('Saved', 'Guardado'));
    emit('rules-saved');
    if (!props.pilots) await load();
  } catch (e) {
    message.error(errorOf(e));
  } finally {
    saving.value = false;
  }
}
</script>

<style scoped>
.pf { display: flex; flex-direction: column; min-height: 0; height: 100%; }
.pf-head { display: flex; align-items: center; gap: 6px; font-size: 13px; line-height: 20px; margin-bottom: 2px; }
.pf-title { font-weight: 600; }
.pf-info { opacity: 0.5; font-size: 12px; cursor: help; }
.pf-gear { font-size: 12px; opacity: 0.7; }
.pf-sum { margin-left: auto; font-size: 12px; font-weight: 700; color: #16a34a; }
.pf-sum-bad { color: #dc2626; }
.pf-list { flex: 1; min-height: 0; overflow-y: auto; }
.pf-row { display: flex; align-items: center; gap: 6px; font-size: 11.5px; padding: 3px 2px; border-top: 1px solid rgba(128, 128, 128, 0.15); cursor: pointer; }
.pf-row:hover { background: rgba(128, 128, 128, 0.08); }
.pf-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pf-hours { opacity: 0.7; white-space: nowrap; font-variant-numeric: tabular-nums; }
.pf-badge { min-width: 22px; text-align: center; border-radius: 10px; font-weight: 700; font-size: 11px; padding: 0 6px; }
.pf-badge-bad { background: #dc2626; color: #fff; }
.pf-badge-ok { background: #dcfce7; color: #15803d; }
.pf-kpis { display: flex; gap: 8px; margin-bottom: 6px; }
.pf-kpis > div { flex: 1; border: 1px solid rgba(128, 128, 128, 0.2); border-radius: 8px; padding: 6px 8px; display: flex; flex-direction: column; }
.pf-kpis b { font-size: 18px; }
.pf-kpis span { font-size: 12px; opacity: 0.7; }
.pf-period { font-size: 12px; opacity: 0.7; margin-bottom: 8px; }
.pf-alert { border: 1px solid rgba(128, 128, 128, 0.2); border-left: 4px solid #dc2626; border-radius: 6px; padding: 6px 10px; margin-bottom: 6px; }
.pf-alert-head { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.pf-tag { font-weight: 700; font-size: 12px; border-radius: 4px; padding: 0 6px; background: #fee2e2; color: #b91c1c; }
.pf-tag-swap_missing { background: #fef3c7; color: #b45309; }
.pf-tag-overlap { background: #ede9fe; color: #6d28d9; }
.pf-when { font-size: 12px; opacity: 0.75; }
.pf-why { font-size: 13px; margin: 2px 0; }
.pf-trip { font-size: 12px; opacity: 0.8; padding-left: 10px; }
</style>
