<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Contractor;
use App\Models\ContractorDocument;
use App\Models\File;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A-23 — Database health/structure diagnostics (developer + reauth gated).
 * Default response: metadata + row counts only. Samples require include_samples=1
 * and are PII-redacted.
 */
class AdminController extends Controller
{
    /** Column names never returned in sample rows. */
    private const REDACT_COLUMNS = [
        'email', 'phone', 'password', 'remember_token', 'contact_name', 'address',
        'customer_token', 'portal_token', 'stripe_account_id', 'payment_info',
        'destination_value', 'public_contact_email', 'public_contact_phone',
        'remittance_address', 'gst_number', 'name',
    ];

    public function databaseOverview(Request $request): JsonResponse
    {
        $user = $request->user();
        $includeSamples = $request->boolean('include_samples');

        AuditLog::create([
            'user_id' => $user->id,
            'user_role' => $user->role,
            'object_type' => 'developer_diagnostics',
            'object_id' => $user->id,
            'action_type' => 'database_overview_accessed',
            'new_value' => [
                'include_samples' => $includeSamples,
                'accessed_at' => now()->toIso8601String(),
                'ip' => $request->ip(),
            ],
            'created_at' => now(),
        ]);

        $tables = [
            $this->tableMeta('companies', 'Legal entities / multi-company root', Company::withTestData()->count(), [
                'id', 'name', 'legal_name', 'operating_name', 'slug', 'service_type', 'province', 'timezone', 'currency', 'gst_verification_status', 'is_active', 'is_test_data', 'created_at',
            ], $includeSamples ? $this->redactedSample(Company::withTestData()->orderBy('id')->first()) : null),
            $this->tableMeta('users', 'System users with role-based access', User::count(), [
                'id', 'role', 'status', 'invitation_status', 'is_developer', 'last_login_at', 'created_at',
            ], null, [
                'roles' => User::select('role', DB::raw('count(*) as total'))->groupBy('role')->get(),
            ]),
            $this->tableMeta('leads', 'Customer inquiries', Lead::count(), [
                'id', 'company_id', 'customer_id', 'service_category', 'source', 'assigned_pm_id', 'status', 'site_visit_date', 'is_test_data',
            ], null, [
                'statuses' => Lead::select('status', DB::raw('count(*) as total'))->groupBy('status')->get(),
            ]),
            $this->tableMeta('jobs', 'Work orders', Job::count(), [
                'id', 'company_id', 'lead_id', 'customer_id', 'contractor_id', 'pm_id', 'service_category', 'status', 'is_test_data',
            ], null, [
                'statuses' => Job::select('status', DB::raw('count(*) as total'))->groupBy('status')->get(),
            ]),
            $this->tableMeta('quotes', 'Pricing documents', Quote::count(), [
                'id', 'job_id', 'customer_id', 'status', 'sent_at', 'accepted_at', 'is_test_data',
            ], null, [
                'statuses' => Quote::select('status', DB::raw('count(*) as total'))->groupBy('status')->get(),
            ]),
            $this->tableMeta('invoices', 'Receivables', Invoice::count(), [
                'id', 'job_id', 'customer_id', 'invoice_number', 'amount', 'balance', 'status', 'due_date', 'is_test_data',
            ]),
            $this->tableMeta('payments', 'Payment records', Payment::count(), [
                'id', 'invoice_id', 'amount', 'method', 'paid_status', 'cleared_status', 'paid_date',
            ]),
            $this->tableMeta('payouts', 'Contractor payout queue', Payout::count(), [
                'id', 'job_id', 'contractor_id', 'payout_amount', 'status', 'eligibility_status', 'paid_date',
            ]),
            $this->tableMeta('contractors', 'Contractor profiles', Contractor::count(), [
                'id', 'user_id', 'wcb_status', 'liability_insurance_status', 'approval_status', 'state',
            ]),
            $this->tableMeta('files', 'Uploaded files + contractor documents', File::count() + ContractorDocument::count(), [
                'id', 'uploader_id', 'related_type', 'related_id', 'file_type', 'visibility',
            ]),
        ];

        return response()->json([
            'mode' => $includeSamples ? 'health_with_redacted_samples' : 'health',
            'include_samples' => $includeSamples,
            'samples_note' => $includeSamples
                ? 'Sample rows are PII-redacted. Personal fields are replaced with [REDACTED].'
                : 'Raw row samples are hidden by default. Pass include_samples=1 after an additional confirmation.',
            'schema_engine' => Schema::getConnection()->getDriverName(),
            'tables' => $tables,
        ]);
    }

    /**
     * @param  list<string>  $columns
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function tableMeta(string $name, string $purpose, int $count, array $columns, mixed $sample = null, array $extra = []): array
    {
        $row = [
            'name' => $name,
            'purpose' => $purpose,
            'count' => $count,
            'columns' => $columns,
            'health' => $count >= 0 ? 'ok' : 'unknown',
        ];

        if ($sample !== null) {
            $row['sample'] = $sample;
            $row['sample_redacted'] = true;
        }

        return array_merge($row, $extra);
    }

    private function redactedSample(mixed $model): ?array
    {
        if (! $model) {
            return null;
        }

        $arr = $model->toArray();
        foreach (self::REDACT_COLUMNS as $col) {
            if (array_key_exists($col, $arr) && $arr[$col] !== null && $arr[$col] !== '') {
                $arr[$col] = '[REDACTED]';
            }
        }

        return $arr;
    }
}
