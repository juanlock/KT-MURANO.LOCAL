<?php

namespace App\Services\DemoData\Modules;

use App\Services\DemoData\DemoContext;
use App\Services\DemoData\DemoModule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Marketing examples: message templates, a customer segment, campaigns
 * (sent / scheduled / draft) with recipients for the sent one, and activity
 * logs. Only campaigns carry company_id; templates and segments are
 * install-wide, so detection uses campaigns alone. Nothing is actually sent.
 */
class MarketingModule extends DemoModule
{
    public function key(): string
    {
        return 'marketing';
    }

    public function label(): string
    {
        return 'Marketing';
    }

    public function dataTables(): array
    {
        return ['marketing_campaigns'];
    }

    public function requiredTables(): array
    {
        return ['marketing_templates', 'marketing_segments', 'marketing_campaigns', 'marketing_campaign_recipients', 'marketing_activity_logs'];
    }

    public function generate(DemoContext $ctx): array
    {
        $counts = [];

        $templates = [];
        foreach ([
            ['Welcome SMS', 'sms', 'onboarding', null, 'Hi {name}, welcome! Enjoy 10% off your next visit.'],
            ['Monthly Newsletter', 'email', 'newsletter', 'What\'s new this month', "Hello {name},\n\nHere are this month's new arrivals and offers.\n\nSee you soon!"],
            ['Flash Sale WhatsApp', 'whatsapp', 'promotion', null, 'Hi {name}! 24h flash sale: up to 30% off. Don\'t miss it.'],
        ] as [$name, $type, $cat, $subject, $content]) {
            $templates[$type] = $ctx->insert('marketing_templates', [
                'name' => $name, 'type' => $type, 'category' => $cat, 'subject' => $subject,
                'content' => $content, 'created_by' => $ctx->userId,
            ]);
        }
        $counts['Templates'] = count($templates);

        // Recipients: the company's customers when available, else sample contacts.
        $contacts = DB::table('clients')->where('company_id', $ctx->companyId)->whereNull('deleted_at')
            ->where('name', '!=', 'Walk-in Customer')->orderBy('id')->limit(5)
            ->get(['id', 'name', 'phone', 'email'])
            ->map(fn ($c) => ['client_id' => $c->id, 'name' => $c->name, 'phone' => $c->phone, 'email' => $c->email])->all();
        if (! $contacts) {
            for ($i = 0; $i < 5; $i++) {
                [$f, $l] = $ctx->personName();
                $contacts[] = ['client_id' => null, 'name' => "$f $l", 'phone' => '+1 555 '.random_int(100, 999).' '.random_int(1000, 9999),
                    'email' => strtolower("$f.$l").'.'.random_int(100, 999).'@example.com'];
            }
        }

        $segmentId = $ctx->insert('marketing_segments', [
            'name' => 'All customers (demo)',
            'description' => 'Every customer of the store',
            'all_customers' => 1,
            'filters' => json_encode([]),
            'customers_count' => count($contacts),
            'created_by' => $ctx->userId,
        ]);
        $counts['Segments'] = 1;

        $n = count($contacts);
        $campaigns = [
            ['Summer Sale Announcement', 'sms', 'sent', Carbon::now()->subDays(10)],
            ['September Newsletter', 'email', 'scheduled', Carbon::now()->addDays(4)],
            ['VIP Flash Sale', 'whatsapp', 'draft', null],
        ];
        $counts['Campaigns'] = 0;
        $counts['Recipients'] = 0;
        foreach ($campaigns as [$title, $type, $status, $when]) {
            $sent = $status === 'sent';
            $failed = $sent ? 1 : 0;
            $content = DB::table('marketing_templates')->where('id', $templates[$type])->value('content');
            $campaignId = $ctx->insert('marketing_campaigns', [
                'title' => $title,
                'description' => $title.' campaign.',
                'type' => $type,
                'subject' => $type === 'email' ? 'What\'s new this month' : null,
                'message_content' => $content,
                'template_id' => $templates[$type],
                'segment_id' => $segmentId,
                'all_customers' => 0,
                'status' => $status,
                'send_immediately' => $status === 'scheduled' ? 0 : 1,
                'scheduled_at' => $status === 'scheduled' ? $when : null,
                'sent_at' => $sent ? $when : null,
                'total_recipients' => $status === 'draft' ? 0 : $n,
                'sent_count' => $sent ? $n - $failed : 0,
                'delivered_count' => $sent ? $n - $failed : 0,
                'failed_count' => $failed,
                'pending_count' => $status === 'scheduled' ? $n : 0,
                'created_by' => $ctx->userId,
            ]);
            $counts['Campaigns']++;

            if ($sent) {
                foreach ($contacts as $i => $c) {
                    $ok = $i < $n - $failed;
                    $ctx->insert('marketing_campaign_recipients', $c + [
                        'campaign_id' => $campaignId,
                        'status' => $ok ? 'delivered' : 'failed',
                        'error_message' => $ok ? null : 'Invalid phone number',
                        'sent_at' => $when,
                        'delivered_at' => $ok ? $when->copy()->addMinute() : null,
                    ]);
                    $counts['Recipients']++;
                }
            }

            $ctx->insert('marketing_activity_logs', [
                'user_id' => $ctx->userId,
                'module' => 'campaign',
                'reference_id' => $campaignId,
                'action' => 'created',
                'description' => 'Campaign "'.$title.'" created',
            ]);
        }
        $counts['Activity logs'] = count($campaigns);

        return $counts;
    }
}
