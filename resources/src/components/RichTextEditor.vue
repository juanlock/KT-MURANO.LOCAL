<template>
  <div class="rte-wrapper">
    <div ref="editorEl"></div>
  </div>
</template>

<script setup>
/**
 * Quill 2 wrapper (v-model), same toolbar as the legacy RichTextEditor
 * component. quill is a vue3-app dependency (npm install after pulling).
 */
import { ref, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import Quill from 'quill';
import 'quill/dist/quill.snow.css';

const props = defineProps({
  modelValue: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);

const editorEl = ref(null);
let quill = null;
let isUpdating = false;

const TOOLBAR = [
  [{ header: [1, 2, 3, 4, 5, 6, false] }],
  ['bold', 'italic', 'underline', 'strike'],
  [{ list: 'ordered' }, { list: 'bullet' }],
  [{ color: [] }, { background: [] }],
  [{ align: [] }],
  ['link', 'image'],
  ['clean'],
];

onMounted(() => {
  quill = new Quill(editorEl.value, {
    theme: 'snow',
    modules: { toolbar: TOOLBAR },
  });
  if (props.modelValue) quill.root.innerHTML = props.modelValue;
  quill.on('text-change', () => {
    isUpdating = true;
    emit('update:modelValue', quill.root.innerHTML);
    nextTick(() => { isUpdating = false; });
  });
});
onBeforeUnmount(() => { quill = null; });

watch(() => props.modelValue, v => {
  if (quill && !isUpdating && quill.root.innerHTML !== v) {
    quill.root.innerHTML = v || '';
  }
});

/** Insert plain text (e.g. a {merge_token}) at the caret, or append if unfocused. */
function insertText(text) {
  if (!quill) return;
  const range = quill.getSelection(true);
  const index = range ? range.index : quill.getLength();
  quill.insertText(index, text, 'user');
  quill.setSelection(index + text.length, 0);
}
function focus() { quill?.focus(); }

defineExpose({ insertText, focus });
</script>

<style scoped>
.rte-wrapper :deep(.ql-container) {
  min-height: 180px;
}
</style>
