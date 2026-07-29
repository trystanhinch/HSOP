<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SiteVisit;
use App\Models\SiteVisitPhoto;
use App\Models\SiteVisitSubmission;
use App\Models\User;
use App\Services\Contractors\ContractorAssignmentService;
use App\Services\EmailService;
use App\Services\SmsMessageTemplates;
use App\Services\SmsService;
use App\Services\UploadStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteVisitWorkflowController extends Controller
{
    public function __construct(
        protected ContractorAssignmentService $assignments,
        protected UploadStorage $uploads,
        protected SmsService $sms,
        protected EmailService $email,
    ) {}

    /**
     * CT-04: Show full site visit detail + submission for contractor.
     */
    public function show(Request $request, SiteVisit $siteVisit): JsonResponse
    {
        $user = $request->user();
        $lead = $siteVisit->lead;

        if ($user->role === 'customer') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        if ($user->role === 'contractor' && (int) $siteVisit->contractor_id !== (int) $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $siteVisit->load([
            'lead:id,contact_name,address,phone,email,service_category,project_description,notes,site_visit_date,site_visit_time',
            'lead.photos',
            'pm:id,name,email,phone',
            'submission.photos',
        ]);

        $submission = $siteVisit->submission;

        return response()->json([
            'site_visit' => [
                'id' => $siteVisit->id,
                'visit_date' => $siteVisit->visit_date,
                'visit_time' => $siteVisit->visit_time,
                'status' => $siteVisit->status,
                'notes' => $siteVisit->notes,
                'accepted_at' => $siteVisit->accepted_at,
                'declined_at' => $siteVisit->declined_at,
                'completed_at' => $siteVisit->completed_at,
            ],
            'lead' => [
                'id' => $lead->id,
                'contact_name' => $lead->contact_name,
                'address' => $lead->address,
                'phone' => $lead->phone,
                'email' => $lead->email,
                'service_category' => $lead->service_category,
                'description' => $lead->project_description ?? $lead->notes,
                'photos' => $lead->photos->map(fn ($p) => ['id' => $p->id, 'url' => $p->file_url]),
            ],
            'pm' => $siteVisit->pm?->only(['id', 'name', 'email', 'phone']),
            'submission' => $submission ? [
                'id' => $submission->id,
                'status' => $submission->status,
                'measurements' => $submission->measurements,
                'materials_notes' => $submission->materials_notes,
                'labour_estimate' => $submission->labour_estimate,
                'crew_size' => $submission->crew_size,
                'duration_estimate' => $submission->duration_estimate,
                'assumptions' => $submission->assumptions,
                'exclusions' => $submission->exclusions,
                'contractor_price' => $submission->contractor_price,
                'price_notes' => $submission->price_notes,
                'price_submitted_at' => $submission->price_submitted_at,
                'visit_completed_at' => $submission->visit_completed_at,
                'photos' => $submission->photos->map(fn ($p) => [
                    'id' => $p->id,
                    'url' => $p->file_url,
                    'file_name' => $p->file_name,
                    'caption' => $p->caption,
                ]),
            ] : null,
            'directions_url' => $lead->address
                ? 'https://www.google.com/maps/dir/?api=1&destination='.urlencode($lead->address)
                : null,
        ]);
    }

    /**
     * CT-04: Contractor accepts the site visit.
     */
    public function accept(Request $request, SiteVisit $siteVisit): JsonResponse
    {
        $this->assertContractorAccess($request->user(), $siteVisit);

        if ($siteVisit->accepted_at) {
            return response()->json(['message' => 'Already accepted'], 422);
        }

        $siteVisit->update([
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);

        return response()->json(['message' => 'Site visit accepted', 'site_visit' => $siteVisit->fresh()]);
    }

    /**
     * CT-04: Contractor declines the site visit.
     */
    public function decline(Request $request, SiteVisit $siteVisit): JsonResponse
    {
        $this->assertContractorAccess($request->user(), $siteVisit);

        $siteVisit->update([
            'status' => 'declined',
            'declined_at' => now(),
        ]);

        $pm = User::find($siteVisit->pm_id);
        if ($pm) {
            $label = $siteVisit->lead?->address ?: 'site visit #'.$siteVisit->id;
            $this->sms->sendToUser($pm, "{$request->user()->name} declined the site visit at {$label}.", 'site_visit_declined');
        }

        return response()->json(['message' => 'Site visit declined', 'site_visit' => $siteVisit->fresh()]);
    }

    /**
     * CT-04: Save draft or submit final site visit data.
     */
    public function saveDraft(Request $request, SiteVisit $siteVisit): JsonResponse
    {
        $user = $request->user();
        $this->assertContractorAccess($user, $siteVisit);

        $data = $request->validate([
            'measurements' => 'nullable|array',
            'materials_notes' => 'nullable|string|max:2000',
            'labour_estimate' => 'nullable|string|max:255',
            'crew_size' => 'nullable|string|max:50',
            'duration_estimate' => 'nullable|string|max:255',
            'assumptions' => 'nullable|string|max:2000',
            'exclusions' => 'nullable|string|max:2000',
            'contractor_price' => 'nullable|numeric|min:0',
            'price_notes' => 'nullable|string|max:2000',
        ]);

        $submission = SiteVisitSubmission::updateOrCreate(
            ['site_visit_id' => $siteVisit->id, 'contractor_id' => $user->id],
            array_merge($data, [
                'lead_id' => $siteVisit->lead_id,
                'status' => 'draft',
                'is_test_data' => (bool) ($user->is_test_data ?? false),
            ])
        );

        return response()->json(['message' => 'Draft saved', 'submission' => $submission->fresh('photos')]);
    }

    /**
     * CT-04: Submit final pricing (locks the form).
     */
    public function submitPrice(Request $request, SiteVisit $siteVisit): JsonResponse
    {
        $user = $request->user();
        $this->assertContractorAccess($user, $siteVisit);

        $request->validate([
            'contractor_price' => 'required|numeric|min:1',
            'price_notes' => 'nullable|string|max:2000',
        ]);

        $submission = SiteVisitSubmission::where('site_visit_id', $siteVisit->id)
            ->where('contractor_id', $user->id)
            ->first();

        if ($submission && $submission->status === 'submitted') {
            return response()->json([
                'message' => 'Price already submitted for this visit. Use the revise action if the PM requests changes.',
                'submission' => $submission,
            ], 422);
        }

        $submission = SiteVisitSubmission::updateOrCreate(
            ['site_visit_id' => $siteVisit->id, 'contractor_id' => $user->id],
            [
                'lead_id' => $siteVisit->lead_id,
                'contractor_price' => $request->contractor_price,
                'price_notes' => $request->price_notes,
                'price_submitted_at' => now(),
                'status' => 'submitted',
                'is_test_data' => (bool) ($user->is_test_data ?? false),
            ]
        );

        $siteVisit->lead?->update([
            'contractor_price' => $request->contractor_price,
            'contractor_price_submitted_at' => now(),
            'contractor_price_notes' => $request->price_notes,
        ]);

        $this->notifyPmOfSubmission($siteVisit, $submission, $user);

        AuditLog::create([
            'user_id' => $user->id,
            'user_role' => $user->role,
            'object_type' => 'site_visit',
            'object_id' => $siteVisit->id,
            'action_type' => 'site_visit_price_submitted',
            'new_value' => json_encode(['price' => $request->contractor_price]),
        ]);

        return response()->json([
            'message' => 'Price submitted. PM has been notified.',
            'submission' => $submission->fresh('photos'),
        ]);
    }

    /**
     * CT-04: PM requests revision → unlocks contractor form.
     */
    public function requestRevision(Request $request, SiteVisit $siteVisit): JsonResponse
    {
        $user = $request->user();
        if (! in_array($user->role, ['owner', 'pm'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $submission = SiteVisitSubmission::where('site_visit_id', $siteVisit->id)->first();
        if (! $submission) {
            return response()->json(['message' => 'No submission found'], 404);
        }

        $submission->update(['status' => 'revision_requested']);

        $contractor = User::find($siteVisit->contractor_id);
        if ($contractor) {
            $label = $siteVisit->lead?->address ?: 'site visit #'.$siteVisit->id;
            $this->sms->sendToUser(
                $contractor,
                "The PM has requested revisions to your price submission for {$label}. Please review and resubmit.",
                'site_visit_revision_requested'
            );
        }

        return response()->json(['message' => 'Revision requested', 'submission' => $submission->fresh()]);
    }

    /**
     * CT-04: Contractor re-submits after PM revision request.
     */
    public function revise(Request $request, SiteVisit $siteVisit): JsonResponse
    {
        $user = $request->user();
        $this->assertContractorAccess($user, $siteVisit);

        $submission = SiteVisitSubmission::where('site_visit_id', $siteVisit->id)
            ->where('contractor_id', $user->id)
            ->firstOrFail();

        if ($submission->status !== 'revision_requested') {
            return response()->json(['message' => 'Revision not requested — cannot resubmit'], 422);
        }

        $data = $request->validate([
            'contractor_price' => 'required|numeric|min:1',
            'price_notes' => 'nullable|string|max:2000',
            'measurements' => 'nullable|array',
            'materials_notes' => 'nullable|string|max:2000',
            'labour_estimate' => 'nullable|string|max:255',
            'crew_size' => 'nullable|string|max:50',
            'duration_estimate' => 'nullable|string|max:255',
            'assumptions' => 'nullable|string|max:2000',
            'exclusions' => 'nullable|string|max:2000',
        ]);

        $submission->update(array_merge($data, [
            'status' => 'revised',
            'price_submitted_at' => now(),
        ]));

        $siteVisit->lead?->update([
            'contractor_price' => $data['contractor_price'],
            'contractor_price_submitted_at' => now(),
            'contractor_price_notes' => $data['price_notes'] ?? null,
        ]);

        $this->notifyPmOfSubmission($siteVisit, $submission->fresh(), $user, revised: true);

        return response()->json(['message' => 'Revised price submitted', 'submission' => $submission->fresh('photos')]);
    }

    /**
     * CT-04: Mark site visit complete.
     */
    public function markComplete(Request $request, SiteVisit $siteVisit): JsonResponse
    {
        $user = $request->user();
        $this->assertContractorAccess($user, $siteVisit);

        $siteVisit->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $submission = SiteVisitSubmission::where('site_visit_id', $siteVisit->id)
            ->where('contractor_id', $user->id)
            ->first();

        if ($submission) {
            $submission->update(['visit_completed_at' => now()]);
        }

        return response()->json(['message' => 'Site visit marked complete', 'site_visit' => $siteVisit->fresh()]);
    }

    /**
     * CT-04: Upload photo(s) to site visit submission.
     */
    public function uploadPhoto(Request $request, SiteVisit $siteVisit): JsonResponse
    {
        $user = $request->user();
        $this->assertContractorAccess($user, $siteVisit);

        $request->validate([
            'photo' => 'required|file|mimes:jpg,jpeg,png,webp,heic,heif|max:10240',
            'caption' => 'nullable|string|max:255',
        ]);

        $submission = SiteVisitSubmission::firstOrCreate(
            ['site_visit_id' => $siteVisit->id, 'contractor_id' => $user->id],
            [
                'lead_id' => $siteVisit->lead_id,
                'status' => 'draft',
                'is_test_data' => (bool) ($user->is_test_data ?? false),
            ]
        );

        $file = $request->file('photo');
        $path = $this->uploads->store($file, 'site-visit-photos/'.$siteVisit->id);
        $url = $this->uploads->publicUrl($path);

        $photo = SiteVisitPhoto::create([
            'site_visit_submission_id' => $submission->id,
            'lead_id' => $siteVisit->lead_id,
            'uploaded_by' => $user->id,
            'file_url' => $url,
            'file_name' => $file->getClientOriginalName(),
            'caption' => $request->caption,
        ]);

        return response()->json(['message' => 'Photo uploaded', 'photo' => $photo], 201);
    }

    private function assertContractorAccess(User $user, SiteVisit $siteVisit): void
    {
        if ($user->role !== 'contractor' || (int) $siteVisit->contractor_id !== (int) $user->id) {
            abort(403, 'Unauthorized');
        }
    }

    private function notifyPmOfSubmission(SiteVisit $siteVisit, SiteVisitSubmission $sub, User $contractor, bool $revised = false): void
    {
        $pm = User::find($siteVisit->pm_id);
        if (! $pm) {
            return;
        }

        $label = $siteVisit->lead?->address ?: 'site visit #'.$siteVisit->id;
        $prefix = $revised ? 'Revised price' : 'Price submitted';
        $structuredMsg = "{$prefix} by {$contractor->name} for {$label}:\n"
            ."• Price: $".number_format((float) $sub->contractor_price, 2)."\n"
            .($sub->materials_notes ? "• Materials: {$sub->materials_notes}\n" : '')
            .($sub->labour_estimate ? "• Labour: {$sub->labour_estimate}\n" : '')
            .($sub->crew_size ? "• Crew: {$sub->crew_size}\n" : '')
            .($sub->duration_estimate ? "• Duration: {$sub->duration_estimate}\n" : '')
            .($sub->assumptions ? "• Assumptions: {$sub->assumptions}\n" : '')
            .($sub->exclusions ? "• Exclusions: {$sub->exclusions}\n" : '')
            .($sub->price_notes ? "• Notes: {$sub->price_notes}\n" : '');

        $this->sms->sendToUser($pm, $structuredMsg, 'site_visit_price_submitted');

        if ($pm->email && ! str_contains((string) $pm->email, '@placeholder.')) {
            $url = SmsMessageTemplates::frontendUrl('leads/'.$siteVisit->lead_id);
            $this->email->send(
                $pm->email,
                $revised ? 'Revised Price Submitted' : 'Site Visit Price Submitted',
                'emails.notification',
                [
                    'heading' => $revised ? 'Revised Price Submitted' : 'Site Visit Price Submitted',
                    'body' => $structuredMsg,
                    'actionUrl' => $url,
                    'actionLabel' => 'View Lead',
                ],
                'site_visit_price_submitted',
                $pm->id,
                null
            );
        }

        User::where('role', 'owner')->get()->each(function (User $admin) use ($structuredMsg) {
            $this->sms->sendToUser($admin, $structuredMsg, 'site_visit_price_submitted');
        });
    }
}
