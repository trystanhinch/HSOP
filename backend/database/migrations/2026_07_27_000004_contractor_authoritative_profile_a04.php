<?php

use App\Models\Contractor;
use App\Models\Job;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Audit A-04 / PM-06: authoritative contractor profile.
 *
 * - Adds contractor `state` enum (distinct from approval_status).
 * - Ensures every users.role=contractor has a contractors row.
 * - Adds contractor_profile_id on jobs/payouts pointing at contractors.id
 *   while keeping jobs.contractor_id / payouts.contractor_id as users.id
 *   (auth/ACL identity used throughout the app).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contractors', function (Blueprint $table) {
            if (! Schema::hasColumn('contractors', 'state')) {
                $table->string('state', 32)->default('profile_incomplete')->after('approval_status');
                $table->index('state');
            }
        });

        // Map legacy approval_status → state
        DB::table('contractors')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $state = match ($row->approval_status) {
                    'approved' => 'approved',
                    'suspended' => 'suspended',
                    default => 'profile_incomplete',
                };
                DB::table('contractors')->where('id', $row->id)->update(['state' => $state]);
            }
        });

        if (Schema::hasTable('jobs') && ! Schema::hasColumn('jobs', 'contractor_profile_id')) {
            Schema::table('jobs', function (Blueprint $table) {
                $table->foreignId('contractor_profile_id')
                    ->nullable()
                    ->after('contractor_id')
                    ->constrained('contractors')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('payouts') && ! Schema::hasColumn('payouts', 'contractor_profile_id')) {
            Schema::table('payouts', function (Blueprint $table) {
                $table->foreignId('contractor_profile_id')
                    ->nullable()
                    ->after('contractor_id')
                    ->constrained('contractors')
                    ->nullOnDelete();
            });
        }

        $this->backfillProfilesAndLinks();
    }

    public function down(): void
    {
        if (Schema::hasColumn('payouts', 'contractor_profile_id')) {
            Schema::table('payouts', function (Blueprint $table) {
                $table->dropConstrainedForeignId('contractor_profile_id');
            });
        }
        if (Schema::hasColumn('jobs', 'contractor_profile_id')) {
            Schema::table('jobs', function (Blueprint $table) {
                $table->dropConstrainedForeignId('contractor_profile_id');
            });
        }
        if (Schema::hasColumn('contractors', 'state')) {
            Schema::table('contractors', function (Blueprint $table) {
                $table->dropIndex(['state']);
                $table->dropColumn('state');
            });
        }
    }

    private function backfillProfilesAndLinks(): void
    {
        $created = 0;
        $linkedJobs = 0;
        $linkedPayouts = 0;
        $manualReview = [];

        // 1) Ensure every contractor user has a profile
        $users = User::withTestData()
            ->where('role', 'contractor')
            ->get(['id', 'name', 'email', 'phone', 'status', 'is_test_data']);

        foreach ($users as $user) {
            $existing = Contractor::withTestData()->where('user_id', $user->id)->first();
            if ($existing) {
                continue;
            }

            Contractor::withTestData()->create([
                'user_id' => $user->id,
                'legal_name' => $user->name,
                'operating_name' => $user->name,
                'contact_name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'services' => [],
                'cities' => [],
                'wcb_status' => 'not_uploaded',
                'liability_insurance_status' => 'not_uploaded',
                'approval_status' => 'pending',
                'state' => 'profile_incomplete',
                'is_test_data' => (bool) $user->is_test_data,
            ]);
            $created++;
        }

        // 2) Link jobs.contractor_profile_id from jobs.contractor_id (users.id)
        $profileByUserId = Contractor::withTestData()->pluck('id', 'user_id');

        Job::withTestData()->whereNotNull('contractor_id')->orderBy('id')->chunkById(200, function ($jobs) use ($profileByUserId, &$linkedJobs, &$manualReview) {
            foreach ($jobs as $job) {
                $userId = (int) $job->contractor_id;
                $profileId = $profileByUserId[$userId] ?? null;

                if (! $profileId) {
                    // Maybe contractor_id was wrongly set to contractors.id
                    $asProfile = Contractor::withTestData()->find($userId);
                    if ($asProfile) {
                        $job->forceFill([
                            'contractor_id' => $asProfile->user_id,
                            'contractor_profile_id' => $asProfile->id,
                        ])->save();
                        $linkedJobs++;
                        continue;
                    }

                    $manualReview[] = [
                        'type' => 'job',
                        'id' => $job->id,
                        'contractor_id' => $userId,
                        'reason' => 'no_matching_contractor_user_or_profile',
                    ];
                    continue;
                }

                if ((int) ($job->contractor_profile_id ?? 0) !== (int) $profileId) {
                    $job->forceFill(['contractor_profile_id' => $profileId])->save();
                    $linkedJobs++;
                }
            }
        });

        // Skip pm/company rows — contractor_id stores recipient user id, not a contractor.
        Payout::withTestData()->whereNotNull('contractor_id')->orderBy('id')->chunkById(200, function ($payouts) use ($profileByUserId, &$linkedPayouts, &$manualReview) {
            foreach ($payouts as $payout) {
                $split = $payout->split_type ?: $payout->payout_type;
                if ($split !== 'contractor') {
                    continue;
                }
                $userId = (int) $payout->contractor_id;
                $profileId = $profileByUserId[$userId] ?? null;
                if (! $profileId) {
                    $manualReview[] = [
                        'type' => 'payout',
                        'id' => $payout->id,
                        'contractor_id' => $userId,
                        'payout_type' => $split,
                        'reason' => 'no_matching_contractor_user_or_profile',
                    ];
                    continue;
                }
                if ((int) ($payout->contractor_profile_id ?? 0) !== (int) $profileId) {
                    $payout->forceFill(['contractor_profile_id' => $profileId])->save();
                    $linkedPayouts++;
                }
            }
        });

        Log::info('A-04 contractor profile backfill complete', [
            'profiles_created' => $created,
            'jobs_linked' => $linkedJobs,
            'payouts_linked' => $linkedPayouts,
            'manual_review_count' => count($manualReview),
            'manual_review' => $manualReview,
        ]);

        // Persist summary for ops visibility
        if (Schema::hasTable('audit_logs')) {
            DB::table('audit_logs')->insert([
                'user_id' => null,
                'user_role' => 'system',
                'object_type' => 'contractor_backfill',
                'object_id' => 0,
                'action_type' => 'a04_contractor_profile_backfill',
                'previous_value' => null,
                'new_value' => json_encode([
                    'profiles_created' => $created,
                    'jobs_linked' => $linkedJobs,
                    'payouts_linked' => $linkedPayouts,
                    'manual_review_count' => count($manualReview),
                    'manual_review' => $manualReview,
                ]),
                'created_at' => now(),
            ]);
        }
    }
};
