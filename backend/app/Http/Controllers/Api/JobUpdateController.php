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
use Illuminate\Support\Facades\Log;

class JobUpdateController extends Controller
{
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
        $job = Job::findOrFail($jobId);

        if ($user->role === 'contractor' && $job->contractor_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        if ($user->role === 'pm' && $job->pm_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        if ($user->role === 'customer') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'update_text' => 'required|string',
            'visibility' => 'in:customer_visible,internal',
            'photos' => 'nullable|array|max:10',
            'photos.*' => 'image|max:8192',
        ]);

        $update = JobUpdate::create([
            'job_id' => $job->id,
            'posted_by' => $user->id,
            'poster_role' => $user->role,
            'update_text' => $request->update_text,
            'visibility' => $request->visibility ?? 'customer_visible',
        ]);

        if ($request->hasFile('photos')) {
            foreach ($request->file('photos') as $photo) {
                try {
                    $path = $this->uploads->store($photo, 'job-updates/'.$job->id);
                    JobUpdatePhoto::create([
                        'job_update_id' => $update->id,
                        'file_name' => $photo->getClientOriginalName(),
                        'file_url' => $this->uploads->publicUrl($path),
                        'file_size' => round($photo->getSize() / 1024, 1).' KB',
                    ]);
                } catch (\Exception $e) {
                    Log::error('Job update photo upload failed', [
                        'job_id' => $job->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

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
        } catch (\Exception $e) {
            Log::error('Notification failed after job update', [
                'job_id' => $job->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json($update->load(['postedBy:id,name,role', 'photos']), 201);
    }
}
