import { screen } from '@testing-library/react';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import type { Certificate } from '../api/types';
import { CertificatesRoute } from './CertificatesRoute';

const API = 'http://localhost:8000/api/v1';

function certificate(overrides: Partial<Certificate> = {}): Certificate {
  return {
    id: 'cert-uuid',
    number: 'CERT-2026-ABCDEFGHIJ',
    status: 'issued',
    status_label: 'Issued',
    is_valid: true,
    has_expired: false,
    issued_at: '2026-09-01T10:00:00+00:00',
    expires_at: null,
    revoked_at: null,
    revoked_reason: null,
    learner_name: 'Rumi Haque',
    course_title: 'Modern Bengali Poetry',
    academy_name: 'Bengali Academy',
    completed_at: '2026-08-30T10:00:00+00:00',
    has_pdf: true,
    verification_url: 'https://orbito.test/verify/tenant-id/' + 'a'.repeat(32),
    ...overrides,
  };
}

function serve(rows: Certificate[]) {
  server.use(
    http.get(`${API}/certificates`, () =>
      HttpResponse.json({
        data: rows,
        meta: { current_page: 1, per_page: 15, total: rows.length, last_page: 1 },
        links: { first: null, prev: null, next: null, last: null },
      }),
    ),
  );
}

describe('CertificatesRoute', () => {
  it('leads with the shareable link, because that is what proves the claim', async () => {
    serve([certificate()]);
    renderWithRouter(<CertificatesRoute />);

    expect(await screen.findByText('Modern Bengali Poetry')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /share/i })).toBeInTheDocument();
    expect(screen.getByText('Valid')).toBeInTheDocument();
  });

  it('says the PDF is preparing rather than offering a dead button', async () => {
    /*
     * A certificate is VALID before its PDF exists — the render is queued.
     * A greyed-out download with no explanation is the dead end this codebase
     * keeps refusing.
     */
    serve([certificate({ has_pdf: false })]);
    renderWithRouter(<CertificatesRoute />);

    expect(await screen.findByText(/pdf preparing/i)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /pdf/i })).not.toBeInTheDocument();
    // Still shareable, still valid.
    expect(screen.getByRole('button', { name: /share/i })).toBeInTheDocument();
    expect(screen.getByText('Valid')).toBeInTheDocument();
  });

  it('shows a revoked certificate and its reason rather than hiding it', async () => {
    // A revoked certificate the holder cannot see the status of is a nasty
    // surprise at an interview.
    serve([
      certificate({
        status: 'revoked',
        is_valid: false,
        revoked_at: '2026-09-05T10:00:00+00:00',
        revoked_reason: 'Issued in error.',
      }),
    ]);
    renderWithRouter(<CertificatesRoute />);

    expect(await screen.findByText('Revoked')).toBeInTheDocument();
    expect(screen.getByText('Issued in error.')).toBeInTheDocument();
  });

  it('colours an expired certificate differently from a revoked one', async () => {
    serve([certificate({ is_valid: false, has_expired: true })]);
    renderWithRouter(<CertificatesRoute />);

    expect(await screen.findByText('Expired')).toBeInTheDocument();
    expect(screen.queryByText('Revoked')).not.toBeInTheDocument();
  });

  it('points somewhere useful when there is nothing yet', async () => {
    serve([]);
    renderWithRouter(<CertificatesRoute />);

    expect(await screen.findByText(/no certificates yet/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /my learning/i })).toBeInTheDocument();
  });

  it('shows an error state rather than an empty list when the request fails', async () => {
    server.use(http.get(`${API}/certificates`, () => HttpResponse.error()));
    renderWithRouter(<CertificatesRoute />);

    expect(await screen.findByText(/something went wrong/i)).toBeInTheDocument();
  });
});
