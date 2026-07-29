<?php

namespace App\Services\Accounting;

use App\Models\Invoice;
use App\Models\Job;
use App\Models\Quote;
use App\Models\Setting;
use App\Services\BrandResolver;
use App\Services\PricingService;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    public function __construct(private PricingService $pricing) {}

    public function nextInvoiceNumber(?\App\Models\Company $company = null): string
    {
        return DB::transaction(function () use ($company) {
            $prefix = $company?->invoice_prefix
                ? rtrim((string) $company->invoice_prefix, '-').'-{XXXX}'
                : null;
            $format = $prefix
                ?? Setting::get('invoice_number_format', config('payment.invoice.number_format', 'INV-{XXXX}'));
            $next = (int) Setting::get('invoice_number_next', '1');
            $pad = (int) config('payment.invoice.number_pad', 4);

            $number = str_replace(
                '{XXXX}',
                str_pad((string) $next, $pad, '0', STR_PAD_LEFT),
                $format
            );

            // Avoid collisions if format/history diverged
            while (Invoice::where('invoice_number', $number)->exists()) {
                $next++;
                $number = str_replace(
                    '{XXXX}',
                    str_pad((string) $next, $pad, '0', STR_PAD_LEFT),
                    $format
                );
            }

            Setting::set('invoice_number_next', (string) ($next + 1));

            return $number;
        });
    }

    public function createFromJob(Job $job, array $overrides = []): Invoice
    {
        $job->loadMissing(['quote', 'lead.companySource', 'company', 'customer']);

        if ($job->invoice) {
            return $job->invoice;
        }

        $quote = $job->quote;
        $gstRate = $this->pricing->gstRate();

        if ($quote) {
            $subtotal = (float) ($quote->customer_price_before_gst ?? $quote->subtotal ?? 0);
            $gst = (float) ($quote->gst ?? 0);
            $total = (float) ($quote->customer_total ?? ($subtotal + $gst));
            $gstRate = (float) ($quote->gst_rate ?? $gstRate);
            $scope = $quote->scope_of_work;
        } else {
            $contractor = (float) ($job->contractor_submitted_price ?? 0);
            $calc = $this->pricing->fromContractorPrice($contractor, true, $job);
            $subtotal = $calc['customer_subtotal'];
            $gst = $calc['gst'];
            $total = $calc['customer_total'];
            $gstRate = $calc['gst_rate'];
            $scope = $job->scope_of_work;
        }

        $source = $job->lead?->companySource;
        $company = $job->company;
        $sourceName = $source?->company_name
            ?? $company?->operating_name
            ?? $company?->name
            ?? null;

        // A-06/A-22: snapshot brand name at creation time; never overwritten by later brand edits.
        $brandNameSnapshot = app(BrandResolver::class)->forJob($job);

        $invoice = Invoice::create(array_merge([
            'job_id' => $job->id,
            'quote_id' => $quote?->id,
            'company_id' => $job->company_id,
            'customer_id' => $job->customer_id,
            'company_source_id' => $source?->id,
            'source_company' => $sourceName,
            'invoice_number' => $this->nextInvoiceNumber($company),
            'scope_of_work' => $scope,
            'subtotal' => $subtotal,
            'gst' => $gst,
            'gst_rate' => $gstRate,
            'amount' => $total,
            'balance' => $total,
            'amount_paid' => 0,
            'status' => 'awaiting_payment',
            'due_date' => now()->addDays(30)->toDateString(),
            'brand_name_snapshot' => $brandNameSnapshot,
        ], $overrides));

        app(\App\Services\Finance\FinancialLedgerService::class)->recordEntry([
            'entry_type' => \App\Models\FinancialLedgerEntry::TYPE_INVOICE_ISSUED,
            'direction' => 'credit',
            'amount' => $subtotal,
            'gst_amount' => $gst,
            'job_id' => $job->id,
            'invoice_id' => $invoice->id,
            'quote_id' => $quote?->id,
            'actor_user_id' => auth()->id(),
            'reference' => $invoice->invoice_number,
            'is_test_data' => (bool) ($invoice->is_test_data ?? false),
        ]);

        return $invoice;
    }

    public function createFromQuote(Quote $quote): Invoice
    {
        $quote->loadMissing('job');
        if (! $quote->job) {
            throw new \InvalidArgumentException('Quote has no linked job.');
        }

        return $this->createFromJob($quote->job, [
            'quote_id' => $quote->id,
            'scope_of_work' => $quote->scope_of_work,
            'subtotal' => $quote->customer_price_before_gst ?? $quote->subtotal,
            'gst' => $quote->gst,
            'gst_rate' => $quote->gst_rate ?? $this->pricing->gstRate(),
            'amount' => $quote->customer_total,
            'balance' => $quote->customer_total,
        ]);
    }
}
