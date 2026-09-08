import { Badge, Button, Card, Group, Pagination, Rating, Stack, Text } from '@mantine/core';
import { IconCheck, IconShieldCheck, IconX } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';

import { formatDateTime } from '@/shared/lib/datetime';
import { EmptyState, ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import { reviewQueueQuery, useModerateReview } from '../api/queries';
import type { Review } from '../api/types';

/**
 * Reviews waiting on a human.
 *
 * The queue holds only PENDING reviews, and it is only ever populated by
 * courses whose settings turn moderation on — so an empty queue is the normal
 * state, not a broken screen, and the empty copy says so.
 */
export function ReviewModerationRoute() {
  const [page, setPage] = useState(1);
  const { data, isPending, isError, error, refetch } = useQuery(reviewQueueQuery(page));

  if (isPending) return <LoadingState rows={3} />;
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />;

  if (data.data.length === 0) {
    return (
      <>
        <PageHeader title="Review moderation" />
        <EmptyState
          icon={IconShieldCheck}
          title="Nothing waiting"
          description="Reviews on courses with moderation switched on appear here before they publish."
        />
      </>
    );
  }

  return (
    <>
      <PageHeader
        title="Review moderation"
        description="Nothing here is visible on a course page yet."
      />

      <Stack gap="sm">
        {data.data.map((review) => (
          <PendingReview key={review.id} review={review} />
        ))}

        {data.meta.last_page > 1 ? (
          <Group justify="center" mt="sm">
            <Pagination value={page} onChange={setPage} total={data.meta.last_page} />
          </Group>
        ) : null}
      </Stack>
    </>
  );
}

function PendingReview({ review }: { review: Review }) {
  const moderate = useModerateReview();
  const pending = moderate.isPending && moderate.variables?.id === review.id;

  return (
    <Card withBorder>
      <Stack gap="xs">
        <Group justify="space-between" wrap="nowrap" align="flex-start">
          <Stack gap={2} style={{ minWidth: 0 }}>
            <Group gap="xs">
              <Rating value={review.rating} count={5} readOnly size="xs" />
              <Badge size="xs" variant="light" color="warning">
                {review.status_label}
              </Badge>
            </Group>
            {review.title ? <Text fw={600}>{review.title}</Text> : null}
            <Text size="xs" c="dimmed">
              {review.author.name ?? 'Former member'} · {formatDateTime(review.created_at)}
            </Text>
          </Stack>

          <Group gap="xs" wrap="nowrap">
            <Button
              size="compact-sm"
              color="success"
              leftSection={<IconCheck size={14} />}
              loading={pending && moderate.variables?.status === 'published'}
              onClick={() => moderate.mutate({ id: review.id, status: 'published' })}
            >
              Publish
            </Button>
            <Button
              size="compact-sm"
              variant="default"
              color="danger"
              leftSection={<IconX size={14} />}
              loading={pending && moderate.variables?.status === 'rejected'}
              onClick={() => moderate.mutate({ id: review.id, status: 'rejected' })}
            >
              Reject
            </Button>
          </Group>
        </Group>

        {review.body ? (
          <div className="orbito-prose" dangerouslySetInnerHTML={{ __html: review.body }} />
        ) : (
          <Text size="sm" c="dimmed">
            A rating with no words.
          </Text>
        )}
      </Stack>
    </Card>
  );
}
