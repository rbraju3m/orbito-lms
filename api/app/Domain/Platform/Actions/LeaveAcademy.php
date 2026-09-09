<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use App\Domain\Identity\Models\User;

/**
 * Returns a platform operator to the central connection — no academy open.
 *
 * The role assignments inside the academies are deliberately left alone: they
 * are what makes re-entering instant, and an operator standing outside an
 * academy has no way to use them.
 */
final class LeaveAcademy
{
    public function handle(User $operator): User
    {
        $operator->forceFill(['tenant_id' => null])->save();

        return $operator->refresh();
    }
}
