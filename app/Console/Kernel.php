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
        // Hostinger's PHP has no proc_open / exec (2026-10-27): `schedule:run` couldn't start a single
        // command (they showed DONE in 1 ms and never ran). Without proc_open each task runs
        // inside schedule:run itself (Artisan::call); withoutOverlapping still guards them.
        $cmd = fn (string $command) => function_exists('proc_open')
            ? $schedule->command($command)
            : $schedule->call(fn () => \Illuminate\Support\Facades\Artisan::call($command))->name($command);

        // Server backup (2026-10-27): once a day, gzipped, outside the website folder, 14 kept
        // (MuranoServerBackup). database:backup (Stocky's) had no frequency = every minute.
        $cmd('murano:server-backup')->dailyAt('03:15')->withoutOverlapping(60);
        // Yesterday's errors in the log -> email to the SuperAdmin (only when there are any).
        $cmd('murano:error-digest')->dailyAt('07:00')->withoutOverlapping();
        // Install-wide integrations only while some company uses them (ModuleFlags::anyCompany).
        $uses = fn (string $key) => fn () => \App\Support\ModuleFlags::anyCompany($key);

        $cmd('assets:check-validation-due')->daily();
        // Sales Contracts: recurring invoices whose date has come.
        $cmd('sales-contracts:invoice')->dailyAt('06:00')->withoutOverlapping();
        // Recurring expenses (rent, internet...): the ones whose date has come.
        $cmd('expenses:recurring')->dailyAt('06:10')->withoutOverlapping();

        $cmd('meetings:send-reminders')->everyMinute()->withoutOverlapping();
        // Bookings: reminder emails before appointments (Settings -> Booking -> Reminders).
        $cmd('bookings:send-reminders')->everyFiveMinutes()->withoutOverlapping();
        // Hospital: appointment reminders to patients, 24 h before.
        $cmd('hospital:appointment-reminders')->everyFiveMinutes()->withoutOverlapping();
        // Hospital: yesterday's unrecorded doses -> missed (not given).
        $cmd('hospital:mark-missed-doses')->dailyAt('00:05')->withoutOverlapping();
        // Hospital demos: today's roster, assignments and example stays.
        $cmd('hospital:demo-day')->dailyAt('00:15')->withoutOverlapping();

        $cmd('marketing:process-scheduled')->everyMinute()->withoutOverlapping();

        // Helpdesk: import new emails from each company's mailbox into tickets.
        $cmd('helpdesk:fetch-mail')->everyFiveMinutes()->withoutOverlapping();
        // Email Office: each company's mailbox, at its own "check every" interval.
        $cmd('email-office:fetch')->everyMinute()->withoutOverlapping(30);

        // Keep integration OAuth connections alive: Salla refresh tokens die
        // after 1 month idle, Xero's after 60 days. A daily authenticated ping
        // refreshes them long before either cliff.
        $cmd('integrations:keep-alive')->daily()->withoutOverlapping()
            ->when(fn () => collect(['salla', 'xero', 'quickbooks', 'shopify', 'google_sheets'])->contains(fn ($k) => \App\Support\ModuleFlags::anyCompany($k)));

        // Nightly Google Sheets snapshot when auto-export is enabled (kept off
        // peak hours; full-tab rewrites can take a while on big datasets).
        $cmd('google-sheets:auto-export')->dailyAt('02:30')->withoutOverlapping()->when($uses('google_sheets'));

        // WooCommerce push (Stocky -> Woo): push only not-yet-linked products
        // nightly; stock changes often, so sync it hourly.
        $cmd('woocommerce:sync --scope=products --only-unsynced')
            ->dailyAt('02:00')
            ->withoutOverlapping()->when($uses('woocommerce_settings'));

        $cmd('woocommerce:sync --scope=stock')
            ->hourly()
            ->withoutOverlapping()->when($uses('woocommerce_settings'));

        // WooCommerce pull (Woo -> Stocky). Orders run often so online sales land in
        // Stocky quickly and are incremental (only orders changed since the last run).
        // Products and customers are full scans, so keep them to once a night.
        $cmd('woocommerce:sync --scope=orders')
            ->everyFifteenMinutes()
            ->withoutOverlapping(30)->when($uses('woocommerce_settings'));

        $cmd('woocommerce:sync --scope=pull-products')
            ->dailyAt('03:00')
            ->withoutOverlapping(120)->when($uses('woocommerce_settings'));

        $cmd('woocommerce:sync --scope=customers')
            ->dailyAt('03:30')
            ->withoutOverlapping(120)->when($uses('woocommerce_settings'));

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
        $cmd('queue:work database --once --queue=default,webhooks --sleep=1 --tries=1 --timeout='.((int) env('QUEUE_WORKER_TIMEOUT', 1200)))
            ->everyMinute()
            ->withoutOverlapping()
            ->evenInMaintenanceMode();

        // Woo batches re-dispatch themselves one slice at a time, so --once would move a
        // sync forward by a single batch per minute. --stop-when-empty + --max-time keeps
        // draining for most of the minute and then exits, which the next tick restarts.
        $cmd('queue:work database --queue='.SyncQueue::NAME.' --stop-when-empty --max-time=55 --sleep=1 --tries=1 --timeout='.((int) env('QUEUE_WORKER_TIMEOUT', 1200)))
            ->everyMinute()
            ->withoutOverlapping(10)
            ->evenInMaintenanceMode()->when($uses('woocommerce_settings'));

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
