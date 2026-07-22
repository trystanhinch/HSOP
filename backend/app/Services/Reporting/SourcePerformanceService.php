<?php

namespace App\Services\Reporting;

use App\Models\CompanySource;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Lead;
use App\Models\Quote;
use App\Models\ReviewFeedback;

class SourcePerformanceService
{
    public function summary(): array
    {
        $sources = CompanySource::query()->orderBy('company_name')->get();

        $rows = $sources->map(function (CompanySource $source) {
            $leadQuery = Lead::where('company_source_id', $source->id);
            $leadIds = (clone $leadQuery)->pluck('id');
            $jobIds = Job::whereIn('lead_id', $leadIds)->pluck('id');

            $quotesSent = Quote::where(function ($q) use ($jobIds, $leadIds) {
                $q->whereIn('job_id', $jobIds);
                if ($leadIds->isNotEmpty()) {
                    $q->orWhereIn('lead_id', $leadIds);
                }
            })->where(function ($q) {
                $q->whereNotNull('sent_at')->orWhereIn('status', ['sent', 'viewed', 'follow_up', 'approved']);
            })->count();

            $quotesApproved = Quote::whereIn('job_id', $jobIds)->where('status', 'approved')->count();

            $paidBySource = Invoice::where('status', 'paid')
                ->where(function ($q) use ($source, $jobIds) {
                    $q->where('company_source_id', $source->id)
                        ->orWhere('source_company', $source->company_name)
                        ->orWhereIn('job_id', $jobIds);
                })
                ->get()
                ->unique('id');

            $revenue = round((float) $paidBySource->sum('subtotal'), 2);
            $gst = round((float) $paidBySource->sum('gst'), 2);

            $reviews = ReviewFeedback::whereIn('job_id', $jobIds)->get();
            $avgRating = $reviews->count() ? round((float) $reviews->avg('star_rating'), 2) : null;

            $jobs = Job::whereIn('id', $jobIds)->get();
            $byPm = $jobs->groupBy('pm_id')->map(function ($group, $pmId) use ($reviews) {
                $pmReviews = $reviews->whereIn('job_id', $group->pluck('id'));

                return [
                    'pm_id' => $pmId ?: null,
                    'job_count' => $group->count(),
                    'avg_rating' => $pmReviews->count() ? round((float) $pmReviews->avg('star_rating'), 2) : null,
                ];
            })->values();

            $byContractor = $jobs->groupBy('contractor_id')->map(function ($group, $contractorId) use ($reviews) {
                $cReviews = $reviews->whereIn('job_id', $group->pluck('id'));

                return [
                    'contractor_id' => $contractorId ?: null,
                    'job_count' => $group->count(),
                    'avg_rating' => $cReviews->count() ? round((float) $cReviews->avg('star_rating'), 2) : null,
                ];
            })->values();

            return [
                'company_source_id' => $source->id,
                'company_name' => $source->company_name,
                'leads' => $leadQuery->count(),
                'quotes_sent' => $quotesSent,
                'quotes_approved' => $quotesApproved,
                'revenue_subtotal' => $revenue,
                'gst_collected' => $gst,
                'avg_review_rating' => $avgRating,
                'review_count' => $reviews->count(),
                'pm_performance' => $byPm,
                'contractor_performance' => $byContractor,
            ];
        })->values();

        // Unspecified / no source
        $orphanLeads = Lead::whereNull('company_source_id')->count();
        if ($orphanLeads > 0) {
            $rows->push([
                'company_source_id' => null,
                'company_name' => 'Unspecified',
                'leads' => $orphanLeads,
                'quotes_sent' => 0,
                'quotes_approved' => 0,
                'revenue_subtotal' => round((float) Invoice::where('status', 'paid')->whereNull('company_source_id')->where(function ($q) {
                    $q->whereNull('source_company')->orWhere('source_company', '');
                })->sum('subtotal'), 2),
                'gst_collected' => 0,
                'avg_review_rating' => null,
                'review_count' => 0,
                'pm_performance' => [],
                'contractor_performance' => [],
            ]);
        }

        return [
            'sources' => $rows,
            'totals' => [
                'leads' => $rows->sum('leads'),
                'quotes_sent' => $rows->sum('quotes_sent'),
                'quotes_approved' => $rows->sum('quotes_approved'),
                'revenue_subtotal' => round((float) $rows->sum('revenue_subtotal'), 2),
                'avg_review_rating' => ReviewFeedback::avg('star_rating')
                    ? round((float) ReviewFeedback::avg('star_rating'), 2)
                    : null,
            ],
        ];
    }
}
