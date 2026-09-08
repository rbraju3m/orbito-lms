<?php

declare(strict_types=1);

namespace App\Domain\Certification\Policies;

use App\Domain\Certification\Models\Certificate;
use App\Domain\Identity\Models\User;

/**
 * Who may see and withdraw a certificate.
 *
 * Note what is absent: there is no `verify`. The public verification page
 * authorises nobody — its credential is the 32-character token, and adding a
 * policy there would be asking "who are you?" of a stranger whose whole
 * purpose is being one.
 */
final class CertificatePolicy
{
    /**
     * The holder always may, with no permission consulted.
     *
     * `certificate.view.own` is not checked for one's own certificate: it is a
     * record of something that person did, and gating it behind a permission
     * an academy could revoke would let an academy hide from a learner what
     * they earned. Same reasoning as OrderPolicy.
     */
    public function view(User $actor, Certificate $certificate): bool
    {
        return $certificate->user_id === $actor->id
            || $actor->hasPermission('certificate.view.any');
    }

    /**
     * Withdrawing one is a serious, visible act — it makes a public page say
     * somebody's qualification was taken back — so it is its own capability
     * rather than riding on `view.any`.
     */
    public function revoke(User $actor, Certificate $certificate): bool
    {
        return $actor->hasPermission('certificate.revoke');
    }
}
