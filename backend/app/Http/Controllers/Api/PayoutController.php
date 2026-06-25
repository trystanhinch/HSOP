<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payout;
use App\Services\JobNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayoutController extends Controller
{
    public function __construct(protected JobNotificationService $notifications) {}

    public function index(Request $request): JsonResponse
    {
        $query = Payout::with(['job:id,address,service_category,job_title', 'contractor:id,name']);

        if (auth()->user()->role === 'contractor') {
            $query->where('contractor_id', auth()->id());
        }

        if ($request->status) {
            $query->where('status', $request->status);
        }

        return response()->json($query->latest()->paginate(20));
    }

    public function show(Payout $payout): JsonResponse
    {
        $user = auth()->user();
        if ($user->role === 'contractor' && $payout->contractor_id !== $user->id) {
            abort(403);
        }

        return response()->json($payout->load(['job', 'contractor:id,name,email']));
    }

    public function update(Request $request, Payout $payout): JsonResponse
    {
        if (auth()->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'payout_method' => 'nullable|string|max:50',
            'payout_due_date' => 'nullable|date',
            'admin_notes' => 'nullable|string',
        ]);

        $payout->update($data);

        return response()->json(['message' => 'Payout updated', 'payout' => $payout->fresh()]);
    }

    public function markPaid(Request $request, Payout $payout): JsonResponse
    {
        if ($payout->status === 'paid') {
            return response()->json(['message' => 'Payout already marked as paid'], 422);
        }

        $payout->update([
            'status' => 'paid',
            'paid_date' => now(),
            'authorized_by' => auth()->id(),
        ]);

        $this->notifications->audit('payout_status_changed', 'payout', $payout->id, null, null, ['status' => 'paid']);

        return response()->json(['message' => 'Payout marked as paid', 'payout' => $payout->fresh()]);
    }

    public function approve(Payout $payout): JsonResponse
    {
        if (! in_array($payout->status, ['pending', 'ready_for_payout'])) {
            return response()->json(['message' => 'Only pending or ready payouts can be approved'], 422);
        }

        $payout->update(['status' => 'approved', 'authorized_by' => auth()->id()]);
        $this->notifications->audit('payout_status_changed', 'payout', $payout->id, null, null, ['status' => 'approved']);

        return response()->json(['message' => 'Payout approved', 'payout' => $payout->fresh()]);
    }
}
