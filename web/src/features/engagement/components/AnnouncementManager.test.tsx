import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it, vi } from 'vitest';

import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import type { Announcement } from '../api/types';
import { AnnouncementManager } from './AnnouncementManager';

const API = 'http://localhost:8000/api/v1';

function announcement(overrides: Partial<Announcement> = {}): Announcement {
  return {
    id: 'ann-uuid',
    title: 'Week 3 is up',
    body: '<p>New lessons.</p>',
    is_published: false,
    published_at: null,
    notify: true,
    author: { name: 'Dr Bose' },
    created_at: '2026-09-01T10:00:00+00:00',
    updated_at: '2026-09-01T10:00:00+00:00',
    ...overrides,
  };
}

function serve(rows: Announcement[], canManage = true) {
  server.use(
    http.get(`${API}/courses/:id/announcements`, () =>
      HttpResponse.json({
        data: rows,
        meta: {
          current_page: 1,
          per_page: 15,
          total: rows.length,
          last_page: 1,
          can_manage: canManage,
        },
        links: { first: null, prev: null, next: null, last: null },
      }),
    ),
  );
}

describe('AnnouncementManager', () => {
  it('will not send on one click', async () => {
    /*
     * Publishing emails everybody enrolled and cannot be un-sent, so the
     * first click asks and the second one sends. Saving a draft and sending
     * it to a thousand people are different acts.
     */
    const published = vi.fn();
    serve([announcement()]);
    server.use(
      http.post(`${API}/announcements/:id/publish`, () => {
        published();
        return HttpResponse.json({ data: announcement({ is_published: true }) });
      }),
    );

    renderWithRouter(<AnnouncementManager courseId="course-uuid" />);

    await userEvent.click(await screen.findByRole('button', { name: /^publish$/i }));

    expect(published).not.toHaveBeenCalled();
    expect(screen.getByText(/cannot be un-sent/i)).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: /publish now/i }));
    expect(published).toHaveBeenCalled();
  });

  it('warns differently when the author chose not to notify', async () => {
    serve([announcement({ notify: false })]);
    renderWithRouter(<AnnouncementManager courseId="course-uuid" />);

    await userEvent.click(await screen.findByRole('button', { name: /^publish$/i }));

    expect(screen.getByText(/without notifying anybody/i)).toBeInTheDocument();
    expect(screen.queryByText(/cannot be un-sent/i)).not.toBeInTheDocument();
  });

  it('shows a draft as a draft', async () => {
    serve([announcement()]);
    renderWithRouter(<AnnouncementManager courseId="course-uuid" />);

    expect(await screen.findByText('Draft')).toBeInTheDocument();
    expect(screen.getByText('Not sent')).toBeInTheDocument();
  });

  it('says so rather than showing a compose box it cannot use', async () => {
    serve([], false);
    renderWithRouter(<AnnouncementManager courseId="course-uuid" />);

    expect(await screen.findByText(/but not write them/i)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /new announcement/i })).not.toBeInTheDocument();
  });
});
