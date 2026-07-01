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
        $name = $this->job->customer?->name ?? 'there';

        return $this->subject('Your Project is Ready for Review')
            ->view('emails.notification', [
                'heading' => "Hi {$name},",
                'body' => "Your project at {$this->job->address} is complete and ready for your review. Please accept the work or request changes.",
                'actionUrl' => $this->portalUrl,
                'actionLabel' => 'Review & Accept',
            ]);
    }
}
