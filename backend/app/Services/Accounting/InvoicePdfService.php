<?php

namespace App\Services\Accounting;

use App\Models\Company;
use App\Models\Invoice;
use App\Services\BrandResolver;
use Dompdf\Dompdf;
use Dompdf\Options;

class InvoicePdfService
{
    public function renderHtml(Invoice $invoice): string
    {
        $invoice->loadMissing(['customer', 'job.company', 'quote']);
        $customer = $invoice->customer;
        $job = $invoice->job;
        $company = $job?->company ?? Company::withTestData()->orderBy('id')->first();

        $operating = e($invoice->brand_name_snapshot
            ?: app(BrandResolver::class)->forInvoice($invoice));
        $legal = e($company?->legal_name ?: $company?->name ?: '');
        $gstNumber = e($company?->gst_number ?: '—');
        $remit = e($company?->remittance_address ?: $company?->address ?: '—');
        $publicEmail = e($company?->public_contact_email ?: $company?->email ?: '');
        $publicPhone = e($company?->public_contact_phone ?: $company?->phone ?: '');

        $subtotal = number_format((float) $invoice->subtotal, 2);
        $gst = number_format((float) ($invoice->gst ?? 0), 2);
        $total = number_format((float) $invoice->amount, 2);
        $paid = number_format((float) ($invoice->amount_paid ?? 0), 2);
        $balance = number_format((float) $invoice->balance, 2);
        $rate = number_format((float) ($invoice->gst_rate ?? 5), 2);
        $scope = e($invoice->scope_of_work ?: ($job?->scope_of_work ?: '—'));
        $address = e($job?->address ?: '—');
        $source = e($invoice->source_company ?: '—');
        $number = e($invoice->invoice_number ?: ('#'.$invoice->id));
        $status = e($invoice->status);
        $name = e($customer?->name ?: 'Customer');
        $email = e($customer?->email ?: '');

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Invoice {$number}</title>
  <style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #0f172a; }
    h1 { font-size: 22px; margin: 0 0 4px; }
    .muted { color: #64748b; }
    table { width: 100%; border-collapse: collapse; margin-top: 20px; }
    th, td { padding: 8px; border-bottom: 1px solid #e2e8f0; text-align: left; }
    .right { text-align: right; }
    .totals td { border: none; }
    .totals .label { text-align: right; color: #64748b; }
    .legal { margin-top: 28px; padding-top: 12px; border-top: 1px solid #e2e8f0; font-size: 10px; color: #64748b; }
  </style>
</head>
<body>
  <h1>{$operating}</h1>
  <p class="muted">Invoice {$number} · Status: {$status}</p>
  <p><strong>Bill to:</strong> {$name}<br>{$email}</p>
  <p><strong>Job address:</strong> {$address}<br><strong>Source:</strong> {$source}</p>
  <p><strong>Scope</strong><br>{$scope}</p>
  <table>
    <thead><tr><th>Description</th><th class="right">Amount</th></tr></thead>
    <tbody>
      <tr><td>Services (subtotal)</td><td class="right">\${$subtotal}</td></tr>
      <tr><td>GST ({$rate}%)</td><td class="right">\${$gst}</td></tr>
    </tbody>
  </table>
  <table class="totals">
    <tr><td class="label">Total</td><td class="right"><strong>\${$total}</strong></td></tr>
    <tr><td class="label">Amount paid</td><td class="right">\${$paid}</td></tr>
    <tr><td class="label">Balance due</td><td class="right"><strong>\${$balance}</strong></td></tr>
  </table>
  <div class="legal">
    <p><strong>Legal entity:</strong> {$legal} · GST/HST: {$gstNumber}</p>
    <p><strong>Remittance:</strong> {$remit}</p>
    <p><strong>Public contact:</strong> {$publicEmail} {$publicPhone}</p>
  </div>
</body>
</html>
HTML;
    }

    public function pdfBinary(Invoice $invoice): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->renderHtml($invoice));
        $dompdf->setPaper('letter');
        $dompdf->render();

        return $dompdf->output();
    }
}
