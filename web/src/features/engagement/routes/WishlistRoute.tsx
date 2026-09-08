import { Group, Pagination, SimpleGrid, Stack } from '@mantine/core';
import { IconBookmark } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useNavigate } from 'react-router';

import { CourseCard } from '@/features/catalog/components/CourseCard';
import { EmptyState, ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import { wishlistQuery } from '../api/queries';

/**
 * Courses somebody means to come back to.
 *
 * It EMPTIES ITSELF when the wish is granted: enrolling removes the entry
 * server-side, because a wishlist of things you already have is noise. That
 * is why there is no "enrolled" badge here — an enrolled course is simply not
 * on the list.
 */
export function WishlistRoute() {
  const navigate = useNavigate();
  const [page, setPage] = useState(1);
  const { data, isPending, isError, error, refetch } = useQuery(wishlistQuery(page));

  if (isPending) return <LoadingState rows={2} height={160} />;
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />;

  if (data.data.length === 0) {
    return (
      <>
        <PageHeader title="Saved courses" />
        <EmptyState
          icon={IconBookmark}
          title="Nothing saved yet"
          description="Save a course from its page and it waits for you here until you enrol."
          action={{ label: 'Browse courses', onClick: () => void navigate('/courses') }}
        />
      </>
    );
  }

  return (
    <>
      <PageHeader title="Saved courses" description="Waiting for you to come back." />

      <Stack gap="md">
        <SimpleGrid cols={{ base: 1, sm: 2, lg: 3 }} spacing="md">
          {data.data.map((item) =>
            item.course ? <CourseCard key={item.course.id} course={item.course} /> : null,
          )}
        </SimpleGrid>

        {data.meta.last_page > 1 ? (
          <Group justify="center">
            <Pagination value={page} onChange={setPage} total={data.meta.last_page} />
          </Group>
        ) : null}
      </Stack>
    </>
  );
}
