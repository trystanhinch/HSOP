<?php

use App\Http\Controllers\DeployController;
use Illuminate\Support\Facades\Route;

Route::prefix('deploy')->group(function () {
    Route::get('/release/{secret}', [DeployController::class, 'release']);
    Route::get('/seed-settings/{secret}', [DeployController::class, 'seedSettings']);
    Route::get('/migrate/{secret}', [DeployController::class, 'migrate']);
    Route::get('/seed/{secret}', [DeployController::class, 'seed']);
    Route::get('/setup/{secret}', [DeployController::class, 'setup']);
    Route::get('/repair/{secret}', [DeployController::class, 'repair']);
    Route::get('/storage-link/{secret}', [DeployController::class, 'storageLink']);
    Route::get('/clean-test-data/{secret}', [DeployController::class, 'cleanTestData']);
});
