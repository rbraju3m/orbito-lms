import { screen } from '@testing-library/react';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { apiUrl, sessionFixture } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import { LazyNotificationBell } from './LazyNotificationBell';

describe('LazyNotificationBell', () => {
  /*
   * Off the first paint, but not off the page: the real bell — with its
   * unread count — replaces the placeholder once its chunk arrives.
   */
  it('becomes the real bell, unread count and all', async () => {
    server.use(
      http.get(apiUrl('/auth/me'), () => HttpResponse.json({ data: sessionFixture({}) })),
      http.get(apiUrl('/notifications/unread-count'), () =>
        HttpResponse.json({ data: { unread_count: 3 } }),
      ),
    );

    /*
     * Load the chunk BEFORE rendering. Left to `React.lazy`, the dynamic
     * import races `findByRole`, and under a full parallel run it lost — the
     * test passed alone and failed in the suite. Pre-importing makes what is
     * under test the swap, not the machine's speed. The swap and the unread
     * count are still two async hops; `asyncUtilTimeout` (test/setup.ts)
     * gives them room when the workers are busy.
     */
    await import('./NotificationBell');

    renderWithRouter(<LazyNotificationBell />);

    expect(await screen.findByRole('button', { name: 'Notifications, 3 unread' })).toBeEnabled();
  });
});
