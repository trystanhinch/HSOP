<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Job;
use App\Models\JobUpdate;
use App\Models\JobUpdatePhoto;
use App\Services\JobNotificationService;
use App\Services\UploadStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class JobUpdateController extends Controller
{
    public const MAX_PHOTOS = 10;

    public const MAX_PHOTO_KB = 10240; // 10 MB per photo

    public function __construct(
        protected JobNotificationService $notifications,
        protected UploadStorage $uploads,
    ) {}

    public function index(Request $request, string $jobId): JsonResponse
    {
        $user = $request->user();
        $job = Job::findOrFail($jobId);

        if ($user->role === 'contractor' && $job->contractor_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        if ($user->role === 'customer' && $job->customer_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        if ($user->role === 'pm' && $job->pm_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = JobUpdate::where('job_id', $job->id)
            ->with(['postedBy:id,name,role', 'photos'])
            ->latest();

        if ($user->role === 'customer') {
            $query->where('visibility', 'customer_visible');
        }

        return response()->json($query->get());
    }

    public function store(Request $request, string $jobId): JsonResponse
    {
        $user = $request->user();
        $photoCount = is_array($request->file('photos')) ? count($request->file('photos')) : 0;

        try {
            $job = Job::findOrFail($jobId);

            if ($user->role === 'contractor' && (int) $job->contractor_id !== (int) $user->id) {
                Log::warning('Job update denied: contractor not assigned', [
                    'job_id' => $job->id,
                    'job_contractor_id' => $job->contractor_id,
                    'user_id' => $user->id,
                ]);

                return response()->json(['message' => 'Unauthorized'], 403);
            }
            if ($user->role === 'pm' && (int) $job->pm_id !== (int) $user->id) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
            if ($user->role === 'customer') {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $request->validate([
                'update_text' => 'required|string',
                'visibility' => 'in:customer_visible,internal',
            ]);

            $this->validateUploadedPhotos($request);

            $update = DB::transaction(function () use ($request, $job, $user) {
                $update = JobUpdate::create([
                    'job_id' => $job->id,
                    'posted_by' => $user->id,
                    'poster_role' => $user->role,
                    'update_text' => $request->update_text,
                    'visibility' => $request->visibility ?? 'customer_visible',
                ]);

                if ($request->hasFile('photos')) {
                    foreach ($request->file('photos') as $index => $photo) {
                        try {
                            $path = $this->uploads->store($photo, 'job-updates/'.$job->id);
                            JobUpdatePhoto::create([
                                'job_update_id' => $update->id,
                                'file_name' => $photo->getClientOriginalName(),
                                'file_url' => $this->uploads->publicUrl($path),
                                'file_size' => round($photo->getSize() / 1024, 1).' KB',
                            ]);
                        } catch (\Throwable $e) {
                            Log::error('Job update photo upload failed', [
                                'job_id' => $job->id,
                                'contractor_id' => $job->contractor_id,
                                'user_id' => $user->id,
                                'update_id' => $update->id,
                                'photo_index' => $index,
                                'photo_count' => count($request->file('photos')),
                                'file_name' => $photo->getClientOriginalName(),
                                'file_size_bytes' => $photo->getSize(),
                                'error' => $e->getMessage(),
                                'trace' => $e->getTraceAsString(),
                            ]);

                            throw $e;
                        }
                    }
                }

                return $update;
            });

            if (in_array($job->status, ['scheduled', 'contractor_assigned'], true)) {
                $job->update(['status' => 'in_progress']);
            } elseif (in_array($job->status, ['in_progress', 'progress_updated'], true)) {
                $job->update(['status' => 'progress_updated']);
            }

            try {
                if ($update->visibility === 'customer_visible') {
                    $this->notifications->progressUpdateCustomer($job->fresh(['lead', 'customer']), $update);
                } else {
                    $this->notifications->progressUpdate($job->fresh(), $user, $update->visibility);
                }
            } catch (\Throwable $e) {
                Log::error('Notification failed after job update saved', [
                    'job_id' => $job->id,
                    'update_id' => $update->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            return response()->json($update->load(['postedBy:id,name,role', 'photos']), 201);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Job update store failed', [
                'job_id' => $jobId,
                'user_id' => $user?->id,
                'user_role' => $user?->role,
                'photo_count' => $photoCount,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Progress update could not be posted. Please try again.',
            ], 500);
        }
    }

    protected function validateUploadedPhotos(Request $request): void
    {
        if (! $request->hasFile('photos')) {
            return;
        }

        $photos = $request->file('photos');
        if (! is_array($photos)) {
            $photos = [$photos];
        }

        if (count($photos) > self::MAX_PHOTOS) {
            throw ValidationException::withMessages([
                'photos' => ['You can upload up to '.self::MAX_PHOTOS.' photos per update.'],
            ]);
        }

        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif'];
        $allowedMimes = [
            'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp',
            'image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence',
            'application/octet-stream',
        ];

        foreach ($photos as $index => $photo) {
            if (! $photo || ! $photo->isValid()) {
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
