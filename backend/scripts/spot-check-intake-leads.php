<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$ids = array_slice($argv, 1);
if ($ids === []) {
    $ids = \App\Models\Lead::whereNotNull('raw_email_copy')->latest()->take(4)->pluck('id')->all();
}

foreach ($ids as $id) {
    $l = \App\Models\Lead::find($id);
    if (! $l) {
        echo "Lead #{$id}: not found\n";
        continue;
    }
    echo "Lead #{$id}:\n";
    echo '  raw_email_copy: '.(strlen($l->raw_email_copy ?? '') > 0 ? strlen($l->raw_email_copy).' chars' : 'MISSING')."\n";
    echo '  parse_metadata: '.(is_array($l->parse_metadata) ? 'present ('.count($l->parse_metadata).' keys)' : 'MISSING')."\n";
    echo '  needs_manual_review: '.($l->needs_manual_review ? 'yes' : 'no')."\n";
}
