<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

use App\Support\Http\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Maps every throwable to the documented error envelope.
 *
 * There is exactly one of these. No controller builds an error response by hand.
 */
final class ApiExceptionRenderer
{
    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $this->shouldHandle($request)) {
            return null;
        }

        return match (true) {
            $e instanceof DomainException => ApiResponse::error(
                $e->errorCode(), $e->getMessage(), $e->status(), $e->details(), $e->meta(),
            ),

            $e instanceof ValidationException => ApiResponse::error(
                'validation_failed',
                'The given data was invalid.',
                422,
                $this->validationDetails($e),
            ),

            $e instanceof AuthenticationException => ApiResponse::error(
                'unauthenticated', 'Authentication is required.', 401,
            ),

            $e instanceof AuthorizationException,
            $e instanceof AccessDeniedHttpException => ApiResponse::error(
                'forbidden', 'You are not allowed to do this.', 403,
            ),

            // A resource the user may not see is indistinguishable from one that
            // does not exist. Never leak existence through the status code.
            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => ApiResponse::error(
                'not_found', 'Resource not found.', 404,
            ),

            $e instanceof MethodNotAllowedHttpException => ApiResponse::error(
                'method_not_allowed', 'This method is not allowed for this endpoint.', 405,
            ),

            // The throttle's headers ARE the answer to "when may I retry?" —
            // `Retry-After` and `X-RateLimit-*`. Rebuilding the response
            // dropped them, on every limiter, while API.md §5 promised them.
            $e instanceof TooManyRequestsHttpException => ApiResponse::error(
                'rate_limited', 'Too many requests. Please slow down.', 429,
            )->withHeaders($e->getHeaders()),

            $e instanceof HttpExceptionInterface => ApiResponse::error(
                'http_error',
                $e->getMessage() !== '' ? $e->getMessage() : 'Request failed.',
                $e->getStatusCode(),
            ),

            default => $this->unexpected($e),
        };
    }

    private function shouldHandle(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    private function unexpected(Throwable $e): JsonResponse
    {
        // Detail is for the logs, never for the client — except in local/testing,
        // where hiding it just wastes the developer's time.
        $message = app()->hasDebugModeEnabled()
            ? $e->getMessage()
            : 'Something went wrong on our end.';

        return ApiResponse::error('server_error', $message, 500);
    }

    /** @return list<array{field: string, code: string, message: string}> */
    private function validationDetails(ValidationException $e): array
    {
        $details = [];

        foreach ($e->errors() as $field => $messages) {
            foreach ($messages as $message) {
                $details[] = [
                    'field' => (string) $field,
                    'code' => 'invalid',
                    'message' => (string) $message,
                ];
            }
        }

        return $details;
    }
}
