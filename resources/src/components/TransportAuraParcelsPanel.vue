<template>
  <!-- "Encomiendas AuraSoft" (2026-11-24): the parcels still to deliver (AuraSoft's kardex, the latest of each branch) and
       what is left to collect at destination (Liquidación Detallada: "pago destino" without its "cobro destino").
       App\Services\TransportAuraBoards::parcels. -->
  <div>
    <a-card v-if="data.enabled" :bordered="false" :body-style="{ padding: '8px' }">
      <a-spin :spinning="loading">
        <TransportStrip :items="tiles" :toggle="false" />
        <div class="ap-grid">
          <!-- Parcels to deliver -->
          <div class="ap-box">
            <div class="ap-h">{{ tr('Parcels to deliver by branch', 'Encomiendas por entregar por sucursal') }}
              <span v-if="data.files?.length" class="ap-mut">· {{ tr('kardex of', 'kardex del') }} {{ data.files.map(f => dateTime(f.at)).join(', ') }}</span>
            </div>
            <a-empty v-if="!data.totals?.n" :image="simple" :description="tr('Upload AuraSoft\'s &quot;Kardex de encomiendas por entregar&quot; (one per branch).', 'Suba el «Kardex de encomiendas por entregar» de AuraSoft (uno por sucursal).')" />
            <template v-else>
              <div class="ap-ages">
                <a-tooltip v-for="(n, k) in data.ages" :key="k" :title="`${k} ${tr('days', 'días')}: ${n}`">
                  <div class="ap-age" :class="{ late: lateBucket(k) }">
                    <div class="ap-av">{{ n }}</div><div class="ap-al">{{ k }} {{ tr('d', 'd') }}</div>
                  </div>
                </a-tooltip>
              </div>
              <table class="ap-t">
                <thead><tr><th>{{ tr('Branch', 'Sucursal') }}</th><th>{{ tr('Parcels', 'Encom.') }}</th><th>{{ tr('Late', 'Atrasadas') }}</th><th>S/</th><th>{{ tr('Status', 'Estado') }}</th></tr></thead>
                <tbody>
                  <tr v-for="b in data.branches" :key="b.branch">
                    <td><b>{{ b.branch }}</b></td><td class="ap-num">{{ b.n }}</td>
                    <td class="ap-num" :class="{ bad: b.late }">{{ b.late }}</td><td class="ap-num">{{ plain(b.amount) }}</td>
                    <td class="ap-st">{{ Object.entries(b.statuses).map(([s, n]) => `${cap(s)} ${n}`).join(' · ') }}</td>
                  </tr>
                </tbody>
              </table>
            </template>
          </div>
          <!-- To collect at destination -->
          <div class="ap-box">
            <div class="ap-h">{{ tr('To collect at destination', 'Por cobrar en destino') }}
              <a-tooltip :title="tr('Parcels issued &quot;pago destino&quot; whose &quot;cobro destino&quot; is not in the Liquidación Detallada uploaded.', 'Encomiendas emitidas «pago destino» cuyo «cobro destino» no está en la Liquidación Detallada subida.')"><InfoCircleOutlined class="ap-i" /></a-tooltip>
              <span v-if="rec.available" class="ap-mut">· {{ date(rec.from) }} – {{ date(rec.to) }}</span>
            </div>
            <a-empty v-if="!rec.available" :image="simple" :description="tr('Upload AuraSoft\'s &quot;Liquidación Detallada&quot;.', 'Suba la «Liquidación Detallada» de AuraSoft.')" />
            <template v-else>
              <div class="ap-ages">
                <div v-for="(a, k) in rec.ages" :key="k" class="ap-age" :class="{ late: ['16-30', '31+'].includes(k) }">
                  <div class="ap-av">{{ plain(a.amount) }}</div><div class="ap-al">{{ k }} {{ tr('d', 'd') }} · {{ a.n }}</div>
                </div>
              </div>
              <table class="ap-t">
                <thead><tr><th>{{ tr('Issued at', 'Emitida en') }}</th><th>{{ tr('Parcels', 'Encom.') }}</th><th>S/</th><th>{{ tr('Oldest', 'Más antigua') }}</th></tr></thead>
                <tbody>
                  <tr v-for="p in rec.by_point" :key="p.branch + p.point">
                    <td>{{ p.point }}<span class="ap-mut"> · {{ p.branch }}</span></td><td class="ap-num">{{ p.n }}</td>
                    <td class="ap-num"><b>{{ plain(p.amount) }}</b></td><td class="ap-num">{{ date(p.oldest) }}</td>
                  </tr>
                </tbody>
              </table>
            </template>
          </div>
          <!-- The late ones, oldest first -->
          <div class="ap-box ap-wide">
            <div class="ap-h">{{ tr('Late parcels (oldest first)', 'Encomiendas atrasadas (las más antiguas primero)') }} <span class="ap-mut">· {{ tr('over', 'más de') }} {{ data.late_days }} {{ tr('days since issued', 'días desde su emisión') }}</span></div>
            <a-empty v-if="!data.late?.length" :image="simple" :description="tr('None.', 'Ninguna.')" />
            <table v-else class="ap-t">
              <thead><tr><th>{{ tr('Days', 'Días') }}</th><th>{{ tr('Issued', 'Emisión') }}</th><th>{{ tr('Document', 'Documento') }}</th><th>{{ tr('Branch', 'Sucursal') }}</th><th>{{ tr('Consignee', 'Consignado') }}</th><th>{{ tr('Contents', 'Detalle') }}</th><th>S/</th><th>C.P</th><th>{{ tr('Status', 'Estado') }}</th></tr></thead>
              <tbody>
                <tr v-for="l in data.late" :key="l.document">
                  <td class="ap-num bad">{{ l.days }}</td><td>{{ date(l.emitted_on) }}</td><td>{{ l.document }}</td><td>{{ l.branch }}</td>
                  <td class="ap-ell">{{ l.consignee }}</td><td class="ap-ell">{{ l.detail }}</td><td class="ap-num">{{ plain(l.amount) }}</td><td>{{ l.pay }}</td><td>{{ cap(l.status) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </a-spin>
    </a-card>
    <a-empty v-else-if="!loading" :description="tr('Pick a company with Transport in the top bar to see this.', 'Elige una compañía con Transporte en la barra superior para ver esto.')" />
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { Empty } from 'ant-design-vue';
import { InfoCircleOutlined } from '@ant-design/icons-vue';
import http from '../lib/http';
import { useTransportCharts } from '../lib/transportCharts';
import { useFormat } from '../composables/useFormat';
import TransportStrip from './TransportStrip.vue';

const { money, tr } = useTransportCharts();
const { date, dateTime } = useFormat();
const simple = Empty.PRESENTED_IMAGE_SIMPLE;
const loading = ref(false);
const data = ref({ enabled: false });
async function load() {
  loading.value = true;
  try { data.value = await http.get('transport/aura-parcels'); } catch (e) { data.value = { enabled: false }; } finally { loading.value = false; }
}
onMounted(load);

const rec = computed(() => data.value.receivable || {});
const plain = v => money(Number(v || 0)).replace(/^[^\d-]+/, '');
const cap = s => (s ? String(s).charAt(0) + String(s).slice(1).toLowerCase() : '—');
const lateBucket = k => ['4-7', '8-15', '16+'].includes(k);
const tiles = computed(() => {
  const t = data.value.totals || {};
  return [
    { label: tr('To deliver', 'Por entregar'), value: String(t.n || 0) },
    { label: tr('Late', 'Atrasadas'), value: String(t.late || 0), help: `${tr('Over', 'Más de')} ${data.value.late_days} ${tr('days since issued', 'días desde su emisión')}` },
    { label: tr('Their amount', 'Su importe'), value: money(t.amount || 0) },
    { label: tr('Oldest', 'Más antigua'), value: data.value.late?.[0] ? `${data.value.late[0].days} ${tr('days', 'días')}` : '—' },
    { label: tr('To collect', 'Por cobrar'), value: rec.value.available ? money(rec.value.amount || 0) : '—', help: rec.value.available ? `${rec.value.n} ${tr('of', 'de')} ${rec.value.issued} ${tr('parcels issued pago destino', 'encomiendas emitidas pago destino')}` : '' },
  ];
});
</script>

<style scoped>
.ap-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; margin-top: 6px; }
.ap-box { border: 1px solid rgba(128, 128, 128, 0.14); border-radius: 4px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.07); padding: 6px 8px; min-width: 0; max-height: calc(50vh - 110px); min-height: 170px; overflow: auto; }
.ap-wide { grid-column: 1 / -1; max-height: calc(50vh - 150px); }
.ap-h { font-weight: 600; font-size: 12.5px; margin-bottom: 4px; }
.ap-i { opacity: 0.55; margin-left: 4px; cursor: help; }
.ap-mut { opacity: 0.55; font-size: 11px; font-weight: 400; }
.ap-ages { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 4px; margin-bottom: 6px; }
.ap-age { border-radius: 4px; padding: 3px 4px; text-align: center; background: rgba(58, 79, 196, 0.08); }
.ap-age.late { background: rgba(214, 69, 69, 0.12); }
.ap-av { font-weight: 600; font-size: 14px; font-variant-numeric: tabular-nums; white-space: nowrap; }
.ap-al { font-size: 10.5px; opacity: 0.65; white-space: nowrap; }
.ap-t { width: 100%; border-collapse: collapse; font-size: 12px; }
.ap-t th { font-weight: 500; opacity: 0.6; font-size: 11px; text-align: left; padding: 1px 4px; white-space: nowrap; }
.ap-t td { padding: 2px 4px; border-top: 1px solid rgba(128, 128, 128, 0.12); white-space: nowrap; }
.ap-num { text-align: right; font-variant-numeric: tabular-nums; }
.ap-st { font-size: 11px; opacity: 0.8; white-space: normal !important; }
.ap-ell { max-width: 220px; overflow: hidden; text-overflow: ellipsis; }
.bad { color: #D64545; font-weight: 600; }
@media (max-width: 900px) { .ap-grid { grid-template-columns: 1fr; } .ap-box { max-height: none; } }
</style>
