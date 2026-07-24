<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Services\PublicIntake\PublicIntakeSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function session(Request $request): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $token = $this->resolveToken($request, $request->query('session_token') ?: $request->input('session_token'));
        if (! $token) {
            return response()->json(['message' => 'Missing intake session token'], 401);
        }

        $session = $this->sessions->findValidByToken($token, $brand);
        if (! $session) {
            return response()->json(['message' => 'Intake session expired or not found'], 404);
        }

        return $this->withIntakeCookie(
            response()->json(array_merge(
                $this->sessions->resumePayload($session, $brand),
                [
                    'price_estimate' => $this->publicPriceEstimate(
                        is_array($session->conversation_state['price_estimate'] ?? null)
                            ? $session->conversation_state['price_estimate']
                            : null
                    ),
                ]
            )),
            $session->session_token,
            $brand
        );
    }

    public function message(Request $request): JsonResponse|StreamedResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');

        $data = $request->validate([
            'message' => 'required|string|max:4000',
            'session_token' => 'nullable|string|max:64',
            'stream' => 'nullable|boolean',
        ]);

        $token = $this->resolveToken($request, $data['session_token'] ?? null);
        if (! $token) {
            return response()->json(['message' => 'Missing intake session token'], 401);
        }

        $session = $this->sessions->findValidByToken($token, $brand);
        if (! $session) {
            return response()->json(['message' => 'Intake session expired or not found'], 404);
        }

        $wantsStream = $request->boolean('stream')
            || str_contains((string) $request->header('Accept'), 'text/event-stream');

        if (! $wantsStream) {
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
                'needs_manual_review' => $result['needs_manual_review'] ?? false,
                'price_estimate' => $this->publicPriceEstimate(
                    is_array($result['price_estimate'] ?? null) ? $result['price_estimate'] : null
                ),
                'expires_at' => $result['session']->expires_at?->toIso8601String(),
            ]), $result['session']->session_token, $brand);
        }

        return $this->sseMessage($session, $brand, $data['message']);
    }

    public function estimate(Request $request): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $data = $request->validate([
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
            $estimate = $this->sessions->estimate($session, $brand);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'session_id' => $session->id,
            'price_estimate' => $this->publicPriceEstimate(is_array($estimate) ? $estimate : null),
        ]);
    }

    public function media(Request $request): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');

        $data = $request->validate([
            'session_token' => 'nullable|string|max:64',
            'photos' => 'required|array|min:1|max:'.PublicIntakeSessionService::MAX_PHOTOS,
            'photos.*' => 'file',
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
            $attachments = $this->sessions->attachMedia($session, $brand, $request->file('photos', []));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->withIntakeCookie(response()->json([
            'session_id' => $session->id,
            'attachments' => $attachments,
            'count' => count($attachments),
        ]), $session->session_token, $brand);
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
            // Never expose full parse_metadata (transcripts, AI usage, internal flags) on public API
            'price_estimate_low' => $result->lead?->price_estimate_low,
            'price_estimate_high' => $result->lead?->price_estimate_high,
            'price_estimate' => $this->publicPriceEstimate($result->lead?->price_estimate_snapshot),
            'booking' => $result->notifications['booking_id'] ?? null
                ? [
                    'id' => $result->notifications['booking_id'],
                    'confirmed' => true,
                    'site_visit_date' => $result->lead?->site_visit_date,
                    'site_visit_time' => $result->lead?->site_visit_time,
                    // Customer-facing: booking succeeded even if contractor match is pending PM
                    'status' => 'confirmed',
                    'message' => 'Your site visit request is confirmed. Our team will follow up shortly.',
                ]
                : null,
            'photos' => $result->lead?->photos?->map(fn ($p) => [
                'id' => $p->id,
                'file_url' => $p->file_url,
            ])->values() ?? [],
        ]);
    }

    private function sseMessage(\App\Models\IntakeSession $session, Brand $brand, string $message): StreamedResponse
    {
        $sessions = $this->sessions;
        $cookieName = config('public.intake_cookie', 'serviceop_intake_token');
        $minutes = ((int) config('public.intake_session_ttl_hours', 48)) * 60;

        $response = new StreamedResponse(function () use ($sessions, $session, $brand, $message) {
            // Keep buffers intact under PHPUnit; flush only for real HTTP clients.
            if (! app()->runningUnitTests()) {
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
            }

            $send = function (string $event, array $data) {
                echo 'event: '.$event."\n";
                echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
                if (function_exists('flush')) {
                    flush();
                }
            };

            try {
                foreach ($sessions->streamMessage($session, $brand, $message) as $payload) {
                    $event = (string) ($payload['event'] ?? 'message');
                    if ($event === 'done') {
                        unset($payload['usage'], $payload['tool_calls'], $payload['tool_results'], $payload['ai_model']);
                        if (isset($payload['price_estimate']) && is_array($payload['price_estimate'])) {
                            $payload['price_estimate'] = $this->publicPriceEstimate($payload['price_estimate']);
                        }
                    }
                    if ($event === 'error') {
                        $payload = [
                            'event' => 'error',
                            'message' => 'Something went wrong. Please try again or submit what you have.',
                            'needs_manual_review' => true,
                        ];
                    }
                    $send($event, $payload);
                }
            } catch (\Throwable $e) {
                report($e);
                $send('error', [
                    'event' => 'error',
                    'message' => 'Something went wrong. Please try again or submit what you have.',
                    'needs_manual_review' => true,
                ]);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream; charset=UTF-8');
        $response->headers->set('Cache-Control', 'no-cache, no-transform');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Resolved-Brand', $brand->slug);
        $response->headers->setCookie(Cookie::create(
            $cookieName,
            $session->session_token,
            now()->addMinutes($minutes)->getTimestamp(),
            '/',
            null,
            false,
            true,
            false,
            Cookie::SAMESITE_LAX,
        ));

        return $response;
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

        $response->headers->set('X-Resolved-Brand', $brand->slug);

        return $response;
    }

    /**
     * Customer-safe estimate fields only (no internal calculation/rule cost logs).
     *
     * @param  array<string, mixed>|null  $snapshot
     * @return array<string, mixed>|null
     */
    private function publicPriceEstimate(?array $snapshot): ?array
    {
        if (! is_array($snapshot)) {
            return null;
        }

        return array_filter([
            'available' => $snapshot['available'] ?? null,
            'low' => $snapshot['low'] ?? null,
            'high' => $snapshot['high'] ?? null,
            'currency' => $snapshot['currency'] ?? null,
            'disclaimer' => $snapshot['disclaimer'] ?? null,
            'message' => $snapshot['message'] ?? null,
            'is_placeholder' => $snapshot['is_placeholder'] ?? null,
            'widened' => $snapshot['widened'] ?? null,
            'confidence' => $snapshot['confidence'] ?? null,
        ], fn ($v) => $v !== null);
    }
}
