<?php

namespace App\Services\PublicIntake;

use App\Contracts\ConversationalAiProviderInterface;
use App\Models\Brand;
use App\Models\IntakeSession;
use App\Services\Brands\BrandPromptTemplate;
use App\Services\LeadIntake\PublicIntakePipeline;
use App\Services\Pricing\PricingRangeEstimator;
use App\Services\UploadStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PublicIntakeSessionService
{
    public const MAX_PHOTOS = 8;

    public const MAX_PHOTO_KB = 10240; // 10 MB

    public function __construct(
        private ConversationalAiProviderInterface $conversationalAi,
        private PublicIntakePipeline $pipeline,
        private UploadStorage $uploads,
        private PricingRangeEstimator $pricingEstimator,
    ) {}

    public function start(Brand $brand): IntakeSession
    {
        $ttlHours = (int) config('public.intake_session_ttl_hours', 48);

        return IntakeSession::create([
            'brand_id' => $brand->id,
            'session_token' => Str::random(64),
            'conversation_state' => [
                'messages' => [],
                'collected' => [],
                'attachments' => [],
            ],
            'expires_at' => now()->addHours($ttlHours),
        ]);
    }

    public function findValidByToken(string $token, ?Brand $brand = null): ?IntakeSession
    {
        $query = IntakeSession::query()->where('session_token', $token);
        if ($brand) {
            $query->where('brand_id', $brand->id);
        }

        $session = $query->first();
        if (! $session || $session->isExpired()) {
            return null;
        }

        return $session;
    }

    /**
     * Public resume payload.
     *
     * @return array<string, mixed>
     */
    public function resumePayload(IntakeSession $session, Brand $brand): array
    {
        $state = $session->conversation_state ?? [];

        return [
            'session_id' => $session->id,
            'session_token' => $session->session_token,
            'expires_at' => $session->expires_at?->toIso8601String(),
            'converted' => $session->isConverted(),
            'messages' => $session->messages(),
            'collected' => is_array($state['collected'] ?? null) ? $state['collected'] : [],
            'attachments' => is_array($state['attachments'] ?? null) ? $state['attachments'] : [],
            'ready_to_submit' => (bool) ($state['ready_to_submit'] ?? false),
            'last_provider' => $state['last_provider'] ?? null,
            'price_estimate' => $state['price_estimate'] ?? null,
            'brand' => $brand->publicConfig(),
        ];
    }

    /**
     * Non-streaming message turn (JSON clients / Phase 1 compat).
     *
     * @return array{session: IntakeSession, reply: string, ready_to_submit: bool, collected: array<string, mixed>, provider: string, usage: mixed, needs_manual_review: bool}
     */
    public function message(IntakeSession $session, Brand $brand, string $content): array
    {
        $final = null;
        foreach ($this->streamMessage($session, $brand, $content) as $event) {
            if (($event['event'] ?? '') === 'done') {
                $final = $event;
            }
        }

        if (! is_array($final)) {
            throw new \RuntimeException('Conversation turn produced no result.');
        }

        return [
            'session' => $session->fresh(),
            'reply' => (string) ($final['reply'] ?? ''),
            'ready_to_submit' => (bool) ($final['ready_to_submit'] ?? false),
            'collected' => $final['collected'] ?? [],
            'provider' => (string) ($final['provider'] ?? 'unknown'),
            'usage' => $final['usage'] ?? null,
            'needs_manual_review' => (bool) ($final['needs_manual_review'] ?? false),
            'price_estimate' => $final['price_estimate'] ?? null,
        ];
    }

    /**
     * Streaming message turn — persists user message immediately, then assistant on done.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function streamMessage(IntakeSession $session, Brand $brand, string $content): \Generator
    {
        if ($session->isConverted()) {
            throw new \RuntimeException('This intake session was already submitted.');
        }
        if ((int) $session->brand_id !== (int) $brand->id) {
            throw new \RuntimeException('Intake session does not belong to this brand.');
        }

        $state = $session->conversation_state ?? [];
        $messages = is_array($state['messages'] ?? null) ? $state['messages'] : [];
        $collected = is_array($state['collected'] ?? null) ? $state['collected'] : [];
        $attachments = is_array($state['attachments'] ?? null) ? $state['attachments'] : [];

        $messages[] = [
            'role' => 'user',
            'content' => $content,
            'at' => now()->toIso8601String(),
        ];

        // Persist user turn immediately so reload mid-stream still has the question
        $session->conversation_state = array_merge($state, [
            'messages' => $messages,
            'collected' => $collected,
            'attachments' => $attachments,
        ]);
        $session->expires_at = now()->addHours((int) config('public.intake_session_ttl_hours', 48));
        $session->save();

        yield [
            'event' => 'session',
            'session_id' => $session->id,
            'session_token' => $session->session_token,
            'expires_at' => $session->expires_at?->toIso8601String(),
        ];

        $promptVars = $brand->promptVariables();
        $systemPrompt = BrandPromptTemplate::render(
            (string) config('public.conversational_system_prompt'),
            $promptVars
        );

        $reply = '';
        $provider = 'unknown';
        $usage = null;
        $needsReview = false;
        $ready = false;

        foreach ($this->conversationalAi->streamRespond($messages, $collected, [
            'brand_id' => $brand->id,
            'company_name' => $brand->company_name,
            'domain' => $brand->domain,
            'system_prompt' => $systemPrompt,
            'prompt_vars' => $promptVars,
            'service_categories' => $brand->serviceCatalog(),
        ]) as $event) {
            $type = $event['event'] ?? '';
            if ($type === 'delta' || $type === 'collected') {
                if ($type === 'collected' && is_array($event['collected'] ?? null)) {
                    $collected = $event['collected'];
                    // Persist progressive field extraction
                    $session->conversation_state = array_merge($session->conversation_state ?? [], [
                        'collected' => $collected,
                    ]);
                    $session->save();
                }
                yield $event;

                continue;
            }
            if ($type === 'done') {
                $reply = (string) ($event['reply'] ?? '');
                $collected = is_array($event['collected'] ?? null) ? $event['collected'] : $collected;
                $ready = (bool) ($event['ready_to_submit'] ?? false);
                $provider = (string) ($event['provider'] ?? 'unknown');
                $usage = $event['usage'] ?? null;
                $needsReview = (bool) ($event['needs_manual_review'] ?? false);
            }
            if ($type === 'error') {
                $reply = (string) ($event['message'] ?? 'Something went wrong.');
                $provider = (string) ($event['provider'] ?? 'error');
                $needsReview = true;
                yield $event;
            }
        }

        $messages[] = [
            'role' => 'assistant',
            'content' => $reply,
            'at' => now()->toIso8601String(),
        ];

        $session->conversation_state = [
            'messages' => $messages,
            'collected' => $collected,
            'attachments' => $attachments,
            'last_provider' => $provider,
            'ready_to_submit' => $ready,
            'needs_manual_review' => $needsReview,
            'last_usage' => $usage,
            'price_estimate' => null,
            'price_estimate_announced' => (bool) ($state['price_estimate_announced'] ?? false),
        ];

        $estimate = $this->maybeEstimate($brand, $collected);
        if ($estimate['available'] ?? false) {
            $session->conversation_state = array_merge($session->conversation_state, [
                'price_estimate' => $estimate,
            ]);
            // Append a brief estimate note once when first available (avoid spam)
            $alreadyAnnounced = (bool) ($state['price_estimate_announced'] ?? false);
            if (! $alreadyAnnounced && ! empty($estimate['message'])) {
                $reply = trim($reply."\n\n".$estimate['message']);
                $messages[count($messages) - 1]['content'] = $reply;
                $session->conversation_state = array_merge($session->conversation_state, [
                    'messages' => $messages,
                    'price_estimate_announced' => true,
                ]);
            }
        }

        $session->expires_at = now()->addHours((int) config('public.intake_session_ttl_hours', 48));
        $session->save();

        yield [
            'event' => 'done',
            'session_id' => $session->id,
            'session_token' => $session->session_token,
            'reply' => $reply,
            'ready_to_submit' => $ready,
            'collected' => $collected,
            'provider' => $provider,
            'usage' => $usage,
            'needs_manual_review' => $needsReview,
            'price_estimate' => ($estimate['available'] ?? false) ? $estimate : ($session->conversation_state['price_estimate'] ?? null),
            'expires_at' => $session->expires_at?->toIso8601String(),
        ];
    }

    /**
     * Explicit re-estimate from current collected fields.
     *
     * @return array<string, mixed>
     */
    public function estimate(IntakeSession $session, Brand $brand): array
    {
        if ((int) $session->brand_id !== (int) $brand->id) {
            throw new \RuntimeException('Intake session does not belong to this brand.');
        }

        $collected = is_array($session->conversation_state['collected'] ?? null)
            ? $session->conversation_state['collected']
            : [];
        $estimate = $this->maybeEstimate($brand, $collected);

        $state = $session->conversation_state ?? [];
        $session->conversation_state = array_merge($state, [
            'price_estimate' => ($estimate['available'] ?? false) ? $estimate : null,
        ]);
        $session->save();

        return $estimate;
    }

    /**
     * @param  array<string, mixed>  $collected
     * @return array<string, mixed>
     */
    private function maybeEstimate(Brand $brand, array $collected): array
    {
        if (empty($collected['service_category'])) {
            return [
                'available' => false,
                'message' => null,
                'calculation' => ['Skipped — no service_category yet'],
            ];
        }

        return $this->pricingEstimator->estimate($brand, [
            'service_category' => $collected['service_category'] ?? null,
            'size_sqft' => $collected['size_sqft'] ?? null,
            'complexity' => $collected['complexity'] ?? null,
            'urgency' => $collected['urgency'] ?? null,
            'project_description' => $collected['project_description'] ?? null,
            'address' => $collected['address'] ?? null,
        ]);
    }

    /**
     * @param  list<UploadedFile>  $files
     * @return list<array<string, mixed>>
     */
    public function attachMedia(IntakeSession $session, Brand $brand, array $files): array
    {
        if ($session->isConverted()) {
            throw new \RuntimeException('This intake session was already submitted.');
        }
        if ((int) $session->brand_id !== (int) $brand->id) {
            throw new \RuntimeException('Intake session does not belong to this brand.');
        }

        $this->validatePhotos($files);

        $state = $session->conversation_state ?? [];
        $attachments = is_array($state['attachments'] ?? null) ? $state['attachments'] : [];

        if (count($attachments) + count($files) > self::MAX_PHOTOS) {
            throw ValidationException::withMessages([
                'photos' => ['You can upload at most '.self::MAX_PHOTOS.' photos per request.'],
            ]);
        }

        foreach ($files as $file) {
            $path = $this->uploads->store($file, 'public-intake/'.$session->id);
            $attachments[] = [
                'path' => $path,
                'url' => $this->uploads->publicUrl($path),
                'file_name' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType(),
                'size' => $file->getSize(),
                'at' => now()->toIso8601String(),
            ];
        }

        $session->conversation_state = array_merge($state, [
            'attachments' => $attachments,
        ]);
        $session->expires_at = now()->addHours((int) config('public.intake_session_ttl_hours', 48));
        $session->save();

        return $attachments;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function submit(IntakeSession $session, Brand $brand, array $overrides = []): \App\Services\LeadIntake\LeadIntakeResult
    {
        if ($session->isConverted()) {
            throw new \RuntimeException('This intake session was already submitted.');
        }
        if ((int) $session->brand_id !== (int) $brand->id) {
            throw new \RuntimeException('Intake session does not belong to this brand.');
        }

        if ($overrides !== []) {
            $state = $session->conversation_state ?? [];
            $collected = is_array($state['collected'] ?? null) ? $state['collected'] : [];
            $session->conversation_state = array_merge($state, [
                'collected' => array_merge($collected, array_filter($overrides, fn ($v) => $v !== null && $v !== '')),
            ]);
            $session->save();
        }

        return $this->pipeline->submit($session->fresh());
    }

    /**
     * @param  list<UploadedFile|null>  $photos
     */
    private function validatePhotos(array $photos): void
    {
        if ($photos === []) {
            throw ValidationException::withMessages([
                'photos' => ['Please choose at least one photo.'],
            ]);
        }

        if (count($photos) > self::MAX_PHOTOS) {
            throw ValidationException::withMessages([
                'photos' => ['You can upload at most '.self::MAX_PHOTOS.' photos at a time.'],
            ]);
        }

        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif'];
        $allowedMimes = [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
            'image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence',
            'application/octet-stream',
        ];

        foreach ($photos as $index => $photo) {
            if (! $photo instanceof UploadedFile || ! $photo->isValid()) {
                throw ValidationException::withMessages([
                    "photos.$index" => ['One or more photos could not be uploaded. Please try again.'],
                ]);
            }

            $extension = strtolower($photo->getClientOriginalExtension() ?: '');
            $mime = strtolower($photo->getMimeType() ?: '');
            $allowedByExtension = in_array($extension, $allowedExtensions, true);
            $allowedByMime = in_array($mime, $allowedMimes, true);

            if (! $allowedByExtension && ! $allowedByMime) {
                throw ValidationException::withMessages([
                    "photos.$index" => ['Only JPG, PNG, WEBP, and HEIC photos are supported.'],
                ]);
            }

            if ($photo->getSize() > self::MAX_PHOTO_KB * 1024) {
                throw ValidationException::withMessages([
                    "photos.$index" => ['One or more photos is too large. Max size is 10 MB per photo.'],
                ]);
            }
        }
    }
}
