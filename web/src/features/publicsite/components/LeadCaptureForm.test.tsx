import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { apiUrl } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import { LeadCaptureForm } from './LeadCaptureForm';

const BASE = '/public/dhaka-art-school';
const CONSENT = 'I agree to Dhaka Art School contacting me by email.';

/** Serves one token per fetch, in order, and counts the fetches. */
function serveForm(tokens: string[] = ['token-1']) {
  let served = 0;

  server.use(
    http.get(apiUrl(`${BASE}/lead-form`), () => {
      const token = tokens[Math.min(served, tokens.length - 1)];
      served += 1;
      return HttpResponse.json({ data: { token, consent_text: CONSENT } });
    }),
  );

  return { served: () => served };
}

/** Records every submission and answers it the way the server answers all of them. */
function serveSubmit(respond?: (body: Record<string, unknown>) => Response | undefined) {
  const bodies: Record<string, unknown>[] = [];

  server.use(
    http.post(apiUrl(`${BASE}/leads`), async ({ request }) => {
      const body = (await request.json()) as Record<string, unknown>;
      bodies.push(body);
      return respond?.(body) ?? HttpResponse.json({ data: { received: true } }, { status: 202 });
    }),
  );

  return bodies;
}

function renderForm() {
  return renderWithRouter(
    <LeadCaptureForm academy="dhaka-art-school" source="course" sourceSlug="watercolour" />,
  );
}

describe('LeadCaptureForm', () => {
  it('asks people to agree to the wording the server sent, and thanks them', async () => {
    serveForm();
    const bodies = serveSubmit();
    const user = userEvent.setup();
    renderForm();

    const consent = await screen.findByRole('checkbox', { name: CONSENT });
    await user.type(screen.getByRole('textbox', { name: /email/i }), 'ada@example.test');
    await user.type(screen.getByRole('textbox', { name: /^name/i }), 'Ada');
    await user.click(consent);
    await user.click(screen.getByRole('button', { name: 'Keep me posted' }));

    expect(await screen.findByText(/Thank you/)).toBeTruthy();
    expect(bodies).toEqual([
      {
        email: 'ada@example.test',
        name: 'Ada',
        consent: true,
        source: 'course',
        source_slug: 'watercolour',
        form_token: 'token-1',
        website: '',
      },
    ]);
  });

  it('sends nothing until they agree to be contacted', async () => {
    serveForm();
    const bodies = serveSubmit();
    const user = userEvent.setup();
    renderForm();

    await screen.findByRole('checkbox', { name: CONSENT });
    await user.type(screen.getByRole('textbox', { name: /email/i }), 'ada@example.test');
    await user.click(screen.getByRole('button', { name: 'Keep me posted' }));

    expect(await screen.findByText('Tick the box to agree to be contacted.')).toBeTruthy();
    expect(bodies).toHaveLength(0);
  });

  it('fetches a fresh form when the old one expired, and asks them to send it again', async () => {
    const form = serveForm(['old-token', 'new-token']);
    const bodies = serveSubmit((body) =>
      body['form_token'] === 'old-token'
        ? HttpResponse.json(
            {
              error: {
                code: 'validation_failed',
                message: 'The given data was invalid.',
                details: [
                  {
                    field: 'form_token',
                    code: 'invalid',
                    message: 'This form has expired. Reload the page and try again.',
                  },
                ],
                request_id: 'req-1',
              },
            },
            { status: 422 },
          )
        : undefined,
    );
    const user = userEvent.setup();
    renderForm();

    await user.click(await screen.findByRole('checkbox', { name: CONSENT }));
    await user.type(screen.getByRole('textbox', { name: /email/i }), 'ada@example.test');
    await user.click(screen.getByRole('button', { name: 'Keep me posted' }));

    expect(await screen.findByText(/we refreshed it/)).toBeTruthy();
    await waitFor(() => expect(form.served()).toBe(2));

    // What they typed survives; only the token changed.
    await user.click(screen.getByRole('button', { name: 'Keep me posted' }));

    expect(await screen.findByText(/Thank you/)).toBeTruthy();
    expect(bodies.map((body) => body['form_token'])).toEqual(['old-token', 'new-token']);
  });

  it('keeps the honeypot out of reach of anybody using the page', async () => {
    serveForm();
    renderForm();

    await screen.findByRole('checkbox', { name: CONSENT });

    // Hidden from assistive technology and out of the tab order: only a
    // script filling every input it finds will ever put something in it.
    expect(screen.queryByRole('textbox', { name: /website/i })).toBeNull();
    expect(screen.getAllByRole('textbox')).toHaveLength(2);
  });
});
