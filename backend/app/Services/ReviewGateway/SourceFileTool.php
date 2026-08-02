<?php

namespace App\Services\ReviewGateway;

/**
 * GET source-file — allowlisted, traversal-safe file read with content_sha256.
 */
class SourceFileTool
{
    public const TOOL = 'source_file';

    public function __construct(
        private SourceCodePathGuard $paths,
        private SensitiveDataGuard $scrub,
    ) {}

    /**
     * @return array{ok: true, payload: array<string, mixed>}|array{ok: false, status: int, payload: array<string, mixed>, denial_reason: string}
     */
    public function handle(string $relativePath): array
    {
        $resolved = $this->paths->resolveReadableFile($relativePath);
        if (! $resolved['ok']) {
            $status = ($resolved['code'] ?? '') === 'path_not_found' ? 404 : 403;
            // Missing allowlisted file → 404; disallowed / traversal / hard-exclude → 403
            if (in_array($resolved['code'] ?? '', ['path_not_allowlisted', 'path_hard_excluded', 'path_traversal', 'path_invalid', 'path_unresolvable'], true)) {
                $status = 403;
            }

            return [
                'ok' => false,
                'status' => $status,
                'denial_reason' => $resolved['code'] ?? 'path_denied',
                'payload' => [
                    'tool' => self::TOOL,
                    'tool_version' => config('review_gateway.tool_versions.source_file', '1.0.0'),
                    'message' => $resolved['reason'] ?? 'Forbidden',
                    'code' => $resolved['code'] ?? 'path_denied',
                ],
            ];
        }

        $max = (int) config('review_gateway_code_scope.max_file_bytes', 1_048_576);
        $size = filesize($resolved['absolute']);
        if ($size === false || $size > $max) {
            return [
                'ok' => false,
                'status' => 403,
                'denial_reason' => 'file_too_large',
                'payload' => [
                    'tool' => self::TOOL,
                    'tool_version' => config('review_gateway.tool_versions.source_file', '1.0.0'),
                    'message' => 'File exceeds max readable size.',
                    'code' => 'file_too_large',
                ],
            ];
        }

        $contents = file_get_contents($resolved['absolute']);
        if ($contents === false) {
            return [
                'ok' => false,
                'status' => 403,
                'denial_reason' => 'file_unreadable',
                'payload' => [
                    'tool' => self::TOOL,
                    'tool_version' => config('review_gateway.tool_versions.source_file', '1.0.0'),
                    'message' => 'Unable to read file.',
                    'code' => 'file_unreadable',
                ],
            ];
        }

        $payload = [
            'tool' => self::TOOL,
            'tool_version' => config('review_gateway.tool_versions.source_file', '1.0.0'),
            'path' => $resolved['relative'],
            'byte_length' => strlen($contents),
            'content' => $contents,
        ];
        $payload = $this->scrub->scrub($payload);
        // Hash what the reviewer actually receives (post-scrub).
        $body = is_string($payload['content'] ?? null) ? $payload['content'] : $contents;
        $payload['content'] = $body;
        $payload['content_sha256'] = hash('sha256', $body);
        $payload['byte_length'] = strlen($body);

        return [
            'ok' => true,
            'payload' => $payload,
        ];
    }
}
