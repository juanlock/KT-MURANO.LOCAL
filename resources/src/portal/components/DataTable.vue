<template>
  <!--
    Server-side data table in the portal list-page shape: a card whose header
    carries the title and the filter form (search, selects, page length), a
    card-table, and the pagination in the card footer.
  -->
  <div class="card">
    <div class="card-header d-block">
      <div class="d-flex flex-wrap align-items-center gap-2">
        <h3 class="card-title mb-0">{{ title }}</h3>
        <span v-if="totalRows" class="badge bg-secondary-lt">{{ totalRows }}</span>

        <form class="ms-auto d-flex flex-wrap align-items-center gap-2 d-print-none" @submit.prevent>
          <div class="input-icon">
            <span class="input-icon-addon"><i class="ti ti-search"></i></span>
            <input
              type="search"
              class="form-control"
              :value="state.search"
              :placeholder="searchPlaceholder || tr('search', 'Search')"
              :aria-label="tr('search', 'Search')"
              @input="setSearch($event.target.value)"
            />
          </div>

          <slot name="filters" :state="state" />

          <select class="form-select w-auto" :value="state.pageSize" :aria-label="tr('entries', 'entries')" @change="setPageSize($event.target.value)">
            <option v-for="n in pageSizes" :key="n" :value="n">{{ n }} / {{ tr('page', 'page') }}</option>
          </select>

          <button v-if="hasActiveFilters" type="button" class="btn btn-outline-secondary" @click="$emit('reset')">
            <i class="ti ti-x me-1"></i>{{ tr('reset_filters', 'Reset') }}
          </button>
        </form>
      </div>
    </div>

    <EmptyState v-if="!loading && !rows.length" :icon="emptyIcon" :title="emptyText || tr('no_matching_records', 'No matching records')"
      :subtitle="hasActiveFilters ? tr('try_adjusting_filters', 'Try adjusting your search or filters.') : ''" />

    <div v-else class="table-responsive" :class="{ 'pc-table-loading': loading }">
      <div v-if="loading" class="pc-table-spinner"><div class="spinner-border text-primary" role="status"></div></div>
      <table class="table card-table table-vcenter table-mobile-md">
        <thead>
          <tr>
            <th v-for="col in columns" :key="col.key" :class="[col.class, col.numeric ? 'text-end' : '']" :style="col.width ? { width: col.width } : null">
              <button
                v-if="col.sortable"
                type="button"
                class="pc-sort"
                :class="{ 'is-active': state.sort === col.key }"
                @click="toggleSort(col.key)"
              >
                <span>{{ col.label }}</span>
                <i class="ti" :class="state.sort === col.key ? (state.dir === 'desc' ? 'ti-sort-descending' : 'ti-sort-ascending') : 'ti-arrows-sort'"></i>
              </button>
              <span v-else>{{ col.label }}</span>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(row, i) in rows" :key="rowKeyOf(row, i)">
            <td v-for="col in columns" :key="col.key" :class="[col.class, col.numeric ? 'text-end font-monospace' : '']" :data-label="col.label">
              <slot :name="'cell-' + col.key" :row="row" :value="row[col.key]">{{ formatCell(row[col.key]) }}</slot>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="card-footer d-flex flex-wrap align-items-center gap-2">
      <p class="m-0 text-secondary">{{ rangeText }}</p>
      <ul class="pagination m-0 ms-auto">
        <li class="page-item" :class="{ disabled: state.page <= 1 || loading }">
          <a class="page-link" href="#" tabindex="-1" :aria-label="tr('previous', 'Previous')" @click.prevent="goTo(state.page - 1)">
            <i class="ti ti-chevron-left"></i>
          </a>
        </li>
        <li v-for="(p, i) in pageWindow" :key="i" class="page-item" :class="{ active: p === state.page, disabled: p === '…' }">
          <span v-if="p === '…'" class="page-link">…</span>
          <a v-else class="page-link" href="#" @click.prevent="goTo(p)">{{ p }}</a>
        </li>
        <li class="page-item" :class="{ disabled: state.page >= totalPages || loading }">
          <a class="page-link" href="#" :aria-label="tr('next', 'Next')" @click.prevent="goTo(state.page + 1)">
            <i class="ti ti-chevron-right"></i>
          </a>
        </li>
      </ul>
    </div>
  </div>
</template>

<script>
import EmptyState from './EmptyState.vue';

/**
 * The component owns the query state — page, page length, search, sort — and
 * emits `query` with { page, limit, search, sort, dir } whenever it changes
 * (search is debounced). The parent fetches and passes `rows` + `totalRows`
 * back. Extra filters live in the parent: render them in the `filters` slot
 * and pass the values as the `filters` prop so a change resets to page 1.
 * Cells render `row[col.key]` by default; override with a `cell-<key>` slot.
 */
export default {
  name: 'DataTable',
  components: { EmptyState },
  props: {
    title: { type: String, default: '' },
    columns: { type: Array, required: true },   // [{ key, label, sortable, numeric, class, width }]
    rows: { type: Array, default: () => [] },
    rowKey: { type: [String, Function], default: 'id' },
    totalRows: { type: Number, default: 0 },
    loading: { type: Boolean, default: false },
    pageSizes: { type: Array, default: () => [10, 25, 50, 100] },
    defaultPageSize: { type: Number, default: 10 },
    defaultSort: { type: String, default: '' },
    defaultDir: { type: String, default: 'desc' },
    searchPlaceholder: { type: String, default: '' },
    emptyText: { type: String, default: '' },
    emptyIcon: { type: String, default: 'search-off' },
    filters: { type: Object, default: () => ({}) },
  },
  emits: ['query', 'reset'],
  data() {
    return {
      state: { page: 1, pageSize: this.defaultPageSize, search: '', sort: this.defaultSort, dir: this.defaultDir },
      debounce: null,
    };
  },
  computed: {
    totalPages() {
      return Math.max(1, Math.ceil((this.totalRows || 0) / this.state.pageSize));
    },
    hasActiveFilters() {
      if (this.state.search) return true;
      return Object.values(this.filters || {}).some((v) => v !== '' && v != null);
    },
    rangeText() {
      const total = this.totalRows || 0;
      if (!total) return this.tr('showing_none', 'No entries');
      const from = (this.state.page - 1) * this.state.pageSize + 1;
      const to = Math.min(total, this.state.page * this.state.pageSize);
      return this.tr('showing_entries', `Showing ${from} to ${to} of ${total} entries`, { from, to, total });
    },
    pageWindow() {
      const total = this.totalPages, cur = this.state.page, out = [];
      if (total <= 7) { for (let i = 1; i <= total; i++) out.push(i); return out; }
      out.push(1);
      if (cur > 3) out.push('…');
      for (let i = Math.max(2, cur - 1); i <= Math.min(total - 1, cur + 1); i++) out.push(i);
      if (cur < total - 2) out.push('…');
      out.push(total);
      return out;
    },
  },
  watch: {
    filters: { deep: true, handler() { this.state.page = 1; this.emitQuery(); } },
  },
  mounted() { this.emitQuery(); },
  methods: {
    rowKeyOf(row, i) {
      if (typeof this.rowKey === 'function') return this.rowKey(row, i);
      return row[this.rowKey] != null ? row[this.rowKey] : i;
    },
    formatCell(v) { return v == null || v === '' ? '—' : v; },
    emitQuery() {
      this.$emit('query', {
        page: this.state.page,
        limit: this.state.pageSize,
        search: this.state.search || undefined,
        sort: this.state.sort || undefined,
        dir: this.state.sort ? this.state.dir : undefined,
      });
    },
    setPageSize(v) { this.state.pageSize = Number(v) || this.defaultPageSize; this.state.page = 1; this.emitQuery(); },
    setSearch(v) {
      this.state.search = v;
      clearTimeout(this.debounce);
      this.debounce = setTimeout(() => { this.state.page = 1; this.emitQuery(); }, 300);
    },
    clearSearch() { this.state.search = ''; this.state.page = 1; this.emitQuery(); },
    toggleSort(key) {
      if (this.state.sort === key) this.state.dir = this.state.dir === 'asc' ? 'desc' : 'asc';
      else { this.state.sort = key; this.state.dir = 'asc'; }
      this.state.page = 1;
      this.emitQuery();
    },
    goTo(p) { if (p < 1 || p > this.totalPages || p === this.state.page) return; this.state.page = p; this.emitQuery(); },
    refresh() { this.emitQuery(); },
  },
};
</script>
