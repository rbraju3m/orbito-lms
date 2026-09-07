<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/**
 * Assert the documented error envelope (docs/API.md §2), not just the status.
 * A wrong `code` is a breaking API change even when the status is right.
 */
expect()->extend('toBeApiError', function (string $code) {
    /** @var TestResponse $response */
    $response = $this->value;

    $response->assertJsonStructure([
        'error' => ['code', 'message', 'details', 'request_id'],
    ]);

    expect($response->json('error.code'))->toBe($code);

    return $this;
});

/**
 * Roles and permissions must exist before almost any Feature test. Seeding the
 * registry once per test is cheap (97 rows) and beats every test remembering.
 */
function seedRegistry(): void
{
    app(PermissionRegistry::class)->sync();
}

/**
 * A user holding one global role, with the registry already seeded.
 */
function userWithRole(
    RoleKey $role,
    array $attributes = [],
): User {
    seedRegistry();

    return User::factory()
        ->withRole($role)
        ->create($attributes);
}

/**
 * Sanctum only starts a session when the request looks like it came from the
 * first-party SPA, which it decides from the Origin header. Tests exercising
 * the cookie flow must therefore look like the SPA.
 *
 * @return array<string, string>
 */
function spaHeaders(): array
{
    return ['Origin' => (string) config('app.frontend_url')];
}
