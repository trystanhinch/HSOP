<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Gmail\GmailInboxFetcher;
use App\Services\Gmail\GmailOAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class GmailOAuthController extends Controller
{
    public function __construct(
        private GmailOAuthService $oauth,
        private GmailInboxFetcher $fetcher,
    ) {}

    public function status(): JsonResponse
    {
        return response()->json($this->oauth->connectionStatus());
    }

    /**
     * Returns the Google consent URL for the logged-in owner to open.
     * Prefer this over a blind redirect so SPA clients can open it cleanly.
     */
    public function initiate(Request $request): JsonResponse
    {
        try {
            $url = $this->oauth->authorizationUrl($request->user()->id);

            return response()->json([
                'auth_url' => $url,
                'instructions' => 'Open auth_url while signed into the leads@serviceop.ca Google account (or an account that can grant access to that mailbox). After consent, you will be redirected back automatically.',
            ]);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function disconnect(): JsonResponse
    {
        $this->oauth->disconnect();

        return response()->json(['message' => 'Gmail inbox disconnected.']);
    }

    public function fetchNow(): JsonResponse
    {
        try {
            $stats = $this->fetcher->fetchAndProcess();

            return response()->json([
                'message' => 'Gmail fetch complete',
                'stats' => $stats,
            ]);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
