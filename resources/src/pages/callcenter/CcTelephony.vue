<template>
  <!-- Telephone exchange (2026-11-17): for the call centers that dial through one. Click to call + the exchange sends each call. -->
  <div class="page">
    <PageHeader :title="tr('Telephone exchange', 'Central telefónica')" :breadcrumb="[tr('Call center', 'Centro de llamadas'), tr('Telephone exchange', 'Central telefónica')]" />

    <a-spin :spinning="loading">
      <a-row :gutter="12">
        <a-col :xs="24" :lg="12">
          <a-card size="small" :title="tr('Connection', 'Conexión')">
            <a-form layout="vertical">
              <a-form-item :label="tr('Exchange', 'Central')">
                <a-select v-model:value="s.provider" :options="providers" />
              </a-form-item>
              <a-form-item>
                <template #label>{{ tr('Click to call address', 'Dirección para «clic para llamar»') }}
                  <a-tooltip :title="tr('Murano calls it when the agent presses Call: {extension} is the agent\'s extension, {phone} the lead\'s number. Empty = the agents dial by hand.', 'Murano la llama cuando el agente pulsa Llamar: {extension} es el anexo del agente, {phone} el número del lead. Vacío = los agentes marcan a mano.')"><InfoCircleOutlined class="info" /></a-tooltip>
                </template>
                <a-input v-model:value="s.dial_url" :placeholder="dialExample" />
              </a-form-item>
              <a-form-item>
                <template #label>{{ tr('Hide the number and ID from the agents', 'Ocultar el número y el DNI a los agentes') }}
                  <a-tooltip :title="tr('The agent sees the lead\'s numbers as ••• ••• 123 and no ID document, and calls with the Call button. Only for agents with an extension and when the click to call address is set; supervisors and admins see everything. Saved with the Save button.', 'El agente ve los números del lead como ••• ••• 123 y no ve el DNI; llama con el botón Llamar. Solo para agentes con anexo y cuando la dirección de «clic para llamar» está puesta; supervisores y administradores ven todo. Se guarda con el botón Guardar.')"><InfoCircleOutlined class="info" /></a-tooltip>
                </template>
                <a-switch v-model:checked="s.hide_from_agent" />
                <span v-if="s.hide_from_agent && !s.dial_url" class="muted small" style="margin-left: 8px">{{ tr('Needs the click to call address: without it the agents dial by hand and see the number.', 'Necesita la dirección de «clic para llamar»: sin ella los agentes marcan a mano y ven el número.') }}</span>
              </a-form-item>
              <a-form-item>
                <template #label>{{ tr('Address where the exchange sends each call', 'Dirección donde la central envía cada llamada') }}
                  <a-tooltip :title="tr('Give it to whoever set up the exchange: at the end of each call it sends the data here (POST or GET). Anyone with this address can record calls: renew it if it leaks.', 'Entrégala a quien configuró la central: al terminar cada llamada envía aquí los datos (POST o GET). Quien tenga esta dirección puede registrar llamadas: renuévala si se filtra.')"><InfoCircleOutlined class="info" /></a-tooltip>
                </template>
                <a-input-group compact>
                  <a-input :value="s.webhook_url" readonly style="width: calc(100% - 150px)" />
                  <a-button @click="copy(s.webhook_url)"><CopyOutlined /></a-button>
                  <a-popconfirm :title="tr('Make a new address? The old one stops working.', '¿Crear una dirección nueva? La anterior deja de funcionar.')" @confirm="newToken">
                    <a-button>{{ tr('Renew', 'Renovar') }}</a-button>
                  </a-popconfirm>
                </a-input-group>
              </a-form-item>
              <a-collapse ghost>
                <a-collapse-panel key="1" :header="tr('What the exchange sends (fields)', 'Qué envía la central (campos)')">
                  <pre class="code">{{ payloadExample }}</pre>
                  <div class="muted small">{{ tr('Also accepted: Asterisk / Issabel CDR names (uniqueid, src, dst, billsec, disposition, calldate) and Twilio\'s (CallSid, From, To, CallDuration, CallStatus, RecordingUrl).', 'También se aceptan los nombres del CDR de Asterisk / Issabel (uniqueid, src, dst, billsec, disposition, calldate) y los de Twilio (CallSid, From, To, CallDuration, CallStatus, RecordingUrl).') }}</div>
                </a-collapse-panel>
              </a-collapse>
            </a-form>
          </a-card>
        </a-col>
        <a-col :xs="24" :lg="12">
          <a-card size="small" :title="tr('Agents\' extensions', 'Anexos de los agentes')">
            <a-table :data-source="s.agents" :columns="agentColumns" row-key="user_id" size="small" :pagination="false">
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'ext'"><a-input v-model:value="record.extension" size="small" :placeholder="tr('e.g. 201', 'ej. 201')" style="width: 110px" /></template>
              </template>
            </a-table>
            <div class="foot"><a-button type="primary" :loading="saving" @click="save">{{ tr('Save', 'Guardar') }}</a-button></div>
          </a-card>
        </a-col>
      </a-row>

      <a-card size="small" :title="tr('Last calls received from the exchange', 'Últimas llamadas recibidas de la central')" class="mt10">
        <a-table :data-source="s.recent" :columns="recentColumns" row-key="id" size="small" :pagination="false">
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'when'">{{ fmt.dateTime(record.started_at) }}</template>
            <template v-else-if="column.key === 'dir'">{{ record.direction === 'inbound' ? tr('Inbound', 'Entrante') : tr('Outbound', 'Saliente') }}</template>
            <template v-else-if="column.key === 'duration'">{{ duration(record.duration_sec) }}</template>
            <template v-else-if="column.key === 'result'">
              <a-tag v-if="record.result" :color="RESULT_COLORS[record.result]">{{ record.result_label }}</a-tag>
              <span v-else class="muted">{{ tr('answered', 'contestada') }}</span>
            </template>
            <template v-else-if="column.key === 'agent'">{{ record.username || tr('(extension not assigned)', '(anexo sin asignar)') }}</template>
          </template>
          <template #emptyText>{{ tr('Nothing received yet.', 'Aún no se recibió nada.') }}</template>
        </a-table>
      </a-card>
    </a-spin>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { CopyOutlined, InfoCircleOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';
import { useTr, duration, RESULT_COLORS, errText } from '../../lib/callcenter';

const tr = useTr();
const fmt = useFormat();
const s = ref({ provider: 'none', dial_url: '', hide_from_agent: false, webhook_url: '', agents: [], recent: [] });
const loading = ref(true);
const saving = ref(false);

const providers = computed(() => [
  { value: 'none', label: tr('None (the agents dial by hand)', 'Ninguna (los agentes marcan a mano)') },
  { value: 'asterisk', label: 'Asterisk / Issabel / FreePBX' },
  { value: '3cx', label: '3CX' },
  { value: 'twilio', label: 'Twilio' },
  { value: 'other', label: tr('Other', 'Otra') },
]);
const dialExample = computed(() => ({
  asterisk: 'http://192.168.1.10/murano-dial.php?ext={extension}&num={phone}',
  '3cx': 'https://empresa.3cx.pe/webclient/api/MakeCall?ext={extension}&to={phone}',
  twilio: 'https://su-servidor/twilio-call?from={extension}&to={phone}',
}[s.value.provider] || 'https://…?ext={extension}&phone={phone}'));
const payloadExample = `{
  "extension": "201",          // ${tr("the agent's extension", 'anexo del agente')}
  "phone": "987654321",        // ${tr('the number called', 'número llamado')}
  "direction": "outbound",     // outbound | inbound
  "started_at": "2026-11-17 10:15:00",
  "duration": 184,             // ${tr('seconds', 'segundos')}
  "result": "answered",        // answered | no answer | busy | failed
  "external_id": "1731852900.42",
  "recording_url": "https://…/grabacion.mp3"
}`;

const agentColumns = computed(() => [
  { title: tr('Agent', 'Agente'), dataIndex: 'name' },
  { title: tr('Login', 'Usuario'), dataIndex: 'email' },
  { title: tr('Extension', 'Anexo'), key: 'ext', width: 130 },
]);
const recentColumns = computed(() => [
  { title: tr('When', 'Fecha'), key: 'when', width: 140 },
  { title: tr('Extension', 'Anexo'), dataIndex: 'extension', width: 90 },
  { title: tr('Agent', 'Agente'), key: 'agent', width: 160 },
  { title: tr('Phone', 'Teléfono'), dataIndex: 'phone', width: 130 },
  { title: tr('Direction', 'Sentido'), key: 'dir', width: 100 },
  { title: tr('Duration', 'Duración'), key: 'duration', width: 90 },
  { title: tr('Result', 'Resultado'), key: 'result', width: 140 },
]);

async function load() {
  loading.value = true;
  try {
    s.value = await http.get('callcenter/telephony');
  } catch (e) {
    message.error(errText(e, tr('Could not load.', 'No se pudo cargar.')));
  } finally {
    loading.value = false;
  }
}

async function save() {
  saving.value = true;
  try {
    s.value = await http.post('callcenter/telephony', {
      provider: s.value.provider, dial_url: s.value.dial_url || null, hide_from_agent: !!s.value.hide_from_agent,
      extensions: Object.fromEntries(s.value.agents.map(a => [a.user_id, a.extension || null])),
    });
    message.success(tr('Saved.', 'Guardado.'));
  } catch (e) {
    message.error(errText(e, tr('Could not save.', 'No se pudo guardar.')));
  } finally {
    saving.value = false;
  }
}

async function newToken() {
  try {
    s.value = await http.post('callcenter/telephony/token');
    message.success(tr('New address made.', 'Dirección nueva creada.'));
  } catch (e) {
    message.error(errText(e, tr('Could not renew it.', 'No se pudo renovar.')));
  }
}

function copy(text) {
  navigator.clipboard?.writeText(text).then(() => message.success(tr('Copied.', 'Copiado.')));
}

onMounted(load);
</script>

<style scoped>
.info { opacity: 0.55; margin-left: 4px; }
.code { font-size: 12px; background: rgba(128, 128, 128, 0.1); padding: 8px; border-radius: 6px; white-space: pre-wrap; }
.muted { opacity: 0.65; }
.small { font-size: 12px; }
.foot { display: flex; justify-content: flex-end; margin-top: 10px; }
.mt10 { margin-top: 10px; }
</style>
