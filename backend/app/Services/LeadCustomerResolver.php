<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Support\Str;

class LeadCustomerResolver
{
    public function resolveForLead(Lead $lead): int
    {
        if ($lead->customer_id) {
            return $lead->customer_id;
        }

        if ($lead->email) {
            $existing = User::where('email', $lead->email)->where('role', 'customer')->first();
            if ($existing) {
                $lead->update(['customer_id' => $existing->id]);
                $this->ensureCustomerProfile($existing, $lead);

                return $existing->id;
            }
        }

        $email = $lead->email ?: ('lead'.$lead->id.'_'.Str::random(6).'@placeholder.hsop.local');

        $user = User::create([
            'name' => $lead->contact_name,
            'email' => $email,
            'password' => bcrypt(Str::random(16)),
            'role' => 'customer',
            'status' => 'active',
            'phone' => $lead->phone,
        ]);

        Customer::create([
            'user_id' => $user->id,
            'name' => $lead->contact_name,
            'phone' => $lead->phone,
            'email' => $lead->email,
            'address' => $lead->address,
        ]);

        $lead->update(['customer_id' => $user->id]);

        return $user->id;
    }

    public function repairJobCustomers(): array
    {
        $repaired = [];

        $jobs = \App\Models\Job::with('lead')->whereNull('customer_id')->whereNotNull('lead_id')->get();
        foreach ($jobs as $job) {
            if (! $job->lead) {
                continue;
            }
            $customerId = $this->resolveForLead($job->lead);
            $job->update(['customer_id' => $customerId]);
            Quote::where('job_id', $job->id)->update(['customer_id' => $customerId]);
            $repaired[] = ['job_id' => $job->id, 'customer_id' => $customerId, 'lead' => $job->lead->contact_name];
        }

        Quote::whereNull('customer_id')
            ->whereHas('job', fn ($q) => $q->whereNotNull('customer_id'))
            ->with('job:id,customer_id')
            ->get()
            ->each(function (Quote $quote) use (&$repaired) {
                $quote->update(['customer_id' => $quote->job->customer_id]);
                $repaired[] = ['quote_id' => $quote->id, 'customer_id' => $quote->job->customer_id, 'lead' => 'quote sync'];
            });

        return $repaired;
    }

    public function repairContractorIds(): array
    {
        $fixed = [];
        $jobs = \App\Models\Job::whereNotNull('contractor_id')->get();

        foreach ($jobs as $job) {
            $valid = User::where('id', $job->contractor_id)->where('role', 'contractor')->exists();
            if ($valid) {
                continue;
            }

            $profile = \App\Models\Contractor::find($job->contractor_id);
            if ($profile?->user_id) {
                $job->update(['contractor_id' => $profile->user_id]);
                $fixed[] = ['job_id' => $job->id, 'contractor_user_id' => $profile->user_id];
            }
        }

        return $fixed;
    }

    private function ensureCustomerProfile(User $user, Lead $lead): void
    {
        if (Customer::where('user_id', $user->id)->exists()) {
            return;
        }

        Customer::create([
            'user_id' => $user->id,
            'name' => $lead->contact_name,
            'phone' => $lead->phone,
            'email' => $lead->email,
            'address' => $lead->address,
        ]);
    }
}
