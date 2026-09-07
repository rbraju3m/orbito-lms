import { screen } from '@testing-library/react';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { apiUrl, attemptResultFixture } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import { QuizResultRoute } from './QuizResultRoute';

const ROUTE = '/learn/course-uuid/item-1/quiz/attempt-1/result';
const PATH = '/learn/:courseId/:itemId/quiz/:attemptId/result';

function resultHandler(result: Record<string, unknown> = attemptResultFixture()) {
  return http.get(apiUrl('/learn/quiz-attempts/attempt-1/result'), () =>
    HttpResponse.json({ data: result }),
  );
}

function renderResult() {
  return renderWithRouter(<QuizResultRoute />, { path: PATH, route: ROUTE });
}

describe('QuizResultRoute', () => {
  it('reports the score the server calculated', async () => {
    server.use(resultHandler());
    renderResult();

    expect(await screen.findByText('Not passed')).toBeInTheDocument();
    expect(screen.getByText('33%')).toBeInTheDocument();
    expect(screen.getByText(/1 of 3 points/)).toBeInTheDocument();
  });

  it('shows each answer with labels rather than option ids', async () => {
    server.use(resultHandler());
    renderResult();

    expect(await screen.findAllByText(/Your answer:/)).toHaveLength(2);
    expect(screen.getAllByText('Rabindranath Tagore').length).toBeGreaterThan(0);
    expect(screen.queryByText(/option_id/)).not.toBeInTheDocument();
  });

  it('reveals the correct answer only for what was got wrong', async () => {
    server.use(resultHandler());
    renderResult();

    const rows = await screen.findAllByText(/Correct answer:/);

    // Only the short-answer row, which scored nothing.
    expect(rows).toHaveLength(1);
    expect(screen.getByText('payar')).toBeInTheDocument();
  });

  it('says so when the quiz does not reveal answers', async () => {
    const fixture = attemptResultFixture();
    server.use(
      resultHandler({
        attempt: {
          ...(fixture.attempt as Record<string, unknown>),
          quiz: {
            ...(fixture.attempt.quiz as Record<string, unknown>),
            show_correct_answers: false,
          },
        },
        review: fixture.review.map(({ correct_answer: _omitted, ...row }) => row),
      }),
    );
    renderResult();

    expect(await screen.findByText(/does not reveal the correct answers/)).toBeInTheDocument();
    expect(screen.queryByText(/Correct answer:/)).not.toBeInTheDocument();
  });

  it('waits for the instructor when an answer needs a person', async () => {
    const fixture = attemptResultFixture();
    const { percent: _percent, passed: _passed, ...attempt } = fixture.attempt;

    server.use(
      resultHandler({
        attempt: { ...attempt, status: 'awaiting_review', status_label: 'Awaiting review' },
        review: [{ ...fixture.review[1], is_correct: null, awaiting_review: true }],
      }),
    );
    renderResult();

    expect(await screen.findByText(/Waiting on your instructor/)).toBeInTheDocument();
    expect(screen.getByText('Awaiting review')).toBeInTheDocument();
  });
});
