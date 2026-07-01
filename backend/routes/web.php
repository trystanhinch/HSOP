<?php

use Illuminate\Support\Facades\Route;

$liveApi = rtrim(config('app.url'), '/');
$liveApp = rtrim(config('app.frontend_url'), '/');

Route::get('/', function () use ($liveApi, $liveApp) {
    return response()->json([
        'app' => config('app.name'),
        'status' => 'ok',
        'api' => $liveApi.'/api',
        'frontend' => $liveApp,
        'storage' => $liveApi.'/storage',
        'deploy' => [
            'note' => 'Replace {secret} with DEPLOY_SECRET from server .env',
            'release' => $liveApi.'/deploy/release/{secret}',
            'migrate' => $liveApi.'/deploy/migrate/{secret}',
            'seed' => $liveApi.'/deploy/seed/{secret}',
            'setup' => $liveApi.'/deploy/setup/{secret}',
            'repair' => $liveApi.'/deploy/repair/{secret}',
        ],
    ]);
});
