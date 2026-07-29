<?php

namespace App\Http\Resources;

use App\Services\Workflow\QuoteLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $role = $user?->role ?? 'guest';
        $lifecycle = app(QuoteLifecycleService::class);
        $followUpOpen = $lifecycle->hasOpenFollowUp($this->resource);

        $data = [
            'id' => $this->id,
            'job_id' => $this->job_id,
            'customer_id' => $this->customer_id,
            'quote_number' => $this->quote_number,
            'revision_number' => (int) ($this->revision_number ?? 1),
            'parent_quote_id' => $this->parent_quote_id,
            'root_quote_id' => $this->root_quote_id,
            'is_immutable' => (bool) $this->is_immutable,
            'scope_of_work' => $this->scope_of_work,
            'customer_price_before_gst' => $this->customer_price_before_gst,
            'subtotal' => $this->subtotal ?? $this->customer_price_before_gst,
            'gst_enabled' => $this->gst_enabled,
            'gst_rate' => $this->gst_rate,
            'gst' => $this->gst,
            'customer_total' => $this->customer_total,
            'status' => $lifecycle->normalizeStatus($this->status),
            'status_raw' => $this->status,
            'follow_up_due' => $followUpOpen,
            'customer_notes' => $this->customer_notes,
            'sent_at' => $this->sent_at,
            'viewed_at' => $this->viewed_at,
            'accepted_at' => $this->accepted_at,
            'declined_at' => $this->declined_at,
            'expired_at' => $this->expired_at,
            'follow_up_due_at' => $this->follow_up_due_at,
            'follow_up_stopped_at' => $this->follow_up_stopped_at,
            'brand_name_snapshot' => $this->brand_name_snapshot,
            'created_at' => $this->created_at,
            'items' => $this->whenLoaded('items'),
            'job' => $this->whenLoaded('job'),
            'customer' => $this->whenLoaded('customer'),
        ];

        // Role-appropriate financial breakdown (PM-01): never expose cost/margin to customers.
        if (in_array($role, ['owner', 'pm', 'contractor'], true)) {
            $data['contractor_base_price'] = $this->contractor_base_price;
        }

        if (in_array($role, ['owner', 'pm'], true)) {
            $data['pm_amount'] = $this->pm_amount;
            $data['contractor_pct'] = $this->contractor_pct;
            $data['pm_pct'] = $this->pm_pct;
            $data['company_pct'] = $this->company_pct;
            $margin = null;
            if ($this->customer_price_before_gst !== null && $this->contractor_base_price !== null) {
                $margin = round((float) $this->customer_price_before_gst - (float) $this->contractor_base_price, 2);
            }
            $data['margin'] = $margin;
            $data['internal_notes'] = $this->internal_notes;
            $data['rejection_reason'] = $this->rejection_reason;
            $data['actions'] = $this->availableActions($role, $followUpOpen);
        }

        if ($role === 'owner') {
            $data['company_amount'] = $this->company_amount;
            $data['hsop_markup'] = $this->hsop_markup;
        }

        return $data;
    }

    /**
     * @return list<string>
     */
    private function availableActions(string $role, bool $followUpOpen): array
    {
        $status = app(QuoteLifecycleService::class)->normalizeStatus($this->status);
        $actions = [];

        if (in_array($status, ['draft', 'internal_review'], true)) {
            $actions[] = 'review';
            $actions[] = 'send';
            $actions[] = 'expire';
            $actions[] = 'decline';
        }
        if ($status === 'draft') {
            $actions[] = 'mark_internal_review';
        }
        if (in_array($status, ['sent', 'viewed'], true)) {
            $actions[] = 'resend';
            $actions[] = 'revise';
            $actions[] = 'expire';
            $actions[] = 'decline';
            if ($followUpOpen || $this->follow_up_due_at) {
                $actions[] = 'follow_up';
            } else {
                $actions[] = 'follow_up'; // staff can still open/flag follow-up
            }
        }
        if ($status === 'revision_requested') {
            $actions[] = 'revise'; // open latest draft if needed
        }

        return array_values(array_unique($actions));
    }
}
