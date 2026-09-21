<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class StoreAccountRejected extends Mailable
{
    use Queueable, SerializesModels;

    public $storeName;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($storeName = null)
    {
        $this->storeName = $storeName;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject('Your Registration Request')
            ->markdown('emails.store_account_rejected')
            ->with(['storeName' => $this->storeName]);
    }
}
