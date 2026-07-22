<?php

namespace App\Services\Reviews;

use App\Models\AiActionLog;
use App\Models\Job;
use App\Models\MessageTemplate;
use App\Models\NextAction;
use App\Models\ReviewFeedback;
use App\Models\User;
use App\Services\EmailService;
use App\Services\SmsMessageTemplates;
use App\Services\SmsService;
use App\Services\UploadStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReviewRequestService
{
    public const ISSUE_CATEGORIES = [
        'quality', 'communication', 'scheduling', 'cleanliness',
        'payment', 'contractor', 'pm', 'other',
    ];

    public function __construct(
        private SmsService $sms,
        private EmailService $email,
        private UploadStorage $uploads,
    ) {}

    /**
     * Fire once when payout eligibility becomes true (completion accepted + invoice paid + no open revision).
     */
    public function requestIfEligible(Job $job): ?array
    {
        $job->loadMissing(['lead', 'customer', 'invoice', 'pm']);

        if ($job->review_request_sent_at) {
            return ['skipped' => true, 'reason' => 'already_sent'];
        }

        if (ReviewFeedback::where('job_id', $job->id)->exists()) {
            return ['skipped' => true, 'reason' => 'feedback_exists'];
        }

        if (! $job->customer_accepted_completion_at || $job->invoice?->status !== 'paid') {
            return ['skipped' => true, 'reason' => 'not_eligible'];
        }

        $lead = $job->lead;
        if (! $lead) {
            return ['skipped' => true, 'reason' => 'no_lead'];
        }

        if (! $lead->customer_portal_token) {
            $lead->update(['customer_portal_token' => Str::random(64)]);
            $lead->refresh();
        }

        $reviewUrl = rtrim(config('app.frontend_url', 'http://localhost:5173'), '/')
            .'/portal/'.$lead->customer_portal_token.'?tab=review';

        $customer = $job->customer;
        $body = MessageTemplate::render(
            'review_request_customer',
            [
                'customer_name' => $customer?->name ?? 'there',
                'review_url' => $reviewUrl,
                'address' => $job->address ?? '',
            ],
            'Hi {{customer_name}}, thanks for choosing ServiceOP! Please rate your experience: {{review_url}}'
        );

        if ($customer) {
            $this->sms->sendToUser($customer, $body, 'review_request', $job->id);
            if ($customer->email) {
                $this->email->send(
                    $customer->email,
                    'How was your ServiceOP experience?',
                    'emails.notification',
                    ['body' => $body, 'title' => 'Rate your experience'],
                    'review_request',
                    $customer->id,
                    $job->id
                );
            }
        }

        $job->update(['review_request_sent_at' => now()]);

        AiActionLog::create([
            'trigger_event' => 'review_request_sent',
            'actor_id' => auth()->id() ?? User::where('role', 'owner')->value('id'),
            'data_viewed' => ['job_id' => $job->id, 'review_url' => $reviewUrl],
            'decision' => 'sent',
            'action_taken' => 'review_request_notification',
            'recipient' => $customer?->phone ?? $customer?->email,
            'rule_applied' => 'completion_accepted + invoice_paid → review request',
            'required_human_approval' => false,
        ]);

        return ['skipped' => false, 'review_url' => $reviewUrl];
    }

    public function submit(Job $job, array $data, ?UploadedFile $photo = null): ReviewFeedback
    {
        return DB::transaction(function () use ($job, $data, $photo) {
            $job->loadMissing(['lead.companySource', 'customer', 'pm', 'contractor']);

            if (ReviewFeedback::where('job_id', $job->id)->exists()) {
                throw new \RuntimeException('Review already submitted for this job.');
            }

            $rating = (int) $data['star_rating'];
            $photoUrl = null;
            if ($photo) {
                $path = $this->uploads->store($photo, 'review-feedback/'.$job->id);
                $photoUrl = $this->uploads->publicUrl($path);
            }

            $source = $job->lead?->companySource;
            $googleUrl = $source?->google_review_url;
            $isFiveStar = $rating === 5;
            // Spec: log google_review_shown=true for 5-star even when URL is null
            $googleShown = $isFiveStar;

            $feedback = ReviewFeedback::create([
                'job_id' => $job->id,
                'customer_id' => $job->customer_id,
                'pm_id' => $job->pm_id,
                'contractor_id' => $job->contractor_id,
                'star_rating' => $rating,
                'comment' => $data['comment'] ?? null,
                'issue_category' => $isFiveStar ? null : ($data['issue_category'] ?? 'other'),
                'photo_url' => $photoUrl,
                'follow_up_status' => $isFiveStar ? null : 'new',
                'google_review_shown' => $googleShown,
                'submitted_at' => now(),
            ]);

            if (! $isFiveStar) {
                $this->openFollowUp($feedback, $job);
            }

            AiActionLog::create([
                'trigger_event' => 'review_feedback_submitted',
                'actor_id' => $job->customer_id ?? User::where('role', 'owner')->value('id'),
                'data_viewed' => [
                    'job_id' => $job->id,
                    'star_rating' => $rating,
                    'google_url_present' => (bool) $googleUrl,
                    'google_review_shown' => $googleShown,
                ],
                'decision' => $isFiveStar ? 'five_star' : 'needs_follow_up',
                'action_taken' => $isFiveStar ? 'show_google_review_prompt' : 'create_pm_follow_up',
                'rule_applied' => '5-star → google prompt; <5 → internal feedback + PM next action',
                'required_human_approval' => ! $isFiveStar,
            ]);

            return $feedback->fresh();
        });
    }

    private function openFollowUp(ReviewFeedback $feedback, Job $job): void
    {
        $feedback->update(['follow_up_status' => 'pm_notified']);

        NextAction::create([
            'subject_type' => $feedback->getMorphClass(),
            'subject_id' => $feedback->id,
            'action_description' => 'Follow up on '.$feedback->star_rating.'-star customer feedback'
                .($feedback->issue_category ? ' ('.$feedback->issue_category.')' : ''),
            'responsible_role' => 'pm',
            'responsible_user_id' => $job->pm_id,
            'due_at' => now()->addDay(),
            'status' => 'pending',
            'last_action_at' => now(),
            'escalation_rule' => 'review_feedback_follow_up',
        ]);

        $msg = "ServiceOP: Customer left a {$feedback->star_rating}-star review on job #{$job->id}"
            .($job->address ? " ({$job->address})" : '')
            .'. Please follow up.';

        if ($job->pm) {
            $this->sms->sendToUser($job->pm, $msg, 'review_follow_up_pm', $job->id);
        }

        $owner = User::where('role', 'owner')->first();
        if ($owner) {
            $this->sms->sendToUser($owner, $msg, 'review_follow_up_owner', $job->id);
        }
    }

    public function portalReviewPayload(Job $job): array
    {
        $job->loadMissing(['lead.companySource', 'reviewFeedback']);
        $existing = $job->reviewFeedback;
        $googleUrl = $job->lead?->companySource?->google_review_url;

        return [
            'can_submit' => $existing === null && (bool) $job->review_request_sent_at,
            'already_submitted' => (bool) $existing,
            'feedback' => $existing ? [
                'star_rating' => $existing->star_rating,
                'comment' => $existing->comment,
                'google_review_shown' => $existing->google_review_shown,
                'submitted_at' => $existing->submitted_at,
            ] : null,
            'google_review_url' => $googleUrl ?: null,
            'show_google_button' => $existing && $existing->star_rating === 5 && filled($googleUrl),
            'issue_categories' => self::ISSUE_CATEGORIES,
            'job' => [
                'id' => $job->id,
                'address' => $job->address,
            ],
        ];
    }
}
