<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Job;
use App\Models\Lead;
use App\Models\ReviewFeedback;
use App\Services\Reviews\ReviewRequestService;
use App\Services\UploadStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewFeedbackController extends Controller
{
    public function __construct(
        private ReviewRequestService $reviews,
        private UploadStorage $uploads,
    ) {}

    public function portalShow(string $token): JsonResponse
    {
        $job = $this->jobFromPortalToken($token);

        return response()->json($this->reviews->portalReviewPayload($job));
    }

    public function portalSubmit(Request $request, string $token): JsonResponse
    {
        $job = $this->jobFromPortalToken($token);

        $data = $request->validate([
            'star_rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:5000',
            'issue_category' => 'nullable|in:'.implode(',', ReviewRequestService::ISSUE_CATEGORIES),
            'photo' => 'nullable|file|max:10240|mimes:jpg,jpeg,png,webp,heic,heif',
        ]);

        if ((int) $data['star_rating'] < 5 && empty($data['issue_category'])) {
            return response()->json(['message' => 'Please select an issue category for ratings under 5 stars.'], 422);
        }

        try {
            $feedback = $this->reviews->submit($job, $data, $request->file('photo'));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $payload = $this->reviews->portalReviewPayload($job->fresh(['lead.companySource', 'reviewFeedback']));

        return response()->json([
            'message' => 'Thank you for your feedback',
            'feedback' => $feedback,
            'review' => $payload,
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = ReviewFeedback::with([
            'job:id,address,pm_id,contractor_id',
            'customer:id,name',
            'pm:id,name',
            'contractor:id,name',
        ])->latest();

        if ($user->role === 'pm') {
            $query->where('pm_id', $user->id);
        } elseif ($user->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($request->needs_follow_up === 'true') {
            $query->where('star_rating', '<', 5)
                ->whereIn('follow_up_status', ['new', 'pm_notified', 'customer_contacted', 'escalated']);
        }

        return response()->json($query->paginate(20));
    }

    public function updateFollowUp(Request $request, ReviewFeedback $reviewFeedback): JsonResponse
    {
        $user = $request->user();
        if ($user->role === 'pm' && (int) $reviewFeedback->pm_id !== (int) $user->id) {
            abort(403);
        }
        if (! in_array($user->role, ['owner', 'pm'], true)) {
            abort(403);
        }

        $data = $request->validate([
            'follow_up_status' => 'required|in:new,pm_notified,customer_contacted,resolved,escalated',
            'resolution_notes' => 'nullable|string|max:5000',
        ]);

        $reviewFeedback->update($data);

        return response()->json(['message' => 'Follow-up updated', 'feedback' => $reviewFeedback->fresh()]);
    }

    private function jobFromPortalToken(string $token): Job
    {
        $lead = Lead::where('customer_portal_token', $token)->firstOrFail();
        $job = Job::where('lead_id', $lead->id)->latest('id')->firstOrFail();

        return $job;
    }
}
