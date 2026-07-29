<?php

namespace App\Services\LeadIntake;

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\CompanySource;
use App\Models\CompanySourceVersion;
use App\Models\IntakeQuarantine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A-25 — Test Parser (dry-run) + per-source health + versioned rule changes.
 */
class CompanySourceParserService
{
    public const PARSER_TYPE = 'lead_email_v1';

    public const PARSER_VERSION = '1.0';

    public function __construct(
        private LeadEmailParser $parser,
        private LeadIntakeQuarantineEvaluator $evaluator,
    ) {}

    /**
     * Dry-run parse + match — never creates a lead.
     *
     * @return array<string, mixed>
     */
    public function testParser(string $rawEmail): array
    {
        $parsed = $this->parser->parse($rawEmail);
        $evaluation = $this->evaluator->evaluate($parsed, $rawEmail);
        $source = ! empty($evaluation['company_source_id'])
            ? CompanySource::with('defaultPm:id,name,email')->find($evaluation['company_source_id'])
            : null;

        return [
            'creates_lead' => false,
            'parser_type' => self::PARSER_TYPE,
            'parser_version' => self::PARSER_VERSION,
            'email_format' => $parsed->emailFormat,
            'extracted' => [
                'contact_name' => $evaluation['parsed_fields']['contact_name'] ?? $parsed->contactName(),
                'phone' => $evaluation['parsed_fields']['phone'] ?? $parsed->phone,
                'email' => $evaluation['parsed_fields']['email'] ?? $parsed->email,
                'address' => $evaluation['parsed_fields']['address'] ?? $parsed->address,
                'service_requested' => $evaluation['parsed_fields']['service_requested'] ?? $parsed->serviceRequested,
                'project_description' => $evaluation['parsed_fields']['project_description'] ?? $parsed->projectDescription,
                'source_label' => $evaluation['parsed_fields']['source_label'] ?? $parsed->sourceLabel,
                'subject' => $evaluation['subject'] ?? $parsed->subject,
                'from_header' => $evaluation['from_header'] ?? null,
            ],
            'evaluation' => [
                'action' => $evaluation['action'],
                'reason' => $evaluation['reason'],
                'validation_errors' => $evaluation['validation_errors'] ?? [],
            ],
            'matched_source' => $source ? [
                'id' => $source->id,
                'company_name' => $source->company_name,
                'domain' => $source->domain,
                'sender_identity' => $source->sender_identity,
                'status' => $source->status,
                'priority' => $source->priority ?? 100,
                'default_pm' => $source->defaultPm?->only(['id', 'name', 'email']),
            ] : null,
            'matched_needle' => $evaluation['matched_needle'] ?? null,
            'match_method' => $evaluation['match_method'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function healthFor(CompanySource $source): array
    {
        $q = IntakeQuarantine::query()->where('company_source_id', $source->id);

        $lastReceived = (clone $q)->max('created_at');
        $lastSuccess = (clone $q)->whereIn('status', ['auto_approved', 'approved'])->max('updated_at');
        $ignored = (clone $q)->where('status', 'ignored')->count();
        $failures = (clone $q)->where('status', 'pending')->count();
        $successes = (clone $q)->whereIn('status', ['auto_approved', 'approved'])->count();

        $recentErrors = IntakeQuarantine::query()
            ->where('company_source_id', $source->id)
            ->where(function ($inner) {
                $inner->where('status', 'pending')
                    ->orWhereNotNull('validation_errors');
            })
            ->latest('id')
            ->limit(5)
            ->get(['id', 'status', 'quarantine_reason', 'validation_errors', 'subject', 'created_at']);

        // Unmatched mail isn't attributed to a source — surface global quarantine backlog for context.
        $unmatchedPending = IntakeQuarantine::query()
            ->whereNull('company_source_id')
            ->where('status', 'pending')
            ->count();

        return [
            'last_received_at' => $lastReceived,
            'last_successful_parse_at' => $lastSuccess,
            'ignored_count' => $ignored,
            'failure_count' => $failures,
            'success_count' => $successes,
            'recent_errors' => $recentErrors,
            'unmatched_pending_global' => $unmatchedPending,
        ];
    }

    /**
     * @param  array<string, mixed>  $newData
     */
    public function updateVersioned(CompanySource $source, array $newData, User $actor): CompanySource
    {
        $tracked = [
            'company_name', 'domain', 'service_categories', 'google_review_url', 'default_pm_id',
            'default_contractor_ids', 'sender_identity', 'lead_parsing_rule', 'intake_allow_patterns',
            'marketing_cost_monthly', 'status', 'priority', 'parser_type', 'parser_version', 'fallback_behavior',
        ];

        $previous = [];
        foreach ($tracked as $key) {
            $previous[$key] = $source->{$key};
        }

        return DB::transaction(function () use ($source, $newData, $actor, $previous, $tracked) {
            $source->update($newData);
            $fresh = $source->fresh();

            $next = [];
            foreach ($tracked as $key) {
                $next[$key] = $fresh->{$key};
            }

            $version = ((int) CompanySourceVersion::where('company_source_id', $source->id)->max('version')) + 1;

            CompanySourceVersion::create([
                'company_source_id' => $source->id,
                'version' => $version,
                'changed_by' => $actor->id,
                'previous_values' => $previous,
                'new_values' => $next,
                'change_summary' => 'Company source rule updated',
            ]);

            AuditLog::create([
                'user_id' => $actor->id,
                'user_role' => $actor->role,
                'object_type' => 'company_source',
                'object_id' => $source->id,
                'action_type' => 'company_source_updated',
                'previous_value' => $previous,
                'new_value' => $next,
                'reason' => 'Owner updated company source matching rule',
                'created_at' => now(),
            ]);

            return $fresh;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeSource(CompanySource $source): array
    {
        $brand = Brand::query()->where('company_source_id', $source->id)->first(['id', 'company_name', 'domain', 'slug']);

        return array_merge($source->toArray(), [
            'target_brand' => $brand,
            'parser_type' => $source->parser_type ?? self::PARSER_TYPE,
            'parser_version' => $source->parser_version ?? self::PARSER_VERSION,
            'priority' => $source->priority ?? 100,
            'fallback_behavior' => $source->fallback_behavior ?? 'category_then_quarantine',
            'health' => $this->healthFor($source),
            'versions_count' => Schema::hasTable('company_source_versions')
                ? CompanySourceVersion::where('company_source_id', $source->id)->count()
                : 0,
        ]);
    }
}
