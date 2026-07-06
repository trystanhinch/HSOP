<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

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
        $path = $file->store($directory, $disk);

        if ($disk === 's3') {
            Storage::disk('s3')->setVisibility($path, 'public');
        }

        return $path;
    }

    public function storeAs(UploadedFile $file, string $directory, string $filename): string
    {
        $disk = $this->diskName();
        $path = $file->storeAs($directory, $filename, $disk);

        if ($disk === 's3') {
            Storage::disk('s3')->setVisibility($path, 'public');
        }

        return $path;
    }

    public function publicUrl(string $path): string
    {
        if ($this->diskName() === 's3') {
            return Storage::disk('s3')->url($path);
        }

        return rtrim(config('app.url'), '/').'/api/files/'.ltrim($path, '/');
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

    private function s3Configured(): bool
    {
        return (bool) config('filesystems.disks.s3.bucket')
            && (bool) config('filesystems.disks.s3.key');
    }
}
