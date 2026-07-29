<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Services\Contractors\ContractorAssignmentService;
use App\Services\Messaging\AssignmentMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Audit CT-02 — contractor↔PM messages on a lead / site-visit assignment.
 */
class LeadMessageController extends Controller
{
    public function __construct(
        protected AssignmentMessageService $messages,
        protected ContractorAssignmentService $assignments,
    ) {}

    public function index(Request $request, string $lead): JsonResponse
    {
        $user = $request->user();
        $leadModel = Lead::with(['assignedPm:id,name,email,phone', 'job:id,lead_id'])->findOrFail($lead);
        $this->assignments->assertContractorLeadAccess($user, $leadModel);

        $thread = $this->messages->threadForLead($leadModel);
        $this->messages->markLeadThreadRead($leadModel, $user);

        return response()->json([
            'lead_id' => $leadModel->id,
            'job_id' => $leadModel->job?->id,
            'pm' => $leadModel->assignedPm?->only(['id', 'name', 'email', 'phone']),
            'messages' => $thread,
        ]);
    }

    public function store(Request $request, string $lead): JsonResponse
    {
        $user = $request->user();
        $leadModel = Lead::with(['assignedPm', 'job'])->findOrFail($lead);
        $this->assignments->assertContractorLeadAccess($user, $leadModel);

        if (! in_array($user->role, ['contractor', 'pm', 'owner'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'content' => 'required|string|max:5000',
        ]);

        $message = $this->messages->postLeadMessage($leadModel, $user, $data['content']);

        return response()->json($message, 201);
    }
}
