<template>
  <a-modal
    :open="open"
    :title="isEdit ? 'Edit folder' : 'New folder'"
    :confirm-loading="saving"
    :ok-text="$t('submit')"
    :cancel-text="$t('Cancel')"
    @ok="submit"
    @cancel="$emit('close')"
  >
    <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
      <a-form-item label="Folder name" name="name">
        <a-input v-model:value="form.name" placeholder="e.g. Supplier contracts" allow-clear />
      </a-form-item>

      <a-form-item label="Parent folder">
        <a-tree-select
          v-model:value="form.parent_id"
          :tree-data="parentOptions"
          placeholder="No parent — top level"
          allow-clear
          tree-default-expand-all
          :field-names="{ label: 'title', value: 'value', children: 'children' }"
        />
      </a-form-item>

      <a-form-item label="Colour">
        <div class="swatches">
          <button
            v-for="c in COLORS" :key="c" type="button" class="swatch"
            :class="{ active: form.color === c }" :style="{ background: c }"
            :aria-label="c" @click="form.color = form.color === c ? null : c"
          />
        </div>
      </a-form-item>

      <a-form-item :label="$t('Description')" style="margin-bottom: 0">
        <a-textarea v-model:value="form.description" :rows="2" allow-clear />
      </a-form-item>
    </a-form>
  </a-modal>
</template>

<script setup>
/**
 * Create / rename a folder. The parent picker excludes the folder itself and
 * everything under it — the same rule the backend enforces in updateFolder(),
 * checked here too so the user never gets a 422 for something the UI offered.
 */
import { ref, computed, watch } from 'vue';
import { message } from 'ant-design-vue';
import http from '../../lib/http';
import { t } from '../../i18n';

const props = defineProps({
  open: { type: Boolean, default: false },
  folder: { type: Object, default: null },
  folders: { type: Array, default: () => [] },
  // Preselected parent when the user hits "+" on a folder row.
  defaultParentId: { type: [Number, String], default: null },
});
const emit = defineEmits(['close', 'saved']);

const COLORS = ['#6d28d9', '#2563eb', '#0891b2', '#16a34a', '#ca8a04', '#ea580c', '#dc2626', '#db2777'];

const formRef = ref();
const saving = ref(false);
const form = ref({ name: '', parent_id: null, color: null, description: '' });

const isEdit = computed(() => !!props.folder?.id);

const rules = computed(() => ({
  name: [{ required: true, message: t('Field_is_required', 'This field is required') }],
}));

/** Ids that may not become the parent: self + descendants. */
const blockedIds = computed(() => {
  if (!isEdit.value) return [];
  const blocked = [props.folder.id];
  let added = true;
  while (added) {
    added = false;
    props.folders.forEach(f => {
      if (blocked.includes(f.parent_id) && !blocked.includes(f.id)) {
        blocked.push(f.id);
        added = true;
      }
    });
  }
  return blocked;
});

const parentOptions = computed(() => {
  const build = parentId => props.folders
    .filter(f => (f.parent_id || null) === parentId && !blockedIds.value.includes(f.id))
    .map(f => ({ title: f.name, value: f.id, children: build(f.id) }));
  return build(null);
});

watch(() => props.open, isOpen => {
  if (!isOpen) return;
  form.value = props.folder
    ? {
        name: props.folder.name,
        parent_id: props.folder.parent_id || null,
        color: props.folder.color || null,
        description: props.folder.description || '',
      }
    : { name: '', parent_id: props.defaultParentId || null, color: null, description: '' };
  formRef.value?.clearValidate?.();
});

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }

  saving.value = true;
  try {
    const payload = { ...form.value, parent_id: form.value.parent_id || null };
    if (isEdit.value) await http.put(`documents/folders/${props.folder.id}`, payload);
    else await http.post('documents/folders', payload);
    message.success(t('Created_in_successfully', 'Saved successfully'));
    emit('saved');
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData', 'Could not save the folder'));
  } finally {
    saving.value = false;
  }
}
</script>

<style scoped>
.swatches {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.swatch {
  width: 26px;
  height: 26px;
  border-radius: 8px;
  border: 2px solid transparent;
  cursor: pointer;
  padding: 0;
  transition: transform 0.12s ease, box-shadow 0.12s ease;
}
.swatch:hover {
  transform: scale(1.08);
}
.swatch.active {
  border-color: #fff;
  box-shadow: 0 0 0 2px rgba(109, 40, 217, 0.9);
}
</style>
