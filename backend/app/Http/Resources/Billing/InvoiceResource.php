<?php

namespace App\Http\Resources\Billing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Invoice */
class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'number' => $this->number,
            'status' => $this->status,
            'currency' => strtoupper($this->currency),
            'subtotal' => (int) $this->subtotal,
            'discount_total' => (int) $this->discount_total,
            'tax_total' => (int) $this->tax_total,
            'total' => (int) $this->total,
            'total_display' => number_format($this->total / 100, 2, '.', ''),
            'issued_at' => $this->issued_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'pdf_url' => $this->pdf_path ? url('/storage/'.ltrim($this->pdf_path, '/')) : null,
        ];
    }
}
