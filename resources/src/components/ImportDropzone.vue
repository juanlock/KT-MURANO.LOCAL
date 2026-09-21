<template>
  <a-upload-dragger
    :file-list="fileListDisplay"
    :before-upload="onBeforeUpload"
    :accept="accept"
    :disabled="disabled"
    :max-count="1"
    @remove="onRemove"
  >
    <p class="ant-upload-drag-icon"><InboxOutlined /></p>
    <p class="ant-upload-text">{{ title }}</p>
    <p class="ant-upload-hint">{{ hint }}</p>
  </a-upload-dragger>
</template>

<script setup>
/**
 * Ant-native dropzone shell for the import wizards (a-upload-dragger).
 * Emits the RAW File — each page keeps its own legacy-verbatim validation
 * (size clamp, extension whitelist, messages), per the validation-parity
 * rule. The selected file renders as the dragger's own file-list item.
 */
import { computed } from 'vue';
import { InboxOutlined } from '@ant-design/icons-vue';

const props = defineProps({
  file: { type: Object, default: null },
  accept: { type: String, default: '' },
  title: { type: String, default: '' },
  hint: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['file', 'clear']);

const fileListDisplay = computed(() => (props.file
  ? [{
    uid: '-1',
    name: props.file.name + ' (' + prettySize(props.file.size) + ')',
    status: 'done',
  }]
  : []));

function prettySize(bytes) {
  if (!bytes || bytes <= 0) return '0 B';
  const k = 1024; const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return (bytes / Math.pow(k, i)).toFixed(2) + ' ' + sizes[i];
}

function onBeforeUpload(file) {
  emit('file', file);
  return false; // never auto-upload — pages submit explicitly
}

function onRemove() {
  emit('clear');
  return false;
}

// Kept for API compatibility with the pages' clearFile(resetInput) calls;
// a-upload-dragger has no persistent input state to reset.
defineExpose({ resetInput() {} });
</script>
