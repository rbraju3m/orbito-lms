import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import type { Discussion } from '../api/types';
import { DiscussionRoute } from './DiscussionRoute';

const API = 'http://localhost:8000/api/v1';

function thread(overrides: Partial<Discussion> = {}): Discussion {
  return {
    id: 'thread-uuid',
    type: 'question',
    type_label: 'Question',
    title: 'What does the third stanza mean?',
    body: '<p>I cannot follow the turn.</p>',
    status: 'open',
    status_label: 'Open',
    is_resolved: false,
    is_pinned: false,
    is_answerable: true,
    reply_count: 1,
    last_reply_at: '2026-09-02T10:00:00+00:00',
    accepted_reply_id: null,
    author: { name: 'Rumi Haque', is_you: true },
    item: null,
    replies: [
      {
        id: 'reply-uuid',
        body: '<p>It is the metre.</p>',
        parent_id: null,
        is_instructor_reply: true,
        author: { name: 'Dr Bose', is_you: false },
        created_at: '2026-09-02T10:00:00+00:00',
      },
    ],
    viewer: { can_reply: true, can_accept: true, can_moderate: false },
    created_at: '2026-09-01T10:00:00+00:00',
    ...overrides,
  };
}

function serve(discussion: Discussion) {
  server.use(http.get(`${API}/discussions/:id`, () => HttpResponse.json({ data: discussion })));
}

function render() {
  return renderWithRouter(<DiscussionRoute />, {
    path: '/learn/:courseId/discussions/:discussionId',
    route: '/learn/course-uuid/discussions/thread-uuid',
  });
}

describe('DiscussionRoute', () => {
  it('marks who answered as course team, from the stored flag', async () => {
    serve(thread());
    render();

    expect(await screen.findByText('What does the third stanza mean?')).toBeInTheDocument();
    // Read off a stored flag: somebody who answered as an instructor and later
    // lost the role still answered as one.
    expect(screen.getByText('Course team')).toBeInTheDocument();
  });

  it('shows the accepted answer as accepted', async () => {
    serve(
      thread({
        accepted_reply_id: 'reply-uuid',
        is_resolved: true,
        status: 'resolved',
        status_label: 'Resolved',
      }),
    );
    render();

    expect(await screen.findByText('Accepted answer')).toBeInTheDocument();
  });

  it('offers accepting only when the reader may accept', async () => {
    serve(thread({ viewer: { can_reply: true, can_accept: false, can_moderate: false } }));
    render();

    await screen.findByText('What does the third stanza mean?');
    await userEvent.click(screen.getByRole('button', { name: /reply actions/i }));

    expect(await screen.findByRole('menuitem', { name: /^reply$/i })).toBeInTheDocument();
    expect(screen.queryByRole('menuitem', { name: /accept as answer/i })).not.toBeInTheDocument();
  });

  it('does not offer accepting on a comment, which cannot be answered', async () => {
    serve(
      thread({
        type: 'comment',
        type_label: 'Comment',
        is_answerable: false,
        viewer: { can_reply: true, can_accept: true, can_moderate: false },
      }),
    );
    render();

    await screen.findByText('What does the third stanza mean?');
    await userEvent.click(screen.getByRole('button', { name: /reply actions/i }));

    expect(await screen.findByRole('menuitem', { name: /^reply$/i })).toBeInTheDocument();
    expect(screen.queryByRole('menuitem', { name: /accept as answer/i })).not.toBeInTheDocument();
  });

  it('hides the reply box from somebody who may not post', async () => {
    serve(thread({ viewer: { can_reply: false, can_accept: false, can_moderate: false } }));
    render();

    await screen.findByText('What does the third stanza mean?');
    expect(screen.queryByLabelText('Add a reply')).not.toBeInTheDocument();
  });

  it('says plainly when a thread is hidden, including to its author', async () => {
    serve(
      thread({
        status: 'hidden',
        status_label: 'Hidden',
        viewer: { can_reply: false, can_accept: false, can_moderate: true },
      }),
    );
    render();

    // Said out loud, including to the author — that is what hiding means.
    expect(await screen.findByText(/only moderators can see it/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /unhide/i })).toBeInTheDocument();
  });
});
