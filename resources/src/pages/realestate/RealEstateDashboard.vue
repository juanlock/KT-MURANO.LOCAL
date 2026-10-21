<template>
  <!--
    Real Estate dashboard (2026-10-20): a real estate company's start page
    (BusinessTypes::HOME). GET realestate/dashboard (RealEstateController,
    acting company): listings, inquiries, by status / purpose, latest ones.
  -->
  <div class="page">
    <PageHeader :title="tf('Real_Estate_Dashboard', 'Real Estate dashboard')" :breadcrumb="[$t('Real_Estate'), tf('Dashboard', 'Dashboard')]">
      <template #actions>
        <PermLink to="/realestate/properties/create" permission="realestate_properties">
          <a-button type="primary"><PlusOutlined /> {{ tf('Add_Property', 'Add property') }}</a-button>
        </PermLink>
      </template>
    </PageHeader>

    <a-spin :spinning="loading">
      <a-row :gutter="[12, 12]">
        <a-col v-for="k in kpis" :key="k.key" :xs="12" :md="6">
          <a-card size="small" class="re-kpi">
            <a-statistic :title="k.label" :value="stats[k.key] || 0" :value-style="{ color: k.color }" />
          </a-card>
        </a-col>
      </a-row>

      <a-row :gutter="[12, 12]" style="margin-top: 12px">
        <a-col :xs="24" :md="12">
          <a-card size="small" :title="tf('By_status', 'By status')">
            <div v-if="!Object.keys(byStatus).length" class="re-empty">—</div>
            <div v-for="(n, s) in byStatus" :key="s" class="re-bar">
              <span class="re-bar-label">{{ statusLabel(s) }}</span>
              <a-progress :percent="pct(n)" :format="() => n" :stroke-color="statusColor(s)" />
            </div>
          </a-card>
        </a-col>
        <a-col :xs="24" :md="12">
          <a-card size="small" :title="tf('By_purpose', 'By purpose')">
            <div v-if="!Object.keys(byPurpose).length" class="re-empty">—</div>
            <div v-for="(n, p) in byPurpose" :key="p" class="re-bar">
              <span class="re-bar-label">{{ purposeLabel(p) }}</span>
              <a-progress :percent="pct(n)" :format="() => n" />
            </div>
          </a-card>
        </a-col>
      </a-row>

      <a-row :gutter="[12, 12]" style="margin-top: 12px">
        <a-col :xs="24" :lg="14">
          <a-card size="small" :title="tf('Latest_properties', 'Latest properties')">
            <template #extra><PermLink to="/realestate/properties" permission="realestate_properties">{{ tf('View_all', 'View all') }}</PermLink></template>
            <a-table :data-source="latestProperties" :columns="propColumns" :pagination="false" size="small" row-key="id">
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'title'">
                  <PermLink :to="`/realestate/properties/edit/${record.id}`" permission="realestate_properties">{{ record.title }}</PermLink>
                </template>
                <template v-else-if="column.key === 'category'">{{ record.category ? record.category.name : '—' }}</template>
                <template v-else-if="column.key === 'status'"><a-tag :color="statusColor(record.status)">{{ statusLabel(record.status) }}</a-tag></template>
                <template v-else-if="column.key === 'price'">{{ money(record.price) }}</template>
              </template>
            </a-table>
          </a-card>
        </a-col>
        <a-col :xs="24" :lg="10">
          <a-card size="small" :title="tf('Latest_inquiries', 'Latest inquiries')">
            <template #extra><PermLink to="/realestate/inquiries" permission="realestate_inquiries">{{ tf('View_all', 'View all') }}</PermLink></template>
            <div v-if="!latestInquiries.length" class="re-empty">{{ tf('No_inquiries_yet', 'No inquiries yet') }}</div>
            <div v-for="q in latestInquiries" :key="q.id" class="re-inq">
              <div>
                <strong>{{ q.name }}</strong>
                <a-tag v-if="q.status === 'new'" color="blue" style="margin-left: 6px">{{ $t('New') }}</a-tag>
              </div>
              <div class="re-sub">{{ q.property ? q.property.title : '—' }} · {{ String(q.created_at || '').substring(0, 10) }}</div>
            </div>
          </a-card>
        </a-col>
      </a-row>
    </a-spin>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { PlusOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { t as tf } from '../../i18n';
import http from '../../lib/http';

const loading = ref(false);
const stats = ref({});
const byStatus = ref({});
const byPurpose = ref({});
const latestProperties = ref([]);
const latestInquiries = ref([]);

const kpis = computed(() => [
  { key: 'total_properties', label: tf('Properties', 'Properties'), color: '#1677ff' },
  { key: 'available_properties', label: tf('Available', 'Available'), color: '#52c41a' },
  { key: 'new_inquiries', label: tf('New_inquiries', 'New inquiries'), color: '#fa8c16' },
  { key: 'total_inquiries', label: tf('Property_Inquiries', 'Inquiries'), color: '#722ed1' },
  { key: 'sold_properties', label: tf('Sold', 'Sold'), color: '#cf1322' },
  { key: 'rented_properties', label: tf('Rented', 'Rented'), color: '#13c2c2' },
  { key: 'featured_properties', label: tf('Featured', 'Featured'), color: '#d48806' },
  { key: 'total_categories', label: tf('Property_Categories', 'Categories'), color: '#8c8c8c' },
]);
const propColumns = computed(() => [
  { title: tf('Title', 'Title'), key: 'title' },
  { title: tf('Category', 'Category'), key: 'category' },
  { title: tf('Status', 'Status'), key: 'status' },
  { title: tf('Price', 'Price'), key: 'price', align: 'right' },
]);

const total = computed(() => Number(stats.value.total_properties) || 0);
const pct = n => (total.value ? Math.round((Number(n) / total.value) * 100) : 0);
const STATUS_COLORS = { available: '#52c41a', sold: '#cf1322', rented: '#13c2c2', pending: '#fa8c16', reserved: '#fa8c16' };
const statusColor = s => STATUS_COLORS[s] || '#8c8c8c';
const statusLabel = s => tf(String(s || '').charAt(0).toUpperCase() + String(s || '').slice(1), s || '—');
const purposeLabel = p => tf(String(p || '').charAt(0).toUpperCase() + String(p || '').slice(1), p || '—');
const money = v => (v == null || v === '' ? '—' : Number(v).toLocaleString(undefined, { maximumFractionDigits: 2 }));

onMounted(async () => {
  loading.value = true;
  try {
    const d = (await http.get('realestate/dashboard')) || {};
    stats.value = d.stats || {};
    byStatus.value = d.by_status || {};
    byPurpose.value = d.by_purpose || {};
    latestProperties.value = d.latest_properties || [];
    latestInquiries.value = d.latest_inquiries || [];
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.re-kpi :deep(.ant-statistic-title) { margin-bottom: 2px; }
.re-bar { display: flex; align-items: center; gap: 10px; margin-bottom: 6px; }
.re-bar-label { width: 110px; flex: none; font-size: 13px; }
.re-empty { color: #8c8c8c; padding: 8px 0; }
.re-inq { padding: 6px 0; border-bottom: 1px dashed #f0f0f0; }
.re-sub { font-size: 12px; color: #8c8c8c; }
</style>
