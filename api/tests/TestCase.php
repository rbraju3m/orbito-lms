<?php

declare(strict_types=1);

namespace Tests;

use App\Support\Http\RequestId;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    private ?Authenticatable $actingAsUser = null;

    private ?string $actingAsGuard = null;

    protected function setUp(): void
    {
        parent::setUp();

        // The request id is process-static; without this a test can observe the
        // id from the previous one.
        RequestId::reset();

        $this->actingAsUser = null;
        $this->actingAsGuard = null;
    }

    /** @param  string|null  $guard */
    public function actingAs(Authenticatable $user, $guard = null): static
    {
        $this->actingAsUser = $user;
        $this->actingAsGuard = $guard;

        return parent::actingAs($user, $guard);
    }

    /**
     * A real deployment boots a fresh container per request. The test process
     * reuses one, so Illuminate's guards keep the user they resolved on an
     * earlier request — which would let a REVOKED token keep authenticating and
     * make a logout test pass for the wrong reason.
     *
     * Forgetting the guards before every call models what actually happens in
     * production; the acting-as user is re-applied because that is test intent,
     * not leaked state.
     *
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $cookies
     * @param  array<string, mixed>  $files
     * @param  array<string, mixed>  $server
     */
    public function call(
        $method,
        $uri,
        $parameters = [],
        $cookies = [],
        $files = [],
        $server = [],
        $content = null,
    ): TestResponse {
        $this->app['auth']->forgetGuards();

        if ($this->actingAsUser !== null) {
            parent::actingAs($this->actingAsUser, $this->actingAsGuard);
        }

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
