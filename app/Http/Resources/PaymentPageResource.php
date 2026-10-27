<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Response of GET /api/admin/payments: { data, meta } as in shopwise-contracts/openapi/payments.yaml.
 *
 * @property LengthAwarePaginator<int, Payment> $resource
 */
final class PaymentPageResource extends JsonResource
{
    /** The response already has its own top-level "data". */
    public static $wrap;

    /**
     * @return array{data: AnonymousResourceCollection, meta: array{page: int, perPage: int, total: int, lastPage: int}}
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => PaymentResource::collection($this->resource->getCollection()),
            'meta' => [
                'page' => $this->resource->currentPage(),
                'perPage' => $this->resource->perPage(),
                'total' => $this->resource->total(),
                'lastPage' => $this->resource->lastPage(),
            ],
        ];
    }
}
