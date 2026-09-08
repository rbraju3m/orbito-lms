import {
  ActionIcon,
  Alert,
  Badge,
  Box,
  Button,
  Card,
  Container,
  Group,
  Menu,
  Stack,
  Text,
  Textarea,
  Title,
} from '@mantine/core';
import {
  IconArrowLeft,
  IconCheck,
  IconCircleCheck,
  IconDotsVertical,
  IconEyeOff,
  IconPin,
  IconTrash,
} from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Link, useParams } from 'react-router';

import { formatDateTime } from '@/shared/lib/datetime';
import { ErrorState, LoadingState } from '@/shared/ui';

import {
  discussionQuery,
  useAcceptAnswer,
  useDeleteReply,
  useModerateDiscussion,
  useReplyToDiscussion,
} from '../api/queries';
import type { Discussion, DiscussionReply } from '../api/types';

/**
 * One thread.
 *
 * Its own page rather than a pane inside the player, because it is what a
 * notification links to: somebody arriving from an email about a reply wants
 * the reply, not the lesson they happened to be on when they asked.
 */
export function DiscussionRoute() {
  const { courseId = '', discussionId = '' } = useParams();
  const { data, isPending, isError, error, refetch } = useQuery(discussionQuery(discussionId));

  if (isPending) {
    return (
      <Container size="md" py="lg">
        <LoadingState rows={3} height={80} />
      </Container>
    );
  }

  if (isError) {
    return (
      <Container size="md" py="lg">
        <ErrorState error={error} onRetry={() => void refetch()} title="Thread unavailable" />
      </Container>
    );
  }

  const replies = data.replies ?? [];
  // One level of nesting, exactly as the API models it: a reply's parent is
  // always a top-level reply, never another child.
  const topLevel = replies.filter((reply) => !reply.parent_id);

  return (
    <Container size="md" py="lg">
      <Stack gap="lg">
        <Group gap="xs">
          <ActionIcon
            component={Link}
            to={`/learn/${courseId}`}
            variant="subtle"
            aria-label="Back to the course"
          >
            <IconArrowLeft size={18} />
          </ActionIcon>
          <Text size="sm" c="dimmed">
            Back to the course
          </Text>
        </Group>

        <Stack gap="xs">
          <Group gap="xs" wrap="wrap">
            {data.is_pinned ? <IconPin size={16} /> : null}
            <Title order={2}>{data.title}</Title>
          </Group>

          <Group gap="xs">
            <Badge variant="light" size="sm">
              {data.type_label}
            </Badge>
            <Badge
              size="sm"
              variant="light"
              color={data.is_resolved ? 'success' : data.status === 'hidden' ? 'danger' : 'gray'}
            >
              {data.status_label}
            </Badge>
            <Text size="xs" c="dimmed">
              {data.author.name ?? 'Former member'} · {formatDateTime(data.created_at)}
              {data.item ? ` · ${data.item.title}` : ''}
            </Text>
          </Group>
        </Stack>

        {data.status === 'hidden' ? (
          <Alert color="danger" icon={<IconEyeOff size={16} />} title="Hidden">
            A moderator has hidden this thread. Only moderators can see it — its author cannot.
          </Alert>
        ) : null}

        <Card withBorder>
          <div className="orbito-prose" dangerouslySetInnerHTML={{ __html: data.body }} />
        </Card>

        <ModerationBar courseId={courseId} discussion={data} />

        <Stack gap="sm">
          <Title order={4}>
            {data.reply_count} {data.reply_count === 1 ? 'reply' : 'replies'}
          </Title>

          {topLevel.length === 0 ? (
            <Text size="sm" c="dimmed">
              Nobody has answered yet.
            </Text>
          ) : (
            topLevel.map((reply) => (
              <ReplyCard
                key={reply.id}
                courseId={courseId}
                discussion={data}
                reply={reply}
                nested={replies.filter((child) => child.parent_id === reply.id)}
              />
            ))
          )}
        </Stack>

        {data.viewer?.can_reply ? (
          <ReplyBox courseId={courseId} discussionId={data.id} label="Add a reply" />
        ) : null}
      </Stack>
    </Container>
  );
}

function ModerationBar({ courseId, discussion }: { courseId: string; discussion: Discussion }) {
  const moderate = useModerateDiscussion(courseId);

  if (!discussion.viewer?.can_moderate) return null;

  return (
    <Group gap="xs">
      <Button
        size="compact-xs"
        variant="default"
        leftSection={<IconPin size={14} />}
        loading={moderate.isPending}
        onClick={() => moderate.mutate({ id: discussion.id, is_pinned: !discussion.is_pinned })}
      >
        {discussion.is_pinned ? 'Unpin' : 'Pin'}
      </Button>

      <Button
        size="compact-xs"
        variant="default"
        color={discussion.status === 'hidden' ? undefined : 'danger'}
        leftSection={<IconEyeOff size={14} />}
        loading={moderate.isPending}
        onClick={() =>
          moderate.mutate({
            id: discussion.id,
            status: discussion.status === 'hidden' ? 'open' : 'hidden',
          })
        }
      >
        {discussion.status === 'hidden' ? 'Unhide' : 'Hide'}
      </Button>
    </Group>
  );
}

function ReplyCard({
  courseId,
  discussion,
  reply,
  nested,
}: {
  courseId: string;
  discussion: Discussion;
  reply: DiscussionReply;
  /** The one level of nesting the API allows — never a tree. */
  nested: DiscussionReply[];
}) {
  const [replying, setReplying] = useState(false);
  const accept = useAcceptAnswer(courseId);
  const remove = useDeleteReply(courseId, discussion.id);

  const isAccepted = discussion.accepted_reply_id === reply.id;
  // Only a question can be answered; a comment must not offer it.
  const canAccept = discussion.is_answerable && discussion.viewer?.can_accept === true;

  return (
    <Card withBorder bd={isAccepted ? '1px solid var(--mantine-color-success-5)' : undefined}>
      <Stack gap="xs">
        <Group justify="space-between" wrap="nowrap" align="flex-start">
          <Group gap="xs" wrap="wrap">
            <Text size="sm" fw={600}>
              {reply.author.name ?? 'Former member'}
            </Text>
            {/*
             * Read off a stored flag, not recomputed: somebody who answered as
             * an instructor and later lost the role still answered as one.
             */}
            {reply.is_instructor_reply ? (
              <Badge size="xs" variant="light">
                Course team
              </Badge>
            ) : null}
            {isAccepted ? (
              <Badge size="xs" color="success" leftSection={<IconCircleCheck size={11} />}>
                Accepted answer
              </Badge>
            ) : null}
            <Text size="xs" c="dimmed">
              {formatDateTime(reply.created_at)}
            </Text>
          </Group>

          <Menu position="bottom-end" withinPortal>
            <Menu.Target>
              <ActionIcon variant="subtle" size="sm" aria-label="Reply actions">
                <IconDotsVertical size={15} />
              </ActionIcon>
            </Menu.Target>
            <Menu.Dropdown>
              <Menu.Item onClick={() => setReplying(true)}>Reply</Menu.Item>

              {canAccept ? (
                <Menu.Item
                  leftSection={<IconCheck size={14} />}
                  onClick={() =>
                    accept.mutate({
                      id: discussion.id,
                      // Clicking the accepted answer un-accepts it.
                      ...(isAccepted ? {} : { replyId: reply.id }),
                    })
                  }
                >
                  {isAccepted ? 'Un-accept' : 'Accept as answer'}
                </Menu.Item>
              ) : null}

              {reply.author.is_you || discussion.viewer?.can_moderate ? (
                <Menu.Item
                  color="danger"
                  leftSection={<IconTrash size={14} />}
                  onClick={() => remove.mutate(reply.id)}
                >
                  Delete
                </Menu.Item>
              ) : null}
            </Menu.Dropdown>
          </Menu>
        </Group>

        <div className="orbito-prose" dangerouslySetInnerHTML={{ __html: reply.body }} />

        {nested.length > 0 ? (
          <Stack gap="xs" pl="md" bd="0 0 0 2px solid var(--mantine-color-default-border)">
            {nested.map((child) => (
              <Box key={child.id}>
                <Group gap="xs">
                  <Text size="sm" fw={600}>
                    {child.author.name ?? 'Former member'}
                  </Text>
                  {child.is_instructor_reply ? (
                    <Badge size="xs" variant="light">
                      Course team
                    </Badge>
                  ) : null}
                  <Text size="xs" c="dimmed">
                    {formatDateTime(child.created_at)}
                  </Text>
                </Group>
                <div className="orbito-prose" dangerouslySetInnerHTML={{ __html: child.body }} />
              </Box>
            ))}
          </Stack>
        ) : null}

        {replying ? (
          <ReplyBox
            courseId={courseId}
            discussionId={discussion.id}
            parentId={reply.id}
            label={`Reply to ${reply.author.name ?? 'this'}`}
            onDone={() => setReplying(false)}
          />
        ) : null}
      </Stack>
    </Card>
  );
}

function ReplyBox({
  courseId,
  discussionId,
  parentId,
  label,
  onDone,
}: {
  courseId: string;
  discussionId: string;
  parentId?: string;
  label: string;
  onDone?: () => void;
}) {
  const [body, setBody] = useState('');
  const reply = useReplyToDiscussion(courseId);

  return (
    <Stack gap="xs">
      <Textarea
        label={label}
        value={body}
        onChange={(event) => setBody(event.currentTarget.value)}
        minRows={3}
        autosize
        placeholder="Write your reply"
      />
      <Group justify="flex-end" gap="xs">
        {onDone ? (
          <Button size="compact-sm" variant="subtle" onClick={onDone}>
            Cancel
          </Button>
        ) : null}
        <Button
          size="compact-sm"
          disabled={body.trim() === ''}
          loading={reply.isPending}
          onClick={() =>
            reply.mutate(
              { id: discussionId, body, ...(parentId ? { parentId } : {}) },
              {
                onSuccess: () => {
                  setBody('');
                  onDone?.();
                },
              },
            )
          }
        >
          Reply
        </Button>
      </Group>
    </Stack>
  );
}
