<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$q = App\Models\Quote::where('contractor_base_price', '>', 0)->latest()->first()
    ?? App\Models\Quote::latest()->first();
if (! $q) { echo "NO_QUOTES\n"; exit(1); }
echo "contractor_base_price: {$q->contractor_base_price}\n";
echo "customer_price_before_gst: {$q->customer_price_before_gst}\n";
$ratio = $q->contractor_base_price > 0 ? $q->customer_price_before_gst / $q->contractor_base_price : 0;
echo "ratio: {$ratio}\n";
echo ($ratio >= 1.249 && $ratio <= 1.251) ? "FORMULA_OK\n" : "FORMULA_WRONG\n";
