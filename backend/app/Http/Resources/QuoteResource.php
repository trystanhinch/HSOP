<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $role = $user?->role ?? 'guest';

        $data = [
            'id' => $this->id,
            'job_id' => $this->job_id,
            'customer_id' => $this->customer_id,
            'quote_number' => $this->quote_number,
            'scope_of_work' => $this->scope_of_work,
            'customer_price_before_gst' => $this->customer_price_before_gst,
            'subtotal' => $this->subtotal ?? $this->customer_price_before_gst,
            'gst_enabled' => $this->gst_enabled,
            'gst_rate' => $this->gst_rate,
            'gst' => $this->gst,
            'customer_total' => $this->customer_total,
            'status' => $this->status,
            'customer_notes' => $this->customer_notes,
            'sent_at' => $this->sent_at,
            'viewed_at' => $this->viewed_at,
            'accepted_at' => $this->accepted_at,
            'items' => $this->whenLoaded('items'),
            'job' => $this->whenLoaded('job'),
            'customer' => $this->whenLoaded('customer'),
        ];

        if (in_array($role, ['owner', 'pm', 'contractor'], true)) {
            $data['contractor_base_price'] = $this->contractor_base_price;
        }

        if (in_array($role, ['owner', 'pm'], true)) {
            $data['pm_amount'] = $this->pm_amount;
            $data['contractor_pct'] = $this->contractor_pct;
            $data['pm_pct'] = $this->pm_pct;
            $data['company_pct'] = $this->company_pct;
        }

        if ($role === 'owner') {
            $data['company_amount'] = $this->company_amount;
            $data['hsop_markup'] = $this->hsop_markup;
            $data['internal_notes'] = $this->internal_notes;
        }

        return $data;
    }
}
