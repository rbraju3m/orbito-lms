import {
  ActionIcon,
  Alert,
  Badge,
  Button,
  Card,
  Drawer,
  Group,
  Modal,
  Pagination,
  Stack,
  Text,
  Title,
} from '@mantine/core';
import {
  IconAlertTriangle,
  IconCalendarEvent,
  IconPencil,
  IconPlus,
  IconTrash,
  IconUsers,
  IconVideo,
} from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';

import { ApiError } from '@/shared/api/errors';
import { formatDate, formatDateTime } from '@/shared/lib/datetime';
import { EmptyState, ErrorState, LoadingState } from '@/shared/ui';

import {
  cohortsQuery,
  courseSessionsQuery,
  rosterQuery,
  useCancelSession,
  useDeleteCohort,
  useMarkAttendance,
} from '../api/queries';
import type { Cohort, LiveSession } from '../api/types';
import { CohortForm } from './CohortForm';
import { SessionForm } from './SessionForm';

/** The zone a new session or run records — the scheduler's own. */
const BROWSER_ZONE = Intl.DateTimeFormat().resolvedOptions().timeZone;

/**
 * Scheduling a course's live sessions and its runs, in the studio.
 *
 * Nothing here is ever deleted that somebody's record depends on: a session is
 * CANCELLED (its roster and attendance stay), and a run with sessions or
 * learners offers no delete at all — the server says which, and refuses the
 * rest with 409. What may be done is the server's answer throughout
 * (`meta.can_manage`, `meta.providers`, `is_deletable`); this screen renders it.
 */
export function LiveManager({ courseId }: { courseId: string }) {
  const [sessionPage, setSessionPage] = useState(1);
  const [cohortPage, setCohortPage] = useState(1);
  const sessions = useQuery(courseSessionsQuery(courseId, sessionPage));
  const cohorts = useQuery(cohortsQuery(courseId, cohortPage));

  const [editingSession, setEditingSession] = useState<LiveSession | 'new' | null>(null);
  const [editingCohort, setEditingCohort] = useState<Cohort | 'new' | null>(null);
  const [rosterFor, setRosterFor] = useState<LiveSession | null>(null);

  if (sessions.isPending || cohorts.isPending) return <LoadingState rows={3} height={72} />;
  if (sessions.isError) {
    return <ErrorState error={sessions.error} onRetry={() => void sessions.refetch()} />;
  }
  if (cohorts.isError) {
    return <ErrorState error={cohorts.error} onRetry={() => void cohorts.refetch()} />;
  }

  if (!sessions.data.meta.can_manage) {
    return (
      <Alert color="gray" variant="light" role="note">
        You can see this course's live sessions, but not schedule them.
      </Alert>
    );
  }

  return (
    <Stack gap="xl">
      <Stack gap="sm">
        <Group justify="space-between" gap="sm">
          <Title order={4}>Sessions</Title>
          <Button
            size="compact-sm"
            leftSection={<IconPlus size={14} />}
            onClick={() => setEditingSession('new')}
          >
            Schedule a session
          </Button>
        </Group>
        <Text size="sm" c="dimmed">
          Times are in your time zone ({BROWSER_ZONE}). A session for one run is only for that run's
          learners; everybody else on the course never sees it.
        </Text>

        {sessions.data.data.length === 0 ? (
          <EmptyState
            icon={IconVideo}
            title="No sessions yet"
            description="Schedule a live class. Orbito keeps the roster, the reminders and the attendance; the meeting itself happens wherever the link points."
            action={{ label: 'Schedule a session', onClick: () => setEditingSession('new') }}
          />
        ) : (
          <Stack gap="xs">
            {sessions.data.data.map((session) => (
              <SessionRow
                key={session.id}
                session={session}
                onEdit={() => setEditingSession(session)}
                onRoster={() => setRosterFor(session)}
              />
            ))}
            {sessions.data.meta.last_page > 1 ? (
              <Group justify="center">
                <Pagination
                  value={sessionPage}
                  onChange={setSessionPage}
                  total={sessions.data.meta.last_page}
                />
              </Group>
            ) : null}
          </Stack>
        )}
      </Stack>

      <Stack gap="sm">
        <Group justify="space-between" gap="sm">
          <Title order={4}>Runs</Title>
          <Button
            size="compact-sm"
            variant="light"
            leftSection={<IconPlus size={14} />}
            onClick={() => setEditingCohort('new')}
          >
            New run
          </Button>
        </Group>
        <Text size="sm" c="dimmed">
          A run is a dated intake of this course — a start date, a capacity and a group. The course
          itself stays one course.
        </Text>

        {cohorts.data.data.length === 0 ? (
          <EmptyState
            icon={IconCalendarEvent}
            title="No runs"
            description="Without one, the course is self-paced and every session is for everybody on it."
            action={{ label: 'New run', onClick: () => setEditingCohort('new') }}
          />
        ) : (
          <Stack gap="xs">
            {cohorts.data.data.map((cohort) => (
              <CohortRow key={cohort.id} cohort={cohort} onEdit={() => setEditingCohort(cohort)} />
            ))}
            {cohorts.data.meta.last_page > 1 ? (
              <Group justify="center">
                <Pagination
                  value={cohortPage}
                  onChange={setCohortPage}
                  total={cohorts.data.meta.last_page}
                />
              </Group>
            ) : null}
          </Stack>
        )}
      </Stack>

      <Modal
        opened={editingSession !== null}
        onClose={() => setEditingSession(null)}
        title={editingSession === 'new' ? 'Schedule a session' : 'Edit session'}
        size="lg"
      >
        {editingSession !== null ? (
          <SessionForm
            key={editingSession === 'new' ? 'new' : editingSession.id}
            courseId={courseId}
            existing={editingSession === 'new' ? null : editingSession}
            providers={sessions.data.meta.providers}
            cohorts={cohorts.data.data}
            timezone={BROWSER_ZONE}
            onDone={() => setEditingSession(null)}
          />
        ) : null}
      </Modal>

      <Modal
        opened={editingCohort !== null}
        onClose={() => setEditingCohort(null)}
        title={editingCohort === 'new' ? 'New run' : 'Edit run'}
        size="lg"
      >
        {editingCohort !== null ? (
          <CohortForm
            key={editingCohort === 'new' ? 'new' : editingCohort.id}
            courseId={courseId}
            existing={editingCohort === 'new' ? null : editingCohort}
            timezone={BROWSER_ZONE}
            onDone={() => setEditingCohort(null)}
          />
        ) : null}
      </Modal>

      <Drawer
        opened={rosterFor !== null}
        onClose={() => setRosterFor(null)}
        title={rosterFor ? `Roster · ${rosterFor.title}` : 'Roster'}
        position="right"
        size="md"
      >
        {rosterFor !== null ? <RosterPanel sessionId={rosterFor.id} /> : null}
      </Drawer>
    </Stack>
  );
}

function SessionRow({
  session,
  onEdit,
  onRoster,
}: {
  session: LiveSession;
  onEdit: () => void;
  onRoster: () => void;
}) {
  const cancel = useCancelSession();
  const [confirming, setConfirming] = useState(false);
  const failure = cancel.error instanceof ApiError ? cancel.error.message : null;

  // Only a session that has not happened can move or be called off.
  const upcoming = session.status === 'scheduled';

  return (
    <Card withBorder>
      <Stack gap="xs">
        <Group justify="space-between" wrap="nowrap" align="flex-start" gap="md">
          <Stack gap={4} style={{ minWidth: 0 }}>
            <Group gap="xs" wrap="wrap">
              <Text fw={600} lineClamp={1}>
                {session.title}
              </Text>
              <Badge size="xs" variant="light" color={session.status === 'live' ? 'danger' : 'gray'}>
                {session.status_label}
              </Badge>
              {session.cohort ? (
                <Badge size="xs" variant="default">
                  {session.cohort.name}
                </Badge>
              ) : null}
            </Group>
            <Text size="sm" c="dimmed">
              {formatDateTime(session.starts_at)} – {formatDateTime(session.ends_at)}
              {` · scheduled in ${session.timezone}`}
            </Text>
            <Text size="xs" c="dimmed">
              {session.provider_label}
              {!session.has_link ? ' · no link yet' : ''}
            </Text>
          </Stack>

          <Group gap={4} wrap="nowrap">
            <ActionIcon
              variant="subtle"
              aria-label={`Roster for ${session.title}`}
              onClick={onRoster}
            >
              <IconUsers size={16} />
            </ActionIcon>
            {upcoming ? (
              <ActionIcon variant="subtle" aria-label={`Edit ${session.title}`} onClick={onEdit}>
                <IconPencil size={16} />
              </ActionIcon>
            ) : null}
          </Group>
        </Group>

        {upcoming && !confirming ? (
          <Group>
            <Button size="compact-xs" variant="subtle" color="red" onClick={() => setConfirming(true)}>
              Cancel session
            </Button>
          </Group>
        ) : null}

        {confirming ? (
          <Alert color="warning" icon={<IconAlertTriangle size={16} />}>
            <Stack gap="xs" align="flex-start">
              <Text size="sm">
                Learners see it as cancelled in their calendar. Nothing is deleted — the roster and
                any attendance stay.
              </Text>
              {failure ? (
                <Text size="sm" c="red">
                  {failure}
                </Text>
              ) : null}
              <Group gap="xs">
                <Button size="compact-xs" variant="subtle" onClick={() => setConfirming(false)}>
                  Keep it
                </Button>
                <Button
                  size="compact-xs"
                  color="red"
                  loading={cancel.isPending}
                  onClick={() =>
                    cancel.mutate(session.id, { onSuccess: () => setConfirming(false) })
                  }
                >
                  Cancel session
                </Button>
              </Group>
            </Stack>
          </Alert>
        ) : null}
      </Stack>
    </Card>
  );
}

function CohortRow({ cohort, onEdit }: { cohort: Cohort; onEdit: () => void }) {
  const remove = useDeleteCohort();
  const [confirming, setConfirming] = useState(false);
  const failure = remove.error instanceof ApiError ? remove.error.message : null;

  const places =
    cohort.capacity === null
      ? 'No place limit'
      : `${cohort.places_remaining ?? 0} of ${cohort.capacity} places left`;

  return (
    <Card withBorder>
      <Stack gap="xs">
        <Group justify="space-between" wrap="nowrap" align="flex-start" gap="md">
          <Stack gap={4} style={{ minWidth: 0 }}>
            <Group gap="xs" wrap="wrap">
              <Text fw={600} lineClamp={1}>
                {cohort.name}
              </Text>
              <Badge size="xs" variant="light" color={cohort.is_joinable ? 'green' : 'gray'}>
                {cohort.status_label}
              </Badge>
            </Group>
            <Text size="sm" c="dimmed">
              {formatDate(cohort.starts_at)}
              {cohort.ends_at ? ` – ${formatDate(cohort.ends_at)}` : ''}
              {cohort.enrollment_deadline
                ? ` · joins close ${formatDate(cohort.enrollment_deadline)}`
                : ''}
            </Text>
            <Text size="xs" c="dimmed">
              {places} · {cohort.enrollment_count ?? 0} learners · {cohort.session_count ?? 0}{' '}
              sessions
            </Text>
          </Stack>

          <Group gap={4} wrap="nowrap">
            <ActionIcon variant="subtle" aria-label={`Edit ${cohort.name}`} onClick={onEdit}>
              <IconPencil size={16} />
            </ActionIcon>
            {cohort.is_deletable ? (
              <ActionIcon
                variant="subtle"
                color="red"
                aria-label={`Delete ${cohort.name}`}
                onClick={() => setConfirming(true)}
              >
                <IconTrash size={16} />
              </ActionIcon>
            ) : null}
          </Group>
        </Group>

        {confirming ? (
          <Alert color="warning" icon={<IconAlertTriangle size={16} />}>
            <Stack gap="xs" align="flex-start">
              <Text size="sm">Nobody has joined this run and it has no sessions, so it can go.</Text>
              {failure ? (
                <Text size="sm" c="red">
                  {failure}
                </Text>
              ) : null}
              <Group gap="xs">
                <Button size="compact-xs" variant="subtle" onClick={() => setConfirming(false)}>
                  Keep it
                </Button>
                <Button
                  size="compact-xs"
                  color="red"
                  loading={remove.isPending}
                  onClick={() => remove.mutate(cohort.id, { onSuccess: () => setConfirming(false) })}
                >
                  Delete run
                </Button>
              </Group>
            </Stack>
          </Alert>
        ) : null}
      </Stack>
    </Card>
  );
}

/**
 * Who was there. A click on the join link is the record; the host's word is
 * the fallback for somebody who dialled in by phone or joined from a link
 * shared in the room.
 */
function RosterPanel({ sessionId }: { sessionId: string }) {
  const { data, isPending, isError, error, refetch } = useQuery(rosterQuery(sessionId));
  const mark = useMarkAttendance(sessionId);

  if (isPending) return <LoadingState rows={4} height={40} />;
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />;

  if (data.roster.length === 0) {
    return (
      <EmptyState
        icon={IconUsers}
        title="Nobody expected yet"
        description="Learners enrolled on the course — or on the session's run — appear here."
      />
    );
  }

  return (
    <Stack gap="sm">
      <Text size="sm" c="dimmed">
        {data.present} of {data.expected} attended.
      </Text>
      {data.roster.map((entry) => (
        <Group key={entry.user_id} justify="space-between" wrap="nowrap" gap="sm">
          <Stack gap={0} style={{ minWidth: 0 }}>
            <Text size="sm" lineClamp={1}>
              {entry.name}
            </Text>
            <Text size="xs" c="dimmed">
              {entry.attended
                ? `${entry.source === 'host' ? 'Marked present' : 'Joined'}${
                    entry.joined_at ? ` ${formatDateTime(entry.joined_at)}` : ''
                  }`
                : 'Not recorded'}
            </Text>
          </Stack>
          {entry.attended ? (
            <Badge size="xs" color="green" variant="light">
              Attended
            </Badge>
          ) : (
            <Button
              size="compact-xs"
              variant="light"
              loading={mark.isPending && mark.variables?.[0] === entry.user_id}
              onClick={() => mark.mutate([entry.user_id])}
            >
              Mark present
            </Button>
          )}
        </Group>
      ))}
    </Stack>
  );
}
