import { ref, computed } from 'vue';
import { message } from 'ant-design-vue';
import http from '../lib/http';
import { t as tf } from '../i18n';

/**
 * Generic batch loop for cursor-based connector sync endpoints (Salla, Xero, …)
 * — same contract as useShopifySync, minus the store id.
 *
 * Each call processes one bounded batch and answers `has_more` plus a cursor
 * (`last_id` for local pushes, `next_page_info` for remote pulls). run() keeps
 * calling until done; cancel() stops after the batch in flight.
 */
export function useBatchSync() {
    const running = ref(false);
    const cancelRequested = ref(false);
    const counters = ref({});
    const batchErrors = ref([]);
    const finishedAt = ref(null);
    const hasCounters = computed(() => Object.keys(counters.value).length > 0);

    function reset() {
        counters.value = {};
        batchErrors.value = [];
        finishedAt.value = null;
    }
    function cancel() {
        cancelRequested.value = true;
    }
    function accumulate(data) {
        const next = { ...counters.value };
        for (const key of ['processed', 'created', 'updated', 'failed', 'skipped', 'imported']) {
            if (typeof data[key] === 'number') next[key] = (next[key] || 0) + data[key];
        }
        counters.value = next;
        if (Array.isArray(data.errors) && data.errors.length) {
            batchErrors.value = batchErrors.value.concat(data.errors).slice(-50);
        }
    }

    /**
     * @param {string} url    e.g. 'salla/sync/products?mode=push'
     * @param {object} params extra body params
     * @param {string} kind   'push' (cursor: last_id) or 'pull' (cursor: next_page_info)
     */
    async function run(url, params, kind) {
        if (running.value) return;
        running.value = true;
        cancelRequested.value = false;
        reset();
        const body = { ...(params || {}) };
        try {
            for (;;) {
                const data = await http.post(url, body);
                accumulate(data || {});
                if (cancelRequested.value || !data?.has_more) break;
                if (kind === 'push') body.start_after_id = data.last_id;
                else body.page_info = data.next_page_info;
            }
            finishedAt.value = new Date();
            const failed = counters.value.failed || 0;
            const text = cancelRequested.value ? tf('Sync_cancelled', 'Sync cancelled') : tf('Sync_completed', 'Sync completed');
            (failed ? message.warning : message.success)(text);
        } catch (e) {
            message.error(e?.data?.error || e?.data?.message || tf('Sync_failed', 'Sync failed'));
        } finally {
            running.value = false;
        }
    }

    return { running, counters, batchErrors, finishedAt, hasCounters, run, cancel, reset };
}

export const COUNTER_LABELS = {
    processed: 'Processed', created: 'Created', updated: 'Updated',
    failed: 'Failed', skipped: 'Skipped', imported: 'Imported',
};
