<?php

namespace App\Http\Controllers\Api;

use App\Contracts\PaymentProviderInterface;
use App\Http\Controllers\Controller;
use App\Models\FinancialLedgerEntry;
use App\Models\Payout;
use App\Services\Finance\FinancialLedgerService;
use App\Services\JobNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayoutController extends Controller
{
    public function __construct(
        protected JobNotificationService $notifications,
        protected FinancialLedgerService $ledger,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();

        if ($user->role === 'owner' && $request->boolean('group_by_job')) {
            return response()->json([
                'refreshed_at' => now()->toIso8601String(),
                'grouped' => true,
                'groups' => $this->ledger->payoutGroups([
                    'status' => $request->query('status'),
                ]),
            ]);
        }

        if ($user->role === 'owner') {
            $query = Payout::with([
                'job:id,address,job_title,customer_id,service_category,completed_at,customer_accepted_completion_at',
                'job.customer:id,name',
                'job.invoice',
                'contractor:id,name,stripe_account_id,stripe_payout_ready,stripe_onboarding_status',
                'pm:id,name',
            ]);
            if ($request->status) {
                $query->where('status', $request->status);
            }

            $page = $query->latest()->paginate(20);
            $page->getCollection()->transform(function (Payout $p) {
                $p->setAttribute('recipient_label', $this->ledger->recipientLabel($p));
                $p->setAttribute('not_ready_reasons', $this->ledger->notReadyReasons($p));

                return $p;
            });

            return response()->json($page);
        }

        if ($user->role === 'pm') {
            $query = Payout::with([
                'job:id,address,job_title,customer_id,completed_at',
                'job.customer:id,name',
            ])
                ->where(function ($q) use ($user) {
                    $q->where('pm_id', $user->id)
                        ->orWhere(function ($q2) use ($user) {
                            $q2->where('contractor_id', $user->id)->where('payout_type', 'pm');
                        });
                });
            if ($request->status) {
                $query->where('status', $request->status);
            }

            return response()->json($query->latest()->paginate(20));
        }

        if ($user->role === 'contractor') {
            $query = Payout::with(['job:id,address,job_title'])
                ->where('contractor_id', $user->id)
                ->where('payout_type', 'contractor');
            if ($request->status) {
                $query->where('status', $request->status);
            }

            return response()->json($query->latest()->paginate(20));
        }

        return response()->json(['data' => []]);
    }

    public function show(Payout $payout): JsonResponse
    {
        $user = auth()->user();
        if ($user->role === 'contractor' && $payout->contractor_id !== $user->id) {
            abort(403);
        }
        if ($user->role === 'pm' && $payout->pm_id !== $user->id && ($payout->contractor_id !== $user->id || $payout->payout_type !== 'pm')) {
            abort(403);
        }

        $payout->load(['job.invoice', 'contractor:id,name,email,stripe_account_id,stripe_payout_ready', 'pm:id,name']);
        $payload = $payout->toArray();
        $payload['recipient_label'] = $this->ledger->recipientLabel($payout);
        $payload['not_ready_reasons'] = $this->ledger->notReadyReasons($payout);
        if ($payout->job_id) {
            $payload['reconciliation'] = $this->ledger->payoutReconciliationForJob((int) $payout->job_id);
        }

        return response()->json($payload);
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
            'status' => 'sometimes|in:not_eligible,waiting_for_payment,waiting_for_completion_acceptance,waiting_for_revision_closure,eligible,scheduled,queued,pending,in_transit,paid,failed,on_hold,not_ready,ready_for_payout,approved,hold_issue',
            'eligibility_status' => 'nullable|string|max:255',
        ]);

        $before = $payout->status;
        if (array_key_exists('status', $data) && $data['status'] !== 'paid') {
            $data['paid_date'] = null;
            if (($payout->stripe_transfer_id ?? '') !== '' && str_starts_with((string) $payout->stripe_transfer_id, 'platform_retain_')) {
                $data['stripe_transfer_id'] = null;
            }
        }

        $payout->update($data);
        if (isset($data['status']) && $data['status'] !== $before) {
            $this->ledger->recordPayoutEvent($payout->fresh(), 'status_changed', auth()->id(), 'Manual status update');
        }

        return response()->json(['message' => 'Payout updated', 'payout' => $payout->fresh()]);
    }

    public function markPaid(Request $request, Payout $payout): JsonResponse
    {
        if (auth()->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($payout->status === 'paid') {
            return response()->json(['message' => 'Payout already marked as paid'], 422);
        }

        $from = $payout->status;
        $payout->update([
            'status' => 'paid',
            'paid_date' => now(),
            'authorized_by' => auth()->id(),
        ]);

        $this->ledger->recordPayoutEvent($payout->fresh(), 'paid', auth()->id());
        $this->ledger->recordEntry([
            'entry_type' => FinancialLedgerEntry::TYPE_PAYOUT_PAID,
            'direction' => 'debit',
            'amount' => $payout->payout_amount,
            'job_id' => $payout->job_id,
            'payout_id' => $payout->id,
            'actor_user_id' => auth()->id(),
            'reference' => $payout->stripe_transfer_id,
            'is_test_data' => (bool) ($payout->is_test_data ?? false),
        ]);
        $this->notifications->audit('payout_status_changed', 'payout', $payout->id, null, null, [
            'from' => $from, 'status' => 'paid',
        ]);

        return response()->json(['message' => 'Payout marked as paid', 'payout' => $payout->fresh()]);
    }

    public function approve(Payout $payout): JsonResponse
    {
        if (auth()->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $allowed = ['pending', 'ready_for_payout', 'eligible', 'scheduled', 'queued'];
        if (! in_array($payout->status, $allowed, true)) {
            return response()->json([
                'message' => 'Payout cannot be approved in status '.$payout->status,
                'not_ready_reasons' => $this->ledger->notReadyReasons($payout),
            ], 422);
        }

        $from = $payout->status;
        $payout->update(['status' => 'approved', 'authorized_by' => auth()->id()]);
        $this->ledger->recordPayoutEvent($payout->fresh(), 'approved', auth()->id());
        $this->ledger->recordEntry([
            'entry_type' => FinancialLedgerEntry::TYPE_PAYOUT_APPROVED,
            'direction' => 'debit',
            'amount' => $payout->payout_amount,
            'job_id' => $payout->job_id,
            'payout_id' => $payout->id,
            'actor_user_id' => auth()->id(),
            'is_test_data' => (bool) ($payout->is_test_data ?? false),
        ]);
        $this->notifications->audit('payout_status_changed', 'payout', $payout->id, null, null, [
            'from' => $from, 'status' => 'approved',
        ]);

        return response()->json(['message' => 'Payout approved', 'payout' => $payout->fresh()]);
    }

    public function hold(Request $request, Payout $payout): JsonResponse
    {
        if (auth()->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        if ($payout->status === 'paid') {
            return response()->json(['message' => 'Cannot hold a paid payout'], 422);
        }

        $notes = $request->input('notes');
        $from = $payout->status;
        $payout->update([
            'status' => 'on_hold',
            'authorized_by' => auth()->id(),
            'admin_notes' => $notes ?: $payout->admin_notes,
        ]);
        $this->ledger->recordPayoutEvent($payout->fresh(), 'held', auth()->id(), $notes);
        $this->ledger->recordEntry([
            'entry_type' => FinancialLedgerEntry::TYPE_PAYOUT_HELD,
            'direction' => 'debit',
            'amount' => $payout->payout_amount,
            'job_id' => $payout->job_id,
            'payout_id' => $payout->id,
            'actor_user_id' => auth()->id(),
            'meta' => ['from' => $from, 'notes' => $notes],
            'is_test_data' => (bool) ($payout->is_test_data ?? false),
        ]);
        $this->notifications->audit('payout_held', 'payout', $payout->id, null, null, ['from' => $from]);

        return response()->json(['message' => 'Payout held', 'payout' => $payout->fresh()]);
    }

    public function release(Request $request, Payout $payout): JsonResponse
    {
        if (auth()->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        if (! in_array($payout->status, ['on_hold', 'hold_issue'], true)) {
            return response()->json(['message' => 'Payout is not on hold'], 422);
        }

        $from = $payout->status;
        $payout->update([
            'status' => 'ready_for_payout',
            'authorized_by' => auth()->id(),
        ]);
        $this->ledger->recordPayoutEvent($payout->fresh(), 'released', auth()->id(), $request->input('notes'));
        $this->notifications->audit('payout_released', 'payout', $payout->id, null, null, ['from' => $from]);

        return response()->json(['message' => 'Payout released', 'payout' => $payout->fresh()]);
    }

    public function retry(Request $request, Payout $payout, PaymentProviderInterface $payments): JsonResponse
    {
        if (auth()->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        if (! in_array($payout->status, ['failed', 'queued', 'approved', 'ready_for_payout', 'scheduled', 'eligible'], true)) {
            return response()->json([
                'message' => 'Payout cannot be retried in status '.$payout->status,
                'not_ready_reasons' => $this->ledger->notReadyReasons($payout),
            ], 422);
        }

        $from = $payout->status;
        $payout->update(['status' => 'queued', 'authorized_by' => auth()->id()]);
        $this->ledger->recordPayoutEvent($payout->fresh(), 'retried', auth()->id());

        try {
            $result = $payments->createTransfer($payout->fresh());
        } catch (\Throwable $e) {
            $payout->update(['status' => 'failed']);
            $this->ledger->recordPayoutEvent($payout->fresh(), 'failed', auth()->id(), $e->getMessage());
            $this->ledger->recordEntry([
                'entry_type' => FinancialLedgerEntry::TYPE_PAYOUT_FAILED,
                'direction' => 'debit',
                'amount' => $payout->payout_amount,
                'job_id' => $payout->job_id,
                'payout_id' => $payout->id,
                'actor_user_id' => auth()->id(),
                'meta' => ['error' => $e->getMessage()],
                'is_test_data' => (bool) ($payout->is_test_data ?? false),
            ]);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->notifications->audit('payout_retried', 'payout', $payout->id, null, null, [
            'from' => $from, 'result' => $result,
        ]);

        return response()->json([
            'message' => 'Retry executed',
            'result' => $result,
            'payout' => $payout->fresh(),
        ]);
    }
}
