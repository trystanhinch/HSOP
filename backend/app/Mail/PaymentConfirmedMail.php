<?php

namespace App\Mail;

use App\Models\Job;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PaymentConfirmedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Job $job,
        public string $portalUrl
    ) {}

    public function build()
    {
        $name = $this->job->customer?->name ?? 'there';

        return $this->subject('Payment Confirmed — Project Complete')
            ->view('emails.notification', [
                'heading' => "Hi {$name},",
                'body' => "Your payment for the project at {$this->job->address} has been confirmed. Thank you for choosing us!",
                'actionUrl' => $this->portalUrl,
                'actionLabel' => 'View Project',
            ]);
    }
}
