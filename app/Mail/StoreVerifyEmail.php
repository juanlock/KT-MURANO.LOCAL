<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class StoreVerifyEmail extends Mailable
{
    use Queueable, SerializesModels;

    public $url;

    public $storeName;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($url, $storeName = null)
    {
        $this->url = $url;
        $this->storeName = $storeName;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject('Verify Your Email Address')
            ->markdown('emails.store_verify_email')
            ->with(['url' => $this->url, 'storeName' => $this->storeName]);
    }
}
