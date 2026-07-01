<?php

namespace App\Mail;

use App\Models\Job;
use App\Models\JobUpdate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ProgressUpdateMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Job $job,
        public JobUpdate $update,
        public string $portalUrl
    ) {}

    public function build()
    {
        $name = $this->job->customer?->name ?? 'there';

        return $this->subject('New Progress Update on Your Project')
            ->view('emails.notification', [
                'heading' => "Hi {$name},",
                'body' => "There is a new progress update for your project at {$this->job->address}.\n\n{$this->update->update_text}",
                'actionUrl' => $this->portalUrl,
                'actionLabel' => 'View Update',
            ]);
    }
}
