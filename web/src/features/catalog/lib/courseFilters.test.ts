import { describe, expect, it } from 'vitest';

import { courseFiltersFromParams, isFiltered } from './courseFilters';

const parse = (query: string, q = '') => courseFiltersFromParams(new URLSearchParams(query), q);

describe('courseFiltersFromParams', () => {
  it('keeps every value the API accepts', () => {
    expect(parse('level=advanced&price=free&sort=rating&page=3', 'ink')).toEqual({
      q: 'ink',
      level: 'advanced',
      price: 'free',
      sort: 'rating',
      page: 3,
    });
  });

  // The API answers each of these with a 422; a shared link should still open.
  it('drops what the API would refuse', () => {
    expect(parse('level=expert&price=cheap&sort=price_asc&page=-2')).toEqual({});
    expect(parse('page=1.5')).toEqual({});
    expect(parse('page=abc')).toEqual({});
  });

  it('leaves page 1 out, so page 1 has one cache entry', () => {
    expect(parse('page=1')).toEqual({});
  });

  it('reads a category only where the page offers one', () => {
    expect(parse('category=design')).toEqual({});
    expect(
      courseFiltersFromParams(new URLSearchParams('category=design'), '', { withCategory: true }),
    ).toEqual({ category: 'design' });
  });

  it('trims the search and caps it at the length the API accepts', () => {
    expect(parse('', '   ')).toEqual({});
    expect(parse('', 'x'.repeat(250)).q).toHaveLength(200);
  });
});

describe('isFiltered', () => {
  it('counts a narrowing filter, not the sort or the page', () => {
    expect(isFiltered({ sort: 'newest', page: 2 })).toBe(false);
    expect(isFiltered({ level: 'beginner' })).toBe(true);
    expect(isFiltered({ price: 'paid' })).toBe(true);
    expect(isFiltered({ q: 'ink' })).toBe(true);
    expect(isFiltered({ category: 'design' })).toBe(true);
  });
});
