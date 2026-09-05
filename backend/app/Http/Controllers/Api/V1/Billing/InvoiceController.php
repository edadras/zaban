<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Billing\InvoiceResource;
use App\Models\Invoice;
use Illuminate\Http\Request;

class InvoiceController extends ApiController
{
    public function index(Request $request)
    {
        $invoices = Invoice::where('user_id', $request->user()->id)
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->paginate(min(50, max(1, (int) $request->integer('per_page', 20))));

        return $this->ok(InvoiceResource::collection($invoices));
    }

    public function show(Request $request, string $number)
    {
        $invoice = Invoice::where('user_id', $request->user()->id)->where('number', $number)->first();
        if (! $invoice) {
            return $this->fail('invoice_not_found', 'That invoice does not exist.', 404);
        }

        return $this->ok(new InvoiceResource($invoice));
    }
}
