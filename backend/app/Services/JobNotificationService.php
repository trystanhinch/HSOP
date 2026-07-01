<?php

namespace App\Services;

use App\Mail\ProgressUpdateMail;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Quote;
use App\Models\User;

class JobNotificationService
{
    public function __construct(
        protected SmsService $sms,
        protected EmailService $email
    ) {}

    public function frontendUrl(string $path = ''): string
    {
        return rtrim(config('app.frontend_url', 'http://localhost:5173'), '/').'/'.ltrim($path, '/');
    }

    public function audit(string $action, string $objectType, int $objectId, ?int $userId = null, ?string $role = null, array $meta = []): void
    {
        AuditLog::create([
            'user_id' => $userId ?? auth()->id(),
            'user_role' => $role ?? auth()->user()?->role,
            'object_type' => $objectType,
            'object_id' => $objectId,
            'action_type' => $action,
            'new_value' => $meta ? json_encode($meta) : null,
        ]);
    }

    public function quoteSent(Quote $quote, string $quoteUrl): void
    {
        $quote->loadMissing(['customer', 'job.pm']);
        $customer = $quote->customer;

        $this->sms->sendToUser(
            $customer,
            "Hi {$customer->name}, your quote from HSOP is ready to review. View and approve it here: {$quoteUrl}",
            'quote_sent',
            $quote->job_id
        );

        $this->email->send(
            $customer->email,
            'Your Quote from HSOP is Ready',
            'emails.notification',
            [
                'heading' => "Hi {$customer->name},",
                'body' => "Your quote for {$quote->job->address} is ready.\n\nQuote Total: \${$quote->customer_total} (includes GST)",
                'actionUrl' => $quoteUrl,
                'actionLabel' => 'View & Approve Quote',
            ],
            'quote_sent',
            $customer->id,
            $quote->job_id
        );

        $this->audit('quote_sent', 'quote', $quote->id, null, null, ['quote_url' => $quoteUrl]);
    }

    public function quoteApproved(Quote $quote): void
    {
        $quote->loadMissing(['customer', 'job.pm']);
        $pm = $quote->job->pm;
        $admin = User::where('role', 'owner')->first();

        $this->sms->sendToUser(
            $pm ?? $admin,
            "Quote {$quote->quote_number} was approved by {$quote->customer->name}.",
            'quote_approved',
            $quote->job_id
        );

        $this->sms->sendToUser(
            $quote->customer,
            "Thank you! Your quote has been approved. We'll be in touch to schedule your project.",
            'quote_approved_confirmation',
            $quote->job_id
        );

        $this->email->send(
            ($pm ?? $admin)?->email,
            "Quote {$quote->quote_number} Approved",
            'emails.notification',
            ['heading' => 'Quote Approved', 'body' => "{$quote->customer->name} approved quote {$quote->quote_number}."],
            'quote_approved',
            ($pm ?? $admin)?->id,
            $quote->job_id
        );

        $this->email->send(
            $quote->customer->email,
            'Your Quote Has Been Approved',
            'emails.notification',
            ['heading' => "Hi {$quote->customer->name},", 'body' => 'Thank you! Your quote has been approved. We will contact you to schedule your project.'],
            'quote_approved_confirmation',
            $quote->customer_id,
            $quote->job_id
        );

        $this->audit('quote_approved', 'quote', $quote->id);
    }

    public function quoteRejected(Quote $quote): void
    {
        $quote->loadMissing(['customer', 'job.pm']);
        $pm = $quote->job->pm;
        $admin = User::where('role', 'owner')->first();

        $this->sms->sendToUser(
            $pm ?? $admin,
            "Quote {$quote->quote_number} was declined by {$quote->customer->name}.",
            'quote_rejected',
            $quote->job_id
        );

        $this->email->send(
            ($pm ?? $admin)?->email,
            "Quote {$quote->quote_number} Declined",
            'emails.notification',
            ['heading' => 'Quote Declined', 'body' => "{$quote->customer->name} declined quote {$quote->quote_number}."],
            'quote_rejected',
            ($pm ?? $admin)?->id,
            $quote->job_id
        );

        $this->audit('quote_rejected', 'quote', $quote->id);
    }

    public function jobScheduled(Job $job, bool $isUpdate = false): void
    {
        $job->loadMissing(['customer', 'contractor', 'pm']);
        $prefix = $isUpdate ? 'Schedule updated:' : 'Your job has been scheduled for';
        $customerPortal = $this->frontendUrl("jobs/{$job->id}");
        $contractorPortal = $this->frontendUrl("jobs/{$job->id}");

        $this->sms->sendToUser(
            $job->customer,
            "Hi {$job->customer->name}, {$prefix} {$job->scheduled_start_date} at {$job->scheduled_start_time}. View details: {$customerPortal}",
            $isUpdate ? 'schedule_changed' : 'job_scheduled',
            $job->id
        );

        $this->sms->sendToUser(
            $job->contractor,
            "Schedule confirmed for {$job->address}: {$job->scheduled_start_date} at {$job->scheduled_start_time}. {$contractorPortal}",
            $isUpdate ? 'schedule_changed' : 'job_scheduled',
            $job->id
        );

        $this->email->send(
            $job->customer?->email,
            $isUpdate ? 'Your Job Schedule Was Updated' : 'Your Job Has Been Scheduled',
            'emails.notification',
            [
                'heading' => "Hi {$job->customer?->name},",
                'body' => "{$prefix} {$job->scheduled_start_date}.",
                'actionUrl' => $customerPortal,
                'actionLabel' => 'View Job',
            ],
            $isUpdate ? 'schedule_changed' : 'job_scheduled',
            $job->customer_id,
            $job->id
        );

        $this->audit($isUpdate ? 'schedule_changed' : 'job_scheduled', 'job', $job->id);
    }

    public function contractorAssigned(Job $job, User $contractor): void
    {
        $portal = $this->frontendUrl("jobs/{$job->id}");

        $this->sms->sendToUser(
            $contractor,
            "You have been assigned a new job: {$job->job_title}. View details: {$portal}",
            'contractor_assigned',
            $job->id
        );

        $this->sms->sendToUser(
            $contractor,
            "Please submit your price for {$job->job_title}. View job: {$portal}",
            'price_required',
            $job->id
        );

        $this->email->send(
            $contractor->email,
            'New Job Assignment — HSOP',
            'emails.notification',
            [
                'heading' => "Hi {$contractor->name},",
                'body' => "You have been assigned: {$job->job_title}\n\nPlease submit your price when ready.",
                'actionUrl' => $portal,
                'actionLabel' => 'View Job',
            ],
            'contractor_assigned',
            $contractor->id,
            $job->id
        );

        $this->audit('contractor_assigned', 'job', $job->id, null, null, ['contractor_id' => $contractor->id]);
    }

    public function priceSubmitted(Job $job): void
    {
        $job->loadMissing(['contractor', 'pm']);
        $pm = $job->pm;
        $admin = User::where('role', 'owner')->first();

        $this->sms->sendToUser(
            $pm ?? $admin,
            "{$job->contractor->name} submitted a price of \${$job->contractor_submitted_price} for {$job->job_title}.",
            'price_submitted',
            $job->id
        );

        $this->email->send(
            ($pm ?? $admin)?->email,
            'Contractor Price Submitted',
            'emails.notification',
            ['heading' => 'Price Submitted', 'body' => "{$job->contractor->name} submitted \${$job->contractor_submitted_price} for {$job->job_title}."],
            'price_submitted',
            ($pm ?? $admin)?->id,
            $job->id
        );

        $this->audit('contractor_price_submitted', 'job', $job->id);
    }

    public function progressUpdate(Job $job, User $poster, string $visibility): void
    {
        $job->loadMissing(['pm', 'customer']);
        $pm = $job->pm;
        $admin = User::where('role', 'owner')->first();

        $this->sms->sendToUser(
            $pm ?? $admin,
            "New progress update for {$job->job_title} by {$poster->name}.",
            'progress_update',
            $job->id
        );

        $this->email->send(
            ($pm ?? $admin)?->email,
            'New Progress Update',
            'emails.notification',
            ['heading' => 'Progress Update', 'body' => "{$poster->name} posted an update on {$job->job_title}."],
            'progress_update',
            ($pm ?? $admin)?->id,
            $job->id
        );

        if ($visibility === 'customer_visible') {
            $portal = $this->frontendUrl("jobs/{$job->id}");
            $this->sms->sendToUser(
                $job->customer,
                "Hi {$job->customer->name}, there is a new progress update for your project. {$portal}",
                'progress_update_customer',
                $job->id
            );

            $this->email->send(
                $job->customer?->email,
                'New Progress Update on Your Project',
                'emails.notification',
                [
                    'heading' => "Hi {$job->customer?->name},",
                    'body' => 'There is a new progress update on your project.',
                    'actionUrl' => $portal,
                    'actionLabel' => 'View Update',
                ],
                'progress_update_customer',
                $job->customer_id,
                $job->id
            );
        }

        $this->audit('progress_update_submitted', 'job', $job->id);
    }

    public function progressUpdateCustomer(Job $job, \App\Models\JobUpdate $update): void
    {
        $job->loadMissing(['pm', 'customer', 'lead']);
        $pm = $job->pm;
        $admin = User::where('role', 'owner')->first();

        $this->sms->sendToUser(
            $pm ?? $admin,
            "New progress update for {$job->job_title}.",
            'progress_update',
            $job->id
        );

        $portalToken = $job->lead?->customer_portal_token;
        $portalUrl = $portalToken
            ? $this->frontendUrl('portal/'.$portalToken)
            : $this->frontendUrl('jobs/'.$job->id);

        $this->sms->sendToUser(
            $job->customer,
            "Hi {$job->customer?->name}, there is a new progress update for your project at {$job->address}. View it here: {$portalUrl}",
            'progress_update_customer',
            $job->customer_id,
            $job->id
        );

        if ($job->customer?->email) {
            $this->email->sendMailable(
                $job->customer->email,
                new ProgressUpdateMail($job, $update, $portalUrl),
                'progress_update_customer',
                $job->customer_id,
                $job->id
            );
        }

        $this->audit('progress_update_submitted', 'job', $job->id);
    }

    public function readyForReview(Job $job): void
    {
        $job->loadMissing(['contractor', 'pm']);
        $pm = $job->pm;
        $admin = User::where('role', 'owner')->first();

        $this->sms->sendToUser(
            $pm ?? $admin,
            "{$job->job_title} has been marked ready for review by {$job->contractor->name}.",
            'ready_for_review',
            $job->id
        );

        $this->email->send(
            ($pm ?? $admin)?->email,
            'Job Ready for Review',
            'emails.notification',
            ['heading' => 'Ready for Review', 'body' => "{$job->job_title} is ready for your review."],
            'ready_for_review',
            ($pm ?? $admin)?->id,
            $job->id
        );

        $this->audit('ready_for_review', 'job', $job->id);
    }

    public function jobComplete(Job $job): void
    {
        $job->loadMissing(['customer', 'contractor']);
        $portal = $this->frontendUrl("jobs/{$job->id}");

        $this->sms->sendToUser(
            $job->customer,
            "Hi {$job->customer->name}, your project has been marked complete. {$portal}",
            'job_complete',
            $job->id
        );

        $this->sms->sendToUser(
            $job->contractor,
            "{$job->job_title} has been marked complete. Thank you for your work.",
            'job_complete_contractor',
            $job->id
        );

        $this->email->send(
            $job->customer?->email,
            'Your Project is Complete',
            'emails.notification',
            ['heading' => "Hi {$job->customer?->name},", 'body' => 'Your project has been marked complete.', 'actionUrl' => $portal, 'actionLabel' => 'View Job'],
            'job_complete',
            $job->customer_id,
            $job->id
        );

        $this->audit('job_completed', 'job', $job->id);
    }

    public function correctionsRequested(Job $job): void
    {
        $job->loadMissing('contractor');
        $portal = $this->frontendUrl("jobs/{$job->id}");

        $this->sms->sendToUser(
            $job->contractor,
            "Corrections requested for {$job->job_title}. {$job->corrections_notes}",
            'corrections_requested',
            $job->id
        );

        $this->email->send(
            $job->contractor?->email,
            'Corrections Requested',
            'emails.notification',
            ['heading' => 'Corrections Required', 'body' => $job->corrections_notes ?? 'Please review the job notes.', 'actionUrl' => $portal, 'actionLabel' => 'View Job'],
            'corrections_requested',
            $job->contractor_id,
            $job->id
        );

        $this->audit('corrections_requested', 'job', $job->id);
    }

    public function invoiceSent(Invoice $invoice): void
    {
        $invoice->loadMissing(['job.customer']);
        $portal = $this->frontendUrl('invoices');

        $this->sms->sendToUser(
            $invoice->job->customer,
            "Hi {$invoice->job->customer->name}, your invoice for \${$invoice->amount} is ready. {$portal}",
            'invoice_sent',
            $invoice->job_id
        );

        $this->email->send(
            $invoice->job->customer?->email,
            'Your Invoice from HSOP',
            'emails.notification',
            [
                'heading' => "Hi {$invoice->job->customer?->name},",
                'body' => "Your invoice {$invoice->invoice_number} for \${$invoice->amount} is ready.",
                'actionUrl' => $portal,
                'actionLabel' => 'View Invoice',
            ],
            'invoice_sent',
            $invoice->customer_id,
            $invoice->job_id
        );

        $this->audit('invoice_sent', 'invoice', $invoice->id);
    }

    public function manualMessageSms(User $recipient, string $message, int $jobId): void
    {
        $this->sms->sendToUser($recipient, $message, 'manual_message', $jobId);
    }
}
