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

import { t } from '@/shared/i18n';
import { formatNumber } from '@/shared/lib/number';
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
        <ErrorState
          error={error}
          onRetry={() => void refetch()}
          title={t('quiz.result.unavailable', 'Result unavailable')}
        />
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
                <Title order={1} size="h2">
                  {t('quiz.result.waiting_title', 'Waiting on your instructor')}
                </Title>
                <Text size="sm" c="dimmed" ta="center">
                  {t(
                    'quiz.result.waiting_body',
                    "Some answers need a person to read them. You'll see your score once they have.",
                  )}
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
                <Title order={1} size="h2">
                  {attempt.passed
                    ? t('quiz.result.passed', 'Passed')
                    : t('quiz.result.not_passed', 'Not passed')}
                </Title>
                <Text size="xl" fw={700}>
                  {formatNumber(Math.round(attempt.percent ?? 0))}%
                </Text>
                <Text size="sm" c="dimmed">
                  {t('quiz.result.points', '{earned} of {total} points · pass mark {pass}%', {
                    earned: figure(attempt.earned_points),
                    total: figure(attempt.total_points),
                    pass: figure(attempt.quiz?.passing_score_percent),
                  })}
                </Text>
              </>
            )}
          </Stack>
        </Card>

        {attempt.quiz?.show_correct_answers === false && !pending ? (
          <Alert color="gray" variant="light">
            {t('quiz.result.no_reveal', 'This quiz does not reveal the correct answers.')}
          </Alert>
        ) : null}

        <Stack gap="sm">
          <Title order={2} size="h4">
            {t('quiz.result.your_answers', 'Your answers')}
          </Title>
          {review.map((row, index) => (
            <ReviewCard key={row.question_id} row={row} number={index + 1} />
          ))}
        </Stack>

        <Group justify="center">
          <Button component={Link} to={`/learn/${courseId}/${itemId}`} variant="light">
            {t('quiz.result.back', 'Back to the course')}
          </Button>
        </Group>
      </Stack>
    </Container>
  );
}

function ReviewCard({ row, number }: { row: ReviewRow; number: number }) {
  const color = row.awaiting_review ? 'warning' : row.is_correct ? 'success' : 'danger';
  const label = row.awaiting_review
    ? t('quiz.result.awaiting_review', 'Awaiting review')
    : row.is_correct
      ? t('quiz.result.correct', 'Correct')
      : t('quiz.result.incorrect', 'Incorrect');

  return (
    <Card>
      <Stack gap="xs">
        <Group justify="space-between" align="flex-start">
          <Text fw={600} size="sm">
            {formatNumber(number)}. {row.title}
          </Text>
          <Group gap="xs">
            <Badge color={color} variant="light" size="sm">
              {label}
            </Badge>
            <Text size="xs" c="dimmed">
              {formatNumber(row.points_earned)}/{formatNumber(row.points_possible)}
            </Text>
          </Group>
        </Group>

        <Text size="sm">
          <Text span c="dimmed" size="sm">
            {t('quiz.result.your_answer', 'Your answer:')}{' '}
          </Text>
          {formatAnswer(row.your_answer_label)}
        </Text>

        {row.correct_answer !== undefined && !row.is_correct ? (
          <Text size="sm">
            <Text span c="dimmed" size="sm">
              {t('quiz.result.correct_answer', 'Correct answer:')}{' '}
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
          <Alert
            color="info"
            variant="light"
            title={t('quiz.result.feedback', 'Instructor feedback')}
          >
            {row.feedback}
          </Alert>
        ) : null}
      </Stack>
    </Card>
  );
}

/** Absent is not zero: a figure the server did not send reads as a dash. */
function figure(value: number | undefined): string {
  return value === undefined ? '—' : formatNumber(value);
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
