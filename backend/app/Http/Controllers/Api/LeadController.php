<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Job;
use App\Models\Lead;
use App\Models\Quote;
use App\Models\SiteVisit;
use App\Models\User;
use App\Mail\SiteVisitScheduledContractorMail;
use App\Mail\SiteVisitScheduledCustomerMail;
use App\Services\EmailService;
use App\Services\LeadCustomerResolver;
use App\Services\LeadQuoteWorkflowService;
use App\Services\PricingService;
use App\Services\SmsMessageTemplates;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LeadController extends Controller
{
    public function __construct(
        protected SmsService $sms,
        protected EmailService $email,
        protected PricingService $pricing,
        protected LeadQuoteWorkflowService $leadQuotes,
    ) {}
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! in_array($user->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = Lead::with(['assignedPm:id,name', 'customer:id,name', 'company:id,name']);

        if ($user->role === 'pm') {
            $query->where('assigned_pm_id', $user->id);
        }

        if ($request->show_converted !== 'true') {
            $query->where('status', '!=', 'converted');
        }

        if ($request->status) {
            $query->where('status', $request->status);
        }

        if ($request->category) {
            $query->where('service_category', $request->category);
        }

        if ($request->company_id) {
            $query->where('company_id', $request->company_id);
        }

        if ($request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('contact_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%");
            });
        }

        return response()->json($query->latest()->paginate(20));
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! in_array($user->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $this->normalizeNullableFields($request, ['address', 'phone', 'email', 'project_description', 'internal_notes']);

        $data = $request->validate([
            'contact_name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20|required_without:email',
            'email' => 'nullable|email|max:255|required_without:phone',
            'address' => 'nullable|string|max:500',
            'service_category' => 'required|in:drywall_paint,insulation',
            'source' => 'nullable|string|max:100',
            'project_description' => 'nullable|string',
            'internal_notes' => 'nullable|string',
            'company_id' => 'nullable|exists:companies,id',
            'assigned_pm_id' => 'nullable|exists:users,id',
            'assigned_contractor_id' => 'nullable|exists:users,id',
            'site_visit_date' => 'nullable|date',
            'site_visit_time' => 'nullable|date_format:H:i',
        ]);

        if ($user->role === 'pm') {
            $data['assigned_pm_id'] = $user->id;
        }

        $data['status'] = 'new';
        $data['notes'] = $data['project_description'] ?? null;

        $lead = Lead::create($data);

        AuditLog::create([
            'user_id' => $user->id,
            'user_role' => $user->role,
            'object_type' => 'lead',
            'object_id' => $lead->id,
            'action_type' => 'created',
            'new_value' => json_encode($lead->toArray()),
        ]);

        return response()->json($lead->load(['assignedPm:id,name', 'company:id,name']), 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $lead = Lead::with([
            'assignedPm:id,name,email,phone',
            'assignedContractor:id,name,email,phone',
            'customer:id,name',
            'company:id,name',
            'photos',
            'job:id,status',
            'siteVisitContractor:id,name,email,phone',
            'siteVisit',
        ])->findOrFail($id);

        if (in_array($user->role, ['owner', 'pm'], true)) {
            if ($user->role === 'pm' && $lead->assigned_pm_id !== $user->id) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $activity = AuditLog::where('object_type', 'lead')
                ->where('object_id', $lead->id)
                ->with('user:id,name,role')
                ->latest()
                ->take(20)
                ->get();

            $leadQuote = Quote::where('lead_id', $lead->id)->whereNull('job_id')->latest()->first();
            $pricingPreview = $lead->contractor_price
                ? $this->pricing->fromContractorPrice((float) $lead->contractor_price)
                : null;

            return response()->json(array_merge($lead->toArray(), [
                'activity' => $activity,
                'lead_quote' => $leadQuote,
                'pricing_preview' => $pricingPreview,
            ]));
        }

        if ($user->role === 'contractor') {
            $siteVisit = SiteVisit::where('lead_id', $lead->id)
                ->where('contractor_id', $user->id)
                ->exists();

            $directlyAssigned = (int) $lead->assigned_contractor_id === (int) $user->id;

            if (! $siteVisit && ! $directlyAssigned && (int) $lead->site_visit_contractor_id !== (int) $user->id) {
                abort(403, 'You are not assigned to this appointment.');
            }

            return response()->json([
                'id' => $lead->id,
                'contact_name' => $lead->contact_name,
                'phone' => $lead->phone,
                'email' => $lead->email,
                'address' => $lead->address,
                'service_category' => $lead->service_category,
                'project_description' => $lead->project_description ?? $lead->notes,
                'status' => $lead->status,
                'site_visit_date' => $lead->site_visit_date,
                'site_visit_time' => $lead->site_visit_time,
                'site_visit_notes' => $lead->site_visit_notes,
                'contractor_price' => $lead->contractor_price,
                'contractor_price_submitted_at' => $lead->contractor_price_submitted_at,
                'contractor_price_notes' => $lead->contractor_price_notes,
                'assigned_pm' => $lead->assignedPm?->only(['id', 'name', 'email', 'phone']),
                'assigned_contractor_id' => $lead->assigned_contractor_id,
                'company' => $lead->company?->only(['id', 'name']),
                'job' => $lead->job?->only(['id', 'status']),
            ]);
        }

        abort(403, 'Access denied.');
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (! in_array($user->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $lead = Lead::findOrFail($id);

        if ($user->role === 'pm' && $lead->assigned_pm_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $this->normalizeNullableFields($request, ['address', 'phone', 'email', 'project_description', 'internal_notes']);

        $data = $request->validate([
            'contact_name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'address' => 'sometimes|nullable|string|max:500',
            'service_category' => 'sometimes|nullable|in:drywall_paint,insulation',
            'source' => 'nullable|string|max:100',
            'project_description' => 'nullable|string',
            'internal_notes' => 'nullable|string',
            'assigned_pm_id' => 'nullable|exists:users,id',
            'assigned_contractor_id' => 'nullable|exists:users,id',
            'status' => 'sometimes|in:new,contacted,site_visit_scheduled,quote_needed,converted,lost',
            'site_visit_date' => 'nullable|date',
            'site_visit_time' => 'nullable|date_format:H:i',
        ]);

        if (isset($data['project_description'])) {
            $data['notes'] = $data['project_description'];
        }

        if ($request->has('assigned_contractor_id') && $request->assigned_contractor_id) {
            User::where('id', $request->assigned_contractor_id)->where('role', 'contractor')->firstOrFail();
        }

        $lead->update($data);

        AuditLog::create([
            'user_id' => $user->id,
            'user_role' => $user->role,
            'object_type' => 'lead',
            'object_id' => $lead->id,
            'action_type' => 'updated',
            'new_value' => json_encode($data),
        ]);

        return response()->json($lead->fresh()->load([
            'assignedPm:id,name',
            'assignedContractor:id,name,email,phone',
            'company:id,name',
        ]));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (! in_array($user->role, ['owner', 'pm'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $lead = Lead::with('job')->findOrFail($id);

        if ($user->role === 'pm' && $lead->assigned_pm_id !== $user->id) {
            return response()->json(['message' => 'You can only delete leads assigned to you.'], 403);
        }

        if ($lead->job && ! in_array($lead->job->status, ['cancelled'], true)) {
            return response()->json([
                'message' => 'Cannot delete a lead that has an active job. Cancel the job first.',
            ], 422);
        }

        $leadId = $lead->id;
        $lead->delete();

        AuditLog::create([
            'user_id' => $user->id,
            'user_role' => $user->role,
            'object_type' => 'lead',
            'object_id' => $leadId,
            'action_type' => 'lead_deleted',
        ]);

        return response()->json(['message' => 'Lead deleted successfully']);
    }

    public function convertToJob(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (! in_array($user->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $lead = Lead::findOrFail($id);

        if ($user->role === 'pm' && $lead->assigned_pm_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($lead->status === 'converted') {
            return response()->json(['message' => 'Lead already converted'], 422);
        }

        if (! $lead->contact_name) {
            return response()->json(['message' => 'Lead is missing a contact name.'], 422);
        }

        $jobId = null;
        $resolver = app(LeadCustomerResolver::class);

        DB::transaction(function () use ($lead, $user, $resolver, &$jobId) {
            $customerId = $resolver->resolveForLead($lead->fresh());
            $category = str_replace('_', ' ', $lead->service_category ?? 'service');

            $jobPayload = [
                'lead_id' => $lead->id,
                'company_id' => $lead->company_id,
                'customer_id' => $customerId,
                'pm_id' => $lead->assigned_pm_id,
                'service_category' => $lead->service_category,
                'address' => $lead->address,
                'job_title' => $lead->contact_name.' — '.ucwords($category),
                'scope_of_work' => $lead->project_description ?? $lead->notes,
                'internal_notes' => $lead->internal_notes,
                'status' => 'new_job',
            ];

            if ($lead->site_visit_contractor_id || $lead->assigned_contractor_id) {
                $jobPayload['contractor_id'] = $lead->site_visit_contractor_id ?? $lead->assigned_contractor_id;
                $jobPayload['contractor_submitted_price'] = $lead->contractor_price;
                $jobPayload['contractor_price_status'] = $lead->contractor_price ? 'submitted' : 'pending';
                $jobPayload['contractor_price_submitted_at'] = $lead->contractor_price_submitted_at;
                $jobPayload['status'] = 'contractor_assigned';
            }

            $job = Job::create($jobPayload);

            $this->pricing->seedSplitOntoJob($job);

            if (! $lead->customer_portal_token) {
                $lead->update(['customer_portal_token' => Str::random(64)]);
            }

            $lead->update(['status' => 'converted']);

            AuditLog::create([
                'user_id' => $user->id,
                'user_role' => $user->role,
                'object_type' => 'job',
                'object_id' => $job->id,
                'action_type' => 'created_from_lead',
                'new_value' => json_encode(['lead_id' => $lead->id, 'customer_id' => $customerId]),
            ]);

            $jobId = $job->id;
        });

        return response()->json(['message' => 'Lead converted to job', 'job_id' => $jobId], 201);
    }

    public function scheduleSiteVisit(Request $request, Lead $lead): JsonResponse
    {
        $user = $request->user();
        if (! in_array($user->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($user->role === 'pm' && $lead->assigned_pm_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'site_visit_date' => 'required|date',
            'site_visit_time' => 'required|date_format:H:i',
            'site_visit_contractor_id' => 'required|exists:users,id',
            'site_visit_notes' => 'nullable|string',
            'address' => 'nullable|string|max:500',
        ]);

        if (! $lead->address && ! $request->address) {
            return response()->json([
                'message' => 'Please add the job address before scheduling a site visit.',
            ], 422);
        }

        if ($request->address) {
            $lead->update(['address' => $request->address]);
            $lead->refresh();
        }

        $contractor = User::where('id', $request->site_visit_contractor_id)
            ->where('role', 'contractor')->firstOrFail();

        $resolver = app(LeadCustomerResolver::class);
        $customerId = $lead->customer_id;
        if (! $customerId) {
            $customerId = $resolver->resolveForLead($lead->fresh());
            $lead->refresh();
        }

        if (! $lead->customer_portal_token) {
            $lead->update(['customer_portal_token' => Str::random(64)]);
            $lead->refresh();
        }

        $lead->update([
            'site_visit_date' => $request->site_visit_date,
            'site_visit_time' => $request->site_visit_time,
            'site_visit_contractor_id' => $contractor->id,
            'site_visit_notes' => $request->site_visit_notes,
            'status' => 'site_visit_scheduled',
        ]);

        $siteVisit = \App\Models\SiteVisit::updateOrCreate(
            ['lead_id' => $lead->id],
            [
                'pm_id' => $lead->assigned_pm_id,
                'contractor_id' => $contractor->id,
                'customer_id' => $customerId,
                'visit_date' => $request->site_visit_date,
                'visit_time' => $request->site_visit_time,
                'notes' => $request->site_visit_notes,
                'status' => 'scheduled',
            ]
        );

        $customerPortalUrl = SmsMessageTemplates::customerPortalUrl($lead->customer_portal_token);
        $contractorPortalUrl = SmsMessageTemplates::contractorDashboardUrl();

        $customerUser = User::find($customerId);
        $this->sms->send(
            SmsService::phoneForUser($customerUser) ?? $lead->phone,
            SmsMessageTemplates::siteVisitCustomer(
                $lead,
                $request->site_visit_date,
                $request->site_visit_time,
                $customerPortalUrl
            ),
            'site_visit_scheduled',
            $customerId,
            null
        );

        $this->email->sendMailable(
            $customerUser->email ?? $lead->email,
            new SiteVisitScheduledCustomerMail($lead->fresh(), $siteVisit, $customerPortalUrl),
            'site_visit_scheduled',
            $customerId,
            null
        );

        $this->sms->send(
            SmsService::phoneForUser($contractor),
            SmsMessageTemplates::siteVisitContractor(
                $contractor,
                $lead,
                $request->site_visit_date,
                $request->site_visit_time,
                $contractorPortalUrl
            ),
            'site_visit_contractor_assigned',
            $contractor->id,
            null
        );

        $this->email->sendMailable(
            $contractor->email,
            new SiteVisitScheduledContractorMail($lead->fresh(), $siteVisit, $contractorPortalUrl),
            'site_visit_contractor_assigned',
            $contractor->id,
            null
        );

        AuditLog::create([
            'user_id' => auth()->id(),
            'user_role' => auth()->user()->role,
            'object_type' => 'lead',
            'object_id' => $lead->id,
            'action_type' => 'site_visit_scheduled',
            'new_value' => json_encode([
                'date' => $request->site_visit_date,
                'time' => $request->site_visit_time,
                'contractor_id' => $contractor->id,
            ]),
        ]);

        return response()->json([
            'message' => 'Site visit scheduled',
            'lead' => $lead->fresh()->load('siteVisitContractor:id,name'),
            'site_visit' => $siteVisit,
            'customer_portal' => $customerPortalUrl,
        ]);
    }

    public function submitPrice(Request $request, Lead $lead): JsonResponse
    {
        $user = $request->user();

        $hasSiteVisit = SiteVisit::where('lead_id', $lead->id)
            ->where('contractor_id', $user->id)
            ->exists();

        $isDirectlyAssigned = (int) $lead->assigned_contractor_id === (int) $user->id;

        if (! $hasSiteVisit && ! $isDirectlyAssigned && (int) $lead->site_visit_contractor_id !== (int) $user->id) {
            return response()->json([
                'message' => 'You are not assigned to this lead.',
            ], 403);
        }

        if ($lead->status === 'converted') {
            return response()->json([
                'message' => 'This lead has already been converted to a job. Submit pricing on the job page.',
            ], 422);
        }

        $data = $request->validate([
            'price' => 'required|numeric|min:1',
            'notes' => 'nullable|string|max:1000',
        ]);

        $lead->update([
            'contractor_price' => $data['price'],
            'contractor_price_submitted_at' => now(),
            'contractor_price_notes' => $data['notes'] ?? null,
            'status' => 'quote_needed',
        ]);

        $message = "{$user->name} submitted a price of $".number_format((float) $data['price'], 2)
            ." for the job at {$lead->address}. Please review and create the customer estimate.";

        $pm = User::find($lead->assigned_pm_id);
        if ($pm) {
            $this->sms->sendToUser($pm, $message, 'lead_price_submitted');
        }

        User::where('role', 'owner')->get()->each(function (User $admin) use ($message) {
            $this->sms->sendToUser($admin, $message, 'lead_price_submitted');
        });

        AuditLog::create([
            'user_id' => $user->id,
            'user_role' => $user->role,
            'object_type' => 'lead',
            'object_id' => $lead->id,
            'action_type' => 'contractor_price_submitted',
            'new_value' => json_encode(['price' => $data['price']]),
        ]);

        return response()->json([
            'message' => 'Price submitted. The project manager has been notified.',
            'lead' => $lead->fresh(),
        ]);
    }

    public function sendQuote(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (! in_array($user->role, ['owner', 'pm'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $lead = Lead::findOrFail($id);

        if ($user->role === 'pm' && $lead->assigned_pm_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'scope_of_work' => 'nullable|string',
            'customer_notes' => 'nullable|string',
            'internal_notes' => 'nullable|string',
        ]);

        try {
            $result = $this->leadQuotes->sendQuote(
                $lead,
                $request->input('scope_of_work'),
                $request->input('customer_notes'),
                $request->input('internal_notes'),
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first()], 422);
        }

        return response()->json([
            'message' => 'Quote sent successfully',
            ...$result,
        ]);
    }

    protected function normalizeNullableFields(Request $request, array $fields): void
    {
        foreach ($fields as $field) {
            if ($request->has($field) && $request->input($field) === '') {
                $request->merge([$field => null]);
            }
        }
    }
}
