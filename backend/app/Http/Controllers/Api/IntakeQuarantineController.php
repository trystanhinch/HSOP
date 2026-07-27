<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IntakeQuarantine;
use App\Services\LeadIntake\LeadIntakeQuarantineEvaluator;
use App\Services\LeadIntake\LeadIntakeQuarantineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntakeQuarantineController extends Controller
{
    public function __construct(
        private LeadIntakeQuarantineService $quarantine,
        private LeadIntakeQuarantineEvaluator $evaluator,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if ($request->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = IntakeQuarantine::with([
            'companySource:id,company_name',
            'reviewer:id,name',
            'convertedLead:id,contact_name,status',
        ])->latest('id');

        $status = $request->get('status', 'pending');
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', "%{$search}%")
                    ->orWhere('from_header', 'like', "%{$search}%")
                    ->orWhere('quarantine_reason', 'like', "%{$search}%")
                    ->orWhere('raw_email', 'like', "%{$search}%");
            });
        }

        return response()->json($query->paginate(20));
    }

    public function show(Request $request, IntakeQuarantine $intakeQuarantine): JsonResponse
    {
        if ($request->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $intakeQuarantine->load([
            'companySource:id,company_name,default_pm_id',
            'reviewer:id,name',
            'convertedLead:id,contact_name,phone,email,status,assigned_pm_id',
            'auditLogs' => fn ($q) => $q->latest('id')->limit(20),
        ]);

        return response()->json($intakeQuarantine);
    }

    public function approve(Request $request, IntakeQuarantine $intakeQuarantine): JsonResponse
    {
        if ($request->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'contact_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:40',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:500',
            'project_description' => 'nullable|string',
            'service_requested' => 'nullable|string|max:255',
            'send_notifications' => 'sometimes|boolean',
        ]);

        $overrides = array_filter([
            'contact_name' => $data['contact_name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
            'project_description' => $data['project_description'] ?? null,
            'service_requested' => $data['service_requested'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        if (isset($overrides['phone']) && ! $this->evaluator->isValidPhone($overrides['phone'])) {
            return response()->json([
                'message' => 'Phone must be a valid phone number — email addresses are not allowed in the phone field.',
            ], 422);
        }

        try {
            $result = $this->quarantine->approve(
                $intakeQuarantine,
                $request->user(),
                $overrides,
                (bool) ($data['send_notifications'] ?? true),
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Quarantine item approved and lead created.',
            'result' => $result->toArray(),
            'lead' => $result->lead?->load(['companySource', 'assignedPm']),
            'quarantine' => $result->quarantine,
        ]);
    }

    public function ignore(Request $request, IntakeQuarantine $intakeQuarantine): JsonResponse
    {
        if ($request->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        try {
            $item = $this->quarantine->ignore($intakeQuarantine, $request->user(), $data['reason']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Quarantine item permanently ignored.',
            'quarantine' => $item,
        ]);
    }

    public function pendingCount(Request $request): JsonResponse
    {
        if ($request->user()->role !== 'owner') {
            return response()->json(['count' => 0]);
        }

        return response()->json([
            'count' => IntakeQuarantine::query()->where('status', 'pending')->count(),
        ]);
    }
}
