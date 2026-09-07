<?php

declare(strict_types=1);

use App\Domain\Platform\Models\Tenant;
use Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper;
use Stancl\Tenancy\Database\Models\Domain;
use Stancl\Tenancy\TenantDatabaseManagers\MySQLDatabaseManager;
use Stancl\Tenancy\TenantDatabaseManagers\SQLiteDatabaseManager;
use Stancl\Tenancy\UUIDGenerator;

/*
|--------------------------------------------------------------------------
| Tenancy — one database per academy
|--------------------------------------------------------------------------
| An academy's whole world (courses, learners, enrolments, grades, media)
| lives in its own MySQL schema. Isolation is therefore structural: a query
| that forgets a scope cannot reach another academy's data, because that data
| is not in the connection.
|
| Users are the exception and live CENTRALLY, keyed to one tenant by
| `users.tenant_id`. The tenant for a request is read from the authenticated
| user — see InitializeTenancyByAuthenticatedUser.
*/

return [
    'tenant_model' => Tenant::class,
    'id_generator' => UUIDGenerator::class,

    'domain_model' => Domain::class,

    /*
     * Central domains. Tenancy here is resolved from the authenticated user,
     * not from the host, so this list only matters to the package's own
     * domain-based helpers — which we do not route through.
     */
    'central_domains' => [
        parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost',
    ],

    /*
     * What gets swapped when a tenant is initialised. Database first — the
     * rest are pointless without it.
     */
    'bootstrappers' => [
        DatabaseTenancyBootstrapper::class,
        CacheTenancyBootstrapper::class,
        FilesystemTenancyBootstrapper::class,
        QueueTenancyBootstrapper::class,
    ],

    'database' => [
        'central_connection' => env('DB_CONNECTION', 'mysql'),

        'template_tenant_connection' => null,

        // orbito_lms_tenant_<uuid>
        'prefix' => env('TENANCY_DB_PREFIX', 'orbito_lms_tenant_'),
        'suffix' => '',

        'managers' => [
            'sqlite' => SQLiteDatabaseManager::class,
            'mysql' => MySQLDatabaseManager::class,
            'mariadb' => MySQLDatabaseManager::class,
        ],
    ],

    'cache' => [
        'tag_base' => 'tenant',
    ],

    /*
     * Private media (lesson video, submitted work) is tenant-scoped on disk,
     * so one academy's uploads cannot be reached through another's signed URL
     * even if an id were guessed.
     *
     * The `public` disk is deliberately NOT scoped: course thumbnails and
     * academy logos are served straight from the web root.
     */
    'filesystem' => [
        'suffix_base' => 'tenant',

        /*
         * OFF. The package would otherwise repoint asset() at its own tenant
         * asset route, which `routes => false` below does not register — and
         * this is an API with no Blade and no asset() calls. Private media is
         * served through short-lived signed URLs (ADR-09), never a public path.
         */
        'asset_helper_tenancy' => false,

        'disks' => [
            'local',
        ],
        'root_override' => [
            'local' => '%storage_path%/app/',
        ],
    ],

    'redis' => [
        'prefix_base' => 'tenant',
        'prefixed_connections' => [],
    ],

    'features' => [],

    // API-only: the package's tenant asset route has no caller here.
    'routes' => false,

    'migration_parameters' => [
        '--force' => true,
        '--path' => [database_path('migrations/tenant')],
        '--realpath' => true,
    ],

    'seeder_parameters' => [
        '--class' => 'Database\\Seeders\\TenantDatabaseSeeder',
    ],
];
