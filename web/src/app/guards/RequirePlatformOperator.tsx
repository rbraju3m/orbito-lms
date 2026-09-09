import { Container } from '@mantine/core';
import { Outlet } from 'react-router';

import { useSession } from '@/features/auth/hooks/useSession';
import { ErrorState } from '@/shared/ui';

/**
 * Hides the academy registry from everyone but a platform operator.
 *
 * Deliberately NOT `RequirePermission`. Permissions are roles, roles live
 * inside an academy's schema, and this surface is about academies rather than
 * inside one — an academy Super Admin holds every permission there is and
 * still has no business here. The answer comes from `is_super_admin` on the
 * central user row, which is what the server's `super_admin` middleware reads.
 *
 * UI only, as ever: every route behind it authorizes on its own.
 */
export function RequirePlatformOperator() {
  const { session, isLoading } = useSession();

  if (isLoading) return null;

  if (session?.is_platform_operator !== true) {
    return (
      <Container size="md" py="xl">
        <ErrorState
          title="This is the platform operator's area"
          error={new Error('It runs the academy registry, and is separate from administering one.')}
        />
      </Container>
    );
  }

  return <Outlet />;
}
