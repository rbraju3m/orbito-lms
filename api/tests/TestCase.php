<?php

declare(strict_types=1);

namespace Tests;

use App\Support\Http\RequestId;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\UsesSharedTenant;

abstract class TestCase extends BaseTestCase
{
    /*
     * RefreshDatabase is composed HERE, not applied per test class, because
     * this class has to wrap `beginDatabaseTransaction` — and a trait applied
     * to a subclass would override an inherited override, silently. The alias
     * keeps the original reachable so the wrapper can call it.
     *
     * Every Feature test runs inside a live academy: there is no meaningful
     * test of this application outside one, since the domain tables only exist
     * in a tenant schema.
     */
    use RefreshDatabase {
        RefreshDatabase::beginDatabaseTransaction as private beginRefreshDatabaseTransaction;
        RefreshDatabase::migrateDatabases as private migrateCentralDatabase;
    }
    use UsesSharedTenant;

    private ?Authenticatable $actingAsUser = null;

    /**
     * Both connections. Without 'tenant' here a test's writes to the academy
     * would COMMIT and leak into the next test.
     *
     * @return list<string>
     */
    protected function connectionsToTransact(): array
    {
        return $this->transactsTenantConnection()
            ? ['mysql', 'tenant']
            : ['mysql'];
    }

    /**
     * The once-per-process hook.
     *
     * NOT `afterRefreshingDatabase()`: in Laravel 13 that runs on every test
     * and only AFTER the transaction has opened, so provisioning there would
     * be rolled back — and the first test would try to transact a connection
     * that does not exist yet. `migrateDatabases()` is the one guarded by
     * RefreshDatabaseState::$migrated, which is what "once per process" means.
     */
    protected function migrateDatabases(): void
    {
        $this->migrateCentralDatabase();

        // Committed, because no transaction is open yet — which is exactly why
        // the tenant row and its seeded roles survive every later rollback.
        $this->provisionSharedTenant();
    }

    public function beginDatabaseTransaction(): void
    {
        // Must run first: the 'tenant' connection listed above does not exist
        // until tenancy is initialised, and this application is brand new.
        $this->initializeSharedTenant();

        $this->beginRefreshDatabaseTransaction();
    }

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
