import { computed } from 'vue';

/**
 * "Reset filters" for a list page's filter row (paired with
 * components/ResetFiltersButton.vue). Snapshots the filters' DEFAULT values
 * when called -- so call it right after `const filters = ref({...})`, before
 * any deep link / preset writes into them -- and restores that snapshot on
 * reset, then runs `onReset` (usually crud.reload()).
 *
 * `isDirty` drives the button's enabled state. Empty values compare equal
 * whatever their shape: a cleared select (undefined), the "All" option ('')
 * and null all mean "no filter".
 *
 * @param {import('vue').Ref<object>} filters
 * @param {() => void} [onReset]
 * @param {{ isDirty?: () => boolean, ignore?: string[] }} [extra]
 *   isDirty: page state outside `filters` (e.g. a date-preset button group)
 *   that also counts as "filtered"; ignore: filter keys left out of the
 *   comparison because that state drives them (e.g. date_from/date_to
 *   filled by a preset).
 */
export function useFilterReset(filters, onReset, extra = {}) {
  const ignore = new Set(extra.ignore || []);
  const clone = v => JSON.parse(JSON.stringify(v ?? {}));
  const initial = clone(filters.value);

  const norm = v => (v === undefined || v === null || v === '' ? null : v);
  const same = (a, b) => JSON.stringify(norm(a)) === JSON.stringify(norm(b));

  const isDirty = computed(() => {
    const cur = filters.value || {};
    const keys = new Set([...Object.keys(initial), ...Object.keys(cur)]);
    for (const k of keys) {
      if (!ignore.has(k) && !same(cur[k], initial[k])) return true;
    }
    return !!extra.isDirty?.();
  });

  function resetFilters() {
    filters.value = clone(initial);
    onReset?.();
  }

  return { isDirty, resetFilters };
}
