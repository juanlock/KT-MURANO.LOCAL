<template>
  <!-- My campaign (2026-11-17): the agent takes an active campaign and calls the next lead nobody else has. -->
  <div class="page cc-work">
    <PageHeader :title="tr('My campaign', 'Mi campaña')" :breadcrumb="[tr('Call center', 'Centro de llamadas'), tr('My campaign', 'Mi campaña')]" />

    <a-spin :spinning="loading">
      <!-- Shift bar: state, the day's figures, start / pause / stop. -->
      <a-card size="small" class="cc-bar">
        <div class="bar-row">
          <div class="state">
            <a-badge :status="stateBadge" />
            <b>{{ stateText }}</b>
            <span v-if="data.session" class="muted">· {{ tr('since', 'desde') }} {{ clock(data.session.since) }} ({{ elapsed(data.session.since) }})</span>
          </div>
          <div class="kpis">
            <span><b>{{ today.calls || 0 }}</b> {{ tr('calls', 'llamadas') }}</span>
            <span><b>{{ today.contacts || 0 }}</b> {{ tr('contacts', 'contactos') }}</span>
            <span class="ok"><b>{{ today.sales || 0 }}</b> {{ tr('sales', 'ventas') }}</span>
            <span><b>{{ duration(today.talk_sec) }}</b> {{ tr('talking', 'hablando') }}</span>
            <span><b>{{ hours(today.worked_sec) }}</b> {{ tr('worked', 'trabajadas') }}</span>
          </div>
          <a-space>
            <a-button v-if="!data.session" type="primary" @click="session('start')"><PlayCircleOutlined /> {{ tr('Start shift', 'Iniciar turno') }}</a-button>
            <a-dropdown v-if="data.session?.kind === 'work'" :trigger="['click']">
              <a-button><PauseCircleOutlined /> {{ tr('Pause', 'Pausa') }}</a-button>
              <template #overlay>
                <a-menu @click="({ key }) => session('pause', key)">
                  <a-menu-item v-for="p in data.pauses" :key="p.value">{{ p.label }}</a-menu-item>
                </a-menu>
              </template>
            </a-dropdown>
            <a-button v-if="data.session?.kind === 'pause'" type="primary" @click="session('resume')"><PlayCircleOutlined /> {{ tr('Back to work', 'Volver al trabajo') }}</a-button>
            <a-popconfirm v-if="data.session" :title="tr('End your shift? A lead in hand goes back to the others.', '¿Terminar tu turno? Un lead en mano vuelve a los demás.')" @confirm="session('stop')">
              <a-button danger>{{ tr('End shift', 'Terminar turno') }}</a-button>
            </a-popconfirm>
          </a-space>
        </div>
      </a-card>

      <a-alert v-if="data.session?.kind === 'pause'" type="warning" show-icon class="mt8"
        :message="tr('On a pause', 'En pausa') + ': ' + (data.session.reason_label || '') + ' — ' + elapsed(data.session.since)" />

      <a-row :gutter="12" class="mt8">
        <a-col :xs="24" :lg="15">
          <!-- The campaigns the agent can take. -->
          <a-card size="small" :title="tr('Campaign', 'Campaña')">
            <a-empty v-if="!data.campaigns?.length" :description="tr('There is no active campaign. Your supervisor starts them.', 'No hay campañas activas. Tu supervisor las inicia.')" />
            <div v-else class="camps">
              <div v-for="c in data.campaigns" :key="c.id" class="camp" :class="{ on: campaignId === c.id }" @click="pickCampaign(c.id)">
                <div class="camp-name">{{ c.name }}</div>
                <div class="muted">{{ c.client_name || '—' }}<span v-if="c.call_from"> · {{ c.call_from }}–{{ c.call_to }}</span></div>
                <a-progress :percent="c.progress?.percent || 0" size="small" :show-info="false" />
                <div class="muted small">{{ tr('to call', 'por llamar') }}: <b>{{ (c.progress?.pending || 0) + (c.progress?.callback || 0) }}</b> · {{ tr('sales', 'ventas') }}: {{ c.progress?.sales || 0 }}</div>
              </div>
            </div>
          </a-card>

          <!-- No lead in hand: ask for the next one. -->
          <a-card v-if="!current" size="small" class="mt8 next-card">
            <a-result v-if="noMore" status="info" :title="noMore" />
            <div class="next-wrap">
              <a-button type="primary" size="large" :disabled="!campaignId || data.session?.kind === 'pause'" :loading="busy" @click="nextLead">
                <PhoneOutlined /> {{ tr('Next lead', 'Siguiente lead') }}
              </a-button>
              <div class="muted small">{{ tr('Each lead goes to one agent only: nobody else will call it while you have it.', 'Cada lead va a un solo agente: nadie más lo llamará mientras lo tengas.') }}</div>
            </div>
          </a-card>

          <!-- The lead in hand. -->
          <a-card v-else size="small" class="mt8 lead-card">
            <template #title>
              <span class="lead-name">{{ current.lead.name || tr('(no name)', '(sin nombre)') }}</span>
              <a-tag v-if="current.is_callback" color="blue">{{ tr('Callback', 'Volver a llamar') }}</a-tag>
              <a-tag v-if="current.attempts">{{ tr('Attempt', 'Intento') }} {{ current.attempts + 1 }}</a-tag>
            </template>
            <template #extra>
              <a-popconfirm :title="tr('Give this lead back without calling?', '¿Devolver este lead sin llamar?')" @confirm="release">
                <a-button size="small">{{ tr('Give back', 'Devolver') }}</a-button>
              </a-popconfirm>
            </template>
            <div class="phone-row">
              <!-- Hidden numbers (Central telefónica -> "Ocultar al agente"): the agent calls by click, never sees them. -->
              <a-tooltip v-if="current.lead.hidden" :title="tr('Hidden by the company: call with the button.', 'Oculto por la empresa: llama con el botón.')">
                <span class="phone"><LockOutlined /> {{ current.lead.phone }}</span>
              </a-tooltip>
              <a v-else class="phone" :href="'tel:' + current.lead.phone" @click="startTimer">{{ current.lead.phone }}</a>
              <a-button v-if="data.telephony?.dial" type="primary" :loading="dialing" @click="dial(1)"><PhoneOutlined /> {{ tr('Call', 'Llamar') }} ({{ tr('ext.', 'anexo') }} {{ data.telephony.extension }})</a-button>
              <a-button v-else @click="startTimer"><FieldTimeOutlined /> {{ timerStart ? tr('Talking…', 'Hablando…') : tr('Call started', 'Empecé la llamada') }}</a-button>
              <span v-if="timerStart" class="timer">{{ duration(talkSec) }}</span>
              <span v-if="current.lead.phone2" class="muted">{{ tr('other', 'otro') }}:
                <a v-if="current.lead.hidden" @click="dial(2)">{{ current.lead.phone2 }} ({{ tr('call', 'llamar') }})</a>
                <a v-else :href="'tel:' + current.lead.phone2">{{ current.lead.phone2 }}</a></span>
            </div>
            <a-descriptions size="small" :column="{ xs: 1, sm: 2, md: 3 }" class="mt8">
              <a-descriptions-item :label="tr('Age', 'Edad')">{{ current.lead.age ?? '—' }}</a-descriptions-item>
              <a-descriptions-item :label="tr('Sex', 'Sexo')">{{ sexText(current.lead.gender) }}</a-descriptions-item>
              <a-descriptions-item :label="tr('ID', 'DNI')">
                <span v-if="current.lead.hidden" class="muted"><LockOutlined /> {{ tr('hidden', 'oculto') }}</span>
                <template v-else>{{ current.lead.document || '—' }}</template>
              </a-descriptions-item>
              <a-descriptions-item :label="tr('Location', 'Ubicación')">{{ current.lead.location || '—' }}</a-descriptions-item>
              <a-descriptions-item :label="tr('Email', 'Correo')">{{ current.lead.email || '—' }}</a-descriptions-item>
              <a-descriptions-item v-for="(v, k) in current.lead.extra" :key="k" :label="k">{{ v }}</a-descriptions-item>
              <a-descriptions-item v-if="current.lead.notes" :label="tr('Notes', 'Notas')">{{ current.lead.notes }}</a-descriptions-item>
            </a-descriptions>

            <a-divider style="margin: 8px 0" />
            <div class="results">
              <a-radio-group v-model:value="form.result" button-style="solid" size="small">
                <a-radio-button v-for="r in data.results" :key="r.value" :value="r.value" :class="'r-' + r.value">{{ r.label }}</a-radio-button>
              </a-radio-group>
            </div>
            <a-row :gutter="8" class="mt8">
              <a-col v-if="form.result === 'callback'" :xs="24" :sm="12">
                <div class="lbl">{{ tr('Call back on', 'Volver a llamar el') }}</div>
                <a-date-picker v-model:value="form.callback_at" show-time value-format="YYYY-MM-DD HH:mm" style="width: 100%" />
                <a-space class="mt4" size="small">
                  <a-button size="small" @click="quickCallback(1)">{{ tr('In 1 h', 'En 1 h') }}</a-button>
                  <a-button size="small" @click="quickCallback(3)">{{ tr('In 3 h', 'En 3 h') }}</a-button>
                  <a-button size="small" @click="quickCallback('tomorrow')">{{ tr('Tomorrow 10:00', 'Mañana 10:00') }}</a-button>
                </a-space>
              </a-col>
              <a-col v-if="form.result === 'sale'" :xs="24" :sm="12">
                <div class="lbl">{{ tr('Sale amount (optional)', 'Monto de la venta (opcional)') }}</div>
                <a-input-number v-model:value="form.sale_amount" :min="0" :prefix="data.currency" style="width: 100%" />
              </a-col>
              <a-col :span="24" class="mt4">
                <a-textarea v-model:value="form.notes" :rows="2" :placeholder="tr('Notes of the call', 'Notas de la llamada')" />
              </a-col>
            </a-row>
            <div class="save-row">
              <a-button :disabled="!form.result" :loading="busy" @click="save(false)">{{ tr('Save', 'Guardar') }}</a-button>
              <a-button type="primary" :disabled="!form.result" :loading="busy" @click="save(true)">{{ tr('Save and next', 'Guardar y siguiente') }}</a-button>
            </div>
          </a-card>
        </a-col>

        <a-col :xs="24" :lg="9">
          <a-card size="small" class="side">
            <a-tabs v-model:activeKey="tab" size="small">
              <a-tab-pane key="script" :tab="tr('Script', 'Guion')">
                <div v-if="script" class="script">{{ script }}</div>
                <a-empty v-else :description="tr('The campaign has no script.', 'La campaña no tiene guion.')" />
              </a-tab-pane>
              <a-tab-pane key="history" :tab="tr('Lead history', 'Historial del lead') + (current?.history?.length ? ` (${current.history.length})` : '')">
                <a-empty v-if="!current?.history?.length" :description="tr('First call to this lead.', 'Primera llamada a este lead.')" />
                <a-timeline v-else class="mt8">
                  <a-timeline-item v-for="(h, i) in current.history" :key="i" :color="RESULT_COLORS[h.result] === 'green' ? 'green' : 'gray'">
                    <div><b>{{ h.result_label }}</b> · {{ fmt.dateTime(h.at) }} · {{ h.agent }}</div>
                    <div class="muted small">{{ h.campaign }} · {{ duration(h.duration_sec) }}</div>
                    <div v-if="h.notes">{{ h.notes }}</div>
                  </a-timeline-item>
                </a-timeline>
              </a-tab-pane>
              <a-tab-pane key="today" :tab="tr('My calls today', 'Mis llamadas hoy')">
                <a-empty v-if="!today.recent?.length" />
                <div v-for="c in today.recent" :key="c.id" class="recent">
                  <span>{{ fmt.date(c.at, 'HH:mm') }}</span>
                  <span class="grow">{{ c.name || c.phone }}</span>
                  <a-tag :color="RESULT_COLORS[c.result]">{{ c.result_label }}</a-tag>
                  <span class="muted">{{ duration(c.duration_sec) }}</span>
                </div>
              </a-tab-pane>
            </a-tabs>
          </a-card>
        </a-col>
      </a-row>
    </a-spin>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue';
import { message } from 'ant-design-vue';
import dayjs from 'dayjs';
import { PhoneOutlined, PauseCircleOutlined, PlayCircleOutlined, FieldTimeOutlined, LockOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import { useTr, duration, hours, RESULT_COLORS, errText } from '../../lib/callcenter';

const tr = useTr();
const fmt = useFormat();
const auth = useAuthStore();
const loading = ref(true);
const busy = ref(false);
const dialing = ref(false);
const data = ref({ campaigns: [], results: [], pauses: [] });
const current = ref(null);
const campaignId = ref(null);
const noMore = ref('');
const tab = ref('script');
const now = ref(Date.now());
const timerStart = ref(null);
const form = reactive({ result: null, notes: '', callback_at: null, sale_amount: null });
const today = computed(() => data.value.today || {});
const talkSec = computed(() => (timerStart.value ? Math.round((now.value - timerStart.value) / 1000) : 0));

const stateBadge = computed(() => (!data.value.session ? 'default' : data.value.session.kind === 'pause' ? 'warning' : 'success'));
const stateText = computed(() => (!data.value.session ? tr('Off shift', 'Fuera de turno') : data.value.session.kind === 'pause' ? tr('On a pause', 'En pausa') : tr('On shift', 'En turno')));
const campaign = computed(() => (data.value.campaigns || []).find(c => c.id === (current.value?.campaign_id || campaignId.value)));
// The script with the lead's and the agent's names in place.
const script = computed(() => (campaign.value?.script || '')
  .replaceAll('{nombre}', current.value?.lead?.name || '…').replaceAll('{name}', current.value?.lead?.name || '…')
  .replaceAll('{agente}', auth.user?.username || '').replaceAll('{agent}', auth.user?.username || ''));

const clock = v => (v ? dayjs(v).format('HH:mm') : '');
const elapsed = v => (v ? duration((now.value - dayjs(v).valueOf()) / 1000) : '');
const sexText = g => (g === 'F' ? tr('Female', 'Femenino') : g === 'M' ? tr('Male', 'Masculino') : '—');

function resetForm() {
  Object.assign(form, { result: null, notes: '', callback_at: null, sale_amount: null });
  timerStart.value = null;
}

async function load() {
  loading.value = true;
  try {
    data.value = await http.get('callcenter/work');
    current.value = data.value.current;
    if (current.value) campaignId.value = current.value.campaign_id;
    else if (!campaignId.value || !data.value.campaigns.some(c => c.id === campaignId.value)) {
      campaignId.value = data.value.session?.campaign_id || data.value.campaigns[0]?.id || null;
    }
  } catch (e) {
    message.error(errText(e, tr('Could not load.', 'No se pudo cargar.')));
  } finally {
    loading.value = false;
  }
}

function pickCampaign(id) {
  if (current.value) return message.info(tr('Finish or give back the lead in hand first.', 'Primero termina o devuelve el lead que tienes.'));
  campaignId.value = id;
  noMore.value = '';
}

async function session(action, reason) {
  try {
    data.value = await http.post('callcenter/work/session', { action, reason, campaign_id: campaignId.value });
    current.value = data.value.current;
    if (action === 'pause' || action === 'stop') resetForm();
  } catch (e) {
    message.error(errText(e, tr('Could not change the shift.', 'No se pudo cambiar el turno.')));
  }
}

async function nextLead() {
  busy.value = true;
  noMore.value = '';
  try {
    const res = await http.post('callcenter/work/next', { campaign_id: campaignId.value });
    current.value = res.current;
    resetForm();
    if (!res.current) noMore.value = res.message;
    const c = data.value.campaigns.find(x => x.id === campaignId.value);
    if (c && res.progress) c.progress = res.progress;
    if (!data.value.session || data.value.session.kind !== 'work') await load();
    tab.value = current.value?.history?.length ? 'history' : 'script';
  } catch (e) {
    message.error(errText(e, tr('Could not take a lead.', 'No se pudo tomar un lead.')));
  } finally {
    busy.value = false;
  }
}

function startTimer() {
  if (!timerStart.value) timerStart.value = Date.now();
}

function quickCallback(h) {
  form.callback_at = (h === 'tomorrow' ? dayjs().add(1, 'day').hour(10).minute(0) : dayjs().add(h, 'hour').second(0)).format('YYYY-MM-DD HH:mm');
}

async function save(andNext) {
  if (form.result === 'callback' && !form.callback_at) return message.warning(tr('Choose when to call back.', 'Elige cuándo volver a llamar.'));
  busy.value = true;
  try {
    const res = await http.post('callcenter/work/save', {
      campaign_lead_id: current.value.campaign_lead_id, result: form.result, notes: form.notes || null,
      callback_at: form.result === 'callback' ? form.callback_at : null, sale_amount: form.result === 'sale' ? form.sale_amount : null,
      duration_sec: timerStart.value ? talkSec.value : null,
      started_at: timerStart.value ? dayjs(timerStart.value).format('YYYY-MM-DD HH:mm:ss') : null,
    });
    message.success(tr('Saved.', 'Guardado.'));
    data.value.today = res.today;
    const c = data.value.campaigns.find(x => x.id === current.value.campaign_id);
    if (c && res.progress) c.progress = res.progress;
    current.value = null;
    resetForm();
    busy.value = false;
    if (andNext) await nextLead();
  } catch (e) {
    message.error(errText(e, tr('Could not save.', 'No se pudo guardar.')));
    if (e?.status === 409) { current.value = null; resetForm(); }
  } finally {
    busy.value = false;
  }
}

async function release() {
  try {
    await http.post('callcenter/work/release', { campaign_lead_id: current.value.campaign_lead_id });
    current.value = null;
    resetForm();
  } catch (e) {
    message.error(errText(e, tr('Could not give it back.', 'No se pudo devolver.')));
  }
}

async function dial(which = 1) {
  dialing.value = true;
  try {
    await http.post('callcenter/work/dial', { campaign_lead_id: current.value.campaign_lead_id, which });
    startTimer();
    message.success(tr('The exchange is calling: pick up your phone.', 'La central está llamando: contesta tu anexo.'));
  } catch (e) {
    message.error(errText(e, tr('The exchange did not answer.', 'La central no respondió.')));
  } finally {
    dialing.value = false;
  }
}

let tick = null;
let beat = null;
onMounted(async () => {
  await load();
  tick = setInterval(() => { now.value = Date.now(); }, 1000);
  // A sign of life every 30 s keeps the shift open and the lead held.
  beat = setInterval(() => { if (data.value.session) http.post('callcenter/work/heartbeat').catch(() => {}); }, 30000);
});
onBeforeUnmount(() => { clearInterval(tick); clearInterval(beat); });
</script>

<style scoped>
.mt8 { margin-top: 8px; }
.mt4 { margin-top: 4px; }
.muted { opacity: 0.65; }
.small { font-size: 12px; }
.bar-row { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; justify-content: space-between; }
.state { display: flex; align-items: center; gap: 6px; }
.kpis { display: flex; gap: 14px; flex-wrap: wrap; font-size: 13px; }
.kpis .ok b { color: #389e0d; }
.camps { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 8px; }
.camp { border: 1px solid rgba(128, 128, 128, 0.25); border-radius: 8px; padding: 8px 10px; cursor: pointer; transition: border-color 0.15s; }
.camp:hover { border-color: var(--ant-primary-color, #1677ff); }
.camp.on { border-color: var(--ant-primary-color, #1677ff); box-shadow: 0 0 0 2px rgba(22, 119, 255, 0.15); }
.camp-name { font-weight: 600; }
.next-wrap { display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 18px 0; text-align: center; }
.lead-name { font-size: 17px; margin-right: 8px; }
.phone-row { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.phone { font-size: 24px; font-weight: 700; letter-spacing: 0.5px; }
.timer { font-variant-numeric: tabular-nums; font-weight: 600; color: #cf1322; }
.results :deep(.ant-radio-button-wrapper) { margin: 0 4px 4px 0; border-radius: 4px; border-left-width: 1px; }
.results :deep(.ant-radio-button-wrapper::before) { display: none; }
.results :deep(.r-sale.ant-radio-button-wrapper-checked) { background: #389e0d; border-color: #389e0d; }
.results :deep(.r-do_not_call.ant-radio-button-wrapper-checked), .results :deep(.r-wrong_number.ant-radio-button-wrapper-checked) { background: #cf1322; border-color: #cf1322; }
.lbl { font-size: 12px; opacity: 0.75; margin-bottom: 2px; }
.save-row { display: flex; justify-content: flex-end; gap: 8px; margin-top: 10px; }
.script { white-space: pre-wrap; line-height: 1.55; max-height: 60vh; overflow: auto; }
.recent { display: flex; align-items: center; gap: 8px; padding: 4px 0; border-bottom: 1px solid rgba(128, 128, 128, 0.12); font-size: 13px; }
.recent .grow { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
</style>
