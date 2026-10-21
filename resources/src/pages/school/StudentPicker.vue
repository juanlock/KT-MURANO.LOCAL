<template>
  <a-select
    :value="modelValue"
    show-search
    :filter-option="false"
    :options="options"
    :placeholder="placeholder || s('Search student by name or admission number')"
    :loading="loading"
    :allow-clear="allowClear"
    :disabled="disabled"
    style="width: 100%"
    @search="onSearch"
    @change="onChange"
    @focus="ensureLoaded"
  >
    <template #notFoundContent>
      <div class="sp-empty">
        <a-spin v-if="loading" size="small" />
        <span v-else>{{ term ? s('No student matches that.') : s('Type a name, admission number or guardian phone.') }}</span>
      </div>
    </template>
  </a-select>
</template>

<script setup>
/**
 * Student selector used by every school form.
 *
 * A school can hold thousands of students, so this NEVER loads a full dropdown
 * — it queries school/search/students (capped at 20 server-side) as the user
 * types, debounced so a fast typist sends one request rather than one per key.
 *
 * When editing an existing record the chosen student may not be in the current
 * result set, so `initialOption` seeds the list to stop the select rendering a
 * bare id.
 */
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import http from '../../lib/http';
import { useSchoolText } from './lang/useSchoolText';
import ES from './lang/StudentPicker.es.js';

const props = defineProps({
  modelValue: { type: [Number, String], default: undefined },
  initialOption: { type: Object, default: null },
  // Empty = the default text in the active language.
  placeholder: { type: String, default: '' },
  allowClear: { type: Boolean, default: true },
  disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue', 'select']);
const { s } = useSchoolText(ES);

const results = ref([]);
const loading = ref(false);
const term = ref('');
const loadedOnce = ref(false);
let timer = null;

const options = computed(() => {
  const list = results.value.map(st => ({
    value: st.id,
    label: st.label || `${st.name} · ${st.admission_number}`,
    student: st,
  }));

  const selected = props.initialOption;
  if (selected?.id && !list.some(o => o.value === selected.id)) {
    list.unshift({
      value: selected.id,
      label: selected.label || `${selected.name}${selected.admission_number ? ' · ' + selected.admission_number : ''}`,
      student: selected,
    });
  }

  return list;
});

async function fetchStudents(search) {
  loading.value = true;
  try {
    const data = await http.get('school/search/students', { search });
    results.value = data?.students || [];
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
  timer = setTimeout(() => fetchStudents(value), 300);
}

function ensureLoaded() {
  if (!loadedOnce.value && !loading.value) fetchStudents('');
}

function onChange(value, option) {
  emit('update:modelValue', value);
  emit('select', option?.student || null);
}

watch(() => props.modelValue, value => {
  if (value === undefined || value === null) term.value = '';
});

onBeforeUnmount(() => clearTimeout(timer));
</script>

<style scoped>
.sp-empty {
  padding: 10px 4px;
  text-align: center;
  font-size: 12.5px;
  opacity: 0.6;
}
</style>
