<?php

namespace App\Services\ReviewGateway;

use RuntimeException;

/**
 * Milestone 6A Phase 2 — resolve + authorize relative paths for source-code tools.
 */
class SourceCodePathGuard
{
    /**
     * @return array{ok: true, absolute: string, relative: string}|array{ok: false, reason: string, code: string}
     */
    public function resolveReadableFile(string $relativePath): array
    {
        $normalized = $this->normalizeRelativeInput($relativePath);
        if ($normalized === null) {
            return ['ok' => false, 'reason' => 'Invalid path.', 'code' => 'path_invalid'];
        }

        if ($this->matchesHardExclude($normalized)) {
            return ['ok' => false, 'reason' => 'Path is hard-excluded.', 'code' => 'path_hard_excluded'];
        }

        if (! $this->isUnderAllowlist($normalized)) {
            return ['ok' => false, 'reason' => 'Path is outside the allowlist.', 'code' => 'path_not_allowlisted'];
        }

        $root = $this->repositoryRootReal();
        $candidate = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $normalized);

        if (! is_file($candidate)) {
            // Still 403 (not 404) for non-allowlisted / excluded; for allowlisted missing files use not found code but 403 per task for disallowed.
            // Missing allowlisted file → treat as path_not_found with 404? Task: "Return 403 (not 404) for disallowed paths".
            // Missing but allowlisted → 404 is OK for honest missing. We'll use 404 for missing allowlisted files.
            return ['ok' => false, 'reason' => 'File not found.', 'code' => 'path_not_found'];
        }

        $real = realpath($candidate);
        if ($real === false || ! is_file($real)) {
            return ['ok' => false, 'reason' => 'Unable to resolve path.', 'code' => 'path_unresolvable'];
        }

        if (! $this->realpathUnderAllowlist($real)) {
            return ['ok' => false, 'reason' => 'Resolved path escapes allowlist (traversal/symlink).', 'code' => 'path_traversal'];
        }

        // Re-check hard exclude on resolved relative form
        $relFromRoot = $this->relativeFromRoot($real);
        if ($relFromRoot === null || $this->matchesHardExclude($relFromRoot)) {
            return ['ok' => false, 'reason' => 'Resolved path is hard-excluded.', 'code' => 'path_hard_excluded'];
        }

        return [
            'ok' => true,
            'absolute' => $real,
            'relative' => $relFromRoot,
        ];
    }

    /**
     * @return list<string> absolute directory roots that are allowlisted and exist
     */
    public function allowlistedDirectoryReals(): array
    {
        $root = $this->repositoryRootReal();
        $dirs = [];
        foreach (config('review_gateway_code_scope.allowlist', []) as $entry) {
            $entry = trim(str_replace('\\', '/', (string) $entry), '/');
            if ($entry === '') {
                continue;
            }
            $abs = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $entry);
            $real = realpath($abs);
            if ($real !== false && is_dir($real)) {
                $dirs[] = $real;
            }
        }

        return array_values(array_unique($dirs));
    }

    public function isSearchableFile(string $absolutePath): bool
    {
        $rel = $this->relativeFromRoot($absolutePath);
        if ($rel === null || $this->matchesHardExclude($rel) || ! $this->isUnderAllowlist($rel)) {
            return false;
        }
        $real = realpath($absolutePath);
        if ($real === false || ! is_file($real) || ! $this->realpathUnderAllowlist($real)) {
            return false;
        }

        $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        // blade.php
        if (str_ends_with(strtolower($real), '.blade.php')) {
            return true;
        }
        $allowed = config('review_gateway_code_scope.search_extensions', []);

        return in_array($ext, $allowed, true);
    }

    public function repositoryRootReal(): string
    {
        $configured = (string) config('review_gateway_code_scope.repository_root', dirname(base_path()));
        $real = realpath($configured);
        if ($real === false || ! is_dir($real)) {
            throw new RuntimeException('REVIEW_GATEWAY_REPO_ROOT is not a resolvable directory.');
        }

        return $real;
    }

    public function matchesHardExclude(string $relativePosix): bool
    {
        $posix = str_replace('\\', '/', $relativePosix);
        $base = basename($posix);

        if (preg_match('/^\.env(\.|$)/i', $base)) {
            return true;
        }

        foreach (config('review_gateway_code_scope.hard_exclude_path_patterns', []) as $pattern) {
            if (@preg_match($pattern, $posix) === 1) {
                return true;
            }
        }

        foreach (config('review_gateway_code_scope.hard_exclude_basenames', []) as $name) {
            if (strcasecmp($base, (string) $name) === 0) {
                return true;
            }
        }

        return false;
    }

    public function isUnderAllowlist(string $relativePosix): bool
    {
        $posix = ltrim(str_replace('\\', '/', $relativePosix), '/');
        foreach (config('review_gateway_code_scope.allowlist', []) as $entry) {
            $prefix = trim(str_replace('\\', '/', (string) $entry), '/');
            if ($prefix === '') {
                continue;
            }
            if ($posix === $prefix || str_starts_with($posix, $prefix.'/')) {
                return true;
            }
            // allowlist entry with trailing slash meaning directory
            if (str_ends_with((string) $entry, '/') && str_starts_with($posix, $prefix.'/')) {
                return true;
            }
            // file exactly under prefix directory
            if (str_starts_with($posix, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function realpathUnderAllowlist(string $absoluteReal): bool
    {
        $root = $this->repositoryRootReal();
        $abs = $this->normalizeOsPath($absoluteReal);
        $rootN = $this->normalizeOsPath($root);
        if (! str_starts_with($abs, $rootN.DIRECTORY_SEPARATOR) && $abs !== $rootN) {
            return false;
        }

        foreach ($this->allowlistedDirectoryReals() as $dir) {
            $dirN = $this->normalizeOsPath($dir);
            if ($abs === $dirN || str_starts_with($abs, $dirN.DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    public function relativeFromRoot(string $absoluteReal): ?string
    {
        $root = $this->normalizeOsPath($this->repositoryRootReal());
        $abs = $this->normalizeOsPath($absoluteReal);
        if ($abs === $root) {
            return '';
        }
        $prefix = $root.DIRECTORY_SEPARATOR;
        if (! str_starts_with($abs, $prefix)) {
            return null;
        }

        return str_replace('\\', '/', substr($abs, strlen($prefix)));
    }

    private function normalizeRelativeInput(string $relativePath): ?string
    {
        $raw = str_replace('\\', '/', trim($relativePath));
        if ($raw === '' || str_starts_with($raw, '/') || preg_match('#^[A-Za-z]:/#', $raw)) {
            return null;
        }
        if (str_contains($raw, "\0")) {
            return null;
        }
        // Reject any .. segment before resolution
        $parts = explode('/', $raw);
        foreach ($parts as $part) {
            if ($part === '..') {
                return null;
            }
        }
        // Collapse . and empty
        $clean = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            $clean[] = $part;
        }

        return implode('/', $clean);
    }

    private function normalizeOsPath(string $path): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        // Windows drive letter case
        if (preg_match('/^[A-Za-z]:/', $path)) {
            $path = strtoupper($path[0]).substr($path, 1);
        }

        return rtrim($path, DIRECTORY_SEPARATOR);
    }
}
