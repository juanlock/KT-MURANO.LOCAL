<?php

namespace App\Console;

use App\Services\WooCommerce\SyncQueue;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
        'App\Console\Commands\DatabaseBackUp',
        'App\Console\Commands\WooCommerceSync',
        'App\\Console\\Commands\\WooCommercePushProducts',
        'App\Console\Commands\SendMeetingReminders',
        'App\Console\Commands\ProcessScheduledCampaigns',
    ];

    /**
     * Define the application's command schedule.
     *
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {

        $schedule->command('database:backup');

        $schedule->command('assets:check-validation-due')->daily();

        $schedule->command('meetings:send-reminders')->everyMinute()->withoutOverlapping();

        $schedule->command('marketing:process-scheduled')->everyMinute()->withoutOverlapping();

        // Keep integration OAuth connections alive: Salla refresh tokens die
        // after 1 month idle, Xero's after 60 days. A daily authenticated ping
        // refreshes them long before either cliff.
        $schedule->command('integrations:keep-alive')->daily()->withoutOverlapping();

        // Nightly Google Sheets snapshot when auto-export is enabled (kept off
        // peak hours; full-tab rewrites can take a while on big datasets).
        $schedule->command('google-sheets:auto-export')->dailyAt('02:30')->withoutOverlapping();

        // WooCommerce push (Stocky -> Woo): push only not-yet-linked products
        // nightly; stock changes often, so sync it hourly.
        $schedule->command('woocommerce:sync --scope=products --only-unsynced')
            ->dailyAt('02:00')
            ->withoutOverlapping();

        $schedule->command('woocommerce:sync --scope=stock')
            ->hourly()
            ->withoutOverlapping();

        // WooCommerce pull (Woo -> Stocky). Orders run often so online sales land in
        // Stocky quickly and are incremental (only orders changed since the last run).
        // Products and customers are full scans, so keep them to once a night.
        $schedule->command('woocommerce:sync --scope=orders')
            ->everyFifteenMinutes()
            ->withoutOverlapping(30);

        $schedule->command('woocommerce:sync --scope=pull-products')
            ->dailyAt('03:00')
            ->withoutOverlapping(120);

        $schedule->command('woocommerce:sync --scope=customers')
            ->dailyAt('03:30')
            ->withoutOverlapping(120);

        /**
         * Shared hosting friendly queue processing:
         * Drain the queue each minute (database driver) instead of running a daemon.
         *
         * IMPORTANT: You must have a cron that runs `php artisan schedule:run` every minute.
         * Without it, Woo sync batches stall at "queued_next_batch" and only advance while
         * someone keeps the WooCommerce settings page open (it ticks one batch per poll).
         */
        // 'webhooks' carries outgoing webhooks, Slack/Telegram notifications and
        // the Mailchimp/Xero auto-sync jobs — it must drain here too, or none of
        // them ever run on cron-only (no Supervisor) hosting.
        $schedule->command('queue:work database --once --queue=default,webhooks --sleep=1 --tries=1 --timeout='.((int) env('QUEUE_WORKER_TIMEOUT', 1200)))
            ->everyMinute()
            ->withoutOverlapping()
            ->evenInMaintenanceMode();

        // Woo batches re-dispatch themselves one slice at a time, so --once would move a
        // sync forward by a single batch per minute. --stop-when-empty + --max-time keeps
        // draining for most of the minute and then exits, which the next tick restarts.
        $schedule->command('queue:work database --queue='.SyncQueue::NAME.' --stop-when-empty --max-time=55 --sleep=1 --tries=1 --timeout='.((int) env('QUEUE_WORKER_TIMEOUT', 1200)))
            ->everyMinute()
            ->withoutOverlapping(10)
            ->evenInMaintenanceMode();

    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
