<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;

/**
 * The single place the API's success envelope is built.
 *
 * Every successful response is `{"data": ...}`. Collections add `meta` and `links`
 * via the resource collection classes. See docs/API.md §2.
 */
final class ApiResponse
{
    public static function ok(mixed $data, int $status = Response::HTTP_OK): JsonResponse
    {
        if ($data instanceof JsonResource) {
            return $data->response()->setStatusCode($status);
        }

        return new JsonResponse(['data' => $data], $status);
    }

    public static function created(mixed $data): JsonResponse
    {
        return self::ok($data, Response::HTTP_CREATED);
    }

    public static function accepted(mixed $data = null): JsonResponse
    {
        return self::ok($data, Response::HTTP_ACCEPTED);
    }

    public static function noContent(): JsonResponse
    {
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @param  list<array{field?: string, code: string, message: string}>  $details
     */
    public static function error(
        string $code,
        string $message,
        int $status,
        array $details = [],
    ): JsonResponse {
        $payload = [
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
                'request_id' => RequestId::current(),
            ],
        ];

        return new JsonResponse($payload, $status);
    }
}
