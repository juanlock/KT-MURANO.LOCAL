<template>
  <!-- Billing to the clients (2026-11-17): a campaign's period -> agent hours, calls or sales x the campaign's rate -> a sale. -->
  <div class="page">
    <PageHeader :title="tr('Client billing', 'Facturación a clientes')" :breadcrumb="[tr('Call center', 'Centro de llamadas'), tr('Billing', 'Facturación')]" />

    <a-card size="small" :title="tr('Bill a campaign', 'Facturar una campaña')">
      <div class="form-row">
        <a-select v-model:value="campaignId" :options="campaignOptions" show-search option-filter-prop="label" :placeholder="tr('Campaign', 'Campaña')" style="width: 260px" @change="preview = null" />
        <PresetRangeFilter v-model:value="period" value-format="YYYY-MM-DD" @change="preview = null" />
        <a-button :disabled="!campaignId || !period?.[0]" :loading="busy" @click="calc">{{ tr('Calculate', 'Calcular') }}</a-button>
      </div>
      <div v-if="preview" class="preview">
        <a-descriptions size="small" :column="{ xs: 1, sm: 2, md: 4 }" bordered>
          <a-descriptions-item :label="tr('Client', 'Cliente')">{{ preview.client || tr('(none: set it in the campaign)', '(ninguno: ponlo en la campaña)') }}</a-descriptions-item>
          <a-descriptions-item :label="tr('Billed', 'Se factura')">{{ preview.mode_label }}</a-descriptions-item>
          <a-descriptions-item :label="tr('Quantity', 'Cantidad')">{{ preview.quantity }} {{ unitText(preview.unit) }}</a-descriptions-item>
          <a-descriptions-item :label="tr('Rate', 'Tarifa')">{{ fmt.money(preview.rate) }}</a-descriptions-item>
        </a-descriptions>
        <div class="total">{{ tr('Amount', 'Monto') }}: <b>{{ fmt.money(preview.amount) }}</b></div>
        <a-alert v-if="preview.overlap?.length" type="warning" show-icon class="mt8"
          :message="tr('Part of this period is already billed', 'Parte de este periodo ya está facturado') + ': ' + preview.overlap.map(o => `${o.Ref} (${fmt.date(o.period_from)} – ${fmt.date(o.period_to)})`).join(', ')" />
        <a-popconfirm :title="tr('Create the invoice (a sale to the client)?', '¿Crear la factura (una venta al cliente)?')" :disabled="!canBill" @confirm="bill">
          <a-button type="primary" class="mt8" :disabled="!canBill" :loading="busy">{{ tr('Create invoice', 'Generar factura') }}</a-button>
        </a-popconfirm>
      </div>
    </a-card>

    <DataTable :crud="crud" :columns="columns" compact-toolbar class="mt10">
      <template #filters>
        <a-select v-model:value="filterCampaign" :options="campaignOptions" allow-clear :placeholder="tr('Campaign', 'Campaña')" style="width: 200px" @change="crud.reload" />
      </template>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'when'">{{ fmt.dateTime(record.created_at) }}</template>
        <template v-else-if="column.key === 'period'">{{ fmt.date(record.period_from) }} – {{ fmt.date(record.period_to) }}</template>
        <template v-else-if="column.key === 'qty'">{{ record.quantity }} × {{ fmt.money(record.rate) }} <div class="muted small">{{ record.mode_label }}</div></template>
        <template v-else-if="column.key === 'amount'"><b>{{ fmt.money(record.amount) }}</b></template>
        <template v-else-if="column.key === 'sale'">
          <PermLink v-if="record.sale_id && !record.sale_deleted" :to="`/sales/${record.sale_id}`" permission="Sales_view">{{ record.sale_ref }}</PermLink>
          <span v-else-if="record.sale_deleted" class="muted"><s>{{ record.sale_ref }}</s> {{ tr('deleted', 'eliminada') }}</span>
          <a-tag v-if="record.payment_status && !record.sale_deleted" :color="record.payment_status === 'paid' ? 'green' : 'orange'" class="ml4">{{ payText(record.payment_status) }}</a-tag>
        </template>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import dayjs from 'dayjs';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import PresetRangeFilter from '../../components/PresetRangeFilter.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useListState } from '../../composables/useListState';
import { useFormat } from '../../composables/useFormat';
import { sortByLabel } from '../../lib/filterOptions';
import http from '../../lib/http';
import { useTr, errText } from '../../lib/callcenter';

const tr = useTr();
const fmt = useFormat();
const campaigns = ref([]);
const campaignId = ref(undefined);
const period = ref([dayjs().subtract(1, 'month').startOf('month').format('YYYY-MM-DD'), dayjs().subtract(1, 'month').endOf('month').format('YYYY-MM-DD')]);
const preview = ref(null);
const busy = ref(false);
const filterCampaign = ref(undefined);
const crud = useCrudTable('callcenter/billing', { rowsKey: 'rows', params: () => ({ campaign_id: filterCampaign.value }) });
useListState('cc-billing', { filterCampaign }, crud);

const campaignOptions = computed(() => sortByLabel(campaigns.value.map(c => ({ value: c.id, label: c.name }))));
const canBill = computed(() => preview.value && preview.value.client_id && preview.value.amount > 0 && !preview.value.overlap?.length);
const unitText = u => ({ h: tr('agent hours', 'horas de agente'), calls: tr('calls', 'llamadas'), sales: tr('sales', 'ventas') }[u] || '');
const payText = s => ({ paid: tr('Paid', 'Pagada'), partial: tr('Partial', 'Parcial'), unpaid: tr('Unpaid', 'Por cobrar') }[s] || s);

const columns = computed(() => [
  { title: tr('Created', 'Creada'), key: 'when', dataIndex: 'created_at', sorter: true, width: 140 },
  { title: tr('Campaign', 'Campaña'), dataIndex: 'campaign', sorter: true, width: 180 },
  { title: tr('Client', 'Cliente'), dataIndex: 'client', sorter: true, width: 190 },
  { title: tr('Period', 'Periodo'), key: 'period', dataIndex: 'period_from', sorter: true, width: 190 },
  { title: tr('Quantity × rate', 'Cantidad × tarifa'), key: 'qty', width: 170 },
  { title: tr('Amount', 'Monto'), key: 'amount', dataIndex: 'amount', sorter: true, width: 120 },
  { title: tr('Invoice', 'Factura'), key: 'sale', width: 180 },
  { title: tr('By', 'Por'), dataIndex: 'by', width: 120 },
]);

async function calc() {
  busy.value = true;
  try {
    preview.value = await http.post('callcenter/billing/preview', { campaign_id: campaignId.value, from: period.value[0], to: period.value[1] });
  } catch (e) {
    preview.value = null;
    message.error(errText(e, tr('Could not calculate.', 'No se pudo calcular.')));
  } finally {
    busy.value = false;
  }
}

async function bill() {
  busy.value = true;
  try {
    const r = await http.post('callcenter/billing', { campaign_id: campaignId.value, from: period.value[0], to: period.value[1] });
    message.success(tr(`Invoice ${r.sale_ref} created.`, `Factura ${r.sale_ref} creada.`));
    preview.value = null;
    crud.fetchRows();
  } catch (e) {
    message.error(errText(e, tr('Could not create it.', 'No se pudo crear.')));
  } finally {
    busy.value = false;
  }
}

onMounted(async () => {
  crud.fetchRows();
  try {
    campaigns.value = (await http.get('callcenter/campaigns/options')).campaigns || [];
  } catch (e) { /* the picker stays empty */ }
});
</script>

<style scoped>
.form-row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.preview { margin-top: 12px; }
.total { margin-top: 8px; font-size: 16px; }
.mt8 { margin-top: 8px; }
.mt10 { margin-top: 10px; }
.ml4 { margin-left: 4px; }
.muted { opacity: 0.65; }
.small { font-size: 12px; }
</style>
