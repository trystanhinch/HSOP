<?php

namespace App\Services;

use App\Models\Job;
use App\Models\Lead;
use App\Models\Quote;
use App\Models\Setting;
use App\Models\User;
use App\Services\BrandResolver;
use Carbon\Carbon;

class SmsMessageTemplates
{
    /**
     * Platform-level fallback company name (for internal/admin SMS only).
     * Customer-facing SMS must resolve brand via BrandResolver::forLead/forJob.
     */
    public static function companyName(): string
    {
        return (string) config('app.brand_name',
            Setting::where('key', 'company_name')->value('value')
            ?? BrandResolver::PLATFORM_NAME
        );
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

    public static function formatDate(null|string|\DateTimeInterface $date): string
    {
        if ($date === null || $date === '') {
            return '';
        }

        // Always format from the calendar Y-m-d portion. Never format a UTC
        // midnight instant in a western timezone (that shifts back one day).
        if ($date instanceof \DateTimeInterface) {
            $date = Carbon::instance($date)->toDateString();
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', (string) $date, $matches)) {
            return Carbon::createFromFormat('Y-m-d', $matches[1])->format('M j, Y');
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
        $brandName = app(BrandResolver::class)->forLead($lead);

        return 'Hi '.$lead->contact_name.', your site visit with '.$brandName
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
        $brand = app(BrandResolver::class)->forQuote($quote);

        $body = \App\Models\MessageTemplate::render(
            'quote_sent',
            [
                'company_name' => $brand,
                'customer_total' => number_format((float) $quote->customer_total, 2),
                'portal_url' => $portalUrl,
            ],
            $brand.': Your quote is ready. Total: $'.number_format((float) $quote->customer_total, 2)
                .'. View quote: '.$portalUrl
        );

        return $body ?? '';
    }

    public static function quoteApprovedCustomer(string $portalUrl, ?Quote $quote = null): string
    {
        $brand = $quote ? app(BrandResolver::class)->forQuote($quote) : self::companyName();

        return $brand.': Your quote has been approved. Your project manager will contact you'
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
        $brand = app(BrandResolver::class)->forJob($job);

        $body = \App\Models\MessageTemplate::render(
            'progress_update_customer',
            [
                'company_name' => $brand,
                'customer_name' => $customer->name ?? 'there',
                'address' => $job->address ?? '',
                'portal_url' => $portalUrl,
            ],
            $brand.': A progress update has been posted for your project.'
                .' View update: '.$portalUrl
        );

        return $body ?? '';
    }

    public static function jobCompletePendingApproval(User $customer, Job $job, string $portalUrl): string
    {
        $brand = app(BrandResolver::class)->forJob($job);

        return $brand.': Your project has been marked complete. Please review and accept or'
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
