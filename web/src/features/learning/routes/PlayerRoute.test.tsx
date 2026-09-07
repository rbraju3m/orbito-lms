import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { apiUrl, itemFixture, playerFixture } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import { PlayerRoute } from './PlayerRoute';

const COURSE = 'course-uuid';

function playerHandlers(
  player: Record<string, unknown> = playerFixture(),
  item: Record<string, unknown> = itemFixture(),
) {
  return [
    http.get(apiUrl(`/learn/courses/${COURSE}`), () => HttpResponse.json({ data: player })),
    http.get(apiUrl('/learn/items/:id'), ({ params }) =>
      HttpResponse.json({ data: { ...item, id: String(params['id']) } }),
    ),
    http.get(apiUrl('/learn/items/:id/notes'), () => HttpResponse.json({ data: [] })),
  ];
}

function renderPlayer(itemId = 'item-1') {
  return renderWithRouter(<PlayerRoute />, {
    path: '/learn/:courseId/:itemId',
    route: `/learn/${COURSE}/${itemId}`,
  });
}

describe('PlayerRoute', () => {
  it('shows a loading state first', () => {
    server.use(...playerHandlers());
    renderPlayer();

    expect(screen.getAllByRole('status', { name: /loading/i })[0]).toBeInTheDocument();
  });

  it('renders the course, curriculum and lesson content', async () => {
    server.use(...playerHandlers());
    renderPlayer();

    expect(await screen.findByText('Modern Bengali Poetry')).toBeInTheDocument();
    expect(screen.getByText('Foundations')).toBeInTheDocument();
    expect(await screen.findByText(/syllable-counted/i)).toBeInTheDocument();
  });

  it('exposes course progress to assistive technology', async () => {
    server.use(
      ...playerHandlers(
        playerFixture({
          progress: {
            completed_items: 1,
            total_items: 3,
            percent: 33.33,
            is_complete: false,
            started_at: null,
            completed_at: null,
            last_activity_at: null,
            last_item_id: null,
          },
        }),
      ),
    );
    renderPlayer();

    expect(await screen.findByLabelText(/33 percent complete/i)).toBeInTheDocument();
  });

  it('marks an item complete optimistically', async () => {
    // Stateful: a static handler would refetch `not_started` the moment the
    // mutation settles, undoing the optimistic tick under the assertion.
    const player = playerFixture() as ReturnType<typeof playerFixture>;
    let posted = false;

    server.use(
      http.get(apiUrl(`/learn/courses/${COURSE}`), () => HttpResponse.json({ data: player })),
      ...playerHandlers().slice(1),
      http.post(apiUrl('/learn/items/item-1/complete'), () => {
        posted = true;
        player.curriculum[0]!.items[0]!.status = 'completed';
        player.progress = { ...player.progress!, completed_items: 1, percent: 33.33 };
        return HttpResponse.json({ data: player.progress });
      }),
    );

    const user = userEvent.setup();
    renderPlayer();

    await user.click(await screen.findByRole('button', { name: /mark complete/i }));

    // The tick moves before the server answers.
    expect(await screen.findByRole('button', { name: /^completed$/i })).toBeInTheDocument();
    await waitFor(() => expect(posted).toBe(true));
  });

  it('rolls the tick back when the server refuses', async () => {
    server.use(
      ...playerHandlers(),
      http.post(apiUrl('/learn/items/item-1/complete'), () =>
        HttpResponse.json(
          { error: { code: 'content_locked', message: 'Nope.', details: [], request_id: 'X' } },
          { status: 423 },
        ),
      ),
    );

    const user = userEvent.setup();
    renderPlayer();

    await user.click(await screen.findByRole('button', { name: /mark complete/i }));

    // A half-applied completion is worse than none.
    await waitFor(() =>
      expect(screen.getByRole('button', { name: /mark complete/i })).toBeInTheDocument(),
    );
  });

  /* 423 is "you could get in", not "you did something wrong". */
  it('shows a locked screen rather than an error for gated content', async () => {
    server.use(
      http.get(apiUrl(`/learn/courses/${COURSE}`), () =>
        HttpResponse.json({
          data: playerFixture({
            access: {
              granted: false,
              reason: 'not_enrolled',
              source: 'none',
              is_staff: false,
              expires_at: null,
            },
            progress: null,
          }),
        }),
      ),
      http.get(apiUrl('/learn/items/:id'), () =>
        HttpResponse.json(
          {
            error: {
              code: 'content_locked',
              message: 'Enrol in this course to open this lesson.',
              details: [{ code: 'not_enrolled', message: 'Enrol in this course.' }],
              request_id: 'X',
            },
          },
          { status: 423 },
        ),
      ),
      http.get(apiUrl('/learn/items/:id/notes'), () => HttpResponse.json({ data: [] })),
    );

    renderPlayer();

    expect(await screen.findByText(/this lesson is locked/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /enrol for free/i })).toBeInTheDocument();
  });

  it('explains an expired enrollment differently from never having enrolled', async () => {
    server.use(
      http.get(apiUrl(`/learn/courses/${COURSE}`), () =>
        HttpResponse.json({
          data: playerFixture({
            access: {
              granted: false,
              reason: 'enrollment_expired',
              source: 'none',
              is_staff: false,
              expires_at: null,
            },
            progress: null,
          }),
        }),
      ),
      ...playerHandlers().slice(1),
    );

    renderPlayer();

    expect(await screen.findByText(/access to this course has expired/i)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /enrol for free/i })).not.toBeInTheDocument();
  });

  it('lists a locked course outline so a visitor can see what they would get', async () => {
    server.use(
      http.get(apiUrl(`/learn/courses/${COURSE}`), () =>
        HttpResponse.json({
          data: playerFixture({
            access: {
              granted: false,
              reason: 'not_enrolled',
              source: 'none',
              is_staff: false,
              expires_at: null,
            },
            progress: null,
          }),
        }),
      ),
      ...playerHandlers().slice(1),
    );

    renderPlayer();

    expect(await screen.findByText('Foundations')).toBeInTheDocument();
    // The lesson title also appears in the locked content pane; scope to the
    // curriculum row.
    expect(
      within(screen.getByTestId('curriculum-item-item-1')).getByText('Lesson 1'),
    ).toBeInTheDocument();
  });

  it('offers previous and next, disabling them at the ends', async () => {
    server.use(...playerHandlers());
    renderPlayer();

    expect(await screen.findByRole('button', { name: /previous/i })).toBeDisabled();
    expect(screen.getByRole('button', { name: /next/i })).toBeEnabled();
  });

  it('does not offer completion controls to course staff', async () => {
    server.use(
      ...playerHandlers(
        playerFixture({
          access: {
            granted: true,
            reason: 'granted',
            source: 'owner',
            is_staff: true,
            expires_at: null,
          },
          progress: null,
        }),
      ),
    );

    renderPlayer();

    await screen.findByText(/syllable-counted/i);
    // An instructor previewing has no enrollment to record against.
    expect(screen.queryByRole('button', { name: /mark complete/i })).not.toBeInTheDocument();
    expect(screen.getByText(/enrol in this course to take notes/i)).toBeInTheDocument();
  });

  it('shows a completed course as done', async () => {
    server.use(
      ...playerHandlers(
        playerFixture({
          progress: {
            completed_items: 3,
            total_items: 3,
            percent: 100,
            is_complete: true,
            started_at: null,
            completed_at: '2026-09-07T00:00:00Z',
            last_activity_at: null,
            last_item_id: null,
          },
        }),
      ),
    );

    renderPlayer();

    expect(await screen.findByText(/you've completed this course/i)).toBeInTheDocument();
  });

  it('marks the active lesson in the curriculum', async () => {
    server.use(...playerHandlers());
    renderPlayer('item-2');

    const row = await screen.findByTestId('curriculum-item-item-2');
    expect(within(row).getByText('Lesson 2')).toBeInTheDocument();
    expect(row).toHaveAttribute('aria-current', 'true');
  });
});
