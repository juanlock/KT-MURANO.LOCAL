<template>
  <!-- The owner's figures on top of the dashboard (2026-11-04): income, tickets, today's
       trips and the month's fleet costs, then by agency, best-selling routes and what falls
       due on the buses. No date filter: always today and the month in progress. -->
  <a-card v-if="data.enabled" class="op" :bordered="false" :loading="loading">
    <template #title>
      <span class="op-title"><FundOutlined /> {{ tf('Owner_Panel', 'Business summary') }}</span>
    </template>
    <template #extra>
      <span class="op-period">{{ tf('Owner_Panel_Period', 'Today and the month in progress') }}</span>
    </template>

    <div class="op-cards">
      <div v-for="c in cards" :key="c.key" class="op-card">
        <span class="op-card-icon" :style="{ background: c.tint, color: c.color }"><component :is="c.icon" /></span>
        <div class="op-card-body">
          <div class="op-card-label">{{ c.label }}</div>
          <div class="op-card-value">{{ c.value }}</div>
          <div class="op-card-sub">{{ c.sub }}</div>
        </div>
      </div>
    </div>

    <a-row :gutter="[16, 16]">
      <a-col :xs="24" :lg="12">
        <div class="op-sub-title">{{ tf('Owner_By_Agency', 'By agency') }}</div>
        <div class="op-scroll">
          <table class="op-table">
            <thead>
              <tr>
                <th>{{ tf('Transport_Agency', 'Agency') }}</th>
                <th class="num">{{ tf('Owner_Income_Today', 'Income today') }}</th>
                <th class="num">{{ tf('Owner_Income_Month', 'Income this month') }}</th>
                <th class="num">{{ tf('Owner_Tickets_Month', 'Tickets this month') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="a in data.agencies" :key="a.name || 'other'">
                <td>{{ a.name || tf('Owner_Other_Locations', 'Other locations') }}</td>
                <td class="num">{{ money(a.today) }}</td>
                <td class="num">{{ money(a.month) }}</td>
                <td class="num">{{ a.tickets_month.toLocaleString() }}</td>
              </tr>
              <tr v-if="!data.agencies.length"><td colspan="4" class="op-muted">{{ tf('Owner_No_Sales', 'No sales this month') }}</td></tr>
            </tbody>
          </table>
        </div>
      </a-col>

      <a-col :xs="24" :lg="6">
        <div class="op-sub-title">{{ tf('Owner_Top_Routes', 'Best-selling routes') }}</div>
        <div v-for="r in data.routes" :key="r.route" class="op-route">
          <div class="op-route-row">
            <span class="op-route-name">{{ r.route }}</span>
            <b>{{ r.tickets.toLocaleString() }}</b>
          </div>
          <div class="op-bar"><span :style="{ width: `${Math.round((r.tickets / topRoute) * 100)}%` }" /></div>
          <div class="op-muted">{{ money(r.income) }}</div>
        </div>
        <div v-if="!data.routes.length" class="op-muted">{{ tf('Owner_No_Sales', 'No sales this month') }}</div>
      </a-col>

      <a-col :xs="24" :lg="6">
        <div class="op-sub-title">
          {{ tf('Owner_Fleet_Alerts', 'Falling due on the buses') }}
          <a-tag v-if="data.alerts_total" :color="overdue ? 'error' : 'warning'" class="op-tag">{{ data.alerts_total }}</a-tag>
        </div>
        <PermLink v-for="(a, i) in data.alerts" :key="`${a.kind}-${a.vehicle_id}-${i}`" :to="`/fleet/vehicles/${a.vehicle_id}`" permission="fleet_vehicles_view" class="op-alert">
          <span class="op-dot" :class="a.days < 0 ? 'late' : 'soon'" />
          <span class="op-alert-body">
            <span class="op-alert-name">{{ a.vehicle_name }}</span>
            <span class="op-muted">{{ alertLabel(a.kind) }} · {{ alertWhen(a.days) }}</span>
          </span>
        </PermLink>
        <div v-if="!data.alerts.length" class="op-muted">{{ tf('Owner_No_Alerts', 'Nothing due in the next 30 days') }}</div>
      </a-col>
    </a-row>
  </a-card>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { CarOutlined, DollarOutlined, FundOutlined, SwapOutlined, ToolOutlined } from '@ant-design/icons-vue';
import http from '../lib/http';
import { t as tf } from '../i18n';
import { useFormat } from '../composables/useFormat';

const { money } = useFormat();
const loading = ref(true);
const data = ref({ enabled: false, agencies: [], routes: [], alerts: [], alerts_total: 0 });

onMounted(async () => {
  try {
    const res = await http.get('transport/owner');
    data.value = { ...data.value, ...res };
  } catch (e) {
    data.value = { ...data.value, enabled: false }; // no access: the dashboard simply goes without it
  } finally {
    loading.value = false;
  }
});

const cards = computed(() => {
  const d = data.value;
  const trips = d.trips || {};
  return [
    { key: 'today', label: tf('Owner_Income_Today', 'Income today'), value: money(d.income?.today ?? 0), sub: `${(d.tickets?.today ?? 0).toLocaleString()} ${tf('Owner_Tickets', 'tickets')}`, icon: DollarOutlined, tint: '#dcfce7', color: '#15803d' },
    { key: 'month', label: tf('Owner_Income_Month', 'Income this month'), value: money(d.income?.month ?? 0), sub: `${(d.tickets?.month ?? 0).toLocaleString()} ${tf('Owner_Tickets', 'tickets')}`, icon: FundOutlined, tint: '#e0f2fe', color: '#0369a1' },
    { key: 'trips', label: tf('Owner_Trips_Today', 'Trips today'), value: trips.total ?? 0, sub: `${trips.completed ?? 0} ${tf('Owner_Done', 'done')} · ${trips.in_progress ?? 0} ${tf('Owner_On_Road', 'on the road')} · ${trips.scheduled ?? 0} ${tf('Owner_To_Leave', 'to leave')}`, icon: SwapOutlined, tint: '#e0e7ff', color: '#4338ca' },
    { key: 'costs', label: tf('Owner_Fleet_Costs', 'Fleet costs this month'), value: money(d.costs?.total ?? 0), sub: `${tf('Owner_Fuel', 'Fuel')} ${money(d.costs?.fuel ?? 0)} · ${tf('Owner_Maintenance', 'Maintenance')} ${money(d.costs?.maintenance ?? 0)}`, icon: ToolOutlined, tint: '#fef3c7', color: '#b45309' },
    { key: 'alerts', label: tf('Owner_Fleet_Alerts', 'Falling due on the buses'), value: d.alerts_total ?? 0, sub: tf('Owner_Next_30', 'overdue or within 30 days'), icon: CarOutlined, tint: '#fee2e2', color: '#b91c1c' },
  ];
});
const topRoute = computed(() => Math.max(1, ...data.value.routes.map(r => r.tickets)));
const overdue = computed(() => data.value.alerts.some(a => a.days < 0));

const alertLabel = kind => ({
  insurance_expiry: tf('Owner_Alert_Insurance', 'Insurance'),
  registration_expiry: tf('Owner_Alert_Registration', 'Registration'),
  inspection_expiry: tf('Owner_Alert_Inspection', 'Inspection'),
  service: tf('Owner_Alert_Service', 'Next service'),
})[kind] || kind;
const alertWhen = days => (days < 0
  ? `${tf('Owner_Overdue', 'overdue')} ${Math.abs(days)} d`
  : `${tf('Owner_In', 'in')} ${days} d`);
</script>

<style scoped>
.op { margin-bottom: 16px; }
.op-title { display: inline-flex; gap: 8px; align-items: center; font-weight: 600; }
.op-period { font-size: 12px; color: #64748b; }
.op-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 12px; margin-bottom: 18px; }
.op-card { display: flex; gap: 12px; padding: 12px 14px; border: 1px solid #eef0f3; border-radius: 10px; background: #fff; min-width: 0; }
.op-card-icon { width: 38px; height: 38px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; font-size: 18px; flex: none; }
.op-card-body { min-width: 0; }
.op-card-label { font-size: 12px; color: #64748b; }
.op-card-value { font-size: 21px; font-weight: 700; line-height: 1.25; overflow-wrap: anywhere; }
.op-card-sub { font-size: 11.5px; color: #64748b; }
.op-sub-title { font-weight: 700; font-size: 14px; margin-bottom: 8px; }
.op-tag { margin-left: 4px; }
.op-scroll { overflow-x: auto; }
.op-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.op-table th { text-align: left; font-size: 12px; font-weight: 600; color: #475569; padding: 6px 8px; border-bottom: 1px solid #e2e8f0; white-space: nowrap; }
.op-table td { padding: 7px 8px; border-bottom: 1px dashed #eef0f3; white-space: nowrap; }
.op-table .num { text-align: right; font-variant-numeric: tabular-nums; }
.op-muted { font-size: 12px; color: #64748b; }
.op-route { margin-bottom: 10px; }
.op-route-row { display: flex; justify-content: space-between; gap: 8px; font-size: 13px; }
.op-route-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.op-bar { height: 5px; border-radius: 3px; background: #eef2f7; margin: 3px 0; overflow: hidden; }
.op-bar span { display: block; height: 100%; background: #0369a1; border-radius: 3px; }
.op-alert { display: flex; gap: 8px; align-items: flex-start; padding: 5px 0; border-bottom: 1px dashed #eef0f3; }
.op-alert-body { display: flex; flex-direction: column; min-width: 0; }
.op-alert-name { font-size: 13px; font-weight: 600; color: #0f172a; }
.op-dot { width: 9px; height: 9px; border-radius: 50%; margin-top: 5px; flex: none; }
.op-dot.late { background: #dc2626; }
.op-dot.soon { background: #f59e0b; }
</style>
