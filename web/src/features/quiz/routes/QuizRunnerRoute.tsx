import {
  Alert,
  Badge,
  Button,
  Card,
  Container,
  Group,
  Progress,
  Stack,
  Text,
  Title,
} from '@mantine/core';
import { modals } from '@mantine/modals';
import { IconAlertTriangle, IconClock, IconDeviceFloppy } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useCallback, useState } from 'react';
import { useNavigate, useParams } from 'react-router';

import { plural, t } from '@/shared/i18n';
import { formatNumber } from '@/shared/lib/number';
import { ApiError } from '@/shared/api/errors';
import { ErrorState, LoadingState } from '@/shared/ui';

import { runnerQuery, useSaveAnswer, useSubmitAttempt } from '../api/queries';
import type { AnswerPayload } from '../api/types';
import { QuestionInput } from '../components/QuestionInput';
import { useAttemptCountdown } from '../hooks/useAttemptCountdown';

type SaveState = 'idle' | 'saving' | 'saved' | 'error';

export function QuizRunnerRoute() {
  const { courseId = '', itemId = '', attemptId = '' } = useParams();
  const navigate = useNavigate();

  const { data, isPending, isError, error, refetch } = useQuery(runnerQuery(attemptId));
  const saveAnswer = useSaveAnswer(attemptId);
  const submit = useSubmitAttempt(attemptId, itemId);

  const [answers, setAnswers] = useState<Record<string, AnswerPayload>>({});
  const [page, setPage] = useState(0);
  const [saveState, setSaveState] = useState<SaveState>('idle');
  const [saveError, setSaveError] = useState<string | null>(null);
  const [hydrated, setHydrated] = useState(false);

  // Seed from whatever the server already has, once.
  if (data && !hydrated) {
    setAnswers(data.answers);
    setHydrated(true);
  }

  const countdown = useAttemptCountdown(data?.attempt.seconds_remaining ?? null);

  const persist = useCallback(
    (questionId: string, answer: AnswerPayload) => {
      setSaveState('saving');
      setSaveError(null);

      saveAnswer.mutate(
        { questionId, answer },
        {
          onSuccess: () => setSaveState('saved'),
          onError: (err) => {
            setSaveState('error');
            // A refused save usually means time ran out — say so rather than
            // letting the learner keep typing into a closed attempt.
            setSaveError(
              err instanceof ApiError
                ? err.message
                : t('quiz.runner.save_failed', 'Could not save that answer.'),
            );
          },
        },
      );
    },
    [saveAnswer],
  );

  if (isPending) {
    return (
      <Container size="md" py="lg">
        <LoadingState rows={4} height={90} />
      </Container>
    );
  }

  if (isError) {
    return (
      <Container size="md" py="lg">
        <ErrorState
          error={error}
          onRetry={() => void refetch()}
          title={t('quiz.unavailable', 'Quiz unavailable')}
        />
      </Container>
    );
  }

  const { attempt, questions } = data;
  const perPage = attempt.quiz?.questions_per_page ?? 1;
  const pageCount = Math.max(1, Math.ceil(questions.length / perPage));
  const visible = questions.slice(page * perPage, page * perPage + perPage);
  const answeredCount = questions.filter((q) => answers[q.id] !== undefined).length;

  const confirmSubmit = () =>
    modals.openConfirmModal({
      title: t('quiz.runner.confirm_title', 'Submit this attempt?'),
      children: (
        <Text size="sm">
          {answeredCount < questions.length
            ? t(
                'quiz.runner.confirm_partial',
                'You have answered {answered} of {total}. Unanswered questions score nothing.',
                { answered: formatNumber(answeredCount), total: formatNumber(questions.length) },
              )
            : t('quiz.runner.confirm_all', 'You have answered every question.')}
        </Text>
      ),
      labels: {
        confirm: t('quiz.runner.confirm_submit', 'Submit'),
        cancel: t('quiz.runner.confirm_cancel', 'Keep working'),
      },
      onConfirm: () =>
        submit.mutate(undefined, {
          onSuccess: () =>
            void navigate(`/learn/${courseId}/${itemId}/quiz/${attemptId}/result`, {
              replace: true,
            }),
        }),
    });

  return (
    <Container size="md" py="lg">
      <Stack gap="lg">
        <Group justify="space-between" wrap="wrap">
          <Stack gap={2}>
            <Title order={1} size="h3">
              {t('quiz.runner.attempt', 'Attempt {number}', {
                number: formatNumber(attempt.attempt_number),
              })}
            </Title>
            <Text size="sm" c="dimmed">
              {t('quiz.runner.answered', '{answered} of {total} answered', {
                answered: formatNumber(answeredCount),
                total: formatNumber(questions.length),
              })}
            </Text>
          </Stack>

          <Group gap="sm">
            {/* Display only — the server re-checks its own deadline on every
                save and on submit. */}
            {countdown.label ? (
              <Badge
                size="lg"
                variant="light"
                color={countdown.seconds !== null && countdown.seconds < 60 ? 'danger' : 'gray'}
                leftSection={<IconClock size={13} />}
              >
                {countdown.label}
              </Badge>
            ) : null}

            <Group gap={4} aria-live="polite">
              {saveState === 'saving' ? (
                <Text size="xs" c="dimmed">
                  {t('quiz.runner.saving', 'Saving…')}
                </Text>
              ) : null}
              {saveState === 'saved' ? (
                <Group gap={3}>
                  <IconDeviceFloppy size={13} />
                  <Text size="xs" c="dimmed">
                    {t('quiz.runner.saved', 'Saved')}
                  </Text>
                </Group>
              ) : null}
            </Group>
          </Group>
        </Group>

        <Progress
          value={(answeredCount / Math.max(1, questions.length)) * 100}
          size="sm"
          aria-label={t('quiz.runner.progress', '{answered} of {total} questions answered', {
            answered: formatNumber(answeredCount),
            total: formatNumber(questions.length),
          })}
        />

        {countdown.expired ? (
          <Alert color="warning" icon={<IconAlertTriangle size={16} />} role="alert">
            {t('quiz.runner.time_up', 'Time is up. Submit now — the server decides what counts.')}
          </Alert>
        ) : null}

        {saveError ? (
          <Alert color="danger" icon={<IconAlertTriangle size={16} />} role="alert">
            {saveError}
          </Alert>
        ) : null}

        {visible.map((question, index) => {
          const number = page * perPage + index + 1;

          return (
            <Card key={question.id}>
              <Stack gap="xs">
                <Group justify="space-between" align="flex-start">
                  <Text fw={600}>
                    {attempt.quiz?.hide_question_numbers ? '' : `${formatNumber(number)}. `}
                    {question.title}
                  </Text>
                  <Badge variant="light" size="sm">
                    {plural('quiz.runner.points', question.points, {
                      one: '{count} point',
                      other: '{count} points',
                    })}
                  </Badge>
                </Group>

                {question.body ? (
                  <Text size="sm" c="dimmed" style={{ whiteSpace: 'pre-wrap' }}>
                    {question.body}
                  </Text>
                ) : null}

                <QuestionInput
                  question={question}
                  value={answers[question.id]}
                  disabled={countdown.expired}
                  onChange={(answer) => {
                    setAnswers((current) => ({ ...current, [question.id]: answer }));
                    persist(question.id, answer);
                  }}
                />
              </Stack>
            </Card>
          );
        })}

        <Group justify="space-between">
          <Button
            variant="default"
            disabled={page === 0 || attempt.quiz?.allow_previous_button === false}
            onClick={() => setPage((p) => Math.max(0, p - 1))}
          >
            {t('quiz.runner.previous', 'Previous')}
          </Button>

          {page < pageCount - 1 ? (
            <Button onClick={() => setPage((p) => Math.min(pageCount - 1, p + 1))}>
              {t('quiz.runner.next', 'Next')}
            </Button>
          ) : (
            <Button loading={submit.isPending} onClick={confirmSubmit}>
              {t('quiz.runner.submit', 'Submit attempt')}
            </Button>
          )}
        </Group>

        {page < pageCount - 1 ? (
          <Group justify="center">
            <Button variant="subtle" size="xs" loading={submit.isPending} onClick={confirmSubmit}>
              {t('quiz.runner.submit_now', 'Submit now')}
            </Button>
          </Group>
        ) : null}
      </Stack>
    </Container>
  );
}
