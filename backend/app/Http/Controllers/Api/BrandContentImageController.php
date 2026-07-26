<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesEditableBrand;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Services\UploadStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class BrandContentImageController extends Controller
{
    use ResolvesEditableBrand;

    private const MAX_PHOTO_KB = 10240;

    private const FIXED_SLOTS = ['logo', 'hero_image', 'og_image'];

    public function __construct(protected UploadStorage $uploads) {}

    public function upload(Request $request): JsonResponse
    {
        $brand = $this->resolveEditableBrand($request);

        $data = $request->validate([
            'slot' => ['required', 'string', 'max:80'],
            'alt' => ['nullable', 'string', 'max:255'],
            'image' => ['required', 'file'],
            'confirm_empty_alt' => ['sometimes', 'boolean'],
        ]);

        $slot = $this->normalizeSlot($data['slot'], $brand);
        $alt = trim((string) ($data['alt'] ?? ''));
        if ($alt === '' && ! ($data['confirm_empty_alt'] ?? false)) {
            throw ValidationException::withMessages([
                'alt' => ['Alt text is strongly recommended. Provide alt text, or set confirm_empty_alt=true to save without it.'],
            ]);
        }

        /** @var UploadedFile $file */
        $file = $data['image'];
        $this->validateImage($file);

        $path = $this->uploads->store($file, 'brand-content/'.$brand->id.'/images');
        $url = $this->uploads->publicUrl($path);

        $images = is_array($brand->images) ? $brand->images : [];
        $previous = $this->slotValue($images, $slot);
        $images = $this->putSlot($images, $slot, [
            'url' => $url,
            'path' => $path,
            'alt' => $alt !== '' ? $alt : null,
        ]);
        $brand->images = $images;
        $brand->save();

        if (is_array($previous) && ! empty($previous['path']) && ($previous['path'] ?? null) !== $path) {
            $this->deleteStoredPath((string) $previous['path']);
        }

        return response()->json([
            'slot' => $slot,
            'image' => $this->slotValue($images, $slot),
            'images' => $this->normalizedImages($brand),
        ]);
    }

    public function updateMeta(Request $request): JsonResponse
    {
        $brand = $this->resolveEditableBrand($request);
        $data = $request->validate([
            'slot' => ['required', 'string', 'max:80'],
            'alt' => ['nullable', 'string', 'max:255'],
            'confirm_empty_alt' => ['sometimes', 'boolean'],
        ]);

        $slot = $this->normalizeSlot($data['slot'], $brand);
        $images = is_array($brand->images) ? $brand->images : [];
        $current = $this->slotValue($images, $slot);
        if (! is_array($current) || empty($current['url'])) {
            abort(404, 'No image in that slot.');
        }

        $alt = trim((string) ($data['alt'] ?? ''));
        if ($alt === '' && ! ($data['confirm_empty_alt'] ?? false)) {
            throw ValidationException::withMessages([
                'alt' => ['Alt text is strongly recommended. Provide alt text, or set confirm_empty_alt=true to clear it.'],
            ]);
        }

        $current['alt'] = $alt !== '' ? $alt : null;
        $images = $this->putSlot($images, $slot, $current);
        $brand->images = $images;
        $brand->save();

        return response()->json([
            'slot' => $slot,
            'image' => $current,
            'images' => $this->normalizedImages($brand),
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $brand = $this->resolveEditableBrand($request);
        $data = $request->validate([
            'slot' => ['required', 'string', 'max:80'],
        ]);
        $slot = $this->normalizeSlot($data['slot'], $brand);
        $images = is_array($brand->images) ? $brand->images : [];
        $current = $this->slotValue($images, $slot);
        if (is_array($current) && ! empty($current['path'])) {
            $this->deleteStoredPath((string) $current['path']);
        }
        $images = $this->clearSlot($images, $slot);
        $brand->images = $images;
        $brand->save();

        return response()->json([
            'slot' => $slot,
            'image' => null,
            'images' => $this->normalizedImages($brand),
        ]);
    }

    private function normalizeSlot(string $slot, Brand $brand): string
    {
        $slot = trim($slot);
        if (in_array($slot, self::FIXED_SLOTS, true)) {
            return $slot;
        }

        if (str_starts_with($slot, 'service:')) {
            $key = substr($slot, strlen('service:'));
            $allowed = array_column($brand->serviceCatalog(), 'key');
            if (! in_array($key, $allowed, true)) {
                abort(422, "Unknown service slot [{$slot}] for this brand.");
            }

            return 'service:'.$key;
        }

        abort(422, 'Invalid image slot. Use logo, hero_image, og_image, or service:{key}.');
    }

    private function validateImage(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages([
                'image' => ['The image could not be uploaded. Please try again.'],
            ]);
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: '');
        $mime = strtolower($file->getMimeType() ?: '');
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/octet-stream'];

        $allowedByExtension = in_array($extension, $allowedExtensions, true);
        $allowedByMime = in_array($mime, $allowedMimes, true);
        if ($mime === 'application/octet-stream' && ! $allowedByExtension) {
            throw ValidationException::withMessages([
                'image' => ['Only JPG, PNG, GIF, and WEBP images are supported.'],
            ]);
        }
        if (! $allowedByExtension && ! $allowedByMime) {
            throw ValidationException::withMessages([
                'image' => ['Only JPG, PNG, GIF, and WEBP images are supported.'],
            ]);
        }

        if (($file->getSize() ?: 0) > self::MAX_PHOTO_KB * 1024) {
            throw ValidationException::withMessages([
                'image' => ['Images must be '.self::MAX_PHOTO_KB.' KB or smaller.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $images
     * @return array<string, mixed>|null
     */
    private function slotValue(array $images, string $slot): ?array
    {
        if (str_starts_with($slot, 'service:')) {
            $key = substr($slot, strlen('service:'));
            $services = is_array($images['services'] ?? null) ? $images['services'] : [];
            $value = $services[$key] ?? null;

            return is_array($value) ? $value : null;
        }

        $value = $images[$slot] ?? null;

        return is_array($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $images
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function putSlot(array $images, string $slot, array $value): array
    {
        if (str_starts_with($slot, 'service:')) {
            $key = substr($slot, strlen('service:'));
            $services = is_array($images['services'] ?? null) ? $images['services'] : [];
            $services[$key] = $value;
            $images['services'] = $services;

            return $images;
        }

        $images[$slot] = $value;

        return $images;
    }

    /**
     * @param  array<string, mixed>  $images
     * @return array<string, mixed>
     */
    private function clearSlot(array $images, string $slot): array
    {
        if (str_starts_with($slot, 'service:')) {
            $key = substr($slot, strlen('service:'));
            $services = is_array($images['services'] ?? null) ? $images['services'] : [];
            unset($services[$key]);
            $images['services'] = $services;

            return $images;
        }

        unset($images[$slot]);

        return $images;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizedImages(Brand $brand): array
    {
        return $brand->normalizedImages();
    }

    private function deleteStoredPath(string $path): void
    {
        try {
            Storage::disk($this->uploads->diskName())->delete($path);
        } catch (\Throwable) {
            // Best-effort cleanup — DB slot is the source of truth.
        }
    }
}
