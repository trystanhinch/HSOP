<?php

namespace App\Mail;

use App\Models\Lead;
use App\Models\SiteVisit;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SiteVisitScheduledCustomerMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Lead $lead,
        public SiteVisit $siteVisit,
        public string $portalUrl
    ) {}

    public function build()
    {
        $company = config('app.company_name', 'ServiceOP');

        return $this->subject("Your Site Visit with {$company} is Scheduled")
            ->view('emails.notification', [
                'heading' => "Hi {$this->lead->contact_name},",
                'body' => "Your site visit with {$company} is scheduled for {$this->siteVisit->visit_date->format('M j, Y')} at {$this->siteVisit->visit_time}.\n\nAddress: {$this->lead->address}",
                'actionUrl' => $this->portalUrl,
                'actionLabel' => 'View Appointment Details',
            ]);
    }
}
