<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CustomEmail extends Mailable
{
    use Queueable, SerializesModels;

    public $data;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($data)
    {
        $this->data = $data;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $mail = $this->subject($this->data['subject'])
            ->markdown('emails.custom')
            ->view('emails.custom');

        // Optional in-memory attachments -- e.g. an invoice PDF, generated
        // fresh per send rather than read from disk. Each entry:
        // ['data' => raw bytes, 'name' => filename, 'mime' => content type].
        foreach ($this->data['attachments'] ?? [] as $attachment) {
            $mail->attachData($attachment['data'], $attachment['name'], [
                'mime' => $attachment['mime'] ?? 'application/pdf',
            ]);
        }

        return $mail;
    }
}
