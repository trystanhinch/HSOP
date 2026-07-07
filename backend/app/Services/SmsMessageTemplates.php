<?php

namespace App\Services;

use App\Models\Job;
use App\Models\Lead;
use App\Models\Quote;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;

class SmsMessageTemplates
{
    public static function companyName(): string
    {
        return Setting::where('key', 'company_name')->value('value')
            ?? config('app.name', 'ServiceOP');
    }

    public static function frontendUrl(string $path = ''): string
    {
        $base = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173')), '/');

        return $path === '' ? $base : $base.'/'.ltrim($path, '/');
    }

    public static function customerPortalUrl(?string $token): string
    {
        return self::frontendUrl('portal/'.($token ?? ''));
    }

    public static function contractorDashboardUrl(): string
    {
        return self::frontendUrl('dashboard/contractor');
    }

    public static function contractorJobUrl(int $jobId): string
    {
        return self::frontendUrl("jobs/{$jobId}");
    }

    public static function customerPortalUrlForJob(Job $job): string
    {
        $job->loadMissing('lead');
        $token = $job->lead?->customer_portal_token;

        return $token
            ? self::customerPortalUrl($token)
            : self::frontendUrl("jobs/{$job->id}");
    }

    public static function formatDate(?string $date): string
    {
        if (! $date) {
            return '';
        }

        return Carbon::parse($date)->format('M j, Y');
    }

    public static function formatTime(?string $time): string
    {
        if (! $time) {
            return '';
        }

        return Carbon::parse($time)->format('g:i A');
    }

    public static function siteVisitCustomer(Lead $lead, string $visitDate, string $visitTime, string $portalUrl): string
    {
        return 'Hi '.$lead->contact_name.', your site visit with '.self::companyName()
            .' is confirmed for '.self::formatDate($visitDate)
            .' at '.self::formatTime($visitTime)
            .". Address: {$lead->address}."
            ." View your appointment details here: {$portalUrl}";
    }

    public static function siteVisitContractor(User $contractor, Lead $lead, string $visitDate, string $visitTime, string $contractorUrl): string
    {
        return 'Hi '.$contractor->name.', you have a site visit assigned:'
            ." {$lead->contact_name}, {$lead->address}"
            .' on '.self::formatDate($visitDate)
            .' at '.self::formatTime($visitTime)
            .". View job details: {$contractorUrl}";
    }

    public static function quoteSent(User $customer, Quote $quote, string $portalUrl): string
    {
        return 'ServiceOP: Your quote is ready. Total: $'.number_format((float) $quote->customer_total, 2)
            .'. View quote: '.$portalUrl;
    }

    public static function quoteApprovedCustomer(string $portalUrl): string
    {
        return 'ServiceOP: Your quote has been approved. Your project manager will contact you'
            .' to schedule the project. View project: '.$portalUrl;
    }

    public static function jobScheduledCustomer(User $customer, Job $job, string $portalUrl, bool $isUpdate = false): string
    {
        $prefix = $isUpdate ? 'Your job schedule has been updated to' : 'your job has been scheduled for';

        return 'Hi '.$customer->name.', '.$prefix.' '
            .self::formatDate($job->scheduled_start_date)
            .' at '.self::formatTime($job->scheduled_start_time)
            .". View details: {$portalUrl}";
    }

    public static function jobScheduledContractor(User $contractor, Job $job, string $contractorUrl, bool $isUpdate = false): string
    {
        $prefix = $isUpdate ? 'Schedule updated for' : 'A job has been scheduled:';

        return 'Hi '.$contractor->name.', '.$prefix
            ." {$job->address} on "
            .self::formatDate($job->scheduled_start_date)
            .' at '.self::formatTime($job->scheduled_start_time)
            .". View: {$contractorUrl}";
    }

    public static function progressUpdateCustomer(User $customer, Job $job, string $portalUrl): string
    {
        return 'ServiceOP: A progress update has been posted for your project.'
            .' View update: '.$portalUrl;
    }

    public static function jobCompletePendingApproval(User $customer, Job $job, string $portalUrl): string
    {
        return 'ServiceOP: Your project has been marked complete. Please review and accept or'
            .' request a revision: '.$portalUrl;
    }

    public static function revisionRequested(User $contractor, Job $job, string $contractorUrl): string
    {
        return 'Hi '.$contractor->name.', a revision has been requested'
            ." for your job at {$job->address}."
            ." Please review the client's feedback: {$contractorUrl}";
    }

    public static function paymentConfirmed(User $customer, Job $job): string
    {
        return 'Hi '.$customer->name.', your payment has been received'
            ." and your project at {$job->address} is now complete. Thank you!";
    }
}
