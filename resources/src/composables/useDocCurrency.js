import { ref, computed } from 'vue';
import { useAuthStore } from '../stores/auth';
import { useFormat } from './useFormat';
import http from '../lib/http';

/**
 * Multi-Currency document picker shared by the sale / purchase / quotation
 * forms and the POS.
 *
 * Conventions (must match the backend, see app/utils/helpers.php):
 * - rate = units of the DOCUMENT currency per 1 base-currency unit
 * - every amount in the component's internal model stays in the BASE currency;
 *   only what the user sees (and types) is converted, so totals, stock math,
 *   credit limits and the submit payload are untouched by the feature.
 *
 *   const dc = useDocCurrency();
 *   await dc.loadCurrencies();
 *   dc.docMoney(baseAmount)          // "€ 12,50" or the global money() when base
 *   dc.toBase(typedValue)            // parse an input typed in the doc currency
 *   { ...payload, ...dc.payloadFields() }
 */
export function useDocCurrency() {
    const auth = useAuthStore();
    // moneyBase, not money: document forms must not follow the report pages'
    // display-currency selector — base documents always render in base.
    const { moneyBase, number } = useFormat();

    // Module toggle AND the 'multi_currency' permission — users without it
    // never see the picker and their documents stay in the base currency.
    const enabled = computed(() => auth.multiCurrencyEnabled && auth.can('multi_currency'));
    const currencies = ref([]);
    const currencyId = ref(null);
    // Rate snapshotted on the document being edited: it wins over the current
    // list rate for that currency so reopening an old document reproduces the
    // amounts it was recorded with.
    const storedRate = ref(null);
    const storedCurrencyId = ref(null);

    async function loadCurrencies() {
        if (!enabled.value) return;
        try {
            const data = await http.get('currencies_list');
            currencies.value = data?.currencies || [];
            if (!currencyId.value) {
                currencyId.value = data?.default_currency_id ?? auth.defaultCurrencyId ?? null;
            }
        } catch (e) {
            currencies.value = [];
        }
    }

    const isBase = computed(() =>
        !enabled.value
        || !currencyId.value
        || Number(currencyId.value) === Number(auth.defaultCurrencyId));

    const current = computed(() =>
        currencies.value.find(c => Number(c.id) === Number(currencyId.value)) || null);

    const rate = computed(() => {
        if (isBase.value) return 1;
        if (storedRate.value && Number(storedCurrencyId.value) === Number(currencyId.value)) {
            return Number(storedRate.value) || 1;
        }
        return Number(current.value?.exchange_rate) || 1;
    });

    const symbol = computed(() => (isBase.value ? null : (current.value?.symbol || '')));

    const options = computed(() =>
        currencies.value.map(c => ({ value: c.id, label: `${c.code} — ${c.name}` })));

    /** base → document currency (for display / input prefill) */
    const toDoc = v => (Number(v) || 0) * rate.value;
    /** document → base (parse what the user typed) */
    const toBase = v => (Number(v) || 0) / (rate.value || 1);

    /** Format a BASE amount in the document currency. */
    function docMoney(v, dec) {
        if (isBase.value) return moneyBase(v, dec);
        const formatted = number(toDoc(v), dec);
        return symbol.value ? `${symbol.value} ${formatted}` : formatted;
    }

    /** Adopt the currency snapshot stored on a document being edited. */
    function setFromDocument(cid, xrate) {
        if (!cid) return;
        currencyId.value = cid;
        storedCurrencyId.value = cid;
        storedRate.value = Number(xrate) || null;
    }

    /**
     * Fields to merge into the submit payload. Base documents send explicit
     * nulls so an edit can move a document back to the base currency.
     */
    function payloadFields() {
        if (!enabled.value) return {};
        return isBase.value
            ? { currency_id: null, exchange_rate: null }
            : { currency_id: currencyId.value, exchange_rate: rate.value };
    }

    return {
        enabled, currencies, currencyId, options, isBase, rate, symbol,
        toDoc, toBase, docMoney, loadCurrencies, setFromDocument, payloadFields,
    };
}
