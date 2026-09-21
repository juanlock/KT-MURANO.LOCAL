/**
 * Temporary stubs for POS sub-systems that port in later batches. Each
 * renders nothing and answers the API surface the page touches, so the
 * ported main screen runs without them. Tracked as parity gaps:
 * - ModernPaymentModal → batch 4 (payment flow)
 * - PosReturnModal     → batch 6 (product returns)
 * - CustomFieldsForm   → batch 6 (quick-add customer extras)
 */
import { defineComponent } from 'vue';

const stub = name => defineComponent({
    name,
    inheritAttrs: false,
    setup(_, { expose }) {
        expose({ show: () => {}, hide: () => {}, open: () => {}, close: () => {} });
        return () => null;
    },
});

export const ModernPaymentModal = stub('ModernPaymentModal');
export const PosReturnModal = stub('PosReturnModal');
export const CustomFieldsForm = stub('CustomFieldsForm');
