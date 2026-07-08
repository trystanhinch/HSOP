<?php

use App\Http\Controllers\DeployController;
use Illuminate\Support\Facades\Route;

Route::prefix('deploy')->group(function () {
    Route::get('/release/{secret}', [DeployController::class, 'release']);
    Route::get('/milestone4-phase1/{secret}', [DeployController::class, 'milestone4Phase1']);
    Route::get('/seed-settings/{secret}', [DeployController::class, 'seedSettings']);
    Route::get('/migrate/{secret}', [DeployController::class, 'migrate']);
    Route::get('/seed/{secret}', [DeployController::class, 'seed']);
    Route::get('/setup/{secret}', [DeployController::class, 'setup']);
    Route::get('/repair/{secret}', [DeployController::class, 'repair']);
    Route::get('/storage-link/{secret}', [DeployController::class, 'storageLink']);
    Route::get('/debug-file-path/{secret}/{path}', [DeployController::class, 'debugFilePath'])->where('path', '.*');
    Route::get('/fix-s3-photo-urls/{secret}', [DeployController::class, 'fixS3PhotoUrls']);
    Route::get('/test-spaces-upload/{secret}', [DeployController::class, 'testSpacesUpload']);
    Route::get('/clean-broken-file-urls/{secret}', [DeployController::class, 'cleanBrokenFileUrls']);
    Route::get('/storage-diagnostic/{secret}', [DeployController::class, 'storageDiagnostic']);
    Route::get('/fix-photo-urls/{secret}', [DeployController::class, 'fixPhotoUrls']);
    Route::get('/clean-test-data/{secret}', [DeployController::class, 'cleanTestData']);
});
