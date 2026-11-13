<?php

namespace App\Notifications;

use App\Mail\CustomEmail;
use App\Models\Meeting;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class MeetingInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @var \App\Models\Meeting
     */
    protected $meeting;

    public function __construct(Meeting $meeting)
    {
        $this->meeting = $meeting;
        // Sent from the queue: the company's language (2026-11-13).
        $this->locale = optional(Setting::forCompany((int) $meeting->company_id ?: null))->default_language ?: null;
    }

    public function via($notifiable)
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    protected function whenText(): string
    {
        $date = $this->meeting->meeting_date ? $this->meeting->meeting_date->format('Y-m-d') : '';
        $time = $this->meeting->start_time ? substr($this->meeting->start_time, 0, 5) : '';

        return trim($date . ' ' . $time);
    }

    public function toMail($notifiable)
    {
        $settings = Setting::current((int) ($this->meeting->company_id) ?: null);
        $businessName = $settings ? $settings->CompanyName : config('app.name');

        $url = url('/app/meeting/details/' . $this->meeting->id);

        $subject = __('Meeting invitation') . ': ' . $this->meeting->title;
        $body = __('You have been invited to a meeting.') . '<br><br>'
            . '<strong>' . $this->meeting->title . '</strong><br>'
            . __('When') . ': ' . $this->whenText() . '<br>'
            . ($this->meeting->type === 'online'
                ? __('Link') . ': ' . ($this->meeting->meeting_link ?: '—') . '<br>'
                : __('Location') . ': ' . ($this->meeting->location ?: '—') . '<br>')
            . '<br><a href="' . $url . '">' . __('View meeting') . '</a>';

        return (new CustomEmail([
            'subject'      => $subject,
            'body'         => $body,
            'company_name' => $businessName,
        ]))->to($notifiable->email);
    }

    public function toArray($notifiable)
    {
        return [
            'type'         => 'meeting_invitation',
            'meeting_id'   => $this->meeting->id,
            'title'        => $this->meeting->title,
            'meeting_when' => $this->whenText(),
            'message'      => __('Meeting invitation') . ': ' . $this->meeting->title . ' – ' . $this->whenText(),
        ];
    }
}
