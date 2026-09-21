<template>
  <div class="page">
    <PageHeader :title="$t('Modules')" :breadcrumb="[$t('Settings'), $t('Modules')]" />
    <a-tabs v-model:activeKey="activeTab">
      <a-tab-pane key="modules" :tab="$t('Modules')">
        <ModuleToggles />
      </a-tab-pane>
      <a-tab-pane key="features" :tab="$t('Features')">
        <FeatureToggles />
      </a-tab-pane>
    </a-tabs>
  </div>
</template>

<script setup>
/**
 * Standalone sidebar page for module toggles — same component the "Modules"
 * section of System Settings embeds, so both surfaces stay in sync. The
 * Features tab mirrors System Settings → Features via FeatureToggles, which
 * saves through the dedicated feature_settings endpoint.
 */
import { ref } from 'vue';
import PageHeader from '../../components/PageHeader.vue';
import ModuleToggles from './ModuleToggles.vue';
import FeatureToggles from './FeatureToggles.vue';

const activeTab = ref('modules');
</script>
