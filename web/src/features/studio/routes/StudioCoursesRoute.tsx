import { Button, Container, Group, SegmentedControl, SimpleGrid, TextInput } from '@mantine/core';
import { useDebouncedValue } from '@mantine/hooks';
import { IconPlus, IconSearch } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Link } from 'react-router';

import { CourseCard } from '@/features/catalog/components/CourseCard';
import { EmptyState, ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import { studioCoursesQuery, type StudioFilters } from '../api/queries';

const STATUSES = [
  { value: '', label: 'All' },
  { value: 'draft', label: 'Draft' },
  { value: 'in_review', label: 'In review' },
  { value: 'published', label: 'Published' },
  { value: 'archived', label: 'Archived' },
];

export function StudioCoursesRoute() {
  const [status, setStatus] = useState('');
  const [search, setSearch] = useState('');
  const [debouncedSearch] = useDebouncedValue(search, 300);

  const filters: StudioFilters = {
    ...(status ? { status: status as StudioFilters['status'] } : {}),
    ...(debouncedSearch ? { q: debouncedSearch } : {}),
  };

  const { data, isPending, isError, error, refetch } = useQuery(studioCoursesQuery(filters));

  return (
    <Container size="xl" py="lg">
      <PageHeader
        title="Courses"
        description="Everything you are building or have published."
        actions={
          <Button component={Link} to="/studio/courses/new" leftSection={<IconPlus size={16} />}>
            New course
          </Button>
        }
      />

      <Group mb="lg" wrap="wrap">
        <SegmentedControl data={STATUSES} value={status} onChange={setStatus} size="sm" />
        <TextInput
          value={search}
          onChange={(event) => setSearch(event.currentTarget.value)}
          placeholder="Search your courses"
          leftSection={<IconSearch size={16} />}
          aria-label="Search your courses"
          style={{ flex: '1 1 200px' }}
        />
      </Group>

      {isPending ? <LoadingState rows={2} height={220} /> : null}
      {isError ? <ErrorState error={error} onRetry={() => void refetch()} /> : null}

      {data && data.data.length === 0 ? (
        <EmptyState
          title={
            status || debouncedSearch
              ? 'No courses match this filter'
              : "You haven't created a course yet"
          }
          description={
            status || debouncedSearch
              ? 'Try a different status or search term.'
              : 'Start with a title. You can fill in everything else as you go.'
          }
        >
          <Button component={Link} to="/studio/courses/new" mt="xs">
            Create your first course
          </Button>
        </EmptyState>
      ) : null}

      {data && data.data.length > 0 ? (
        <SimpleGrid cols={{ base: 1, sm: 2, md: 3, lg: 4 }}>
          {data.data.map((course) => (
            <CourseCard key={course.id} course={course} to={`/studio/courses/${course.id}`} />
          ))}
        </SimpleGrid>
      ) : null}
    </Container>
  );
}
