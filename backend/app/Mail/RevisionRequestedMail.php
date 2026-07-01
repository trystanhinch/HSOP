<?php

namespace App\Mail;

use App\Models\Job;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class RevisionRequestedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Job $job,
        public string $description,
        public string $portalUrl
    ) {}

    public function build()
    {
        return $this->subject('Revision Requested — '.$this->job->address)
            ->view('emails.notification', [
                'heading' => 'Revision Requested',
                'body' => "A revision has been requested for the job at {$this->job->address}.\n\nFeedback:\n{$this->description}",
                'actionUrl' => $this->portalUrl,
                'actionLabel' => 'View Job',
            ]);
    }
}
