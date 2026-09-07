<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

/*
 * The central/tenant boundary, enforced.
 *
 * When tenancy is initialised, `database.default` becomes the academy's
 * connection. A model whose table lives in the CENTRAL database and which is
 * not pinned will quietly follow that default and look for its table inside
 * the tenant schema. Depending on the table name that is either a confusing
 * "table doesn't exist", or — far worse for anything ever added to both — a
 * silent read of the wrong database.
 *
 * These tables are central. Anything mapping to one must declare
 * `protected $connection = 'mysql'`.
 */
const CENTRAL_TABLES = [
    'tenants',
    'users',
    'user_social_links',
    'usage_counters',
    'plans',
    'subscriptions',
    'sessions',
    'password_reset_tokens',
    'personal_access_tokens',
];

/** @return list<class-string<Model>> */
function domainModels(): array
{
    $classes = [];

    foreach (Finder::create()->files()->in(app_path('Domain'))->name('*.php')->path('Models') as $file) {
        $class = 'App\\Domain\\'.Str::of($file->getRelativePathname())
            ->replace(['/', '.php'], ['\\', ''])
            ->toString();

        if (class_exists($class) && is_subclass_of($class, Model::class)) {
            $classes[] = $class;
        }
    }

    return $classes;
}

it('pins every central model to the central connection', function (): void {
    $unpinned = [];

    foreach (domainModels() as $class) {
        $model = new $class;

        if (! in_array($model->getTable(), CENTRAL_TABLES, true)) {
            continue;
        }

        if ($model->getConnectionName() !== 'mysql') {
            $unpinned[] = $class.' ('.$model->getTable().')';
        }
    }

    expect($unpinned)->toBe(
        [],
        'These models map to a central table but are not pinned: '.implode(', ', $unpinned),
    );
});

/*
 * The pin has to hold while a tenant is OPEN — that is the only moment it
 * matters, and asserting it in central context would pass for free.
 */
it('keeps central models on the central connection inside a tenant', function (): void {
    expect(tenancy()->initialized)->toBeTrue()
        ->and(config('database.default'))->toBe('tenant');

    foreach (domainModels() as $class) {
        $model = new $class;

        if (in_array($model->getTable(), CENTRAL_TABLES, true)) {
            expect($model->getConnectionName())->toBe('mysql', $class.' drifted onto the tenant connection');
        }
    }
});

it('reads central tables from the central database while a tenant is open', function (): void {
    $user = User::factory()->create();

    expect($user->getConnectionName())->toBe('mysql')
        ->and(User::query()->whereKey($user->id)->exists())->toBeTrue();
});

/*
 * The other direction. A tenant model must follow the CURRENT default rather
 * than inherit a pinned parent's connection — see LivesInTenantSchema.
 */
it('keeps a tenant model on the tenant connection even when reached from a central one', function (): void {
    $user = User::factory()->create();

    expect($user->courses()->getModel()->getConnectionName())->toBe('tenant')
        ->and($user->roleAssignments()->getModel()->getConnectionName())->toBe('tenant')
        ->and($user->instructorProfile()->getModel()->getConnectionName())->toBe('tenant');
});
