<?php

namespace App\Services\WooCommerce;

use App\Models\WooCommerceSetting;

/**
 * Tuning knobs for the WooCommerce sync.
 *
 * These used to be `env('WOO_*')` reads scattered across the jobs, the service
 * and the controllers, which meant a shop owner had to edit .env over SSH to
 * change a batch size or a timeout. They now live in
 * `woocommerce_settings.sync_options` (one JSON column) and are editable from
 * the WooCommerce settings screen.
 *
 * Resolution order per key: saved DB value -> matching env var -> built-in
 * default. The env fallback is deliberate, so installs that already tuned .env
 * keep behaving exactly as before until someone saves the form.
 *
 * Values are clamped to the ranges below, so a bad entry can slow the sync down
 * but cannot break it.
 */
class SyncOptions
{
    /**
     * key => [env var, default, min, max, group, label, hint]
     */
    public const INTEGERS = [
        'products_per_job' => [
            'WOO_PRODUCTS_PER_JOB', 25, 1, 200, 'batching',
            'Products per batch',
            'How many products one sync batch handles. Higher finishes faster but each batch takes longer.',
        ],
        'stock_products_per_job' => [
            'WOO_STOCK_PRODUCTS_PER_JOB', 25, 1, 100, 'batching',
            'Products per stock batch',
            'Same, for the stock sync. Each product is one WooCommerce API call.',
        ],
        'poll_tick_budget_seconds' => [
            'WOO_POLL_TICK_BUDGET_SECONDS', 45, 5, 300, 'batching',
            'Manual sync work per refresh (seconds)',
            'While the sync page is open it processes batches for this long on each refresh. Keep below 60.',
        ],
        'poll_tick_max_seconds' => [
            'WOO_POLL_TICK_MAX_SECONDS', 300, 30, 1800, 'batching',
            'Max script time per refresh (seconds)',
            'PHP time limit raised for those refreshes. Must exceed the value above.',
        ],
        'stuck_seconds' => [
            'WOO_SYNC_STUCK_SECONDS', 600, 60, 3600, 'timeouts',
            'Declare sync stuck after (seconds)',
            'If a running sync stops reporting progress for this long it is marked failed.',
        ],
        'queue_wait_seconds' => [
            'WOO_SYNC_QUEUE_WAIT_SECONDS', 1800, 120, 21600, 'timeouts',
            'Allowed wait between batches (seconds)',
            'Grace period while a batch waits for a worker. Raise it if syncs are wrongly marked stuck.',
        ],
        'http_timeout_ms' => [
            'WOO_TIMEOUT_MS_BATCH', 30000, 1000, 300000, 'timeouts',
            'WooCommerce request timeout (ms)',
            'How long to wait for one WooCommerce API call. Raise on slow hosting.',
        ],
        'http_retries' => [
            'WOO_HTTP_RETRIES', 2, 0, 10, 'retries',
            'Request retries',
            'Retries after a timeout or a temporary WooCommerce error (429/502/503/504).',
        ],
        'http_retry_base_ms' => [
            'WOO_HTTP_RETRY_BASE_MS', 300, 0, 10000, 'retries',
            'Retry backoff (ms)',
            'Base wait before retrying. Doubles on each further attempt.',
        ],
        'max_consecutive_failures' => [
            'WOO_MAX_CONSECUTIVE_FAILURES', 7, 1, 100, 'retries',
            'Abort after consecutive failures',
            'Stops a push run once this many products fail in a row, instead of hammering a broken store.',
        ],
        'media_search_timeout' => [
            'WOO_WP_MEDIA_SEARCH_TIMEOUT', 15, 1, 300, 'media',
            'Media search timeout (seconds)',
            'Looking up an existing image in the WordPress Media Library.',
        ],
        'media_upload_timeout' => [
            'WOO_WP_MEDIA_UPLOAD_TIMEOUT', 240, 1, 600, 'media',
            'Media upload timeout (seconds)',
            'Uploading a product image to WordPress. Raise it for large images or slow hosting.',
        ],
        'media_retries' => [
            'WOO_WP_MEDIA_RETRIES', 2, 0, 10, 'media',
            'Media retries',
            'Retries for a failed image search or upload.',
        ],
        'media_retry_sleep_ms' => [
            'WOO_WP_MEDIA_RETRY_SLEEP_MS', 500, 0, 10000, 'media',
            'Media retry wait (ms)',
            'Pause before retrying an image operation.',
        ],
        'remote_index_page_cap' => [
            'WOO_REMOTE_INDEX_PAGE_CAP', 200, 1, 5000, 'limits',
            'Product index page limit',
            'Max pages read when building the SKU index used to match existing WooCommerce products.',
        ],
        'sku_search_page_cap' => [
            'WOO_SKU_SEARCH_PAGE_CAP', 5, 1, 200, 'limits',
            'SKU search page limit',
            'Max pages scanned when hunting for one product by SKU.',
        ],
        'pull_stats_page_cap' => [
            'WOO_PULL_STATS_PAGE_CAP', 200, 1, 5000, 'limits',
            'Statistics page limit',
            'Max pages read for the "WooCommerce → POS" counters shown on each tab.',
        ],
        'autolink_page_cap' => [
            'WOO_AUTOLINK_PAGE_CAP', 200, 1, 5000, 'limits',
            'Auto-link page limit',
            'Max pages scanned by "Link products by SKU".',
        ],
        'pull_order_notes_max' => [
            'WOO_PULL_ORDER_NOTES_MAX', 25, 0, 500, 'limits',
            'Order notes imported',
            'How many WooCommerce order notes are copied into the imported sale.',
        ],
        'variations_list_page_cap' => [
            'WOO_VARIATIONS_LIST_PAGE_CAP', 100, 1, 1000, 'limits',
            'Variation list page limit',
            'Max pages read when listing a variable product\'s variations.',
        ],
        'pull_variations_page_cap' => [
            'WOO_PULL_VARIATIONS_PAGE_CAP', 50, 1, 1000, 'limits',
            'Variation import page limit',
            'Max pages read when importing variations into Stocky.',
        ],
        'delete_variations_page_cap' => [
            'WOO_DELETE_VARIATIONS_PAGE_CAP', 50, 1, 1000, 'limits',
            'Variation delete page limit',
            'Max pages processed when clearing variations from a WooCommerce product.',
        ],
    ];

    /**
     * key => [env var, default, group, label, hint]
     */
    public const BOOLEANS = [
        'allow_alt_sku_on_lookup_conflict' => [
            'WOO_ALLOW_ALT_SKU_ON_LOOKUP_CONFLICT', false, 'limits',
            'Allow alternate SKU on conflict',
            'When a SKU already belongs to a different WooCommerce product, push under a modified SKU instead of skipping. Leave off unless you know you need it.',
        ],
    ];

    /**
     * key => [env var, default, group, label, hint]
     */
    public const STRINGS = [
        'api_base_url' => [
            'WOO_API_BASE_URL', null, 'advanced',
            'API base URL override',
            'Developer use only: send API calls to this address instead of the store URL (local tunnels/proxies). Leave empty.',
        ],
    ];

    /** Group key => human title, in display order. */
    public const GROUPS = [
        'batching' => 'Batch size and speed',
        'timeouts' => 'Timeouts and stuck detection',
        'retries' => 'Retries',
        'media' => 'Product images (WordPress media)',
        'limits' => 'Lookup limits',
        'advanced' => 'Advanced',
    ];

    /** Resolved values, cached for the life of the process. */
    private static ?array $resolved = null;

    /** Drop the cache after the settings are saved. */
    public static function flush(): void
    {
        self::$resolved = null;
    }

    /**
     * An integer option.
     *
     * $fallbackDefault preserves call sites that historically used a different
     * default than the catalog one; it only applies when neither the DB nor env
     * has a value.
     */
    public static function int(string $key, ?int $fallbackDefault = null): int
    {
        [, $default, $min, $max] = self::INTEGERS[$key] ?? [null, 0, PHP_INT_MIN, PHP_INT_MAX];
        $raw = self::raw($key);

        if ($raw === null || $raw === '') {
            $raw = $fallbackDefault ?? $default;
        }

        return (int) max($min, min($max, (int) $raw));
    }

    public static function bool(string $key): bool
    {
        [, $default] = self::BOOLEANS[$key] ?? [null, false];
        $raw = self::raw($key);

        if ($raw === null || $raw === '') {
            return (bool) $default;
        }
        if (is_bool($raw)) {
            return $raw;
        }

        return in_array(strtolower((string) $raw), ['1', 'true', 'on', 'yes'], true);
    }

    public static function str(string $key): ?string
    {
        [, $default] = self::STRINGS[$key] ?? [null, null];
        $raw = self::raw($key);
        $value = ($raw === null || $raw === '') ? $default : (string) $raw;
        $value = is_string($value) ? trim($value) : null;

        return ($value === null || $value === '') ? null : $value;
    }

    /** Every option resolved to its effective value, for the settings API. */
    public static function all(): array
    {
        $out = [];
        foreach (array_keys(self::INTEGERS) as $key) {
            $out[$key] = self::int($key);
        }
        foreach (array_keys(self::BOOLEANS) as $key) {
            $out[$key] = self::bool($key);
        }
        foreach (array_keys(self::STRINGS) as $key) {
            $out[$key] = self::str($key);
        }

        return $out;
    }

    /** Field descriptors so the settings screen can render the form itself. */
    public static function meta(): array
    {
        $fields = [];
        foreach (self::INTEGERS as $key => [$env, $default, $min, $max, $group, $label, $hint]) {
            $fields[] = compact('key', 'group', 'label', 'hint', 'default', 'min', 'max') + ['type' => 'integer'];
        }
        foreach (self::BOOLEANS as $key => [$env, $default, $group, $label, $hint]) {
            $fields[] = compact('key', 'group', 'label', 'hint', 'default') + ['type' => 'boolean'];
        }
        foreach (self::STRINGS as $key => [$env, $default, $group, $label, $hint]) {
            $fields[] = compact('key', 'group', 'label', 'hint', 'default') + ['type' => 'string'];
        }

        $groups = [];
        foreach (self::GROUPS as $key => $title) {
            $groups[] = ['key' => $key, 'title' => $title];
        }

        return ['groups' => $groups, 'fields' => $fields];
    }

    /** Validation rules for the `sync_options` payload on POST settings. */
    public static function validationRules(): array
    {
        $rules = ['sync_options' => 'nullable|array'];

        foreach (self::INTEGERS as $key => [, , $min, $max]) {
            $rules['sync_options.'.$key] = 'nullable|integer|min:'.$min.'|max:'.$max;
        }
        foreach (array_keys(self::BOOLEANS) as $key) {
            $rules['sync_options.'.$key] = 'nullable|boolean';
        }
        foreach (array_keys(self::STRINGS) as $key) {
            $rules['sync_options.'.$key] = 'nullable|string|max:255';
        }

        return $rules;
    }

    /**
     * Normalise a submitted payload: drop unknown keys, clamp numbers, and store
     * nothing for blanks so the option falls back to env/default again.
     */
    public static function sanitize(?array $input): array
    {
        $out = [];
        if (! is_array($input)) {
            return $out;
        }

        foreach (self::INTEGERS as $key => [, , $min, $max]) {
            if (! array_key_exists($key, $input) || $input[$key] === null || $input[$key] === '') {
                continue;
            }
            $out[$key] = (int) max($min, min($max, (int) $input[$key]));
        }
        foreach (array_keys(self::BOOLEANS) as $key) {
            if (! array_key_exists($key, $input) || $input[$key] === null || $input[$key] === '') {
                continue;
            }
            $out[$key] = filter_var($input[$key], FILTER_VALIDATE_BOOLEAN);
        }
        foreach (array_keys(self::STRINGS) as $key) {
            if (! array_key_exists($key, $input)) {
                continue;
            }
            $value = is_string($input[$key]) ? trim($input[$key]) : '';
            if ($value !== '') {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /** Saved value, else env, else null. */
    private static function raw(string $key)
    {
        if (self::$resolved === null) {
            self::$resolved = self::loadSaved();
        }

        if (array_key_exists($key, self::$resolved)) {
            return self::$resolved[$key];
        }

        $env = self::INTEGERS[$key][0] ?? self::BOOLEANS[$key][0] ?? self::STRINGS[$key][0] ?? null;
        if ($env === null) {
            return null;
        }

        $value = env($env);

        return ($value === null || $value === '') ? null : $value;
    }

    private static function loadSaved(): array
    {
        try {
            $settings = WooCommerceSetting::query()->first();
            $saved = $settings ? $settings->sync_options : null;

            if (is_string($saved)) {
                $saved = json_decode($saved, true);
            }

            return is_array($saved) ? $saved : [];
        } catch (\Throwable $e) {
            // Table may not exist yet (fresh install, mid-migration).
            return [];
        }
    }
}
