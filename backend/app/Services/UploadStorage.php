<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UploadStorage
{
    public function diskName(): string
    {
        if (config('filesystems.uploads_disk') === 's3' && $this->s3Configured()) {
            return 's3';
        }

        return 'public';
    }

    public function store(UploadedFile $file, string $directory): string
    {
        $disk = $this->diskName();

        if ($disk === 's3') {
            $path = $this->storeOnS3($file, $directory);

            return $path;
        }

        return $file->store($directory, $disk);
    }

    public function storeAs(UploadedFile $file, string $directory, string $filename): string
    {
        $disk = $this->diskName();

        if ($disk === 's3') {
            $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'file';
            $path = trim($directory, '/').'/'.$safeName;
            Storage::disk('s3')->put($path, file_get_contents($file->getRealPath()));
            try {
                Storage::disk('s3')->setVisibility($path, 'public');
            } catch (\Throwable) {
                //
            }

            return $path;
        }

        return $file->storeAs($directory, $filename, $disk);
    }

    public function publicUrl(string $path): string
    {
        $path = ltrim($path, '/');

        if ($path === '') {
            throw new \RuntimeException('Cannot build public URL for empty storage path');
        }

        // Encode slashes so the full storage key survives Laravel/nginx routing.
        $encoded = implode('%2F', array_map('rawurlencode', explode('/', $path)));

        return rtrim(config('app.url'), '/').'/api/files/'.$encoded;
    }

    /** Convert legacy /storage/... or broken API /storage/ URLs to the files route. */
    public static function normalizeStoredUrl(?string $url): ?string
    {
        if (! $url) {
            return $url;
        }

        if (preg_match('#^https?://[^/]+/storage/(.+)$#i', $url, $m)) {
            return rtrim(config('app.url'), '/').'/api/files/'.$m[1];
        }

        if (str_starts_with($url, '/storage/')) {
            return rtrim(config('app.url'), '/').'/api/files/'.substr($url, strlen('/storage/'));
        }

        if (str_starts_with($url, '/api/files/')) {
            return rtrim(config('app.url'), '/').$url;
        }

        return $url;
    }

    private function storeOnS3(UploadedFile $file, string $directory): string
    {
        $extension = $file->getClientOriginalExtension() ?: $file->extension() ?: 'bin';
        $path = trim($directory, '/').'/'.Str::random(40).'.'.strtolower($extension);

        $stored = Storage::disk('s3')->put($path, file_get_contents($file->getRealPath()));
        if (! $stored) {
            throw new \RuntimeException('S3 upload failed for path: '.$path);
        }

        $verify = Storage::disk('s3')->get($path);
        if ($verify === null || $verify === '') {
            throw new \RuntimeException(
                'S3 upload verify failed — object not readable after put. Check bucket/region/endpoint. Path: '.$path
            );
        }

        try {
            Storage::disk('s3')->setVisibility($path, 'public');
        } catch (\Throwable) {
            // Some Spaces buckets disable per-object ACLs — rely on bucket/CDN policy.
        }

        return $path;
    }

    private function s3Configured(): bool
    {
        return (bool) config('filesystems.disks.s3.bucket')
            && (bool) config('filesystems.disks.s3.key');
    }
}
