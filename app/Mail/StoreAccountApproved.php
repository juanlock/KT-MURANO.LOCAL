<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class StoreAccountApproved extends Mailable
{
    use Queueable, SerializesModels;

    public $loginUrl;

    public $storeName;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($loginUrl, $storeName = null)
    {
        $this->loginUrl = $loginUrl;
        $this->storeName = $storeName;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject('Your Account Has Been Approved')
            ->markdown('emails.store_account_approved')
            ->with(['loginUrl' => $this->loginUrl, 'storeName' => $this->storeName]);
    }
}
