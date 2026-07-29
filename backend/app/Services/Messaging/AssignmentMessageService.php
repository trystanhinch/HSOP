<?php

namespace App\Services\Messaging;

use App\Models\AuditLog;
use App\Models\Job;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use App\Services\Contractors\ContractorAssignmentService;
use App\Services\EmailService;
use App\Services\SmsMessageTemplates;
use App\Services\SmsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Audit CT-02 — contractor↔PM threads on leads (pre-job) that survive conversion.
 */
class AssignmentMessageService
{
    /** Channels a contractor may see on an assignment thread (excludes owner/PM-only notes). */
    public const CONTRACTOR_VISIBLE_CHANNELS = [
        'contractor_to_pm',
        'pm_to_contractor',
    ];

    public function __construct(
        protected ContractorAssignmentService $assignments,
        protected SmsService $sms,
        protected EmailService $email,
    ) {}

    public function leadSupportsMessages(): bool
    {
        return Schema::hasColumn('messages', 'lead_id');
    }

    public function threadForLead(Lead $lead): Collection
    {
        if (! $this->leadSupportsMessages()) {
            return collect();
        }

        $query = Message::query()
            ->where('lead_id', $lead->id)
            ->whereIn('channel', self::CONTRACTOR_VISIBLE_CHANNELS)
            ->with('sender:id,name,role')
            ->oldest();

        // After conversion, messages may also be keyed by job_id — still include by lead_id.
        return $query->get();
    }

    public function threadForJob(Job $job, User $viewer): Collection
    {
        $query = Message::query()->with('sender:id,name,role')->oldest();

        $query->where(function ($q) use ($job) {
            $q->where('job_id', $job->id);
            if ($job->lead_id && $this->leadSupportsMessages()) {
                $q->orWhere('lead_id', $job->lead_id);
            }
        });

        if ($viewer->role === 'contractor') {
            $query->whereIn('channel', array_merge(
                self::CONTRACTOR_VISIBLE_CHANNELS,
                ['customer_to_pm', 'pm_to_customer']
            ));
        } elseif ($viewer->role === 'customer') {
            $query->where('visibility', 'customer_visible');
        }

        return $query->get()->unique('id')->values();
    }

    public function postLeadMessage(Lead $lead, User $sender, string $content): Message
    {
        $this->assignments->assertContractorLeadAccess($sender, $lead);

        if (! $this->leadSupportsMessages()) {
            throw new \RuntimeException('Lead messaging is not available (migration pending).');
        }

        $channel = $sender->role === 'contractor' ? 'contractor_to_pm' : 'pm_to_contractor';
        $receiverId = $sender->role === 'contractor'
            ? $lead->assigned_pm_id
            : ($lead->site_visit_contractor_id ?: $lead->assigned_contractor_id);

        $jobId = $lead->job?->id;

        $message = Message::create([
            'job_id' => $jobId,
            'lead_id' => $lead->id,
            'sender_id' => $sender->id,
            'receiver_id' => $receiverId,
            'sender_role' => $sender->role,
            'content' => $content,
            'visibility' => 'internal',
            'channel' => $channel,
            'is_read' => false,
        ]);

        $this->notifyCounterpart($lead, $sender, $message, $receiverId);

        AuditLog::create([
            'user_id' => $sender->id,
            'user_role' => $sender->role,
            'object_type' => 'lead',
            'object_id' => $lead->id,
            'action_type' => 'assignment_message_sent',
            'new_value' => json_encode(['message_id' => $message->id, 'channel' => $channel]),
        ]);

        return $message->load('sender:id,name,role');
    }

    public function markLeadThreadRead(Lead $lead, User $user): void
    {
        if (! $this->leadSupportsMessages()) {
            return;
        }

        Message::query()
            ->where('lead_id', $lead->id)
            ->whereIn('channel', self::CONTRACTOR_VISIBLE_CHANNELS)
            ->where('sender_id', '!=', $user->id)
            ->where('is_read', false)
            ->update(['is_read' => true]);
    }

    /**
     * Attach lead-stage messages to the new job so the same thread continues.
     */
    public function carryForwardOnConvert(Lead $lead, Job $job): int
    {
        if (! $this->leadSupportsMessages()) {
            return 0;
        }

        return Message::query()
            ->where('lead_id', $lead->id)
            ->where(function ($q) {
                $q->whereNull('job_id')->orWhere('job_id', 0);
            })
            ->update(['job_id' => $job->id]);
    }

    private function notifyCounterpart(Lead $lead, User $sender, Message $message, ?int $receiverId): void
    {
        if (! $receiverId) {
            return;
        }

        $receiver = User::find($receiverId);
        if (! $receiver) {
            return;
        }

        $label = $lead->address ?: ($lead->contact_name ?: 'lead #'.$lead->id);

        if (SmsService::phoneForUser($receiver)) {
            $this->sms->sendToUser(
                $receiver,
                "New message from {$sender->name} about site visit / lead at {$label}.",
                'new_message_assignment',
                null
            );
        }

        if ($receiver->email && ! str_contains((string) $receiver->email, '@placeholder.')) {
            $url = SmsMessageTemplates::frontendUrl('leads/'.$lead->id);
            $this->email->send(
                $receiver->email,
                'New message on assignment',
                'emails.notification',
                [
                    'heading' => 'New message',
                    'body' => "{$sender->name} sent a message about {$label}:\n\n{$message->content}",
                    'actionUrl' => $url,
                    'actionLabel' => 'Open assignment',
                ],
                'new_message_assignment',
                $receiver->id,
                null
            );
        }
    }
}
