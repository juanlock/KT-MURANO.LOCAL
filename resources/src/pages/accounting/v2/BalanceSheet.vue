<template>
  <div class="page">
    <PageHeader :title="$t('Balance_Sheet_Title')" :breadcrumb="[$t('Accounting'), $t('Balance_Sheet_Title')]" />

    <a-card size="small" style="margin-bottom: 16px">
      <a-row :gutter="[12, 12]" align="bottom">
        <a-col :xs="24" :md="8">
          <div class="filter-label">{{ $t('As_Of') }}</div>
          <a-input v-model:value="to" type="date" />
        </a-col>
        <a-col :xs="24" :md="8">
          <a-button type="primary" @click="fetch">
            <template #icon><ReloadOutlined /></template>
            {{ $t('Refresh') }}
          </a-button>
        </a-col>
      </a-row>
    </a-card>

    <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
      <a-col :xs="24" :md="8">
        <a-card size="small"><a-statistic :title="$t('Assets')" :value="number(data.assets)" /></a-card>
      </a-col>
      <a-col :xs="24" :md="8">
        <a-card size="small"><a-statistic :title="$t('Liabilities')" :value="number(data.liabilities)" /></a-card>
      </a-col>
      <a-col :xs="24" :md="8">
        <a-card size="small"><a-statistic :title="$t('Equity')" :value="number(data.equity)" /></a-card>
      </a-col>
    </a-row>

    <a-alert
      :type="Math.abs(data.balance) < 0.01 ? 'success' : 'warning'"
      show-icon
      :message="$t('Balance_Check')"
      :description="number(data.balance)"
    />
  </div>
</template>

<script setup>
/**
 * Balance sheet (v2) — GET accounting/v2/reports/balance-sheet?to= →
 * {assets, liabilities, equity, balance}. Balance check green when |balance|
 * < 0.01 (legacy threshold).
 */
import { ref, onMounted } from 'vue';
import { ReloadOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../../components/PageHeader.vue';
import { useFormat } from '../../../composables/useFormat';
import http from '../../../lib/http';

const { number } = useFormat();

const to = ref('');
const data = ref({ assets: 0, liabilities: 0, equity: 0, balance: 0 });

async function fetch() {
  try {
    const params = {};
    if (to.value) params.to = to.value;
    const r = await http.get('accounting/v2/reports/balance-sheet', params);
    data.value = r || data.value;
  } catch (e) { /* stays empty */ }
}

onMounted(fetch);
</script>

<style scoped>
.filter-label {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.55);
  margin-bottom: 4px;
}
</style>
