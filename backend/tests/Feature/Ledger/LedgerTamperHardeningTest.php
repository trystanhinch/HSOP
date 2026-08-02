<?php

namespace Tests\Feature\Ledger;

use App\Services\Ledger\LedgerAppendOnlyTriggers;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Milestone 6A Phase 6 — DB-level append-only triggers (SEC-09 tamper tests).
 * Bypasses Eloquent deliberately via DB::table() to prove triggers catch raw SQL.
 */
class LedgerTamperHardeningTest extends TestCase
{
    use DatabaseTransactions;

    public function createApplication()
    {
        $app = parent::createApplication();
        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql.database', 'hsop_job_command');
        $app['config']->set('payment.provider', 'mock');
        $app['config']->set('ai.provider', 'mock');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('mysql', DB::connection()->getDriverName());

        foreach ([
            'database/migrations/2026_07_30_120001_create_review_gateway_access_logs_table.php' => 'review_gateway_access_logs',
            'database/migrations/2026_07_31_120002_create_learning_gateway_access_logs_table.php' => 'learning_gateway_access_logs',
            'database/migrations/2026_07_31_180001_create_ai_evaluation_runs_table.php' => 'ai_evaluation_runs',
            'database/migrations/2026_07_31_180002_create_ai_evaluation_findings_table.php' => 'ai_evaluation_findings',
            'database/migrations/2026_07_31_200001_add_append_only_ledger_triggers.php' => null,
        ] as $path => $table) {
            if ($table === null || ! Schema::hasTable($table)) {
                $this->artisan('migrate', ['--path' => $path, '--force' => true]);
            }
        }

        // Ensure triggers exist even if migration was previously recorded without them.
        LedgerAppendOnlyTriggers::applyAll();
    }

    public function test_1_raw_update_and_delete_blocked_on_all_four_ledgers(): void
    {
        $ids = $this->seedOneRowPerLedger();

        foreach (LedgerAppendOnlyTriggers::TABLES as $table) {
            $id = $ids[$table];

            try {
                DB::table($table)->where('id', $id)->update(['created_at' => now()]);
                $this->fail("Expected UPDATE on {$table} to be blocked by trigger");
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
                $this->assertStringContainsString('updates are not permitted', $e->getMessage());
            }

            try {
                DB::table($table)->where('id', $id)->delete();
                $this->fail("Expected DELETE on {$table} to be blocked by trigger");
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
                $this->assertStringContainsString('deletes are not permitted', $e->getMessage());
            }

            $this->assertTrue(DB::table($table)->where('id', $id)->exists(), "{$table} row must still exist");
        }
    }

    public function test_2_raw_insert_still_works_on_all_four_ledgers(): void
    {
        foreach (LedgerAppendOnlyTriggers::TABLES as $table) {
            $id = $this->insertRaw($table);
            $this->assertTrue(DB::table($table)->where('id', $id)->exists());
        }
    }

    public function test_3_ledger_verify_triggers_reports_healthy(): void
    {
        $exit = Artisan::call('ledger:verify-triggers');
        $output = Artisan::output();
        $this->assertSame(0, $exit, $output);
        $this->assertTrue(
            str_contains($output, 'All ledger append-only triggers are present')
            || str_contains($output, 'review_gateway_access_logs'),
            'Unexpected verify output: '.$output
        );

        $verify = LedgerAppendOnlyTriggers::verify();
        $this->assertTrue($verify['ok']);
        foreach (LedgerAppendOnlyTriggers::TABLES as $table) {
            $this->assertTrue($verify['tables'][$table]['update'], "missing UPDATE trigger on {$table}");
            $this->assertTrue($verify['tables'][$table]['delete'], "missing DELETE trigger on {$table}");
        }
    }

    public function test_4_truncate_bypasses_delete_triggers_documented_mysql_behavior(): void
    {
        // MySQL TRUNCATE does not fire BEFORE DELETE triggers. Confirm and document.
        // Use a disposable sandbox table with the same trigger pattern — never truncate
        // production ledger tables in this assertion.
        $sandbox = 'ledger_tamper_truncate_probe_'.Str::lower(Str::random(6));
        DB::unprepared("CREATE TABLE `{$sandbox}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `note` VARCHAR(32) NULL,
            `created_at` TIMESTAMP NULL
        )");

        try {
            $bu = $sandbox.'_bu_append_only';
            $bd = $sandbox.'_bd_append_only';
            DB::unprepared("CREATE TRIGGER `{$bu}` BEFORE UPDATE ON `{$sandbox}` FOR EACH ROW
                BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'sandbox append-only: updates are not permitted'; END");
            DB::unprepared("CREATE TRIGGER `{$bd}` BEFORE DELETE ON `{$sandbox}` FOR EACH ROW
                BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'sandbox append-only: deletes are not permitted'; END");

            DB::table($sandbox)->insert(['note' => 'row', 'created_at' => now()]);
            $this->assertSame(1, DB::table($sandbox)->count());

            // DELETE must fail
            try {
                DB::table($sandbox)->delete();
                $this->fail('DELETE should be blocked');
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
            $this->assertSame(1, DB::table($sandbox)->count());

            // TRUNCATE succeeds — triggers do not fire (MySQL documented behavior)
            DB::statement("TRUNCATE TABLE `{$sandbox}`");
            $this->assertSame(0, DB::table($sandbox)->count());
        } finally {
            DB::unprepared("DROP TRIGGER IF EXISTS `{$sandbox}_bu_append_only`");
            DB::unprepared("DROP TRIGGER IF EXISTS `{$sandbox}_bd_append_only`");
            DB::unprepared("DROP TABLE IF EXISTS `{$sandbox}`");
        }
    }

    public function test_5_drop_table_bypasses_triggers_so_migrate_fresh_style_refresh_is_safe(): void
    {
        $sandbox = 'ledger_tamper_drop_probe_'.Str::lower(Str::random(6));
        DB::unprepared("CREATE TABLE `{$sandbox}` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY)");
        DB::unprepared("CREATE TRIGGER `{$sandbox}_bd_append_only` BEFORE DELETE ON `{$sandbox}` FOR EACH ROW
            BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'sandbox append-only: deletes are not permitted'; END");

        // DROP TABLE succeeds even with DELETE trigger present
        DB::unprepared("DROP TABLE `{$sandbox}`");
        $this->assertFalse(Schema::hasTable($sandbox));
    }

    /**
     * @return array<string, int>
     */
    private function seedOneRowPerLedger(): array
    {
        $ids = [];
        foreach (LedgerAppendOnlyTriggers::TABLES as $table) {
            $ids[$table] = $this->insertRaw($table);
        }

        return $ids;
    }

    private function insertRaw(string $table): int
    {
        $now = now();
        $payload = match ($table) {
            'review_gateway_access_logs' => [
                'actor_user_id' => null,
                'personal_access_token_id' => null,
                'token_name' => 'tamper-test',
                'ability' => 'review:read',
                'tool' => 'search',
                'http_method' => 'GET',
                'path' => '/api/review-gateway/tools/search',
                'parameters' => null,
                'response_record_count' => 0,
                'outcome' => 'success',
                'http_status' => 200,
                'ip' => '127.0.0.1',
                'trace_id' => (string) Str::uuid(),
                'denial_reason' => null,
                'created_at' => $now,
            ],
            'learning_gateway_access_logs' => [
                'actor_user_id' => null,
                'personal_access_token_id' => null,
                'token_name' => 'tamper-test',
                'ability' => 'learning:read',
                'tool' => 'ping',
                'http_method' => 'GET',
                'path' => '/api/learning-gateway/ping',
                'parameters' => null,
                'response_record_count' => 0,
                'outcome' => 'success',
                'http_status' => 200,
                'ip' => '127.0.0.1',
                'trace_id' => (string) Str::uuid(),
                'denial_reason' => null,
                'created_at' => $now,
            ],
            'ai_evaluation_runs' => [
                'provider' => 'openai',
                'model' => 'tamper-probe',
                'model_version' => '1',
                'prompt_version' => 'p1',
                'evaluation_version' => '1.0.0',
                'benchmark_set_version' => null,
                'run_type' => 'smoke',
                'initiated_by_type' => 'user',
                'initiated_by_id' => null,
                'actor_user_id' => null,
                'personal_access_token_id' => null,
                'started_at' => $now,
                'completed_at' => $now,
                'total_cost' => 0,
                'status' => 'completed',
                'trace_id' => (string) Str::uuid(),
                'created_at' => $now,
            ],
            'ai_evaluation_findings' => [
                'evaluation_run_id' => $this->ensureEvalRunId(),
                'subject_type' => 'ai_conversation_log',
                'subject_id' => 1,
                'dimension' => 'consistency',
                'score' => 1,
                'max_score' => 5,
                'critique' => 'tamper probe',
                'statement_kind' => 'observed_fact',
                'evidence_reference' => 'ai_conversation_log:1',
                'created_at' => $now,
            ],
            default => throw new \InvalidArgumentException($table),
        };

        return (int) DB::table($table)->insertGetId($payload);
    }

    private function ensureEvalRunId(): int
    {
        static $runId = null;
        if ($runId) {
            return $runId;
        }
        $runId = (int) DB::table('ai_evaluation_runs')->insertGetId([
            'provider' => 'openai',
            'model' => 'tamper-parent',
            'prompt_version' => 'p1',
            'evaluation_version' => '1.0.0',
            'run_type' => 'smoke',
            'initiated_by_type' => 'user',
            'started_at' => now(),
            'completed_at' => now(),
            'total_cost' => 0,
            'status' => 'completed',
            'trace_id' => (string) Str::uuid(),
            'created_at' => now(),
        ]);

        return $runId;
    }
}
