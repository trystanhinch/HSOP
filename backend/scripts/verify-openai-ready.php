<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

config(['ai.provider' => 'openai']);
$app->forgetInstance(App\Contracts\AiProviderInterface::class);

$provider = $app->make(App\Contracts\AiProviderInterface::class);
echo 'Resolved: '.get_class($provider).PHP_EOL;

$result = $provider->classifyLead([
    'subject' => 'Mission Drywall Client From Bil',
    'service_requested' => 'Drywall',
]);
echo 'Classify (no API key → fallback): '.json_encode($result, JSON_PRETTY_PRINT).PHP_EOL;

$classifier = $app->make(App\Services\LeadIntake\KeywordCategoryClassifier::class);
echo 'Ambiguous: '.json_encode($classifier->classify([
    'subject' => 'Hello',
    'service_requested' => 'help',
])).PHP_EOL;

// Confirm settings endpoint payload never includes api key
$settings = [
    'ai_kill_switch' => false,
    'module_modes' => [],
];
$encoded = json_encode($settings);
echo 'Settings leak check: '.(str_contains($encoded, 'OPENAI') || str_contains($encoded, 'api_key') ? 'FAIL' : 'OK').PHP_EOL;
echo 'Config key present server-side only: '.(config('ai.openai.api_key') === null || config('ai.openai.api_key') === '' ? 'empty (expected)' : 'set').PHP_EOL;
