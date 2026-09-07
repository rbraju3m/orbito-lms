import {
  Alert,
  Badge,
  Button,
  Card,
  Container,
  Group,
  Stack,
  Text,
  ThemeIcon,
  Title,
} from '@mantine/core';
import { IconCheck, IconClock, IconX } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { Link, useParams } from 'react-router';

import { ErrorState, LoadingState } from '@/shared/ui';

import { attemptResultQuery } from '../api/queries';
import type { ReviewRow } from '../api/types';

export function QuizResultRoute() {
  const { courseId = '', itemId = '', attemptId = '' } = useParams();
  const { data, isPending, isError, error, refetch } = useQuery(attemptResultQuery(attemptId));

  if (isPending) {
    return (
      <Container size="md" py="lg">
        <LoadingState rows={4} height={80} />
      </Container>
    );
  }

  if (isError) {
    return (
      <Container size="md" py="lg">
        <ErrorState error={error} onRetry={() => void refetch()} title="Result unavailable" />
      </Container>
    );
  }

  const { attempt, review } = data;
  const pending = attempt.status === 'awaiting_review';

  return (
    <Container size="md" py="lg">
      <Stack gap="lg">
        <Card>
          <Stack gap="sm" align="center">
            {pending ? (
              <>
                <ThemeIcon size={54} radius="xl" variant="light" color="warning">
                  <IconClock size={28} />
                </ThemeIcon>
                <Title order={2}>Waiting on your instructor</Title>
                <Text size="sm" c="dimmed" ta="center">
                  Some answers need a person to read them. You'll see your score once they have.
                </Text>
              </>
            ) : (
              <>
                <ThemeIcon
                  size={54}
                  radius="xl"
                  variant="light"
                  color={attempt.passed ? 'success' : 'danger'}
                >
                  {attempt.passed ? <IconCheck size={28} /> : <IconX size={28} />}
                </ThemeIcon>
                <Title order={2}>{attempt.passed ? 'Passed' : 'Not passed'}</Title>
                <Text size="xl" fw={700}>
                  {Math.round(attempt.percent ?? 0)}%
                </Text>
                <Text size="sm" c="dimmed">
                  {attempt.earned_points} of {attempt.total_points} points · pass mark{' '}
                  {attempt.quiz?.passing_score_percent}%
                </Text>
              </>
            )}
          </Stack>
        </Card>

        {attempt.quiz?.show_correct_answers === false && !pending ? (
          <Alert color="gray" variant="light">
            This quiz does not reveal the correct answers.
          </Alert>
        ) : null}

        <Stack gap="sm">
          <Title order={4}>Your answers</Title>
          {review.map((row, index) => (
            <ReviewCard key={row.question_id} row={row} number={index + 1} />
          ))}
        </Stack>

        <Group justify="center">
          <Button component={Link} to={`/learn/${courseId}/${itemId}`} variant="light">
            Back to the course
          </Button>
        </Group>
      </Stack>
    </Container>
  );
}

function ReviewCard({ row, number }: { row: ReviewRow; number: number }) {
  const color = row.awaiting_review ? 'warning' : row.is_correct ? 'success' : 'danger';
  const label = row.awaiting_review ? 'Awaiting review' : row.is_correct ? 'Correct' : 'Incorrect';

  return (
    <Card>
      <Stack gap="xs">
        <Group justify="space-between" align="flex-start">
          <Text fw={600} size="sm">
            {number}. {row.title}
          </Text>
          <Group gap="xs">
            <Badge color={color} variant="light" size="sm">
              {label}
            </Badge>
            <Text size="xs" c="dimmed">
              {row.points_earned}/{row.points_possible}
            </Text>
          </Group>
        </Group>

        <Text size="sm">
          <Text span c="dimmed" size="sm">
            Your answer:{' '}
          </Text>
          {formatAnswer(row.your_answer_label)}
        </Text>

        {row.correct_answer !== undefined && !row.is_correct ? (
          <Text size="sm">
            <Text span c="dimmed" size="sm">
              Correct answer:{' '}
            </Text>
            {formatAnswer(row.correct_answer)}
          </Text>
        ) : null}

        {row.explanation ? (
          <Text size="sm" c="dimmed">
            {row.explanation}
          </Text>
        ) : null}

        {row.feedback ? (
          <Alert color="info" variant="light" title="Instructor feedback">
            {row.feedback}
          </Alert>
        ) : null}
      </Stack>
    </Card>
  );
}

function formatAnswer(answer: unknown): string {
  if (Array.isArray(answer)) return answer.join(', ');
  if (answer !== null && typeof answer === 'object') {
    return Object.entries(answer as Record<string, unknown>)
      .map(([key, value]) => `${key} → ${String(value)}`)
      .join('; ');
  }
  return String(answer ?? '—');
}
