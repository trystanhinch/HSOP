<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class HsopNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $mailSubject,
        public string $viewName,
        public array $data = []
    ) {}

    public function build()
    {
        return $this->subject($this->mailSubject)
            ->view($this->viewName, $this->data);
    }
}
