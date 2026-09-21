import { ref, computed, onMounted } from 'vue';
import { useAuthStore } from '../stores/auth';
import { useFormat } from './useFormat';
import http from '../lib/http';

/**
 * Multi-Currency selector for document DETAIL pages.
 *
 * The show endpoints deliver amounts ALREADY converted into the document's
 * currency, along with currency_symbol / currency_code / currency_id /
 * currency_rate. This composable renders those amounts (default = the
 * document's own currency) and offers a view-only selector to re-express
 * them in any other currency:
 *
 *   base = doc_amount / doc_rate;   shown = base × selected_rate
 *
 * Independent of the reports' shared display-currency state on purpose —
 * a detail page always opens in its document's currency.
 *
 *   const { docMoney, currencyOptions, currencySelectId, showCurrencySelect }
 *     = useDetailsCurrency(sale); // the page's show-payload ref
 */
export function useDetailsCurrency(payloadRef) {
    const auth = useAuthStore();
    const { moneyBase, number } = useFormat();

    const currencies = ref([]);
    const baseId = ref(null);
    const selectedId = ref(null); // null = the document's currency (default)

    onMounted(async () => {
        if (!auth.multiCurrencyEnabled) return;
        try {
            const data = await http.get('currencies_list');
            currencies.value = data?.currencies || [];
            baseId.value = data?.default_currency_id ?? auth.defaultCurrencyId ?? null;
        } catch (e) {
            currencies.value = [];
        }
    });

    const currencyOptions = computed(() =>
        currencies.value.map(c => ({ value: c.id, label: c.code })));

    const showCurrencySelect = computed(() =>
        auth.multiCurrencyEnabled && currencyOptions.value.length > 1);

    // The select's model: before any pick it shows the document's currency.
    const currencySelectId = computed({
        get: () => selectedId.value
            ?? payloadRef.value?.currency_id
            ?? baseId.value,
        set: (v) => { selectedId.value = v; },
    });

    function docMoney(v, dec) {
        const doc = payloadRef.value || {};
        const docCurrencyId = doc.currency_id ?? baseId.value;

        // Default, or the document's own currency picked: render as delivered.
        if (selectedId.value === null
            || Number(selectedId.value) === Number(docCurrencyId ?? 0)) {
            const sym = doc.currency_symbol;
            return sym ? `${sym} ${number(v, dec)}` : moneyBase(v, dec);
        }

        // Re-express: back to base at the document's snapshotted rate, then
        // into the selected currency at its current rate.
        const base = (Number(v) || 0) / (Number(doc.currency_rate) || 1);
        const sel = currencies.value.find(c => Number(c.id) === Number(selectedId.value));
        if (!sel || Number(selectedId.value) === Number(baseId.value)) {
            return moneyBase(base, dec);
        }
        const rate = Number(sel.exchange_rate) || 1;
        return `${sel.symbol} ${number(base * rate, dec)}`;
    }

    return { docMoney, currencyOptions, currencySelectId, showCurrencySelect };
}
