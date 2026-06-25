<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Job;
use App\Models\Lead;
use App\Services\LeadCustomerResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeadController extends Controller
{
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

        $data = $request->validate([
            'contact_name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'address' => 'required|string|max:500',
            'service_category' => 'required|in:drywall_paint,insulation',
            'source' => 'nullable|string|max:100',
            'project_description' => 'nullable|string',
            'internal_notes' => 'nullable|string',
            'company_id' => 'nullable|exists:companies,id',
            'assigned_pm_id' => 'nullable|exists:users,id',
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

        if (! in_array($user->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $lead = Lead::with(['assignedPm:id,name', 'customer:id,name', 'company:id,name', 'photos', 'job:id,status'])
            ->findOrFail($id);

        if ($user->role === 'pm' && $lead->assigned_pm_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $activity = AuditLog::where('object_type', 'lead')
            ->where('object_id', $lead->id)
            ->with('user:id,name,role')
            ->latest()
            ->take(20)
            ->get();

        return response()->json(array_merge($lead->toArray(), ['activity' => $activity]));
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

        $data = $request->validate([
            'contact_name' => 'sometimes|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'address' => 'sometimes|string|max:500',
            'service_category' => 'sometimes|in:drywall_paint,insulation',
            'source' => 'nullable|string|max:100',
            'project_description' => 'nullable|string',
            'internal_notes' => 'nullable|string',
            'assigned_pm_id' => 'nullable|exists:users,id',
            'status' => 'sometimes|in:new,contacted,site_visit_scheduled,quote_needed,converted,lost',
            'site_visit_date' => 'nullable|date',
            'site_visit_time' => 'nullable|date_format:H:i',
        ]);

        if (isset($data['project_description'])) {
            $data['notes'] = $data['project_description'];
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

        return response()->json($lead->fresh()->load(['assignedPm:id,name', 'company:id,name']));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        if ($request->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        Lead::findOrFail($id)->delete();

        return response()->json(['message' => 'Lead deleted']);
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

        if (! $lead->address || ! $lead->contact_name) {
            return response()->json(['message' => 'Lead is missing required information (name or address)'], 422);
        }

        $jobId = null;
        $resolver = app(LeadCustomerResolver::class);

        DB::transaction(function () use ($lead, $user, $resolver, &$jobId) {
            $customerId = $resolver->resolveForLead($lead->fresh());
            $category = str_replace('_', ' ', $lead->service_category ?? 'service');

            $job = Job::create([
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
            ]);

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
}
