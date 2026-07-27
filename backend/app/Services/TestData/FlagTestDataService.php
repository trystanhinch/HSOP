<?php

namespace App\Services\TestData;

use App\Models\ActivityTimelineEntry;
use App\Models\Booking;
use App\Models\Brand;
use App\Models\Company;
use App\Models\CompanySource;
use App\Models\Contractor;
use App\Models\Customer;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Lead;
use App\Models\NextAction;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\PricingRule;
use App\Models\Quote;
use App\Models\SiteVisit;
use App\Models\SmsLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Identifies known QA / placeholder records and flags is_test_data (never deletes).
 */
class FlagTestDataService
{
    /** @var list<string> */
    public const EMAIL_NEEDLES = [
        'rotation.test',
        'walkthrough.tester',
        '@example.com',
        '@example.local',
        '@placeholder.hsop.local',
        '@servicemail.ald',
    ];

    /** @var list<string> */
    public const NAME_NEEDLES = [
        'rotation test',
        'walkthrough tester',
        'datefix',
        'date fix',
    ];

    /** @var list<string> */
    public const COMPANY_NAMES = [
        'Example Roofing Co',
        'Example Roofing Design',
    ];

    /**
     * @return array{
     *   dry_run: bool,
     *   before: array<string, int>,
     *   after: array<string, int>,
     *   flagged: array<string, list<array<string, mixed>>>,
     *   needs_manual_review: list<array<string, mixed>>,
     *   totals: array{would_flag: int, flagged: int, review: int}
     * }
     */
    public function run(bool $apply = false): array
    {
        $before = $this->testCounts();
        $flagged = [];
        $review = [];

        $this->collectUsers($flagged, $review);
        $this->collectCustomers($flagged, $review);
        $this->collectCompanies($flagged, $review);
        $this->collectCompanySources($flagged, $review);
        $this->collectBrands($flagged, $review);
        $this->collectLeads($flagged, $review);
        $this->collectJobs($flagged, $review);
        $this->collectPricingRules($flagged, $review);
        $this->collectContractors($flagged, $review);

        // Cascade dependents of already-identified parents (even if parents already flagged)
        $this->cascadeFromParents($flagged);

        $wouldFlag = 0;
        foreach ($flagged as $rows) {
            $wouldFlag += count($rows);
        }

        if ($apply) {
            foreach ($flagged as $table => $rows) {
                foreach ($rows as $row) {
                    $this->applyFlag($table, (int) $row['id']);
                }
            }
        }

        $after = $this->testCounts();

        return [
            'dry_run' => ! $apply,
            'before' => $before,
            'after' => $after,
            'flagged' => $flagged,
            'needs_manual_review' => $review,
            'totals' => [
                'would_flag' => $wouldFlag,
                'flagged' => $apply ? $wouldFlag : 0,
                'review' => count($review),
            ],
        ];
    }

    /**
     * @return array<string, int>
     */
    public function testCounts(): array
    {
        $counts = [];
        foreach ($this->modelMap() as $table => $class) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'is_test_data')) {
                continue;
            }
            /** @var Model $class */
            $counts[$table] = $class::onlyTestData()->count();
        }

        return $counts;
    }

    /**
     * @return array<string, class-string<Model>>
     */
    public function modelMap(): array
    {
        return [
            'users' => User::class,
            'companies' => Company::class,
            'company_sources' => CompanySource::class,
            'brands' => Brand::class,
            'contractors' => Contractor::class,
            'customers' => Customer::class,
            'leads' => Lead::class,
            'jobs' => Job::class,
            'quotes' => Quote::class,
            'invoices' => Invoice::class,
            'payments' => Payment::class,
            'payouts' => Payout::class,
            'pricing_rules' => PricingRule::class,
            'sms_logs' => SmsLog::class,
            'email_logs' => EmailLog::class,
            'activity_timeline_entries' => ActivityTimelineEntry::class,
            'next_actions' => NextAction::class,
            'site_visits' => SiteVisit::class,
            'bookings' => Booking::class,
        ];
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $flagged
     * @param  list<array<string, mixed>>  $review
     */
    private function collectUsers(array &$flagged, array &$review): void
    {
        User::withTestData()->orderBy('id')->get()->each(function (User $user) use (&$flagged, &$review) {
            if ($user->isTestData()) {
                return;
            }
            $email = strtolower((string) $user->email);
            $name = strtolower((string) $user->name);
            if ($this->emailMatches($email) || $this->nameMatches($name)) {
                $this->push($flagged, 'users', $user->id, $user->email ?: $user->name, 'email_or_name_pattern');

                return;
            }
            if ($this->looksAmbiguous($email, $name)) {
                $review[] = [
                    'table' => 'users',
                    'id' => $user->id,
                    'label' => $user->email,
                    'reason' => 'ambiguous_testish_name_or_email',
                ];
            }
        });
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $flagged
     * @param  list<array<string, mixed>>  $review
     */
    private function collectCustomers(array &$flagged, array &$review): void
    {
        Customer::withTestData()->orderBy('id')->get()->each(function (Customer $c) use (&$flagged, &$review) {
            if ($c->isTestData()) {
                return;
            }
            $email = strtolower((string) $c->email);
            $name = strtolower((string) $c->name);
            if ($this->emailMatches($email) || $this->nameMatches($name)) {
                $this->push($flagged, 'customers', $c->id, $c->email ?: $c->name, 'email_or_name_pattern');

                return;
            }
            if ($this->looksAmbiguous($email, $name)) {
                $review[] = [
                    'table' => 'customers',
                    'id' => $c->id,
                    'label' => $c->email ?: $c->name,
                    'reason' => 'ambiguous_testish_name_or_email',
                ];
            }
        });
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $flagged
     * @param  list<array<string, mixed>>  $review
     */
    private function collectCompanies(array &$flagged, array &$review): void
    {
        Company::withTestData()->orderBy('id')->get()->each(function (Company $c) use (&$flagged, &$review) {
            if ($c->isTestData()) {
                return;
            }
            // Name match only — never flag by GST (prod may use a placeholder GST pending A-13).
            if (in_array($c->name, self::COMPANY_NAMES, true)) {
                $this->push($flagged, 'companies', $c->id, $c->name, 'example_company_name');

                return;
            }
            if (Str::contains(strtolower($c->name), 'example roofing')) {
                $this->push($flagged, 'companies', $c->id, $c->name, 'example_roofing_name');

                return;
            }
            // Legacy HSOP branding — do not auto-flag; manual review only.
            if (Str::contains(strtolower($c->name), 'hsop drywall')) {
                $review[] = [
                    'table' => 'companies',
                    'id' => $c->id,
                    'label' => $c->name,
                    'reason' => 'legacy_hsop_branding_manual_review',
                ];
            }
        });
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $flagged
     * @param  list<array<string, mixed>>  $review
     */
    private function collectCompanySources(array &$flagged, array &$review): void
    {
        if (! Schema::hasTable('company_sources')) {
            return;
        }
        CompanySource::withTestData()->orderBy('id')->get()->each(function (CompanySource $c) use (&$flagged, &$review) {
            if ($c->isTestData()) {
                return;
            }
            $name = (string) $c->company_name;
            if (in_array($name, self::COMPANY_NAMES, true) || Str::contains(strtolower($name), 'example roofing')) {
                $this->push($flagged, 'company_sources', $c->id, $name, 'example_company_source');

                return;
            }
            if (($c->status ?? null) === 'testing') {
                $this->push($flagged, 'company_sources', $c->id, $name, 'status_testing');

                return;
            }
            if (Str::contains(strtolower($name), 'hsop drywall')) {
                $review[] = [
                    'table' => 'company_sources',
                    'id' => $c->id,
                    'label' => $name,
                    'reason' => 'legacy_hsop_branding_manual_review',
                ];
            }
        });
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $flagged
     * @param  list<array<string, mixed>>  $review
     */
    private function collectBrands(array &$flagged, array &$review): void
    {
        if (! Schema::hasTable('brands')) {
            return;
        }
        Brand::withTestData()->orderBy('id')->get()->each(function (Brand $b) use (&$flagged, &$review) {
            if ($b->isTestData()) {
                return;
            }
            $domain = strtolower((string) $b->domain);
            $name = (string) ($b->company_name ?? '');
            if (Str::contains($domain, 'example-roofing') || Str::contains(strtolower($name), 'example roofing')) {
                $this->push($flagged, 'brands', $b->id, $name.' ('.$domain.')', 'example_brand');

                return;
            }
            if (Str::contains(strtolower($name), 'hsop drywall')) {
                $review[] = [
                    'table' => 'brands',
                    'id' => $b->id,
                    'label' => $name,
                    'reason' => 'legacy_hsop_branding_manual_review',
                ];
            }
        });
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $flagged
     * @param  list<array<string, mixed>>  $review
     */
    private function collectLeads(array &$flagged, array &$review): void
    {
        Lead::withTestData()->orderBy('id')->get()->each(function (Lead $lead) use (&$flagged, &$review) {
            if ($lead->isTestData()) {
                return;
            }
            $email = strtolower((string) $lead->email);
            $name = strtolower((string) $lead->contact_name);
            $desc = strtolower((string) ($lead->project_description ?? ''));
            $notes = strtolower((string) ($lead->notes ?? ''));
            if ($this->emailMatches($email) || $this->nameMatches($name)
                || $this->nameMatches($desc) || Str::contains($notes, 'datefix')
                || Str::contains($notes, 'date-fix') || Str::contains($desc, 'date fix')) {
                $this->push($flagged, 'leads', $lead->id, $lead->contact_name ?: $lead->email, 'lead_pattern');

                return;
            }
            if ($this->looksAmbiguous($email, $name)) {
                $review[] = [
                    'table' => 'leads',
                    'id' => $lead->id,
                    'label' => $lead->contact_name ?: $lead->email,
                    'reason' => 'ambiguous_testish_name_or_email',
                ];
            }
        });
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $flagged
     * @param  list<array<string, mixed>>  $review
     */
    private function collectJobs(array &$flagged, array &$review): void
    {
        Job::withTestData()->orderBy('id')->get()->each(function (Job $job) use (&$flagged, &$review) {
            if ($job->isTestData()) {
                return;
            }
            $hay = strtolower(implode(' ', array_filter([
                $job->address ?? null,
                $job->job_title ?? null,
                $job->scope_of_work ?? null,
                $job->notes ?? null,
            ])));
            if ($this->nameMatches($hay) || Str::contains($hay, 'date fix') || Str::contains($hay, 'datefix')) {
                $this->push($flagged, 'jobs', $job->id, $job->address ?: ('#'.$job->id), 'job_pattern');

                return;
            }
            if (Str::contains($hay, 'test') && Str::contains($hay, 'verify')) {
                $review[] = [
                    'table' => 'jobs',
                    'id' => $job->id,
                    'label' => $job->address ?: ('#'.$job->id),
                    'reason' => 'ambiguous_test_verify_wording',
                ];
            }
        });
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $flagged
     * @param  list<array<string, mixed>>  $review
     */
    private function collectPricingRules(array &$flagged, array &$review): void
    {
        if (! Schema::hasTable('pricing_rules')) {
            return;
        }
        PricingRule::withTestData()->orderBy('id')->get()->each(function (PricingRule $rule) use (&$flagged) {
            if ($rule->isTestData()) {
                return;
            }
            $notes = strtoupper((string) ($rule->notes ?? ''));
            $type = strtoupper((string) ($rule->rule_type ?? ''));
            $status = strtoupper((string) ($rule->status ?? ''));
            if ($rule->is_placeholder
                || Str::contains($notes, 'PLACEHOLDER')
                || $type === 'PLACEHOLDER'
                || $status === 'PLACEHOLDER') {
                $this->push(
                    $flagged,
                    'pricing_rules',
                    $rule->id,
                    ($rule->service_category ?: 'rule').' #'.$rule->id,
                    'placeholder_pricing_rule'
                );
            }
        });
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $flagged
     * @param  list<array<string, mixed>>  $review
     */
    private function collectContractors(array &$flagged, array &$review): void
    {
        if (! Schema::hasTable('contractors')) {
            return;
        }
        Contractor::withTestData()->orderBy('id')->get()->each(function (Contractor $c) use (&$flagged, &$review) {
            if ($c->isTestData()) {
                return;
            }
            $email = strtolower((string) ($c->email ?? ''));
            $name = strtolower(implode(' ', array_filter([
                $c->contact_name ?? null,
                $c->legal_name ?? null,
                $c->operating_name ?? null,
            ])));
            if ($this->emailMatches($email) || $this->nameMatches($name)) {
                $this->push($flagged, 'contractors', $c->id, $c->email ?: ($c->contact_name ?: $c->legal_name), 'contractor_pattern');

                return;
            }
            if ($this->looksAmbiguous($email, $name)) {
                $review[] = [
                    'table' => 'contractors',
                    'id' => $c->id,
                    'label' => $c->email ?: ($c->contact_name ?: $c->legal_name),
                    'reason' => 'ambiguous_testish_name_or_email',
                ];
            }
        });
    }

    /**
     * Cascade quotes/invoices/payments/payouts/logs/timeline from flagged parents.
     *
     * @param  array<string, list<array<string, mixed>>>  $flagged
     */
    private function cascadeFromParents(array &$flagged): void
    {
        $leadIds = $this->ids($flagged, 'leads');
        $jobIds = $this->ids($flagged, 'jobs');
        $userIds = $this->ids($flagged, 'users');
        $customerIds = $this->ids($flagged, 'customers');

        // Jobs linked to flagged leads
        if ($leadIds !== []) {
            Job::withTestData()->whereIn('lead_id', $leadIds)->where('is_test_data', false)
                ->each(fn (Job $j) => $this->push($flagged, 'jobs', $j->id, 'lead:'.$j->lead_id, 'cascade_from_lead'));
            $jobIds = array_values(array_unique(array_merge($jobIds, $this->ids($flagged, 'jobs'))));
        }

        // Customers linked to flagged users
        if ($userIds !== []) {
            Customer::withTestData()->whereIn('user_id', $userIds)->where('is_test_data', false)
                ->each(fn (Customer $c) => $this->push($flagged, 'customers', $c->id, 'user:'.$c->user_id, 'cascade_from_user'));
            Contractor::withTestData()->whereIn('user_id', $userIds)->where('is_test_data', false)
                ->each(fn (Contractor $c) => $this->push($flagged, 'contractors', $c->id, 'user:'.$c->user_id, 'cascade_from_user'));
        }

        if ($jobIds !== []) {
            Quote::withTestData()->whereIn('job_id', $jobIds)->where('is_test_data', false)
                ->each(fn (Quote $q) => $this->push($flagged, 'quotes', $q->id, 'job:'.$q->job_id, 'cascade_from_job'));
            Invoice::withTestData()->whereIn('job_id', $jobIds)->where('is_test_data', false)
                ->each(fn (Invoice $i) => $this->push($flagged, 'invoices', $i->id, 'job:'.$i->job_id, 'cascade_from_job'));
            Payout::withTestData()->whereIn('job_id', $jobIds)->where('is_test_data', false)
                ->each(fn (Payout $p) => $this->push($flagged, 'payouts', $p->id, 'job:'.$p->job_id, 'cascade_from_job'));
            SmsLog::withTestData()->whereIn('related_job_id', $jobIds)->where('is_test_data', false)
                ->each(fn (SmsLog $l) => $this->push($flagged, 'sms_logs', $l->id, 'job:'.$l->related_job_id, 'cascade_from_job'));
            EmailLog::withTestData()->whereIn('related_job_id', $jobIds)->where('is_test_data', false)
                ->each(fn (EmailLog $l) => $this->push($flagged, 'email_logs', $l->id, 'job:'.$l->related_job_id, 'cascade_from_job'));
            if (Schema::hasTable('bookings') && Schema::hasColumn('bookings', 'job_id')) {
                Booking::withTestData()->whereIn('job_id', $jobIds)->where('is_test_data', false)
                    ->each(fn (Booking $b) => $this->push($flagged, 'bookings', $b->id, 'job:'.$b->job_id, 'cascade_from_job'));
            }
        }

        $invoiceIds = $this->ids($flagged, 'invoices');
        if ($invoiceIds !== []) {
            Payment::withTestData()->whereIn('invoice_id', $invoiceIds)->where('is_test_data', false)
                ->each(fn (Payment $p) => $this->push($flagged, 'payments', $p->id, 'invoice:'.$p->invoice_id, 'cascade_from_invoice'));
        }

        if ($leadIds !== []) {
            Quote::withTestData()->whereIn('lead_id', $leadIds)->where('is_test_data', false)
                ->each(fn (Quote $q) => $this->push($flagged, 'quotes', $q->id, 'lead:'.$q->lead_id, 'cascade_from_lead'));
            if (Schema::hasTable('site_visits') && Schema::hasColumn('site_visits', 'lead_id')) {
                SiteVisit::withTestData()->whereIn('lead_id', $leadIds)->where('is_test_data', false)
                    ->each(fn (SiteVisit $s) => $this->push($flagged, 'site_visits', $s->id, 'lead:'.$s->lead_id, 'cascade_from_lead'));
            }
            if (Schema::hasTable('bookings') && Schema::hasColumn('bookings', 'lead_id')) {
                Booking::withTestData()->whereIn('lead_id', $leadIds)->where('is_test_data', false)
                    ->each(fn (Booking $b) => $this->push($flagged, 'bookings', $b->id, 'lead:'.$b->lead_id, 'cascade_from_lead'));
            }
            NextAction::withTestData()
                ->where('subject_type', (new Lead)->getMorphClass())
                ->whereIn('subject_id', $leadIds)
                ->where('is_test_data', false)
                ->each(fn (NextAction $n) => $this->push($flagged, 'next_actions', $n->id, 'lead:'.$n->subject_id, 'cascade_from_lead'));
            ActivityTimelineEntry::withTestData()
                ->where('subject_type', (new Lead)->getMorphClass())
                ->whereIn('subject_id', $leadIds)
                ->where('is_test_data', false)
                ->each(fn (ActivityTimelineEntry $e) => $this->push($flagged, 'activity_timeline_entries', $e->id, 'lead:'.$e->subject_id, 'cascade_from_lead'));
        }

        if ($jobIds !== []) {
            NextAction::withTestData()
                ->where('subject_type', (new Job)->getMorphClass())
                ->whereIn('subject_id', $jobIds)
                ->where('is_test_data', false)
                ->each(fn (NextAction $n) => $this->push($flagged, 'next_actions', $n->id, 'job:'.$n->subject_id, 'cascade_from_job'));
            ActivityTimelineEntry::withTestData()
                ->where('subject_type', (new Job)->getMorphClass())
                ->whereIn('subject_id', $jobIds)
                ->where('is_test_data', false)
                ->each(fn (ActivityTimelineEntry $e) => $this->push($flagged, 'activity_timeline_entries', $e->id, 'job:'.$e->subject_id, 'cascade_from_job'));
        }

        if ($userIds !== []) {
            SmsLog::withTestData()->whereIn('user_id', $userIds)->where('is_test_data', false)
                ->each(fn (SmsLog $l) => $this->push($flagged, 'sms_logs', $l->id, 'user:'.$l->user_id, 'cascade_from_user'));
            EmailLog::withTestData()->whereIn('user_id', $userIds)->where('is_test_data', false)
                ->each(fn (EmailLog $l) => $this->push($flagged, 'email_logs', $l->id, 'user:'.$l->user_id, 'cascade_from_user'));
        }
    }

    private function emailMatches(string $email): bool
    {
        if ($email === '') {
            return false;
        }
        foreach (self::EMAIL_NEEDLES as $needle) {
            if (Str::contains($email, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    private function nameMatches(string $name): bool
    {
        if ($name === '') {
            return false;
        }
        foreach (self::NAME_NEEDLES as $needle) {
            if (Str::contains($name, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function looksAmbiguous(string $email, string $name): bool
    {
        // Broad "test" alone is ambiguous — never auto-flag.
        $hay = $email.' '.$name;
        if ($hay === ' ') {
            return false;
        }
        if (preg_match('/\b(qa|dummy|fake|sample|lorem)\b/i', $hay)) {
            return true;
        }
        if (preg_match('/\btest\b/i', $hay) && ! $this->nameMatches($hay) && ! $this->emailMatches($email)) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $flagged
     */
    private function push(array &$flagged, string $table, int $id, string $label, string $reason): void
    {
        $flagged[$table] ??= [];
        foreach ($flagged[$table] as $existing) {
            if ((int) $existing['id'] === $id) {
                return;
            }
        }
        $flagged[$table][] = [
            'id' => $id,
            'label' => $label,
            'reason' => $reason,
        ];
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $flagged
     * @return list<int>
     */
    private function ids(array $flagged, string $table): array
    {
        return array_map(fn ($r) => (int) $r['id'], $flagged[$table] ?? []);
    }

    private function applyFlag(string $table, int $id): void
    {
        $map = $this->modelMap();
        $class = $map[$table] ?? null;
        if (! $class || ! Schema::hasTable($table)) {
            return;
        }
        /** @var Model|null $model */
        $model = $class::withTestData()->find($id);
        if ($model && ! $model->isTestData()) {
            $model->forceFill(['is_test_data' => true])->save();
        }
    }
}
