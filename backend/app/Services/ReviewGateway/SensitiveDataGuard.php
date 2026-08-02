<?php

namespace App\Services\ReviewGateway;

/**
 * Strips sensitive keys/patterns from review-gateway payloads (allowlist tools + denylist sweep).
 */
class SensitiveDataGuard
{
    /**
     * @param  mixed  $payload
     * @return mixed
     */
    public function scrub(mixed $payload): mixed
    {
        if (is_array($payload)) {
            $out = [];
            foreach ($payload as $key => $value) {
                if (is_string($key) && $this->isDeniedKey($key)) {
                    continue;
                }
                $out[$key] = $this->scrub($value);
            }

            return $out;
        }

        if (is_string($payload)) {
            return $this->scrubString($payload);
        }

        return $payload;
    }

    public function isDeniedKey(string $key): bool
    {
        $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '_', $key) ?? $key);
        foreach (config('review_gateway.sensitive_key_denylist', []) as $denied) {
            $deniedNorm = strtolower(preg_replace('/[^a-z0-9]/i', '_', (string) $denied) ?? (string) $denied);
            if ($normalized === $deniedNorm || str_contains($normalized, $deniedNorm)) {
                return true;
            }
        }

        return false;
    }

    public function scrubString(string $value): string
    {
        $result = $value;
        foreach (config('review_gateway.sensitive_value_patterns', []) as $pattern) {
            $result = preg_replace($pattern, '[REDACTED]', $result) ?? $result;
        }

        return $result;
    }

    /**
     * Walk a JSON-decoded structure and collect any denylist key hits (for tests).
     *
     * @return list<string>
     */
    public function findDeniedKeys(mixed $payload, string $path = ''): array
    {
        $hits = [];
        if (! is_array($payload)) {
            return $hits;
        }

        foreach ($payload as $key => $value) {
            $here = $path === '' ? (string) $key : $path.'.'.$key;
            if (is_string($key) && $this->isDeniedKey($key)) {
                $hits[] = $here;
            }
            $hits = array_merge($hits, $this->findDeniedKeys($value, $here));
        }

        return $hits;
    }
}
