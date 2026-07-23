<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Services\PublicIntake\PublicIntakeSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

class PublicIntakeController extends Controller
{
    public function __construct(private PublicIntakeSessionService $sessions) {}

    public function brand(Request $request): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');

        return response()->json([
            'brand' => $brand->publicConfig(),
        ]);
    }

    public function start(Request $request): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $session = $this->sessions->start($brand);

        return $this->withIntakeCookie(response()->json([
            'session_id' => $session->id,
            'session_token' => $session->session_token,
            'expires_at' => $session->expires_at?->toIso8601String(),
            'brand' => $brand->publicConfig(),
        ]), $session->session_token, $brand);
    }

    public function message(Request $request): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');

        $data = $request->validate([
            'message' => 'required|string|max:4000',
            'session_token' => 'nullable|string|max:64',
        ]);

        $token = $this->resolveToken($request, $data['session_token'] ?? null);
        if (! $token) {
            return response()->json(['message' => 'Missing intake session token'], 401);
        }

        $session = $this->sessions->findValidByToken($token, $brand);
        if (! $session) {
            return response()->json(['message' => 'Intake session expired or not found'], 404);
        }

        try {
            $result = $this->sessions->message($session, $brand, $data['message']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->withIntakeCookie(response()->json([
            'session_id' => $result['session']->id,
            'session_token' => $result['session']->session_token,
            'reply' => $result['reply'],
            'ready_to_submit' => $result['ready_to_submit'],
            'collected' => $result['collected'],
            'provider' => $result['provider'],
            'expires_at' => $result['session']->expires_at?->toIso8601String(),
        ]), $result['session']->session_token, $brand);
    }

    public function submit(Request $request): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $allowedKeys = array_column($brand->serviceCatalog(), 'key');

        $data = $request->validate([
            'session_token' => 'nullable|string|max:64',
            'contact_name' => 'nullable|string|max:120',
            'phone' => 'nullable|string|max:40',
            'email' => 'nullable|email|max:120',
            'address' => 'nullable|string|max:255',
            'project_description' => 'nullable|string|max:5000',
            'service_category' => ['nullable', 'string', 'max:80', function ($attr, $value, $fail) use ($allowedKeys) {
                if ($value === null || $value === '') {
                    return;
                }
                if ($allowedKeys !== [] && ! in_array($value, $allowedKeys, true)) {
                    $fail('The selected service is not offered by this brand.');
                }
            }],
        ]);

        $token = $this->resolveToken($request, $data['session_token'] ?? null);
        if (! $token) {
            return response()->json(['message' => 'Missing intake session token'], 401);
        }

        $session = $this->sessions->findValidByToken($token, $brand);
        if (! $session) {
            return response()->json(['message' => 'Intake session expired or not found'], 404);
        }

        $overrides = array_filter([
            'contact_name' => $data['contact_name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
            'project_description' => $data['project_description'] ?? null,
            'service_category' => $data['service_category'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        try {
            $result = $this->sessions->submit($session, $brand, $overrides);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'duplicate' => $result->duplicate,
            'duplicate_match_type' => $result->duplicateMatchType,
            'lead_id' => $result->lead?->id,
            'brand_id' => $result->lead?->brand_id,
            'intake_channel' => $result->lead?->intake_channel,
            'conversation_id' => $result->lead?->conversation_id,
            'source' => $result->lead?->source,
            'company_source_id' => $result->companySourceId,
            'needs_manual_review' => $result->lead?->needs_manual_review,
            'parse_metadata' => $result->lead?->parse_metadata,
        ]);
    }

    private function resolveToken(Request $request, ?string $bodyToken): ?string
    {
        $cookieName = config('public.intake_cookie', 'serviceop_intake_token');

        return $bodyToken
            ?: $request->bearerToken()
            ?: $request->header('X-Intake-Token')
            ?: $request->cookie($cookieName);
    }

    private function withIntakeCookie(JsonResponse $response, string $token, Brand $brand): JsonResponse
    {
        $cookieName = config('public.intake_cookie', 'serviceop_intake_token');
        $minutes = ((int) config('public.intake_session_ttl_hours', 48)) * 60;

        $response->headers->setCookie(Cookie::create(
            $cookieName,
            $token,
            now()->addMinutes($minutes)->getTimestamp(),
            '/',
            null,
            false,
            true,
            false,
            Cookie::SAMESITE_LAX,
        ));

        // Help SSR clients know which brand resolved
        $response->headers->set('X-Resolved-Brand', $brand->slug);

        return $response;
    }
}
