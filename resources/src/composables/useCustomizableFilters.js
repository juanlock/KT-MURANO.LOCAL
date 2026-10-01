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

  // A saved order + the page's filters that aren't in it yet (e.g. a filter
  // added after the company last customized). A missing filter goes where
  // the built-in default order puts it relative to its neighbours -- right
  // after the nearest earlier default filter that is present, or before the
  // first later one -- instead of always at the end (a new "first" filter,
  // like Products' Type Product, would otherwise land last for every
  // company that saved an order before it existed).
  function mergeWithDefaults(order) {
    const out = order.filter((k, i) => labelByKey.has(k) && order.indexOf(k) === i);
    defaultKeys.forEach((k, i) => {
      if (out.includes(k)) return;
      for (let j = i - 1; j >= 0; j--) {
        const at = out.indexOf(defaultKeys[j]);
        if (at >= 0) { out.splice(at + 1, 0, k); return; }
      }
      const later = out.findIndex(x => defaultKeys.indexOf(x) > i);
      if (later >= 0) out.splice(later, 0, k); else out.push(k);
    });
    return out;
  }

  // Ordered, visible keys for the actual filter row (saved hidden ones
  // dropped; filters new since the save are visible).
  const visibleFilterKeys = computed(() => {
    const cfg = savedCfg.value;
    if (! cfg) return [...defaultKeys];
    const hidden = new Set(cfg.filter(e => e.hidden).map(e => e.key));
    return mergeWithDefaults(cfg.map(e => e.key)).filter(k => ! hidden.has(k));
  });

  const customizeOpen = ref(false);
  const customizeSaving = ref(false);
  const customizeRows = ref([]);

  function openCustomize() {
    const cfg = savedCfg.value;
    const hiddenByKey = new Map((cfg || []).map(c => [c.key, !!c.hidden]));
    const order = cfg ? mergeWithDefaults(cfg.map(c => c.key)) : [...defaultKeys];
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
