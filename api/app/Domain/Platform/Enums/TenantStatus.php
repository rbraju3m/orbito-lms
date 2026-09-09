<?php

declare(strict_types=1);

namespace App\Domain\Platform\Enums;

/**
 * Where an academy sits in its lifecycle.
 *
 * Separate from `is_active`, which is the operator's kill switch. A tenant can
 * be `active` and switched off; the two answer different questions.
 */
enum TenantStatus: string
{
    /** Signed up, schema not yet provisioned or not yet approved. */
    case Pending = 'pending';
    case Active = 'active';
    /** Access closed, data intact, reinstatable without a restore. */
    case Suspended = 'suspended';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting approval',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Rejected => 'Rejected',
        };
    }

    public function grantsAccess(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether a transition is legal from here.
     *
     * The single definition: `ChangeTenantStatus` enforces it and
     * `TenantResource` renders it, so the button an operator sees and the
     * transition the server accepts cannot drift. Same instinct as
     * `PublishChecklist` and `SubmissionRules`.
     *
     * Suspend stays legal on an already-suspended academy on purpose — it is
     * how an operator amends the reason — which is why the UI labels it
     * differently there rather than hiding it.
     */
    public function allows(TenantAction $action): bool
    {
        return match ($action) {
            TenantAction::Approve, TenantAction::Reject => $this === self::Pending,
            TenantAction::Suspend => $this !== self::Rejected,
            TenantAction::Reactivate => $this === self::Suspended,
        };
    }

    /** @return list<TenantAction> */
    public function availableActions(): array
    {
        return array_values(array_filter(
            TenantAction::cases(),
            fn (TenantAction $action): bool => $this->allows($action),
        ));
    }
}
