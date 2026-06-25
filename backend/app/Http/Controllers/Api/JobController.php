<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Job;
use App\Models\Quote;
use App\Models\User;
use App\Services\JobNotificationService;
use App\Services\PayoutWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JobController extends Controller
{
    public function __construct(
        protected JobNotificationService $notifications,
        protected PayoutWorkflowService $payouts
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Job::with(['customer:id,name', 'contractor:id,name', 'pm:id,name', 'company:id,name', 'invoice', 'payout']);

        if ($user->role === 'pm') {
            $query->where('pm_id', $user->id);
        } elseif ($user->role === 'contractor') {
            $query->where('contractor_id', $user->id);
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

        return response()->json($job);
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

    public function destroy(string $id): JsonResponse
    {
        return response()->json(['message' => 'Not allowed'], 403);
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

        $data = $request->validate(['price' => 'required|numeric|min:0']);
        $job = Job::findOrFail($id);

        if ($job->contractor_id !== $user->id) {
            abort(403, 'You do not have permission to view this job.');
        }

        $job->update([
            'contractor_submitted_price' => $data['price'],
            'contractor_price_status' => 'submitted',
            'contractor_price_submitted_at' => now(),
        ]);

        $this->notifications->priceSubmitted($job->fresh());

        return response()->json(['message' => 'Price submitted', 'job' => $job->fresh()]);
    }

    public function approvePrice(Request $request, string $id): JsonResponse
    {
        if (! in_array($request->user()->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $job = Job::findOrFail($id);
        $job->update(['contractor_price_status' => 'approved']);

        return response()->json(['message' => 'Contractor price approved']);
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
