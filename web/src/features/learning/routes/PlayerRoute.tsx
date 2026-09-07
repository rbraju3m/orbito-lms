import {
  ActionIcon,
  Alert,
  Box,
  Button,
  Drawer,
  Group,
  Progress,
  Stack,
  Tabs,
  Text,
} from '@mantine/core';
import { useDisclosure, useMediaQuery } from '@mantine/hooks';
import {
  IconArrowLeft,
  IconArrowRight,
  IconCheck,
  IconLayoutSidebar,
  IconLock,
  IconX,
} from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useEffect } from 'react';
import { Link, useNavigate, useParams } from 'react-router';

import { ApiError } from '@/shared/api/errors';
import { ErrorState, LoadingState } from '@/shared/ui';

import {
  itemQuery,
  playerQuery,
  useCompleteCourse,
  useEnroll,
  useToggleItemComplete,
} from '../api/queries';
import type { LearnerItem } from '../api/types';
import { CurriculumPanel } from '../components/CurriculumPanel';
import { LessonPane } from '../components/LessonPane';
import { LockedPane } from '../components/LockedPane';
import { AssignmentPane } from '@/features/assignment/components/AssignmentPane';

import { QuizPane } from '../components/QuizPane';
import { NotesPanel } from '../components/NotesPanel';

export function PlayerRoute() {
  const { courseId = '', itemId } = useParams();
  const navigate = useNavigate();
  const isDesktop = useMediaQuery('(min-width: 75em)');
  const [drawerOpen, { open: openDrawer, close: closeDrawer }] = useDisclosure(false);

  const player = useQuery(playerQuery(courseId));
  const toggleComplete = useToggleItemComplete(courseId);
  const completeCourse = useCompleteCourse(courseId);
  const enroll = useEnroll();

  const allItems = player.data?.curriculum.flatMap((section) => section.items) ?? [];
  const resolvedItemId = itemId ?? player.data?.progress?.last_item_id ?? allItems[0]?.id ?? null;

  const item = useQuery({ ...itemQuery(resolvedItemId ?? ''), enabled: resolvedItemId !== null });

  // Land on the resume point rather than leaving the URL pointing at nothing.
  useEffect(() => {
    if (!itemId && resolvedItemId) {
      void navigate(`/learn/${courseId}/${resolvedItemId}`, { replace: true });
    }
  }, [itemId, resolvedItemId, courseId, navigate]);

  if (player.isPending) {
    return (
      <Box p="lg">
        <LoadingState rows={4} height={90} />
      </Box>
    );
  }

  if (player.isError) {
    return (
      <Box p="lg">
        <ErrorState
          error={player.error}
          onRetry={() => void player.refetch()}
          title="Course unavailable"
        />
      </Box>
    );
  }

  const { course, access, progress, curriculum } = player.data;
  const current = allItems.find((candidate) => candidate.id === resolvedItemId) ?? null;
  const locked = item.isError && item.error instanceof ApiError && item.error.isLocked;

  /*
   * When drip names the item standing in the way, the lock screen offers to go
   * there. The API returns its title, not its id, so this matches on title —
   * which is enough because the blocker is always in this course's outline.
   */
  const blockedByTitle =
    locked && item.error instanceof ApiError
      ? (item.error.meta.blocked_by_title as string | undefined)
      : undefined;
  const blocker = blockedByTitle
    ? (allItems.find((candidate) => candidate.title === blockedByTitle) ?? null)
    : null;

  const select = (next: LearnerItem) => {
    closeDrawer();
    void navigate(`/learn/${courseId}/${next.id}`);
  };

  const sidebar = (
    <CurriculumPanel
      sections={curriculum}
      activeItemId={resolvedItemId}
      progress={progress}
      hasAccess={access.granted}
      onSelect={select}
    />
  );

  return (
    <Box mih="100dvh" bg="var(--mantine-color-body)">
      {/* A distraction-free shell: the player is not the dashboard. */}
      <Group
        justify="space-between"
        px="md"
        h={56}
        bd="0 0 1px 0 solid var(--mantine-color-default-border)"
      >
        <Group gap="sm" style={{ minWidth: 0 }}>
          <ActionIcon
            variant="subtle"
            hiddenFrom="lg"
            onClick={openDrawer}
            aria-label="Open curriculum"
          >
            <IconLayoutSidebar size={18} />
          </ActionIcon>
          <Text fw={600} lineClamp={1}>
            {course.title}
          </Text>
        </Group>

        <Group gap="sm">
          {progress ? (
            <Group gap="xs" visibleFrom="sm">
              <Progress
                value={progress.percent}
                w={110}
                size="sm"
                aria-label={`${Math.round(progress.percent)} percent complete`}
              />
              <Text size="xs" c="dimmed">
                {Math.round(progress.percent)}%
              </Text>
            </Group>
          ) : null}

          <ActionIcon
            component={Link}
            to={`/courses/${course.slug}`}
            variant="subtle"
            aria-label="Leave the player"
          >
            <IconX size={18} />
          </ActionIcon>
        </Group>
      </Group>

      <Group align="stretch" gap={0} wrap="nowrap">
        {isDesktop ? (
          <Box
            w={320}
            style={{ flexShrink: 0 }}
            bd="0 1px 0 0 solid var(--mantine-color-default-border)"
            h="calc(100dvh - 56px)"
          >
            {sidebar}
          </Box>
        ) : null}

        <Box style={{ flex: 1, minWidth: 0 }} p="lg">
          <Stack gap="lg" maw={860} mx="auto">
            {!access.granted && !current?.is_preview ? (
              <Alert
                color="warning"
                icon={<IconLock size={16} />}
                title="You don't have access yet"
              >
                <Stack gap="sm" align="flex-start">
                  <Text size="sm">
                    {access.reason === 'enrollment_expired'
                      ? 'Your access to this course has expired.'
                      : 'Enrol to open the lessons in this course.'}
                  </Text>
                  {access.reason === 'not_enrolled' ? (
                    <Button
                      size="xs"
                      loading={enroll.isPending}
                      onClick={() => enroll.mutate(course.id)}
                    >
                      Enrol for free
                    </Button>
                  ) : null}
                </Stack>
              </Alert>
            ) : null}

            {item.isPending && resolvedItemId ? <LoadingState rows={3} height={80} /> : null}

            {locked ? (
              <LockedPane
                error={item.error}
                onOpenBlocker={blocker ? () => select(blocker) : undefined}
              />
            ) : null}

            {item.data && !locked ? (
              <>
                {item.data.type === 'quiz' ? (
                  <QuizPane
                    courseId={courseId}
                    item={item.data}
                    canAttempt={access.granted && !access.is_staff}
                  />
                ) : item.data.type === 'assignment' ? (
                  <AssignmentPane
                    itemId={item.data.id}
                    title={item.data.title}
                    canSubmit={access.granted && !access.is_staff}
                  />
                ) : (
                  <LessonPane item={item.data} resumeAt={current?.watch_position_seconds ?? 0} />
                )}

                <Group justify="space-between" wrap="wrap">
                  <Button
                    variant="default"
                    leftSection={<IconArrowLeft size={16} />}
                    disabled={!item.data.previous_id}
                    onClick={() => void navigate(`/learn/${courseId}/${item.data.previous_id}`)}
                  >
                    Previous
                  </Button>

                  <Group gap="xs">
                    {access.granted &&
                    !access.is_staff &&
                    current?.is_completable &&
                    current.is_self_markable ? (
                      <Button
                        variant={current.status === 'completed' ? 'light' : 'filled'}
                        color={current.status === 'completed' ? 'success' : undefined}
                        leftSection={<IconCheck size={16} />}
                        loading={toggleComplete.isPending}
                        onClick={() =>
                          toggleComplete.mutate({
                            itemId: current.id,
                            complete: current.status !== 'completed',
                          })
                        }
                      >
                        {current.status === 'completed' ? 'Completed' : 'Mark complete'}
                      </Button>
                    ) : null}

                    <Button
                      rightSection={<IconArrowRight size={16} />}
                      disabled={!item.data.next_id}
                      onClick={() => void navigate(`/learn/${courseId}/${item.data.next_id}`)}
                    >
                      Next
                    </Button>
                  </Group>
                </Group>

                {progress && !progress.is_complete && course.completion_mode === 'flexible' ? (
                  <Group justify="center">
                    <Button
                      variant="subtle"
                      loading={completeCourse.isPending}
                      onClick={() => completeCourse.mutate()}
                    >
                      Mark the whole course complete
                    </Button>
                  </Group>
                ) : null}

                {progress?.is_complete ? (
                  <Alert color="success" icon={<IconCheck size={16} />}>
                    You've completed this course.
                  </Alert>
                ) : null}

                <Tabs defaultValue="notes">
                  <Tabs.List>
                    <Tabs.Tab value="notes">Notes</Tabs.Tab>
                  </Tabs.List>
                  <Tabs.Panel value="notes" pt="md">
                    <NotesPanel
                      itemId={item.data.id}
                      canWrite={access.granted && !access.is_staff}
                    />
                  </Tabs.Panel>
                </Tabs>
              </>
            ) : null}
          </Stack>
        </Box>
      </Group>

      <Drawer
        opened={drawerOpen && !isDesktop}
        onClose={closeDrawer}
        title="Course content"
        size="sm"
        padding={0}
      >
        {sidebar}
      </Drawer>
    </Box>
  );
}
