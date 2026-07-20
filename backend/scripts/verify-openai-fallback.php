<?php

/**
 * Temporarily break OPENAI_API_KEY, confirm mock_fallback, then restore.
 * Never prints the key.
 */

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$envPath = dirname(__DIR__).'/.env';
$env = file_get_contents($envPath);
if (! preg_match('/^OPENAI_API_KEY=(.*)$/m', $env, $m)) {
    fwrite(STDERR, "OPENAI_API_KEY line not found\n");
    exit(1);
}
$original = $m[1];
$broken = 'sk-invalid-fallback-test-key';

$envBroken = preg_replace('/^OPENAI_API_KEY=.*$/m', 'OPENAI_API_KEY='.$broken, $env, 1);
file_put_contents($envPath, $envBroken);

// Clear cached config in this process
Illuminate\Support\Facades\Artisan::call('config:clear');
config(['ai.openai.api_key' => $broken, 'ai.provider' => 'openai']);

/** @var \App\Contracts\AiProviderInterface $provider */
$provider = app(\App\Contracts\AiProviderInterface::class);
$result = $provider->classifyLead([
    'subject' => 'Coquitlam Drywall Client From Bil',
    'service_requested' => 'Drywall / Painting',
    'project_description' => 'Popcorn ceiling removal',
    'source_label' => 'Coquitlam Drywall',
    'email_format' => 'form',
]);

$restored = preg_replace('/^OPENAI_API_KEY=.*$/m', 'OPENAI_API_KEY='.$original, file_get_contents($envPath), 1);
file_put_contents($envPath, $restored);
Illuminate\Support\Facades\Artisan::call('config:clear');

$log = \App\Models\AiActionLog::query()
    ->where('rule_applied', 'openai_provider')
    ->latest('id')
    ->first(['id', 'trigger_event', 'action_taken', 'decision', 'error', 'created_at']);

echo json_encode([
    'fallback_provider' => $result['provider'] ?? null,
    'fallback_reason' => $result['openai_fallback_reason'] ?? null,
    'category_still_set' => $result['service_category'] ?? null,
    'pipeline_crashed' => false,
    'latest_log_decision' => $log?->decision,
    'latest_log_has_error' => (bool) $log?->error,
    'key_restored_len' => strlen($original),
    'key_still_valid_shape' => str_starts_with($original, 'sk-'),
], JSON_PRETTY_PRINT).PHP_EOL;
