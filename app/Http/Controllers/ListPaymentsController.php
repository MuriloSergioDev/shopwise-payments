<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Http\Requests\ListPaymentsRequest;
use App\Http\Resources\PaymentPageResource;
use App\Models\Payment;

final class ListPaymentsController
{
    /**
     * Payments newest first (ULID ids sort by creation time), optionally of one status.
     */
    public function __invoke(ListPaymentsRequest $request): PaymentPageResource
    {
        $status = $request->statusFilter();

        $payments = Payment::query()
            ->when($status instanceof PaymentStatus, fn ($query) => $query->where('status', $status))
            ->orderByDesc('id')
            ->paginate(perPage: $request->perPage(), page: $request->pageNumber());

        return new PaymentPageResource($payments);
    }
}
