<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\UploadStorage;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileController extends Controller
{
    public function __construct(protected UploadStorage $uploads) {}

    public function show(string $path): StreamedResponse|\Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $path = str_replace(['..', '\\'], '', $path);

        if ($this->uploads->diskName() === 's3') {
            return $this->serveFromS3($path);
        }

        if (! Storage::disk('public')->exists($path)) {
            abort(404);
        }

        return response()->file(Storage::disk('public')->path($path), [
            'Cache-Control' => 'public, max-age=31536000',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    private function serveFromS3(string $path): StreamedResponse|\Illuminate\Http\Response
    {
        try {
            $content = Storage::disk('s3')->get($path);
            if ($content === null) {
                abort(404);
            }

            $mime = 'application/octet-stream';
            try {
                $mime = Storage::disk('s3')->mimeType($path) ?: $mime;
            } catch (\Throwable) {
                //
            }

            return response($content, 200, [
                'Content-Type' => $mime,
                'Cache-Control' => 'public, max-age=31536000',
                'Access-Control-Allow-Origin' => '*',
            ]);
        } catch (\Throwable) {
            abort(404);
        }
    }
}
