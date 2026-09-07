import { Container } from '@mantine/core';
import { Outlet } from 'react-router';

import { useSession } from '@/features/auth/hooks/useSession';
import { ErrorState } from '@/shared/ui';

export interface RequirePermissionProps {
  /** The caller needs at least one of these. */
  anyOf: string[];
}

/**
 * Hides a whole route subtree from users who lack the permission.
 *
 * Again: UI only. The server is what actually enforces this — see
 * docs/ROLES_PERMISSIONS.md §5.
 */
export function RequirePermission({ anyOf }: RequirePermissionProps) {
  const { canAny, isLoading } = useSession();

  if (isLoading) return null;

  if (!canAny(anyOf)) {
    return (
      <Container size="md" py="xl">
        <ErrorState
          title="You do not have access to this area"
          error={new Error('Ask an administrator if you think this is a mistake.')}
        />
      </Container>
    );
  }

  return <Outlet />;
}
