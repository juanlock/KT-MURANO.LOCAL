import { defineStore } from 'pinia';
import http from '../lib/http';

/**
 * Everything awaiting staff action, from GET /pending-work.
 *
 * One fetch feeds two surfaces: the topbar bell (the list) and the sidebar
 * (the same numbers as badges). Counts are derived server-side from live rows,
 * so an entry disappears on the next refresh once the work is done — there is
 * nothing to mark as read.
 */
export const usePendingWorkStore = defineStore('pendingWork', {
    state: () => ({
        items: [],
        total: 0,
        loading: false,
        loadedAt: 0,
    }),

    getters: {
        /** { product_reviews: 3, messages: 1, … } for the sidebar badges. */
        byKey: (state) => Object.fromEntries(state.items.map(i => [i.key, i.count])),
    },

    actions: {
        /**
         * `force` skips the 60s throttle — used after an action that resolves
         * work, so the badge drops immediately instead of on the next poll.
         */
        async fetch(force = false) {
            if (this.loading) return;
            if (!force && Date.now() - this.loadedAt < 60000) return;

            this.loading = true;
            try {
                const data = await http.get('pending-work');
                this.items = Array.isArray(data?.items) ? data.items : [];
                this.total = Number(data?.total) || 0;
                this.loadedAt = Date.now();
            } catch (e) {
                // A failed poll must never break the shell — keep the last counts.
            } finally {
                this.loading = false;
            }
        },

        reset() {
            this.items = [];
            this.total = 0;
            this.loadedAt = 0;
        },
    },
});
