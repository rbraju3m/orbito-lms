import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it, vi } from 'vitest';

import { apiUrl, runnerFixture } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import { QuizRunnerRoute } from './QuizRunnerRoute';

const ROUTE = '/learn/course-uuid/item-1/quiz/attempt-1';
const PATH = '/learn/:courseId/:itemId/quiz/:attemptId';

function runnerHandlers(attempt = runnerFixture(), onSave?: (body: unknown) => void) {
  return [
    http.get(apiUrl('/learn/quiz-attempts/attempt-1'), () => HttpResponse.json({ data: attempt })),
    http.patch(apiUrl('/learn/quiz-attempts/attempt-1/answers'), async ({ request }) => {
      onSave?.(await request.json());

      return HttpResponse.json({ data: { saved: true, seconds_remaining: 590 } });
    }),
  ];
}

describe('QuizRunnerRoute', () => {
  it('shows one page of questions at a time', async () => {
    server.use(...runnerHandlers());
    renderWithRouter(<QuizRunnerRoute />, { path: PATH, route: ROUTE });

    expect(await screen.findByText(/Who wrote Gitanjali/)).toBeInTheDocument();
    expect(screen.queryByText(/Name one Bengali metre/)).not.toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: 'Next' }));

    expect(await screen.findByText(/Name one Bengali metre/)).toBeInTheDocument();
  });

  it('autosaves an answer in the shape the API documents', async () => {
    const saved = vi.fn();
    server.use(...runnerHandlers(runnerFixture(), saved));
    renderWithRouter(<QuizRunnerRoute />, { path: PATH, route: ROUTE });

    await userEvent.click(await screen.findByRole('radio', { name: /Rabindranath Tagore/ }));

    await waitFor(() =>
      expect(saved).toHaveBeenCalledWith({ question_id: 'q-1', answer: { option_id: 11 } }),
    );
    expect(await screen.findByText('Saved')).toBeInTheDocument();
  });

  it('surfaces a refused save rather than losing the answer quietly', async () => {
    server.use(
      http.get(apiUrl('/learn/quiz-attempts/attempt-1'), () =>
        HttpResponse.json({ data: runnerFixture() }),
      ),
      http.patch(apiUrl('/learn/quiz-attempts/attempt-1/answers'), () =>
        HttpResponse.json(
          { error: { code: 'attempt_rejected', message: 'Time ran out on this attempt.' } },
          { status: 409 },
        ),
      ),
    );
    renderWithRouter(<QuizRunnerRoute />, { path: PATH, route: ROUTE });

    await userEvent.click(await screen.findByRole('radio', { name: /Rabindranath Tagore/ }));

    expect(await screen.findByRole('alert')).toHaveTextContent(/Time ran out/);
  });

  it('counts down from the time the server reported', async () => {
    server.use(...runnerHandlers());
    renderWithRouter(<QuizRunnerRoute />, { path: PATH, route: ROUTE });

    expect(await screen.findByText('10:00')).toBeInTheDocument();
  });

  it('warns that time is up when the server reported none left', async () => {
    server.use(
      ...runnerHandlers(
        runnerFixture({
          attempt: { ...runnerFixture().attempt, seconds_remaining: 0 },
        }),
      ),
    );
    renderWithRouter(<QuizRunnerRoute />, { path: PATH, route: ROUTE });

    expect(await screen.findByRole('alert')).toHaveTextContent(/Time is up/);
    expect(await screen.findByRole('radio', { name: /Rabindranath Tagore/ })).toBeDisabled();
  });

  it('asks before submitting, and says how much is unanswered', async () => {
    server.use(...runnerHandlers());
    renderWithRouter(<QuizRunnerRoute />, { path: PATH, route: ROUTE });

    await userEvent.click(await screen.findByRole('button', { name: 'Submit now' }));

    expect(await screen.findByText(/You have answered 0 of 2/)).toBeInTheDocument();
  });

  /*
   * The runner type cannot carry a correct answer, and neither can the payload
   * the API sends. This is the client-side half of ADR-06.
   */
  it('renders nothing that reveals which option is correct', async () => {
    server.use(...runnerHandlers());
    const { container } = renderWithRouter(<QuizRunnerRoute />, { path: PATH, route: ROUTE });

    await screen.findByText(/Who wrote Gitanjali/);

    expect(container.innerHTML).not.toContain('is_correct');
    expect(container.innerHTML).not.toContain('correct_answer');
  });
});
