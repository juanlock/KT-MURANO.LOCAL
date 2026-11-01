<?php

namespace App\Console\Commands;

use App\Jobs\SendMarketingCampaign;
use App\Models\MarketingCampaign;
use App\Models\MarketingSetting;
use App\Support\ModuleFlags;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ProcessScheduledCampaigns extends Command
{
    protected $signature = 'marketing:process-scheduled';

    protected $description = 'Dispatch marketing campaigns whose scheduled send time has arrived';

    public function handle()
    {
        $settings = MarketingSetting::current();

        if (! $settings->scheduling_enabled) {
            $this->info('Campaign scheduling is disabled.');

            return 0;
        }

        $now = Carbon::now();

        $campaigns = MarketingCampaign::where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', $now)
            ->get();

        foreach ($campaigns as $campaign) {
            // Only companies that use Marketing (2026-11-01, after the demo campaigns of
            // 2026-10-27 went out on their own): Business Modules switch "marketing" off = never sent.
            if (! $campaign->company_id || ! ModuleFlags::enabled((int) $campaign->company_id, 'marketing')) {
                $this->info('Skipped campaign #'.$campaign->id.': Marketing is off for its company.');

                continue;
            }
            SendMarketingCampaign::dispatch($campaign->id);
            $this->info('Queued campaign #'.$campaign->id.' ('.$campaign->title.').');
        }

        $this->info(sprintf('Processed %d scheduled campaign(s).', $campaigns->count()));

        return 0;
    }
}
