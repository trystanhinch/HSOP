<?php

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\PaymentDestination;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit A-03 — brand-scoped customer payment destinations.
 * Decisions: 1A platform Stripe, 2A job→lead→brand fallback, 3A migrate settings without nulling.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_destinations')) {
            Schema::create('payment_destinations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
                $table->string('payment_method', 32); // stripe | e_transfer
                $table->string('destination_type', 32)->default('company_verified'); // company_verified | contractor (blocked by default)
                $table->string('destination_value', 255)->nullable(); // email, or "platform" for Stripe
                $table->boolean('is_verified')->default(false);
                $table->boolean('needs_owner_review')->default(false);
                $table->boolean('is_active')->default(true);
                $table->boolean('contractor_email_override')->default(false);
                $table->text('override_reason')->nullable();
                $table->unsignedBigInteger('verified_by')->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->text('legacy_source_note')->nullable();
                $table->json('meta')->nullable();
                $table->boolean('is_test_data')->default(false);
                $table->timestamps();

                $table->unique(['brand_id', 'payment_method'], 'payment_destinations_brand_method_unique');
                $table->index(['brand_id', 'is_verified', 'is_active']);
                $table->index('is_test_data');
            });
        }

        $this->migrateLegacySettings();
    }

    private function migrateLegacySettings(): void
    {
        if (! Schema::hasTable('settings') || ! Schema::hasTable('brands')) {
            return;
        }

        $companyEmail = Setting::where('key', 'company_email')->value('value');
        $instructions = Setting::where('key', 'payment_instructions')->value('value');

        // Extract email from "Send e-transfer to X" style instructions if present.
        $instructionEmail = null;
        if (is_string($instructions) && preg_match('/([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})/', $instructions, $m)) {
            $instructionEmail = strtolower($m[1]);
        }

        $eTransferValue = $instructionEmail ?: (is_string($companyEmail) ? strtolower(trim($companyEmail)) : null);

        $brands = Brand::query()->orderBy('id')->get();
        if ($brands->isEmpty()) {
            return;
        }

        $contractorMatch = false;
        if ($eTransferValue) {
            $contractorMatch = User::withTestData()
                ->where('role', 'contractor')
                ->whereRaw('LOWER(email) = ?', [$eTransferValue])
                ->exists()
                || DB::table('contractors')
                    ->whereRaw('LOWER(email) = ?', [$eTransferValue])
                    ->exists();
        }

        foreach ($brands as $brand) {
            // Stripe default path — unverified until owner confirms (1A: destination_value = platform).
            PaymentDestination::query()->updateOrCreate(
                [
                    'brand_id' => $brand->id,
                    'payment_method' => 'stripe',
                ],
                [
                    'destination_type' => 'company_verified',
                    'destination_value' => 'platform',
                    'is_verified' => false,
                    'needs_owner_review' => true,
                    'is_active' => true,
                    'legacy_source_note' => 'Seeded as platform Stripe receive path (A-03). Requires owner verification.',
                    'meta' => ['seeded_from' => 'a03_migration'],
                ]
            );

            if ($eTransferValue) {
                PaymentDestination::query()->updateOrCreate(
                    [
                        'brand_id' => $brand->id,
                        'payment_method' => 'e_transfer',
                    ],
                    [
                        'destination_type' => 'company_verified',
                        'destination_value' => $eTransferValue,
                        'is_verified' => false,
                        'needs_owner_review' => true,
                        'is_active' => true,
                        'legacy_source_note' => sprintf(
                            'Migrated from settings (company_email=%s; payment_instructions=%s).%s Original settings keys left intact for audit/history.',
                            $companyEmail ?: 'null',
                            $instructions ?: 'null',
                            $contractorMatch
                                ? ' FLAGGED: matches a contractor account email — blocked from re-save without owner override.'
                                : ''
                        ),
                        'meta' => [
                            'seeded_from' => 'a03_migration',
                            'legacy_company_email' => $companyEmail,
                            'legacy_payment_instructions' => $instructions,
                            'matches_contractor_email' => $contractorMatch,
                        ],
                    ]
                );
            }
        }

        if (Schema::hasTable('audit_logs')) {
            AuditLog::create([
                'user_id' => null,
                'user_role' => 'system',
                'object_type' => 'payment_destination',
                'object_id' => 0,
                'action_type' => 'a03_payment_destination_migrated',
                'previous_value' => [
                    'company_email' => $companyEmail,
                    'payment_instructions' => $instructions,
                ],
                'new_value' => [
                    'e_transfer_value' => $eTransferValue,
                    'matches_contractor_email' => $contractorMatch,
                    'brands_seeded' => $brands->pluck('id')->all(),
                    'settings_cleared' => false,
                ],
                'reason' => 'A-03 migration: settings copied to payment_destinations as needs_owner_review; settings keys not nulled.',
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_destinations');
    }
};
