<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class StorePasswordReset extends Mailable
{
    use Queueable, SerializesModels;

    public $url;

    public $storeName;

    public function __construct($url, $storeName = null)
    {
        $this->url = $url;
        $this->storeName = $storeName;
    }

    public function build()
    {
        return $this->subject('Reset Your Password')
            ->markdown('emails.store_password_reset')
            ->with(['url' => $this->url, 'storeName' => $this->storeName]);
    }
}
