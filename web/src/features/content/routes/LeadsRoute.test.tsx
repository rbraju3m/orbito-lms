import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { apiUrl, paginated } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import type { Lead } from '../api/leads';
import { LeadsRoute } from './LeadsRoute';

function lead(overrides: Partial<Lead> = {}): Lead {
  return {
    id: 'l-1',
    email: 'ada@example.test',
    name: 'Ada Lovelace',
    status: 'new',
    status_label: 'New',
    source: 'course',
    source_label: 'Course page',
    source_title: 'Watercolour',
    consent_text: 'I agree to be contacted by email.',
    consented_at: '2026-09-10T00:00:00Z',
    submissions_count: 2,
    first_submitted_at: '2026-09-10T00:00:00Z',
    last_submitted_at: '2026-09-12T00:00:00Z',
    ...overrides,
  };
}

function serve(rows: Lead[], can: { manage?: boolean; export?: boolean } = {}) {
  const page = paginated(rows);

  server.use(
    http.get(apiUrl('/admin/leads'), () =>
      HttpResponse.json({
        ...page,
        meta: { ...page.meta, can_manage: can.manage ?? true, can_export: can.export ?? true },
      }),
    ),
  );
}

describe('LeadsRoute', () => {
  it('lists who asked, where they asked, and how often', async () => {
    serve([lead()]);
    renderWithRouter(<LeadsRoute />);

    expect(await screen.findByText('ada@example.test')).toBeTruthy();
    expect(screen.getByText(/Course page: Watercolour/)).toBeTruthy();
    expect(screen.getByText(/asked 2 times/)).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Export CSV' })).toBeTruthy();
  });

  it('explains an empty list instead of drawing nothing', async () => {
    serve([]);
    renderWithRouter(<LeadsRoute />);

    expect(await screen.findByText('No leads yet')).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Export CSV' })).toBeNull();
  });

  it('draws no controls for a reader the server says may only look', async () => {
    serve([lead()], { manage: false, export: false });
    renderWithRouter(<LeadsRoute />);

    await screen.findByText('ada@example.test');

    expect(screen.queryByRole('button', { name: 'Delete ada@example.test' })).toBeNull();
    expect(screen.queryByRole('textbox', { name: 'Status for ada@example.test' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Export CSV' })).toBeNull();
  });

  it('erases a lead only after asking', async () => {
    serve([lead()]);
    let deleted = false;
    server.use(
      http.delete(apiUrl('/admin/leads/l-1'), () => {
        deleted = true;
        return new HttpResponse(null, { status: 204 });
      }),
    );
    const user = userEvent.setup();
    renderWithRouter(<LeadsRoute />);

    await user.click(await screen.findByRole('button', { name: 'Delete ada@example.test' }));

    const dialog = await screen.findByRole('dialog');
    expect(deleted).toBe(false);
    expect(within(dialog).getByText(/delete it there too/)).toBeTruthy();

    await user.click(within(dialog).getByRole('button', { name: 'Delete' }));

    await waitFor(() => expect(deleted).toBe(true));
  });
});
