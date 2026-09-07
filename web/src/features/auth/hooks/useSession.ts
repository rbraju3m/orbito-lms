import { useQuery } from '@tanstack/react-query';

import { sessionQuery } from '../api/queries';
import type { Session } from '../api/types';

export interface SessionState {
  session: Session | null;
  isLoading: boolean;
  isAuthenticated: boolean;
  /**
   * True when the caller holds the permission.
   *
   * This hides UI the user may not use. It is NOT security — every endpoint
   * authorizes independently on the server.
   */
  can: (permission: string) => boolean;
  canAny: (permissions: string[]) => boolean;
  hasRole: (role: string) => boolean;
}

export function useSession(): SessionState {
  const { data, isPending } = useQuery(sessionQuery());

  const permissions = new Set(data?.permissions ?? []);
  const roles = new Set(data?.roles ?? []);

  return {
    session: data ?? null,
    isLoading: isPending,
    isAuthenticated: data != null,
    can: (permission) => permissions.has(permission),
    canAny: (list) => list.some((permission) => permissions.has(permission)),
    hasRole: (role) => roles.has(role),
  };
}
