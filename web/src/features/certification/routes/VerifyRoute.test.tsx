import { screen } from '@testing-library/react';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import type { CertificateVerification } from '../api/types';
import { VerifyRoute } from './VerifyRoute';

const API = 'http://localhost:8000/api/v1';
const TENANT = '01a07f6b-799c-702e-bbfc-b14f1913ab04';
const TOKEN = 'a'.repeat(32);

function verification(overrides: Partial<CertificateVerification> = {}): CertificateVerification {
  return {
    number: 'CERT-2026-ABCDEFGHIJ',
    status: 'issued',
    is_valid: true,
    has_expired: false,
    is_revoked: false,
    issued_at: '2026-09-01T10:00:00+00:00',
    expires_at: null,
    revoked_at: null,
    learner_name: 'Rumi Haque',
    course_title: 'Modern Bengali Poetry',
    academy_name: 'Bengali Academy',
    completed_at: '2026-08-30T10:00:00+00:00',
    ...overrides,
  };
}

function serve(body: CertificateVerification) {
  server.use(http.get(`${API}/verify/:tenant/:token`, () => HttpResponse.json({ data: body })));
}

function renderVerify() {
  return renderWithRouter(<VerifyRoute />, {
    route: `/verify/${TENANT}/${TOKEN}`,
    path: '/verify/:tenant/:token',
  });
}

describe('VerifyRoute', () => {
  it('answers the question first, then shows the detail', async () => {
    serve(verification());
    renderVerify();

    // The verdict, in words, before any field.
    expect(await screen.findByText(/this certificate is valid/i)).toBeInTheDocument();
    expect(screen.getByText('Rumi Haque')).toBeInTheDocument();
    expect(screen.getByText('Modern Bengali Poetry')).toBeInTheDocument();
    expect(screen.getByText('Bengali Academy')).toBeInTheDocument();
  });

  it('says a revoked certificate was withdrawn, plainly', async () => {
    serve(
      verification({
        status: 'revoked',
        is_valid: false,
        is_revoked: true,
        revoked_at: '2026-09-05T10:00:00+00:00',
      }),
    );
    renderVerify();

    expect(await screen.findByText(/withdrawn/i)).toBeInTheDocument();
    expect(screen.getByText(/should not be relied on/i)).toBeInTheDocument();
    expect(screen.queryByText(/this certificate is valid/i)).not.toBeInTheDocument();
  });

  it('does not treat an expired certificate as a failure', async () => {
    /*
     * It was genuinely earned. Colouring or wording it like a forgery would
     * misrepresent an honest holder — the achievement is real, the
     * certificate is merely no longer current.
     */
    serve(
      verification({
        is_valid: false,
        has_expired: true,
        expires_at: '2026-09-05T10:00:00+00:00',
      }),
    );
    renderVerify();

    expect(await screen.findByText(/has expired/i)).toBeInTheDocument();
    expect(screen.getByText(/the achievement below is real/i)).toBeInTheDocument();
    expect(screen.queryByText(/withdrawn/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/should not be relied on/i)).not.toBeInTheDocument();
  });

  it('says we have no record, never that the certificate is fake', async () => {
    // We know we have no record. We do not know what the paper in their hand
    // is, and claiming otherwise would accuse somebody on no evidence.
    server.use(
      http.get(`${API}/verify/:tenant/:token`, () =>
        HttpResponse.json(
          { error: { code: 'not_found', message: 'Not found.', details: [], request_id: 'r' } },
          { status: 404 },
        ),
      ),
    );
    renderVerify();

    expect(await screen.findByText(/no record of this certificate/i)).toBeInTheDocument();
    expect(screen.queryByText(/fake|forged|invalid/i)).not.toBeInTheDocument();
  });

  it('distinguishes a server failure from a missing certificate', async () => {
    server.use(http.get(`${API}/verify/:tenant/:token`, () => HttpResponse.error()));
    renderVerify();

    expect(await screen.findByText(/could not check this certificate/i)).toBeInTheDocument();
    expect(screen.queryByText(/no record/i)).not.toBeInTheDocument();
  });

  it('tells the reader the page is authoritative, not the printout', async () => {
    serve(verification());
    renderVerify();

    expect(await screen.findByText(/this page is the authoritative record/i)).toBeInTheDocument();
  });
});
