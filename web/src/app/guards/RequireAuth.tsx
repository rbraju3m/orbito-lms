import { Center, Loader } from '@mantine/core';
import { Navigate, Outlet, useLocation } from 'react-router';

import { useSession } from '@/features/auth/hooks/useSession';

/**
 * Route guard for signed-in areas.
 *
 * This is navigation, not security: it stops a signed-out user landing on a
 * page that will only 401 anyway. Every endpoint behind it authorizes on its own.
 */
export function RequireAuth() {
  const { isAuthenticated, isLoading } = useSession();
  const location = useLocation();

  if (isLoading) {
    return (
      <Center h="60vh">
        <Loader aria-label="Checking your session" />
      </Center>
    );
  }

  if (!isAuthenticated) {
    // Remember where they were headed so login can send them back.
    return <Navigate to="/login" replace state={{ from: location.pathname + location.search }} />;
  }

  return <Outlet />;
}
