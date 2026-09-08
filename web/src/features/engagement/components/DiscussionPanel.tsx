import { Badge, Button, Card, Group, Stack, Switch, Text } from '@mantine/core';
import { IconMessageQuestion, IconPin } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Link } from 'react-router';

import { formatDate } from '@/shared/lib/datetime';
import { EmptyState, ErrorState, LoadingState } from '@/shared/ui';

import { discussionsQuery } from '../api/queries';
import type { Discussion } from '../api/types';
import { AskQuestionForm } from './AskQuestionForm';

/**
 * The Q&A list, inside the player.
 *
 * It scopes to the lesson being watched by default, because a question asked
 * while stuck on lesson 12 is about lesson 12 — but the whole course is one
 * switch away, since the answer often is not.
 *
 * `meta.can_ask` comes from the same policy the write endpoint authorizes
 * against, so the ask box appears exactly when posting would be accepted.
 */
export function DiscussionPanel({ courseId, itemId }: { courseId: string; itemId?: string }) {
  const [thisLessonOnly, setThisLessonOnly] = useState(itemId !== undefined);
  const [asking, setAsking] = useState(false);

  const scopedItemId = thisLessonOnly ? itemId : undefined;
  const { data, isPending, isError, error, refetch } = useQuery(
    discussionsQuery(courseId, scopedItemId),
  );

  if (isPending) return <LoadingState rows={2} height={64} />;
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />;

  return (
    <Stack gap="md">
      <Group justify="space-between" wrap="wrap" gap="xs">
        {itemId ? (
          <Switch
            size="sm"
            checked={thisLessonOnly}
            onChange={(event) => setThisLessonOnly(event.currentTarget.checked)}
            label="Only this lesson"
          />
        ) : (
          <span />
        )}

        {data.meta.can_ask && !asking ? (
          <Button size="compact-sm" variant="light" onClick={() => setAsking(true)}>
            Ask a question
          </Button>
        ) : null}
      </Group>

      {asking ? (
        <Card withBorder>
          <AskQuestionForm
            courseId={courseId}
            itemId={scopedItemId}
            onDone={() => setAsking(false)}
          />
        </Card>
      ) : null}

      {data.data.length === 0 ? (
        <EmptyState
          icon={IconMessageQuestion}
          title={thisLessonOnly ? 'No questions on this lesson' : 'No questions yet'}
          description={
            data.meta.can_ask
              ? 'If something is unclear, asking here reaches the people teaching it.'
              : 'Questions from learners appear here.'
          }
          {...(data.meta.can_ask && !asking
            ? { action: { label: 'Ask a question', onClick: () => setAsking(true) } }
            : {})}
        />
      ) : (
        <Stack gap="xs">
          {data.data.map((discussion) => (
            <ThreadRow key={discussion.id} courseId={courseId} discussion={discussion} />
          ))}
        </Stack>
      )}
    </Stack>
  );
}

function ThreadRow({ courseId, discussion }: { courseId: string; discussion: Discussion }) {
  return (
    <Card
      withBorder
      padding="sm"
      component={Link}
      to={`/learn/${courseId}/discussions/${discussion.id}`}
    >
      <Group justify="space-between" wrap="nowrap" align="flex-start" gap="sm">
        <Stack gap={2} style={{ minWidth: 0 }}>
          <Group gap="xs" wrap="nowrap">
            {discussion.is_pinned ? <IconPin size={14} /> : null}
            <Text fw={600} lineClamp={1}>
              {discussion.title}
            </Text>
          </Group>

          <Text size="xs" c="dimmed" lineClamp={1}>
            {discussion.author.name ?? 'Former member'} · {formatDate(discussion.created_at)}
            {discussion.item ? ` · ${discussion.item.title}` : ''}
          </Text>
        </Stack>

        <Group gap="xs" wrap="nowrap">
          {/*
           * Resolved is the state worth showing: it tells a reader with the
           * same problem that there is an answer down there.
           */}
          {discussion.is_resolved ? (
            <Badge size="xs" color="success" variant="light">
              Resolved
            </Badge>
          ) : null}
          {discussion.status === 'hidden' ? (
            <Badge size="xs" color="danger" variant="light">
              Hidden
            </Badge>
          ) : null}
          <Badge size="xs" variant="default">
            {/* The stored counter, never a subquery per row (§10). */}
            {discussion.reply_count}
          </Badge>
        </Group>
      </Group>
    </Card>
  );
}
