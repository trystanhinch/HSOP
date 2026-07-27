<?php

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Job;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit PM-01 / PM-02 — explicit PM↔brand assignments (1A).
 * Empty assignment = no brand access. Own-work-only record scope is 2A (enforced in app layer).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pm_brand_assignments')) {
            Schema::create('pm_brand_assignments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
                $table->unsignedBigInteger('assigned_by')->nullable();
                $table->timestamp('assigned_at')->useCurrent();
                $table->timestamps();

                $table->unique(['user_id', 'brand_id']);
                $table->index('brand_id');
            });
        }

        $this->backfillFromExistingWork();
    }

    private function backfillFromExistingWork(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasTable('brands')) {
            return;
        }

        $pms = User::query()->where('role', 'pm')->get(['id', 'name']);
        $seeded = [];

        foreach ($pms as $pm) {
            $brandIds = collect();

            if (Schema::hasColumn('leads', 'brand_id')) {
                $brandIds = $brandIds->merge(
                    Lead::withTestData()
                        ->where('assigned_pm_id', $pm->id)
                        ->whereNotNull('brand_id')
                        ->pluck('brand_id')
                );
            }

            $jobLeadBrandIds = Job::withTestData()
                ->where('pm_id', $pm->id)
                ->whereNotNull('lead_id')
                ->with('lead:id,brand_id')
                ->get()
                ->pluck('lead.brand_id')
                ->filter();
            $brandIds = $brandIds->merge($jobLeadBrandIds);

            if (Schema::hasTable('company_sources') && Schema::hasColumn('company_sources', 'default_pm_id')) {
                $sourceIds = DB::table('company_sources')->where('default_pm_id', $pm->id)->pluck('id');
                if ($sourceIds->isNotEmpty()) {
                    $brandIds = $brandIds->merge(
                        Brand::query()->whereIn('company_source_id', $sourceIds)->pluck('id')
                    );
                }
            }

            $brandIds = $brandIds->filter()->unique()->values();
            foreach ($brandIds as $brandId) {
                DB::table('pm_brand_assignments')->insertOrIgnore([
                    'user_id' => $pm->id,
                    'brand_id' => (int) $brandId,
                    'assigned_by' => null,
                    'assigned_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $seeded[] = ['pm_id' => $pm->id, 'brand_id' => (int) $brandId];
            }
        }

        if (Schema::hasTable('audit_logs')) {
            AuditLog::create([
                'user_id' => null,
                'user_role' => 'system',
                'object_type' => 'pm_brand_assignment',
                'object_id' => 0,
                'action_type' => 'pm01_brand_assignments_backfilled',
                'previous_value' => null,
                'new_value' => ['assignments' => $seeded, 'count' => count($seeded)],
                'reason' => 'PM-01 migration: seed brand access from existing assigned work / default_pm sources. Empty after this still means no access for new PMs.',
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_brand_assignments');
    }
};
