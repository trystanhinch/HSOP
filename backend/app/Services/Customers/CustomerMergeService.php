<?php

namespace App\Services\Customers;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerMergeLog;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Lead;
use App\Models\Message;
use App\Models\NextAction;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\ReviewFeedback;
use App\Models\SiteVisit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CustomerMergeService
{
    public function __construct(
        private CustomerValidationService $validation,
    ) {}

    /**
     * @param  list<int>  $customerIds  customers.id values (profile PKs)
     * @param  array<string, mixed>  $fieldChoices  name|phone|email|address|communication_preference|consent_* from chosen customer id or raw value
     * @return array{primary: Customer, log: CustomerMergeLog, counts: array<string, int>}
     */
    public function merge(array $customerIds, int $primaryCustomerId, User $actor, array $fieldChoices = [], bool $simulateFailure = false): array
    {
        $customerIds = array_values(array_unique(array_map('intval', $customerIds)));
        if (count($customerIds) < 2) {
            throw new \InvalidArgumentException('Select at least two customer records to merge.');
        }
        if (! in_array($primaryCustomerId, $customerIds, true)) {
            throw new \InvalidArgumentException('Primary customer must be one of the selected records.');
        }

        $customers = Customer::withTestData()
            ->whereIn('id', $customerIds)
            ->whereNull('merged_into_customer_id')
            ->get()
            ->keyBy('id');

        if ($customers->count() !== count($customerIds)) {
            throw new \InvalidArgumentException('One or more customers were not found or already merged.');
        }

        $primary = $customers->get($primaryCustomerId);
        $secondaries = $customers->except($primaryCustomerId);

        $snapshot = $customers->map(fn (Customer $c) => [
            'id' => $c->id,
            'user_id' => $c->user_id,
            'name' => $c->name,
            'phone' => $c->phone,
            'email' => $c->email,
            'address' => $c->address,
            'communication_preference' => $c->communication_preference,
            'do_not_contact' => $c->do_not_contact,
            'consent_source' => $c->consent_source,
            'consent_recorded_at' => $c->consent_recorded_at?->toIso8601String(),
            'data_quality_flags' => $c->data_quality_flags,
            'duplicate_group_id' => $c->duplicate_group_id,
        ])->values()->all();

        $log = CustomerMergeLog::create([
            'primary_customer_id' => $primary->id,
            'merged_customer_ids' => $secondaries->keys()->values()->all(),
            'actor_id' => $actor->id,
            'pre_merge_snapshot' => $snapshot,
            'field_choices' => $fieldChoices,
            'status' => 'pending',
        ]);

        try {
            $counts = DB::transaction(function () use ($primary, $secondaries, $fieldChoices, $simulateFailure) {
                $primaryUserId = (int) $primary->user_id;
                $counts = [
                    'leads' => 0,
                    'jobs' => 0,
                    'quotes' => 0,
                    'invoices' => 0,
                    'payments' => 0,
                    'messages' => 0,
                    'site_visits' => 0,
                    'bookings' => 0,
                    'next_actions' => 0,
                    'review_feedback' => 0,
                ];

                foreach ($secondaries as $secondary) {
                    $fromUserId = (int) $secondary->user_id;
                    if ($fromUserId === $primaryUserId) {
                        continue;
                    }

                    $counts['leads'] += Lead::withTestData()->where('customer_id', $fromUserId)->update(['customer_id' => $primaryUserId]);
                    $counts['jobs'] += (int) DB::table('jobs')->where('customer_id', $fromUserId)->update(['customer_id' => $primaryUserId]);
                    $counts['quotes'] += (int) DB::table('quotes')->where('customer_id', $fromUserId)->update(['customer_id' => $primaryUserId]);
                    $counts['invoices'] += (int) DB::table('invoices')->where('customer_id', $fromUserId)->update(['customer_id' => $primaryUserId]);

                    if (Schema::hasTable('site_visits')) {
                        $counts['site_visits'] += SiteVisit::withTestData()->where('customer_id', $fromUserId)->update(['customer_id' => $primaryUserId]);
                    }

                    if (Schema::hasTable('review_feedback')) {
                        $counts['review_feedback'] += ReviewFeedback::query()->where('customer_id', $fromUserId)->update(['customer_id' => $primaryUserId]);
                    }

                    // Messages: reassign sender/receiver when they point at the secondary customer user
                    $counts['messages'] += Message::query()->where('sender_id', $fromUserId)->update(['sender_id' => $primaryUserId]);
                    $counts['messages'] += Message::query()->where('receiver_id', $fromUserId)->update(['receiver_id' => $primaryUserId]);

                    // Payments follow invoices; also update any orphan payment user refs if column exists
                    if (Schema::hasColumn('payments', 'customer_id')) {
                        $counts['payments'] += Payment::withTestData()->where('customer_id', $fromUserId)->update(['customer_id' => $primaryUserId]);
                    }

                    // Bookings often link via lead; update customer if column exists
                    if (Schema::hasTable('bookings') && Schema::hasColumn('bookings', 'customer_id')) {
                        $counts['bookings'] += Booking::withTestData()->where('customer_id', $fromUserId)->update(['customer_id' => $primaryUserId]);
                    }

                    // Next actions responsible_user_id stays; subject stays on lead/job — already moved via lead/job customer_id
                    if (Schema::hasColumn('next_actions', 'responsible_user_id')) {
                        // No customer_id on next_actions typically; count stays 0 unless we find customer-scoped rows
                        $counts['next_actions'] += 0;
                    }

                    if ($simulateFailure) {
                        throw new \RuntimeException('Simulated merge failure for rollback test.');
                    }

                    $secondary->forceFill([
                        'merged_into_customer_id' => $primary->id,
                        'merged_at' => now(),
                        'is_duplicate_primary' => false,
                        'duplicate_group_id' => $primary->duplicate_group_id ?? $secondary->duplicate_group_id,
                    ])->save();

                    // Anonymize secondary auth user but keep row for FK audit trail (do not delete — cascades to customers).
                    if ($fromUserId && $fromUserId !== $primaryUserId) {
                        User::whereKey($fromUserId)->update([
                            'email' => 'merged-'.$secondary->id.'-'.$fromUserId.'@merged.hsop.local',
                            'phone' => null,
                            'status' => 'inactive',
                        ]);
                    }
                }

                $this->applyFieldChoices($primary, $secondaries, $fieldChoices);
                $primary->refresh();

                if ($primary->user_id) {
                    User::whereKey($primary->user_id)->update(array_filter([
                        'name' => $primary->name,
                        'phone' => $primary->phone,
                        'email' => $primary->email,
                    ]));
                }

                $flags = $this->validation->evaluateFlags($primary->name, $primary->phone, $primary->email, $primary->address);
                $primary->forceFill([
                    'data_quality_flags' => $flags === [] ? null : $flags,
                    'phone_normalized' => $this->validation->normalizePhoneE164($primary->phone),
                    'is_duplicate_primary' => true,
                    'duplicate_group_id' => null,
                    'merged_into_customer_id' => null,
                    'merged_at' => null,
                ])->save();

                return $counts;
            });

            $log->update([
                'status' => 'completed',
                'reassignment_counts' => $counts,
            ]);

            return [
                'primary' => $primary->fresh(),
                'log' => $log->fresh(),
                'counts' => $counts,
            ];
        } catch (Throwable $e) {
            Log::error('Customer merge failed — rolled back', [
                'primary_customer_id' => $primaryCustomerId,
                'merged_customer_ids' => $secondaries->keys()->all(),
                'error' => $e->getMessage(),
            ]);
            $log->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Customer>  $secondaries
     * @param  array<string, mixed>  $fieldChoices
     */
    private function applyFieldChoices(Customer $primary, $secondaries, array $fieldChoices): void
    {
        $all = $secondaries->prepend($primary)->keyBy('id');
        $fields = ['name', 'phone', 'email', 'address', 'communication_preference', 'do_not_contact', 'consent_source', 'consent_recorded_at'];
        $updates = [];

        foreach ($fields as $field) {
            if (! array_key_exists($field, $fieldChoices)) {
                continue;
            }
            $choice = $fieldChoices[$field];
            if (is_int($choice) || (is_string($choice) && ctype_digit($choice))) {
                $source = $all->get((int) $choice);
                if ($source) {
                    $updates[$field] = $source->{$field};
                }
            } else {
                $updates[$field] = $choice;
            }
        }

        if ($updates !== []) {
            if (isset($updates['phone'])) {
                $updates['phone_normalized'] = $this->validation->normalizePhoneE164($updates['phone']);
            }
            $primary->forceFill($updates)->save();
        }
    }
}
