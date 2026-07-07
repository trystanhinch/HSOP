<?php

namespace App\Mail;

use App\Models\Job;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class JobReadyForReviewMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Job $job,
        public string $portalUrl
    ) {}

    public function build()
    {
        return $this->subject('Your Project is Complete')
            ->view('emails.notification', [
                'heading' => 'Project Complete',
                'body' => 'Your project has been marked complete. Please review and accept or request a revision.',
                'actionUrl' => $this->portalUrl,
                'actionLabel' => 'View Project',
            ]);
    }
}
