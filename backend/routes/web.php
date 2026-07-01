<?php

use App\Http\Controllers\DeployController;
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

Route::prefix('deploy')->group(function () {
    Route::get('/release/{secret}', [DeployController::class, 'release']);
    Route::get('/seed-settings/{secret}', [DeployController::class, 'seedSettings']);
    Route::get('/migrate/{secret}', [DeployController::class, 'migrate']);
    Route::get('/seed/{secret}', [DeployController::class, 'seed']);
    Route::get('/setup/{secret}', [DeployController::class, 'setup']);
    Route::get('/repair/{secret}', [DeployController::class, 'repair']);
});
