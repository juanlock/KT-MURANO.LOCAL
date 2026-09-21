<template>
  <div>
    <div v-if="loading" style="display: flex; justify-content: center; padding: 48px 0">
      <a-spin size="large" />
    </div>

    <template v-else>
      <!-- Real-data warning, as requested: generating is still allowed, but the
           admin is told demo rows will mix with real ones and how reset behaves. -->
      <a-alert
        v-if="hasRealData"
        type="warning"
        show-icon
        message="Your database contains real data"
        style="margin-bottom: 16px"
      >
        <template #description>
          {{ realSummary }} already exist. Demo records will be mixed in with them,
          but every generated record is tagged — <strong>Reset demo data</strong> removes
          only the demo records and never touches your real data.
        </template>
      </a-alert>
      <a-alert
        v-else
        type="info"
        show-icon
        message="Fill the app with realistic sample data"
        description="Products, customers, suppliers, sales, purchases, quotations and expenses spread over the last 90 days — ideal for exploring dashboards and reports. Everything generated is tagged and can be removed in one click."
        style="margin-bottom: 16px"
      />

      <!-- ============================ Generate ============================ -->
      <a-card title="Generate demo data" size="small" style="margin-bottom: 16px">
        <a-form layout="vertical">
          <a-row :gutter="16">
            <a-col v-for="f in fields" :key="f.key" :xs="12" :md="8" :lg="6">
              <a-form-item :label="f.label">
                <a-input-number v-model:value="counts[f.key]" :min="0" :max="300" style="width: 100%" />
              </a-form-item>
            </a-col>
          </a-row>
        </a-form>
        <div class="ddg-hint">
          Sales, purchases and quotations only use demo products, customers and suppliers —
          a small pool is created automatically if you request documents without them.
        </div>
        <a-button type="primary" :loading="generating" :disabled="totalRequested === 0" @click="onGenerate">
          <template #icon><ThunderboltOutlined /></template>
          Generate demo data
        </a-button>
      </a-card>

      <!-- ============================== Reset ============================== -->
      <a-card size="small">
        <template #title>
          Current demo data
          <a-tag v-if="totalDemo" color="purple" style="margin-inline-start: 8px">{{ totalDemo }} records</a-tag>
        </template>
        <template v-if="totalDemo">
          <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
            <a-col v-for="f in fields" :key="f.key" :xs="12" :md="8" :lg="6">
              <a-statistic :title="f.label" :value="demo[f.key] || 0" />
            </a-col>
          </a-row>
          <a-button danger :loading="resetting" @click="onReset">
            <template #icon><DeleteOutlined /></template>
            Reset demo data
          </a-button>
        </template>
        <a-empty v-else description="No demo data in the database" />
      </a-card>
    </template>
  </div>
</template>

<script setup>
/**
 * Demo Data generator — the "demo" section of System Settings.
 *
 * GET  demo_data/status    → { demo: {entity: n}, real: {entity: n} }
 * POST demo_data/generate  → { created: {entity: n} } (counts capped at 300 each)
 * DELETE demo_data         → { deleted: {entity: n} } (removes ONLY tagged rows)
 *
 * Documents (sales/purchases/quotations) reference demo entities exclusively,
 * so the backend may create a few more products/customers/suppliers than
 * requested — the created summary reflects what actually happened.
 */
import { ref, reactive, computed, onMounted, createVNode } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { ThunderboltOutlined, DeleteOutlined, ExclamationCircleOutlined } from '@ant-design/icons-vue';
import http from '../../lib/http';

const fields = [
  { key: 'products', label: 'Products' },
  { key: 'clients', label: 'Customers' },
  { key: 'providers', label: 'Suppliers' },
  { key: 'sales', label: 'Sales' },
  { key: 'purchases', label: 'Purchases' },
  { key: 'quotations', label: 'Quotations' },
  { key: 'expenses', label: 'Expenses' },
];

const loading = ref(true);
const generating = ref(false);
const resetting = ref(false);
const demo = ref({});
const real = ref({});

const counts = reactive({
  products: 20, clients: 10, providers: 10,
  sales: 50, purchases: 25, quotations: 10, expenses: 15,
});

const totalRequested = computed(() => fields.reduce((n, f) => n + (Number(counts[f.key]) || 0), 0));
const totalDemo = computed(() => fields.reduce((n, f) => n + (Number(demo.value[f.key]) || 0), 0));
const hasRealData = computed(() => fields.some(f => Number(real.value[f.key]) > 0));

const realSummary = computed(() => fields
  .filter(f => Number(real.value[f.key]) > 0)
  .map(f => `${real.value[f.key]} ${f.label.toLowerCase()}`)
  .join(', '));

async function loadStatus() {
  try {
    const data = await http.get('demo_data/status');
    demo.value = data.demo || {};
    real.value = data.real || {};
  } catch (e) {
    message.error(e?.data?.message || e?.message || 'Could not load demo data status');
  }
}

function summary(map) {
  return fields
    .filter(f => Number(map?.[f.key]) > 0)
    .map(f => `${map[f.key]} ${f.label.toLowerCase()}`)
    .join(', ');
}

async function generate() {
  generating.value = true;
  try {
    const payload = {};
    fields.forEach(f => { payload[f.key] = Number(counts[f.key]) || 0; });
    const data = await http.post('demo_data/generate', payload);
    message.success(`Demo data created: ${summary(data.created) || 'nothing'}`, 6);
    await loadStatus();
  } catch (e) {
    message.error(e?.data?.message || e?.message || 'Generation failed');
  } finally {
    generating.value = false;
  }
}

function onGenerate() {
  if (!hasRealData.value) return generate();
  Modal.confirm({
    title: 'Generate demo data alongside real data?',
    icon: createVNode(ExclamationCircleOutlined),
    content: `Your database already contains ${realSummary.value}. Demo records will appear next to them in every list and report until you reset. Continue?`,
    okText: 'Generate',
    onOk: generate,
  });
}

function onReset() {
  Modal.confirm({
    title: 'Remove all demo data?',
    icon: createVNode(ExclamationCircleOutlined),
    content: `This permanently deletes the ${totalDemo.value} tagged demo records (including their line items, payments and stock). Real data is not affected.`,
    okText: 'Reset demo data',
    okType: 'danger',
    async onOk() {
      resetting.value = true;
      try {
        const data = await http.delete('demo_data');
        message.success(`Demo data removed: ${summary(data.deleted) || 'nothing to remove'}`, 6);
        await loadStatus();
      } catch (e) {
        message.error(e?.data?.message || e?.message || 'Reset failed');
      } finally {
        resetting.value = false;
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
.ddg-hint {
  font-size: 12px;
  color: #8c8c8c;
  margin: -4px 0 16px;
  max-width: 640px;
}
</style>
