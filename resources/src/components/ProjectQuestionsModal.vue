<template>
  <!-- PREGUNTAS (user, 2026-11-23): the questions of the projects for this login; each answer goes to its task. -->
  <a-modal :open="open" :width="isPhone ? '100%' : 760" :footer="null" :title="null" class="pq-modal" @cancel="later">
    <div class="pq-head">
      <QuestionCircleOutlined class="pq-ico" />
      <div>
        <h3>{{ tr('Questions', 'Preguntas') }}</h3>
        <div class="muted">{{ pending.length
          ? tr(`${pending.length} question(s) to answer. Your answers go straight to the project.`, `${pending.length} pregunta(s) por responder. Sus respuestas van directo al proyecto.`)
          : tr('Nothing to answer. Thank you!', 'No hay nada por responder. ¡Gracias!') }}</div>
      </div>
    </div>
    <a-tabs v-model:activeKey="tab" size="small">
      <a-tab-pane key="pending" :tab="`${tr('To answer', 'Por responder')} (${pending.length})`">
        <a-spin :spinning="loading">
          <div class="pq-list">
            <div v-for="q in pending" :key="q.id" class="pq-card" :class="{ urgent: q.urgent }">
              <div class="pq-meta">
                <a-tag v-if="q.urgent" color="red">{{ tr('Urgent', 'Urgente') }}</a-tag>
                <span class="muted">{{ q.project }}<template v-if="q.task"> · {{ q.task }}</template></span>
              </div>
              <div class="pq-q">{{ q.question }}</div>
              <div v-if="q.help" class="pq-help">{{ q.help }}</div>

              <a-radio-group v-if="q.kind === 'yesno'" v-model:value="draft[q.id].answer">
                <a-radio-button value="Sí">{{ tr('Yes', 'Sí') }}</a-radio-button><a-radio-button value="No">No</a-radio-button>
              </a-radio-group>
              <a-radio-group v-else-if="q.kind === 'choice'" v-model:value="draft[q.id].answer" class="pq-choice">
                <a-radio v-for="o in q.options || []" :key="o" :value="o">{{ o }}</a-radio>
              </a-radio-group>
              <a-input-number v-else-if="q.kind === 'number'" v-model:value="draft[q.id].answer" style="width: 180px" :placeholder="tr('Number', 'Número')" />
              <template v-else-if="q.kind === 'confirm'">
                <a-radio-group v-model:value="draft[q.id].mode">
                  <a-radio value="ok">{{ tr('Correct', 'Correcto') }}: <b>{{ q.current_value }}</b></a-radio>
                  <a-radio value="fix">{{ tr('Correct it', 'Corregir') }}</a-radio>
                </a-radio-group>
                <a-input v-if="draft[q.id].mode === 'fix'" v-model:value="draft[q.id].answer" class="pq-fix" :placeholder="tr('The right value', 'El dato correcto')" />
              </template>
              <a-textarea v-else v-model:value="draft[q.id].answer" :auto-size="{ minRows: 1, maxRows: 5 }" :placeholder="tr('Your answer', 'Su respuesta')" />

              <div class="pq-foot">
                <a-input v-model:value="draft[q.id].comment" size="small" class="pq-comment" :placeholder="tr('Comment (optional)', 'Comentario (opcional)')" />
                <a-button type="primary" size="small" :disabled="!ready(q)" :loading="saving === q.id" @click="send(q)">{{ tr('Answer', 'Responder') }}</a-button>
              </div>
            </div>
            <a-empty v-if="!loading && !pending.length" :description="tr('All answered', 'Todo respondido')" />
          </div>
        </a-spin>
      </a-tab-pane>
      <a-tab-pane key="done" :tab="`${tr('Answered', 'Respondidas')} (${answered.length})`">
        <div class="pq-list">
          <div v-for="q in answered" :key="q.id" class="pq-card done">
            <div class="pq-meta"><span class="muted">{{ q.project }}<template v-if="q.task"> · {{ q.task }}</template> · {{ dateTime(q.answered_at) }}</span></div>
            <div class="pq-q">{{ q.question }}</div>
            <div class="pq-ans"><CheckCircleFilled /> {{ q.answer === 'Correcto' && q.current_value ? `${q.answer} (${q.current_value})` : q.answer }}</div>
            <div v-if="q.comment" class="pq-help">{{ q.comment }}</div>
          </div>
          <a-empty v-if="!answered.length" :description="tr('Nothing answered yet', 'Nada respondido todavía')" />
        </div>
      </a-tab-pane>
    </a-tabs>
    <div class="pq-actions">
      <a-button @click="later">{{ pending.length ? tr('Later', 'Más tarde') : tr('Close', 'Cerrar') }}</a-button>
      <a-button v-if="filled.length > 1" type="primary" :loading="saving === 'all'" @click="sendAll">{{ tr(`Answer the ${filled.length} filled in`, `Responder las ${filled.length} llenas`) }}</a-button>
    </div>
  </a-modal>
</template>

<script setup>
/**
 * The PREGUNTAS window (user, 2026-11-23): the project questions asked to this login (any company), by kind -- yes/no,
 * choice, number, text, confirm a value we have -- each answered on its own or all the filled ones at once. "Más tarde"
 * keeps it closed until tomorrow (urgent ones: 4 hours). ProjectQuestionController mine / answer / later.
 */
import { ref, reactive, computed, watch } from 'vue';
import { message } from 'ant-design-vue';
import { QuestionCircleOutlined, CheckCircleFilled } from '@ant-design/icons-vue';
import http from '../lib/http';
import { useTr } from '../lib/useTr';
import { useFormat } from '../composables/useFormat';
import { useIsPhone } from '../lib/useIsPhone';

const props = defineProps({ open: { type: Boolean, default: false } });
const emit = defineEmits(['update:open', 'changed']);
const tr = useTr();
const { dateTime } = useFormat();
const isPhone = useIsPhone();

const tab = ref('pending');
const loading = ref(false);
const saving = ref(null);
const rows = ref([]);
const draft = reactive({});
const pending = computed(() => rows.value.filter(q => q.status === 'pending'));
const answered = computed(() => rows.value.filter(q => q.status === 'answered'));

async function load() {
  loading.value = true;
  try {
    rows.value = (await http.get('my/questions')).questions || [];
    rows.value.forEach((q) => { if (!draft[q.id]) draft[q.id] = { answer: q.kind === 'number' ? null : '', comment: '', mode: q.kind === 'confirm' ? 'ok' : null }; });
    tab.value = pending.value.length ? 'pending' : 'done';
  } finally { loading.value = false; }
}
watch(() => props.open, (v) => { if (v) load(); }, { immediate: true });

const valueOf = (q) => {
  const d = draft[q.id] || {};
  if (q.kind === 'confirm') return d.mode === 'ok' ? 'Correcto' : String(d.answer || '').trim();
  return d.answer === null || d.answer === undefined ? '' : String(d.answer).trim();
};
const ready = q => valueOf(q) !== '';
const filled = computed(() => pending.value.filter(ready));

async function post(q) {
  await http.post(`my/questions/${q.id}/answer`, { answer: valueOf(q), comment: (draft[q.id]?.comment || '').trim() || null });
}
async function send(q) {
  saving.value = q.id;
  try {
    await post(q);
    message.success(tr('Answer saved', 'Respuesta guardada'));
    await load();
    emit('changed', pending.value.length);
  } catch (e) { message.error(e?.data?.message || tr('Could not save', 'No se pudo guardar')); } finally { saving.value = null; }
}
async function sendAll() {
  saving.value = 'all';
  let n = 0;
  try {
    for (const q of filled.value) { await post(q); n++; }
    message.success(tr(`${n} answers saved`, `${n} respuestas guardadas`));
  } catch (e) { message.error(e?.data?.message || tr('Could not save', 'No se pudo guardar')); } finally {
    saving.value = null;
    await load();
    emit('changed', pending.value.length);
  }
}
async function later() {
  if (pending.value.length) { try { await http.post('my/questions/later', {}); } catch (e) { /* only closes */ } }
  emit('update:open', false);
}
</script>

<style scoped>
.pq-head { display: flex; gap: 12px; align-items: center; margin-bottom: 4px; }
.pq-head h3 { margin: 0; font-size: 18px; }
.pq-ico { font-size: 28px; color: var(--app-primary, #1677ff); }
.muted { opacity: 0.65; font-size: 12px; }
.pq-list { max-height: min(62vh, 620px); overflow-y: auto; display: flex; flex-direction: column; gap: 10px; padding-right: 4px; }
.pq-card { border: 1px solid rgba(128, 128, 128, 0.25); border-radius: 8px; padding: 10px 12px; }
.pq-card.urgent { border-color: #ff7875; background: rgba(255, 77, 79, 0.04); }
.pq-card.done { opacity: 0.9; }
.pq-meta { display: flex; gap: 6px; align-items: center; margin-bottom: 4px; }
.pq-q { font-weight: 600; margin-bottom: 4px; }
.pq-help { font-size: 12px; opacity: 0.7; margin-bottom: 6px; }
.pq-choice { display: flex; flex-direction: column; gap: 2px; }
.pq-fix { margin-top: 6px; }
.pq-foot { display: flex; gap: 8px; margin-top: 8px; align-items: center; }
.pq-comment { flex: 1; }
.pq-ans { color: #389e0d; font-weight: 600; }
.pq-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 12px; }
</style>
