<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Milestone 5 addendum — versioned estimate_outcomes for Learning Centre foundation.
 * Capture only; embedding_vector reserved empty for future semantic search.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('estimate_outcomes')) {
            Schema::create('estimate_outcomes', function (Blueprint $table) {
                $table->id();
                $table->uuid('estimate_group_id')->index();
                $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
                $table->foreignId('job_id')->nullable()->constrained('jobs')->nullOnDelete();
                $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
                $table->unsignedInteger('version');
                $table->string('source_kind', 40); // estimator | manual_override | recalculate
                $table->string('service_category', 80);
                $table->decimal('price_low', 12, 2)->nullable();
                $table->decimal('price_high', 12, 2)->nullable();
                $table->string('currency', 8)->default('CAD');
                $table->string('confidence', 40)->nullable();
                $table->boolean('available')->default(false);
                $table->boolean('widened')->default(false);
                $table->boolean('is_placeholder')->default(false);
                $table->boolean('is_current')->default(true)->index();
                $table->unsignedBigInteger('pricing_rule_id')->nullable();
                $table->json('inputs_used')->nullable();
                $table->json('calculation')->nullable();
                $table->json('materials_assumptions')->nullable();
                $table->json('labour_assumptions')->nullable();
                $table->json('reasoning_snapshot')->nullable();
                $table->string('ai_provider', 40)->nullable();
                $table->string('ai_model', 80)->nullable();
                $table->string('ai_model_version', 80)->nullable();
                $table->string('estimator_engine', 60)->nullable();
                $table->timestamp('estimated_at')->nullable();
                $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedBigInteger('supersedes_id')->nullable();
                $table->string('reason')->nullable();
                // Reserved for future semantic search — do not populate in M5
                $table->json('embedding_vector')->nullable();
                $table->timestamps();

                $table->unique(['estimate_group_id', 'version']);
                $table->index(['lead_id', 'is_current']);
                $table->index(['lead_id', 'service_category']);
            });
        }

        if (Schema::hasTable('pricing_override_logs') && ! Schema::hasColumn('pricing_override_logs', 'estimate_outcome_id')) {
            Schema::table('pricing_override_logs', function (Blueprint $table) {
                $table->foreignId('estimate_outcome_id')->nullable()->after('job_id')
                    ->constrained('estimate_outcomes')->nullOnDelete();
            });
        }

        // Backfill current lead estimates into version 1 rows (local / existing data)
        if (Schema::hasTable('leads') && Schema::hasTable('estimate_outcomes')) {
            $leads = DB::table('leads')
                ->whereNotNull('price_estimate_snapshot')
                ->select(['id', 'brand_id', 'service_category', 'price_estimate_low', 'price_estimate_high', 'price_estimate_snapshot', 'created_at', 'updated_at'])
                ->get();

            foreach ($leads as $lead) {
                $exists = DB::table('estimate_outcomes')->where('lead_id', $lead->id)->exists();
                if ($exists) {
                    continue;
                }
                $snap = json_decode($lead->price_estimate_snapshot ?? 'null', true);
                if (! is_array($snap)) {
                    continue;
                }
                $category = $lead->service_category
                    ?: (string) ($snap['inputs_used']['service_category'] ?? 'unknown');

                DB::table('estimate_outcomes')->insert([
                    'estimate_group_id' => (string) Str::uuid(),
                    'lead_id' => $lead->id,
                    'job_id' => null,
                    'brand_id' => $lead->brand_id,
                    'version' => 1,
                    'source_kind' => ! empty($snap['manual_override']) ? 'manual_override' : 'estimator',
                    'service_category' => $category !== '' ? $category : 'unknown',
                    'price_low' => $lead->price_estimate_low,
                    'price_high' => $lead->price_estimate_high,
                    'currency' => $snap['currency'] ?? 'CAD',
                    'confidence' => $snap['confidence'] ?? null,
                    'available' => (bool) ($snap['available'] ?? true),
                    'widened' => (bool) ($snap['widened'] ?? false),
                    'is_placeholder' => (bool) ($snap['is_placeholder'] ?? false),
                    'is_current' => true,
                    'pricing_rule_id' => $snap['rule_id'] ?? null,
                    'inputs_used' => isset($snap['inputs_used']) ? json_encode($snap['inputs_used']) : null,
                    'calculation' => isset($snap['calculation']) ? json_encode($snap['calculation']) : null,
                    'materials_assumptions' => isset($snap['materials_assumptions']) ? json_encode($snap['materials_assumptions']) : null,
                    'labour_assumptions' => isset($snap['labour_assumptions']) ? json_encode($snap['labour_assumptions']) : null,
                    'reasoning_snapshot' => json_encode($snap),
                    'ai_provider' => $snap['ai_provider'] ?? null,
                    'ai_model' => $snap['ai_model'] ?? null,
                    'ai_model_version' => $snap['ai_model_version'] ?? null,
                    'estimator_engine' => $snap['estimator_engine'] ?? 'pricing_range_v1',
                    'estimated_at' => $lead->updated_at ?? $lead->created_at ?? now(),
                    'actor_id' => $snap['manual_override_by'] ?? null,
                    'supersedes_id' => null,
                    'reason' => $snap['manual_override_reason'] ?? null,
                    'embedding_vector' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pricing_override_logs') && Schema::hasColumn('pricing_override_logs', 'estimate_outcome_id')) {
            Schema::table('pricing_override_logs', function (Blueprint $table) {
                $table->dropConstrainedForeignId('estimate_outcome_id');
            });
        }

        Schema::dropIfExists('estimate_outcomes');
    }
};
