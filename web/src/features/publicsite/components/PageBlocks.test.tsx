import { screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { CourseListItem } from '@/features/catalog/api/types';
import type { PageBlock } from '@/features/content/api/pageTypes';
import { courseFixture } from '@/shared/test/handlers';
import { renderWithRouter } from '@/shared/test/renderRoute';

import { PageBlocks } from './PageBlocks';

function render(blocks: PageBlock[]) {
  return renderWithRouter(<PageBlocks academy="dhaka-art-school" blocks={blocks} />);
}

describe('PageBlocks', () => {
  it('draws headings, saved HTML and both kinds of button', () => {
    render([
      { id: '1', type: 'heading', props: { text: 'About the school', level: 2 } },
      { id: '2', type: 'text', props: { html: '<p>We paint every <strong>day</strong>.</p>' } },
      { id: '3', type: 'button', props: { label: 'See the courses', url: '/a/dhaka-art-school' } },
      { id: '4', type: 'button', props: { label: 'Find us', url: 'https://maps.example/school' } },
    ]);

    expect(screen.getByRole('heading', { level: 2, name: 'About the school' })).toBeInTheDocument();
    expect(screen.getByText('day')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'See the courses' })).toHaveAttribute(
      'href',
      '/a/dhaka-art-school',
    );
    expect(screen.getByRole('link', { name: 'Find us' })).toHaveAttribute(
      'rel',
      'noopener noreferrer',
    );
  });

  it('links course cards and posts inside the academy site', () => {
    render([
      {
        id: '1',
        type: 'courses',
        props: { title: 'Start here', course_ids: ['c'] },
        data: [
          // The fixture is a JSON body, typed loosely for MSW; here it is a prop.
          {
            ...courseFixture({
              status: 'published',
              status_label: 'Published',
              title: 'Watercolour',
              slug: 'watercolour',
            }),
            ref: 7,
            price: null,
          } as unknown as CourseListItem,
        ],
      },
      {
        id: '2',
        type: 'posts',
        props: { title: 'News', limit: 3 },
        data: [
          {
            id: 'p',
            slug: 'open-day',
            title: 'Open day',
            excerpt: null,
            cover_url: null,
            published_at: '2026-09-01T08:00:00+00:00',
            reading_minutes: 2,
          },
        ],
      },
    ]);

    expect(screen.getByRole('heading', { name: 'Start here' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Open day' })).toHaveAttribute(
      'href',
      '/a/dhaka-art-school/blog/open-day',
    );
  });

  it('draws nothing for a block whose courses are all gone, rather than an empty frame', () => {
    render([
      { id: '1', type: 'courses', props: { title: 'Start here', course_ids: ['c'] }, data: [] },
    ]);

    expect(screen.queryByRole('heading', { name: 'Start here' })).toBeNull();
  });
});
