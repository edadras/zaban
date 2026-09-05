<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Single JSON envelope for the whole API.
 *
 * Every response has the same shape so the Flutter client can parse one way:
 *   { "data": …, "meta": {…}?, "error": {…}? }
 */
final class ApiResponse
{
    public static function ok(mixed $data = null, array $meta = [], int $status = 200): JsonResponse
    {
        $payload = ['data' => self::normalise($data)];

        if ($data instanceof ResourceCollection && $data->resource instanceof LengthAwarePaginator) {
            $p = $data->resource;
            $meta += [
                'page' => $p->currentPage(),
                'per_page' => $p->perPage(),
                'total' => $p->total(),
                'last_page' => $p->lastPage(),
            ];
        }
        if ($meta) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    public static function created(mixed $data = null, array $meta = []): JsonResponse
    {
        return self::ok($data, $meta, 201);
    }

    public static function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }

    public static function error(string $code, string $message, int $status = 400, array $details = []): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details) {
            $error['details'] = $details;
        }

        return response()->json(['data' => null, 'error' => $error], $status);
    }

    private static function normalise(mixed $data): mixed
    {
        if ($data instanceof JsonResource || $data instanceof ResourceCollection) {
            return $data;
        }

        return $data;
    }
}
