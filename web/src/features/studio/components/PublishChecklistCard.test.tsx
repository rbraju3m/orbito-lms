import { screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { checklistFixture } from '@/shared/test/handlers';
import { renderWithProviders } from '@/shared/test/render';

import { PublishChecklistCard } from './PublishChecklistCard';

describe('PublishChecklistCard', () => {
  it('reports readiness when every blocking check passes', () => {
    renderWithProviders(<PublishChecklistCard checks={checklistFixture(true)} />);

    expect(screen.getByText('Ready')).toBeInTheDocument();
  });

  it('counts only the blocking failures', () => {
    renderWithProviders(<PublishChecklistCard checks={checklistFixture(false)} />);

    // Two blocking checks fail; the advisory thumbnail one must not be counted.
    expect(screen.getByText('2 to fix')).toBeInTheDocument();
  });

  it('separates advisory suggestions from blockers', () => {
    renderWithProviders(<PublishChecklistCard checks={checklistFixture(true)} />);

    expect(screen.getByText('Recommended')).toBeInTheDocument();
    expect(screen.getByText(/thumbnail makes the course/i)).toBeInTheDocument();
  });

  it('renders the server messages verbatim rather than its own copy', () => {
    const checks = checklistFixture(false);
    renderWithProviders(<PublishChecklistCard checks={checks} />);

    // The checklist the user reads must be the rules the server enforces.
    for (const check of checks) {
      expect(screen.getByText(check.message)).toBeInTheDocument();
    }
  });
});
