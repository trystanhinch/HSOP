<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Milestone 6A.2 — HTTP Basic Auth gate for the entire staging app.
 * No-op when staging_mode is false (zero production impact).
 */
class RequireStagingBasicAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('app.staging_mode')) {
            return $next($request);
        }

        $path = trim($request->path(), '/');
        foreach (config('staging.basic_auth_except', []) as $except) {
            $except = trim((string) $except, '/');
            if ($except !== '' && ($path === $except || str_starts_with($path, $except.'/'))) {
                return $next($request);
            }
        }

        $user = (string) config('staging.basic_auth_user', '');
        $pass = (string) config('staging.basic_auth_password', '');

        if ($user === '' || $pass === '') {
            return response('Staging Basic Auth is not configured.', 503);
        }

        $givenUser = (string) $request->getUser();
        $givenPass = (string) $request->getPassword();

        if (! hash_equals($user, $givenUser) || ! hash_equals($pass, $givenPass)) {
            return response('Staging authentication required.', 401, [
                'WWW-Authenticate' => 'Basic realm="ServiceOP Staging"',
            ]);
        }

        return $next($request);
    }
}
