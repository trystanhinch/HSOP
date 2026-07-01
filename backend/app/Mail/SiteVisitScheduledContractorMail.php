<?php

namespace App\Mail;

use App\Models\Lead;
use App\Models\SiteVisit;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SiteVisitScheduledContractorMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Lead $lead,
        public SiteVisit $siteVisit,
        public string $portalUrl
    ) {}

    public function build()
    {
        return $this->subject('Site Visit Scheduled — '.$this->lead->contact_name)
            ->view('emails.notification', [
                'heading' => 'Site Visit Assigned',
                'body' => "You have a site visit scheduled:\n\nCustomer: {$this->lead->contact_name}\nAddress: {$this->lead->address}\nDate: {$this->siteVisit->visit_date->format('M j, Y')}\nTime: {$this->siteVisit->visit_time}",
                'actionUrl' => $this->portalUrl,
                'actionLabel' => 'View in Dashboard',
            ]);
    }
}
