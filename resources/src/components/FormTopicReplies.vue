<template>
  <!-- Automatic replies by topic (user's rules, 2026-11-25 / 2026-11-26): the business user reads the suggested text, adjusts it,
       turns it on, changes it later or turns it off; editing the form itself stays with the IT Admin (forms_manage). -->
  <a-modal :open="open" :title="(es ? 'Respuestas automáticas' : 'Automatic replies') + (form ? ' · ' + form.name : '')"
    :width="860" :footer="null" destroy-on-close @cancel="emit('update:open', false)">
    <a-spin :spinning="loading">
      <a-empty v-if="!loading && !list.length" :description="es ? 'Este formulario no tiene motivos con respuesta automática.' : 'This form has no topics with an automatic reply.'" />
      <a-row v-else :gutter="14">
        <a-col :xs="24" :md="8">
          <div class="tr-muted" style="margin-bottom: 6px">{{ es ? 'Motivo del mensaje' : 'Message topic' }}</div>
          <div v-for="r in list" :key="r.topic" class="tr-topic" :class="{ on: r.topic === topic }" @click="pick(r.topic)">
            <span class="tr-ellipsis">{{ r.topic }}</span>
            <a-tag :color="r.active ? 'green' : 'gold'" class="tr-tag">{{ r.active ? (es ? 'Activa' : 'On') : (es ? 'Sugerida' : 'Suggested') }}</a-tag>
          </div>
        </a-col>
        <a-col :xs="24" :md="16">
          <template v-if="edit">
            <a-alert v-if="!edit.active" type="warning" show-icon style="margin-bottom: 10px; padding: 4px 10px"
              :message="es ? 'Sugerida: aún NO se envía. Revise el texto y actívela.' : 'Suggested: NOT sent yet. Review the text and turn it on.'" />
            <a-alert v-else type="success" show-icon style="margin-bottom: 10px; padding: 4px 10px"
              :message="es ? 'Activa: se envía sola a quien escribe por este motivo. Puede cambiar el texto o desactivarla cuando quiera.' : 'On: it is sent by itself to whoever writes about this topic. Change the text or turn it off at any time.'" />
            <div class="tr-muted">{{ es ? 'Asunto del correo' : 'Email subject' }}</div>
            <a-input v-model:value="edit.subject" :disabled="!canApprove" :maxlength="190" style="margin-bottom: 8px" />
            <div class="tr-muted">{{ es ? 'Texto que recibirá la persona' : 'Text the person will receive' }}</div>
            <a-textarea v-model:value="edit.message" :disabled="!canApprove" :auto-size="{ minRows: 8, maxRows: 16 }" :maxlength="5000" />
            <div v-if="canApprove" style="margin-top: 12px; display: flex; gap: 8px; justify-content: flex-end; flex-wrap: wrap">
              <a-button v-if="edit.active" danger :loading="saving" @click="save(false)">{{ es ? 'Desactivar' : 'Turn off' }}</a-button>
              <a-button v-if="edit.active" type="primary" :loading="saving" @click="save(true)">{{ es ? 'Guardar texto' : 'Save text' }}</a-button>
              <template v-else>
                <a-button :loading="saving" @click="save(false)">{{ es ? 'Guardar sin activar' : 'Save, keep off' }}</a-button>
                <a-button type="primary" :loading="saving" @click="save(true)">{{ es ? 'Confirmar texto y activar' : 'Confirm text and turn on' }}</a-button>
              </template>
            </div>
            <div v-else class="tr-muted" style="margin-top: 10px">{{ es ? 'Su rol solo puede ver estas respuestas.' : 'Your role can only view these replies.' }}</div>
          </template>
        </a-col>
      </a-row>
    </a-spin>
  </a-modal>
</template>

<script setup>
/** The automatic replies of a contact form, one per topic (server: forms/{id}/topic-replies, permission forms_replies to save). */
import { computed, ref, watch } from 'vue';
import { message } from 'ant-design-vue';
import http from '../lib/http';
import { useUiStore } from '../stores/ui';

const props = defineProps({ open: Boolean, form: { type: Object, default: null }, initialTopic: { type: String, default: null } });
const emit = defineEmits(['update:open', 'saved']);
const ui = useUiStore();
const es = computed(() => String(ui.locale || 'en').startsWith('es'));
const loading = ref(false);
const saving = ref(false);
const list = ref([]);
const topic = ref(null);
const edit = ref(null);
const canApprove = ref(false);

function pick(t) {
  const r = list.value.find(x => x.topic === t) || list.value[0];
  topic.value = r ? r.topic : null;
  edit.value = r ? { ...r } : null;
}
async function load() {
  if (!props.form) return;
  loading.value = true;
  list.value = [];
  edit.value = null;
  try {
    const r = await http.get(`forms/${props.form.id}/topic-replies`);
    list.value = r.replies || [];
    canApprove.value = !!r.can_approve;
    pick(props.initialTopic || (list.value.find(x => !x.active) || {}).topic);
  } catch (e) {
    message.error(es.value ? 'No se pudieron cargar las respuestas' : 'Could not load the replies');
    emit('update:open', false);
  }
  loading.value = false;
}
async function save(active) {
  const e = edit.value;
  if (!e || !String(e.subject || '').trim() || !String(e.message || '').trim()) {
    message.warning(es.value ? 'El asunto y el texto no pueden quedar vacíos' : 'Subject and text cannot be empty');
    return;
  }
  saving.value = true;
  try {
    const r = await http.put(`forms/${props.form.id}/topic-replies`, { topic: e.topic, subject: e.subject, message: e.message, active });
    const i = list.value.findIndex(x => x.topic === e.topic);
    if (i >= 0) list.value[i] = r.reply;
    edit.value = { ...r.reply };
    message.success(active ? (es.value ? 'Respuesta activada' : 'Reply turned on') : (es.value ? 'Guardada, sin enviar' : 'Saved, not sent'));
    emit('saved', r.reply);
  } catch (err) { message.error(err?.data?.message || (es.value ? 'No se pudo guardar' : 'Could not save')); }
  saving.value = false;
}
watch(() => [props.open, props.form?.id], ([o]) => { if (o) load(); }, { immediate: true });
</script>

<style scoped>
.tr-muted { font-size: 12px; color: var(--ant-color-text-secondary, #888); }
.tr-topic { display: flex; justify-content: space-between; align-items: center; gap: 6px; padding: 5px 8px; border-radius: 4px; cursor: pointer; }
.tr-topic:hover { background: var(--ant-color-fill-quaternary, #fafafa); }
.tr-topic.on { background: #e6f4ff; }
.tr-ellipsis { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tr-tag { margin: 0; }
</style>
