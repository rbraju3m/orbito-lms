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

function uploadedFixture(overrides: Record<string, unknown> = {}) {
  return {
    id: 'media-1',
    ref: 11,
    collection: 'submission',
    mime: 'application/pdf',
    size_bytes: 40_960,
    width: null,
    height: null,
    original_name: 'essay.pdf',
    is_private: true,
    url: 'https://files.test/essay.pdf',
    url_expires_at: '2026-09-10T12:15:00Z',
    ...overrides,
  };
}

async function attachFile(container: HTMLElement) {
  await screen.findByRole('button', { name: 'Attach a file' });
  const input = container.querySelector<HTMLInputElement>('input[type="file"]');
  expect(input).not.toBeNull();
  await userEvent.upload(input!, new File(['%PDF-1.4'], 'essay.pdf', { type: 'application/pdf' }));
}

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

  /* An unused upload counts against the learner's quota until it is DELETED. */
  it('deletes a removed file on the server, not only from the list', async () => {
    const deleted = vi.fn();
    server.use(
      briefHandler(),
      http.post(apiUrl('/media'), () =>
        HttpResponse.json({ data: uploadedFixture() }, { status: 201 }),
      ),
      http.delete(apiUrl('/media/media-1'), () => {
        deleted();
        return new HttpResponse(null, { status: 204 });
      }),
    );
    const { container } = renderPane();

    await attachFile(container);
    await userEvent.click(await screen.findByRole('button', { name: 'Remove essay.pdf' }));

    await waitFor(() => expect(deleted).toHaveBeenCalledOnce());
    await waitFor(() => expect(screen.queryByText('essay.pdf')).not.toBeInTheDocument());
  });

  /* Already gone is the outcome the learner asked for. */
  it('drops a file from the list when the server no longer has it', async () => {
    server.use(
      briefHandler(),
      http.post(apiUrl('/media'), () =>
        HttpResponse.json({ data: uploadedFixture() }, { status: 201 }),
      ),
      http.delete(apiUrl('/media/media-1'), () =>
        HttpResponse.json(
          { error: { code: 'not_found', message: 'Resource not found.', details: [] } },
          { status: 404 },
        ),
      ),
    );
    const { container } = renderPane();

    await attachFile(container);
    await userEvent.click(await screen.findByRole('button', { name: 'Remove essay.pdf' }));

    await waitFor(() => expect(screen.queryByText('essay.pdf')).not.toBeInTheDocument());
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });

  it('says why an upload was refused when the quota is full', async () => {
    server.use(
      briefHandler(),
      http.post(apiUrl('/media'), () =>
        HttpResponse.json(
          {
            error: {
              code: 'upload_quota_exceeded',
              message:
                'You have 500 MB of uploads you have not handed in yet, and this file would take you past the 512 MB limit. Hand in or remove some of them first.',
              details: [],
              meta: { used_bytes: 524_288_000, limit_bytes: 536_870_912, file_bytes: 20_971_520 },
            },
          },
          { status: 409 },
        ),
      ),
    );
    const { container } = renderPane();

    await attachFile(container);

    expect(await screen.findByRole('alert')).toHaveTextContent(/not handed in yet/);
    expect(screen.queryByText('essay.pdf')).not.toBeInTheDocument();
  });
});
