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
        return $this->subject('Progress Update on Your Project')
            ->view('emails.notification', [
                'heading' => 'Progress Update',
                'body' => "A progress update has been posted for your project.\n\n{$this->update->update_text}",
                'actionUrl' => $this->portalUrl,
                'actionLabel' => 'View Update',
            ]);
    }
}
