<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContractorLeadController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'contractor') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $leads = Lead::query()
            ->where(function ($q) use ($user) {
                $q->where('assigned_contractor_id', $user->id)
                    ->orWhere('site_visit_contractor_id', $user->id);
            })
            ->whereNotIn('status', ['converted', 'lost'])
            ->whereDoesntHave('job')
            ->with(['assignedPm:id,name,email,phone'])
            ->latest()
            ->get()
            ->map(fn (Lead $lead) => [
                'id' => $lead->id,
                'contact_name' => $lead->contact_name,
                'address' => $lead->address,
                'phone' => $lead->phone,
                'email' => $lead->email,
                'service_category' => $lead->service_category,
                'status' => $lead->status,
                'project_description' => $lead->project_description ?? $lead->notes,
                'site_visit_date' => $lead->site_visit_date,
                'site_visit_time' => $lead->site_visit_time,
                'contractor_price' => $lead->contractor_price,
                'contractor_price_submitted_at' => $lead->contractor_price_submitted_at,
                'contractor_price_notes' => $lead->contractor_price_notes,
                'assigned_pm' => $lead->assignedPm?->only(['id', 'name', 'email', 'phone']),
                'assignment_type' => (int) $lead->assigned_contractor_id === (int) $user->id
                    ? 'assigned'
                    : 'site_visit',
            ]);

        return response()->json(['data' => $leads]);
    }
}
