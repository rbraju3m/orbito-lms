<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Support\PermissionRegistry;
use Illuminate\Database\Seeder;

final class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistry::class)->sync();
    }
}
