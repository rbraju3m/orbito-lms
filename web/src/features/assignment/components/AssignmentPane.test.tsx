import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it, vi } from 'vitest';

import {
  apiUrl,
  assignmentBriefFixture,
  assignmentFixture,
  submissionFixture,
  submissionRulesFixture,
} from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import { AssignmentPane } from './AssignmentPane';

const ITEM = 'item-1';

function briefHandler(brief: Record<string, unknown> = assignmentBriefFixture()) {
  return http.get(apiUrl(`/learn/items/${ITEM}/assignment`), () =>
    HttpResponse.json({ data: brief }),
  );
}

const renderPane = (canSubmit = true) =>
  renderWithRouter(<AssignmentPane itemId={ITEM} title="Close reading" canSubmit={canSubmit} />);

describe('AssignmentPane', () => {
  it('shows the brief and what it is worth', async () => {
    server.use(briefHandler());
    renderPane();

    expect(await screen.findByText('Close reading')).toBeInTheDocument();
    expect(screen.getByText(/close reading of one poem/)).toBeInTheDocument();
    expect(screen.getByText('50 marks')).toBeInTheDocument();
    expect(screen.getByText('0 of 2 attempts used')).toBeInTheDocument();
  });

  it('hands in a written answer', async () => {
    const posted = vi.fn();
    server.use(
      briefHandler(),
      http.post(apiUrl(`/learn/items/${ITEM}/assignment/submissions`), async ({ request }) => {
        posted(await request.json());

        return HttpResponse.json({ data: submissionFixture() }, { status: 201 });
      }),
    );
    renderPane();

    await userEvent.click(await screen.findByLabelText('Your answer'));
    await userEvent.paste('Tagore bends payar into something conversational.');
    await userEvent.click(screen.getByRole('button', { name: 'Hand in' }));

    await waitFor(() => expect(posted).toHaveBeenCalled());
    expect(posted.mock.calls[0]![0]).toMatchObject({
      body: 'Tagore bends payar into something conversational.',
    });
  });

  it('will not hand in an empty answer', async () => {
    server.use(briefHandler());
    renderPane();

    expect(await screen.findByRole('button', { name: 'Hand in' })).toBeDisabled();
  });

  /* The button and the server read the same rules object. */
  it('closes the form when the attempts have run out', async () => {
    server.use(
      briefHandler(
        assignmentBriefFixture({
          rules: submissionRulesFixture({
            can_submit: false,
            reason: 'no_attempts_left',
            attempts_used: 2,
            attempts_left: 0,
          }),
        }),
      ),
    );
    renderPane();

    expect(await screen.findByText('No attempts left')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Hand in' })).not.toBeInTheDocument();
  });

  it('closes the form when the deadline has passed under a rejecting policy', async () => {
    server.use(
      briefHandler(
        assignmentBriefFixture({
          assignment: assignmentFixture({ late_policy: 'reject', due_at: '2026-09-01T00:00:00Z' }),
          rules: submissionRulesFixture({
            can_submit: false,
            reason: 'past_due',
            is_past_due: true,
          }),
        }),
      ),
    );
    renderPane();

    expect(await screen.findByText('The deadline has passed')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Hand in' })).not.toBeInTheDocument();
  });

  it('warns before handing in that the work will be penalised', async () => {
    server.use(
      briefHandler(
        assignmentBriefFixture({
          assignment: assignmentFixture({ late_policy: 'penalise', late_penalty_percent: 25 }),
          rules: submissionRulesFixture({ will_be_late: true, late_penalty_percent: 25 }),
        }),
      ),
    );
    renderPane();

    expect(await screen.findByText(/25% will be taken off the mark/)).toBeInTheDocument();
  });

  it('shows a mark with the late penalty broken out', async () => {
    server.use(
      briefHandler(
        assignmentBriefFixture({
          submissions: [
            submissionFixture({
              status: 'graded',
              status_label: 'Graded',
              is_late: true,
              points_raw: 40,
              late_penalty_points: 10,
              points_earned: 30,
              feedback: '<p>Strong on imagery.</p>',
            }),
          ],
        }),
      ),
    );
    renderPane();

    expect(await screen.findByText('30')).toBeInTheDocument();
    expect(screen.getByText(/40 marked, 10 off for lateness/)).toBeInTheDocument();
    expect(screen.getByText(/Strong on imagery/)).toBeInTheDocument();
  });

  it('does not offer the form to course staff', async () => {
    server.use(briefHandler());
    renderPane(false);

    expect(await screen.findByText(/viewing this as course staff/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Hand in' })).not.toBeInTheDocument();
  });

  it('surfaces a refusal from the server rather than losing the work', async () => {
    server.use(
      http.post(apiUrl(`/learn/items/${ITEM}/assignment/submissions`), () =>
        HttpResponse.json(
          {
            error: {
              code: 'submission_rejected',
              message: 'The deadline for this assignment has passed.',
              details: [],
            },
          },
          { status: 409 },
        ),
      ),
      briefHandler(),
    );
    renderPane();

    await userEvent.click(await screen.findByLabelText('Your answer'));
    await userEvent.paste('Late work');
    await userEvent.click(screen.getByRole('button', { name: 'Hand in' }));

    expect(await screen.findByRole('alert')).toHaveTextContent(/deadline .* has passed/);
  });
});
