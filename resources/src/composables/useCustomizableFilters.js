import { ref, computed } from 'vue';
import { message } from 'ant-design-vue';
import http from '../lib/http';
import { useAuthStore } from '../stores/auth';

/**
 * "Customize Filters" -- the filter-row analog of DataTable.vue's
 * "Customize Columns" (components/DataTable.vue, backed by
 * settings.datatable_columns / PUT datatable_columns). Same idea, same
 * `datatable_customize` permission, same per-company storage shape
 * ({tableKey: [{key, hidden}, ...] in display order}) via
 * settings.datatable_filters / PUT datatable_filters -- just for a page's
 * filter inputs instead of its table's columns, since (unlike columns)
 * there's no shared `<DataTable>`-style wrapper for filter rows to hook
 * into; each list page hand-writes its own, so this composable is what
 * Products/Sales/Purchases each call directly.
 *
 * @param {string} tableKey - stable per-list key, e.g. 'products_filters'.
 * @param {Array<{key: string, label: string}>} defaultFilters - the page's
 *   full set of filters in their built-in default order.
 */
export function useCustomizableFilters(tableKey, defaultFilters) {
  const auth = useAuthStore();
  const defaultKeys = defaultFilters.map(f => f.key);
  const labelByKey = new Map(defaultFilters.map(f => [f.key, f.label]));

  const savedCfg = computed(() => {
    const cfg = auth.datatableFilters?.[tableKey];
    return Array.isArray(cfg) && cfg.length ? cfg : null;
  });

  // Ordered, visible keys for the actual filter row: saved order for known
  // keys (dropping hidden ones), then any of the page's own filters that
  // aren't in the saved config yet (e.g. a filter added after the company
  // last customized) appended at the end -- same forward-compat rule as
  // DataTable.vue's customizedColumns().
  const visibleFilterKeys = computed(() => {
    const cfg = savedCfg.value;
    if (! cfg) return [...defaultKeys];
    const seen = new Set();
    const out = [];
    cfg.forEach(entry => {
      if (! labelByKey.has(entry.key) || seen.has(entry.key)) return;
      seen.add(entry.key);
      if (! entry.hidden) out.push(entry.key);
    });
    defaultKeys.forEach(k => { if (! seen.has(k)) out.push(k); });

    return out;
  });

  const customizeOpen = ref(false);
  const customizeSaving = ref(false);
  const customizeRows = ref([]);

  function openCustomize() {
    const cfg = savedCfg.value;
    const hiddenByKey = new Map((cfg || []).map(c => [c.key, !!c.hidden]));
    const order = cfg
      ? cfg.filter(c => labelByKey.has(c.key)).map(c => c.key)
      : [...defaultKeys];
    defaultKeys.forEach(k => { if (! order.includes(k)) order.push(k); });
    customizeRows.value = order.map(key => ({
      key,
      label: labelByKey.get(key) || key,
      visible: ! hiddenByKey.get(key),
    }));
    customizeOpen.value = true;
  }

  function moveRow(index, direction) {
    const target = index + direction;
    if (target < 0 || target >= customizeRows.value.length) return;
    const rows = [...customizeRows.value];
    [rows[index], rows[target]] = [rows[target], rows[index]];
    customizeRows.value = rows;
  }

  async function persistCustomize(rows) {
    customizeSaving.value = true;
    try {
      const payload = rows ? rows.map(r => ({ key: r.key, hidden: ! r.visible })) : null;
      const res = await http.put('datatable_filters', { table_key: tableKey, filters: payload });
      if (auth.user) auth.user.datatable_filters = res?.datatable_filters || {};
      customizeOpen.value = false;
      message.success('Saved');
    } catch (e) {
      message.error(e?.data?.message || 'Could not save');
    } finally {
      customizeSaving.value = false;
    }
  }

  function saveCustomize() {
    return persistCustomize(customizeRows.value);
  }

  function resetCustomize() {
    return persistCustomize(null);
  }

  return {
    visibleFilterKeys,
    customizeOpen,
    customizeSaving,
    customizeRows,
    openCustomize,
    moveRow,
    saveCustomize,
    resetCustomize,
  };
}
