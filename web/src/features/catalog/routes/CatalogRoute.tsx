import { Container, Group, Select, SimpleGrid, TextInput } from '@mantine/core';
import { useDebouncedValue } from '@mantine/hooks';
import { IconSearch } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useSearchParams } from 'react-router';

import { EmptyState, ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import { categoriesQuery, courseCatalogQuery } from '../api/queries';
import type { CatalogFilters } from '../api/types';
import { CourseCard } from '../components/CourseCard';

const LEVELS = [
  { value: 'beginner', label: 'Beginner' },
  { value: 'intermediate', label: 'Intermediate' },
  { value: 'advanced', label: 'Advanced' },
  { value: 'all', label: 'All levels' },
];

const SORTS = [
  { value: 'popular', label: 'Most popular' },
  { value: 'newest', label: 'Newest' },
  { value: 'rating', label: 'Highest rated' },
];

/**
 * Filters live in the URL, so a filtered catalogue is shareable and the back
 * button behaves (docs/DESIGN_SYSTEM.md §4).
 */
export function CatalogRoute() {
  const [params, setParams] = useSearchParams();
  const [debouncedQ] = useDebouncedValue(params.get('q') ?? '', 300);

  const filters: CatalogFilters = {
    ...(debouncedQ ? { q: debouncedQ } : {}),
    ...(params.get('category') ? { category: params.get('category')! } : {}),
    ...(params.get('level') ? { level: params.get('level') as CatalogFilters['level'] } : {}),
    ...(params.get('sort') ? { sort: params.get('sort') as CatalogFilters['sort'] } : {}),
  };

  const { data, isPending, isError, error, refetch } = useQuery(courseCatalogQuery(filters));
  const { data: categories } = useQuery(categoriesQuery());

  const setParam = (key: string, value: string | null) => {
    const next = new URLSearchParams(params);
    if (value) next.set(key, value);
    else next.delete(key);
    setParams(next, { replace: true });
  };

  return (
    <Container size="xl" py="lg">
      <PageHeader title="Courses" description="Browse everything published on Orbito." />

      <Group mb="lg" wrap="wrap">
        <TextInput
          value={params.get('q') ?? ''}
          onChange={(event) => setParam('q', event.currentTarget.value || null)}
          placeholder="Search courses"
          leftSection={<IconSearch size={16} />}
          aria-label="Search courses"
          style={{ flex: '1 1 220px' }}
        />
        <Select
          data={(categories ?? []).map((c) => ({ value: c.slug, label: c.name }))}
          value={params.get('category')}
          onChange={(value) => setParam('category', value)}
          placeholder="Any category"
          clearable
          aria-label="Category"
          w={180}
        />
        <Select
          data={LEVELS}
          value={params.get('level')}
          onChange={(value) => setParam('level', value)}
          placeholder="Any level"
          clearable
          aria-label="Level"
          w={150}
        />
        <Select
          data={SORTS}
          value={params.get('sort') ?? 'popular'}
          onChange={(value) => setParam('sort', value)}
          aria-label="Sort by"
          w={170}
        />
      </Group>

      {isPending ? <LoadingState rows={3} height={220} /> : null}
      {isError ? <ErrorState error={error} onRetry={() => void refetch()} /> : null}

      {data && data.data.length === 0 ? (
        <EmptyState
          title="No courses match these filters"
          description="Try widening your search."
          action={{ label: 'Clear filters', onClick: () => setParams({}, { replace: true }) }}
        />
      ) : null}

      {data && data.data.length > 0 ? (
        <SimpleGrid cols={{ base: 1, sm: 2, md: 3, lg: 4 }}>
          {data.data.map((course) => (
            <CourseCard key={course.id} course={course} />
          ))}
        </SimpleGrid>
      ) : null}
    </Container>
  );
}
