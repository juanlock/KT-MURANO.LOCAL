<?php

namespace App\Console\Commands;

use App\Jobs\SendMarketingCampaign;
use App\Models\MarketingCampaign;
use App\Models\MarketingSetting;
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
            SendMarketingCampaign::dispatch($campaign->id);
            $this->info('Queued campaign #'.$campaign->id.' ('.$campaign->title.').');
        }

        $this->info(sprintf('Processed %d scheduled campaign(s).', $campaigns->count()));

        return 0;
    }
}
