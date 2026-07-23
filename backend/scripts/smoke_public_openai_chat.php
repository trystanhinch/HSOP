<?php

/**
 * One-shot local smoke: real OpenAI conversational streaming + AiActionLog.
 * Run: php scripts/smoke_public_openai_chat.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\AiActionLog;
use Illuminate\Support\Facades\Http;

$base = rtrim(env('APP_URL', 'http://127.0.0.1:8000'), '/');
// Prefer artisan serve
$base = 'http://127.0.0.1:8000';

$headers = [
    'X-Brand-Domain' => 'acuteradrywall.ca',
    'Accept' => 'application/json',
];

echo "Provider config: ".config('ai.conversational_provider')."\n";
echo "API key set: ".(config('ai.openai.api_key') ? 'yes' : 'no')."\n";

$before = AiActionLog::where('trigger_event', 'public_intake_chat')->count();

$start = Http::withHeaders($headers)->post($base.'/api/public/intake/start');
if (! $start->successful()) {
    fwrite(STDERR, "start failed: ".$start->status().' '.$start->body()."\n");
    exit(1);
}
$token = $start->json('session_token');
echo "session ok\n";

$suffix = substr(uniqid(), -6);
$turns = [
    'Hi, I need drywall repair in a bedroom in Coquitlam. There is a large hole after a leak.',
    'My name is OpenAI Smoke '.$suffix,
    'Phone is (604) 555-'.$suffix,
    'You can use smoke-'.$suffix.'@example.com — ready to submit when you are.',
];

$lastProvider = null;
$lastCollected = [];
$sawDelta = false;

foreach ($turns as $i => $msg) {
    echo "\n--- turn ".($i + 1)." ---\n";
    $response = Http::withHeaders(array_merge($headers, [
        'Accept' => 'text/event-stream',
        'Content-Type' => 'application/json',
    ]))
        ->withOptions(['stream' => true])
        ->timeout(90)
        ->post($base.'/api/public/intake/message', [
            'session_token' => $token,
            'message' => $msg,
            'stream' => true,
        ]);

    if (! $response->successful()) {
        fwrite(STDERR, "message failed: ".$response->status().' '.$response->body()."\n");
        exit(1);
    }

    $body = $response->toPsrResponse()->getBody();
    $buf = '';
    $reply = '';
    while (! $body->eof()) {
        $buf .= $body->read(1024);
        while (($pos = strpos($buf, "\n\n")) !== false) {
            $chunk = substr($buf, 0, $pos);
            $buf = substr($buf, $pos + 2);
            $event = 'message';
            $data = '';
            foreach (explode("\n", $chunk) as $line) {
                if (str_starts_with($line, 'event:')) {
                    $event = trim(substr($line, 6));
                }
                if (str_starts_with($line, 'data:')) {
                    $data .= trim(substr($line, 5));
                }
            }
            if ($data === '') {
                continue;
            }
            $payload = json_decode($data, true) ?: [];
            if ($event === 'delta') {
                $sawDelta = true;
                echo $payload['text'] ?? '';
            }
            if ($event === 'done') {
                $reply = $payload['reply'] ?? '';
                $lastProvider = $payload['provider'] ?? null;
                $lastCollected = $payload['collected'] ?? [];
                echo "\n[provider={$lastProvider} ready=".(($payload['ready_to_submit'] ?? false) ? '1' : '0')."]\n";
                if (! empty($payload['usage'])) {
                    echo '[usage] '.json_encode($payload['usage'])."\n";
                }
            }
            if ($event === 'error') {
                fwrite(STDERR, "SSE error: ".($payload['message'] ?? 'unknown')."\n");
            }
        }
    }
    if ($reply === '') {
        echo "\n(no done event)\n";
    }
}

$submit = Http::withHeaders($headers)->post($base.'/api/public/intake/submit', [
    'session_token' => $token,
    'contact_name' => 'OpenAI Smoke '.$suffix,
    'phone' => '(604) 555-'.$suffix,
    'email' => 'smoke-'.$suffix.'@example.com',
    'address' => 'Coquitlam',
    'project_description' => 'Bedroom drywall hole after leak — smoke test '.$suffix,
    'service_category' => 'drywall_paint',
]);

echo "\nsubmit: ".$submit->status()." lead=".$submit->json('lead_id')." provider_path_ok\n";
echo "collected after chat: ".json_encode($lastCollected)."\n";
echo "saw_streaming_deltas: ".($sawDelta ? 'yes' : 'no')."\n";
echo "last_provider: {$lastProvider}\n";

$afterLogs = AiActionLog::where('trigger_event', 'public_intake_chat')
    ->orderByDesc('id')
    ->limit(5)
    ->get(['id', 'decision', 'action_taken', 'data_viewed', 'created_at']);

$openaiLogs = $afterLogs->filter(function ($log) {
    $provider = $log->data_viewed['provider'] ?? null;
    $usage = $log->data_viewed['usage'] ?? null;

    return $provider === 'openai' || is_array($usage);
});

echo "new public_intake_chat logs since start: ".(AiActionLog::where('trigger_event', 'public_intake_chat')->count() - $before)."\n";
foreach ($openaiLogs as $log) {
    $usage = $log->data_viewed['usage'] ?? null;
    echo "log#{$log->id} provider=".($log->data_viewed['provider'] ?? '?')
        .' usage='.json_encode($usage)."\n";
}

if ($lastProvider !== 'openai') {
    fwrite(STDERR, "FAIL: expected provider openai, got {$lastProvider}\n");
    exit(2);
}
if (! $sawDelta) {
    fwrite(STDERR, "FAIL: no SSE deltas observed\n");
    exit(3);
}
if ($openaiLogs->isEmpty()) {
    fwrite(STDERR, "FAIL: no AiActionLog with openai usage\n");
    exit(4);
}

echo "SMOKE_OK\n";
