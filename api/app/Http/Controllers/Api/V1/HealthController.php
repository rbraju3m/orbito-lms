<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Liveness + dependency readiness. Used by the load balancer, by CI, and as the
 * Phase 2 exit check. Returns 503 when any hard dependency is down so an
 * orchestrator can pull the instance out of rotation.
 */
final class HealthController
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('select 1')),
            'cache' => $this->check(function (): void {
                Cache::put('health:ping', '1', 5);
                Cache::get('health:ping');
            }),
            'queue' => $this->check(fn () => Queue::connection()->size()),
        ];

        $healthy = ! in_array(false, array_column($checks, 'ok'), true);

        return ApiResponse::ok([
            'status' => $healthy ? 'ok' : 'degraded',
            'app' => config('app.name'),
            'environment' => app()->environment(),
            'version' => config('orbito.version'),
            'time' => now()->toIso8601String(),
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    /** @return array{ok: bool, error?: string} */
    private function check(callable $probe): array
    {
        try {
            $probe();

            return ['ok' => true];
        } catch (Throwable $e) {
            report($e);

            return ['ok' => false, 'error' => class_basename($e)];
        }
    }
}
