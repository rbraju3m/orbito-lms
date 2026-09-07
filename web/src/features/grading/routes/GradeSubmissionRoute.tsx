import {
  Alert,
  Anchor,
  Badge,
  Box,
  Button,
  Card,
  Container,
  Group,
  NumberInput,
  Stack,
  Text,
  Textarea,
  Title,
} from '@mantine/core';
import { IconAlertCircle, IconArrowLeft, IconPaperclip } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useNavigate, useParams } from 'react-router';

import { numberValue } from '@/features/quiz/components/numberValue';
import { ApiError } from '@/shared/api/errors';
import { formatDateTime } from '@/shared/lib/datetime';
import { ErrorState, LoadingState } from '@/shared/ui';

import { submissionGradingQuery, useGradeSubmission, useReturnSubmission } from '../api/queries';

export function GradeSubmissionRoute() {
  const { submissionId = '' } = useParams();
  const navigate = useNavigate();

  const { data, isPending, isError, error, refetch } = useQuery(
    submissionGradingQuery(submissionId),
  );
  const grade = useGradeSubmission(submissionId);
  const hand = useReturnSubmission(submissionId);

  const [points, setPoints] = useState<number | null>(null);
  const [feedback, setFeedback] = useState('');
  const [failure, setFailure] = useState<string | null>(null);

  if (isPending) {
    return (
      <Container size="md" py="lg">
        <LoadingState rows={3} height={100} />
      </Container>
    );
  }

  if (isError) {
    return (
      <Container size="md" py="lg">
        <ErrorState error={error} onRetry={() => void refetch()} title="Submission unavailable" />
      </Container>
    );
  }

  const { submission, assignment, learner, item } = data;
  // Seeded from a previous mark so re-grading starts where it left off.
  const mark = points ?? submission.points_raw ?? 0;
  const back = () => void navigate(-1);

  const onError = (err: unknown) =>
    setFailure(
      err instanceof ApiError
        ? (Object.values(err.fieldErrors())[0] ?? err.message)
        : 'That could not be saved.',
    );

  return (
    <Container size="md" py="lg">
      <Stack gap="lg">
        <Group>
          <Button variant="subtle" leftSection={<IconArrowLeft size={16} />} onClick={back}>
            Back to the queue
          </Button>
        </Group>

        <Stack gap="xs">
          <Title order={2}>{item.title ?? 'Assignment'}</Title>
          <Group gap="xs">
            <Text size="sm" c="dimmed">
              {learner.name} · attempt {submission.attempt_number} ·{' '}
              {formatDateTime(submission.submitted_at)}
            </Text>
            {submission.is_late ? (
              <Badge size="sm" variant="light" color="warning">
                Late
              </Badge>
            ) : null}
          </Group>
        </Stack>

        {failure ? (
          <Alert color="danger" icon={<IconAlertCircle size={16} />} role="alert">
            {failure}
          </Alert>
        ) : null}

        {submission.is_late && assignment.late_policy === 'penalise' ? (
          <Alert color="warning" variant="light">
            Mark this out of {assignment.total_points} as usual — {assignment.late_penalty_percent}%
            is taken off automatically because it was handed in late.
          </Alert>
        ) : null}

        <Card>
          <Stack gap="sm">
            <Text size="sm" fw={600}>
              What they handed in
            </Text>

            {submission.body ? (
              <Box className="orbito-prose" dangerouslySetInnerHTML={{ __html: submission.body }} />
            ) : (
              <Text size="sm" c="dimmed">
                No written answer.
              </Text>
            )}

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
          </Stack>
        </Card>

        <Card>
          <Stack gap="md">
            <NumberInput
              value={mark}
              onChange={(value) => setPoints(numberValue(value, 0))}
              label={`Mark (out of ${assignment.total_points})`}
              min={0}
              max={assignment.total_points}
            />

            <Textarea
              value={feedback}
              onChange={(event) => setFeedback(event.currentTarget.value)}
              label="Feedback"
              description="The learner sees this as soon as you save."
              autosize
              minRows={4}
              maxRows={16}
            />

            <Group justify="space-between">
              <Button
                variant="default"
                loading={hand.isPending}
                disabled={feedback.trim() === ''}
                onClick={() => {
                  setFailure(null);
                  hand.mutate(feedback, { onSuccess: back, onError });
                }}
              >
                Hand back for another go
              </Button>

              <Button
                loading={grade.isPending}
                onClick={() => {
                  setFailure(null);
                  grade.mutate(
                    { points: mark, feedback: feedback || null },
                    { onSuccess: back, onError },
                  );
                }}
              >
                Save the mark
              </Button>
            </Group>

            <Text size="xs" c="dimmed">
              Handing work back clears any mark and does not use up one of their attempts.
            </Text>
          </Stack>
        </Card>
      </Stack>
    </Container>
  );
}
