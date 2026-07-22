<?php

namespace App\Http\Controllers\Api;

use App\Contracts\PaymentProviderInterface;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payout;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountingController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        if ($request->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $invoiceQuery = Invoice::with('job:id,service_category');
        $this->applyInvoiceFilters($invoiceQuery, $request);
        $all = $invoiceQuery->get();

        $paid = $all->where('status', 'paid');
        $unpaidStatuses = ['draft', 'sent', 'invoice_sent', 'awaiting_payment', 'payment_pending', 'partially_paid', 'overdue', 'unpaid', 'partial', 'payment_failed'];
        $unpaid = $all->whereIn('status', $unpaidStatuses);
        $overdue = $all->filter(fn (Invoice $i) => $i->is_overdue);

        $grossRevenue = round((float) $paid->sum('subtotal'), 2);
        $gstCollected = round((float) $paid->sum('gst'), 2);

        $payoutQuery = Payout::query();
        $this->applyPayoutFilters($payoutQuery, $request);
        $payoutRows = $payoutQuery->get();

        $splitKey = fn (Payout $p) => $p->split_type ?: $p->payout_type;
        $owed = $payoutRows->whereNotIn('status', ['paid', 'failed', 'not_eligible']);
        $paidPayouts = $payoutRows->where('status', 'paid');

        $contractorOwed = round((float) $owed->filter(fn ($p) => $splitKey($p) === 'contractor')->sum('payout_amount'), 2);
        $pmOwed = round((float) $owed->filter(fn ($p) => $splitKey($p) === 'pm')->sum('payout_amount'), 2);
        $contractorPaid = round((float) $paidPayouts->filter(fn ($p) => $splitKey($p) === 'contractor')->sum('payout_amount'), 2);
        $pmPaid = round((float) $paidPayouts->filter(fn ($p) => $splitKey($p) === 'pm')->sum('payout_amount'), 2);
        $companyPaid = round((float) $paidPayouts->filter(fn ($p) => $splitKey($p) === 'company')->sum('payout_amount'), 2);

        // Revenue (ex-GST) minus contractor+PM payouts (owed + paid)
        $companyProfit = round($grossRevenue - ($contractorPaid + $contractorOwed) - ($pmPaid + $pmOwed), 2);

        $byCategory = $paid->groupBy(fn (Invoice $i) => $i->job?->service_category ?: 'unknown')
            ->map(fn ($group, $key) => [
                'service_category' => $key,
                'subtotal' => round((float) $group->sum('subtotal'), 2),
                'count' => $group->count(),
            ])->values();

        $bySource = $paid->groupBy(fn (Invoice $i) => $i->source_company ?: 'Unspecified')
            ->map(fn ($group, $key) => [
                'source_company' => $key,
                'subtotal' => round((float) $group->sum('subtotal'), 2),
                'count' => $group->count(),
            ])->values();

        return response()->json([
            'invoices' => [
                'total' => $all->count(),
                'paid' => $paid->count(),
                'unpaid' => $unpaid->count(),
                'overdue' => $overdue->count(),
            ],
            'gst_collected' => $gstCollected,
            'gross_revenue' => $grossRevenue,
            'payouts' => [
                'contractor_owed' => $contractorOwed,
                'contractor_paid' => $contractorPaid,
                'pm_owed' => $pmOwed,
                'pm_paid' => $pmPaid,
                'company_paid' => $companyPaid,
            ],
            'company_profit' => $companyProfit,
            'revenue_by_service_category' => $byCategory,
            'revenue_by_source_company' => $bySource,
            'payment_provider' => config('payment.provider'),
        ]);
    }

    public function export(Request $request): StreamedResponse|JsonResponse
    {
        if ($request->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $type = $request->get('type', 'invoices');
        if (! in_array($type, ['invoices', 'payments', 'payouts'], true)) {
            return response()->json(['message' => 'Invalid export type'], 422);
        }

        $filename = "{$type}_export_".now()->format('Ymd_His').'.csv';

        return Response::streamDownload(function () use ($type, $request) {
            $out = fopen('php://output', 'w');
            if ($type === 'invoices') {
                fputcsv($out, ['invoice_number', 'status', 'customer_id', 'job_id', 'source_company', 'subtotal', 'gst', 'total', 'amount_paid', 'balance', 'payment_date', 'created_at']);
                $q = Invoice::query();
                $this->applyInvoiceFilters($q, $request);
                $q->orderBy('id')->chunk(200, function ($rows) use ($out) {
                    foreach ($rows as $inv) {
                        fputcsv($out, [
                            $inv->invoice_number, $inv->status, $inv->customer_id, $inv->job_id,
                            $inv->source_company, $inv->subtotal, $inv->gst, $inv->amount,
                            $inv->amount_paid, $inv->balance, $inv->payment_date, $inv->created_at,
                        ]);
                    }
                });
            } elseif ($type === 'payments') {
                fputcsv($out, ['id', 'invoice_id', 'amount', 'method', 'paid_date', 'reference_number', 'status', 'created_at']);
                $q = Payment::query()->whereHas('invoice', function ($iq) use ($request) {
                    $this->applyInvoiceFilters($iq, $request);
                });
                $q->orderBy('id')->chunk(200, function ($rows) use ($out) {
                    foreach ($rows as $p) {
                        fputcsv($out, [$p->id, $p->invoice_id, $p->amount, $p->method, $p->paid_date, $p->reference_number, $p->status, $p->created_at]);
                    }
                });
            } else {
                fputcsv($out, ['id', 'job_id', 'split_type', 'payout_type', 'amount', 'status', 'eligible_at', 'scheduled_for', 'stripe_transfer_id', 'paid_date']);
                $q = Payout::query();
                $this->applyPayoutFilters($q, $request);
                $q->orderBy('id')->chunk(200, function ($rows) use ($out) {
                    foreach ($rows as $p) {
                        fputcsv($out, [
                            $p->id, $p->job_id, $p->split_type, $p->payout_type, $p->payout_amount,
                            $p->status, $p->eligible_at, $p->scheduled_for, $p->stripe_transfer_id, $p->paid_date,
                        ]);
                    }
                });
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function mockPay(Request $request, Invoice $invoice, PaymentProviderInterface $payments): JsonResponse
    {
        if ($request->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (config('payment.provider') !== 'mock') {
            return response()->json(['message' => 'Mock payments only available when PAYMENT_PROVIDER=mock'], 422);
        }

        $result = $payments->handleWebhook([
            'event' => 'payment_succeeded',
            'invoice_id' => $invoice->id,
            'amount' => $invoice->balance,
        ]);

        return response()->json([
            'message' => 'Mock payment applied',
            'result' => $result,
            'invoice' => $invoice->fresh(['job']),
        ]);
    }

    public function mockTransfer(Request $request, Payout $payout, PaymentProviderInterface $payments): JsonResponse
    {
        if ($request->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (config('payment.provider') !== 'mock') {
            return response()->json(['message' => 'Mock transfers only available when PAYMENT_PROVIDER=mock'], 422);
        }

        if (! in_array($payout->status, ['eligible', 'scheduled', 'pending', 'ready_for_payout', 'approved'], true)) {
            return response()->json(['message' => 'Payout is not ready for transfer'], 422);
        }

        $result = $payments->createTransfer($payout);

        return response()->json([
            'message' => 'Mock transfer completed',
            'result' => $result,
            'payout' => $payout->fresh(),
        ]);
    }

    public function executeTransfer(Request $request, Payout $payout, PaymentProviderInterface $payments): JsonResponse
    {
        if ($request->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (! in_array($payout->status, ['eligible', 'scheduled', 'queued', 'pending', 'ready_for_payout', 'approved'], true)) {
            return response()->json(['message' => 'Payout is not ready for transfer'], 422);
        }

        try {
            $result = $payments->createTransfer($payout->fresh());
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $status = $result['status'] ?? '';
        $http = $status === 'queued' ? 200 : 200;

        return response()->json([
            'message' => $status === 'queued'
                ? 'Transfer deferred — payout queued until Stripe Connect/balance is ready'
                : 'Transfer executed',
            'result' => $result,
            'payout' => $payout->fresh(),
        ], $http);
    }

    private function applyInvoiceFilters($query, Request $request): void
    {
        if ($request->from) {
            $query->whereDate('created_at', '>=', $request->from);
        }
        if ($request->to) {
            $query->whereDate('created_at', '<=', $request->to);
        }
        if ($request->source_company) {
            $query->where('source_company', $request->source_company);
        }
        if ($request->service_category) {
            $query->whereHas('job', fn ($j) => $j->where('service_category', $request->service_category));
        }
        if ($request->status) {
            $query->where('status', $request->status);
        }
    }

    private function applyPayoutFilters($query, Request $request): void
    {
        if ($request->from) {
            $query->whereDate('created_at', '>=', $request->from);
        }
        if ($request->to) {
            $query->whereDate('created_at', '<=', $request->to);
        }
        if ($request->status) {
            $query->where('status', $request->status);
        }
        if ($request->service_category) {
            $query->whereHas('job', fn ($j) => $j->where('service_category', $request->service_category));
        }
    }
}
