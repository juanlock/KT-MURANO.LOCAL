<template>
  <!-- How the Portfolio shows on the front page, per the company's front page type. -->
  <a-card size="small" :loading="loading" class="pf-front">
    <template #title>Portfolio on the front page</template>

    <template v-if="info">
      <!-- our store front page: it's a Homepage Block -->
      <template v-if="info.front_mode !== 'imported'">
        <div class="pf-help">
          The front page uses the store's sections. Turn on the <b>Portfolio</b> block in
          <b>Store Settings → Homepage Blocks</b> and move it where you want it.
        </div>
        <a-button v-if="!inSettings" type="primary" @click="$router.push('/website/homepage')">
          Open Homepage Blocks
        </a-button>
      </template>

      <!-- imported website: inserted into its front page -->
      <template v-else>
        <div class="pf-row">
          <a-switch v-model:checked="form.enabled" />
          <span><b>Show the Portfolio on the imported front page</b></span>
        </div>
        <template v-if="form.enabled">
          <a-radio-group v-model:value="form.placement" class="pf-place">
            <a-radio value="replace" :disabled="!info.site_has_section">
              Replace the website's own Portfolio section
              <span v-if="!info.site_has_section" class="pf-muted">(this website has none — it goes before the footer)</span>
            </a-radio>
            <a-radio value="before_footer">Before the footer</a-radio>
            <a-radio value="end">At the end of the page</a-radio>
          </a-radio-group>
          <a-row :gutter="12">
            <a-col :xs="24" :md="9"><a-form-item label="Title" style="margin-bottom: 8px"><a-input v-model:value="form.title" placeholder="Portfolio" /></a-form-item></a-col>
            <a-col :xs="24" :md="11"><a-form-item label="Subtitle" style="margin-bottom: 8px"><a-input v-model:value="form.subtitle" /></a-form-item></a-col>
            <a-col :xs="24" :md="4"><a-form-item label="Pictures" style="margin-bottom: 8px"><a-input-number v-model:value="form.limit" :min="1" :max="60" style="width: 100%" /></a-form-item></a-col>
          </a-row>
        </template>
        <a-space>
          <a-button type="primary" :loading="saving" @click="save">{{ $t('Save') }}</a-button>
          <a-button :href="info.front_url" target="_blank" rel="noopener">View the front page</a-button>
        </a-space>
      </template>
    </template>
  </a-card>
</template>

<script setup>
/**
 * GET store/portfolio (front_mode, section, site_has_section, front_url);
 * PUT store/portfolio/section. Used on Store -> Portfolio and in Store
 * Settings -> Homepage Blocks (imported website).
 */
import { ref, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import http from '../../lib/http';

defineProps({ inSettings: { type: Boolean, default: false } });
const emit = defineEmits(['loaded']);

const loading = ref(true);
const saving = ref(false);
const info = ref(null);
const form = ref({ enabled: false, placement: 'replace', title: 'Portfolio', subtitle: '', limit: 24 });

async function load() {
  loading.value = true;
  try {
    info.value = await http.get('store/portfolio');
    form.value = { ...form.value, ...(info.value.section || {}) };
    if (form.value.placement === 'replace' && !info.value.site_has_section) form.value.placement = 'before_footer';
    emit('loaded', info.value);
  } finally {
    loading.value = false;
  }
}
async function save() {
  saving.value = true;
  try {
    form.value = { ...form.value, ...(await http.put('store/portfolio/section', form.value)) };
    message.success(form.value.enabled ? 'The Portfolio is now on the front page.' : 'The Portfolio is no longer on the front page.');
  } catch (e) {
    message.error(e?.data?.message || 'Could not save.');
  } finally {
    saving.value = false;
  }
}
defineExpose({ load });
onMounted(load);
</script>

<style scoped>
.pf-help { margin-bottom: 10px; }
.pf-row { display: flex; gap: 10px; align-items: center; margin-bottom: 10px; }
.pf-place { display: flex; flex-direction: column; gap: 6px; margin: 0 0 10px; }
.pf-muted { font-size: 12px; opacity: 0.6; }
</style>
