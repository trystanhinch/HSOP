<?php

namespace App\Jobs;

use App\Services\TestData\FlagTestDataService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FlagTestDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public bool $apply = false,
        public ?int $requestedByUserId = null,
    ) {}

    public function handle(FlagTestDataService $service): void
    {
        $result = $service->run(apply: $this->apply);

        Cache::put('serviceop:flag_test_data:last_result', [
            'ran_at' => now()->toIso8601String(),
            'apply' => $this->apply,
            'requested_by' => $this->requestedByUserId,
            'totals' => $result['totals'],
            'before' => $result['before'],
            'after' => $result['after'],
            'flagged_tables' => array_map('count', $result['flagged']),
            'needs_manual_review_count' => count($result['needs_manual_review']),
            'needs_manual_review' => array_slice($result['needs_manual_review'], 0, 100),
            'flagged_sample' => collect($result['flagged'])->map(fn ($rows) => array_slice($rows, 0, 20))->all(),
        ], now()->addDays(7));

        Log::info('FlagTestDataJob finished', [
            'apply' => $this->apply,
            'totals' => $result['totals'],
            'requested_by' => $this->requestedByUserId,
        ]);
    }
}
