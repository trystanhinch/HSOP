<?php

namespace App\Mail;

use App\Models\Job;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CompletionAcceptedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Job $job,
        public string $paymentUrl
    ) {}

    public function build()
    {
        $name = $this->job->customer?->name ?? 'there';

        return $this->subject('Completion Accepted — Payment Next')
            ->view('emails.notification', [
                'heading' => "Hi {$name},",
                'body' => "Thank you for accepting the completed work at {$this->job->address}. Please proceed with payment when ready.",
                'actionUrl' => $this->paymentUrl,
                'actionLabel' => 'View Payment Details',
            ]);
    }
}
