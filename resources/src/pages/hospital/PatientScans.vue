<template>
  <!--
    Patient scans (2026-10-26): the insurance card and the ID document, front and back,
    scanned or photographed at the arrival (on a phone / tablet the button opens the camera).
    Never a payment card. With a patient: uploaded right away (private file of the company,
    shown through a signed link of a few minutes). Without one yet (a new patient at the
    hospital window): kept here (v-model:pending) and sent with uploadPendingScans() once
    the arrival has created the patient.
  -->
  <div class="ps">
    <div class="ps-h">
      <CameraOutlined /> {{ s('Scanned documents') }}
      <span class="muted small">{{ s('Photo or scan — JPG, PNG, PDF') }}</span>
    </div>
    <div class="ps-grid">
      <div v-for="k in shownKinds" :key="k" class="ps-slot">
        <div class="ps-label">{{ s(KIND_LABEL[k]) }}</div>
        <div v-if="thumb(k)" class="ps-thumb">
          <a v-if="thumb(k).isPdf" :href="thumb(k).url" target="_blank" rel="noopener" class="ps-pdf"><FilePdfOutlined /> PDF</a>
          <a-image v-else :src="thumb(k).url" :width="120" :height="78" class="ps-img" />
          <div class="ps-actions">
            <label class="ps-link">
              {{ s('Replace') }}
              <input type="file" accept="image/*,application/pdf" capture="environment" hidden @change="e => pick(k, e)">
            </label>
            <a-popconfirm :title="s('Remove this scan?')" :ok-text="s('Yes')" :cancel-text="s('No')" @confirm="remove(k)">
              <a class="ps-link danger">{{ s('Remove') }}</a>
            </a-popconfirm>
          </div>
        </div>
        <label v-else class="ps-add" :class="{ busy: busy === k }">
          <a-spin v-if="busy === k" size="small" />
          <template v-else><CameraOutlined /><span>{{ s('Scan / photo') }}</span></template>
          <input type="file" accept="image/*,application/pdf" capture="environment" hidden :disabled="!!busy" @change="e => pick(k, e)">
        </label>
      </div>
    </div>
    <div v-if="!patientId && pendingCount" class="muted small">{{ s('They are saved with the patient when the arrival is registered.') }}</div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { message } from 'ant-design-vue';
import { CameraOutlined, FilePdfOutlined } from '@ant-design/icons-vue';
import http from '../../lib/http';
import { useHospitalText } from './lang/useHospitalText';
import ES from './lang/PatientScans.es.js';

const props = defineProps({
  patientId: { type: [Number, String], default: null },
  // Which sides to ask for: e.g. ['insurance', 'id'] or ['id'] (a payment card is never scanned).
  docs: { type: Array, default: () => ['insurance', 'id'] },
  // Where it was taken (kept with the scan): { appointment_id } / { er_case_id } / { admission_id }.
  context: { type: Object, default: () => ({}) },
  // New patient: the files wait here until uploadPendingScans().
  pending: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['update:pending', 'changed']);
const { s } = useHospitalText(ES);

const KIND_LABEL = {
  insurance_front: 'Insurance card — front', insurance_back: 'Insurance card — back',
  id_front: 'ID document — front', id_back: 'ID document — back',
};
const shownKinds = computed(() => props.docs.flatMap(d => [`${d}_front`, `${d}_back`]));

const scans = ref([]);
const busy = ref(null);
const previews = ref({}); // kind -> object URL of a pending file
const pendingCount = computed(() => Object.keys(props.pending || {}).length);

async function load() {
  scans.value = [];
  if (!props.patientId) return;
  try {
    scans.value = (await http.get(`hospital/patients/${props.patientId}/scans`))?.scans || [];
  } catch (e) { /* no permission / not found: nothing to show */ }
}
watch(() => props.patientId, load, { immediate: true });

function thumb(kind) {
  if (!props.patientId && props.pending?.[kind]) {
    const f = props.pending[kind];
    return { url: previews.value[kind], isPdf: f.type === 'application/pdf' };
  }
  const row = scans.value.find(r => r.kind === kind);
  return row ? { url: row.url, isPdf: row.mime === 'application/pdf' } : null;
}

async function pick(kind, e) {
  const file = e.target.files?.[0];
  e.target.value = '';
  if (!file) return;
  if (file.size > 15 * 1024 * 1024) return message.warning(s('The file is over 15 MB.'));
  if (!props.patientId) {
    if (previews.value[kind]) URL.revokeObjectURL(previews.value[kind]);
    previews.value = { ...previews.value, [kind]: URL.createObjectURL(file) };
    emit('update:pending', { ...(props.pending || {}), [kind]: file });
    return;
  }
  busy.value = kind;
  try {
    const res = await uploadScan(props.patientId, kind, file, props.context);
    scans.value = res.scans || scans.value;
    emit('changed', scans.value);
    message.success(s('Saved'));
  } catch (err) {
    message.error(err?.message || s('Could not upload'));
  } finally {
    busy.value = null;
  }
}

async function remove(kind) {
  if (!props.patientId) {
    const next = { ...(props.pending || {}) };
    delete next[kind];
    if (previews.value[kind]) URL.revokeObjectURL(previews.value[kind]);
    emit('update:pending', next);
    return;
  }
  const row = scans.value.find(r => r.kind === kind);
  if (!row) return;
  try {
    scans.value = (await http.delete(`hospital/patient-scans/${row.id}`))?.scans || [];
    emit('changed', scans.value);
  } catch (err) {
    message.error(err?.data?.message || s('Could not remove'));
  }
}

onBeforeUnmount(() => Object.values(previews.value).forEach(u => URL.revokeObjectURL(u)));
</script>

<script>
import { uploadForm as upload } from '../../lib/upload';

/** One scan up (resolves with the server's { scans }; rejects with a readable message). */
export async function uploadScan(patientId, kind, file, context = {}) {
  const fd = new FormData();
  fd.append('patient_id', patientId);
  fd.append('kind', kind);
  fd.append('file', file);
  Object.entries(context || {}).forEach(([k, v]) => { if (v) fd.append(k, v); });
  const { status, data } = await upload('hospital/patient-scans', fd);
  if (status >= 400) throw new Error(data?.message || 'Could not upload');
  return data || {};
}

/** The scans kept while the patient did not exist yet (v-model:pending), sent once it does. */
export async function uploadPendingScans(patientId, pending, context = {}) {
  for (const [kind, file] of Object.entries(pending || {})) {
    await uploadScan(patientId, kind, file, context);
  }
}
</script>

<style scoped>
.ps { border: 1px dashed rgba(128, 128, 128, 0.35); border-radius: 10px; padding: 10px 12px; margin-top: 4px; }
.ps-h { font-weight: 600; margin-bottom: 8px; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
/* Two columns: front | back of each document. */
.ps-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
.ps-slot { display: flex; flex-direction: column; gap: 4px; }
.ps-label { font-size: 12px; opacity: 0.75; }
.ps-add { height: 78px; border: 1px dashed rgba(22, 119, 255, 0.5); border-radius: 8px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; cursor: pointer; color: #1677ff; font-size: 12px; background: rgba(22, 119, 255, 0.04); }
.ps-add:hover { background: rgba(22, 119, 255, 0.09); }
.ps-add.busy { cursor: progress; }
.ps-thumb { display: flex; flex-direction: column; gap: 2px; }
.ps-img :deep(img), .ps-img { border-radius: 8px; object-fit: cover; }
.ps-pdf { height: 78px; width: 120px; border-radius: 8px; border: 1px solid rgba(128, 128, 128, 0.3); display: flex; align-items: center; justify-content: center; gap: 6px; }
.ps-actions { display: flex; gap: 10px; font-size: 12px; }
.ps-link { color: #1677ff; cursor: pointer; }
.ps-link.danger { color: #cf1322; }
.muted { opacity: 0.6; }
.small { font-size: 12px; font-weight: 400; }
</style>
