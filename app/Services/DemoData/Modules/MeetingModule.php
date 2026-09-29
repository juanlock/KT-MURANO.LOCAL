<?php

namespace App\Services\DemoData\Modules;

use App\Services\DemoData\DemoContext;
use App\Services\DemoData\DemoModule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Meetings examples: past and upcoming meetings with participants (the
 * company's existing users), notes/decisions/action items and activity logs.
 * Attachments are skipped (they need real files).
 */
class MeetingModule extends DemoModule
{
    public function key(): string
    {
        return 'meeting';
    }

    public function label(): string
    {
        return 'Meetings';
    }

    public function dataTables(): array
    {
        return ['meetings'];
    }

    public function requiredTables(): array
    {
        return ['meetings', 'meeting_participants', 'meeting_notes', 'meeting_activity_logs'];
    }

    public function generate(DemoContext $ctx): array
    {
        // Participants: the acting user plus up to 3 of the company's users.
        $userIds = DB::table('users')->where('company_id', $ctx->companyId)
            ->whereNull('deleted_at')->orderBy('id')->limit(3)->pluck('id')->all();
        $userIds = array_values(array_unique(array_merge([$ctx->userId], array_map('intval', $userIds))));

        $meetings = [
            ['Weekly Sales Review', 'physical', 'completed', -7, '09:30', '10:30', 'Conference room A', null],
            ['Quarterly Planning', 'online', 'completed', -3, '14:00', '15:30', null, 'google_meet'],
            ['Inventory Check-in', 'physical', 'scheduled', 2, '11:00', '11:45', 'Warehouse office', null],
            ['Supplier Negotiation Call', 'online', 'scheduled', 5, '16:00', '16:45', null, 'zoom'],
            ['Team Building Kick-off', 'physical', 'cancelled', 9, '10:00', '12:00', 'Main hall', null],
        ];
        $counts = ['Meetings' => 0, 'Participants' => 0, 'Notes' => 0, 'Activity logs' => 0];
        $noteSets = [
            ['note' => 'Sales were up 8% over last week.', 'decision' => 'Extend the weekend promotion by one week.', 'action_item' => 'Prepare updated sales dashboard.'],
            ['note' => 'Budget for next quarter reviewed.', 'decision' => 'Approve hiring of two sales associates.', 'action_item' => 'Draft the job postings.'],
        ];

        foreach ($meetings as $n => [$title, $type, $status, $offset, $start, $end, $location, $platform]) {
            $id = $ctx->insert('meetings', [
                'title' => $title,
                'description' => "$title with the team.",
                'agenda' => "1. Review of previous actions\n2. Main topic\n3. Next steps",
                'meeting_date' => Carbon::now()->addDays($offset)->toDateString(),
                'start_time' => $start.':00',
                'end_time' => $end.':00',
                'location' => $location,
                'type' => $type,
                'status' => $status,
                'platform' => $platform,
                'meeting_link' => $platform ? 'https://meet.example.com/'.strtolower(str_replace(' ', '-', $title)) : null,
                'reminder_minutes' => 30,
                'reminder_sent' => $status === 'completed' ? 1 : 0,
                'organizer_id' => $ctx->userId,
                'created_by' => $ctx->userId,
            ]);
            $counts['Meetings']++;

            foreach ($userIds as $uid) {
                $ctx->insert('meeting_participants', [
                    'meeting_id' => $id,
                    'user_id' => $uid,
                    'invitation_status' => $status === 'completed' ? 'accepted' : $ctx->pick(['invited', 'accepted', 'tentative']),
                    'attendance_status' => $status === 'completed' ? $ctx->pick(['present', 'present', 'late']) : 'pending',
                    'is_notified' => 1,
                    'notified_at' => Carbon::now()->addDays($offset - 1),
                ]);
                $counts['Participants']++;
            }

            if (isset($noteSets[$n])) {
                foreach ($noteSets[$n] as $noteType => $content) {
                    $ctx->insert('meeting_notes', [
                        'meeting_id' => $id,
                        'type' => $noteType,
                        'content' => $content,
                        'assigned_to' => $noteType === 'action_item' ? $ctx->pick($userIds) : null,
                        'due_date' => $noteType === 'action_item' ? Carbon::now()->addDays(7)->toDateString() : null,
                        'status' => $noteType === 'action_item' ? 'in_progress' : 'open',
                        'created_by' => $ctx->userId,
                    ]);
                    $counts['Notes']++;
                }
            }

            $ctx->insert('meeting_activity_logs', [
                'meeting_id' => $id,
                'user_id' => $ctx->userId,
                'action' => 'created',
                'description' => 'Meeting "'.$title.'" created',
            ]);
            $counts['Activity logs']++;
        }

        return $counts;
    }
}
