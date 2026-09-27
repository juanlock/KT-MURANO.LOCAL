import { ref } from 'vue';
import http from '../lib/http';

/**
 * Country reference data (see App\Console\Commands\ImportGeoData) -- 249
 * rows, small enough to load once and share across every form that needs a
 * Country dropdown (Quick Add Customer today; more to follow).
 */
const countries = ref([]);
let loaded = false;
let loading = null;

export function useCountries() {
    async function ensureLoaded() {
        if (loaded) return;
        if (!loading) {
            loading = http.get('countries')
                .then(data => {
                    countries.value = data?.countries || [];
                    loaded = true;
                })
                .catch(() => {
                    countries.value = [];
                })
                .finally(() => {
                    loading = null;
                });
        }
        await loading;
    }

    return { countries, ensureLoaded };
}
