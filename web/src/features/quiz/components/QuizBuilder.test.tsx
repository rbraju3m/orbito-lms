import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it, vi } from 'vitest';

import { apiUrl, quizBuilderFixture } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import { QuizBuilder } from './QuizBuilder';

function builderHandlers(
  state = quizBuilderFixture(),
  hooks: { onCreate?: (body: unknown) => void; onSettings?: (body: unknown) => void } = {},
) {
  return [
    http.get(apiUrl('/studio/items/item-1/quiz'), () => HttpResponse.json({ data: state })),
    http.post(apiUrl('/studio/items/item-1/quiz/questions'), async ({ request }) => {
      hooks.onCreate?.(await request.json());

      return HttpResponse.json({ data: state.questions[0] }, { status: 201 });
    }),
    http.patch(apiUrl('/studio/items/item-1/quiz'), async ({ request }) => {
      hooks.onSettings?.(await request.json());

      return HttpResponse.json({ data: state.settings });
    }),
  ];
}

const renderBuilder = () => renderWithRouter(<QuizBuilder itemId="item-1" />);

/** Typing key by key through a whole Mantine tree dominates these tests. */
async function fill(field: HTMLElement, value: string) {
  await userEvent.click(field);
  await userEvent.paste(value);
}

describe('QuizBuilder', () => {
  it('lists the questions with their type and worth', async () => {
    server.use(...builderHandlers());
    renderBuilder();

    expect(await screen.findByText('Who wrote Gitanjali?')).toBeInTheDocument();
    expect(screen.getByText('Single choice')).toBeInTheDocument();
    expect(screen.getByText('1 pt')).toBeInTheDocument();
    expect(screen.getByText('1 points in total')).toBeInTheDocument();
  });

  it('says plainly when a quiz has no questions', async () => {
    server.use(...builderHandlers(quizBuilderFixture({ questions: [] })));
    renderBuilder();

    expect(await screen.findByText(/No questions yet/)).toBeInTheDocument();
    expect(screen.getByText(/cannot be taken/)).toBeInTheDocument();
  });

  it('sends a new single choice question with exactly one correct option', async () => {
    const created = vi.fn();
    server.use(...builderHandlers(quizBuilderFixture(), { onCreate: created }));
    renderBuilder();

    await userEvent.click(await screen.findByRole('button', { name: /Add a question/ }));

    const dialog = await screen.findByRole('dialog');
    await fill(within(dialog).getByLabelText(/^Question/), 'Capital of Bengal?');
    await fill(within(dialog).getByLabelText('Option 1'), 'Kolkata');
    await fill(within(dialog).getByLabelText('Option 2'), 'Dhaka');
    await userEvent.click(within(dialog).getByLabelText('Option 1 is correct'));
    await userEvent.click(within(dialog).getByRole('button', { name: 'Add question' }));

    await waitFor(() => expect(created).toHaveBeenCalled());

    expect(created.mock.calls[0]![0]).toMatchObject({
      type: 'single_choice',
      title: 'Capital of Bengal?',
      points: 1,
      options: [
        { label: 'Kolkata', is_correct: true, position: 0 },
        { label: 'Dhaka', is_correct: false, position: 1 },
      ],
    });
  });

  /*
   * Carrying four choices into a true/false question would only produce a
   * validation error on save, so the type resets its own options.
   */
  it('replaces the options when the question type changes', async () => {
    server.use(...builderHandlers());
    renderBuilder();

    await userEvent.click(await screen.findByRole('button', { name: /Add a question/ }));

    const dialog = await screen.findByRole('dialog');
    await fill(within(dialog).getByLabelText('Option 1'), 'Kolkata');

    await userEvent.click(within(dialog).getAllByLabelText('Type')[0]!);
    await userEvent.click(await screen.findByRole('option', { name: 'True or false' }));

    expect(within(dialog).getByLabelText('Option 1')).toHaveValue('True');
    expect(within(dialog).getByLabelText('Option 2')).toHaveValue('False');
  });

  it('offers accepted answers only for a short answer question', async () => {
    server.use(...builderHandlers());
    renderBuilder();

    await userEvent.click(await screen.findByRole('button', { name: /Add a question/ }));

    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).queryByLabelText('Accepted answers')).not.toBeInTheDocument();

    await userEvent.click(within(dialog).getAllByLabelText('Type')[0]!);
    await userEvent.click(await screen.findByRole('option', { name: 'Short answer' }));

    expect(within(dialog).getByLabelText('Accepted answers')).toBeInTheDocument();
    expect(within(dialog).queryByLabelText('Option 1')).not.toBeInTheDocument();
  });

  /*
   * A state updater runs after the event has been released, so reading
   * `event.currentTarget` inside one crashes the whole tree.
   */
  it('accepts typing into a fill-in-the-blank row', async () => {
    server.use(...builderHandlers());
    renderBuilder();

    await userEvent.click(await screen.findByRole('button', { name: /Add a question/ }));

    const dialog = await screen.findByRole('dialog');
    await userEvent.click(within(dialog).getAllByLabelText('Type')[0]!);
    await userEvent.click(await screen.findByRole('option', { name: 'Fill in the blank' }));

    await fill(within(dialog).getByLabelText('Blank 1'), '1910');

    expect(within(dialog).getByLabelText('Blank 1')).toHaveValue('1910');
  });

  it('warns that a long answer always waits for a person', async () => {
    server.use(...builderHandlers());
    renderBuilder();

    await userEvent.click(await screen.findByRole('button', { name: /Add a question/ }));

    const dialog = await screen.findByRole('dialog');
    await userEvent.click(within(dialog).getAllByLabelText('Type')[0]!);
    await userEvent.click(await screen.findByRole('option', { name: 'Long answer' }));

    expect(within(dialog).getByText(/wait for a person to grade/)).toBeInTheDocument();
  });

  it('reports a server rejection instead of pretending the question saved', async () => {
    // Registered before the defaults: MSW resolves handlers in order.
    server.use(
      http.post(apiUrl('/studio/items/item-1/quiz/questions'), () =>
        HttpResponse.json(
          {
            error: {
              code: 'validation_failed',
              message: 'The given data was invalid.',
              details: [
                {
                  field: 'options',
                  code: 'invalid',
                  message: 'Mark exactly one option correct.',
                },
              ],
            },
          },
          { status: 422 },
        ),
      ),
      ...builderHandlers(),
    );
    renderBuilder();

    await userEvent.click(await screen.findByRole('button', { name: /Add a question/ }));

    const dialog = await screen.findByRole('dialog');
    await fill(within(dialog).getByLabelText(/^Question/), 'Broken');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Add question' }));

    expect(await within(dialog).findByRole('alert')).toHaveTextContent(
      /Mark exactly one option correct/,
    );
  });

  it('saves the quiz settings the author changed', async () => {
    const settings = vi.fn();
    server.use(...builderHandlers(quizBuilderFixture(), { onSettings: settings }));
    renderBuilder();

    await userEvent.click(await screen.findByRole('tab', { name: 'Settings' }));
    const pass = screen.getByLabelText('Pass mark (%)');
    await userEvent.clear(pass);
    await userEvent.type(pass, '70');
    await userEvent.click(screen.getByRole('button', { name: 'Save settings' }));

    await waitFor(() => expect(settings).toHaveBeenCalled());
    expect(settings.mock.calls[0]![0]).toMatchObject({ passing_score_percent: 70 });
  });
});
