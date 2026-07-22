<?php

namespace App\Services\Reporting;

use App\Models\AiActionLog;
use App\Models\AiOpsReport;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Lead;
use App\Models\Payout;
use App\Models\Quote;
use App\Models\ReviewFeedback;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiOpsReportService
{
    public function generate(string $period = 'daily', ?Carbon $forDate = null): AiOpsReport
    {
        $forDate = ($forDate ?? now())->startOfDay();
        $from = $period === 'weekly' ? $forDate->copy()->subDays(6) : $forDate->copy();
        $to = $forDate->copy()->endOfDay();

        $metrics = $this->collectMetrics($from, $to);
        [$summary, $provider] = $this->summarize($metrics, $period);

        return AiOpsReport::updateOrCreate(
            [
                'report_date' => $forDate->toDateString(),
                'period' => $period,
            ],
            [
                'summary_text' => $summary,
                'raw_metrics' => $metrics,
                'provider' => $provider,
            ]
        );
    }

    public function collectMetrics(Carbon $from, Carbon $to): array
    {
        return [
            'window' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'new_leads' => Lead::whereBetween('created_at', [$from, $to])->count(),
            'leads_needing_review' => Lead::where('needs_manual_review', true)->count(),
            'quotes_sent' => Quote::whereIn('status', ['sent', 'viewed', 'follow_up', 'approved'])
                ->whereBetween('sent_at', [$from, $to])->count(),
            'quotes_approved' => Quote::where('status', 'approved')
                ->whereBetween('updated_at', [$from, $to])->count(),
            'jobs_in_progress' => Job::whereIn('status', ['in_progress', 'update_posted', 'progress_updated', 'scheduled'])->count(),
            'jobs_completed_window' => Job::whereIn('status', ['paid', 'paid_completed', 'completed'])
                ->whereBetween('updated_at', [$from, $to])->count(),
            'invoices_paid' => Invoice::where('status', 'paid')->whereBetween('payment_date', [$from->toDateString(), $to->toDateString()])->count(),
            'revenue_paid_subtotal' => round((float) Invoice::where('status', 'paid')
                ->whereBetween('payment_date', [$from->toDateString(), $to->toDateString()])
                ->sum('subtotal'), 2),
            'payouts_scheduled' => Payout::where('status', 'scheduled')->count(),
            'payouts_paid' => Payout::where('status', 'paid')->whereBetween('paid_date', [$from->toDateString(), $to->toDateString()])->count(),
            'reviews_submitted' => ReviewFeedback::whereBetween('submitted_at', [$from, $to])->count(),
            'reviews_needing_follow_up' => ReviewFeedback::where('star_rating', '<', 5)
                ->whereIn('follow_up_status', ['new', 'pm_notified', 'customer_contacted', 'escalated'])
                ->count(),
            'avg_star_rating' => round((float) (ReviewFeedback::avg('star_rating') ?? 0), 2),
            'ai_errors' => AiActionLog::whereNotNull('error')
                ->whereBetween('created_at', [$from, $to])
                ->count(),
            'ai_kill_switch' => Setting::get('ai_kill_switch', 'false'),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function summarize(array $metrics, string $period): array
    {
        $fallback = $this->fallbackSummary($metrics, $period);

        if (Setting::get('ai_kill_switch') === 'true' || ! config('ai.openai.api_key') || config('ai.provider') !== 'openai') {
            return [$fallback, config('ai.provider', 'mock')];
        }

        try {
            $response = Http::withToken(config('ai.openai.api_key'))
                ->timeout((int) config('ai.openai.timeout', 20))
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => config('ai.openai.model', 'gpt-4o-mini'),
                    'temperature' => 0.3,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You are an operations analyst for a home-services company. Write a concise '.$period.' status briefing for the owner (5-8 sentences). No markdown headings. Call out risks and wins.',
                        ],
                        [
                            'role' => 'user',
                            'content' => json_encode($metrics, JSON_UNESCAPED_SLASHES),
                        ],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('AI ops report OpenAI HTTP error', ['status' => $response->status()]);

                return [$fallback, 'mock_fallback'];
            }

            $text = trim((string) ($response->json('choices.0.message.content') ?? ''));

            return [$text !== '' ? $text : $fallback, 'openai'];
        } catch (Throwable $e) {
            Log::warning('AI ops report failed', ['error' => $e->getMessage()]);

            return [$fallback, 'mock_fallback'];
        }
    }

    private function fallbackSummary(array $m, string $period): string
    {
        return sprintf(
            '%s ops snapshot (%s to %s): %d new leads, %d quotes sent, %d jobs in progress, %d invoices paid ($%s revenue ex-GST), %d reviews submitted (avg %s★), %d reviews needing follow-up, %d AI log errors.',
            ucfirst($period),
            $m['window']['from'],
            $m['window']['to'],
            $m['new_leads'],
            $m['quotes_sent'],
            $m['jobs_in_progress'],
            $m['invoices_paid'],
            number_format($m['revenue_paid_subtotal'], 2),
            $m['reviews_submitted'],
            $m['avg_star_rating'],
            $m['reviews_needing_follow_up'],
            $m['ai_errors']
        );
    }
}
