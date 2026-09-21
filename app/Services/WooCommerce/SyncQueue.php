<?php

namespace App\Services\WooCommerce;

/**
 * Fixed queue name shared by every WooCommerce sync batch.
 *
 * Batches used to go to a per-run queue (woocommerce-sync-{syncJobId} for
 * products, woocommerce-stock-{token} for stock). Laravel cannot match queue
 * names by wildcard, so neither the scheduled worker nor Supervisor could ever
 * drain them: a sync only advanced while the browser sat on the WooCommerce
 * settings page, ticking one batch per progress poll. Close the tab and the run
 * froze at "queued_next_batch" until the stuck detector failed it.
 *
 * With one fixed name a plain `queue:work --queue=woocommerce` picks up every
 * batch, and the inline browser tick keeps working as a no-cron fallback.
 */
class SyncQueue
{
    /** Queue every WooCommerce batch job is dispatched to. */
    public const NAME = 'woocommerce';

    /** Queue connection used for WooCommerce batch jobs. */
    public const CONNECTION = 'database';
}
