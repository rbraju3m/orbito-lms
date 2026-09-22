import type { CatalogFilters, CourseLevel } from '@/features/catalog/api/types';

export const LEVELS: { value: CourseLevel; label: string }[] = [
  { value: 'beginner', label: 'Beginner' },
  { value: 'intermediate', label: 'Intermediate' },
  { value: 'advanced', label: 'Advanced' },
  { value: 'all', label: 'All levels' },
];

export const PRICES: { value: NonNullable<CatalogFilters['price']>; label: string }[] = [
  { value: 'free', label: 'Free' },
  { value: 'paid', label: 'Paid' },
];

/*
 * No price sort: a price is one row per currency, so the API accepts
 * `price_asc` and answers newest-first (CourseCatalogQuery::applySort).
 * Offering it here would be a control that does nothing.
 */
export const SORTS: { value: NonNullable<CatalogFilters['sort']>; label: string }[] = [
  { value: 'popular', label: 'Most popular' },
  { value: 'newest', label: 'Newest' },
  { value: 'rating', label: 'Highest rated' },
];

const pick = <T extends string>(value: string | null, allowed: { value: T }[]): T | undefined =>
  allowed.find((option) => option.value === value)?.value;

/**
 * The filters a public course URL asks for, keeping only values the API
 * accepts. The URL is somebody's shared link — mistyped, truncated, or from
 * an older version of this page — and the API answers a bad value with a
 * 422. Dropping it shows the list; passing it on shows an error page.
 *
 * `q` is taken separately because the page debounces it.
 */
export function courseFiltersFromParams(params: URLSearchParams, q: string): CatalogFilters {
  const level = pick(params.get('level'), LEVELS);
  const price = pick(params.get('price'), PRICES);
  const sort = pick(params.get('sort'), SORTS);
  const page = Number(params.get('page'));
  const query = q.trim().slice(0, 200);

  return {
    ...(query ? { q: query } : {}),
    ...(level ? { level } : {}),
    ...(price ? { price } : {}),
    ...(sort ? { sort } : {}),
    ...(Number.isInteger(page) && page > 1 ? { page } : {}),
  };
}

/** Whether the reader narrowed the list — which decides what "empty" means. */
export function isFiltered(filters: CatalogFilters): boolean {
  return Boolean(filters.q ?? filters.level ?? filters.price);
}
