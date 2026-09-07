import { Center, Loader } from '@mantine/core';
import { Navigate, Outlet } from 'react-router';

import { useSession } from '@/features/auth/hooks/useSession';

/** Keeps a signed-in user off the login and register pages. */
export function RequireGuest() {
  const { isAuthenticated, isLoading } = useSession();

  if (isLoading) {
    return (
      <Center h="60vh">
        <Loader aria-label="Checking your session" />
      </Center>
    );
  }

  return isAuthenticated ? <Navigate to="/dashboard" replace /> : <Outlet />;
}
