<?php

/**
 * Run intake with unique contact data so duplicates don't skip OpenAI.
 * Does not print API keys.
 */

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$fixture = $argv[1] ?? null;
if (! $fixture) {
    fwrite(STDERR, "Usage: php scripts/verify-openai-pipeline.php <fixture>\n");
    exit(1);
}

$path = dirname(__DIR__)."/tests/fixtures/lead_emails/{$fixture}.txt";
if (! is_file($path)) {
    fwrite(STDERR, "Fixture not found: {$path}\n");
    exit(1);
}

$raw = file_get_contents($path);
$suffix = (string) random_int(100000, 999999);
$raw = preg_replace('/Phone:\s*.+/i', 'Phone: (604) 555-'.$suffix, $raw, 1);
$raw = preg_replace('/E-mail:\s*.+/i', 'E-mail: test.'.$suffix.'@example.com', $raw, 1);
// Avoid fuzzy name+description duplicate matches from prior fixture runs.
$raw = preg_replace('/First Name:\s*.+/i', 'First Name: Verify'.$suffix, $raw, 1);
$raw = preg_replace('/Last Name:\s*.+/i', 'Last Name: OpenAI', $raw, 1);
$raw = preg_replace('/Text area:\s*.+/i', 'Text area: Unique verification run '.$suffix.' — '.$fixture, $raw, 1);

/** @var \App\Services\LeadIntake\LeadIntakePipeline $pipeline */
$pipeline = app(\App\Services\LeadIntake\LeadIntakePipeline::class);
$result = $pipeline->process($raw, sendNotifications: false);

$lead = $result->lead?->fresh();
$meta = $lead?->parse_metadata ?? [];
$classification = $meta['classification'] ?? null;
$usage = $meta['ai_usage'] ?? null;

$log = \App\Models\AiActionLog::query()
    ->where('trigger_event', 'like', '%openai%')
    ->orWhere('rule_applied', 'openai_provider')
    ->latest('id')
    ->first();

// Prefer the most recent openai_provider log after this run
$recentLogs = \App\Models\AiActionLog::query()
    ->where('rule_applied', 'openai_provider')
    ->latest('id')
    ->limit(3)
    ->get(['id', 'trigger_event', 'action_taken', 'decision', 'rule_applied', 'created_at']);

$out = [
    'fixture' => $fixture,
    'ai_config_provider' => config('ai.provider'),
    'duplicate' => $result->duplicate,
    'lead_id' => $lead?->id,
    'service_category' => $lead?->service_category,
    'classification_provider' => $classification['provider'] ?? null,
    'classification' => $classification,
    'ai_usage' => $usage,
    'recent_openai_logs' => $recentLogs->toArray(),
];

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
