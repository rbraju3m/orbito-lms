import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { apiUrl, checklistFixture, courseFixture } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import { CourseEditorRoute } from './CourseEditorRoute';

const COURSE_ID = '018f-course-uuid';

function editorHandlers(course: Record<string, unknown>) {
  return [
    http.get(apiUrl(`/studio/courses/${COURSE_ID}`), () => HttpResponse.json({ data: course })),
    http.get(apiUrl('/categories'), () => HttpResponse.json({ data: [] })),
  ];
}

function renderEditor() {
  return renderWithRouter(<CourseEditorRoute />, {
    path: '/studio/courses/:id',
    route: `/studio/courses/${COURSE_ID}`,
  });
}

describe('CourseEditorRoute', () => {
  it('loads the course into the form', async () => {
    server.use(
      ...editorHandlers(
        courseFixture({
          publish_checklist: checklistFixture(true),
          allowed_transitions: ['in_review', 'published', 'archived'],
        }),
      ),
    );

    renderEditor();

    await waitFor(() =>
      expect(screen.getByLabelText(/^title/i)).toHaveValue('Introduction to Bengali Poetry'),
    );
    expect(screen.getByText('Draft')).toBeInTheDocument();
  });

  it('shows the publish checklist from the server', async () => {
    server.use(
      ...editorHandlers(
        courseFixture({
          publish_checklist: checklistFixture(false),
          allowed_transitions: ['published'],
        }),
      ),
    );

    renderEditor();

    expect(await screen.findByText('2 to fix')).toBeInTheDocument();
  });

  /*
   * A refused publish carries the failed checks in `details[]`. Showing only
   * "failed" would leave the instructor guessing what to fix.
   */
  it('lists every reason a publish was refused', async () => {
    server.use(
      ...editorHandlers(
        courseFixture({
          publish_checklist: checklistFixture(false),
          allowed_transitions: ['published'],
        }),
      ),
      http.post(apiUrl(`/studio/courses/${COURSE_ID}/publish`), () =>
        HttpResponse.json(
          {
            error: {
              code: 'course_not_publishable',
              message: 'This course is not ready to publish yet.',
              details: [
                {
                  field: 'description',
                  code: 'description_present',
                  message: 'Write a longer description.',
                },
                { field: 'category_id', code: 'category_present', message: 'Choose a category.' },
              ],
              request_id: 'X',
            },
          },
          { status: 422 },
        ),
      ),
    );

    const user = userEvent.setup();
    renderEditor();

    await user.click(await screen.findByRole('button', { name: /actions/i }));
    await user.click(await screen.findByRole('menuitem', { name: /^publish$/i }));

    // Scope to the transition alert: the page can show more than one.
    const alert = await screen.findByRole('alert', { name: /not published/i });
    expect(alert).toHaveTextContent(/write a longer description/i);
    expect(alert).toHaveTextContent(/choose a category/i);
  });

  it('publishes a ready course', async () => {
    server.use(
      ...editorHandlers(
        courseFixture({
          publish_checklist: checklistFixture(true),
          allowed_transitions: ['published'],
        }),
      ),
      http.post(apiUrl(`/studio/courses/${COURSE_ID}/publish`), () =>
        HttpResponse.json({
          data: courseFixture({
            status: 'published',
            status_label: 'Published',
            publish_checklist: checklistFixture(true),
            allowed_transitions: ['draft', 'archived'],
          }),
        }),
      ),
    );

    const user = userEvent.setup();
    renderEditor();

    await user.click(await screen.findByRole('button', { name: /actions/i }));
    await user.click(await screen.findByRole('menuitem', { name: /^publish$/i }));

    expect(await screen.findByText('Published')).toBeInTheDocument();
  });

  it('offers only the transitions the server allows', async () => {
    server.use(
      ...editorHandlers(
        courseFixture({
          status: 'published',
          status_label: 'Published',
          publish_checklist: checklistFixture(true),
          allowed_transitions: ['draft', 'archived'],
        }),
      ),
    );

    const user = userEvent.setup();
    renderEditor();

    await user.click(await screen.findByRole('button', { name: /actions/i }));

    // A published course cannot go back into review.
    expect(screen.queryByRole('menuitem', { name: /submit for review/i })).not.toBeInTheDocument();
    expect(screen.getByRole('menuitem', { name: /move back to draft/i })).toBeInTheDocument();
  });

  it('surfaces a save failure on the field it belongs to', async () => {
    server.use(
      ...editorHandlers(courseFixture({ publish_checklist: checklistFixture(true) })),
      http.patch(apiUrl(`/studio/courses/${COURSE_ID}`), () =>
        HttpResponse.json(
          {
            error: {
              code: 'validation_failed',
              message: 'The given data was invalid.',
              details: [
                { field: 'title', code: 'invalid', message: 'That title is already taken.' },
              ],
              request_id: 'X',
            },
          },
          { status: 422 },
        ),
      ),
    );

    const user = userEvent.setup();
    renderEditor();

    const title = await screen.findByLabelText(/^title/i);
    await user.clear(title);
    await user.type(title, 'A new title');
    await user.click(screen.getByRole('button', { name: /save changes/i }));

    expect(await screen.findByText(/that title is already taken/i)).toBeInTheDocument();
  });
});
