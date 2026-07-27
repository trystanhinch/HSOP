<?php

/**
 * One-shot, review-first cleanup helper for production known test records (Audit A-05).
 *
 * Usage (staging/local first):
 *   php artisan serviceop:flag-test-data --dry-run
 *   php artisan serviceop:flag-test-data --apply
 *
 * Or via tinker after code deploy:
 *   app(\App\Services\TestData\FlagTestDataService::class)->run(apply: false);
 *   app(\App\Services\TestData\FlagTestDataService::class)->run(apply: true);
 *
 * Never deletes rows. Ambiguous matches are reported under needs_manual_review.
 */

use App\Services\TestData\FlagTestDataService;

return static function (bool $apply = false): array {
    return app(FlagTestDataService::class)->run(apply: $apply);
};
