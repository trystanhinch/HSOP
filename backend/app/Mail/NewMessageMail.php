<?php

namespace App\Mail;

use App\Models\Job;
use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NewMessageMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Job $job,
        public Message $message,
        public string $portalUrl
    ) {}

    public function build()
    {
        return $this->subject('New message about your project')
            ->view('emails.notification', [
                'heading' => 'You have a new message',
                'body' => "There is a new message about your project at {$this->job->address}.\n\n\"{$this->message->content}\"",
                'actionUrl' => $this->portalUrl,
                'actionLabel' => 'View in Portal',
            ]);
    }
}
