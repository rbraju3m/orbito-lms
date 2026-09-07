import {
  Alert,
  Badge,
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
import { IconAlertCircle, IconArrowLeft } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useNavigate, useParams } from 'react-router';

import type { ReviewRow } from '@/features/quiz/api/types';
import { numberValue } from '@/shared/lib/numberValue';
import { ApiError } from '@/shared/api/errors';
import { formatDateTime } from '@/shared/lib/datetime';
import { ErrorState, LoadingState } from '@/shared/ui';

import { attemptGradingQuery, useGradeAttempt, type AnswerGrade } from '../api/queries';

interface Draft {
  points: number;
  feedback: string;
}

/**
 * Marking the open questions of a quiz attempt.
 *
 * Only the questions that actually need a person are editable; the
 * auto-graded ones are shown for context so the grader can see the whole
 * paper without being able to overwrite what the server already decided.
 */
export function GradeAttemptRoute() {
  const { attemptId = '' } = useParams();
  const navigate = useNavigate();

  const { data, isPending, isError, error, refetch } = useQuery(attemptGradingQuery(attemptId));
  const grade = useGradeAttempt(attemptId);

  const [drafts, setDrafts] = useState<Record<string, Draft>>({});
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
        <ErrorState error={error} onRetry={() => void refetch()} title="Attempt unavailable" />
      </Container>
    );
  }

  const { attempt, learner, review } = data;
  const open = review.filter((row) => row.awaiting_review);

  const draftFor = (row: ReviewRow): Draft =>
    drafts[row.question_id] ?? { points: row.points_earned, feedback: row.feedback ?? '' };

  const save = () => {
    setFailure(null);

    const grades: AnswerGrade[] = open.map((row) => {
      const draft = draftFor(row);

      return {
        question_id: row.question_id,
        points: draft.points,
        feedback: draft.feedback || null,
      };
    });

    grade.mutate(grades, {
      onSuccess: () => void navigate(-1),
      onError: (err) =>
        setFailure(
          err instanceof ApiError
            ? (Object.values(err.fieldErrors())[0] ?? err.message)
            : 'That could not be saved.',
        ),
    });
  };

  return (
    <Container size="md" py="lg">
      <Stack gap="lg">
        <Group>
          <Button
            variant="subtle"
            leftSection={<IconArrowLeft size={16} />}
            onClick={() => void navigate(-1)}
          >
            Back to the queue
          </Button>
        </Group>

        <Stack gap="xs">
          <Title order={2}>Quiz attempt {attempt.attempt_number}</Title>
          <Text size="sm" c="dimmed">
            {learner.name} · handed in {formatDateTime(attempt.submitted_at)}
          </Text>
        </Stack>

        {failure ? (
          <Alert color="danger" icon={<IconAlertCircle size={16} />} role="alert">
            {failure}
          </Alert>
        ) : null}

        {open.length === 0 ? (
          <Alert color="success" variant="light">
            Nothing here needs a person — this attempt graded itself.
          </Alert>
        ) : null}

        {review.map((row, index) => {
          const draft = draftFor(row);

          return (
            <Card key={row.question_id}>
              <Stack gap="sm">
                <Group justify="space-between" align="flex-start">
                  <Text size="sm" fw={600}>
                    {index + 1}. {row.title}
                  </Text>
                  <Badge
                    size="sm"
                    variant="light"
                    color={row.awaiting_review ? 'warning' : row.is_correct ? 'success' : 'danger'}
                  >
                    {row.awaiting_review
                      ? 'Needs marking'
                      : `${row.points_earned}/${row.points_possible}`}
                  </Badge>
                </Group>

                <Text size="sm" style={{ whiteSpace: 'pre-wrap' }}>
                  {formatAnswer(row.your_answer_label)}
                </Text>

                {row.awaiting_review ? (
                  <Stack gap="xs">
                    <NumberInput
                      value={draft.points}
                      onChange={(value) =>
                        setDrafts((current) => ({
                          ...current,
                          [row.question_id]: { ...draft, points: numberValue(value, 0) },
                        }))
                      }
                      label={`Mark (out of ${row.points_possible})`}
                      min={0}
                      max={row.points_possible}
                    />
                    <Textarea
                      value={draft.feedback}
                      onChange={(event) => {
                        // Read before the updater runs: a state updater is
                        // called after the event has been released, and
                        // `currentTarget` is null by then.
                        const feedback = event.currentTarget.value;

                        setDrafts((current) => ({
                          ...current,
                          [row.question_id]: { ...draft, feedback },
                        }));
                      }}
                      label="Feedback"
                      autosize
                      minRows={2}
                      maxRows={8}
                    />
                  </Stack>
                ) : null}
              </Stack>
            </Card>
          );
        })}

        {open.length > 0 ? (
          <Group justify="flex-end">
            <Button loading={grade.isPending} onClick={save}>
              Save {open.length === 1 ? 'the mark' : 'the marks'}
            </Button>
          </Group>
        ) : null}
      </Stack>
    </Container>
  );
}

function formatAnswer(answer: unknown): string {
  if (answer === null || answer === undefined || answer === '') return 'Not answered.';
  if (Array.isArray(answer)) return answer.join(', ');
  if (typeof answer === 'object') {
    return Object.entries(answer as Record<string, unknown>)
      .map(([key, value]) => `${key} → ${String(value)}`)
      .join('; ');
  }

  return String(answer);
}
