import { screen } from '@testing-library/react';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import type { FunnelItem } from '../api/types';
import { FunnelHeatmap } from './FunnelHeatmap';

const API = 'http://localhost:8000/api/v1';

function item(overrides: Partial<FunnelItem> = {}): FunnelItem {
  return {
    item_id: 'item-1',
    title: 'Opening lecture',
    type: 'lesson',
    position: 0,
    section_title: 'Week 1',
    started: 10,
    completed: 9,
    drop_off_rate: 0.1,
    avg_seconds: 125,
    computed_at: '2026-09-09T04:00:00+00:00',
    ...overrides,
  };
}

function serve(items: FunnelItem[]) {
  server.use(
    http.get(`${API}/analytics/courses/:id/funnel`, () =>
      HttpResponse.json({ data: { course: { id: 'c', title: 'Course' }, items } }),
    ),
  );
}

describe('FunnelHeatmap', () => {
  it('keeps curriculum order and flags the worst item', async () => {
    /*
     * "They drop out after the third video" is the insight, and a list sorted
     * by severity destroys the adjacency that makes it visible — so the
     * shading carries severity and the ORDER carries the course.
     */
    serve([
      item({ item_id: 'a', title: 'Opening lecture', position: 0, drop_off_rate: 0.1 }),
      item({
        item_id: 'b',
        title: 'The hard one',
        position: 1,
        drop_off_rate: 0.78,
        started: 9,
        completed: 2,
      }),
      item({ item_id: 'c', title: 'Wrap up', position: 2, drop_off_rate: 0.2 }),
    ]);

    renderWithRouter(<FunnelHeatmap courseId="course-uuid" />);

    const rows = await screen.findAllByRole('row');

    // Header plus three, in position order.
    expect(rows).toHaveLength(4);
    expect(rows[1]).toHaveTextContent('Opening lecture');
    expect(rows[2]).toHaveTextContent('The hard one');
    expect(rows[3]).toHaveTextContent('Wrap up');

    expect(screen.getByText('Biggest drop')).toBeInTheDocument();
  });

  it('prints the number beside the shading', async () => {
    // Colour alone is not information.
    serve([item({ drop_off_rate: 0.78 })]);
    renderWithRouter(<FunnelHeatmap courseId="course-uuid" />);

    expect(await screen.findByText('78%')).toBeInTheDocument();
  });

  it('shows a dash, not a zero, when there was nothing to measure', async () => {
    /*
     * "Nobody opened this" and "everybody left immediately" are different
     * facts, and a text lesson has no seconds to average at all.
     */
    serve([item({ avg_seconds: null })]);
    renderWithRouter(<FunnelHeatmap courseId="course-uuid" />);

    expect(await screen.findByText('—')).toBeInTheDocument();
  });

  it('says how stale the snapshot is', async () => {
    serve([item()]);
    renderWithRouter(<FunnelHeatmap courseId="course-uuid" />);

    expect(await screen.findByText(/^Computed /)).toBeInTheDocument();
  });

  it('explains an empty funnel rather than showing an empty table', async () => {
    serve([]);
    renderWithRouter(<FunnelHeatmap courseId="course-uuid" />);

    expect(await screen.findByText('No one has reached these lessons yet')).toBeInTheDocument();
  });
});
