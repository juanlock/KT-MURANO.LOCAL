<template>
  <!-- Call center quality (2026-11-17): score a call 0-5 on each criterion; the review's score is the weighted %. -->
  <a-modal :open="!!call" :title="tr('Review the call', 'Evaluar la llamada')" width="620px" :confirm-loading="saving" :ok-text="tr('Save', 'Guardar')" @ok="save" @cancel="$emit('close')">
    <template v-if="call">
      <div class="head">
        <b>{{ call.username }}</b> · {{ call.lead || call.phone }} · {{ call.result_label }} · {{ duration(call.duration_sec) }}
        <div class="muted">{{ call.campaign }} · {{ fmt.dateTime(call.started_at) }}</div>
        <div v-if="call.notes" class="notes">{{ call.notes }}</div>
        <a v-if="call.recording_url" :href="call.recording_url" target="_blank" rel="noopener">{{ tr('Recording', 'Grabación') }}</a>
      </div>
      <a-spin :spinning="loading">
        <div v-for="c in criteria" :key="c.id" class="crit">
          <span class="name">{{ c.name }} <span class="muted">×{{ c.weight }}</span></span>
          <a-rate v-model:value="scores[c.id]" :count="5" allow-clear />
        </div>
      </a-spin>
      <div class="total">{{ tr('Score', 'Puntaje') }}: <b>{{ total }} %</b></div>
      <a-textarea v-model:value="comments" :rows="3" :placeholder="tr('Feedback for the agent', 'Comentarios para el agente')" />
    </template>
  </a-modal>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { message } from 'ant-design-vue';
import http from '../lib/http';
import { useFormat } from '../composables/useFormat';
import { useTr, duration, errText } from '../lib/callcenter';

const props = defineProps({ call: { type: Object, default: null }, review: { type: Object, default: null } });
const emit = defineEmits(['close', 'saved']);
const tr = useTr();
const fmt = useFormat();
const criteria = ref([]);
const scores = ref({});
const comments = ref('');
const loading = ref(false);
const saving = ref(false);

const total = computed(() => {
  let got = 0;
  let max = 0;
  criteria.value.forEach(c => { got += (scores.value[c.id] || 0) * c.weight; max += 5 * c.weight; });
  return max ? Math.round((1000 * got) / max) / 10 : 0;
});

watch(() => props.call, async c => {
  if (!c) return;
  loading.value = true;
  try {
    criteria.value = ((await http.get('callcenter/quality/criteria')).rows || []).filter(x => x.active);
    const prev = props.review?.scores || {};
    scores.value = Object.fromEntries(criteria.value.map(x => [x.id, prev[x.id] ?? 0]));
    comments.value = props.review?.comments || '';
  } finally {
    loading.value = false;
  }
});

async function save() {
  saving.value = true;
  try {
    const r = await http.post('callcenter/quality/reviews', { call_id: props.call.id, scores: scores.value, comments: comments.value || null });
    message.success(tr(`Saved: ${r.score} %`, `Guardado: ${r.score} %`));
    emit('saved');
    emit('close');
  } catch (e) {
    message.error(errText(e, tr('Could not save.', 'No se pudo guardar.')));
  } finally {
    saving.value = false;
  }
}
</script>

<style scoped>
.head { margin-bottom: 10px; }
.muted { opacity: 0.65; font-size: 12px; }
.notes { margin-top: 4px; padding: 6px 8px; border-radius: 6px; background: rgba(128, 128, 128, 0.1); }
.crit { display: flex; justify-content: space-between; align-items: center; padding: 3px 0; border-bottom: 1px solid rgba(128, 128, 128, 0.12); }
.total { margin: 10px 0 6px; font-size: 15px; }
</style>
