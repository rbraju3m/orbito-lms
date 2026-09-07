import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { apiUrl, gradingQueueFixture, paginated } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import { GradingQueueRoute } from './GradingQueueRoute';

const COURSE = 'course-uuid';
const PATH = '/studio/courses/:id/grading';
const ROUTE = `/studio/courses/${COURSE}/grading`;

function queueHandler(rows: unknown[] = gradingQueueFixture()) {
  return http.get(apiUrl(`/studio/courses/${COURSE}/grading`), () =>
    HttpResponse.json(paginated(rows)),
  );
}

const renderQueue = () => renderWithRouter(<GradingQueueRoute />, { path: PATH, route: ROUTE });

describe('GradingQueueRoute', () => {
  /*
   * The whole point of the shared queue: an instructor sees one list of work,
   * not one list per kind of thing.
   */
  it('lists quizzes and assignments in the same table', async () => {
    server.use(queueHandler());
    renderQueue();

    expect(await screen.findByText('Chapter quiz')).toBeInTheDocument();
    expect(screen.getByText('Close reading')).toBeInTheDocument();
    expect(screen.getByText('Anita Roy')).toBeInTheDocument();
    expect(screen.getByText('Bijoy Das')).toBeInTheDocument();
  });

  it('sends each row to the screen that can mark it', async () => {
    server.use(queueHandler());
    renderQueue();

    const quizRow = (await screen.findByText('Chapter quiz')).closest('tr')!;
    const assignmentRow = screen.getByText('Close reading').closest('tr')!;

    expect(within(quizRow).getByRole('link', { name: 'Mark it' })).toHaveAttribute(
      'href',
      '/studio/grading/quiz/attempt-1',
    );
    expect(within(assignmentRow).getByRole('link', { name: 'Mark it' })).toHaveAttribute(
      'href',
      '/studio/grading/assignment/submission-1',
    );
  });

  it('says so when there is nothing to mark', async () => {
    server.use(queueHandler([]));
    renderQueue();

    expect(await screen.findByText('Nothing to mark')).toBeInTheDocument();
  });

  it('can ask for everything, not just what is waiting', async () => {
    const seen: string[] = [];
    server.use(
      http.get(apiUrl(`/studio/courses/${COURSE}/grading`), ({ request }) => {
        seen.push(new URL(request.url).searchParams.get('status') ?? '');

        return HttpResponse.json(paginated(gradingQueueFixture()));
      }),
    );
    renderQueue();

    await screen.findByText('Chapter quiz');
    await userEvent.click(screen.getByRole('radio', { name: 'Everything' }));

    await screen.findByText('Chapter quiz');
    expect(seen).toContain('all');
  });

  it('shows an error with a way to retry', async () => {
    server.use(
      http.get(apiUrl(`/studio/courses/${COURSE}/grading`), () =>
        HttpResponse.json(
          { error: { code: 'server_error', message: 'Something broke.', details: [] } },
          { status: 500 },
        ),
      ),
    );
    renderQueue();

    expect(await screen.findByText('Queue unavailable')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /try again/i })).toBeInTheDocument();
  });
});
