<template>
  <div class="page">
    <PageHeader
      title="Integrations"
      subtitle="Connect Stocky with the platforms and services your business runs on."
    >
      <template #actions>
        <a-segmented
          v-model:value="filter"
          :options="[
            { value: 'all', label: 'All' },
            { value: 'connected', label: $t('Connected') },
            { value: 'not', label: 'Not connected' },
          ]"
        />
      </template>
    </PageHeader>

    <section v-for="cat in categories" :key="cat.title">
      <div class="cat-head">
        <h4 class="cat-title">{{ cat.title }}</h4>
        <span class="cat-count">{{ cat.items.length }} {{ cat.items.length === 1 ? 'integration' : 'integrations' }}</span>
      </div>
      <a-row :gutter="[16, 16]">
        <a-col v-for="c in cat.items" :key="c.key" :xs="24" :sm="12" :xl="8">
          <a-card size="small" class="int-card">
            <div class="int-top">
              <a-avatar shape="square" :size="44" :style="avatarStyle(c)">
                <template #icon><component :is="c.icon" /></template>
              </a-avatar>
              <a-tag v-if="c.status === 'loading'">Checking…</a-tag>
              <a-tag v-else-if="c.status === 'on'" color="success">{{ $t('Connected') }}</a-tag>
              <a-tag v-else-if="c.status === 'unknown'">Status unavailable</a-tag>
              <a-tag v-else>Not connected</a-tag>
            </div>
            <div class="int-name">{{ c.name }}</div>
            <div class="int-desc">{{ c.desc }}</div>
            <div class="int-foot">
              <span class="int-detail">{{ c.detail }}</span>
              <a-button size="small" type="primary" ghost @click="configure(c)">
                {{ c.status === 'on' ? 'Manage' : 'Configure' }} <RightOutlined />
              </a-button>
            </div>
          </a-card>
        </a-col>
      </a-row>
    </section>

    <template v-if="filter === 'all'">
      <div class="cat-head" style="margin-top: 28px">
        <h4 class="cat-title">Coming soon</h4>
        <span class="cat-count">on the roadmap</span>
      </div>
      <a-row :gutter="[12, 12]">
        <a-col v-for="s in comingSoon" :key="s.name" :xs="12" :sm="8" :lg="6">
          <a-card size="small" class="int-card soon">
            <div class="soon-body">
              <a-avatar shape="square" :size="36" :style="avatarStyle(s)">
                <template #icon><component :is="s.icon" /></template>
              </a-avatar>
              <div class="soon-text">
                <div class="soon-name">{{ s.name }}</div>
                <div class="soon-desc">{{ s.desc }}</div>
              </div>
            </div>
          </a-card>
        </a-col>
      </a-row>
    </template>
  </div>
</template>

<script setup>
/**
 * Integrations Hub — one card per connector, grouped by category. Each card
 * probes its own settings endpoint for a live "connected" state (in parallel,
 * failures degrade to "Status unavailable"). Cards the user has no permission
 * for are hidden entirely, mirroring the sidebar gates.
 */
import { ref, computed, onMounted, markRaw } from 'vue';
import { useRouter } from 'vue-router';
import {
  ShoppingCartOutlined, ShopOutlined, BankOutlined, ThunderboltOutlined,
  WhatsAppOutlined, ApiOutlined, RightOutlined, SlackOutlined,
  SendOutlined, TableOutlined, MailOutlined, CalculatorOutlined,
  ShoppingOutlined, GlobalOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { useAuthStore } from '../../stores/auth';

const auth = useAuthStore();
const router = useRouter();
const filter = ref('all');

const CAT_ECOMMERCE = 'Ecommerce Platforms';
const CAT_AUTOMATION = 'Automation & Messaging';
const CAT_ACCOUNTING = 'Accounting & Data';

// targets: [permission, vue path] pairs — Configure goes to the first one
// the user holds, so the router guard always lets them in.
const defs = [
  {
    key: 'woocommerce', name: 'WooCommerce', category: CAT_ECOMMERCE,
    color: '#96588a', icon: markRaw(ShoppingCartOutlined),
    desc: 'Sync products, stock levels and orders with your WooCommerce store.',
    perms: ['woocommerce_settings'],
    targets: [['woocommerce_settings', '/woocommerce']],
    check: async () => {
      const d = await http.get('woocommerce/settings');
      const s = d && (d.settings || d);
      return { on: !!(s && s.store_url && s.consumer_key && s.consumer_secret) };
    },
  },
  {
    key: 'shopify', name: 'Shopify', category: CAT_ECOMMERCE,
    color: '#5e8e3e', icon: markRaw(ShopOutlined),
    desc: 'Multi-store sync of products, inventory, orders and customers with Shopify.',
    perms: ['shopify_stores', 'shopify_sync', 'shopify_logs'],
    targets: [['shopify_stores', '/shopify/stores'], ['shopify_sync', '/shopify/sync'], ['shopify_logs', '/shopify/logs']],
    check: async () => {
      const d = await http.get('shopify/stores');
      const list = (d && (d.stores || d.data)) || (Array.isArray(d) ? d : []);
      const n = Array.isArray(list) ? list.length : 0;
      return { on: n > 0, detail: n ? `${n} store${n > 1 ? 's' : ''} connected` : '' };
    },
  },
  {
    key: 'salla', name: 'Salla', category: CAT_ECOMMERCE,
    color: '#00a58e', icon: markRaw(ShoppingOutlined),
    desc: 'Sync products, stock and orders with your Salla store (Gulf ecommerce).',
    perms: ['salla_settings'],
    targets: [['salla_settings', '/integrations/salla']],
    check: async () => {
      const d = await http.get('salla/settings');
      return {
        on: !!(d && d.connected && d.enabled),
        detail: d && d.store && d.store.name ? d.store.name : '',
      };
    },
  },
  {
    key: 'prestashop', name: 'PrestaShop', category: CAT_ECOMMERCE,
    color: '#df0067', icon: markRaw(GlobalOutlined),
    desc: 'Sync products, stock and orders with your PrestaShop store.',
    perms: ['prestashop_settings'],
    targets: [['prestashop_settings', '/integrations/prestashop']],
    check: async () => {
      const d = await http.get('prestashop/settings');
      return { on: !!(d && d.configured && d.enabled) };
    },
  },
  {
    key: 'jumia', name: 'Jumia', category: CAT_ECOMMERCE,
    color: '#f68b1e', icon: markRaw(ShoppingOutlined),
    desc: 'Push prices & stock to Jumia Seller Center and import marketplace orders.',
    perms: ['jumia_settings'],
    targets: [['jumia_settings', '/integrations/jumia']],
    check: async () => {
      const d = await http.get('jumia/settings');
      return { on: !!(d && d.configured && d.enabled) };
    },
  },
  {
    key: 'mailchimp', name: 'Mailchimp', category: CAT_AUTOMATION,
    color: '#e2a400', icon: markRaw(MailOutlined),
    desc: 'Keep a Mailchimp audience in sync with your customers for email campaigns.',
    perms: ['mailchimp_settings'],
    targets: [['mailchimp_settings', '/integrations/mailchimp']],
    check: async () => {
      const d = await http.get('mailchimp/settings');
      return {
        on: !!(d && d.ready && d.enabled),
        detail: d && d.list_name ? d.list_name : '',
      };
    },
  },
  {
    key: 'slack', name: 'Slack', category: CAT_AUTOMATION,
    color: '#611f69', icon: markRaw(SlackOutlined),
    desc: 'Post sales, stock alerts and payment events into a Slack channel.',
    perms: ['slack_settings'],
    targets: [['slack_settings', '/integrations/slack']],
    check: async () => {
      const d = await http.get('slack/settings');
      return { on: !!(d && d.enabled && d.configured) };
    },
  },
  {
    key: 'telegram', name: 'Telegram', category: CAT_AUTOMATION,
    color: '#229ed9', icon: markRaw(SendOutlined),
    desc: 'Send business events to a Telegram chat or group via your bot.',
    perms: ['telegram_settings'],
    targets: [['telegram_settings', '/integrations/telegram']],
    check: async () => {
      const d = await http.get('telegram/settings');
      return { on: !!(d && d.enabled && d.configured) };
    },
  },
  {
    key: 'webhooks', name: 'Webhooks', category: CAT_AUTOMATION,
    color: '#6d28d9', icon: markRaw(ApiOutlined),
    desc: 'Push sales, purchases, payments and stock events to any endpoint.',
    perms: ['webhooks_view'],
    targets: [['webhooks_view', '/settings/webhooks']],
    check: async () => {
      const d = await http.get('webhooks?limit=-1');
      const all = (d && d.webhooks) || [];
      const active = all.filter(w => w.is_active).length;
      return {
        on: active > 0,
        detail: all.length ? `${active} active · ${all.length} total` : '',
      };
    },
  },
  {
    key: 'quickbooks', name: 'QuickBooks', category: CAT_ACCOUNTING,
    color: '#2ca01c', icon: markRaw(BankOutlined),
    desc: 'Sync sales, payments and expenses with QuickBooks Online.',
    perms: ['quickbooks_settings'],
    targets: [['quickbooks_settings', '/settings/quickbooks']],
    check: async () => {
      const d = await http.get('quickbooks/status');
      return { on: !!(d && d.has_token) };
    },
  },
  {
    key: 'xero', name: 'Xero', category: CAT_ACCOUNTING,
    color: '#13b5ea', icon: markRaw(CalculatorOutlined),
    desc: 'Push customers as contacts and sales as invoices to your Xero organisation.',
    perms: ['xero_settings'],
    targets: [['xero_settings', '/integrations/xero']],
    check: async () => {
      const d = await http.get('xero/settings');
      return {
        on: !!(d && d.connected && d.enabled),
        detail: d && d.tenant_name ? d.tenant_name : '',
      };
    },
  },
  {
    key: 'google_sheets', name: 'Google Sheets', category: CAT_ACCOUNTING,
    color: '#188038', icon: markRaw(TableOutlined),
    desc: 'Export sales, products and customers into a live spreadsheet.',
    perms: ['google_sheets_settings'],
    targets: [['google_sheets_settings', '/integrations/google-sheets']],
    check: async () => {
      const d = await http.get('google-sheets/settings');
      return {
        on: !!(d && d.connected && d.enabled),
        detail: d && d.spreadsheet_id ? 'Spreadsheet linked' : '',
      };
    },
  },
];

const cards = ref(
  defs
    .filter(d => d.perms.some(p => auth.can(p)))
    .map(d => ({ ...d, status: 'loading', detail: '' }))
);

const comingSoon = [
  { name: 'Zapier', desc: 'Workflow automation', color: '#ff4a00', icon: markRaw(ThunderboltOutlined) },
  { name: 'WhatsApp Business', desc: 'Invoice & alert messages', color: '#25d366', icon: markRaw(WhatsAppOutlined) },
  { name: 'Meta Commerce', desc: 'Facebook & Instagram Shops', color: '#0866ff', icon: markRaw(ShopOutlined) },
  { name: 'TikTok Shop', desc: 'Social commerce', color: '#fe2c55', icon: markRaw(ShoppingCartOutlined) },
  { name: 'Shipping (Aramex, Shippo…)', desc: 'Fulfillment & tracking', color: '#e47911', icon: markRaw(GlobalOutlined) },
];

function matches(c) {
  if (filter.value === 'connected') return c.status === 'on';
  if (filter.value === 'not') return c.status === 'off' || c.status === 'unknown';
  return true;
}

const categories = computed(() =>
  [CAT_ECOMMERCE, CAT_AUTOMATION, CAT_ACCOUNTING]
    .map(title => ({ title, items: cards.value.filter(c => c.category === title && matches(c)) }))
    .filter(cat => cat.items.length)
);

function tint(hex, alpha = 0.14) {
  const n = parseInt(hex.replace('#', ''), 16);
  return `rgba(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}, ${alpha})`;
}
function avatarStyle(c) {
  return { background: tint(c.color), color: c.color };
}

function configure(c) {
  const target = c.targets.find(([p]) => auth.can(p));
  if (target) router.push(target[1]);
}

onMounted(() => {
  for (const c of cards.value) {
    c.check()
      .then(r => { c.status = r.on ? 'on' : 'off'; c.detail = r.detail || ''; })
      .catch(() => { c.status = 'unknown'; });
  }
});
</script>

<style scoped>
.cat-head {
  display: flex;
  align-items: baseline;
  gap: 10px;
  margin: 20px 0 12px;
}
.cat-title {
  margin: 0;
  font-size: 15px;
  font-weight: 600;
}
.cat-count {
  font-size: 12px;
  opacity: 0.55;
}
.int-card {
  height: 100%;
  transition: box-shadow 0.18s ease, transform 0.18s ease;
}
.int-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
}
.int-top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  margin-bottom: 12px;
}
.int-name {
  font-weight: 600;
  font-size: 15px;
  margin-bottom: 4px;
}
.int-desc {
  font-size: 12.5px;
  line-height: 1.5;
  opacity: 0.65;
  min-height: 38px;
}
.int-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-top: 14px;
  padding-top: 12px;
  border-top: 1px solid rgba(128, 128, 128, 0.15);
}
.int-detail {
  font-size: 12px;
  opacity: 0.6;
}
.int-card.soon :deep(.ant-card-body) {
  padding: 10px 12px;
}
.soon {
  border-style: dashed;
}
.soon-body {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
}
.soon-text {
  min-width: 0;
}
.soon-name {
  font-weight: 600;
  font-size: 13px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.soon-desc {
  font-size: 11px;
  opacity: 0.55;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
</style>
