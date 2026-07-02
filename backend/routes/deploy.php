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
    Route::get('/update-contractor-phone/{secret}', [DeployController::class, 'updateContractorPhone']);
    Route::get('/fix-customer-assignments/{secret}', [DeployController::class, 'fixCustomerAssignments']);
});
