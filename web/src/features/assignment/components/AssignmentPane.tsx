import { Alert, Anchor, Badge, Box, Card, Divider, Group, Stack, Text, Title } from '@mantine/core';
import { IconAlertCircle, IconLock, IconPaperclip } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';

import { formatDateTime } from '@/shared/lib/datetime';
import { ErrorState, LoadingState } from '@/shared/ui';

import { assignmentBriefQuery } from '../api/queries';
import type { Submission } from '../api/types';

import { SubmissionForm } from './SubmissionForm';

export interface AssignmentPaneProps {
  itemId: string;
  title: string;
  /** Staff can read the brief but have no enrollment to hand work in against. */
  canSubmit: boolean;
}

export function AssignmentPane({ itemId, title, canSubmit }: AssignmentPaneProps) {
  const { data, isPending, isError, error, refetch } = useQuery(assignmentBriefQuery(itemId));

  if (isPending) return <LoadingState rows={3} height={90} />;
  if (isError) {
    return (
      <ErrorState error={error} onRetry={() => void refetch()} title="Assignment unavailable" />
    );
  }

  const { assignment, submissions, rules } = data;

  return (
    <Stack gap="lg">
      <Stack gap="xs">
        <Title order={2}>{title}</Title>
        <Group gap="xs">
          <Badge variant="light">{assignment.total_points} marks</Badge>
          {assignment.due_at ? (
            <Badge variant="light" color={rules.is_past_due ? 'danger' : 'gray'}>
              Due {formatDateTime(assignment.due_at)}
            </Badge>
          ) : null}
          <Badge variant="light" color="gray">
            {assignment.max_attempts === null
              ? 'Unlimited attempts'
              : `${rules.attempts_used} of ${assignment.max_attempts} attempts used`}
          </Badge>
        </Group>
      </Stack>

      {assignment.instructions ? (
        // The server sanitises on write; this renders what it stored.
        <Box
          className="orbito-prose"
          dangerouslySetInnerHTML={{ __html: assignment.instructions }}
        />
      ) : (
        <Text c="dimmed" size="sm">
          No instructions were written for this assignment.
        </Text>
      )}

      {assignment.attachments && assignment.attachments.length > 0 ? (
        <Stack gap="xs">
          <Text size="sm" fw={600}>
            Files from your instructor
          </Text>
          {assignment.attachments.map((file) => (
            <Group key={file.id} gap="xs">
              <IconPaperclip size={14} />
              <Anchor href={file.url} size="sm" target="_blank" rel="noreferrer">
                {file.name}
              </Anchor>
            </Group>
          ))}
        </Stack>
      ) : null}

      <Divider />

      {!canSubmit ? (
        <Alert color="gray" variant="light">
          You are viewing this as course staff. Only enrolled learners hand work in.
        </Alert>
      ) : rules.can_submit ? (
        <SubmissionForm itemId={itemId} assignment={assignment} rules={rules} />
      ) : (
        <Alert
          color="warning"
          icon={
            rules.reason === 'past_due' ? <IconLock size={16} /> : <IconAlertCircle size={16} />
          }
          title={rules.reason === 'past_due' ? 'The deadline has passed' : 'No attempts left'}
        >
          {rules.reason === 'past_due'
            ? 'This assignment no longer takes new work.'
            : 'You have used every attempt at this assignment.'}
        </Alert>
      )}

      {submissions.length > 0 ? (
        <Stack gap="sm">
          <Title order={4}>What you have handed in</Title>
          {submissions.map((submission) => (
            <SubmissionCard key={submission.id} submission={submission} />
          ))}
        </Stack>
      ) : null}
    </Stack>
  );
}

function SubmissionCard({ submission }: { submission: Submission }) {
  const color =
    submission.status === 'graded'
      ? 'success'
      : submission.status === 'returned'
        ? 'warning'
        : 'gray';

  return (
    <Card>
      <Stack gap="xs">
        <Group justify="space-between" align="flex-start">
          <Group gap="xs">
            <Text size="sm" fw={600}>
              Attempt {submission.attempt_number}
            </Text>
            <Badge size="sm" variant="light" color={color}>
              {submission.status_label}
            </Badge>
            {submission.is_late ? (
              <Badge size="sm" variant="light" color="warning">
                Late
              </Badge>
            ) : null}
          </Group>

          <Text size="xs" c="dimmed">
            {formatDateTime(submission.submitted_at)}
          </Text>
        </Group>

        {submission.points_earned !== undefined ? (
          <Text size="sm">
            <Text span fw={600} size="sm">
              {submission.points_earned}
            </Text>
            {submission.late_penalty_points ? (
              <Text span size="sm" c="dimmed">
                {' '}
                ({submission.points_raw} marked, {submission.late_penalty_points} off for lateness)
              </Text>
            ) : null}
          </Text>
        ) : null}

        {submission.body ? (
          <Box
            className="orbito-prose"
            style={{ fontSize: 'var(--mantine-font-size-sm)' }}
            dangerouslySetInnerHTML={{ __html: submission.body }}
          />
        ) : null}

        {submission.files?.map((file) => (
          <Group key={file.id} gap="xs">
            <IconPaperclip size={14} />
            {file.url ? (
              <Anchor href={file.url} size="sm" target="_blank" rel="noreferrer">
                {file.name}
              </Anchor>
            ) : (
              <Text size="sm">{file.name}</Text>
            )}
          </Group>
        ))}

        {submission.feedback ? (
          <Alert color="info" variant="light" title="Feedback">
            <Box
              className="orbito-prose"
              dangerouslySetInnerHTML={{ __html: submission.feedback }}
            />
          </Alert>
        ) : null}
      </Stack>
    </Card>
  );
}
