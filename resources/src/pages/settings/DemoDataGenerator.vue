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
        :message="tx('Example data for {name}', { name: companyName || tx('this company') })"
      >
        <template #description>
          {{ tx(INTRO_1) }} <strong>{{ tx('skipped') }}</strong> {{ tx(INTRO_2) }}
        </template>
      </a-alert>

      <!-- What the company does: accounting / expenses examples (and their
           categories + accounts) follow it. Saved on the company. -->
      <a-card size="small" style="margin-bottom: 16px" :class="{ 'ddg-type--need': needsType }">
        <div class="ddg-type">
          <div>
            <div class="ddg-type__title">{{ tx('What kind of business is {name}?', { name: companyName || tx('this company') }) }}</div>
            <div class="ddg-muted">
              {{ tx(TYPE_HELP) }} <b>{{ tx('Choosing another type replaces those examples') }}</b> {{ tx('— the old ones are removed first.') }}
            </div>
          </div>
          <a-select v-model:value="businessType" :options="businessTypeOptions" style="width: 320px" :placeholder="tx('Choose the type of business')" />
        </div>
      </a-card>

      <!-- HRM / Payroll history: how the staff are paid. -->
      <a-card v-if="needsPay" size="small" style="margin-bottom: 16px">
        <div class="ddg-type">
          <div>
            <div class="ddg-type__title">{{ tx('How are the staff paid?') }}</div>
            <div class="ddg-muted">
              {{ tx(PAY_HELP) }}
            </div>
          </div>
          <a-radio-group v-model:value="payType" button-style="solid">
            <a-radio-button value="hourly">{{ tx('Hours') }}</a-radio-button>
            <a-radio-button value="commission">{{ tx('Commission') }}</a-radio-button>
          </a-radio-group>
        </div>
      </a-card>

      <a-card size="small">
        <template #title>
          {{ tx('Modules') }}
          <a-tag v-if="demoTotal" color="purple" style="margin-inline-start: 8px">{{ tx('{n} demo records', { n: demoTotal }) }}</a-tag>
        </template>
        <template #extra>
          <a-space>
            <a-button :disabled="!availableKeys.length" size="small" @click="selectAvailable">{{ tx('Select available') }}</a-button>
            <a-button size="small" @click="selected = []">{{ tx('Clear') }}</a-button>
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
            getCheckboxProps: r => ({ disabled: !r.available && !willReplace(r) }),
          }"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'status'">
              <a-tooltip v-if="willReplace(record)" :title="tx('Its examples follow the business type: they are removed and created again for {type}.', { type: typeLabel(businessType) })">
                <a-tag color="orange">{{ tx('Will be replaced') }}</a-tag>
              </a-tooltip>
              <a-tag v-else-if="record.available" color="green">{{ tx('Ready') }}</a-tag>
              <a-tag v-else-if="record.demo_count" color="purple">{{ tx('Examples generated') }}</a-tag>
              <a-tag v-else-if="!record.enabled" color="default">{{ tx('Module off') }}</a-tag>
              <a-tag v-else-if="record.has_real_data" color="blue">{{ tx('Has real data — skipped') }}</a-tag>
              <a-tag v-else color="orange">{{ record.reason }}</a-tag>
            </template>
            <template v-else-if="column.key === 'demo_count'">
              {{ record.demo_count || '—' }}
            </template>
            <template v-else-if="column.key === 'actions'">
              <a-button
                v-if="record.demo_count" size="small" danger type="link"
                :loading="resetting === record.key" @click="onReset(record)"
              >{{ tx('Remove') }}</a-button>
            </template>
          </template>
        </a-table>

        <a-space style="margin-top: 16px">
          <a-button type="primary" :loading="generating" :disabled="!selected.length" @click="generate">
            <template #icon><ThunderboltOutlined /></template>
            {{ tx('Generate examples ({n})', { n: selected.length }) }}
          </a-button>
          <a-button danger :disabled="!demoTotal" :loading="resetting === 'all'" @click="onReset(null)">
            <template #icon><DeleteOutlined /></template>
            {{ tx('Remove all demo data') }}
          </a-button>
        </a-space>
      </a-card>

      <a-card v-if="lastResults.length" size="small" :title="tx('Last run')" style="margin-top: 16px">
        <div v-for="r in lastResults" :key="r.key" class="ddg-result">
          <strong>{{ r.label }}:</strong>
          <span v-if="r.status === 'created'"> {{ summary(r.created) || tx('nothing created') }}</span>
          <span v-else-if="r.status === 'skipped'" class="ddg-muted"> {{ tx('skipped — {reason}', { reason: r.reason }) }}</span>
          <span v-else class="ddg-error"> {{ tx('failed — {reason}', { reason: r.reason }) }}</span>
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
import { ref, computed, watch, onMounted, createVNode } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { ThunderboltOutlined, DeleteOutlined, ExclamationCircleOutlined } from '@ant-design/icons-vue';
import http from '../../lib/http';
import { sortByLabel } from '../../lib/filterOptions';
import { useLocalText } from '../../lib/useLocalText';
import ES from './lang/DemoDataGenerator.es.js';

const { s: tx } = useLocalText(ES);

// Long help texts (English = key of the Spanish map).
const INTRO_1 = 'One set of realistic examples per module — core (products, customers, suppliers, sales, purchases, quotations, expenses) plus every business module switched on under Modules. A module is';
const INTRO_2 = 'when this company already has real data in it, or when its examples were already generated. Everything generated is tagged per company and can be removed in one click; real data is never touched.';
const TYPE_HELP = 'Accounting, Regular expenses, Assets, Portfolio and Website examples use it: a landscaper gets fuel, mulch and subcontractor bills, mowers and trucks as assets, lawn and garden photos and pages; a salon, hair color, laundry, styling chairs and salon photos and pages; an IT consultant, laptops, servers, network gear and IT pages; a restaurant, food orders and kitchen equipment. Its own expense categories and accounts are added too (they stay after removing demo data).';
const PAY_HELP = "Hours: weekly payroll = hours worked (on the service jobs they complete) × hourly rate. Commission: weekly payroll = a % of their sales. Demo employees and their weekly payrolls follow it; Payroll history uses each real employee's own setup (Employee → Company → Paid by).";

const loading = ref(true);
const generating = ref(false);
const resetting = ref(null);
const modules = ref([]);
const companyName = ref('');
const demoTotal = ref(0);
const selected = ref([]);
const lastResults = ref([]);

const columns = computed(() => [
  { title: tx('Module'), dataIndex: 'label', key: 'label' },
  { title: tx('Status'), key: 'status', width: 220 },
  { title: tx('Demo rows'), key: 'demo_count', width: 110, align: 'right' },
  { title: '', key: 'actions', width: 90, align: 'right' },
]);

const availableKeys = computed(() => modules.value.filter(m => m.available).map(m => m.key));

// Business type (companies.business_type): asked before generating accounting / expenses.
const businessType = ref(undefined);
const businessTypes = ref([]);
const businessTypeOptions = computed(() => sortByLabel(businessTypes.value));
// Modules whose examples follow the business type (from the server: type_modules).
const TYPE_MODULES = ref(['accounting', 'regular_expenses', 'assets', 'portfolio', 'website']);
const needsType = computed(() => selected.value.some(k => TYPE_MODULES.value.includes(k)));
// The type saved on the company (what the existing examples were made for).
const savedType = ref(undefined);
const typeChanged = computed(() => !!businessType.value && !!savedType.value && businessType.value !== savedType.value);
// Another type chosen: the examples made for the old one are removed and generated again.
const willReplace = r => typeChanged.value && TYPE_MODULES.value.includes(r.key) && Number(r.demo_count) > 0;
const typeLabel = v => (businessTypes.value.find(t => t.value === v) || {}).label || v;
watch(typeChanged, changed => {
  const keys = modules.value.filter(willReplace).map(m => m.key);
  selected.value = changed
    ? [...new Set([...selected.value, ...keys])]
    : selected.value.filter(k => availableKeys.value.includes(k));
});
// Hours or commission (HRM demo staff / payroll history); default from the business type.
const needsPay = computed(() => selected.value.some(k => ['hrm', 'payroll_history'].includes(k)));
const payType = ref(null);
watch(businessType, bt => { if (!payType.value) payType.value = ['salon', 'barber'].includes(bt) ? 'commission' : 'hourly'; }, { immediate: true });

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
    // Shown alphabetically by label (the server's order is the generation
    // order -- Core first -- which still applies when generating).
    modules.value = sortByLabel(data.modules || []);
    companyName.value = data.company_name || '';
    demoTotal.value = Number(data.demo_total) || 0;
    businessTypes.value = data.business_types || [];
    businessType.value = data.business_type || undefined;
    savedType.value = data.business_type || undefined;
    if (Array.isArray(data.type_modules)) TYPE_MODULES.value = data.type_modules;
    selected.value = selected.value.filter(k => availableKeys.value.includes(k));
  } catch (e) {
    message.error(e?.data?.message || e?.message || tx('Could not load demo data status'));
  }
}

async function generate() {
  if (needsType.value && !businessType.value) {
    message.warning(tx('Choose what kind of business this is first — Accounting, Regular expenses, Assets, Portfolio and Website examples follow it.'));
    return;
  }
  const replacing = modules.value.filter(willReplace);
  if (replacing.length) {
    const ok = await new Promise(resolve => Modal.confirm({
      title: tx('Change the examples to {type}?', { type: typeLabel(businessType.value) }),
      icon: createVNode(ExclamationCircleOutlined),
      content: tx(
        'These demo examples were made for {type}: {modules}. They will be removed and created again for the new business type. Real data is never touched.',
        { type: typeLabel(savedType.value), modules: replacing.map(m => m.label).join(', ') },
      ),
      okText: tx('Replace and generate'), cancelText: tx('Cancel'),
      onOk: () => resolve(true), onCancel: () => resolve(false),
    }));
    if (!ok) return;
  }
  generating.value = true;
  try {
    const data = await http.post('demo_data/generate', {
      modules: selected.value, business_type: businessType.value || null,
      ...(needsPay.value ? { pay_type: payType.value || 'hourly' } : {}),
    });
    const byKey = Object.fromEntries(modules.value.map(m => [m.key, m.label]));
    lastResults.value = sortByLabel(Object.entries(data.results || {}).map(([key, r]) => ({ key, label: byKey[key] || key, ...r })));
    const ok = lastResults.value.filter(r => r.status === 'created').length;
    const failed = lastResults.value.filter(r => r.status === 'error').length;
    const replaced = (data.replaced || []).length;
    if (failed) message.warning(tx('{ok} module(s) generated, {failed} failed — see "Last run".', { ok, failed }), 6);
    else if (replaced) message.success(tx('{ok} module(s) generated ({n} replaced for the new business type).', { ok, n: replaced }), 5);
    else message.success(tx('{ok} module(s) generated.', { ok }), 5);
    selected.value = [];
    await loadStatus();
  } catch (e) {
    message.error(e?.data?.message || e?.message || tx('Generation failed'));
  } finally {
    generating.value = false;
  }
}

function onReset(record) {
  Modal.confirm({
    title: record ? tx('Remove {label} demo data?', { label: record.label }) : tx('Remove all demo data?'),
    icon: createVNode(ExclamationCircleOutlined),
    content: record
      ? tx('Deletes the {n} demo rows generated for this module (this company only). Real data is not affected.', { n: record.demo_count })
      : tx('Deletes all {n} demo rows of {name} (line items, payments and stock included). Real data is not affected.', { n: demoTotal.value, name: companyName.value || tx('this company') }),
    okText: tx('Remove'),
    cancelText: tx('Cancel'),
    okType: 'danger',
    async onOk() {
      resetting.value = record ? record.key : 'all';
      try {
        const data = await http.delete('demo_data', record ? { module: record.key } : undefined);
        message.success(tx('Removed {n} demo rows.', { n: data.deleted ?? 0 }), 5);
        await loadStatus();
      } catch (e) {
        message.error(e?.data?.message || e?.message || tx('Reset failed'));
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
.ddg-type { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
.ddg-type__title { font-weight: 600; margin-bottom: 2px; }
/* Accounting / Regular expenses selected: the business type matters -- highlight it. */
.ddg-type--need { border-color: #b37feb; box-shadow: 0 0 0 2px rgba(146, 84, 222, 0.12); }
.ddg-error { color: #ff4d4f; }
</style>
