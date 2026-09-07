import { renderHook, waitFor } from '@testing-library/react';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { apiUrl, sessionFixture } from '@/shared/test/handlers';
import { createTestQueryClient } from '@/shared/test/render';
import { server } from '@/shared/test/server';

import { useSession } from './useSession';

import { QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';

function wrapper({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={createTestQueryClient()}>{children}</QueryClientProvider>;
}

describe('useSession', () => {
  it('treats a 401 as signed out, not as an error', async () => {
    const { result } = renderHook(() => useSession(), { wrapper });

    await waitFor(() => expect(result.current.isLoading).toBe(false));

    expect(result.current.isAuthenticated).toBe(false);
    expect(result.current.session).toBeNull();
    expect(result.current.can('review.create')).toBe(false);
  });

  it('exposes the resolved permissions of a signed-in caller', async () => {
    server.use(http.get(apiUrl('/auth/me'), () => HttpResponse.json({ data: sessionFixture() })));

    const { result } = renderHook(() => useSession(), { wrapper });

    await waitFor(() => expect(result.current.isAuthenticated).toBe(true));

    expect(result.current.can('review.create')).toBe(true);
    expect(result.current.can('course.create')).toBe(false);
    expect(result.current.hasRole('student')).toBe(true);
  });

  it('answers canAny across a list', async () => {
    server.use(
      http.get(apiUrl('/auth/me'), () =>
        HttpResponse.json({
          data: sessionFixture({
            roles: ['student', 'instructor'],
            permissions: ['course.create'],
          }),
        }),
      ),
    );

    const { result } = renderHook(() => useSession(), { wrapper });

    await waitFor(() => expect(result.current.isAuthenticated).toBe(true));

    expect(result.current.canAny(['user.delete', 'course.create'])).toBe(true);
    expect(result.current.canAny(['user.delete', 'order.refund'])).toBe(false);
  });
});
