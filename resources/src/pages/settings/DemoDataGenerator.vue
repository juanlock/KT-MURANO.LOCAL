<template>
  <div>
    <div v-if="loading" style="display: flex; justify-content: center; padding: 48px 0">
      <a-spin size="large" />
    </div>

    <template v-else>
      <a-alert
        type="info"
        show-icon
        style="margin-bottom: 16px"
        :message="`Example data for ${companyName || 'this company'}`"
      >
        <template #description>
          One set of realistic examples per module — core (products, customers, suppliers, sales,
          purchases, quotations, expenses) plus every business module switched on under Modules.
          A module is <strong>skipped</strong> when this company already has real data in it, or when its
          examples were already generated. Everything generated is tagged per company and can be removed
          in one click; real data is never touched.
        </template>
      </a-alert>

      <a-card size="small">
        <template #title>
          Modules
          <a-tag v-if="demoTotal" color="purple" style="margin-inline-start: 8px">{{ demoTotal }} demo records</a-tag>
        </template>
        <template #extra>
          <a-space>
            <a-button :disabled="!availableKeys.length" size="small" @click="selectAvailable">Select available</a-button>
            <a-button size="small" @click="selected = []">Clear</a-button>
          </a-space>
        </template>

        <a-table
          :columns="columns"
          :data-source="modules"
          :pagination="false"
          row-key="key"
          size="small"
          :row-selection="{
            selectedRowKeys: selected,
            onChange: keys => (selected = keys),
            getCheckboxProps: r => ({ disabled: !r.available }),
          }"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'status'">
              <a-tag v-if="record.available" color="green">Ready</a-tag>
              <a-tag v-else-if="record.demo_count" color="purple">Examples generated</a-tag>
              <a-tag v-else-if="!record.enabled" color="default">Module off</a-tag>
              <a-tag v-else-if="record.has_real_data" color="blue">Has real data — skipped</a-tag>
              <a-tag v-else color="orange">{{ record.reason }}</a-tag>
            </template>
            <template v-else-if="column.key === 'demo_count'">
              {{ record.demo_count || '—' }}
            </template>
            <template v-else-if="column.key === 'actions'">
              <a-button
                v-if="record.demo_count" size="small" danger type="link"
                :loading="resetting === record.key" @click="onReset(record)"
              >Remove</a-button>
            </template>
          </template>
        </a-table>

        <a-space style="margin-top: 16px">
          <a-button type="primary" :loading="generating" :disabled="!selected.length" @click="generate">
            <template #icon><ThunderboltOutlined /></template>
            Generate examples ({{ selected.length }})
          </a-button>
          <a-button danger :disabled="!demoTotal" :loading="resetting === 'all'" @click="onReset(null)">
            <template #icon><DeleteOutlined /></template>
            Remove all demo data
          </a-button>
        </a-space>
      </a-card>

      <a-card v-if="lastResults.length" size="small" title="Last run" style="margin-top: 16px">
        <div v-for="r in lastResults" :key="r.key" class="ddg-result">
          <strong>{{ r.label }}:</strong>
          <span v-if="r.status === 'created'"> {{ summary(r.created) || 'nothing created' }}</span>
          <span v-else-if="r.status === 'skipped'" class="ddg-muted"> skipped — {{ r.reason }}</span>
          <span v-else class="ddg-error"> failed — {{ r.reason }}</span>
        </div>
      </a-card>
    </template>
  </div>
</template>

<script setup>
/**
 * Demo Data generator — the "demo" section of System Settings, per company
 * (the acting company; a SuperAdmin picks it with the header switcher).
 *
 * GET    demo_data/status    → { company_name, demo_total, modules: [{key, label, enabled,
 *                                has_real_data, demo_count, available, reason}] }
 * POST   demo_data/generate  { modules: [key] } → { results: {key: {status, created|reason}} }
 * DELETE demo_data[?module=] → removes only this company's tagged demo rows
 */
import { ref, computed, onMounted, createVNode } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { ThunderboltOutlined, DeleteOutlined, ExclamationCircleOutlined } from '@ant-design/icons-vue';
import http from '../../lib/http';

const loading = ref(true);
const generating = ref(false);
const resetting = ref(null);
const modules = ref([]);
const companyName = ref('');
const demoTotal = ref(0);
const selected = ref([]);
const lastResults = ref([]);

const columns = [
  { title: 'Module', dataIndex: 'label', key: 'label' },
  { title: 'Status', key: 'status', width: 220 },
  { title: 'Demo rows', key: 'demo_count', width: 110, align: 'right' },
  { title: '', key: 'actions', width: 90, align: 'right' },
];

const availableKeys = computed(() => modules.value.filter(m => m.available).map(m => m.key));

function selectAvailable() {
  selected.value = [...availableKeys.value];
}

function summary(map) {
  return Object.entries(map || {})
    .filter(([, n]) => Number(n) > 0)
    .map(([label, n]) => `${n} ${label.toLowerCase()}`)
    .join(', ');
}

async function loadStatus() {
  try {
    const data = await http.get('demo_data/status');
    modules.value = data.modules || [];
    companyName.value = data.company_name || '';
    demoTotal.value = Number(data.demo_total) || 0;
    selected.value = selected.value.filter(k => availableKeys.value.includes(k));
  } catch (e) {
    message.error(e?.data?.message || e?.message || 'Could not load demo data status');
  }
}

async function generate() {
  generating.value = true;
  try {
    const data = await http.post('demo_data/generate', { modules: selected.value });
    const byKey = Object.fromEntries(modules.value.map(m => [m.key, m.label]));
    lastResults.value = Object.entries(data.results || {}).map(([key, r]) => ({ key, label: byKey[key] || key, ...r }));
    const ok = lastResults.value.filter(r => r.status === 'created').length;
    const failed = lastResults.value.filter(r => r.status === 'error').length;
    if (failed) message.warning(`${ok} module(s) generated, ${failed} failed — see "Last run".`, 6);
    else message.success(`${ok} module(s) generated.`, 5);
    selected.value = [];
    await loadStatus();
  } catch (e) {
    message.error(e?.data?.message || e?.message || 'Generation failed');
  } finally {
    generating.value = false;
  }
}

function onReset(record) {
  Modal.confirm({
    title: record ? `Remove ${record.label} demo data?` : 'Remove all demo data?',
    icon: createVNode(ExclamationCircleOutlined),
    content: record
      ? `Deletes the ${record.demo_count} demo rows generated for this module (this company only). Real data is not affected.`
      : `Deletes all ${demoTotal.value} demo rows of ${companyName.value || 'this company'} (line items, payments and stock included). Real data is not affected.`,
    okText: 'Remove',
    okType: 'danger',
    async onOk() {
      resetting.value = record ? record.key : 'all';
      try {
        const data = await http.delete('demo_data', record ? { module: record.key } : undefined);
        message.success(`Removed ${data.deleted ?? 0} demo rows.`, 5);
        await loadStatus();
      } catch (e) {
        message.error(e?.data?.message || e?.message || 'Reset failed');
      } finally {
        resetting.value = null;
      }
    },
  });
}

onMounted(async () => {
  await loadStatus();
  loading.value = false;
});
</script>

<style scoped>
.ddg-result { padding: 2px 0; }
.ddg-muted { opacity: 0.6; }
.ddg-error { color: #ff4d4f; }
</style>
