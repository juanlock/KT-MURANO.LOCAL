<template>
  <div class="page">
    <PageHeader
      title="Bills of Materials"
      subtitle="What each product is made of, and how it is made."
      :breadcrumb="['Manufacturing', 'Bills of Materials']"
    >
      <template #actions>
        <a-button @click="$router.push('/mrp/work-centers')">
          <template #icon><SettingOutlined /></template>
          Work centres
        </a-button>
        <a-button type="primary" @click="$router.push('/mrp/boms/create')">
          <template #icon><PlusOutlined /></template>
          New BOM
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <div class="toolbar">
        <a-input-search
          v-model:value="crud.search.value" placeholder="Search name, code or product…"
          allow-clear class="tb-search" @search="crud.reload"
        />
        <a-select
          v-model:value="filters.status" class="tb-item" allow-clear
          placeholder="All statuses" :options="BOM_STATUSES" @change="crud.reload"
        />
      </div>
    </a-card>

    <DataTable :crud="crud" :columns="columns" :searchable="false">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'name'">
          <a class="link" @click="$router.push(`/mrp/boms/${record.id}/edit`)">{{ record.name }}</a>
          <div class="sub">{{ record.code }}</div>
        </template>
        <template v-else-if="column.key === 'product'">
          {{ record.product_name }}
          <div class="sub">{{ record.product_code }}</div>
        </template>
        <template v-else-if="column.key === 'output_qty'">
          {{ number(record.output_qty, 2) }}
          <div class="sub">per run</div>
        </template>
        <template v-else-if="column.key === 'status'">
          <a-space :size="4">
            <a-tag :color="optionOf(BOM_STATUSES, record.status).color">
              {{ labelOf(BOM_STATUSES, record.status) }}
            </a-tag>
            <a-tag v-if="record.is_default" color="purple">Default</a-tag>
          </a-space>
        </template>
        <template v-else-if="column.key === 'structure'">
          {{ record.component_count }} component{{ record.component_count === 1 ? '' : 's' }}
          <div class="sub">{{ record.operation_count }} operation{{ record.operation_count === 1 ? '' : 's' }}</div>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space :size="0">
            <a-tooltip title="Explode">
              <a-button type="text" size="small" @click="openExplode(record)">
                <template #icon><PartitionOutlined style="color: #6d28d9" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip title="New version">
              <a-button type="text" size="small" @click="duplicate(record)">
                <template #icon><CopyOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Edit')">
              <a-button type="text" size="small" @click="$router.push(`/mrp/boms/${record.id}/edit`)">
                <template #icon><EditOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Del')">
              <a-button type="text" size="small" danger @click="crud.remove(record, { label: record.name })">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>

    <a-drawer
      :open="explodeOpen" :title="`Full structure — ${exploding?.name || ''}`"
      placement="right" :width="720" @close="explodeOpen = false"
    >
      <div class="explode-head">
        <a-input-number v-model:value="explodeQty" :min="0.0001" style="width: 160px" @change="loadExplode" />
        <span class="sub">units to build</span>
        <a-tag color="purple" class="explode-total">Material: {{ money(explodeTotal) }}</a-tag>
      </div>

      <a-alert
        v-if="loops.length" type="error" show-icon style="margin-bottom: 12px"
        message="Circular structure detected"
        :description="loops.map(l => l.product_name || ('#' + l.product_id)).join(', ') + ' — the branch was stopped rather than followed.'"
      />

      <a-table
        size="small" :columns="explodeColumns" :data-source="explodeRows"
        row-key="rowKey" :pagination="false" :loading="exploding && loadingExplode"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'product'">
            <span :style="{ paddingInlineStart: (record.level - 1) * 16 + 'px' }">
              <span class="tree-mark">{{ record.level > 1 ? '└' : '' }}</span>
              {{ record.product_name }}
              <a-tag v-if="record.has_bom" class="mini">sub-assembly</a-tag>
              <a-tag v-if="record.is_optional" class="mini">optional</a-tag>
            </span>
            <div class="sub" :style="{ paddingInlineStart: (record.level - 1) * 16 + 14 + 'px' }">
              {{ record.product_code }}
            </div>
          </template>
          <template v-else-if="['unit_cost', 'total_cost'].includes(column.key)">
            {{ money(record[column.key]) }}
          </template>
        </template>
      </a-table>
    </a-drawer>
  </div>
</template>

<script setup>
/**
 * The BOM register.
 *
 * "New version" duplicates rather than editing in place: changing a live recipe
 * silently rewrites what every future order costs, so the safe path is offered
 * as the obvious one.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import {
  PlusOutlined, EditOutlined, DeleteOutlined, CopyOutlined,
  PartitionOutlined, SettingOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { BOM_STATUSES, labelOf, optionOf } from './mrpOptions';
import http from '../../lib/http';

const router = useRouter();
const { money, number } = useFormat();

const filters = reactive({ status: undefined });

const crud = useCrudTable('mrp/boms', {
  rowsKey: 'boms',
  params: () => ({ status: filters.status || '' }),
});

const columns = computed(() => [
  { title: 'Bill of materials', key: 'name', dataIndex: 'name', sorter: true },
  { title: 'Builds', key: 'product', dataIndex: 'product_name', sorter: true },
  { title: 'Output', key: 'output_qty', dataIndex: 'output_qty', width: 110, align: 'right' },
  { title: 'Structure', key: 'structure', width: 150 },
  { title: 'Version', dataIndex: 'version', key: 'version', width: 90, align: 'center' },
  { title: 'Status', key: 'status', dataIndex: 'status', sorter: true, width: 160 },
  { title: '', key: 'actions', width: 150, align: 'center' },
]);

// ---------------- explode ----------------

const explodeOpen = ref(false);
const exploding = ref(null);
const explodeQty = ref(1);
const explodeRows = ref([]);
const explodeTotal = ref(0);
const loops = ref([]);
const loadingExplode = ref(false);

const explodeColumns = [
  { title: 'Component', key: 'product', dataIndex: 'product_name' },
  { title: 'Qty', dataIndex: 'qty', key: 'qty', width: 100, align: 'right' },
  { title: 'Unit cost', key: 'unit_cost', dataIndex: 'unit_cost', width: 110, align: 'right' },
  { title: 'Total', key: 'total_cost', dataIndex: 'total_cost', width: 120, align: 'right' },
];

function openExplode(record) {
  exploding.value = record;
  explodeQty.value = record.output_qty || 1;
  explodeOpen.value = true;
  loadExplode();
}

async function loadExplode() {
  if (!exploding.value) return;
  loadingExplode.value = true;
  try {
    const res = await http.get(`mrp/boms/${exploding.value.id}/explode`, { qty: explodeQty.value });
    // Rows repeat product ids at different levels, so the row key has to
    // include the position or antd collapses them into one.
    explodeRows.value = (res?.rows || []).map((r, i) => ({ ...r, rowKey: `${r.product_id}-${r.level}-${i}` }));
    explodeTotal.value = res?.total_cost || 0;
    loops.value = res?.loops || [];
  } catch (e) {
    explodeRows.value = [];
    loops.value = [];
  } finally {
    loadingExplode.value = false;
  }
}

async function duplicate(record) {
  try {
    const res = await http.post(`mrp/boms/${record.id}/duplicate`);
    message.success('New draft version created');
    // Open the copy straight away — the point of duplicating is to change it.
    if (res?.id) router.push(`/mrp/boms/${res.id}/edit`);
    else crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || 'Could not duplicate that BOM');
  }
}

onMounted(crud.fetchRows);
</script>

<style scoped>
.sub {
  font-size: 11.5px;
  opacity: 0.55;
}
.mini {
  font-size: 10.5px;
  line-height: 16px;
  margin-inline-start: 4px;
}
.link {
  color: #6d28d9;
  cursor: pointer;
  font-weight: 500;
}
.tree-mark {
  opacity: 0.4;
  margin-inline-end: 2px;
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}
.tb-search {
  flex: 1 1 220px;
  min-width: 180px;
}
.tb-item {
  width: 170px;
}
.explode-head {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 14px;
  flex-wrap: wrap;
}
.explode-total {
  margin-inline-start: auto;
}
@media (max-width: 767px) {
  .tb-item {
    width: 100%;
  }
}
</style>
