<?php

namespace App\Services\Monitoring;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Milestone 6A.4 — Owner visibility into Laravel failed_jobs (PII-safe summaries).
 */
class FailedJobMonitoringService
{
    public function paginate(int $perPage = 25): LengthAwarePaginator
    {
        $this->assertTable();

        return DB::table('failed_jobs')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn ($row) => $this->summarizeRow($row));
    }

    /**
     * @return array<string, mixed>
     */
    public function summarizeRow(object $row): array
    {
        $payload = json_decode((string) $row->payload, true);
        $displayName = is_array($payload)
            ? (string) ($payload['displayName'] ?? $payload['data']['commandName'] ?? 'unknown')
            : 'unknown';

        $exception = (string) $row->exception;
        $exceptionSummary = Str::limit(preg_replace('/\s+/', ' ', $exception) ?? $exception, 400);

        return [
            'id' => (int) $row->id,
            'uuid' => (string) $row->uuid,
            'queue' => (string) $row->queue,
            'connection' => (string) $row->connection,
            'job_name' => Str::limit($displayName, 200),
            'exception_summary' => $exceptionSummary,
            'failed_at' => $row->failed_at,
            // Full payload intentionally omitted — may contain PII / secrets.
        ];
    }

    public function find(int $id): ?object
    {
        $this->assertTable();

        return DB::table('failed_jobs')->where('id', $id)->first();
    }

    public function retry(int $id): array
    {
        $row = $this->find($id);
        if (! $row) {
            throw new RuntimeException('Failed job not found.');
        }

        // Prefer uuid (database-uuids driver); fall back to numeric id.
        $retryId = (string) ($row->uuid ?: $row->id);

        try {
            $exit = Artisan::call('queue:retry', ['id' => [$retryId]]);
            $output = trim(Artisan::output());
        } catch (\Throwable $e) {
            $exit = 1;
            $output = $e->getMessage();
        }

        return [
            'id' => (int) $row->id,
            'uuid' => (string) $row->uuid,
            'artisan_exit_code' => $exit,
            'output' => $output,
        ];
    }

    public function dismiss(int $id): array
    {
        $row = $this->find($id);
        if (! $row) {
            throw new RuntimeException('Failed job not found.');
        }

        $snapshot = $this->summarizeRow($row);
        DB::table('failed_jobs')->where('id', $id)->delete();

        return $snapshot;
    }

    private function assertTable(): void
    {
        if (! Schema::hasTable('failed_jobs')) {
            throw new RuntimeException('failed_jobs table is missing — run migrations.');
        }
    }
}
