<template>
  <a-card :loading="loading">
    <a-alert
      type="info"
      show-icon
      message="Turn business modules on or off"
      description="A disabled module disappears from the sidebar and its pages become unreachable for every user. No data is deleted — switching a module back on restores it exactly as it was."
      style="margin-bottom: 16px"
    />

    <div class="mt-toolbar">
      <a-space>
        <a-button size="small" @click="setAll(true)">Enable all</a-button>
        <a-button size="small" @click="setAll(false)">Disable all</a-button>
      </a-space>
      <a-space>
        <a-button danger :loading="resetting" @click="confirmReset">
          <template #icon><UndoOutlined /></template>
          {{ $t('Reset_to_Default') }}
        </a-button>
        <a-button type="primary" :disabled="!dirty" :loading="saving" @click="save">
          <template #icon><SaveOutlined /></template>
          {{ $t('submit') }}
        </a-button>
      </a-space>
    </div>

    <div class="mt-list">
      <div v-for="mod in modules" :key="mod.key" class="mt-row" :class="{ 'mt-off': !flags[mod.key] }">
        <span class="mt-icon">
          <component :is="mod.iconCmp" v-if="mod.iconCmp" :size="18" />
          <AppstoreOutlined v-else />
        </span>
        <div class="mt-text">
          <div class="mt-label">{{ mod.label }}</div>
          <div class="mt-desc">{{ mod.description }}</div>
        </div>
        <a-switch v-model:checked="flags[mod.key]" @change="dirty = true" />
      </div>
    </div>
  </a-card>
</template>

<script setup>
/**
 * Module toggles — the "modules" section of System Settings.
 *
 * One switch per optional business module (Hospital, Fleet, Projects, HRM…).
 * Persists a {key: bool} map via PUT/DELETE module_flags; keys are the
 * top-level menu.js ids catalogued in config/modules.js. After a save the map
 * is written back onto auth.user.module_flags so the sidebar and router react
 * immediately without a reload. Missing key / null map = module enabled.
 */
import { ref, reactive, onMounted } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { SaveOutlined, UndoOutlined, AppstoreOutlined } from '@ant-design/icons-vue';
import { TOGGLEABLE_MODULES } from '../../config/modules';
import { MENU } from '../../config/menu';
import { MENU_ICONS } from '../../config/menuIcons';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';

const { t } = useI18n();
const auth = useAuthStore();

// Reuse each module's sidebar icon so the list reads like the navigation.
const iconByKey = Object.fromEntries(MENU.map(e => [e.key, MENU_ICONS[e.icon]]));
const modules = TOGGLEABLE_MODULES.map(m => ({ ...m, iconCmp: iconByKey[m.key] }));

const loading = ref(true);
const saving = ref(false);
const resetting = ref(false);
const dirty = ref(false);
const flags = reactive({});

function applyFlags(map) {
  for (const m of TOGGLEABLE_MODULES) {
    flags[m.key] = !map || map[m.key] !== false;
  }
}

function setAll(value) {
  for (const m of TOGGLEABLE_MODULES) flags[m.key] = value;
  dirty.value = true;
}

async function save() {
  saving.value = true;
  try {
    const payload = {};
    for (const m of TOGGLEABLE_MODULES) payload[m.key] = !!flags[m.key];
    await http.put('module_flags', { flags: payload });
    // Live-update this session; other users get the change at next boot.
    if (auth.user) auth.user.module_flags = payload;
    dirty.value = false;
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

function confirmReset() {
  Modal.confirm({
    title: t('Reset_to_Default'),
    content: 'Re-enable every module for all users?',
    okText: t('Reset_to_Default'),
    okType: 'danger',
    onOk: reset,
  });
}

async function reset() {
  resetting.value = true;
  try {
    await http.delete('module_flags');
    if (auth.user) auth.user.module_flags = null;
    applyFlags(null);
    dirty.value = false;
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.message || t('InvalidData'));
  } finally {
    resetting.value = false;
  }
}

onMounted(async () => {
  try {
    const data = await http.get('module_flags');
    applyFlags(data?.module_flags || null);
  } catch (e) {
    applyFlags(null);
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.mt-toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 12px;
}
.mt-list {
  display: flex;
  flex-direction: column;
}
.mt-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 4px;
  border-bottom: 1px solid rgba(128, 128, 128, 0.14);
}
.mt-row:last-child {
  border-bottom: none;
}
.mt-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border-radius: 8px;
  background: rgba(128, 128, 128, 0.1);
  flex: none;
}
.mt-text {
  flex: 1;
  min-width: 0;
}
.mt-label {
  font-weight: 600;
}
.mt-desc {
  color: #8c8c8c;
  font-size: 12.5px;
}
.mt-off .mt-label,
.mt-off .mt-icon {
  opacity: 0.55;
}
</style>
