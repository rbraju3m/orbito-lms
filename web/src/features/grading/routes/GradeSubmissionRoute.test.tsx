import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it, vi } from 'vitest';

import { apiUrl, assignmentFixture, submissionFixture } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import { GradeSubmissionRoute } from './GradeSubmissionRoute';

const ID = 'submission-1';
const PATH = '/studio/grading/assignment/:submissionId';
const ROUTE = `/studio/grading/assignment/${ID}`;

function view(overrides: Record<string, unknown> = {}) {
  return {
    submission: submissionFixture(),
    assignment: assignmentFixture(),
    learner: { id: 'user-2', name: 'Bijoy Das' },
    item: { id: 'item-10', title: 'Close reading' },
    ...overrides,
  };
}

function viewHandler(data: Record<string, unknown> = view()) {
  return http.get(apiUrl(`/studio/grading/assignment/${ID}`), () => HttpResponse.json({ data }));
}

const renderGrader = () => renderWithRouter(<GradeSubmissionRoute />, { path: PATH, route: ROUTE });

describe('GradeSubmissionRoute', () => {
  it('shows the work, who wrote it and what it is out of', async () => {
    server.use(viewHandler());
    renderGrader();

    expect(await screen.findByText('Close reading')).toBeInTheDocument();
    expect(screen.getByText(/Bijoy Das/)).toBeInTheDocument();
    expect(screen.getByText(/My close reading/)).toBeInTheDocument();
    expect(screen.getByLabelText('Mark (out of 50)')).toBeInTheDocument();
  });

  it('saves a mark and feedback', async () => {
    const graded = vi.fn();
    server.use(
      viewHandler(),
      http.post(apiUrl(`/studio/grading/assignment/${ID}`), async ({ request }) => {
        graded(await request.json());

        return HttpResponse.json({ data: submissionFixture({ status: 'graded' }) });
      }),
    );
    renderGrader();

    const mark = await screen.findByLabelText('Mark (out of 50)');
    await userEvent.clear(mark);
    await userEvent.type(mark, '42');

    await userEvent.click(screen.getByLabelText('Feedback'));
    await userEvent.paste('Strong on imagery.');
    await userEvent.click(screen.getByRole('button', { name: 'Save the mark' }));

    await waitFor(() => expect(graded).toHaveBeenCalled());
    expect(graded.mock.calls[0]![0]).toMatchObject({
      points: 42,
      feedback: 'Strong on imagery.',
    });
  });

  /*
   * The penalty is arithmetic the server does. An instructor who deducted it
   * by hand as well would take it off twice.
   */
  it('tells the grader the late penalty is applied for them', async () => {
    server.use(
      viewHandler(
        view({
          submission: submissionFixture({ is_late: true }),
          assignment: assignmentFixture({ late_policy: 'penalise', late_penalty_percent: 25 }),
        }),
      ),
    );
    renderGrader();

    expect(await screen.findByText(/25% is taken off automatically/)).toBeInTheDocument();
  });

  it('will not hand work back without saying why', async () => {
    server.use(viewHandler());
    renderGrader();

    expect(await screen.findByRole('button', { name: /Hand back/ })).toBeDisabled();
  });

  it('hands work back with a reason', async () => {
    const returned = vi.fn();
    server.use(
      viewHandler(),
      http.post(apiUrl(`/studio/grading/assignment/${ID}/return`), async ({ request }) => {
        returned(await request.json());

        return HttpResponse.json({ data: submissionFixture({ status: 'returned' }) });
      }),
    );
    renderGrader();

    await userEvent.click(await screen.findByLabelText('Feedback'));
    await userEvent.paste('Please cite the text.');
    await userEvent.click(screen.getByRole('button', { name: /Hand back/ }));

    await waitFor(() => expect(returned).toHaveBeenCalled());
    expect(returned.mock.calls[0]![0]).toEqual({ feedback: 'Please cite the text.' });
  });

  it('starts from the mark a previous grader gave', async () => {
    server.use(
      viewHandler(
        view({
          submission: submissionFixture({ status: 'graded', points_raw: 33, points_earned: 33 }),
        }),
      ),
    );
    renderGrader();

    expect(await screen.findByLabelText('Mark (out of 50)')).toHaveValue('33');
  });
});
