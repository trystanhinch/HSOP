<?php

use App\Http\Controllers\GmailOAuthCallbackController;
use Illuminate\Support\Facades\Route;

$liveApi = rtrim(config('app.url'), '/');
$liveApp = rtrim(config('app.frontend_url'), '/');

// Google OAuth redirect URI (must match GOOGLE_REDIRECT_URI exactly).
Route::get('/oauth/gmail/callback', GmailOAuthCallbackController::class);

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
            'milestone4_phase1' => $liveApi.'/deploy/milestone4-phase1/{secret}',
            'milestone4_phase2' => $liveApi.'/deploy/milestone4-phase2/{secret}',
            'seed' => $liveApi.'/deploy/seed/{secret}',
            'setup' => $liveApi.'/deploy/setup/{secret}',
            'repair' => $liveApi.'/deploy/repair/{secret}',
        ],
    ]);
});
