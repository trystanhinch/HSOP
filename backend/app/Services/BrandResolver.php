<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Lead;
use App\Models\Quote;

/**
 * Brand Identity Hierarchy (A-06 / A-22)
 *
 * PLATFORM  : "ServiceOP" — the SaaS platform that powers the operation.
 *             Used only in: internal admin UI, platform-level error messages,
 *             contractor/PM account creation messages, Stripe Connect flows.
 *             NEVER on customer-facing documents or SMS/email to customers.
 *
 * LEGAL ENTITY: e.g. "HSOP Drywall & Paint" — the legal registered business.
 *               Used for: GST numbers, legal footers, contractor payroll.
 *
 * OPERATING BRAND: e.g. "Acutera Drywall and Paint" — what customers see.
 *                  Source of truth: brands.company_name (Brand Content system).
 *                  Used for: quotes, invoices, SMS/email to customers, portals,
 *                  Stripe payment descriptor, review requests, email headers.
 *
 * FALLBACK: If no brand can be resolved, use APP_BRAND_NAME env or "ServiceOP"
 *           (acceptable for internal/test contexts only).
 */
class BrandResolver
{
    /** Platform-level name — internal use only, never on customer surfaces. */
    public const PLATFORM_NAME = 'ServiceOP';

    /**
     * Resolve the operating brand name for a Job (customer-facing contexts).
     * Looks up via: Job → Lead → Brand record.
     */
    public function forJob(Job $job): string
    {
        // Load lead if not already loaded; if lead is loaded but brand is not, load brand separately.
        $job->loadMissing('lead');
        if ($job->lead && ! $job->lead->relationLoaded('brand')) {
            $job->lead->load('brand');
        }
        $brand = $job->lead?->brand;

        if ($brand) {
            return (string) $brand->company_name;
        }

        // Secondary: check if job has a brand_id directly (future proofing)
        if (isset($job->brand_id)) {
            $brand = Brand::find($job->brand_id);
            if ($brand) {
                return (string) $brand->company_name;
            }
        }

        return $this->fallback();
    }

    /**
     * Resolve the operating brand name for a Quote.
     */
    public function forQuote(Quote $quote): string
    {
        // Prefer snapshotted name on the quote (set at creation time).
        if (! empty($quote->brand_name_snapshot)) {
            return (string) $quote->brand_name_snapshot;
        }

        if ($quote->job_id) {
            $quote->loadMissing('job');
            if ($quote->job) {
                return $this->forJob($quote->job);
            }
        }

        if ($quote->lead_id) {
            $quote->loadMissing('lead.brand');
            $brand = $quote->lead?->brand;
            if ($brand) {
                return (string) $brand->company_name;
            }
        }

        return $this->fallback();
    }

    /**
     * Resolve the operating brand name for an Invoice.
     */
    public function forInvoice(Invoice $invoice): string
    {
        // Prefer snapshotted name on the invoice.
        if (! empty($invoice->brand_name_snapshot)) {
            return (string) $invoice->brand_name_snapshot;
        }

        if ($invoice->job_id) {
            $invoice->loadMissing('job');
            if ($invoice->job) {
                return $this->forJob($invoice->job);
            }
        }

        return $this->fallback();
    }

    /**
     * Resolve brand for a Lead (customer portal, site visit, review request).
     */
    public function forLead(Lead $lead): string
    {
        $lead->loadMissing('brand');
        if ($lead->brand) {
            return (string) $lead->brand->company_name;
        }

        return $this->fallback();
    }

    /**
     * Resolve brand by brand_id (for preview, settings, content editor contexts).
     */
    public function forBrandId(int $brandId): string
    {
        $brand = Brand::find($brandId);

        return $brand ? (string) $brand->company_name : $this->fallback();
    }

    /**
     * Return all active brands with their resolved identity fields for preview.
     *
     * @return list<array{
     *   id: int,
     *   slug: string,
     *   domain: string,
     *   operating_name: string,
     *   invoice_name: string,
     *   sender_name: string,
     *   portal_brand: string,
     *   payment_descriptor: string,
     *   review_destination: string,
     * }>
     */
    public function previewAll(): array
    {
        return Brand::where('status', 'active')
            ->orderBy('company_name')
            ->get()
            ->map(fn (Brand $b) => $this->previewFor($b))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function previewFor(Brand $brand): array
    {
        $name = (string) $brand->company_name;
        $domain = (string) $brand->domain;

        return [
            'id' => $brand->id,
            'slug' => $brand->slug,
            'domain' => $domain,
            // What the customer reads on their quote/invoice header
            'operating_name' => $name,
            'invoice_name' => $name,
            // What appears in SMS "from" prefix and email "from" label
            'sender_name' => $name,
            // What the customer sees on the portal header
            'portal_brand' => $name,
            // What appears on the Stripe checkout payment line (max 22 chars for statement descriptor)
            'payment_descriptor' => mb_substr($name, 0, 22),
            // Where review requests reference the brand
            'review_destination' => $name.' — '.$domain,
            // Platform attribution note (internal only)
            'platform_note' => 'Powered by '.self::PLATFORM_NAME.' (internal only — not shown to customers)',
        ];
    }

    /**
     * Fallback brand name when no Brand record is resolvable.
     * Uses APP_BRAND_NAME env variable if set, otherwise the platform name.
     * This should only occur in internal/test contexts.
     */
    public function fallback(): string
    {
        return (string) config('app.brand_name', self::PLATFORM_NAME);
    }
}
