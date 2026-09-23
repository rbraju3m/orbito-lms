import { Button, Card, Center, Stack, Text, ThemeIcon, Title } from '@mantine/core';
import { IconCalendarClock, IconLock, IconPlayerPlay } from '@tabler/icons-react';

import { ApiError } from '@/shared/api/errors';
import { t } from '@/shared/i18n';
import { formatDateTime } from '@/shared/lib/datetime';

interface LockedPaneProps {
  error: unknown;
  /** Jump to the item standing in the way, when there is one. */
  onOpenBlocker?: (() => void) | undefined;
  onEnrol?: (() => void) | undefined;
}

/**
 * A 423 is not an error screen. The content exists and the learner could
 * legitimately reach it, so this says what would get them in — a date, an item
 * to finish, or enrolling — rather than apologising.
 *
 * The copy keys on `reason` (the machine code), never on `message`.
 */
export function LockedPane({ error, onOpenBlocker, onEnrol }: LockedPaneProps) {
  const apiError = error instanceof ApiError ? error : null;
  const reason = apiError?.reason;
  const meta = apiError?.meta ?? {};

  const blockedBy = typeof meta.blocked_by_title === 'string' ? meta.blocked_by_title : null;
  const unlocksAt = typeof meta.unlocks_at === 'string' ? meta.unlocks_at : null;

  const { icon, title, body, action } = describe({
    reason,
    blockedBy,
    unlocksAt,
    message: apiError?.message,
    onOpenBlocker,
    onEnrol,
  });

  return (
    <Card withBorder padding="xl">
      <Center>
        <Stack align="center" gap="sm" maw={440} ta="center">
          <ThemeIcon size={56} radius="xl" variant="light" color="gray">
            {icon}
          </ThemeIcon>

          <Title order={1} size="h4">
            {title}
          </Title>

          <Text c="dimmed" size="sm">
            {body}
          </Text>

          {action ? (
            <Button mt="xs" onClick={action.onClick}>
              {action.label}
            </Button>
          ) : null}
        </Stack>
      </Center>
    </Card>
  );
}

function describe({
  reason,
  blockedBy,
  unlocksAt,
  message,
  onOpenBlocker,
  onEnrol,
}: {
  reason: string | undefined;
  blockedBy: string | null;
  unlocksAt: string | null;
  message: string | undefined;
  onOpenBlocker: (() => void) | undefined;
  onEnrol: (() => void) | undefined;
}) {
  if (reason === 'drip_locked') {
    if (blockedBy) {
      return {
        icon: <IconPlayerPlay size={26} />,
        title: t('learning.locked.finish_previous_title', 'Finish the previous lesson first'),
        body: t(
          'learning.locked.finish_previous_body',
          'This unlocks once you have completed “{title}”.',
          { title: blockedBy },
        ),
        action: onOpenBlocker
          ? {
              label: t('learning.locked.go_to', 'Go to “{title}”', { title: blockedBy }),
              onClick: onOpenBlocker,
            }
          : null,
      };
    }

    return {
      icon: <IconCalendarClock size={26} />,
      title: t('learning.locked.not_yet_title', 'Not available yet'),
      body: unlocksAt
        ? t('learning.locked.unlocks_on', 'This lesson unlocks on {date}.', {
            date: formatDateTime(unlocksAt),
          })
        : t('learning.locked.not_released', 'This lesson has not been released yet.'),
      action: null,
    };
  }

  if (reason === 'not_enrolled') {
    return {
      icon: <IconLock size={26} />,
      title: t('learning.locked.enrol_title', 'Enrol to open this lesson'),
      body: t(
        'learning.locked.enrol_body',
        'You can preview parts of this course, but this lesson is for enrolled learners.',
      ),
      action: onEnrol ? { label: t('learning.locked.enrol', 'Enrol'), onClick: onEnrol } : null,
    };
  }

  if (reason === 'enrollment_expired') {
    return {
      icon: <IconCalendarClock size={26} />,
      title: t('learning.locked.expired_title', 'Your access has ended'),
      body: t(
        'learning.locked.expired_body',
        'Your enrolment in this course has expired. Ask the course team to extend it.',
      ),
      action: null,
    };
  }

  if (reason === 'enrollment_not_started') {
    return {
      icon: <IconCalendarClock size={26} />,
      title: t('learning.locked.not_started_title', 'Your access has not started'),
      body: unlocksAt
        ? t('learning.locked.opens_on', 'This course opens for you on {date}.', {
            date: formatDateTime(unlocksAt),
          })
        : t('learning.locked.not_opened', 'This course has not opened for you yet.'),
      action: null,
    };
  }

  if (reason === 'enrollment_suspended') {
    return {
      icon: <IconLock size={26} />,
      title: t('learning.locked.suspended_title', 'Your access is suspended'),
      body: t(
        'learning.locked.suspended_body',
        'Your enrolment has been suspended. Contact the course team to reinstate it.',
      ),
      action: null,
    };
  }

  return {
    icon: <IconLock size={26} />,
    title: t('learning.locked.not_available_title', 'Not available'),
    body: message ?? t('learning.locked.no_access', 'You do not have access to this content.'),
    action: null,
  };
}
