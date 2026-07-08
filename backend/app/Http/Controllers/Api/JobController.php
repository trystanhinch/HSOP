<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\RevisionRequest;
use App\Models\RevisionRequestPhoto;
use App\Models\Setting;
use App\Models\SiteVisit;
use App\Models\User;
use App\Mail\JobReadyForReviewMail;
use App\Mail\PaymentConfirmedMail;
use App\Mail\RevisionRequestedMail;
use App\Services\EmailService;
use App\Services\JobNotificationService;
use App\Services\PricingService;
use App\Services\PayoutWorkflowService;
use App\Services\SmsMessageTemplates;
use App\Services\SmsService;
use App\Services\UploadStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class JobController extends Controller
{
    public function __construct(
        protected JobNotificationService $notifications,
        protected PayoutWorkflowService $payouts,
        protected PricingService $pricing,
        protected SmsService $sms,
        protected EmailService $email,
        protected UploadStorage $uploads,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role === 'contractor') {
            $jobs = Job::with(['customer:id,name', 'pm:id,name', 'company:id,name'])
                ->where('contractor_id', $user->id)
                ->latest()
                ->get()
                ->map(fn ($j) => [
                    'type' => 'job',
                    'id' => $j->id,
                    'job_title' => $j->job_title,
                    'address' => $j->address,
                    'service_category' => $j->service_category,
                    'status' => $j->status,
                    'contractor_price_status' => $j->contractor_price_status,
                    'contractor_submitted_price' => $j->contractor_submitted_price,
                    'scheduled_start_date' => $j->scheduled_start_date,
                    'customer' => $j->customer?->only(['id', 'name']),
                    'pm' => $j->pm?->only(['id', 'name', 'phone']),
                    'url' => '/jobs/'.$j->id,
                ]);

            $siteVisits = SiteVisit::where('contractor_id', $user->id)
                ->whereHas('lead', fn ($q) => $q->where('status', '!=', 'converted'))
                ->with([
                    'lead:id,contact_name,address,service_category,project_description,status,contractor_price,contractor_price_submitted_at,contractor_price_notes',
                    'pm:id,name,phone',
                ])
                ->orderByDesc('visit_date')
                ->get()
                ->map(fn ($sv) => [
                    'type' => 'site_visit',
                    'id' => 'sv_'.$sv->id,
                    'site_visit_id' => $sv->id,
                    'lead_id' => $sv->lead_id,
                    'job_title' => 'Site Visit — '.($sv->lead->contact_name ?? 'Customer'),
                    'address' => $sv->lead->address ?? '',
                    'service_category' => $sv->lead->service_category ?? '',
                    'status' => 'site_visit_'.$sv->status,
                    'visit_date' => $sv->visit_date,
                    'visit_time' => $sv->visit_time,
                    'contractor_price_status' => $sv->lead->contractor_price ? 'submitted' : 'pending',
                    'contractor_submitted_price' => $sv->lead->contractor_price,
                    'customer' => ['id' => null, 'name' => $sv->lead->contact_name ?? ''],
                    'pm' => $sv->pm?->only(['id', 'name', 'phone']),
                    'description' => $sv->lead->project_description ?? '',
                    'url' => '/leads/'.$sv->lead_id,
                ]);

            $siteVisitLeadIds = $siteVisits->pluck('lead_id')->filter()->values();

            $leadAppointments = Lead::where('site_visit_contractor_id', $user->id)
                ->where('status', '!=', 'converted')
                ->whereDoesntHave('job')
                ->when($siteVisitLeadIds->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $siteVisitLeadIds))
                ->with(['assignedPm:id,name,phone'])
                ->orderByDesc('site_visit_date')
                ->get()
                ->map(fn ($lead) => [
                    'type' => 'site_visit',
                    'id' => 'lead_'.$lead->id,
                    'site_visit_id' => null,
                    'lead_id' => $lead->id,
                    'job_title' => 'Site Visit — '.($lead->contact_name ?? 'Customer'),
                    'address' => $lead->address ?? '',
                    'service_category' => $lead->service_category ?? '',
                    'status' => $lead->status === 'quote_needed' ? 'site_visit_completed' : 'site_visit_scheduled',
                    'visit_date' => $lead->site_visit_date,
                    'visit_time' => $lead->site_visit_time,
                    'contractor_price_status' => $lead->contractor_price ? 'submitted' : 'pending',
                    'contractor_submitted_price' => $lead->contractor_price,
                    'customer' => ['id' => null, 'name' => $lead->contact_name ?? ''],
                    'pm' => $lead->assignedPm?->only(['id', 'name', 'phone']),
                    'description' => $lead->project_description ?? '',
                    'url' => '/leads/'.$lead->id,
                ]);

            $existingLeadIds = $siteVisits->pluck('lead_id')
                ->concat($leadAppointments->pluck('lead_id'))
                ->filter()
                ->unique()
                ->values();

            $directLeads = Lead::where('assigned_contractor_id', $user->id)
                ->whereNotIn('status', ['converted', 'lost'])
                ->whereDoesntHave('job')
                ->when($existingLeadIds->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $existingLeadIds))
                ->with(['assignedPm:id,name,phone'])
                ->latest()
                ->get()
                ->map(fn ($lead) => [
                    'type' => 'direct_lead',
                    'id' => 'lead_'.$lead->id,
                    'lead_id' => $lead->id,
                    'job_title' => 'Lead — '.($lead->contact_name ?? 'Customer'),
                    'address' => $lead->address ?? '',
                    'service_category' => $lead->service_category ?? '',
                    'status' => 'lead_assigned',
                    'contractor_price_status' => $lead->contractor_price ? 'submitted' : 'pending',
                    'contractor_submitted_price' => $lead->contractor_price,
                    'customer' => ['id' => null, 'name' => $lead->contact_name ?? ''],
                    'pm' => $lead->assignedPm?->only(['id', 'name', 'phone']),
                    'description' => $lead->project_description ?? '',
                    'url' => '/leads/'.$lead->id,
                ]);

            $all = $jobs->concat($siteVisits)->concat($leadAppointments)->concat($directLeads)->sortByDesc(function ($item) {
                $date = $item['scheduled_start_date'] ?? $item['visit_date'] ?? null;

                return $date ? strtotime((string) $date) : 0;
            })->values();

            return response()->json([
                'data' => $all,
                'meta' => ['total' => $all->count()],
            ]);
        }

        $query = Job::with(['customer:id,name', 'contractor:id,name', 'pm:id,name', 'company:id,name', 'invoice', 'payout']);

        if ($user->role === 'pm') {
            $query->where('pm_id', $user->id);
        } elseif ($user->role === 'customer') {
            $query->where('customer_id', $user->id);
        }

        if ($request->status && $request->status !== 'All') {
            $status = str_replace(' ', '_', strtolower($request->status));
            $query->where('status', $status);
        }

        if ($request->q) {
            $s = $request->q;
            $query->where(function ($qq) use ($s) {
                $qq->where('address', 'like', "%{$s}%")
                    ->orWhere('job_title', 'like', "%{$s}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$s}%"));
            });
        }
        if ($request->contractor_id) {
            $query->where('contractor_id', $request->contractor_id);
        }
        if ($request->pm_id) {
            $query->where('pm_id', $request->pm_id);
        }
        if ($request->date_from) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->date_to) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        if ($request->payment_status) {
            $query->whereHas('invoice', fn ($i) => $i->where('status', $request->payment_status));
        }
        if ($request->payout_status) {
            $query->whereHas('payout', fn ($p) => $p->where('status', $request->payout_status));
        }

        return response()->json($query->latest()->paginate(20));
    }

    public function search(Request $request): JsonResponse
    {
        if (! in_array($request->user()->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = Job::with(['customer:id,name', 'contractor:id,name', 'pm:id,name', 'invoice', 'payout']);

        if ($request->user()->role === 'pm') {
            $query->where('pm_id', $request->user()->id);
        }

        if ($request->q) {
            $s = $request->q;
            $query->where(function ($qq) use ($s) {
                $qq->where('address', 'like', "%{$s}%")
                    ->orWhere('job_title', 'like', "%{$s}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$s}%"));
            });
        }

        if ($request->status) {
            $query->where('status', $request->status);
        }
        if ($request->contractor_id) {
            $query->where('contractor_id', $request->contractor_id);
        }
        if ($request->pm_id) {
            $query->where('pm_id', $request->pm_id);
        }
        if ($request->date_from) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->date_to) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        if ($request->payment_status) {
            $query->whereHas('invoice', fn ($i) => $i->where('status', $request->payment_status));
        }
        if ($request->payout_status) {
            $query->whereHas('payout', fn ($p) => $p->where('status', $request->payout_status));
        }

        return response()->json($query->latest()->paginate(20));
    }

    public function store(Request $request): JsonResponse
    {
        if (! in_array($request->user()->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'customer_id' => 'required|exists:users,id',
            'company_id' => 'nullable|exists:companies,id',
            'service_category' => 'required|in:drywall_paint,insulation',
            'address' => 'required|string',
            'job_title' => 'nullable|string',
            'scope_of_work' => 'nullable|string',
            'pm_id' => 'nullable|exists:users,id',
        ]);

        $data['status'] = 'new_job';
        if ($request->user()->role === 'pm') {
            $data['pm_id'] = $request->user()->id;
        }

        $job = Job::create($data);
        $this->pricing->seedSplitOntoJob($job);
        $this->notifications->audit('job_created', 'job', $job->id);

        return response()->json($job->load(['customer:id,name', 'pm:id,name']), 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $job = Job::with([
            'lead:id,contact_name,phone,email,address',
            'customer:id,name,email',
            'contractor:id,name,email',
            'pm:id,name,email',
            'company:id,name',
            'quote.items',
            'invoice',
            'payout',
            'updates' => fn ($q) => $q->latest(),
            'updates.photos',
            'updates.postedBy:id,name,role',
        ])->findOrFail($id);

        if ($user->role === 'pm' && $job->pm_id !== $user->id) {
            abort(403, 'You do not have permission to view this job.');
        }
        if ($user->role === 'contractor' && $job->contractor_id !== $user->id) {
            abort(403, 'You do not have permission to view this job.');
        }
        if ($user->role === 'customer' && $job->customer_id !== $user->id) {
            abort(403, 'You do not have permission to view this job.');
        }

        if ($user->role === 'customer') {
            $job->setRelation('updates', $job->updates->where('visibility', 'customer_visible')->values());
        }

        $timelineService = app(\App\Services\ActivityTimelineService::class);

        return response()->json(array_merge($job->toArray(), [
            'next_action' => $job->pendingNextAction()->with('responsibleUser:id,name,role')->first(),
            'event_timeline' => $timelineService->forSubject($job, 20),
        ]));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if (! in_array($request->user()->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $job = Job::findOrFail($id);

        if ($request->user()->role === 'pm' && $job->pm_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'job_title' => 'nullable|string',
            'scope_of_work' => 'nullable|string',
            'internal_notes' => 'nullable|string',
            'status' => 'nullable|string',
            'notes' => 'nullable|string',
            'contractor_submitted_price' => 'nullable|numeric|min:0',
        ]);

        $job->update($data);

        return response()->json($job->fresh());
    }

    public function destroy(Request $request, Job $job): JsonResponse
    {
        if ($request->user()->role !== 'owner') {
            return response()->json(['message' => 'Only admins can delete jobs.'], 403);
        }

        $job->load(['updates.photos', 'revisionRequests.photos', 'invoice', 'quote.items']);

        DB::transaction(function () use ($job, $request) {
            foreach ($job->updates as $update) {
                foreach ($update->photos as $photo) {
                    $this->deleteStoredFile($photo->file_url);
                    $photo->delete();
                }
                $update->delete();
            }

            foreach ($job->revisionRequests as $revision) {
                foreach ($revision->photos as $photo) {
                    $this->deleteStoredFile($photo->file_url);
                    $photo->delete();
                }
                $revision->delete();
            }

            $job->messages()->delete();
            $job->invoice?->payments()->delete();
            $job->invoice?->delete();
            $job->payouts()->delete();
            $job->quote?->items()->delete();
            $job->quote?->delete();
            $job->files()->delete();

            if ($job->lead) {
                $job->lead->update([
                    'status' => 'site_visit_scheduled',
                    'contractor_price' => null,
                    'contractor_price_submitted_at' => null,
                    'contractor_price_notes' => null,
                ]);
            }

            AuditLog::create([
                'user_id' => $request->user()->id,
                'user_role' => $request->user()->role,
                'object_type' => 'job',
                'object_id' => $job->id,
                'action_type' => 'job_deleted',
                'created_at' => now(),
            ]);

            $job->delete();
        });

        return response()->json(['message' => 'Job deleted successfully']);
    }

    private function deleteStoredFile(?string $url): void
    {
        if (! $url) {
            return;
        }

        $path = null;
        if (str_contains($url, 'digitaloceanspaces.com')) {
            $path = ltrim(parse_url($url, PHP_URL_PATH) ?? '', '/');
        } elseif (preg_match('#/api/files/(.+)$#', $url, $matches)) {
            $path = urldecode($matches[1]);
        }

        if (! $path) {
            return;
        }

        try {
            if (config('filesystems.uploads_disk') === 's3' || config('filesystems.default') === 's3') {
                Storage::disk('s3')->delete($path);
            } else {
                Storage::disk('public')->delete($path);
            }
        } catch (\Exception $e) {
            Log::warning('Could not delete file from storage', ['path' => $path, 'error' => $e->getMessage()]);
        }
    }

    public function assignPm(Request $request, string $id): JsonResponse
    {
        if ($request->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate(['pm_id' => 'required|exists:users,id']);
        $pm = User::where('id', $request->pm_id)->where('role', 'pm')->firstOrFail();
        $job = Job::findOrFail($id);
        $job->update(['pm_id' => $pm->id]);

        $this->notifications->audit('pm_assigned', 'job', $job->id, null, null, ['pm_id' => $pm->id]);

        return response()->json(['message' => 'PM assigned', 'pm' => $pm->only('id', 'name', 'email')]);
    }

    public function assignContractor(Request $request, string $id): JsonResponse
    {
        if (! in_array($request->user()->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate(['contractor_id' => 'required|exists:users,id']);
        $contractor = User::where('id', $request->contractor_id)->where('role', 'contractor')->firstOrFail();
        $job = Job::findOrFail($id);

        if ($request->user()->role === 'pm' && $job->pm_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $job->update([
            'contractor_id' => $contractor->id,
            'status' => 'contractor_assigned',
            'contractor_price_status' => 'pending',
        ]);

        $this->notifications->contractorAssigned($job->fresh(), $contractor);

        return response()->json(['message' => 'Contractor assigned']);
    }

    public function schedule(Request $request, string $id): JsonResponse
    {
        if (! in_array($request->user()->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'scheduled_start_date' => 'required|date',
            'scheduled_start_time' => 'nullable|date_format:H:i',
            'estimated_completion_date' => 'required|date|after_or_equal:scheduled_start_date',
            'schedule_notes' => 'nullable|string',
        ], [
            'scheduled_start_date.required' => 'Please select a start date.',
            'estimated_completion_date.after_or_equal' => 'Completion date must be after the start date.',
        ]);

        $job = Job::findOrFail($id);
        $wasScheduled = $job->status === 'scheduled';

        $job->update([
            ...$data,
            'scheduled_end_date' => $data['estimated_completion_date'],
            'status' => 'scheduled',
        ]);

        $this->notifications->jobScheduled($job->fresh(), $wasScheduled);

        return response()->json(['message' => 'Job scheduled', 'job' => $job->fresh()]);
    }

    public function submitPrice(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if ($user->role !== 'contractor') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $job = Job::with('contractor')->findOrFail($id);

        if ($job->contractor_id !== $user->id) {
            return response()->json(['message' => 'You are not assigned to this job.'], 403);
        }

        if ($job->contractor_price_status === 'approved') {
            return response()->json(['message' => 'Price has already been approved.'], 422);
        }

        $data = $request->validate([
            'price' => 'required|numeric|min:1',
            'estimated_duration' => 'nullable|string|max:200',
            'availability_notes' => 'nullable|string|max:500',
            'exclusions' => 'nullable|string|max:500',
            'concerns' => 'nullable|string|max:500',
        ]);

        $job->update([
            'contractor_submitted_price' => $data['price'],
            'contractor_price_status' => 'submitted',
            'contractor_price_submitted_at' => now(),
            'status' => 'contractor_pricing_pending',
        ]);

        $this->notifications->priceSubmitted($job->fresh());

        AuditLog::create([
            'user_id' => $user->id,
            'user_role' => 'contractor',
            'object_type' => 'job',
            'object_id' => $job->id,
            'action_type' => 'contractor_price_submitted',
            'new_value' => json_encode([
                'price' => $data['price'],
                'estimated_duration' => $data['estimated_duration'] ?? null,
                'availability_notes' => $data['availability_notes'] ?? null,
                'exclusions' => $data['exclusions'] ?? null,
                'concerns' => $data['concerns'] ?? null,
            ]),
        ]);

        return response()->json([
            'message' => 'Price submitted successfully. The project manager has been notified.',
            'job' => $job->fresh(),
        ]);
    }

    public function approvePrice(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (! in_array($user->role, ['owner', 'pm'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $job = Job::findOrFail($id);

        if ($user->role === 'pm' && $job->pm_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (! $job->contractor_submitted_price) {
            return response()->json(['message' => 'No price submitted yet.'], 422);
        }

        if ($job->contractor_price_status === 'approved') {
            return response()->json(['message' => 'Price has already been approved.'], 422);
        }

        $job->update(['contractor_price_status' => 'approved']);

        AuditLog::create([
            'user_id' => $user->id,
            'user_role' => $user->role,
            'object_type' => 'job',
            'object_id' => $job->id,
            'action_type' => 'contractor_price_approved',
        ]);

        return response()->json([
            'message' => 'Price approved. You can now create the customer estimate.',
            'job' => $job->fresh(),
        ]);
    }

    public function updateSplit(Request $request, Job $job): JsonResponse
    {
        if ($request->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'split_contractor_pct' => 'required|numeric|min:1|max:99',
            'split_pm_pct' => 'required|numeric|min:0|max:99',
            'split_company_pct' => 'required|numeric|min:0|max:99',
        ]);

        $total = $request->split_contractor_pct + $request->split_pm_pct + $request->split_company_pct;
        if (abs($total - 100) > 0.01) {
            return response()->json(['message' => "Split percentages must add up to 100. Current total: {$total}"], 422);
        }

        $job->update($request->only(['split_contractor_pct', 'split_pm_pct', 'split_company_pct']));

        return response()->json(['message' => 'Split updated', 'job' => $job->fresh()]);
    }

    public function contractorComplete(Request $request, Job $job): JsonResponse
    {
        if (auth()->id() !== $job->contractor_id) {
            abort(403);
        }

        if (! in_array($job->status, ['in_progress', 'progress_updated', 'scheduled', 'contractor_assigned', 'revision_requested', 'corrections_required'], true)) {
            return response()->json(['message' => 'Job cannot be marked complete in its current status.'], 422);
        }

        $job->update([
            'status' => 'pending_customer_approval',
            'pending_customer_approval_at' => now(),
        ]);

        $job->load('lead', 'customer');
        $portalUrl = SmsMessageTemplates::customerPortalUrlForJob($job);

        $this->sms->sendToUser(
            $job->customer,
            SmsMessageTemplates::jobCompletePendingApproval($job->customer, $job, $portalUrl),
            'contractor_marked_complete',
            $job->id
        );

        if ($job->customer?->email) {
            $this->email->sendMailable(
                $job->customer->email,
                new JobReadyForReviewMail($job, $portalUrl),
                'contractor_marked_complete',
                $job->customer_id,
                $job->id
            );
        }

        if ($job->pm) {
            $this->sms->sendToUser(
                $job->pm,
                "Contractor marked job at {$job->address} complete — awaiting customer approval.",
                'contractor_marked_complete',
                $job->id
            );
        }

        AuditLog::create([
            'user_id' => auth()->id(),
            'user_role' => 'contractor',
            'object_type' => 'job',
            'object_id' => $job->id,
            'action_type' => 'contractor_marked_complete',
        ]);

        return response()->json(['message' => 'Job marked complete, customer notified for review']);
    }

    public function acceptCompletion(Job $job): JsonResponse
    {
        $user = auth()->user();
        if ($user->role === 'customer' && $job->customer_id !== $user->id) {
            abort(403);
        }

        if ($job->status !== 'pending_customer_approval') {
            return response()->json(['message' => 'Job is not awaiting customer approval'], 422);
        }

        $job->update([
            'status' => 'payment_pending',
            'customer_accepted_completion_at' => now(),
        ]);

        $this->ensureInvoiceForJob($job);

        $paymentUrl = rtrim(config('app.frontend_url', 'http://localhost:5173'), '/').'/payment/'.$job->id;

        AuditLog::create([
            'user_id' => auth()->id(),
            'user_role' => $user->role,
            'object_type' => 'job',
            'object_id' => $job->id,
            'action_type' => 'customer_accepted_completion',
        ]);

        return response()->json([
            'message' => 'Completion accepted',
            'payment_url' => $paymentUrl,
        ]);
    }

    public function requestRevision(Request $request, Job $job): JsonResponse
    {
        $user = auth()->user();
        if ($user->role === 'customer' && $job->customer_id !== $user->id) {
            abort(403);
        }

        $request->validate(['description' => 'required|string|max:2000']);

        $revision = RevisionRequest::create([
            'job_id' => $job->id,
            'requested_by' => auth()->id(),
            'description' => $request->description,
            'status' => 'open',
        ]);

        if ($request->hasFile('photos')) {
            foreach ($request->file('photos') as $photo) {
                $path = $this->uploads->store($photo, 'revision-requests/'.$job->id);
                RevisionRequestPhoto::create([
                    'revision_request_id' => $revision->id,
                    'file_name' => $photo->getClientOriginalName(),
                    'file_url' => $this->uploads->publicUrl($path),
                ]);
            }
        }

        $job->update([
            'status' => 'revision_requested',
            'revision_description' => $request->description,
        ]);

        $contractorPortalUrl = SmsMessageTemplates::contractorDashboardUrl();
        $job->loadMissing('contractor');
        $this->sms->sendToUser(
            $job->contractor,
            SmsMessageTemplates::revisionRequested($job->contractor, $job, $contractorPortalUrl),
            'revision_requested',
            $job->id
        );

        if ($job->contractor?->email) {
            $this->email->sendMailable(
                $job->contractor->email,
                new RevisionRequestedMail($job->fresh(), $request->description, $contractorPortalUrl),
                'revision_requested',
                $job->contractor_id,
                $job->id
            );
        }

        AuditLog::create([
            'user_id' => auth()->id(),
            'user_role' => $user->role,
            'object_type' => 'job',
            'object_id' => $job->id,
            'action_type' => 'revision_requested',
            'new_value' => json_encode(['description' => $request->description]),
        ]);

        return response()->json(['message' => 'Revision request submitted. Contractor and PM have been notified.']);
    }

    public function notifyEtransferSent(Request $request, Job $job): JsonResponse
    {
        $user = auth()->user();
        if ($job->customer_id !== $user->id && $user->role !== 'owner') {
            abort(403);
        }

        $job->update(['status' => 'etransfer_pending_confirmation', 'payment_method' => 'e_transfer']);

        $admin = User::where('role', 'owner')->first();
        if ($admin) {
            $this->sms->sendToUser(
                $admin,
                "Customer notified e-transfer sent for job at {$job->address}. Please confirm payment.",
                'etransfer_pending',
                $job->id
            );
        }

        AuditLog::create([
            'user_id' => auth()->id(),
            'user_role' => $user->role,
            'object_type' => 'job',
            'object_id' => $job->id,
            'action_type' => 'etransfer_notified',
        ]);

        return response()->json(['message' => 'Thank you. The team has been notified to confirm your payment.']);
    }

    /**
     * STRIPE INTEGRATION POINT
     * When Stripe is added:
     * 1. Create Stripe PaymentIntent via POST /api/jobs/{job}/create-payment-intent
     * 2. Return client_secret to frontend
     * 3. Mount Stripe Elements in the #stripe-payment-element div
     * 4. On payment success, Stripe webhook calls POST /api/webhooks/stripe
     * 5. Webhook verifies signature, updates job status to paid_completed
     */
    public function confirmPayment(Request $request, Job $job): JsonResponse
    {
        if (auth()->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'payment_reference' => 'nullable|string',
            'payment_date' => 'required|date',
        ]);

        $this->ensureInvoiceForJob($job);
        $job->refresh();

        $job->update([
            'status' => 'paid_completed',
            'payment_confirmed_at' => now(),
            'payment_confirmed_by' => auth()->id(),
            'payment_reference' => $request->payment_reference,
            'payment_method' => 'e_transfer',
            'completed_at' => now(),
        ]);

        if ($job->invoice) {
            $job->invoice->update(['status' => 'paid', 'balance' => 0]);
            Payment::create([
                'invoice_id' => $job->invoice->id,
                'amount' => $job->invoice->amount,
                'method' => 'e_transfer',
                'paid_status' => true,
                'cleared_status' => true,
                'marked_by' => auth()->id(),
                'paid_date' => $request->payment_date,
                'reference_number' => $request->payment_reference,
            ]);
        }

        $this->payouts->markPayoutsReady($job);

        $job->loadMissing(['customer', 'lead']);
        if ($job->customer) {
            $this->sms->sendToUser(
                $job->customer,
                SmsMessageTemplates::paymentConfirmed($job->customer, $job),
                'payment_confirmed',
                $job->id
            );
        }

        $portalUrl = SmsMessageTemplates::customerPortalUrlForJob($job);
        if ($job->customer?->email) {
            $this->email->sendMailable(
                $job->customer->email,
                new PaymentConfirmedMail($job->fresh(), $portalUrl),
                'payment_confirmed',
                $job->customer_id,
                $job->id
            );
        }

        AuditLog::create([
            'user_id' => auth()->id(),
            'user_role' => 'owner',
            'object_type' => 'job',
            'object_id' => $job->id,
            'action_type' => 'payment_confirmed',
        ]);

        return response()->json(['message' => 'Payment confirmed. Job marked as Paid/Completed.']);
    }

    public function paymentDetails(Job $job): JsonResponse
    {
        $user = auth()->user();
        if ($user->role === 'customer' && $job->customer_id !== $user->id) {
            abort(403);
        }

        $job->load(['quote', 'invoice', 'lead']);

        return response()->json([
            'job' => [
                'id' => $job->id,
                'address' => $job->address,
                'scope_of_work' => $job->scope_of_work,
                'status' => $job->status,
            ],
            'invoice' => $job->invoice ? [
                'amount' => $job->invoice->amount,
                'gst' => $job->invoice->gst,
                'subtotal' => $job->invoice->subtotal ?? $job->quote?->customer_price_before_gst,
                'balance' => $job->invoice->balance,
                'status' => $job->invoice->status,
            ] : ($job->quote ? [
                'amount' => $job->quote->customer_total,
                'gst' => $job->quote->gst,
                'subtotal' => $job->quote->customer_price_before_gst,
                'balance' => $job->quote->customer_total,
                'status' => 'awaiting_payment',
            ] : null),
            'company_email' => Setting::where('key', 'company_email')->value('value') ?? 'payments@hsop.ca',
            'payment_instructions' => Setting::where('key', 'payment_instructions')->value('value'),
        ]);
    }

    protected function ensureInvoiceForJob(Job $job): void
    {
        if ($job->invoice) {
            return;
        }

        $quote = $job->quote;
        if (! $quote) {
            return;
        }

        Invoice::create([
            'job_id' => $job->id,
            'quote_id' => $quote->id,
            'company_id' => $quote->company_id,
            'customer_id' => $quote->customer_id,
            'invoice_number' => 'INV-'.str_pad(Invoice::count() + 1, 4, '0', STR_PAD_LEFT),
            'scope_of_work' => $quote->scope_of_work,
            'subtotal' => $quote->subtotal ?? $quote->customer_price_before_gst,
            'gst' => $quote->gst,
            'gst_rate' => $quote->gst_rate,
            'balance' => $quote->customer_total,
            'amount' => $quote->customer_total,
            'status' => 'awaiting_payment',
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
    }

    public function markReadyForReview(Job $job): JsonResponse
    {
        if (auth()->id() !== $job->contractor_id) {
            abort(403, 'Only the assigned contractor can mark this job ready for review.');
        }

        $job->update(['status' => 'ready_for_review', 'ready_for_review_at' => now()]);
        $this->notifications->audit('marked_ready_for_review', 'job', $job->id);
        $this->notifications->readyForReview($job->fresh());

        return response()->json(['message' => 'Job marked ready for review']);
    }

    public function markComplete(Job $job): JsonResponse
    {
        if (! in_array(auth()->user()->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $job->update(['status' => 'completed', 'completed_at' => now()]);
        $this->notifications->audit('marked_complete', 'job', $job->id);
        $this->notifications->jobComplete($job->fresh());
        $this->payouts->syncPayoutReadiness($job->fresh(['invoice']));

        return response()->json(['message' => 'Job marked complete']);
    }

    public function requestCorrections(Request $request, Job $job): JsonResponse
    {
        if (! in_array(auth()->user()->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate(['corrections_notes' => 'required|string|max:2000']);
        $job->update([
            'status' => 'corrections_required',
            'corrections_notes' => $request->corrections_notes,
        ]);

        $this->notifications->audit('corrections_requested', 'job', $job->id, null, null, ['notes' => $request->corrections_notes]);
        $this->notifications->correctionsRequested($job->fresh());

        return response()->json(['message' => 'Corrections requested']);
    }

    public function activityLog(Job $job): JsonResponse
    {
        $user = auth()->user();
        if ($user->role === 'pm' && $job->pm_id !== $user->id) {
            abort(403);
        }
        if ($user->role === 'contractor' && $job->contractor_id !== $user->id) {
            abort(403);
        }
        if ($user->role === 'customer' && $job->customer_id !== $user->id) {
            abort(403);
        }

        $quoteIds = Quote::where('job_id', $job->id)->pluck('id')->all();

        $logs = AuditLog::where(function ($q) use ($job, $quoteIds) {
            $q->where(fn ($qq) => $qq->where('object_type', 'job')->where('object_id', $job->id));
            if ($quoteIds) {
                $q->orWhere(fn ($qq) => $qq->where('object_type', 'quote')->whereIn('object_id', $quoteIds));
            }
        })
            ->with('user:id,name,role')
            ->latest()
            ->get();

        return response()->json($logs);
    }
}
