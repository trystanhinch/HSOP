<?php

namespace App\Http\Controllers;

use App\Services\Gmail\GmailOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class GmailOAuthCallbackController extends Controller
{
    public function __invoke(Request $request, GmailOAuthService $oauth): RedirectResponse
    {
        $frontend = rtrim(config('app.frontend_url'), '/');
        $settingsUrl = $frontend.'/settings?tab=lead-inbox';

        if ($request->query('error')) {
            return redirect()->away($settingsUrl.'&gmail=error&reason='.urlencode((string) $request->query('error')));
        }

        $code = $request->query('code');
        $state = $request->query('state');

        if (! $code || ! $state) {
            return redirect()->away($settingsUrl.'&gmail=error&reason='.urlencode('missing_code_or_state'));
        }

        try {
            $result = $oauth->handleCallback($code, $state);
            $mailbox = urlencode($result['mailbox']);

            return redirect()->away($settingsUrl.'&gmail=connected&mailbox='.$mailbox);
        } catch (Throwable $e) {
            return redirect()->away($settingsUrl.'&gmail=error&reason='.urlencode($e->getMessage()));
        }
    }
}
