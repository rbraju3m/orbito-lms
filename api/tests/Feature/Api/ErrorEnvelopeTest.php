<?php

declare(strict_types=1);

use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

it('returns the documented envelope for an unknown route', function (): void {
    expect(getJson('/api/v1/does-not-exist')->assertNotFound())
        ->toBeApiError('not_found');
});

it('returns method_not_allowed for the wrong verb', function (): void {
    expect(postJson('/api/v1/health')->assertStatus(405))
        ->toBeApiError('method_not_allowed');
});

it('maps validation failures to field-level details', function (): void {
    Route::middleware('api')->post('/api/v1/_test/validate', function (): void {
        throw ValidationException::withMessages([
            'email' => ['The email field is required.'],
            'password' => ['The password is too short.'],
        ]);
    });

    $response = postJson('/api/v1/_test/validate')->assertStatus(422);

    expect($response)->toBeApiError('validation_failed');

    expect($response->json('error.details'))->toHaveCount(2)
        ->and($response->json('error.details.0.field'))->toBe('email')
        ->and($response->json('error.details.0.code'))->toBe('invalid');
});

it('maps a domain exception to its stable machine code and status', function (): void {
    $exception = new class('This course cannot be published yet.') extends DomainException
    {
        public function errorCode(): string
        {
            return 'course_not_publishable';
        }

        public function status(): int
        {
            return 422;
        }
    };

    Route::middleware('api')->get('/api/v1/_test/domain', function () use ($exception): void {
        throw $exception;
    });

    $response = getJson('/api/v1/_test/domain')->assertStatus(422);

    expect($response)->toBeApiError('course_not_publishable')
        ->and($response->json('error.message'))->toBe('This course cannot be published yet.');
});

it('does not leak internal detail from an unexpected exception in production', function (): void {
    config()->set('app.debug', false);

    Route::middleware('api')->get('/api/v1/_test/boom', function (): void {
        throw new RuntimeException('SELECT * FROM users WHERE secret = "hunter2"');
    });

    $response = getJson('/api/v1/_test/boom');

    $response->assertStatus(500);

    expect($response)->toBeApiError('server_error')
        ->and($response->json('error.message'))->toBe('Something went wrong on our end.')
        ->and($response->json('error.message'))->not->toContain('hunter2');
});

it('carries the request id inside the error body', function (): void {
    $response = getJson('/api/v1/does-not-exist', ['X-Request-Id' => 'ORBITO-ERR-1']);

    expect($response->json('error.request_id'))->toBe('ORBITO-ERR-1');
});
