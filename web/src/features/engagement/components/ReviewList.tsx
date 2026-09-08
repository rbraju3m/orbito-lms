import {
  ActionIcon,
  Alert,
  Badge,
  Button,
  Card,
  Group,
  Pagination,
  Rating,
  Stack,
  Text,
  Textarea,
  Title,
} from '@mantine/core';
import { IconMessage2, IconPencil, IconStar, IconTrash } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';

import { formatDate } from '@/shared/lib/datetime';
import { EmptyState, ErrorState, LoadingState } from '@/shared/ui';

import { reviewsQuery, useDeleteReview, useReplyToReview } from '../api/queries';
import type { Review } from '../api/types';
import { ReviewForm } from './ReviewForm';

/**
 * A course's reviews, and the form to write one.
 *
 * `meta.can_review` decides whether the form appears at all. It is computed
 * from the two conditions SubmitReview itself enforces — reviews enabled, and
 * an enrolment of any status — so the page never offers a form the server
 * will refuse.
 */
export function ReviewList({
  courseId,
  canReply = false,
}: {
  courseId: string;
  canReply?: boolean;
}) {
  const [page, setPage] = useState(1);
  const [writing, setWriting] = useState(false);
  const { data, isPending, isError, error, refetch } = useQuery(reviewsQuery(courseId, page));

  if (isPending) return <LoadingState rows={2} height={90} />;
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />;

  const mine = data.data.find((review) => review.author.is_you);
  const canReview = data.meta.can_review;

  return (
    <Stack gap="md">
      <Group justify="space-between" align="center">
        <Title order={3}>Reviews</Title>
        {canReview && !writing ? (
          <Button
            size="compact-sm"
            variant="light"
            leftSection={<IconPencil size={14} />}
            onClick={() => setWriting(true)}
          >
            {mine ? 'Edit your review' : 'Write a review'}
          </Button>
        ) : null}
      </Group>

      {writing ? (
        <Card withBorder>
          <ReviewForm courseId={courseId} existing={mine} onDone={() => setWriting(false)} />
        </Card>
      ) : null}

      {data.data.length === 0 ? (
        <EmptyState
          icon={IconStar}
          title="No reviews yet"
          description={
            canReview
              ? 'You took this course — be the first to say what it was like.'
              : 'Reviews appear here once learners who took the course write them.'
          }
          {...(canReview && !writing
            ? { action: { label: 'Write a review', onClick: () => setWriting(true) } }
            : {})}
        />
      ) : (
        <Stack gap="sm">
          {data.data.map((review) => (
            <ReviewCard
              key={review.id}
              review={review}
              courseId={courseId}
              canReply={canReply}
              onEdit={() => setWriting(true)}
            />
          ))}

          {data.meta.last_page > 1 ? (
            <Group justify="center" mt="sm">
              <Pagination value={page} onChange={setPage} total={data.meta.last_page} />
            </Group>
          ) : null}
        </Stack>
      )}
    </Stack>
  );
}

function ReviewCard({
  review,
  courseId,
  canReply,
  onEdit,
}: {
  review: Review;
  courseId: string;
  canReply: boolean;
  onEdit: () => void;
}) {
  const [replying, setReplying] = useState(false);
  const [reply, setReply] = useState(review.instructor_reply ?? '');
  const remove = useDeleteReview(courseId);
  const respond = useReplyToReview(courseId);

  return (
    <Card withBorder>
      <Stack gap="xs">
        <Group justify="space-between" wrap="nowrap" align="flex-start">
          <Stack gap={2} style={{ minWidth: 0 }}>
            <Group gap="xs">
              <Rating value={review.rating} count={5} readOnly size="xs" />
              {review.author.is_you ? (
                <Badge size="xs" variant="light">
                  You
                </Badge>
              ) : null}
              {/*
               * Said plainly. A learner whose review is sitting in moderation
               * must see that it has not appeared — discovering it silently
               * vanished is worse than being told it is pending.
               */}
              {!review.is_published ? (
                <Badge
                  size="xs"
                  variant="light"
                  color={review.status === 'rejected' ? 'danger' : 'warning'}
                >
                  {review.status_label}
                </Badge>
              ) : null}
            </Group>

            {review.title ? <Text fw={600}>{review.title}</Text> : null}

            <Text size="xs" c="dimmed">
              {review.author.name ?? 'Former member'} ·{' '}
              {formatDate(review.published_at ?? review.created_at)}
            </Text>
          </Stack>

          {review.author.is_you ? (
            <Group gap={4} wrap="nowrap">
              <ActionIcon variant="subtle" aria-label="Edit review" onClick={onEdit}>
                <IconPencil size={16} />
              </ActionIcon>
              <ActionIcon
                variant="subtle"
                color="danger"
                aria-label="Delete review"
                loading={remove.isPending}
                onClick={() => remove.mutate(review.id)}
              >
                <IconTrash size={16} />
              </ActionIcon>
            </Group>
          ) : null}
        </Group>

        {review.body ? (
          <div className="orbito-prose" dangerouslySetInnerHTML={{ __html: review.body }} />
        ) : null}

        {review.instructor_reply ? (
          <Alert
            color="gray"
            variant="light"
            icon={<IconMessage2 size={16} />}
            title="Response from the course team"
          >
            <Text size="sm">{review.instructor_reply}</Text>
            {review.replied_at ? (
              <Text size="xs" c="dimmed" mt={4}>
                {formatDate(review.replied_at)}
              </Text>
            ) : null}
          </Alert>
        ) : null}

        {canReply ? (
          replying ? (
            <Stack gap="xs">
              <Textarea
                label="Your response"
                value={reply}
                onChange={(event) => setReply(event.currentTarget.value)}
                minRows={2}
                autosize
              />
              <Group gap="xs" justify="flex-end">
                <Button size="compact-sm" variant="subtle" onClick={() => setReplying(false)}>
                  Cancel
                </Button>
                <Button
                  size="compact-sm"
                  loading={respond.isPending}
                  onClick={() =>
                    respond.mutate(
                      { id: review.id, reply: reply.trim() === '' ? null : reply },
                      { onSuccess: () => setReplying(false) },
                    )
                  }
                >
                  Respond
                </Button>
              </Group>
            </Stack>
          ) : (
            <Group>
              <Button
                size="compact-xs"
                variant="subtle"
                leftSection={<IconMessage2 size={14} />}
                onClick={() => setReplying(true)}
              >
                {review.instructor_reply ? 'Edit response' : 'Respond'}
              </Button>
            </Group>
          )
        ) : null}
      </Stack>
    </Card>
  );
}
