<template>
  <a-select
    :value="modelValue"
    show-search
    :filter-option="false"
    :options="options"
    :placeholder="placeholder"
    :loading="loading"
    :allow-clear="allowClear"
    :disabled="disabled"
    style="width: 100%"
    @search="onSearch"
    @change="onChange"
    @focus="ensureLoaded"
  >
    <template #notFoundContent>
      <div class="pp-empty">
        <a-spin v-if="loading" size="small" />
        <span v-else>{{ term ? 'No patient matches that.' : 'Type a name, MRN or phone number.' }}</span>
      </div>
    </template>
  </a-select>
</template>

<script setup>
/**
 * Patient selector used by every hospital form.
 *
 * A hospital can hold tens of thousands of patients, so this NEVER loads a full
 * dropdown — it queries hospital/search/patients (capped at 20 server-side) as
 * the user types. Lookups are debounced so a fast typist sends one request, not
 * one per keystroke.
 *
 * When editing an existing record the chosen patient may not be in the current
 * result set, so `initialOption` seeds the list to stop the select rendering a
 * bare id.
 */
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import http from '../../lib/http';

const props = defineProps({
  modelValue: { type: [Number, String], default: undefined },
  // { id, name, mrn } for the already-selected patient, when editing.
  initialOption: { type: Object, default: null },
  placeholder: { type: String, default: 'Search patient by name, MRN or phone' },
  allowClear: { type: Boolean, default: true },
  disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue', 'select']);

const results = ref([]);
const loading = ref(false);
const term = ref('');
const loadedOnce = ref(false);
let timer = null;

const options = computed(() => {
  const list = results.value.map(p => ({
    value: p.id,
    label: p.label || `${p.name} · ${p.mrn}`,
    patient: p,
  }));

  // Keep the current selection visible even when it is not in the results.
  const selected = props.initialOption;
  if (selected?.id && !list.some(o => o.value === selected.id)) {
    list.unshift({
      value: selected.id,
      label: selected.label || `${selected.name}${selected.mrn ? ' · ' + selected.mrn : ''}`,
      patient: selected,
    });
  }

  return list;
});

async function fetchPatients(search) {
  loading.value = true;
  try {
    const data = await http.get('hospital/search/patients', { search });
    results.value = data?.patients || [];
    loadedOnce.value = true;
  } catch (e) {
    results.value = [];
  } finally {
    loading.value = false;
  }
}

function onSearch(value) {
  term.value = value;
  clearTimeout(timer);
  timer = setTimeout(() => fetchPatients(value), 300);
}

/** First open shows the most recent patients rather than an empty box. */
function ensureLoaded() {
  if (!loadedOnce.value && !loading.value) fetchPatients('');
}

function onChange(value, option) {
  emit('update:modelValue', value);
  emit('select', option?.patient || null);
}

watch(() => props.modelValue, value => {
  if (value === undefined || value === null) term.value = '';
});

onBeforeUnmount(() => clearTimeout(timer));
</script>

<style scoped>
.pp-empty {
  padding: 10px 4px;
  text-align: center;
  font-size: 12.5px;
  opacity: 0.6;
}
</style>
