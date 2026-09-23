import type { CatalogFilters, CourseLevel } from '@/features/catalog/api/types';
import { t } from '@/shared/i18n';

/*
 * Labels are GETTERS: these lists are built when the module loads, before the
 * reader's catalogue has arrived, and a plain string would stay English for
 * ever. A getter is read when the Select draws (docs/I18N.md §3a), and the
 * lists keep their shape for the public site that shares them.
 */

export const LEVELS: { value: CourseLevel; label: string }[] = [
  {
    value: 'beginner',
    get label() {
      return t('catalog.level.beginner', 'Beginner');
    },
  },
  {
    value: 'intermediate',
    get label() {
      return t('catalog.level.intermediate', 'Intermediate');
    },
  },
  {
    value: 'advanced',
    get label() {
      return t('catalog.level.advanced', 'Advanced');
    },
  },
  {
    value: 'all',
    get label() {
      return t('catalog.level.all', 'All levels');
    },
  },
];

export const PRICES: { value: NonNullable<CatalogFilters['price']>; label: string }[] = [
  {
    value: 'free',
    get label() {
      return t('catalog.price.free', 'Free');
    },
  },
  {
    value: 'paid',
    get label() {
      return t('catalog.price.paid', 'Paid');
    },
  },
];

/*
 * No price sort: a price is one row per currency, so the API accepts
 * `price_asc` and answers newest-first (CourseCatalogQuery::applySort).
 * Offering it here would be a control that does nothing.
 */
export const SORTS: { value: NonNullable<CatalogFilters['sort']>; label: string }[] = [
  {
    value: 'popular',
    get label() {
      return t('catalog.sort.popular', 'Most popular');
    },
  },
  {
    value: 'newest',
    get label() {
      return t('catalog.sort.newest', 'Newest');
    },
  },
  {
    value: 'rating',
    get label() {
      return t('catalog.sort.rating', 'Highest rated');
    },
  },
];

const pick = <T extends string>(value: string | null, allowed: { value: T }[]): T | undefined =>
  allowed.find((option) => option.value === value)?.value;

/**
 * The filters a course-list URL asks for, keeping only values the API
 * accepts. The URL is somebody's shared link — mistyped, truncated, or from
 * an older version of this page — and the API answers a bad value with a
 * 422. Dropping it shows the list; passing it on shows an error page.
 *
 * `q` is taken separately because the page debounces it. `category` only
 * where the page has a control for it: the public site has none (the
 * category list is members-only), and a filter nobody can see is one
 * nobody can clear.
 */
export function courseFiltersFromParams(
  params: URLSearchParams,
  q: string,
  { withCategory = false }: { withCategory?: boolean } = {},
): CatalogFilters {
  const level = pick(params.get('level'), LEVELS);
  const price = pick(params.get('price'), PRICES);
  const sort = pick(params.get('sort'), SORTS);
  const page = Number(params.get('page'));
  const query = q.trim().slice(0, 200);
  // A slug is not a closed set, so only its length can be checked here.
  const category = withCategory ? (params.get('category') ?? '').trim().slice(0, 120) : '';

  return {
    ...(query ? { q: query } : {}),
    ...(category ? { category } : {}),
    ...(level ? { level } : {}),
    ...(price ? { price } : {}),
    ...(sort ? { sort } : {}),
    ...(Number.isInteger(page) && page > 1 ? { page } : {}),
  };
}

/** Whether the reader narrowed the list — which decides what "empty" means. */
export function isFiltered(filters: CatalogFilters): boolean {
  return Boolean(filters.q ?? filters.category ?? filters.level ?? filters.price);
}
