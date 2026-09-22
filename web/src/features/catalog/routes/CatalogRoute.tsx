import { Container, Group, Select, SimpleGrid, Stack, Text, TextInput } from '@mantine/core';
import { useDebouncedValue } from '@mantine/hooks';
import { IconBook, IconSearch } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useRef } from 'react';
import { useSearchParams } from 'react-router';

import { EmptyState, ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import { categoriesQuery, courseCatalogQuery } from '../api/queries';
import { CourseCard } from '../components/CourseCard';
import { CoursePager } from '../components/CoursePager';
import { courseFiltersFromParams, isFiltered, LEVELS, PRICES, SORTS } from '../lib/courseFilters';

/**
 * Filters and the page number live in the URL, so a filtered catalogue is
 * shareable and the Back button steps through pages (docs/DESIGN_SYSTEM.md §4).
 * Same shape as the public list (`PublicCoursesRoute`), plus a category.
 */
export function CatalogRoute() {
  const [params, setParams] = useSearchParams();
  const [debouncedQ] = useDebouncedValue(params.get('q') ?? '', 300);
  const heading = useRef<HTMLHeadingElement>(null);

  const filters = courseFiltersFromParams(params, debouncedQ, { withCategory: true });
  const { data, isPending, isError, error, refetch, isPlaceholderData } = useQuery(
    courseCatalogQuery(filters),
  );
  const { data: categories } = useQuery(categoriesQuery());

  // Typing is not history: a filter replaces the entry, and returns to page 1.
  const setFilter = (key: string, value: string | null) => {
    const next = new URLSearchParams(params);
    if (value) next.set(key, value);
    else next.delete(key);
    next.delete('page');
    setParams(next, { replace: true });
  };

  // A page IS history, so Back returns to it. Focus goes to the heading, which
  // brings the top of the new page into view — the pager sits below the grid.
  const setPage = (page: number) => {
    const next = new URLSearchParams(params);
    if (page > 1) next.set('page', String(page));
    else next.delete('page');
    setParams(next);
    heading.current?.focus();
  };

  const filtered = isFiltered(filters);

  return (
    <Container size="xl" py="lg">
      <PageHeader
        title="Courses"
        description="Browse everything published on Orbito."
        titleRef={heading}
      />

      <Group mb="lg" wrap="wrap">
        <TextInput
          value={params.get('q') ?? ''}
          onChange={(event) => setFilter('q', event.currentTarget.value || null)}
          placeholder="Search courses"
          leftSection={<IconSearch size={16} />}
          aria-label="Search courses"
          maxLength={200}
          style={{ flex: '1 1 220px' }}
        />
        <Select
          data={(categories ?? []).map((c) => ({ value: c.slug, label: c.name }))}
          value={filters.category ?? null}
          onChange={(value) => setFilter('category', value)}
          placeholder="Any category"
          clearable
          aria-label="Category"
          w={{ base: '100%', xs: 180 }}
        />
        <Select
          data={LEVELS}
          value={filters.level ?? null}
          onChange={(value) => setFilter('level', value)}
          placeholder="Any level"
          clearable
          aria-label="Level"
          w={{ base: '100%', xs: 150 }}
        />
        <Select
          data={PRICES}
          value={filters.price ?? null}
          onChange={(value) => setFilter('price', value)}
          placeholder="Any price"
          clearable
          aria-label="Price"
          w={{ base: '100%', xs: 140 }}
        />
        <Select
          data={SORTS}
          value={filters.sort ?? 'popular'}
          onChange={(value) => setFilter('sort', value === 'popular' ? null : value)}
          aria-label="Sort by"
          allowDeselect={false}
          w={{ base: '100%', xs: 170 }}
        />
      </Group>

      {isPending ? <LoadingState rows={3} height={220} label="Loading courses" /> : null}
      {isError ? <ErrorState error={error} onRetry={() => void refetch()} /> : null}

      {/* A shared link to page 9 of a list that has since shrunk. */}
      {data && data.data.length === 0 && data.meta.total > 0 ? (
        <EmptyState
          icon={IconBook}
          title="This page is past the end of the list"
          description={`There are ${data.meta.total} courses, on fewer pages than that.`}
          action={{ label: 'Go to the first page', onClick: () => setPage(1) }}
        />
      ) : null}

      {data && data.meta.total === 0 && filtered ? (
        <EmptyState
          title="No courses match these filters"
          description="Try widening your search."
          action={{ label: 'Clear filters', onClick: () => setParams({}, { replace: true }) }}
        />
      ) : null}

      {data && data.meta.total === 0 && !filtered ? (
        <EmptyState
          icon={IconBook}
          title="No courses yet"
          description="Nothing has been published here so far."
        />
      ) : null}

      {data && data.data.length > 0 ? (
        <Stack gap="md">
          {/* Announced, so a search that narrows the grid is not silent. */}
          <Text size="sm" c="dimmed" aria-live="polite">
            {data.meta.total === 1 ? '1 course' : `${data.meta.total} courses`}
          </Text>

          <SimpleGrid
            cols={{ base: 1, sm: 2, md: 3, lg: 4 }}
            aria-busy={isPlaceholderData}
            style={{ opacity: isPlaceholderData ? 0.6 : 1 }}
          >
            {data.data.map((course) => (
              <CourseCard key={course.id} course={course} />
            ))}
          </SimpleGrid>

          <CoursePager page={filters.page ?? 1} total={data.meta.last_page} onChange={setPage} />
        </Stack>
      ) : null}
    </Container>
  );
}
