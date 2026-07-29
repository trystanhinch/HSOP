<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Contractors\ContractorAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContractorLeadController extends Controller
{
    public function __construct(
        protected ContractorAssignmentService $assignments,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'contractor') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $leads = $this->assignments->serializeOpenLeadAssignments($user);

        return response()->json(['data' => $leads]);
    }
}
