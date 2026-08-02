<?php

namespace App\Services\ReviewGateway;

/**
 * GET source-search — PHP recursive search within allowlisted paths only (no shell).
 */
class SourceSearchTool
{
    public const TOOL = 'source_search';

    public function __construct(
        private SourceCodePathGuard $paths,
        private SensitiveDataGuard $scrub,
    ) {}

    /**
     * @return array{ok: true, payload: array<string, mixed>}|array{ok: false, status: int, payload: array<string, mixed>, denial_reason: string}
     */
    public function handle(string $query, ?string $pathPrefix = null): array
    {
        $query = trim($query);
        if ($query === '' || mb_strlen($query) > 200) {
            return [
                'ok' => false,
                'status' => 422,
                'denial_reason' => 'invalid_query',
                'payload' => [
                    'tool' => self::TOOL,
                    'tool_version' => config('review_gateway.tool_versions.source_search', '1.0.0'),
                    'message' => 'Query must be 1–200 characters.',
                    'code' => 'invalid_query',
                ],
            ];
        }

        $prefixRel = null;
        if (is_string($pathPrefix) && trim($pathPrefix) !== '') {
            $normalized = str_replace('\\', '/', trim($pathPrefix));
            $normalized = trim($normalized, '/');
            if (str_contains($normalized, '..') || ! $this->paths->isUnderAllowlist($normalized)) {
                return [
                    'ok' => false,
                    'status' => 403,
                    'denial_reason' => 'path_not_allowlisted',
                    'payload' => [
                        'tool' => self::TOOL,
                        'tool_version' => config('review_gateway.tool_versions.source_search', '1.0.0'),
                        'message' => 'path_prefix is outside the allowlist.',
                        'code' => 'path_not_allowlisted',
                    ],
                ];
            }
            if ($this->paths->matchesHardExclude($normalized)) {
                return [
                    'ok' => false,
                    'status' => 403,
                    'denial_reason' => 'path_hard_excluded',
                    'payload' => [
                        'tool' => self::TOOL,
                        'tool_version' => config('review_gateway.tool_versions.source_search', '1.0.0'),
                        'message' => 'path_prefix is hard-excluded.',
                        'code' => 'path_hard_excluded',
                    ],
                ];
            }
            $prefixRel = $normalized;
        }

        $maxMatches = (int) config('review_gateway_code_scope.max_search_matches', 50);
        $maxFiles = (int) config('review_gateway_code_scope.max_search_files', 2000);
        $matches = [];
        $filesScanned = 0;

        foreach ($this->paths->allowlistedDirectoryReals() as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $fileInfo) {
                if (! $fileInfo->isFile()) {
                    continue;
                }
                $abs = $fileInfo->getPathname();
                if (! $this->paths->isSearchableFile($abs)) {
                    continue;
                }
                $rel = $this->paths->relativeFromRoot($abs);
                if ($rel === null) {
                    continue;
                }
                if ($prefixRel !== null && $rel !== $prefixRel && ! str_starts_with($rel, $prefixRel.'/')) {
                    continue;
                }

                $filesScanned++;
                if ($filesScanned > $maxFiles) {
                    break 2;
                }

                $contents = @file_get_contents($abs);
                if ($contents === false) {
                    continue;
                }

                $offset = 0;
                $lineBase = 1;
                while (($pos = mb_stripos($contents, $query, $offset)) !== false) {
                    $line = $lineBase + substr_count(substr($contents, 0, $pos), "\n");
                    $lineStart = strrpos(substr($contents, 0, $pos), "\n");
                    $lineStart = $lineStart === false ? 0 : $lineStart + 1;
                    $lineEnd = strpos($contents, "\n", $pos);
                    $lineText = $lineEnd === false
                        ? substr($contents, $lineStart)
                        : substr($contents, $lineStart, $lineEnd - $lineStart);

                    $matches[] = [
                        'path' => $rel,
                        'line' => $line,
                        'snippet' => mb_substr(trim($lineText), 0, 240),
                    ];

                    if (count($matches) >= $maxMatches) {
                        break 3;
                    }

                    $offset = $pos + mb_strlen($query);
                }
            }
        }

        $payload = [
            'tool' => self::TOOL,
            'tool_version' => config('review_gateway.tool_versions.source_search', '1.0.0'),
            'query' => $query,
            'path_prefix' => $prefixRel,
            'files_scanned' => $filesScanned,
            'match_count' => count($matches),
            'matches' => $matches,
            'truncated' => [
                'total' => count($matches),
                'capped' => count($matches) >= $maxMatches,
            ],
            // Provenance over the result set shape (not file bodies)
            'content_sha256' => hash('sha256', json_encode($matches, JSON_UNESCAPED_UNICODE)),
        ];

        return [
            'ok' => true,
            'payload' => $this->scrub->scrub($payload),
        ];
    }
}
