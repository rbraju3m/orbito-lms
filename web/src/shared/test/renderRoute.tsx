import { MantineProvider } from '@mantine/core';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, type RenderResult } from '@testing-library/react';
import type { ReactElement, ReactNode } from 'react';
import { createMemoryRouter, RouterProvider } from 'react-router';

import { theme } from '@/app/theme';

import { createTestQueryClient } from './render';

/**
 * Renders a component inside a router, for anything that navigates, reads the
 * URL, or renders <Link>.
 */
export function renderWithRouter(
  ui: ReactElement,
  options: { route?: string; path?: string; queryClient?: QueryClient } = {},
): RenderResult & { queryClient: QueryClient } {
  const queryClient = options.queryClient ?? createTestQueryClient();
  const path = options.path ?? '/';

  const router = createMemoryRouter(
    [
      { path, element: ui },
      { path: '*', element: <div data-testid="elsewhere" /> },
    ],
    { initialEntries: [options.route ?? path] },
  );

  function Wrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={queryClient}>
        {/* env="test" disables Mantine's transitions. Without it, portalled
            content (menus, dropdowns) is still animating when an assertion
            runs, producing failures that look like missing elements. */}
        <MantineProvider theme={theme} defaultColorScheme="light" env="test">
          {children}
        </MantineProvider>
      </QueryClientProvider>
    );
  }

  const result = render(<RouterProvider router={router} />, { wrapper: Wrapper });

  return Object.assign(result, { queryClient });
}
