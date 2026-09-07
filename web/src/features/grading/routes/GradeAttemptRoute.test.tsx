import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it, vi } from 'vitest';

import { apiUrl } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import { GradeAttemptRoute } from './GradeAttemptRoute';

const ID = 'attempt-1';
const PATH = '/studio/grading/quiz/:attemptId';
const ROUTE = `/studio/grading/quiz/${ID}`;

function view(overrides: Record<string, unknown> = {}) {
  return {
    attempt: {
      id: ID,
      attempt_number: 1,
      status: 'awaiting_review',
      status_label: 'Awaiting review',
      submitted_at: '2026-09-08T08:00:00+00:00',
      total_points: 6,
      earned_points: 1,
    },
    learner: { id: 'user-1', name: 'Anita Roy' },
    review: [
      {
        question_id: 'q-1',
        type: 'single_choice',
        title: 'Who wrote Gitanjali?',
        points_possible: 1,
        points_earned: 1,
        is_correct: true,
        awaiting_review: false,
        your_answer: { option_id: 11 },
        your_answer_label: 'Rabindranath Tagore',
        feedback: null,
      },
      {
        question_id: 'q-2',
        type: 'long_answer',
        title: 'Discuss the metre.',
        points_possible: 5,
        points_earned: 0,
        is_correct: null,
        awaiting_review: true,
        your_answer: { text: 'Payar is counted by syllable.' },
        your_answer_label: 'Payar is counted by syllable.',
        feedback: null,
      },
    ],
    ...overrides,
  };
}

const renderGrader = () => renderWithRouter(<GradeAttemptRoute />, { path: PATH, route: ROUTE });

describe('GradeAttemptRoute', () => {
  it('shows the whole paper with the essay marked as needing a person', async () => {
    server.use(
      http.get(apiUrl(`/studio/grading/quiz/${ID}`), () => HttpResponse.json({ data: view() })),
    );
    renderGrader();

    expect(await screen.findByText(/Who wrote Gitanjali/)).toBeInTheDocument();
    expect(screen.getByText('1/1')).toBeInTheDocument();
    expect(screen.getByText('Needs marking')).toBeInTheDocument();
    expect(screen.getByText(/Payar is counted by syllable/)).toBeInTheDocument();
  });

  /* The server already decided the auto-graded ones; overwriting them here
     would let a grader silently contradict it. */
  it('only offers a mark box for what needs one', async () => {
    server.use(
      http.get(apiUrl(`/studio/grading/quiz/${ID}`), () => HttpResponse.json({ data: view() })),
    );
    renderGrader();

    expect(await screen.findByLabelText('Mark (out of 5)')).toBeInTheDocument();
    expect(screen.queryByLabelText('Mark (out of 1)')).not.toBeInTheDocument();
  });

  it('sends only the questions a person marked', async () => {
    const graded = vi.fn();
    server.use(
      http.get(apiUrl(`/studio/grading/quiz/${ID}`), () => HttpResponse.json({ data: view() })),
      http.post(apiUrl(`/studio/grading/quiz/${ID}`), async ({ request }) => {
        graded(await request.json());

        return HttpResponse.json({ data: { id: ID, status: 'graded' } });
      }),
    );
    renderGrader();

    const mark = await screen.findByLabelText('Mark (out of 5)');
    await userEvent.clear(mark);
    await userEvent.type(mark, '4');

    await userEvent.click(screen.getByLabelText('Feedback'));
    await userEvent.paste('Expand on metre.');
    await userEvent.click(screen.getByRole('button', { name: 'Save the mark' }));

    await waitFor(() => expect(graded).toHaveBeenCalled());
    expect(graded.mock.calls[0]![0]).toEqual({
      grades: [{ question_id: 'q-2', points: 4, feedback: 'Expand on metre.' }],
    });
  });

  it('says so when an attempt graded itself', async () => {
    server.use(
      http.get(apiUrl(`/studio/grading/quiz/${ID}`), () =>
        HttpResponse.json({
          data: view({ review: view().review.filter((row) => !row.awaiting_review) }),
        }),
      ),
    );
    renderGrader();

    expect(await screen.findByText(/graded itself/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Save the mark/ })).not.toBeInTheDocument();
  });
});
